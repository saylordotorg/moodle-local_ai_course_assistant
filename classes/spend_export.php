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

defined('MOODLE_INTERNAL') || die();

/**
 * Builder behind spend_export.php: the Saylor AI Spend dashboard pull contract.
 *
 * The dashboard (https://saylor-ai-spend.streamlit.app) is PULL-ONLY: it has no
 * ingest endpoint, so nothing can be pushed to it. Per the contract documented
 * in saylordotorg/ai-spend-dashboard it calls, for each configured tool:
 *
 *   GET <endpoint>?month=YYYY-MM
 *   Authorization: Bearer <KEY>
 *   -> {"by_provider": {"openai": 12.34, "google": 5.67, ...}}
 *
 * Today SOLA is served to that dashboard by a Cloud Run FastAPI shim running
 * Redash queries. Everything here exists so that hop can be dropped: the plugin
 * speaks the contract itself.
 *
 * Every dollar figure the shim ever reported was a FLOOR, not the truth, because
 * gemini-2.5-flash — the production chat tutor — matched no rate-card prefix and
 * therefore priced at null, which every consumer treats as "nothing to add".
 * v7.4.0 corrects the rate card, but a rate card can always go stale again, so
 * this builder counts what it could not price (`unpriced_rows` /
 * `unpriced_models`) and puts the counters in the response `meta`. A silent zero
 * and a real zero must be distinguishable by the dashboard, not just by whoever
 * happens to read the database.
 *
 * All logic lives here rather than in the page so it is testable without HTTP.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class spend_export {
    /** @var string Config key holding the bearer key. Empty means the endpoint is off. */
    public const SETTING_KEY = 'spend_export_key';

    /** @var string Rate-limit bucket name (per-IP). */
    public const RATE_BUCKET = 'spend_export';

    /** @var int Requests per window per IP. A real dashboard pull is monthly. */
    public const RATE_MAX = 30;

    /** @var int Rate-limit window in seconds. */
    public const RATE_WINDOW = 60;

    /** @var string Audit action name written on every successful export. */
    public const AUDIT_ACTION = 'spend_export';

    /**
     * Plugin provider id => the vendor that actually sends the invoice.
     *
     * The dashboard groups by billing vendor ("google", "anthropic"), while the
     * `provider` column of the messages table holds SOLA's own provider ids
     * ("gemini", "claude"). Only the ids that DIFFER from their vendor are listed;
     * anything not listed passes through unchanged, so a provider added after this
     * was written is reported under its own name rather than silently dropped.
     *
     * @var array<string, string>
     */
    public const VENDOR_MAP = [
        'gemini' => 'google',
        'vertex' => 'google',
        'google' => 'google',
        'claude' => 'anthropic',
        'anthropic' => 'anthropic',
    ];

    /**
     * Resolve the UTC [start, end) timestamp range for a YYYY-MM month.
     *
     * The single source of truth for what "a month" means here, shared by the
     * page (which needs to reject a bad month with a 400 before doing any work)
     * and by analytics::get_monthly_provider_spend(). Two implementations of this
     * regex would eventually disagree about which rows belong to which month.
     *
     * The upper bound is exclusive and always present. An open-ended lower bound
     * (the "since" shape used elsewhere in this plugin) would make every month
     * include every earlier month, so each month's spend would be reported again
     * in every later pull — the dashboard would double-report rather than fail.
     *
     * Boundaries are UTC, not the site timezone: timecreated is a Unix timestamp
     * and the dashboard aggregates across tools, so the only defensible boundary
     * is the one that does not depend on where the Moodle server thinks it is.
     *
     * @param string $month Candidate month string.
     * @return array{0: int, 1: int}|null [start, end) or null when $month is not a valid YYYY-MM.
     */
    public static function month_range(string $month): ?array {
        // \z, not $: PCRE's $ also matches immediately BEFORE a final newline,
        // so "2026-09\n" would have been accepted. Nothing else here is anchored
        // loosely either — " 2026-09" is rejected rather than trimmed into
        // validity, because the caller's job is to send the contract's format
        // exactly, and quietly repairing a month means answering a different
        // question than the one asked.
        if (!preg_match('/^(\d{4})-(\d{2})\z/', $month, $m)) {
            return null;
        }
        $year = (int) $m[1];
        $mon = (int) $m[2];
        // "2026-00" and "2026-13" match the regex. gmmktime() would happily
        // normalize them into December 2025 and January 2027, silently answering
        // a question nobody asked.
        if ($mon < 1 || $mon > 12) {
            return null;
        }
        if ($year < 1970 || $year > 2999) {
            return null;
        }

        $start = gmmktime(0, 0, 0, $mon, 1, $year);
        // Month 13 is deliberate for December: gmmktime normalizes it to January
        // of the following year, which is exactly the exclusive upper bound.
        $end = gmmktime(0, 0, 0, $mon + 1, 1, $year);

        return [(int) $start, (int) $end];
    }

    /**
     * The current month in UTC, used when the caller omits the parameter.
     *
     * @return string YYYY-MM.
     */
    public static function current_month(): string {
        return gmdate('Y-m');
    }

    /**
     * Billing vendor for a SOLA provider id.
     *
     * @param string $provider Provider id as stored in the messages table.
     * @return string Vendor key for the dashboard.
     */
    public static function vendor_for(string $provider): string {
        $key = strtolower(trim($provider));
        if ($key === '') {
            return 'unknown';
        }
        return self::VENDOR_MAP[$key] ?? $key;
    }

    /**
     * Is the endpoint switched on?
     *
     * An empty key means off. The page answers 404 in that state rather than
     * 403: a 403 confirms the URL exists and is worth attacking, and on a site
     * that never configured this there is nothing there to talk about.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return self::configured_key() !== '';
    }

    /**
     * The configured bearer key, or '' when unset.
     *
     * @return string
     */
    public static function configured_key(): string {
        $key = get_config('local_ai_course_assistant', self::SETTING_KEY);
        return $key === false || $key === null ? '' : trim((string) $key);
    }

    /**
     * Constant-time check of a presented bearer key.
     *
     * Returns false when the endpoint is off, so a caller can never authenticate
     * against the empty string.
     *
     * @param string $presented Key supplied by the caller.
     * @return bool
     */
    public static function authenticate(string $presented): bool {
        $configured = self::configured_key();
        if ($configured === '' || $presented === '') {
            return false;
        }
        return hash_equals($configured, $presented);
    }

    /**
     * Extract the bearer token from a $_SERVER-shaped array.
     *
     * Both header spellings are read: mod_php exposes Authorization as
     * HTTP_AUTHORIZATION, while a CGI/FastCGI setup that has to rewrite it
     * exposes REDIRECT_HTTP_AUTHORIZATION. The key is NEVER read from the query
     * string — a secret in a URL ends up in access logs, browser history,
     * Referer headers and in whatever third party the URL was pasted into.
     *
     * @param array $server Typically $_SERVER.
     * @return string The token, or '' when no bearer header is present.
     */
    public static function bearer_from_server(array $server): string {
        $header = '';
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $name) {
            $candidate = trim((string) ($server[$name] ?? ''));
            if ($candidate !== '') {
                $header = $candidate;
                break;
            }
        }
        if ($header === '' || !preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return '';
        }
        return trim($m[1]);
    }

    /**
     * Build the response payload for one month.
     *
     * Shape is the contract plus a `meta` block:
     *   {
     *     "by_provider": {"openai": 12.34, "google": 5.67},
     *     "meta": {
     *       "month": "2026-09", "generated_at": "2026-09-08T00:00:00+00:00",
     *       "unpriced_rows": 0, "unpriced_models": [], "by_model": {...},
     *       "plugin_version": "7.4.0", "provider_aliases": {"gemini": "google"}
     *     }
     *   }
     *
     * `by_provider` is the only key the dashboard reads; everything diagnostic is
     * under `meta` so the contract stays exactly as documented.
     *
     * @param string $month YYYY-MM, already validated by the caller.
     * @return array Payload ready for json_encode (by_provider is an array; the
     *               page casts empty ones to objects — see encode()).
     */
    public static function build(string $month): array {
        $spend = analytics::get_monthly_provider_spend($month);

        // Fold SOLA provider ids into billing vendors. Two ids can land on one
        // vendor (gemini + vertex -> google), so this sums rather than assigns.
        $byvendor = [];
        $aliases = [];
        foreach ($spend['by_provider'] as $provider => $amount) {
            $vendor = self::vendor_for((string) $provider);
            if ($vendor !== (string) $provider) {
                $aliases[(string) $provider] = $vendor;
            }
            $byvendor[$vendor] = round(($byvendor[$vendor] ?? 0.0) + (float) $amount, 6);
        }
        ksort($byvendor);

        return [
            'by_provider' => $byvendor,
            'meta' => [
                'month' => $month,
                'generated_at' => gmdate('c'),
                // The honesty signal. Non-zero means this month's figure is a
                // floor: those rows were real API calls whose model had no price,
                // so they contributed $0.00 to every number above.
                'unpriced_rows' => $spend['unpriced_rows'],
                'unpriced_models' => $spend['unpriced_models'],
                'by_model' => $spend['by_model'],
                'plugin_version' => self::plugin_release(),
                'provider_aliases' => $aliases,
            ],
        ];
    }

    /**
     * JSON body for a payload, with the contract's object shapes preserved.
     *
     * json_encode() renders an empty PHP array as `[]`, so a month with no spend
     * would emit "by_provider": [] and break a consumer that indexes into an
     * object. The maps are cast so they are always objects; `unpriced_models` is
     * a list and stays an array.
     *
     * @param array $payload From build().
     * @return string JSON.
     */
    public static function encode(array $payload): string {
        $payload['by_provider'] = (object) ($payload['by_provider'] ?? []);
        if (isset($payload['meta']) && is_array($payload['meta'])) {
            $payload['meta']['by_model'] = (object) ($payload['meta']['by_model'] ?? []);
            $payload['meta']['provider_aliases'] = (object) ($payload['meta']['provider_aliases'] ?? []);
            $payload['meta']['unpriced_models'] = array_values($payload['meta']['unpriced_models'] ?? []);
        }
        // JSON_PRESERVE_ZERO_FRACTION: without it a provider at exactly 0.0 is
        // emitted as `0` and a consumer that type-checks sees an int in a map of
        // dollars. Every value in these maps is money and must look like it.
        return (string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * Plugin release string, read the same way redash_export.php reads it.
     *
     * @return string
     */
    private static function plugin_release(): string {
        $plugin = new \stdClass();
        require(__DIR__ . '/../version.php');
        return (string) ($plugin->release ?? 'unknown');
    }
}
