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

        $embeddings = [];

        foreach (array_chunk($texts, self::BATCH_SIZE) as $batch) {
            $payload = [
                'model' => $this->model,
                'input' => $batch,
                'input_type' => $inputtype,
            ];

            // voyage-3.5 defaults to 1024 dimensions; valid MRL widths are
            // 256/512/1024/2048. Pass output_dimension only when admin asked
            // for a non-default width.
            if ($this->dimensions > 0 && $this->dimensions !== 1024) {
                $payload['output_dimension'] = $this->dimensions;
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
                $embeddings[] = $item['embedding'];
            }

            $usagetokens = (int) ($data['usage']['total_tokens'] ?? 0);
            if ($usagetokens > 0) {
                $this->log_embedding_cost($usagetokens);
            }
        }

        return $embeddings;
    }
}
