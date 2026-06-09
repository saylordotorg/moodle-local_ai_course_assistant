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
 * Voyage AI re-ranker (rerank-2.5). Cross-encoder used as stage 2 of
 * two-stage retrieval: embedding-based cosine returns top-N candidates,
 * the reranker scores each (query, document) pair, and the top-K results
 * by relevance feed the chat prompt.
 *
 * v5.11.0: introduced per the vendor recommendations doc. ~40x cheaper than
 * Cohere Rerank 3.5 for near-equivalent quality; published recall lifts of
 * +15 Recall@10 on enterprise corpora and +39% NDCG on BEIR over BM25.
 *
 * Pricing: $0.05/MTok (rate-card-tracked). At a typical SOLA workload of 5
 * RAG-backed turns per learner per month and ~50 chunks reranked per turn,
 * expect ~$63/mo at 100k Saylor MAU.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class voyage_reranker {

    /** @var string */
    private string $apikey;

    /** @var string */
    private string $model;

    /** @var string */
    private string $baseurl;

    /**
     * Constructor — reads rerank config (apikey shared with voyage embed
     * config when same Voyage account; separate rerank_apikey override
     * supported for split-account deployments).
     */
    public function __construct() {
        $this->apikey = (string) (get_config('local_ai_course_assistant', 'rerank_apikey') ?: '');
        if ($this->apikey === '') {
            // Fall back to the embed key — typical Voyage deployments share one key.
            $this->apikey = (string) (get_config('local_ai_course_assistant', 'embed_apikey') ?: '');
        }
        $this->model = (string) (get_config('local_ai_course_assistant', 'rerank_model') ?: 'rerank-2.5');

        $configurl = get_config('local_ai_course_assistant', 'rerank_apibaseurl');
        if ($configurl === false || $configurl === '') {
            $configurl = get_config('local_ai_course_assistant', 'embed_apibaseurl');
        }
        $this->baseurl = !empty($configurl) ? rtrim($configurl, '/') : 'https://api.voyageai.com/v1';
    }

    /**
     * Whether the reranker is configured (has an API key).
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->apikey !== '';
    }

    /**
     * Score (query, document) pairs and return the top-k indices into
     * $documents, ordered by descending relevance.
     *
     * @param string $query
     * @param string[] $documents
     * @param int $topk
     * @return array<int,array{index:int,score:float}> Indices into $documents.
     * @throws \moodle_exception On API error.
     */
    public function rerank(string $query, array $documents, int $topk): array {
        if (empty($documents) || $topk <= 0) {
            return [];
        }
        if (!$this->is_configured()) {
            throw new \moodle_exception(
                'chat:error_notconfigured',
                'local_ai_course_assistant',
                '',
                null,
                'Voyage reranker called without an API key configured.'
            );
        }

        $topk = min($topk, count($documents));

        $payload = [
            'query' => $query,
            'documents' => array_values($documents),
            'model' => $this->model,
            'top_k' => $topk,
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apikey,
        ];

        $response = $this->http_post(
            $this->baseurl . '/rerank',
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
                'Voyage rerank response missing data array'
            );
        }

        $results = [];
        foreach ($data['data'] as $item) {
            $results[] = [
                'index' => (int) ($item['index'] ?? -1),
                'score' => (float) ($item['relevance_score'] ?? 0),
            ];
        }
        // Voyage already orders by descending score, but normalize defensively.
        usort($results, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $tokens = (int) ($data['usage']['total_tokens'] ?? 0);
        if ($tokens > 0) {
            $this->log_rerank_cost($tokens);
        }

        return $results;
    }

    /**
     * Log rerank token usage so it lands in the same per-component spend
     * dashboards as embedding and chat costs.
     *
     * @param int $tokens
     */
    private function log_rerank_cost(int $tokens): void {
        global $DB;
        try {
            $record = new \stdClass();
            $record->conversationid = 0;
            $record->userid = 0;
            $record->courseid = SITEID;
            $record->role = 'system';
            $record->message = '[Rerank]';
            $record->tokens_used = $tokens;
            $record->prompt_tokens = $tokens;
            $record->completion_tokens = 0;
            $record->model_name = $this->model;
            $record->provider = 'rerank';
            $record->interaction_type = 'rerank';
            $record->timecreated = time();
            $DB->insert_record('local_ai_course_assistant_msgs', $record);
        } catch (\Throwable $e) {
            // Non-critical: don't break retrieval if cost logging fails.
        }
    }

    /**
     * HTTP POST via Moodle's curl class with SSRF guard.
     *
     * @param string $url
     * @param string[] $headers
     * @param string $body
     * @return string
     * @throws \moodle_exception On HTTP error.
     */
    private function http_post(string $url, array $headers, string $body): string {
        global $CFG;
        if (!\local_ai_course_assistant\security::is_safe_provider_url($url)) {
            throw new \moodle_exception('chat:error_generic', 'local_ai_course_assistant',
                '', null, "rerank endpoint failed SSRF validation: {$url}");
        }
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_HTTPHEADER'    => $headers,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT'       => 30,
        ]);

        $response = $curl->post($url, $body);
        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($httpcode < 200 || $httpcode >= 300) {
            if ($httpcode === 401 || $httpcode === 403) {
                throw new \moodle_exception('chat:error_auth', 'local_ai_course_assistant');
            }
            if ($httpcode === 429) {
                throw new \moodle_exception('chat:error_ratelimit', 'local_ai_course_assistant');
            }
            throw new \moodle_exception(
                'chat:error',
                'local_ai_course_assistant',
                '',
                null,
                "Voyage rerank HTTP {$httpcode}: {$response}"
            );
        }

        return $response;
    }
}
