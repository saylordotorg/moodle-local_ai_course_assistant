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
 * Voyage AI embedding provider (voyage-4-large, voyage-4, voyage-4-lite,
 * voyage-context-4, voyage-3.5, voyage-3.5-lite; see MODEL_PROFILES).
 *
 * v5.11.0: introduced as the recommended primary embedding provider per the
 * vendor recommendations doc. ~+4 MTEB English vs OpenAI text-embedding-3-small,
 * 4x the input context (32k vs 8k), and materially better multilingual recall.
 *
 * SHARED vs ASYMMETRIC EMBEDDING SPACE (v7.4.0 — read this before "fixing" it)
 *
 * Voyage's embeddings API accepts an `input_type` of "document" or "query".
 * Sending "query" on the retrieval call and "document" on the index call —
 * asymmetric retrieval — is the vendor's suggested optimization, and this
 * adapter did it unconditionally from v5.11.0 to v7.3.x.
 *
 * It is worth having ON, and that holds across two model generations. Measured
 * directly on 2026-08-21 by embedding the identical queries both ways against
 * the identical cached document vectors (voyage-3.5, 1,008 production-shaped
 * fixtures): `input_type: query` scored 61.0% R@3 against 30.2% for `document`,
 * a 30.8 pp penalty for getting it wrong, and `query` won in EVERY query-length
 * bucket, from -19.9 pp on the shortest to -43.3 pp in the middle
 * (`.drafts/sola-rag-rerank-benchmark-2026-08-21.md` section 5b). Re-measured
 * independently on 2026-09-10 through the production harness (voyage-4-large
 * @2048, 816 production-shaped fixtures): 66.7% against 42.6%, +24.1 pp. Same
 * direction, same order of magnitude, different model. The asymmetric
 * projection is doing real work.
 *
 * DO NOT CONFUSE THIS WITH ASYMMETRIC MODEL PAIRING. The thing that did not
 * reproduce on the SOLA corpus is a different mechanism: embedding DOCUMENTS
 * with a larger Voyage model than QUERIES (a wash for voyage-4-lite, 1.0 pp
 * WORSE for voyage-4; a follow-up in-product run at n=30 agreed but is too
 * small to count as a second independent measurement).
 * embedding_compat::SHARED_SPACES records that measurement, and it is about
 * model pairing, not about input_type; pairing is governed by
 * `embed_query_model`. This docblock used to cite that result as evidence about
 * input_type, and the settings help text inherited the same conflation and told
 * operators to avoid the option that wins by 24-31 pp.
 *
 * The cost of asymmetric is real but narrow: queries and documents live in two
 * differently-projected spaces, comparable only through whatever projection the
 * vendor ships. One shared space is migration insurance — both sides embedded
 * the same way stay comparable across a model change, so a query-model change
 * is not a reindex.
 *
 * `embed_input_type_mode` (shared|asymmetric) selects. The shipped DEFAULT is
 * still shared, i.e. the worse-retrieving option: flipping it changes retrieval
 * behaviour on every existing Voyage install and belongs in its own signed-off
 * commit rather than in a documentation fix. It is a setting rather than a
 * constant so the choice is reversible without a code deploy; anything
 * unrecognized resolves to shared.
 *
 * Shared mode sends "document" for BOTH sides on purpose: that is the value the
 * existing corpus was indexed with, so flipping the mode needs no reindex —
 * only the query-side projection changes. The mode changes the WIRE value only.
 * The query-side model and dtype are still chosen from the logical side of the
 * call (see build_embed_payload), so embed_query_model and the
 * float-query-against-int8-documents rule keep working exactly as before.
 *
 * OUTPUT WIDTH: `output_dimension` is an MRL truncation, per-model valid widths
 * in MODEL_PROFILES (256/512/1024/2048 across the current line, native 1024).
 * voyage-4-large at 2048 measured +14.0 pp recall on realistic queries for
 * under $4/month, and reduces rather than increases the reranker's necessity.
 * To adopt it an operator sets embed_model=voyage-4-large and
 * embed_dimensions=2048 and then REINDEXES: changing the model or the width
 * invalidates every stored vector, and rag_retriever refuses to score across
 * embedding spaces, so a half-migrated index retrieves nothing rather than
 * retrieving badly.
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

    /** The two input_type values the API accepts. */
    private const INPUT_TYPES = ['document', 'query'];

    /**
     * Wire input_type used for BOTH sides in shared mode.
     *
     * "document" and not "query" or an omitted parameter, because the indexed
     * corpus was written with "document": choosing it means switching modes
     * changes only the query projection and needs no reindex.
     */
    private const SHARED_INPUT_TYPE = self::DEFAULT_INPUT_TYPE;

    /** embed_input_type_mode: one shared space for queries and documents. */
    public const INPUT_MODE_SHARED = 'shared';

    /** embed_input_type_mode: vendor's asymmetric projection (see class docblock). */
    public const INPUT_MODE_ASYMMETRIC = 'asymmetric';

    /** Native default output width; sending it explicitly is redundant. */
    private const NATIVE_DIMENSION = 1024;

    /** Valid Matryoshka (MRL) output widths for the voyage-3.x / voyage-4 line. */
    private const VALID_DIMENSIONS = [256, 512, 1024, 2048];

    /**
     * Per-model output-width profiles, matched by prefix.
     *
     * `native` is the width the API returns when output_dimension is omitted,
     * so a configured width equal to it is not sent. `dims` are the MRL widths
     * the model accepts; anything else is omitted rather than sent and
     * rejected.
     *
     * This is an allowlist for the *width*, never for the model name: a model
     * absent from here still works and simply falls back to the line-wide
     * defaults above, because a new Voyage model must be adoptable by typing
     * its name into embed_model with no code deploy. Adding a row here only
     * makes the width validation exact for that model.
     *
     * Longest prefix wins, so 'voyage-4-large' cannot be swallowed by
     * 'voyage-4' — the same trap the rate card documents.
     */
    private const MODEL_PROFILES = [
        // v7.4.0: measured +14.0 pp on realistic queries at width 2048.
        'voyage-4-large'   => ['native' => self::NATIVE_DIMENSION, 'dims' => [256, 512, 1024, 2048]],
        'voyage-4-lite'    => ['native' => self::NATIVE_DIMENSION, 'dims' => [256, 512, 1024, 2048]],
        'voyage-4'         => ['native' => self::NATIVE_DIMENSION, 'dims' => [256, 512, 1024, 2048]],
        'voyage-context-4' => ['native' => self::NATIVE_DIMENSION, 'dims' => [256, 512, 1024, 2048]],
        'voyage-3.5-lite'  => ['native' => self::NATIVE_DIMENSION, 'dims' => [256, 512, 1024, 2048]],
        'voyage-3.5'       => ['native' => self::NATIVE_DIMENSION, 'dims' => [256, 512, 1024, 2048]],
        'voyage-3-large'   => ['native' => self::NATIVE_DIMENSION, 'dims' => [256, 512, 1024, 2048]],
    ];

    /**
     * Model prefixes with an exact width profile, longest first.
     *
     * @return string[]
     */
    public static function supported_models(): array {
        $models = array_keys(self::MODEL_PROFILES);
        usort($models, fn($a, $b) => strlen($b) <=> strlen($a));
        return $models;
    }

    /**
     * Width profile for a model name, or null when it has no explicit row.
     *
     * @param string $model
     * @return array|null ['native' => int, 'dims' => int[]]
     */
    public static function model_profile(string $model): ?array {
        $m = strtolower(trim($model));
        if ($m === '') {
            return null;
        }
        foreach (self::supported_models() as $prefix) {
            if ($m === $prefix || strpos($m, $prefix) === 0) {
                return self::MODEL_PROFILES[$prefix];
            }
        }
        return null;
    }

    /**
     * Width the API returns for this model when output_dimension is omitted.
     *
     * @param string $model
     * @return int
     */
    public static function native_dimension(string $model = ''): int {
        $profile = self::model_profile($model);
        return $profile === null ? self::NATIVE_DIMENSION : (int) $profile['native'];
    }

    /**
     * Wire input_type for a logical side of the call.
     *
     * Shared mode (the default — see the class docblock for why) sends one
     * input_type for both sides so queries and documents share an embedding
     * space. Asymmetric mode sends the vendor's per-side value.
     *
     * Pure when $mode is supplied, which is how the tests pin it; passing null
     * reads embed_input_type_mode. An unset, empty or unrecognized mode is
     * shared: this value reaches an outbound payload and decides whether the
     * corpus is queryable, so it must never propagate unvalidated.
     *
     * @param string $requested Logical side: 'document' or 'query'.
     * @param string|null $mode Explicit mode, or null to read config.
     * @return string The input_type to put on the wire.
     */
    public static function resolve_input_type(string $requested, ?string $mode = null): string {
        $requested = in_array($requested, self::INPUT_TYPES, true)
            ? $requested
            : self::DEFAULT_INPUT_TYPE;

        if ($mode === null) {
            $raw = get_config('local_ai_course_assistant', 'embed_input_type_mode');
            $mode = ($raw === false || trim((string) $raw) === '')
                ? self::INPUT_MODE_SHARED
                : strtolower(trim((string) $raw));
        } else {
            $mode = strtolower(trim($mode));
        }

        return ($mode === self::INPUT_MODE_ASYMMETRIC) ? $requested : self::SHARED_INPUT_TYPE;
    }

    /**
     * Resolve the output_dimension to send, or null to omit it (native default).
     *
     * Pure and testable. Only a valid non-native MRL width is sent; anything
     * else (0/unset, the native 1024, or an OpenAI-shaped width such as 1536
     * left over from a provider switch) omits the parameter so Voyage applies
     * its own default instead of rejecting the call.
     *
     * v7.4.0: $model narrows the valid set to that model's profile when it has
     * one. It is optional and defaults to the line-wide widths, so the
     * single-argument behaviour every existing caller and test relies on is
     * unchanged.
     *
     * @param int $configured Configured embed_dimensions (0 = unset).
     * @param string $model Model the width will be requested for ('' = any).
     * @return int|null Width to send, or null to omit.
     */
    public static function mrl_output_dimension(int $configured, string $model = ''): ?int {
        $valid = self::VALID_DIMENSIONS;
        $native = self::NATIVE_DIMENSION;
        $profile = self::model_profile($model);
        if ($profile !== null) {
            $valid = $profile['dims'];
            $native = (int) $profile['native'];
        }
        if (
            $configured > 0
                && $configured !== $native
                && in_array($configured, $valid, true)
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
     * Voyage's 4 series shares an embedding space, and embed_batch_typed() sends
     * the query model on query calls, so a separate query model is honored here.
     *
     * @return bool
     */
    public function supports_query_model(): bool {
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
     * Embed a single query for retrieval.
     *
     * "query" is the LOGICAL side of the call, which picks the query model and
     * the query dtype. Whether "query" or "document" goes on the wire is
     * decided by embed_input_type_mode — shared by default, so by default this
     * embeds into the same space as the documents. See the class docblock.
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

        $embeddings = [];

        foreach (array_chunk($texts, self::BATCH_SIZE) as $batch) {
            $payload = $this->build_embed_payload($batch, $inputtype);
            $dtype = (string) ($payload['output_dtype']
                ?? \local_ai_course_assistant\embedding_compat::DTYPE_FLOAT);
            $outputdim = $payload['output_dimension'] ?? null;

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
            $logicalwidth = $outputdim ?? self::native_dimension((string) $payload['model']);
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
     * Build the /embeddings request payload for one batch.
     *
     * Extracted from embed_batch_typed so the wire shape can be asserted in a
     * unit test: everything above this point is a billable network call, which
     * is how the asymmetric input_type sat unmeasured for three releases.
     * Pure apart from reading embed_input_type_mode.
     *
     * $inputtype is the LOGICAL side of the call. It selects the model and the
     * dtype; the input_type that goes on the wire is resolve_input_type()'s
     * business and, in the default shared mode, is the same for both sides.
     *
     * @param string[] $batch Texts for this request.
     * @param string $inputtype Logical side: 'document' or 'query'.
     * @return array Payload ready for json_encode.
     */
    public function build_embed_payload(array $batch, string $inputtype): array {
        $logical = in_array($inputtype, self::INPUT_TYPES, true)
            ? $inputtype
            : self::DEFAULT_INPUT_TYPE;

        // Queries may be embedded with a different model from documents when
        // both sit in a shared embedding space. Documents always use $model:
        // the stored corpus defines the space, so only the query side may vary.
        $model = ($logical === 'query') ? $this->querymodel : $this->model;

        $payload = [
            'model' => $model,
            'input' => array_values($batch),
            'input_type' => self::resolve_input_type($logical),
        ];

        // Send output_dtype only when it is not the API default. Omitting
        // it on float keeps the payload identical to what earlier releases
        // sent, so a site that never touches the new setting cannot be
        // affected by this code path at all.
        $dtype = $this->wire_dtype($logical);
        if ($dtype !== \local_ai_course_assistant\embedding_compat::DTYPE_FLOAT) {
            $payload['output_dtype'] = $dtype;
        }

        // Pass output_dimension only when the configured width is a valid
        // non-default MRL width for the model being called (256/512/2048);
        // 0/unset, the native 1024, or an invalid width (e.g. an OpenAI-shaped
        // 1536 left after a provider switch) omit it so Voyage applies its own
        // default rather than erroring. voyage-4-large @ 2048 is sent by this
        // branch, on both the document and the query call.
        $outputdim = self::mrl_output_dimension($this->dimensions, $model);
        if ($outputdim !== null) {
            $payload['output_dimension'] = $outputdim;
        }

        return $payload;
    }

    /**
     * Encoding to request for one logical side of the call.
     *
     * Document vectors take the configured dtype. The query side depends on
     * which dtype it has to be compared against:
     *
     *  - int8 documents are scored with a FLOAT query. Cosine is
     *    scale-invariant, so a full-precision query against quantized
     *    documents is both valid and strictly better than quantizing both
     *    sides — it keeps precision on the one vector computed fresh per
     *    request, which costs nothing extra.
     *
     *  - binary documents need a BINARY query, because bits are compared by
     *    Hamming distance and a float vector has no meaningful Hamming
     *    distance to a bit string. Crucially the query cannot be binarized
     *    locally: measured against a live response, sign(float) agrees with
     *    the API's binary output on only 87.5% of bits (chance is 50%, so
     *    the layout is right but the values are not). Voyage derives binary
     *    embeddings through a separate quantization path, so the binary
     *    query must come from the API.
     *
     * This keys off the LOGICAL side, not the wire input_type: in shared mode
     * both sides send input_type "document" while the query still has to come
     * back in the encoding it will be compared in.
     *
     * @param string $logical 'document' or 'query'.
     * @return string One of embedding_compat::DTYPES.
     */
    private function wire_dtype(string $logical): string {
        $configured = \local_ai_course_assistant\embedding_compat::normalize_dtype($this->dtype);
        if ($logical === 'document') {
            return $configured;
        }
        return ($configured === \local_ai_course_assistant\embedding_compat::DTYPE_BINARY)
            ? \local_ai_course_assistant\embedding_compat::DTYPE_BINARY
            : \local_ai_course_assistant\embedding_compat::DTYPE_FLOAT;
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
        $dtype = $this->wire_dtype($inputtype);
        $outputdim = self::mrl_output_dimension($this->dimensions, $this->model);

        $out = [];
        foreach ($this->batch_groups($groups) as $batch) {
            $payload = [
                'model' => $this->model,
                'inputs' => array_values($batch['inputs']),
                'input_type' => self::resolve_input_type($inputtype),
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
