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

namespace local_ai_course_assistant\provider;

/**
 * Base provider with shared configuration and cURL helpers.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_provider implements provider_interface {

    /** @var string API key */
    protected string $apikey;

    /** @var string Model name */
    protected string $model;

    /** @var string Base URL */
    protected string $baseurl;

    /** @var float Temperature */
    protected float $temperature;

    /**
     * Constructor. Reads plugin config, with optional per-course overrides.
     *
     * @param array $overrides Optional config overrides from course_config_manager::get_effective_config().
     *                         Blank/absent keys fall through to the global plugin config.
     */
    public function __construct(array $overrides = []) {
        $rawkey = !empty($overrides['apikey'])
            ? $overrides['apikey']
            : (get_config('local_ai_course_assistant', 'apikey') ?: '');
        // Strip any descriptive label accidentally saved before the key
        // e.g. "OpenAI API Key sk-proj-..." → "sk-proj-..."
        $rawkey = trim($rawkey);
        $parts = preg_split('/\s+/', $rawkey);
        $this->apikey = count($parts) > 1 ? trim(end($parts)) : $rawkey;

        $adminmodel = get_config('local_ai_course_assistant', 'model');
        $this->model = !empty($overrides['model'])
            ? $overrides['model']
            : (!empty($adminmodel)
                ? $adminmodel
                : (\local_ai_course_assistant\remote_config_manager::get_value('model_default') ?: $this->get_default_model()));

        // Note: ?: would treat the string "0" as falsy and silently apply 0.7
        // when an admin explicitly set temperature=0 for deterministic output.
        // Use an explicit default-when-unset check instead.
        if (isset($overrides['temperature']) && $overrides['temperature'] !== '') {
            $this->temperature = (float) $overrides['temperature'];
        } else {
            $rawtemp = get_config('local_ai_course_assistant', 'temperature');
            $this->temperature = ($rawtemp === false || $rawtemp === '') ? 0.7 : (float) $rawtemp;
        }

        $configurl = !empty($overrides['apibaseurl'])
            ? $overrides['apibaseurl']
            : get_config('local_ai_course_assistant', 'apibaseurl');
        $this->baseurl = !empty($configurl) ? rtrim($configurl, '/') : $this->get_default_base_url();
    }

    /**
     * Get the default model for this provider.
     *
     * @return string
     */
    abstract protected function get_default_model(): string;

    /**
     * Get the default base URL for this provider.
     *
     * @return string
     */
    abstract protected function get_default_base_url(): string;

    /**
     * v5.10.0: best-effort detection of the backend context window
     * (max_model_len) via the OpenAI-compatible /v1/models endpoint that vLLM
     * and similar servers expose. Returns 0 when the window cannot be
     * determined (hosted providers, older servers, network/auth failure), in
     * which case the admin sets backend_context_tokens manually.
     *
     * @return int detected max_model_len, or 0 if unknown
     */
    public function detect_context_window(): int {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        try {
            // Most OpenAI-compatible base URLs already include /v1.
            $base = rtrim($this->baseurl, '/');
            $url = (strpos($base, '/v1') !== false) ? $base . '/models' : $base . '/v1/models';
            if (!\local_ai_course_assistant\security::is_safe_provider_url($url)) {
                return 0;
            }
            $curl = new \curl();
            $headers = [];
            if (!empty($this->apikey)) {
                $headers[] = 'Authorization: Bearer ' . $this->apikey;
            }
            $curl->setopt(array_merge([
                'CURLOPT_HTTPHEADER' => $headers,
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_TIMEOUT' => 10,
            ], \local_ai_course_assistant\security::resolve_pin_options($url)));
            $resp = $curl->get($url);
            $code = $curl->get_info()['http_code'] ?? 0;
            if ($code < 200 || $code >= 300) {
                return 0;
            }
            $data = json_decode($resp, true);
            if (!is_array($data) || empty($data['data']) || !is_array($data['data'])) {
                return 0;
            }
            foreach ($data['data'] as $modelinfo) {
                if (isset($modelinfo['max_model_len'])) {
                    return (int) $modelinfo['max_model_len'];
                }
            }
            return 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Make a non-streaming HTTP POST request using Moodle's curl class.
     *
     * @param string $url Full URL.
     * @param array $headers HTTP headers.
     * @param string $body JSON body.
     * @return string Response body.
     * @throws \moodle_exception On HTTP errors.
     */
    protected function http_post(string $url, array $headers, string $body): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php'); // For \curl.
        // v5.10.0: a saturated backend may reject with a transient 429/503
        // before any work is done; retry the whole (non-streaming) call.
        return self::with_transient_retry(function () use ($url, $headers, $body) {
            $curl = new \curl();
            $curl->setopt([
                'CURLOPT_HTTPHEADER' => $headers,
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_TIMEOUT' => 120,
            ]);

            $response = $curl->post($url, $body);
            $httpcode = $curl->get_info()['http_code'] ?? 0;

            $this->check_http_error($httpcode, $response);

            return $response;
        }, 0);
    }

    /**
     * v5.10.0: build a moodle_exception flagged as a transient (retryable)
     * backend error (HTTP 429 or 503). The flag rides in debuginfo so the
     * retry wrapper can distinguish it from a permanent error without
     * changing the user-facing string the SSE layer already handles.
     *
     * @param int $status the upstream HTTP status (429 or 503)
     * @param int|null $retryafter parsed Retry-After header value in seconds, if any
     */
    public static function transient_http_exception(int $status, ?int $retryafter): \moodle_exception {
        $key = $status === 429 ? 'chat:error_ratelimit' : 'chat:error_unavailable';
        $e = new \moodle_exception($key, 'local_ai_course_assistant');
        $e->debuginfo = json_encode(['transient' => true, 'status' => $status, 'retry_after' => $retryafter]);
        return $e;
    }

    /**
     * Is this exception a transient backend error, and what Retry-After (if any)?
     *
     * @param \Throwable $e the exception thrown by the upstream call
     * @return array{0:bool,1:int|null} [transient, retry_after_seconds]
     */
    private static function is_transient(\Throwable $e): array {
        if ($e instanceof \moodle_exception && !empty($e->debuginfo) && is_string($e->debuginfo)) {
            $d = json_decode($e->debuginfo, true);
            if (is_array($d) && !empty($d['transient'])) {
                $ra = isset($d['retry_after']) && $d['retry_after'] !== null ? (int) $d['retry_after'] : null;
                return [true, $ra];
            }
        }
        return [false, null];
    }

    /**
     * Seam so unit tests do not actually sleep between retries.
     *
     * @param float $seconds
     */
    protected static function backoff_sleep(float $seconds): void {
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            return;
        }
        if ($seconds > 0) {
            usleep((int) round($seconds * 1000000));
        }
    }

    /**
     * v5.10.0: run $fn, retrying on a transient 429/503 up to
     * backend_retry_attempts times, but only while $streamedtokens === 0 so a
     * mid-stream failure is never retried (which would duplicate visible
     * output). Honors a Retry-After value capped at backend_retry_max_wait;
     * otherwise uses exponential backoff. Re-throws the last exception when
     * retries are exhausted or the error is not transient.
     *
     * @param callable $fn the operation to run/retry
     * @param int $streamedtokens count of real output tokens already streamed (0 = safe to retry)
     * @return mixed whatever $fn returns
     */
    public static function with_transient_retry(callable $fn, int $streamedtokens) {
        $attempts = (int) get_config('local_ai_course_assistant', 'backend_retry_attempts');
        if ($attempts < 0) {
            $attempts = 0;
        }
        $rawmax = get_config('local_ai_course_assistant', 'backend_retry_max_wait');
        $maxwait = ($rawmax === false || $rawmax === '') ? 5 : (int) $rawmax;

        $tries = 0;
        $backoffs = [0.5, 1.5, 3.0];
        while (true) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                [$transient, $retryafter] = self::is_transient($e);
                $canretry = $transient && $streamedtokens === 0 && $tries < $attempts;
                if (!$canretry) {
                    throw $e;
                }
                $wait = $retryafter !== null ? min($retryafter, $maxwait) : ($backoffs[$tries] ?? 3.0);
                self::backoff_sleep($wait);
                $tries++;
            }
        }
    }

    /**
     * Make a streaming HTTP POST request using raw curl with WRITEFUNCTION.
     *
     * @param string $url Full URL.
     * @param array $headers HTTP headers.
     * @param string $body JSON body.
     * @param callable $writecallback Called with each chunk of response data.
     * @throws \moodle_exception On HTTP errors.
     */
    protected function http_post_stream(string $url, array $headers, string $body, callable $writecallback): void {
        // SSRF validation. Every provider driver lands here for its outbound
        // call, so one gate closes the internal-address attack vector for all
        // 12 drivers. Fails loudly so a misconfigured endpoint cannot silently
        // be redirected at 127.0.0.1 or 169.254.169.254.
        if (!\local_ai_course_assistant\security::is_safe_provider_url($url)) {
            throw new \moodle_exception('error', 'local_ai_course_assistant', '',
                'Provider endpoint rejected by SSRF validator: ' . $url);
        }

        global $CFG;

        // v5.10.0: wrap the stream in the bounded transient-retry. A header
        // function captures the status before any body arrives; the write
        // function swallows error-body bytes (status >= 400) so a clean 429/503
        // rejection forwards zero real tokens and is safe to retry. A transport
        // error (which may occur mid-stream) is thrown as non-transient and is
        // never retried, so visible output cannot be duplicated.
        self::with_transient_retry(function () use ($url, $headers, $body, $writecallback, $CFG) {
            $status = 0;
            $retryafter = null;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$status, &$retryafter) {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                        $status = (int) $m[1];
                    } else if (stripos($header, 'Retry-After:') === 0) {
                        $val = trim(substr($header, strlen('Retry-After:')));
                        if (is_numeric($val)) {
                            $retryafter = (int) $val;
                        }
                    }
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($writecallback, &$status) {
                    if ($status >= 400) {
                        return strlen($data); // Swallow error body; keep call retry-safe.
                    }
                    $writecallback($data);
                    return strlen($data);
                },
            ]);

            // Add proxy settings from Moodle config if present.
            if (!empty($CFG->proxyhost)) {
                curl_setopt($ch, CURLOPT_PROXY, $CFG->proxyhost);
                if (!empty($CFG->proxyport)) {
                    curl_setopt($ch, CURLOPT_PROXYPORT, $CFG->proxyport);
                }
                if (!empty($CFG->proxyuser)) {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $CFG->proxyuser . ':' . ($CFG->proxypassword ?? ''));
                }
            }

            // Pin the connection to the IP validated above, closing the
            // DNS-rebinding window (no-op under a proxy / literal IP / trusted host).
            \local_ai_course_assistant\security::pin_curl_handle($ch, $url);

            curl_exec($ch);

            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                // Transport error: possibly mid-stream, so non-transient.
                throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null, $error);
            }

            if ($httpcode === 429) {
                throw self::transient_http_exception(429, $retryafter);
            }
            if ($httpcode === 503) {
                throw self::transient_http_exception(503, $retryafter);
            }
            if ($httpcode >= 400) {
                $this->check_http_error($httpcode, '');
            }
        }, 0);
    }

    /**
     * Check HTTP status code and throw appropriate exception.
     *
     * @param int $httpcode
     * @param string $response
     * @throws \moodle_exception
     */
    protected function check_http_error(int $httpcode, string $response): void {
        if ($httpcode >= 200 && $httpcode < 300) {
            return;
        }

        if ($httpcode === 401 || $httpcode === 403) {
            throw new \moodle_exception('chat:error_auth', 'local_ai_course_assistant');
        }

        if ($httpcode === 429) {
            // v5.10.0: transient — the retry wrapper may re-attempt before streaming.
            throw self::transient_http_exception(429, null);
        }

        if ($httpcode === 404) {
            // Model not found — likely misspelled or deprecated.
            $defaultmodel = $this->get_default_model();
            $detail = "The model \"{$this->model}\" was not found (HTTP 404). "
                . "Check the model name in Site Admin > Plugins > AI Course Assistant. "
                . "The default for this provider is \"{$defaultmodel}\".";
            throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null, $detail);
        }

        if ($httpcode === 503) {
            // v5.10.0: transient — backend temporarily unavailable/overloaded.
            throw self::transient_http_exception(503, null);
        }

        if ($httpcode >= 500) {
            throw new \moodle_exception('chat:error_unavailable', 'local_ai_course_assistant');
        }

        throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null, "HTTP {$httpcode}: {$response}");
    }

    /**
     * Whether this provider can retry with its default model after a 404 error.
     *
     * @return bool True if the configured model differs from the default.
     */
    public function can_retry_with_default_model(): bool {
        return !empty($this->model) && $this->model !== $this->get_default_model();
    }

    /**
     * Reset the model to the provider's default for a retry attempt.
     */
    public function use_default_model(): void {
        $this->model = $this->get_default_model();
    }

    /**
     * Get token usage from the last streaming call.
     *
     * Default implementation returns null. Providers that support usage reporting
     * (OpenAI-compatible with stream_options, Claude) override this.
     *
     * @return array|null
     */
    public function get_last_token_usage(): ?array {
        return null;
    }

    /**
     * Factory method to create a provider from plugin config, with optional per-course overrides.
     *
     * @param int $courseid Course ID to look up per-course overrides (0 = use global only).
     * @return provider_interface
     * @throws \moodle_exception If provider is not configured.
     */
    public static function create_from_config(int $courseid = 0): provider_interface {
        $overrides = \local_ai_course_assistant\course_config_manager::get_effective_config($courseid);
        $provider = !empty($overrides['provider'])
            ? $overrides['provider']
            : (get_config('local_ai_course_assistant', 'provider') ?: '');

        // Spend guard: consult the cap before instantiation. If the site is
        // over the cap for chat/analytics workload, try the failover chain.
        // If no failover is configured, throw; the SSE handler catches this
        // and shows a friendly "budget paused" message to the student.
        // Read defensively; a fresh install has no caps and this is a no-op.
        try {
            $level = spend_guard::check($courseid, self::infer_capability_for_primary($courseid));
            if ($level === spend_guard::CAP_BLOCKED) {
                $failover = spend_guard::resolve_failover('chat');
                if ($failover !== null) {
                    $overrides['provider'] = $failover['provider'];
                    $overrides['apikey']   = $failover['apikey'];
                    $provider = $failover['provider'];
                } else {
                    throw new \moodle_exception('error', 'local_ai_course_assistant', '',
                        'SOLA spend cap reached for this period; no failover provider configured.');
                }
            }
        } catch (\moodle_exception $budgeterr) {
            throw $budgeterr;
        } catch (\Throwable $ignore) {
            // Never let the guard break core flow on a fresh install.
        }

        $primary = self::instantiate($provider, $overrides);

        // v5.5.0: optional per-call failover. Off by default until admins
        // build confidence. When the failover_per_call_enabled flag is set
        // AND the configured chain has at least one resolvable fallback,
        // wrap the primary in a failover_chain decorator that tries each
        // fallback on per-call timeout / 5xx.
        if ((bool) get_config('local_ai_course_assistant', 'failover_per_call_enabled')) {
            try {
                $chain = spend_guard::resolve_failover_chain('chat');
                if (!empty($chain)) {
                    $fallbacks = [];
                    foreach ($chain as $entry) {
                        $entryoverrides = $overrides;
                        $entryoverrides['provider'] = $entry['provider'];
                        $entryoverrides['apikey']   = $entry['apikey'];
                        // v5.5.2: per-row base URL override flows from
                        // comparison_providers through resolve_failover_chain.
                        if (!empty($entry['apibaseurl'])) {
                            $entryoverrides['apibaseurl'] = $entry['apibaseurl'];
                        } else {
                            // Make sure a parent override doesn't leak into
                            // a row that didn't set one.
                            unset($entryoverrides['apibaseurl']);
                        }
                        $fallbacks[] = [
                            'provider' => self::instantiate($entry['provider'], $entryoverrides),
                            'label'    => $entry['label'],
                        ];
                    }
                    global $USER;
                    $primarylabel = self::label_for_active_provider($provider, $overrides);
                    $rawtimeout = get_config('local_ai_course_assistant', 'failover_timeout_chat');
                    $timeoutseconds = ($rawtimeout === false || $rawtimeout === '') ? 8 : (int) $rawtimeout;
                    $primary = new failover_chain($primary, $primarylabel, $fallbacks, [
                        'timeout_seconds' => $timeoutseconds,
                        'audit'           => true,
                        'courseid'        => $courseid,
                        'userid'          => (int) ($USER->id ?? 0),
                    ]);
                }
            } catch (\Throwable $ignore) {
                // Never let chain construction break the primary call path.
            }
        }

        return $primary;
    }

    /**
     * Derive a stable label for the primary provider so the failover_chain
     * decorator can track its circuit state. Tries to find a matching row
     * in comparison_providers (where labels and provider IDs are the same
     * shape); falls back to "<providerid>-primary" if no match exists.
     *
     * @param string $providerid
     * @param array $overrides
     * @return string
     */
    private static function label_for_active_provider(string $providerid, array $overrides): string {
        $raw = (string) (get_config('local_ai_course_assistant', 'comparison_providers') ?: '');
        $apikey = (string) ($overrides['apikey'] ?? '');
        foreach (preg_split("/\r?\n/", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if (strtolower($parts[0] ?? '') === strtolower($providerid)
                && ($apikey === '' || ($parts[1] ?? '') === $apikey)) {
                return strtolower($parts[0]);
            }
        }
        return strtolower($providerid) . '-primary';
    }

    /**
     * For the primary factory (used by both student chat and Learning Radar
     * via `create_from_config`), we can't always tell chat vs analytics from
     * the call site. Default to 'chat' — Learning Radar also respects the
     * chat cap since analytics workload is tiny in comparison. If an admin
     * specifically caps analytics, meta_ai_sse can pass a capability hint
     * directly via `spend_guard::check` before calling this factory.
     *
     * @param int $courseid
     * @return string
     */
    private static function infer_capability_for_primary(int $courseid): string {
        // No cheap way to distinguish from here; use 'chat' as the common case.
        return 'chat';
    }

    /**
     * Factory for the admin LLM comparison picker. Looks up the API key from
     * the comparison_providers admin setting, falling back to the primary key.
     *
     * @param string $providerid Provider ID selected by the admin.
     * @param string $model Model name selected by the admin (may be blank).
     * @param int $courseid Course context for base config inheritance.
     * @return provider_interface
     * @throws \moodle_exception If provider is unknown.
     */
    public static function create_for_comparison(string $providerid, string $model, int $courseid = 0): provider_interface {
        $overrides = \local_ai_course_assistant\course_config_manager::get_effective_config($courseid);
        $overrides['provider'] = $providerid;
        if ($model !== '') {
            $overrides['model'] = $model;
        }

        $row = self::lookup_comparison_row($providerid);
        if ($row !== null) {
            if (!empty($row['apikey'])) {
                $overrides['apikey'] = $row['apikey'];
            }
            if ($row['temperature'] !== '') {
                $overrides['temperature'] = $row['temperature'];
            }
            // v5.5.2: per-row base URL override (5th column). Empty means
            // use the provider class's hardcoded default.
            if (!empty($row['apibaseurl'])) {
                $overrides['apibaseurl'] = $row['apibaseurl'];
            }
        }

        return self::instantiate($providerid, $overrides);
    }

    /**
     * Look up a comparison provider row from the admin textarea.
     *
     * v5.5.2: added optional `apibaseurl` field as the 5th column of each
     * row. Blank or absent means "use the provider class's default base URL."
     *
     * @param string $providerid
     * @return array|null Row with apikey, models, temperature, apibaseurl keys, or null if not found.
     */
    private static function lookup_comparison_row(string $providerid): ?array {
        $raw = get_config('local_ai_course_assistant', 'comparison_providers') ?: '';
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) >= 2 && strtolower($parts[0]) === $providerid) {
                return [
                    'apikey'      => $parts[1] ?? '',
                    'models'      => $parts[2] ?? '',
                    'temperature' => $parts[3] ?? '',
                    'apibaseurl'  => $parts[4] ?? '',
                ];
            }
        }
        return null;
    }

    /**
     * Instantiate a provider by ID with the given overrides.
     *
     * @param string $provider Provider ID.
     * @param array $overrides Config overrides (apikey, model, etc.).
     * @return provider_interface
     * @throws \moodle_exception If provider is unknown.
     */
    private static function instantiate(string $provider, array $overrides): provider_interface {
        switch ($provider) {
            case 'claude':
                return new claude_provider($overrides);
            case 'openai':
                return new openai_provider($overrides);
            case 'ollama':
                return new ollama_provider($overrides);
            case 'minimax':
                return new minimax_provider($overrides);
            case 'deepseek':
                return new deepseek_provider($overrides);
            case 'gemini':
                return new gemini_provider($overrides);
            case 'mistral':
                return new mistral_provider($overrides);
            case 'openrouter':
                return new openrouter_provider($overrides);
            case 'together':
                return new together_provider($overrides);
            case 'xai':
                return new xai_provider($overrides);
            case 'coreai':
                return new coreai_provider($overrides);
            case 'custom':
                return new custom_provider($overrides);
            case 'stub':
                // Test-only canned-response provider. Production callers never
                // configure provider='stub'; PHPUnit suites (v5.3.26+) flip
                // it via set_config() to exercise generate_quiz, generate_
                // flashcards, score_essay, and generate_insights without
                // touching upstream APIs.
                return new stub_provider($overrides);
            default:
                throw new \moodle_exception('chat:error_notconfigured', 'local_ai_course_assistant');
        }
    }
}
