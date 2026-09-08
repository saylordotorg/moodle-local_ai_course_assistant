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
 * rate-card format, and writes it row-by-row into the v7.4.0 model registry so
 * {@see token_cost_manager::get_rates()} returns the fresh values on the next
 * request — no plugin redeploy.
 *
 * Default upstream is the community-maintained LiteLLM model_prices file
 * (`github.com/BerriAI/litellm`), which carries `input_cost_per_token` and
 * `output_cost_per_token` per model. We multiply by 1,000,000 to match
 * SOLA's $/1M-tokens schema and filter out non-LLM modes (image gen, video,
 * moderation, etc).
 *
 * v7.4.0 fixes a real data-loss defect here. This class used to end with
 * set_config('rate_card_overrides', <the entire transformed blob>), which
 * REPLACED the override setting outright. Since the setting was also the only
 * place an admin could hand-enter or correct a price, every Monday at 02:30 the
 * scheduled task silently destroyed every hand-entered correction on the site —
 * including any correction made precisely because the community aggregator was
 * wrong about that model. Writes now go through
 * {@see model_registry::upsert()} with source 'upstream', and that method
 * refuses to overwrite a row whose source is 'manual'. The legacy blob is left
 * untouched and still readable, so nothing an existing site configured is lost.
 *
 * Designed to be safe to call repeatedly: SSRF-checked, malformed-input
 * tolerant, and on any failure it writes nothing at all, so the previous
 * prices stay in place and the rate card never silently empties.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rate_card_refresher {
    /** @var string Default upstream when admin has not configured one. */
    public const DEFAULT_UPSTREAM_URL =
        'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';

    /**
     * LiteLLM `mode` values we keep, mapped to a SOLA capability.
     *
     * v7.4.0 adds 'rerank'. It was absent, so the transform dropped every
     * reranker row and a reranker could never be auto-priced — while
     * voyage_reranker was live in the RAG path logging token counts against a
     * model the refresher structurally refused to learn a price for.
     *
     * @var array<string, string>
     */
    private const KEPT_MODES = [
        'chat'       => 'chat',
        'completion' => 'chat',
        'embedding'  => 'embedding',
        'rerank'     => 'rerank',
    ];

    /**
     * Modes billed on input tokens only, where a missing output cost means 0.
     *
     * For chat/completion a missing output cost means the row is incomplete and
     * must be skipped — pricing an LLM's output at zero is the same failure
     * class this whole class exists to fix.
     *
     * @var string[]
     */
    private const INPUT_ONLY_MODES = ['embedding', 'rerank'];

    /**
     * Fetch upstream and write the transformed rate card into the registry.
     *
     * The {ok, count, error} keys are unchanged for the two existing callers
     * (the weekly task and rate_card_refresh.php); `count` now means rows
     * actually written. The three extra keys report what the refusal rule did.
     *
     * @return array{ok:bool, count:int, error:string, inserted?:int, updated?:int, skipped?:int}
     */
    public static function refresh(): array {
        // Privacy: this is an outbound GET for provider pricing data only. No
        // personal data is transmitted, so no privacy external-location link is
        // declared for it (see classes/privacy/provider.php::get_metadata()).
        $url = (string) (get_config('local_ai_course_assistant', 'rate_card_upstream_url') ?: self::DEFAULT_UPSTREAM_URL);
        if (!security::is_safe_provider_url($url)) {
            return self::record_failure('Upstream URL rejected by SSRF allowlist: ' . $url);
        }

        global $CFG;
        require_once($CFG->dirroot . '/lib/filelib.php');
        $curl = new \curl();
        // Pin to the validated IP, closing the DNS-rebinding window.
        $body = $curl->get($url, [], array_merge(
            ['CURLOPT_TIMEOUT' => 30, 'CURLOPT_CONNECTTIMEOUT' => 10],
            security::resolve_pin_options($url)
        ));
        $code = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($code < 200 || $code >= 300 || $body === false || $body === '') {
            return self::record_failure('Upstream HTTP ' . $code . ' (empty or error response)');
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            return self::record_failure('Upstream did not return a JSON object');
        }

        $rows = self::transform_litellm($decoded);
        if (empty($rows)) {
            return self::record_failure('No usable chat / embedding / completion / rerank entries in upstream payload');
        }

        $result = self::apply_rows($rows);

        set_config('rate_card_last_refresh_at', (string) time(), 'local_ai_course_assistant');
        set_config('rate_card_last_refresh_status', 'success', 'local_ai_course_assistant');
        set_config('rate_card_last_refresh_error', '', 'local_ai_course_assistant');
        // Recorded separately from the error string: a run that skipped rows is
        // a SUCCESS (the human's price stood), but an admin comparing the
        // upstream table with what the site actually charges needs to know that
        // some rows were deliberately not taken.
        set_config('rate_card_last_refresh_skipped', (string) $result['skipped'], 'local_ai_course_assistant');

        return [
            'ok'       => true,
            'count'    => $result['inserted'] + $result['updated'],
            'error'    => '',
            'inserted' => $result['inserted'],
            'updated'  => $result['updated'],
            'skipped'  => $result['skipped'],
        ];
    }

    /**
     * Write transformed rows into the registry as source 'upstream'.
     *
     * Each row goes through {@see model_registry::upsert()} individually, so a
     * hand-entered 'manual' row survives the feed instead of being replaced by
     * a wholesale blob write. addedby is null: no human made this write, and
     * stamping the cron user would misreport the provenance the admin UI shows.
     *
     * @param array $rows
     * @return array{inserted: int, updated: int, skipped: int}
     */
    private static function apply_rows(array $rows): array {
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($rows as $key => $row) {
            $outcome = null;
            model_registry::upsert([
                'modelkey'       => $key,
                'provider'       => $row['provider'],
                'capability'     => $row['capability'],
                'input_rate'     => $row['input'],
                'output_rate'    => $row['output'],
                'context_tokens' => $row['context'],
                'status'         => 'active',
            ], 'upstream', null, $outcome);
            if ($outcome === 'inserted') {
                $inserted++;
            } else if ($outcome === 'updated') {
                $updated++;
            } else {
                $skipped++;
            }
        }
        return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Convert LiteLLM's per-token-USD rates to our $/1M-tokens schema and
     * filter to the modes we price. Tolerant of missing fields — entries that
     * lack the cost keys are skipped, not failed.
     *
     * Also carries the provider, capability and context window through, so a
     * registry row created by the feed is as complete as one an admin types.
     *
     * @param array $litellm The decoded LiteLLM JSON.
     * @return array<string, array{input: float, output: float, provider: ?string,
     *                             capability: string, context: ?int}>
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
            if (!isset(self::KEPT_MODES[$mode])) {
                continue;
            }
            $capability = self::KEPT_MODES[$mode];
            if (!isset($row['input_cost_per_token'])) {
                continue;
            }
            $inputonly = in_array($capability, self::INPUT_ONLY_MODES, true);
            if (!isset($row['output_cost_per_token']) && !$inputonly) {
                continue;
            }
            $input  = (float) $row['input_cost_per_token'] * 1_000_000.0;
            $output = (float) ($row['output_cost_per_token'] ?? 0.0) * 1_000_000.0;
            // Embedding and rerank entries legitimately have output=0; chat
            // entries with both 0 are placeholders or free-tier shells.
            if ($input <= 0.0 && $output <= 0.0) {
                continue;
            }
            $context = null;
            foreach (['max_input_tokens', 'max_tokens'] as $ctxfield) {
                if (isset($row[$ctxfield]) && (int) $row[$ctxfield] > 0) {
                    $context = (int) $row[$ctxfield];
                    break;
                }
            }
            $out[strtolower(trim($model))] = [
                'input'      => $input,
                'output'     => $output,
                'provider'   => isset($row['litellm_provider']) ? (string) $row['litellm_provider'] : null,
                'capability' => $capability,
                'context'    => $context,
            ];
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
