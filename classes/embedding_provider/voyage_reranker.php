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
     * Constructor: reads rerank config. The key comes from, in order: the provider row chosen in
     * rerank_key_from, the pasted rerank_apikey (split-account deployments), then the key Embedding
     * resolves to (typical Voyage deployments share one key).
     *
     * A chosen row the key identifies as another vendor's is skipped, since the key goes to Voyage (unless a
     * base URL is set: a gateway takes any key). The next source is used instead, silently. The same check
     * applies to the embedding key when Embedding itself took it from a chosen row.
     */
    public function __construct() {
        $configurl = get_config('local_ai_course_assistant', 'rerank_apibaseurl');
        if ($configurl === false || $configurl === '') {
            $configurl = get_config('local_ai_course_assistant', 'embed_apibaseurl');
        }

        $this->apikey = \local_ai_course_assistant\provider_keys::key_from_setting(
            'rerank_key_from',
            'voyage',
            (string) $configurl
        );
        if ($this->apikey === '') {
            $this->apikey = (string) (\local_ai_course_assistant\secrets::get('rerank_apikey') ?: '');
        }
        if ($this->apikey === '') {
            // Fall back to the embed key. A pasted one is used exactly as it always was (typical Voyage deployments
            // share one key). One Embedding took from a chosen provider row is vendor-checked against Voyage like
            // Rerank's own choice: an OpenAI row chosen for Embedding must not reach Voyage by this road.
            $embed = base_embedding_provider::resolve_apikey_source();
            if (!$embed['fromrow']
                    || \local_ai_course_assistant\course_key_guard::conflict('voyage', $embed['key'], (string) $configurl) === '') {
                $this->apikey = $embed['key'];
            }
        }
        $this->model = (string) (get_config('local_ai_course_assistant', 'rerank_model') ?: 'rerank-2.5');

        $this->baseurl = !empty($configurl) ? rtrim($configurl, '/') : 'https://api.voyageai.com/v1';
    }

    /**
     * The key this instance will send. Read-only, so a test can check the choice without a network call.
     *
     * @return string
     */
    public function resolved_key(): string {
        return $this->apikey;
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
     * Record that stage 2 was gated off for this query, and why.
     *
     * A skipped rerank has to be observable. Without a row here the only
     * evidence that a gate is working is spend going down, which is
     * indistinguishable from the reranker being misconfigured, the Voyage key
     * having expired, or retrieval returning nothing at all — three failures
     * that also look like a cost drop. So the skip is written to the same
     * telemetry channel as log_rerank_cost() (which is untouched: a rerank that
     * DOES run still logs its tokens), and an operator can count both:
     *
     *   SELECT message, COUNT(*) FROM {local_ai_course_assistant_msgs}
     *    WHERE interaction_type IN ('rerank', 'rerank_skipped')
     *      AND timecreated > ? GROUP BY message;
     *
     * interaction_type is 'rerank_skipped', deliberately NOT 'rerank': these
     * rows carry zero tokens, and analytics::spend_rows_predicate() names the
     * billable system interaction types explicitly, so a new one is excluded
     * from spend, from the RAG cap in spend_guard, and from token analytics
     * rather than diluting cost-per-rerank with rows that cost nothing.
     * role='system' keeps them out of learner history, the model's context and
     * conversation_rows_predicate() for the same reason the cost rows are.
     *
     * Static because the whole point is that no reranker was constructed and no
     * API call was made; the model name is read from config only so the row
     * says which reranker would have run.
     *
     * @param string $reason Machine-readable gate reason, e.g. 'short_query'.
     * @param int $courseid Course whose retrieval was gated (0 = site).
     */
    public static function log_skip(string $reason, int $courseid = 0): void {
        global $DB;
        try {
            // Allowlisted shape rather than trusted: this string is
            // concatenated into a stored message, and a caller-supplied reason
            // must not be able to put arbitrary text there.
            $slug = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($reason)));
            if ($slug === '' || $slug === null) {
                $slug = 'unspecified';
            }
            $record = new \stdClass();
            $record->conversationid = 0;
            $record->userid = 0;
            $record->courseid = $courseid > 0 ? $courseid : SITEID;
            $record->role = 'system';
            $record->message = '[Rerank skipped: ' . $slug . ']';
            $record->tokens_used = 0;
            $record->prompt_tokens = 0;
            $record->completion_tokens = 0;
            $record->model_name = (string) (
                get_config('local_ai_course_assistant', 'rerank_model') ?: 'rerank-2.5'
            );
            $record->provider = 'rerank';
            $record->interaction_type = 'rerank_skipped';
            $record->timecreated = time();
            $DB->insert_record('local_ai_course_assistant_msgs', $record);
        } catch (\Throwable $e) {
            // Non-critical: never break retrieval to record that it was cheap.
            debugging('rerank skip telemetry failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
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
            throw new \moodle_exception(
                'chat:error_generic',
                'local_ai_course_assistant',
                '',
                null,
                "rerank endpoint failed SSRF validation: {$url}"
            );
        }
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_HTTPHEADER'    => $headers,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT'       => 30,
        ]);

        // Pin to the validated IP, closing the DNS-rebinding window.
        $response = $curl->post(
            $url,
            $body,
            \local_ai_course_assistant\security::resolve_pin_options($url)
        );
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
