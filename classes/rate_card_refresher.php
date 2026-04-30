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

namespace local_ai_course_assistant;

/**
 * Pulls an upstream pricing JSON manifest, transforms it into SOLA's
 * rate-card format, and writes it to the `rate_card_overrides` config so
 * {@see token_cost_manager::get_rates()} returns the fresh values on the
 * next request — no plugin redeploy.
 *
 * Default upstream is the community-maintained LiteLLM model_prices file
 * (`github.com/BerriAI/litellm`), which carries `input_cost_per_token` and
 * `output_cost_per_token` per model. We multiply by 1,000,000 to match
 * SOLA's $/1M-tokens schema and filter out non-LLM modes (image gen, audio
 * transcription, etc).
 *
 * Designed to be safe to call repeatedly: SSRF-checked, malformed-input
 * tolerant, and on any failure it leaves the previous override in place
 * so the rate card never silently empties.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rate_card_refresher {

    /** @var string Default upstream when admin has not configured one. */
    public const DEFAULT_UPSTREAM_URL =
        'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';

    /** @var string[] LiteLLM `mode` values we keep. */
    private const KEPT_MODES = ['chat', 'completion', 'embedding'];

    /**
     * Fetch upstream and write the transformed rate card to config.
     *
     * @return array{ok:bool, count:int, error:string}
     */
    public static function refresh(): array {
        $url = (string) (get_config('local_ai_course_assistant', 'rate_card_upstream_url') ?: self::DEFAULT_UPSTREAM_URL);
        if (!security::is_safe_provider_url($url)) {
            return self::record_failure('Upstream URL rejected by SSRF allowlist: ' . $url);
        }

        global $CFG;
        require_once($CFG->dirroot . '/lib/filelib.php');
        $curl = new \curl();
        $body = $curl->get($url, [], ['CURLOPT_TIMEOUT' => 30, 'CURLOPT_CONNECTTIMEOUT' => 10]);
        $code = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($code < 200 || $code >= 300 || $body === false || $body === '') {
            return self::record_failure('Upstream HTTP ' . $code . ' (empty or error response)');
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            return self::record_failure('Upstream did not return a JSON object');
        }

        $rates = self::transform_litellm($decoded);
        if (empty($rates)) {
            return self::record_failure('No usable chat / embedding / completion entries in upstream payload');
        }

        $encoded = json_encode($rates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return self::record_failure('Failed to re-encode transformed rate card');
        }

        set_config('rate_card_overrides', $encoded, 'local_ai_course_assistant');
        set_config('rate_card_last_refresh_at', (string) time(), 'local_ai_course_assistant');
        set_config('rate_card_last_refresh_status', 'success', 'local_ai_course_assistant');
        set_config('rate_card_last_refresh_error', '', 'local_ai_course_assistant');

        return ['ok' => true, 'count' => count($rates), 'error' => ''];
    }

    /**
     * Convert LiteLLM's per-token-USD rates to our $/1M-tokens schema and
     * filter to LLM modes only. Tolerant of missing fields — entries that
     * lack the cost keys are skipped, not failed.
     *
     * @param array $litellm The decoded LiteLLM JSON.
     * @return array<string, array{input: float, output: float}>
     */
    private static function transform_litellm(array $litellm): array {
        $out = [];
        foreach ($litellm as $model => $row) {
            if (!is_string($model) || !is_array($row)) {
                continue;
            }
            // LiteLLM ships an `sample_spec` placeholder we should skip.
            if ($model === 'sample_spec') {
                continue;
            }
            $mode = isset($row['mode']) ? (string) $row['mode'] : 'chat';
            if (!in_array($mode, self::KEPT_MODES, true)) {
                continue;
            }
            if (!isset($row['input_cost_per_token']) || !isset($row['output_cost_per_token'])) {
                continue;
            }
            $input  = (float) $row['input_cost_per_token']  * 1_000_000.0;
            $output = (float) $row['output_cost_per_token'] * 1_000_000.0;
            // Embedding entries legitimately have output=0; chat entries
            // with both 0 are placeholders or free-tier shells we can skip.
            if ($input <= 0.0 && $output <= 0.0) {
                continue;
            }
            $out[strtolower(trim($model))] = ['input' => $input, 'output' => $output];
        }
        return $out;
    }

    /**
     * Persist a failure to config and return the standard result shape.
     *
     * @param string $error
     * @return array{ok:bool, count:int, error:string}
     */
    private static function record_failure(string $error): array {
        set_config('rate_card_last_refresh_at', (string) time(), 'local_ai_course_assistant');
        set_config('rate_card_last_refresh_status', 'error', 'local_ai_course_assistant');
        set_config('rate_card_last_refresh_error', $error, 'local_ai_course_assistant');
        debugging('rate_card_refresher: ' . $error, DEBUG_DEVELOPER);
        return ['ok' => false, 'count' => 0, 'error' => $error];
    }
}
