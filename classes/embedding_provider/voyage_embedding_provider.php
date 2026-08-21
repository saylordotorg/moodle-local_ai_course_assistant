<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_ai_course_assistant\embedding_provider;

/**
 * Voyage AI embedding provider (voyage-3.5, voyage-3.5-lite, voyage-3.1-large).
 *
 * v5.11.0: introduced as the recommended primary embedding provider per the
 * vendor recommendations doc. ~+4 MTEB English vs OpenAI text-embedding-3-small,
 * 4x the input context (32k vs 8k), and materially better multilingual recall.
 *
 * Voyage's embeddings API supports an `input_type` parameter ("document" or
 * "query") for asymmetric retrieval, plus an `output_dimension` MRL truncation
 * (default 1024 for voyage-3.5; valid values 256/512/1024/2048). We default to
 * "document" for index calls; rag_retriever asks for "query" on the user-query
 * embed call so the two vectors are projected for retrieval rather than
 * symmetric comparison.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class voyage_embedding_provider extends base_embedding_provider {
    /** Voyage batch limit per call (per docs.voyageai.com). */
    private const BATCH_SIZE = 1000;

    /** Default input_type when caller doesn't specify (indexing uses "document"). */
    private const DEFAULT_INPUT_TYPE = 'document';

    /** Native default output width; sending it explicitly is redundant. */
    private const NATIVE_DIMENSION = 1024;

    /** Valid Matryoshka (MRL) output widths for the voyage-3.x / voyage-4 line. */
    private const VALID_DIMENSIONS = [256, 512, 1024, 2048];

    /**
     * Resolve the output_dimension to send, or null to omit it (native default).
     *
     * Pure and testable. Only a valid non-native MRL width is sent; anything
     * else (0/unset, the native 1024, or an OpenAI-shaped width such as 1536
     * left over from a provider switch) omits the parameter so Voyage applies
     * its own default instead of rejecting the call.
     *
     * @param int $configured Configured embed_dimensions (0 = unset).
     * @return int|null Width to send, or null to omit.
     */
    public static function mrl_output_dimension(int $configured): ?int {
        if (
            $configured > 0
                && $configured !== self::NATIVE_DIMENSION
                && in_array($configured, self::VALID_DIMENSIONS, true)
        ) {
            return $configured;
        }
        return null;
    }

    /** Contextualized-chunk models, matched by prefix. */
    private const CONTEXT_MODEL_PREFIX = 'voyage-context-';

    /**
     * Voyage returns int8/binary vectors natively via output_dtype, so the
     * configured dtype can be honored rather than silently downgraded.
     *
     * @return bool
     */
    public function supports_dtype(): bool {
        return true;
    }

    /**
     * Is the configured document model a contextualized-chunk model?
     *
     * These use a different endpoint and a different request shape: chunks
     * arrive grouped by source document so each chunk's vector can encode the
     * surrounding document, rather than being embedded in isolation.
     *
     * @return bool
     */
    public function is_contextualized(): bool {
        return strpos(strtolower($this->model), self::CONTEXT_MODEL_PREFIX) === 0;
    }

    protected function get_default_model(): string {
        return 'voyage-3.5';
    }

    protected function get_default_base_url(): string {
        return 'https://api.voyageai.com/v1';
    }

    public function embed(string $text): array {
        $results = $this->embed_batch([$text]);
        return $results[0];
    }

    public function embed_batch(array $texts): array {
        return $this->embed_batch_typed($texts, self::DEFAULT_INPUT_TYPE);
    }

    /**
     * Embed a single query for retrieval. Uses input_type="query" so the
     * vector is projected for asymmetric retrieval against document vectors.
     *
     * @param string $text
     * @return float[]
     */
    public function embed_query(string $text): array {
        $results = $this->embed_batch_typed([$text], 'query');
        return $results[0];
    }

    /**
     * Embed a batch with an explicit input_type.
     *
     * @param string[] $texts
     * @param string $inputtype Either "document" or "query".
     * @return float[][] Vectors in the same order as input.
     * @throws \moodle_exception On API error or malformed response.
     */
    private function embed_batch_typed(array $texts, string $inputtype): array {
        if (empty($texts)) {
            return [];
        }
        if (!in_array($inputtype, ['document', 'query'], true)) {
            $inputtype = self::DEFAULT_INPUT_TYPE;
        }

        // Queries may be embedded with a different model from documents when
        // both sit in a shared embedding space. Documents always use $model:
        // the stored corpus defines the space, so only the query side may vary.
        $model = ($inputtype === 'query') ? $this->querymodel : $this->model;

        // Document vectors take the configured dtype. The query side depends on
        // which dtype it has to be compared against:
        //
        //  - int8 documents are scored with a FLOAT query. Cosine is
        //    scale-invariant, so a full-precision query against quantized
        //    documents is both valid and strictly better than quantizing both
        //    sides — it keeps precision on the one vector computed fresh per
        //    request, which costs nothing extra.
        //
        //  - binary documents need a BINARY query, because bits are compared by
        //    Hamming distance and a float vector has no meaningful Hamming
        //    distance to a bit string. Crucially the query cannot be binarized
        //    locally: measured against a live response, sign(float) agrees with
        //    the API's binary output on only 87.5% of bits (chance is 50%, so
        //    the layout is right but the values are not). Voyage derives binary
        //    embeddings through a separate quantization path, so the binary
        //    query must come from the API.
        $configured = \local_ai_course_assistant\embedding_compat::normalize_dtype($this->dtype);
        if ($inputtype === 'document') {
            $dtype = $configured;
        } else {
            $dtype = ($configured === \local_ai_course_assistant\embedding_compat::DTYPE_BINARY)
                ? \local_ai_course_assistant\embedding_compat::DTYPE_BINARY
                : \local_ai_course_assistant\embedding_compat::DTYPE_FLOAT;
        }

        $embeddings = [];

        foreach (array_chunk($texts, self::BATCH_SIZE) as $batch) {
            $payload = [
                'model' => $model,
                'input' => $batch,
                'input_type' => $inputtype,
            ];

            // Send output_dtype only when it is not the API default. Omitting
            // it on float keeps the payload identical to what earlier releases
            // sent, so a site that never touches the new setting cannot be
            // affected by this code path at all.
            if ($dtype !== \local_ai_course_assistant\embedding_compat::DTYPE_FLOAT) {
                $payload['output_dtype'] = $dtype;
            }

            // Pass output_dimension only when the configured width is a valid
            // non-default MRL width (256/512/2048); 0/unset, 1024, or an invalid
            // width (e.g. an OpenAI-shaped 1536 left after a provider switch)
            // omit it so Voyage applies its native 1024 rather than erroring.
            $outputdim = self::mrl_output_dimension($this->dimensions);
            if ($outputdim !== null) {
                $payload['output_dimension'] = $outputdim;
            }

            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apikey,
            ];

            $response = $this->http_post(
                $this->baseurl . '/embeddings',
                $headers,
                json_encode($payload)
            );

            $data = json_decode($response, true);
            if (!isset($data['data']) || !is_array($data['data'])) {
                throw new \moodle_exception(
                    'chat:error',
                    'local_ai_course_assistant',
                    '',
                    null,
                    'Voyage embeddings response missing data array'
                );
            }

            // Voyage returns each item with an `index` field; sort to preserve order.
            $rows = $data['data'];
            usort($rows, function ($a, $b) {
                return (int) ($a['index'] ?? 0) <=> (int) ($b['index'] ?? 0);
            });
            // Expected element count for the width/dtype we asked for. Binary
            // packs eight dimensions per returned value, so a 1024-wide binary
            // vector arrives as 128 elements. Checking here means a width/dtype
            // disagreement surfaces at write time instead of producing rows that
            // decode to the wrong length and score as noise.
            $logicalwidth = $outputdim ?? self::NATIVE_DIMENSION;
            $expected = \local_ai_course_assistant\embedding_compat::expected_element_count(
                $logicalwidth,
                $dtype
            );

            foreach ($rows as $item) {
                if (!isset($item['embedding']) || !is_array($item['embedding'])) {
                    throw new \moodle_exception(
                        'chat:error',
                        'local_ai_course_assistant',
                        '',
                        null,
                        'Voyage embeddings item missing embedding array'
                    );
                }
                if ($expected > 0 && count($item['embedding']) !== $expected) {
                    throw new \moodle_exception(
                        'chat:error',
                        'local_ai_course_assistant',
                        '',
                        null,
                        sprintf(
                            'Voyage returned %d values for a %d-wide %s vector; expected %d',
                            count($item['embedding']),
                            $logicalwidth,
                            $dtype,
                            $expected
                        )
                    );
                }
                $embeddings[] = $item['embedding'];
            }

            $usagetokens = (int) ($data['usage']['total_tokens'] ?? 0);
            if ($usagetokens > 0) {
                $this->log_embedding_cost($usagetokens);
            }
        }

        return $embeddings;
    }

    /**
     * Embed chunks grouped by source document, using the contextualized
     * endpoint.
     *
     * Standard embedding encodes each chunk in isolation, discarding the
     * document it came from. A paragraph deep in a course unit is embedded
     * without the unit's topic, which is a large part of why bare noun-phrase
     * queries retrieve poorly. Contextualized models process a whole document
     * in one pass and return one vector per chunk that encodes both the chunk
     * and its surroundings.
     *
     * @param array $groups List of documents; each document is an ordered list
     *                      of chunk strings. Order within a document is
     *                      significant — it is the context signal.
     * @param string $inputtype "document" or "query".
     * @return array Same shape as $groups, with each string replaced by its
     *               vector.
     * @throws \moodle_exception On API error or malformed response.
     */
    public function embed_contextualized(array $groups, string $inputtype = 'document'): array {
        if (empty($groups)) {
            return [];
        }
        if (!in_array($inputtype, ['document', 'query'], true)) {
            $inputtype = self::DEFAULT_INPUT_TYPE;
        }
        $dtype = ($inputtype === 'document')
            ? \local_ai_course_assistant\embedding_compat::normalize_dtype($this->dtype)
            : \local_ai_course_assistant\embedding_compat::DTYPE_FLOAT;
        $outputdim = self::mrl_output_dimension($this->dimensions);

        $out = [];
        foreach ($this->batch_groups($groups) as $batch) {
            $payload = [
                'model' => $this->model,
                'inputs' => array_values($batch['inputs']),
                'input_type' => $inputtype,
            ];
            if ($outputdim !== null) {
                $payload['output_dimension'] = $outputdim;
            }
            if ($dtype !== \local_ai_course_assistant\embedding_compat::DTYPE_FLOAT) {
                $payload['output_dtype'] = $dtype;
            }

            $response = $this->http_post(
                $this->baseurl . '/contextualizedembeddings',
                [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apikey,
                ],
                json_encode($payload)
            );

            $data = json_decode($response, true);
            if (!isset($data['data']) || !is_array($data['data'])) {
                throw new \moodle_exception(
                    'chat:error',
                    'local_ai_course_assistant',
                    '',
                    null,
                    'Voyage contextualized response missing data array'
                );
            }

            // The response nests one level deeper than the plain endpoint: an
            // outer entry per document, each holding an inner list of chunk
            // embeddings. Both levels carry `index` and both are sorted, since
            // ordering is what maps a vector back to its chunk.
            $docs = $data['data'];
            usort($docs, fn($a, $b) => (int) ($a['index'] ?? 0) <=> (int) ($b['index'] ?? 0));
            if (count($docs) !== count($batch['inputs'])) {
                throw new \moodle_exception(
                    'chat:error',
                    'local_ai_course_assistant',
                    '',
                    null,
                    sprintf(
                        'Voyage contextualized returned %d documents for %d sent',
                        count($docs),
                        count($batch['inputs'])
                    )
                );
            }

            $keys = array_keys($batch['inputs']);
            foreach ($docs as $i => $doc) {
                $inner = $doc['data'] ?? null;
                if (!is_array($inner)) {
                    throw new \moodle_exception(
                        'chat:error',
                        'local_ai_course_assistant',
                        '',
                        null,
                        'Voyage contextualized document missing inner data array'
                    );
                }
                usort($inner, fn($a, $b) => (int) ($a['index'] ?? 0) <=> (int) ($b['index'] ?? 0));
                $sentcount = count($batch['inputs'][$keys[$i]]);
                if (count($inner) !== $sentcount) {
                    throw new \moodle_exception(
                        'chat:error',
                        'local_ai_course_assistant',
                        '',
                        null,
                        sprintf(
                            'Voyage contextualized returned %d chunk vectors for %d chunks sent',
                            count($inner),
                            $sentcount
                        )
                    );
                }
                $vecs = [];
                foreach ($inner as $item) {
                    if (!isset($item['embedding']) || !is_array($item['embedding'])) {
                        throw new \moodle_exception(
                            'chat:error',
                            'local_ai_course_assistant',
                            '',
                            null,
                            'Voyage contextualized item missing embedding array'
                        );
                    }
                    $vecs[] = $item['embedding'];
                }
                $out[$keys[$i]] = $vecs;
            }

            $usagetokens = (int) ($data['usage']['total_tokens'] ?? 0);
            if ($usagetokens > 0) {
                $this->log_embedding_cost($usagetokens);
            }
        }

        // Preserve the caller's key order regardless of batching.
        $ordered = [];
        foreach (array_keys($groups) as $k) {
            $ordered[$k] = $out[$k] ?? [];
        }
        return $ordered;
    }

    /**
     * Split documents into requests that respect the contextualized endpoint's
     * documented limits: at most 1,000 inputs, 16,000 chunks, and 120,000
     * tokens per request.
     *
     * A single document that exceeds a limit on its own is still emitted as its
     * own request rather than dropped — the API will reject it and the error
     * will name the document, which is more useful than silently skipping
     * content.
     *
     * @param array $groups
     * @return array List of ['inputs' => array] batches, keys preserved.
     */
    private function batch_groups(array $groups): array {
        // Deliberately conservative against the 120K ceiling: chunk sizes are
        // estimated from characters, and running close to the limit would turn
        // an estimation error into a failed request mid-index.
        $maxtokens = 100000;
        $maxdocs = 1000;
        $maxchunks = 16000;

        $batches = [];
        $cur = [];
        $curtokens = 0;
        $curchunks = 0;
        foreach ($groups as $key => $chunks) {
            $chunks = array_values(array_filter(array_map('strval', (array) $chunks), fn($c) => trim($c) !== ''));
            if (empty($chunks)) {
                continue;
            }
            // Characters/4 is the same crude estimator the rest of the plugin
            // uses for budgeting. It only has to be right enough to keep a
            // request under the token ceiling, and it is applied against a
            // deliberately low ceiling for that reason.
            $tokens = (int) ceil(array_sum(array_map('mb_strlen', $chunks)) / 4);
            $wouldexceed = !empty($cur) && (
                count($cur) + 1 > $maxdocs
                || $curchunks + count($chunks) > $maxchunks
                || $curtokens + $tokens > $maxtokens
            );
            if ($wouldexceed) {
                $batches[] = ['inputs' => $cur];
                $cur = [];
                $curtokens = 0;
                $curchunks = 0;
            }
            $cur[$key] = $chunks;
            $curtokens += $tokens;
            $curchunks += count($chunks);
        }
        if (!empty($cur)) {
            $batches[] = ['inputs' => $cur];
        }
        return $batches;
    }

}
