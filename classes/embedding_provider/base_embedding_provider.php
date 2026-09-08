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
 * Abstract base for embedding providers.
 *
 * Concrete implementations: openai_embedding_provider, ollama_embedding_provider.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_embedding_provider {
    /** @var string API key (may be empty for local providers). */
    protected string $apikey;

    /** @var string Model name. */
    protected string $model;

    /** @var string Base URL. */
    protected string $baseurl;

    /** @var int Expected embedding dimensions. */
    protected int $dimensions;

    /**
     * @var string Model used for QUERY-side embedding. Usually identical to
     * $model. Differs only when an administrator has set embed_query_model to
     * exploit a shared embedding space (see embedding_compat).
     */
    protected string $querymodel;

    /** @var string Encoding for stored document vectors; one of embedding_compat::DTYPES. */
    protected string $dtype;

    /**
     * Constructor — reads plugin RAG config.
     */
    public function __construct() {
        $this->apikey     = (string) (get_config('local_ai_course_assistant', 'embed_apikey') ?: '');
        $this->model      = (string) (get_config('local_ai_course_assistant', 'embed_model') ?: $this->get_default_model());
        // Query-side model. Empty (the default) means "same as the document
        // model", which is the only universally safe choice: vectors from
        // different models are comparable only within a declared shared
        // embedding space. Mixing is gated by embedding_compat at read time, so
        // a misconfiguration here degrades to a warning and a same-model
        // fallback rather than silently meaningless scores.
        $rawqm = get_config('local_ai_course_assistant', 'embed_query_model');
        $this->querymodel = (trim((string) $rawqm) !== '') ? trim((string) $rawqm) : $this->model;
        // Stored-vector encoding. Normalized against an allowlist rather than
        // trusted: this value reaches an outbound API payload and selects a
        // decode path, so an unrecognized string must resolve to float rather
        // than propagate.
        $this->dtype = \local_ai_course_assistant\embedding_compat::normalize_dtype(
            (string) get_config('local_ai_course_assistant', 'embed_dtype')
        );
        // 0 means "use the provider's native default width". An unset config
        // must NOT fall back to a hard 1536: that is only valid for OpenAI, and
        // forcing it on Voyage (whose MRL widths are 256/512/1024/2048) makes
        // every embedding call fail. Each provider skips sending an explicit
        // width when this is 0 and lets its own model default apply.
        $rawdim = get_config('local_ai_course_assistant', 'embed_dimensions');
        $this->dimensions = ($rawdim === false || $rawdim === '') ? 0 : (int) $rawdim;

        $configurl = get_config('local_ai_course_assistant', 'embed_apibaseurl');
        $this->baseurl = !empty($configurl) ? rtrim($configurl, '/') : $this->get_default_base_url();
    }

    /**
     * Get the default model name for this provider.
     *
     * @return string
     */
    abstract protected function get_default_model(): string;

    /**
     * Get the actual model name in use after construction-time resolution.
     * Callers that want to record the model that produced an embedding
     * should use this rather than reading embed_model config directly —
     * an empty embed_model resolves to the provider's default here.
     *
     * @return string
     */
    public function get_model(): string {
        return $this->model;
    }

    /**
     * Configured output width, or 0 to use the provider's native default.
     *
     * @return int
     */
    public function get_dimensions(): int {
        return $this->dimensions;
    }

    /**
     * Can this provider embed queries with a different model from documents?
     *
     * Only true where a shared embedding space actually exists and the adapter
     * sends the query model on the query call. A provider that returns false
     * ignores embed_query_model entirely, and get_query_model() reports the
     * document model so callers are told what really produced the vector.
     *
     * @return bool
     */
    public function supports_query_model(): bool {
        return false;
    }

    /**
     * Model that actually produced the query vector.
     *
     * The retriever uses this to decide whether a query may be scored against a
     * stored chunk, so it MUST name the model that did the embedding rather than
     * whatever is configured. For a provider that ignores embed_query_model this
     * is the document model.
     *
     * Returning the configured value unconditionally was a bug: with OpenAI
     * selected, setting embed_query_model made the comparability check test a
     * model that had embedded nothing, so every row was judged incomparable and
     * retrieval returned nothing at all — while the query vector had in fact
     * been produced by the ordinary document model and was perfectly usable.
     *
     * @return string
     */
    public function get_query_model(): string {
        return $this->supports_query_model() ? $this->querymodel : $this->model;
    }

    /**
     * Encoding used for stored document vectors.
     *
     * @return string One of embedding_compat::DTYPES.
     */
    public function get_dtype(): string {
        return $this->dtype;
    }

    /**
     * Does this provider support quantized output?
     *
     * Only providers that can actually return int8/binary vectors should
     * advertise this. A provider that cannot must keep writing float, because
     * recording a dtype the stored bytes do not match would make every affected
     * row unscoreable.
     *
     * @return bool
     */
    public function supports_dtype(): bool {
        return false;
    }

    /**
     * Effective dtype for writing: the configured dtype if the provider can
     * honor it, float otherwise.
     *
     * @return string
     */
    public function effective_dtype(): string {
        if (!$this->supports_dtype()) {
            return \local_ai_course_assistant\embedding_compat::DTYPE_FLOAT;
        }
        return $this->dtype;
    }

    /**
     * Get the default API base URL for this provider.
     *
     * @return string
     */
    abstract protected function get_default_base_url(): string;

    /**
     * Embed a single text string.
     *
     * @param string $text
     * @return float[] Embedding vector.
     * @throws \moodle_exception On API error.
     */
    abstract public function embed(string $text): array;

    /**
     * Embed multiple texts in a batch (may make multiple API calls).
     *
     * @param string[] $texts
     * @return float[][] Array of embedding vectors (same order as input).
     * @throws \moodle_exception On API error.
     */
    abstract public function embed_batch(array $texts): array;

    /**
     * Factory: create the configured embedding provider from plugin settings.
     *
     * @return base_embedding_provider
     * @throws \moodle_exception If embed_provider is not set or unsupported.
     */
    public static function create_from_config(): base_embedding_provider {
        $provider = (string) (get_config('local_ai_course_assistant', 'embed_provider') ?: 'openai');

        switch ($provider) {
            case 'openai':
                return new openai_embedding_provider();
            case 'ollama':
                return new ollama_embedding_provider();
            case 'voyage':
                return new voyage_embedding_provider();
            default:
                throw new \moodle_exception(
                    'chat:error_notconfigured',
                    'local_ai_course_assistant',
                    '',
                    null,
                    "Unknown embed_provider: {$provider}"
                );
        }
    }

    /**
     * Log embedding token usage to the msgs table for cost tracking.
     *
     * Uses a system-level conversation record so the cost appears in analytics
     * alongside chat token costs.
     *
     * @param int $tokens Total tokens used in this embedding call.
     */
    protected function log_embedding_cost(int $tokens): void {
        global $DB;
        try {
            // Use site-level (courseid=1) system record for embedding costs.
            // These are background indexing costs, not per-student.
            $record = new \stdClass();
            $record->conversationid = 0;
            $record->userid = 0;
            $record->courseid = SITEID;
            $record->role = 'system';
            $record->message = '[Embedding]';
            $record->tokens_used = $tokens;
            $record->prompt_tokens = $tokens;
            $record->completion_tokens = 0;
            $record->model_name = $this->model;
            $record->provider = 'embedding';
            $record->interaction_type = 'embedding';
            $record->timecreated = time();
            $DB->insert_record('local_ai_course_assistant_msgs', $record);
        } catch (\Throwable $e) {
            // Non-critical — don't break indexing if cost logging fails.
        }
    }

    /**
     * Make a POST request using Moodle's curl class.
     *
     * @param string $url
     * @param array  $headers
     * @param string $body JSON-encoded request body.
     * @return string Raw response body.
     * @throws \moodle_exception On HTTP errors.
     */
    protected function http_post(string $url, array $headers, string $body): string {
        global $CFG;
        if (!\local_ai_course_assistant\security::is_safe_provider_url($url)) {
            throw new \moodle_exception(
                'chat:error_generic',
                'local_ai_course_assistant',
                '',
                null,
                "embedding endpoint failed SSRF validation: {$url}"
            );
        }
        require_once($CFG->libdir . '/filelib.php'); // For \curl.
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_HTTPHEADER'    => $headers,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT'       => 120,
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
                "Embedding API HTTP {$httpcode}: {$response}"
            );
        }

        return $response;
    }
}
