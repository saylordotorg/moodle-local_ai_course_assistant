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
 * Fetch and parse a pricing source described by a DATABASE ROW (v7.4.0).
 *
 * The design constraint this class exists to satisfy: after the next production
 * release there are no code deploys and no filesystem access, so **adding a
 * vendor price feed has to be a form, not a class**. A source is therefore a
 * row in {@see model_registry::TABLE_SOURCES} carrying a url, a `format` from a
 * fixed set of four generic parsers, and a declarative JSON `spec` — never a
 * per-vendor PHP subclass. Four formats cover every shape a pricing feed has
 * come in so far:
 *
 *  - `litellm`      — the community model_prices_and_context_window.json map.
 *  - `openrouter`   — https://openrouter.ai/api/v1/models (public, no auth).
 *  - `json_generic`  — any JSON, walked with dot-paths supplied in the spec.
 *  - `html_regex`   — a vendor pricing page, scraped with a spec-supplied regex.
 *
 * PARSE FAILURES ARE LOUD. Every entry point records `laststatus` and a
 * human-readable `lastmessage` on the source row, and an empty parse is a
 * FAILURE, never "this vendor has no prices". That distinction is the whole
 * point: the bug this feature exists to prevent is a price that silently is not
 * there. token_cost_manager::estimate_cost() returns null for an unpriced
 * model and every consumer treats null as "nothing to attribute", so a feed
 * that quietly parses to zero rows reads downstream as $0.00 spend rather than
 * as a broken feed. A source that returned prices yesterday and none today is
 * additionally flagged as a REGRESSION, because that is a changed page layout
 * or a renamed JSON key, and the admin needs to know which.
 *
 * Every outbound fetch goes through Moodle's \curl plus
 * {@see security::is_safe_provider_url()} and {@see security::resolve_pin_options()},
 * exactly as {@see rate_card_refresher} does. Redirects are deliberately NOT
 * followed: the IP pin only covers the host that was validated, so a redirect
 * would reopen the SSRF window it closes.
 *
 * Nothing here writes to the filesystem, and nothing here applies a price.
 * Parsed prices are returned to the caller; the drift check turns them into
 * PROPOSED findings for an admin to apply.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class price_source {
    /** @var string[] Recognized parse formats. Adding a feed picks one of these. */
    public const FORMATS = ['litellm', 'openrouter', 'json_generic', 'html_regex'];

    /** @var int Connect timeout, seconds. Short: this is a cron nicety, not a request path. */
    public const CONNECT_TIMEOUT = 5;

    /** @var int Total transfer timeout, seconds. */
    public const TIMEOUT = 20;

    /** @var int Reject a body larger than this before handing it to a parser. */
    public const MAX_BODY_BYTES = 8388608;

    /** @var float Per-token feeds (litellm, openrouter) scale to SOLA's USD-per-1M-tokens schema. */
    public const PER_TOKEN_SCALE = 1000000.0;

    /**
     * LiteLLM `mode` values we price, mapped to a SOLA capability.
     *
     * Mirrors rate_card_refresher::KEPT_MODES including the v7.4.0 'rerank'
     * addition, whose absence meant a reranker could never be auto-priced while
     * voyage_reranker was live in the RAG path logging tokens.
     *
     * @var array<string, string>
     */
    private const LITELLM_MODES = [
        'chat'       => 'chat',
        'completion' => 'chat',
        'embedding'  => 'embedding',
        'rerank'     => 'rerank',
    ];

    /** @var string[] Capabilities billed on input only, where a missing output rate means 0. */
    private const INPUT_ONLY = ['embedding', 'rerank'];

    /** @var string[] Delimiters tried, in order, when compiling a spec-supplied regex. */
    private const REGEX_DELIMITERS = ['~', '#', '%', '!', '@', '|'];

    /** @var string[] Regex modifiers an admin may request. Deliberately no 'e'-alikes. */
    private const ALLOWED_REGEX_FLAGS = ['i', 'm', 's', 'u', 'x'];

    /**
     * Every enabled source row, cheapest-to-explain order (by name).
     *
     * Keyed by id (get_records keys on the primary key, which is unique), so
     * the keying trap that get_records_sql has does not apply.
     *
     * @return \stdClass[]
     */
    public static function enabled_sources(): array {
        global $DB;
        try {
            return $DB->get_records(model_registry::TABLE_SOURCES, ['enabled' => 1], 'name ASC, id ASC');
        } catch (\Throwable $e) {
            // Mid-upgrade the table may not exist yet; that is "no sources",
            // not a fatal, and the caller still reports it.
            return [];
        }
    }

    /**
     * Fetch a source row's URL and parse it, recording the outcome on the row.
     *
     * @param \stdClass $source Row from {@see model_registry::TABLE_SOURCES}.
     * @return array{ok: bool, prices: array, message: string, httpcode: int, count: int}
     */
    public static function refresh_source(\stdClass $source): array {
        $fetch = self::fetch((string) ($source->url ?? ''));
        if (!$fetch['ok']) {
            self::record_status($source, false, $fetch['message'], 0);
            return ['ok' => false, 'prices' => [], 'message' => $fetch['message'],
                'httpcode' => $fetch['httpcode'], 'count' => 0];
        }
        $result = self::parse_into_source($source, $fetch['body']);
        $result['httpcode'] = $fetch['httpcode'];
        return $result;
    }

    /**
     * Parse an already-fetched body for a source row and record the outcome.
     *
     * Split out from {@see refresh_source()} on purpose: it is the seam that
     * lets the tests drive every format, and every failure path, from fixture
     * STRINGS with no network call at all.
     *
     * @param \stdClass $source Row from {@see model_registry::TABLE_SOURCES}.
     * @param string $body The fetched payload.
     * @return array{ok: bool, prices: array, message: string, count: int}
     */
    public static function parse_into_source(\stdClass $source, string $body): array {
        $spec = self::decode_spec($source->spec ?? null);
        if ($spec === null) {
            $message = 'Parse spec is not valid JSON — fix the spec field on this source.';
            self::record_status($source, false, $message, 0);
            return ['ok' => false, 'prices' => [], 'message' => $message, 'count' => 0];
        }

        $parsed = self::parse((string) ($source->format ?? ''), $body, $spec);
        $count = count($parsed['prices']);

        if (!$parsed['ok']) {
            // An empty parse from a source that worked before is a layout or
            // key rename, not an absence of prices. Say which, loudly.
            $previous = self::previous_count($source);
            $message = $parsed['message'];
            if ($count === 0 && $previous > 0) {
                $message = 'REGRESSION: this source returned ' . $previous
                    . ' prices on its previous run and 0 now. ' . $message;
            }
            self::record_status($source, false, $message, 0);
            return ['ok' => false, 'prices' => [], 'message' => $message, 'count' => 0];
        }

        self::record_status($source, true, $parsed['message'], $count);
        return ['ok' => true, 'prices' => $parsed['prices'], 'message' => $parsed['message'], 'count' => $count];
    }

    /**
     * Parse a payload in one of the four supported formats. Pure: no DB, no IO.
     *
     * The returned price rows use exactly the shape
     * {@see model_registry::upsert()} consumes, so a proposal an admin accepts
     * needs no further translation.
     *
     * @param string $format One of {@see FORMATS}.
     * @param string $body Raw payload.
     * @param array $spec Decoded parse spec from the source row.
     * @return array{ok: bool, message: string, prices: array<string, array{input: float,
     *               output: float, provider: ?string, capability: string, context: ?int}>}
     */
    public static function parse(string $format, string $body, array $spec = []): array {
        $format = strtolower(trim($format));
        if (!in_array($format, self::FORMATS, true)) {
            return self::parse_error('Unknown source format "' . self::snippet($format) . '". Supported: '
                . implode(', ', self::FORMATS) . '.');
        }
        if ($body === '') {
            return self::parse_error('Source returned an empty body.');
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return self::parse_error('Source body is ' . strlen($body) . ' bytes, over the '
                . self::MAX_BODY_BYTES . '-byte limit; refusing to parse.');
        }

        $notes = [];
        switch ($format) {
            case 'litellm':
                $decoded = self::decode_json($body);
                if ($decoded === null) {
                    return self::parse_error('Expected a JSON object for format litellm; got '
                        . self::describe_body($body));
                }
                $prices = self::parse_litellm($decoded, $notes);
                break;
            case 'openrouter':
                $decoded = self::decode_json($body);
                if ($decoded === null) {
                    return self::parse_error('Expected a JSON object for format openrouter; got '
                        . self::describe_body($body));
                }
                $prices = self::parse_openrouter($decoded, $spec, $notes);
                break;
            case 'json_generic':
                $decoded = self::decode_json($body);
                if ($decoded === null) {
                    return self::parse_error('Expected JSON for format json_generic; got '
                        . self::describe_body($body));
                }
                $generic = self::parse_json_generic($decoded, $spec, $notes);
                if (!$generic['ok']) {
                    return $generic;
                }
                $prices = $generic['prices'];
                break;
            default:
                $regex = self::parse_html_regex($body, $spec, $notes);
                if (!$regex['ok']) {
                    return $regex;
                }
                $prices = $regex['prices'];
                break;
        }

        if (empty($prices)) {
            // Never "no prices". A pricing source that yields nothing is broken,
            // and saying so is the difference between a visible failure and a
            // silent $0.00 further down the pipeline.
            return self::parse_error('Parsed 0 usable prices from ' . strlen($body)
                . ' bytes of ' . $format . ' payload — treat this as a broken source, not as '
                . 'an absence of prices. Check the URL and the parse spec.'
                . ($notes ? ' (' . implode('; ', $notes) . ')' : ''));
        }

        $message = 'Parsed ' . count($prices) . ' prices.';
        if ($notes) {
            $message .= ' ' . implode(' ', $notes);
        }
        return ['ok' => true, 'message' => $message, 'prices' => $prices];
    }

    /**
     * GET a URL through Moodle \curl with the plugin's SSRF gate and IP pin.
     *
     * @param string $url
     * @return array{ok: bool, body: string, httpcode: int, message: string}
     */
    public static function fetch(string $url): array {
        global $CFG;

        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'body' => '', 'httpcode' => 0, 'message' => 'Source has no URL.'];
        }
        if (!security::is_safe_provider_url($url)) {
            return ['ok' => false, 'body' => '', 'httpcode' => 0,
                'message' => 'URL rejected by the SSRF allowlist: ' . $url
                    . ' (https only, no private or reserved addresses unless the host is in '
                    . 'ssrf_trusted_endpoints).'];
        }

        require_once($CFG->dirroot . '/lib/filelib.php');
        try {
            // resolve_pin_options() throws when a public host has started
            // resolving to a private address — the DNS-rebinding case.
            $pin = security::resolve_pin_options($url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'body' => '', 'httpcode' => 0,
                'message' => 'Refused to fetch: ' . $e->getMessage()];
        }

        try {
            $curl = new \curl();
            $body = $curl->get($url, [], array_merge([
                'CURLOPT_TIMEOUT'        => self::TIMEOUT,
                'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
                // The IP pin above only covers the host that was validated, so
                // following a redirect would hand the fetch to an unvalidated
                // host and reopen the hole the pin closes. A source that
                // redirects is reported so the admin can enter the final URL.
                'CURLOPT_FOLLOWLOCATION' => 0,
                'CURLOPT_PROTOCOLS'      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ], $pin));
        } catch (\Throwable $e) {
            return ['ok' => false, 'body' => '', 'httpcode' => 0,
                'message' => 'Fetch threw: ' . $e->getMessage()];
        }

        $info = $curl->get_info();
        $code = (int) ($info['http_code'] ?? 0);
        if ($code >= 300 && $code < 400) {
            return ['ok' => false, 'body' => '', 'httpcode' => $code,
                'message' => 'HTTP ' . $code . ' redirect. Redirects are not followed (the IP pin '
                    . 'only covers the validated host); enter the final URL instead.'];
        }
        if ($code < 200 || $code >= 300) {
            $err = (string) ($curl->error ?? '');
            return ['ok' => false, 'body' => '', 'httpcode' => $code,
                'message' => 'HTTP ' . $code . ($err !== '' ? ' (' . $err . ')' : '') . '.'];
        }
        if ($body === false || $body === null || $body === '') {
            return ['ok' => false, 'body' => '', 'httpcode' => $code,
                'message' => 'HTTP ' . $code . ' but the body was empty.'];
        }

        return ['ok' => true, 'body' => (string) $body, 'httpcode' => $code, 'message' => ''];
    }

    /**
     * Record a run outcome on the source row, and remember the price count.
     *
     * The count lives in plugin config rather than a column because this wave
     * may not add columns; it is what makes "worked yesterday, empty today"
     * detectable rather than indistinguishable from "never worked".
     *
     * @param \stdClass $source
     * @param bool $ok
     * @param string $message Human-readable, shown verbatim in the admin UI.
     * @param int $count Prices parsed.
     * @return void
     */
    public static function record_status(\stdClass $source, bool $ok, string $message, int $count): void {
        global $DB;

        $id = (int) ($source->id ?? 0);
        $source->laststatus = $ok ? 'ok' : 'error';
        $source->lastmessage = $message;
        $source->lastfetch = time();
        $source->timemodified = time();
        if ($id <= 0) {
            return;
        }
        try {
            $DB->update_record(model_registry::TABLE_SOURCES, (object) [
                'id'           => $id,
                'laststatus'   => $source->laststatus,
                'lastmessage'  => $message,
                'lastfetch'    => $source->lastfetch,
                'timemodified' => $source->timemodified,
            ]);
            if ($ok) {
                set_config(self::count_key($id), (string) $count, 'local_ai_course_assistant');
            }
        } catch (\Throwable $e) {
            debugging('price_source::record_status failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        if (!$ok) {
            debugging('price_source: source ' . $id . ' failed: ' . $message, DEBUG_DEVELOPER);
        }
    }

    /**
     * Append a line to a source row's lastmessage (used to add finding counts).
     *
     * @param \stdClass $source
     * @param string $extra
     * @return void
     */
    public static function append_message(\stdClass $source, string $extra): void {
        global $DB;
        $id = (int) ($source->id ?? 0);
        $source->lastmessage = trim((string) ($source->lastmessage ?? '') . ' ' . $extra);
        if ($id <= 0) {
            return;
        }
        try {
            $DB->update_record(model_registry::TABLE_SOURCES, (object) [
                'id'           => $id,
                'lastmessage'  => $source->lastmessage,
                'timemodified' => time(),
            ]);
        } catch (\Throwable $e) {
            debugging('price_source::append_message failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Prices this source parsed on its previous successful run.
     *
     * @param \stdClass $source
     * @return int
     */
    public static function previous_count(\stdClass $source): int {
        $id = (int) ($source->id ?? 0);
        if ($id <= 0) {
            return 0;
        }
        return (int) (get_config('local_ai_course_assistant', self::count_key($id)) ?: 0);
    }

    /**
     * Config key holding a source's last successful price count.
     *
     * @param int $id
     * @return string
     */
    public static function count_key(int $id): string {
        return 'price_source_count_' . $id;
    }

    /**
     * Decode a source row's spec column.
     *
     * @param string|null $raw
     * @return array|null Null means "present but not valid JSON" — an error.
     */
    public static function decode_spec($raw): ?array {
        $raw = trim((string) ($raw ?? ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * LiteLLM map → SOLA price rows.
     *
     * Semantics are deliberately identical to
     * rate_card_refresher::transform_litellm(): same kept modes (including
     * rerank), same "chat with no output cost is incomplete, skip it" rule
     * (pricing an LLM's output at zero is the exact failure class this feature
     * exists to fix), same context-window fallback. The refresher should
     * delegate here once it is in scope to edit; until then this is the single
     * documented copy and the two must not drift.
     *
     * @param array $decoded
     * @param string[] $notes Out: human-readable parse notes.
     * @return array<string, array{input: float, output: float, provider: ?string,
     *               capability: string, context: ?int}>
     */
    private static function parse_litellm(array $decoded, array &$notes): array {
        $out = [];
        $skippedmode = 0;
        $skippedincomplete = 0;
        foreach ($decoded as $model => $row) {
            if (!is_string($model) || !is_array($row)) {
                continue;
            }
            if ($model === 'sample_spec') {
                continue;
            }
            $mode = isset($row['mode']) ? (string) $row['mode'] : 'chat';
            if (!isset(self::LITELLM_MODES[$mode])) {
                $skippedmode++;
                continue;
            }
            $capability = self::LITELLM_MODES[$mode];
            if (!isset($row['input_cost_per_token'])) {
                $skippedincomplete++;
                continue;
            }
            $inputonly = in_array($capability, self::INPUT_ONLY, true);
            if (!isset($row['output_cost_per_token']) && !$inputonly) {
                $skippedincomplete++;
                continue;
            }
            $input = (float) $row['input_cost_per_token'] * self::PER_TOKEN_SCALE;
            $output = (float) ($row['output_cost_per_token'] ?? 0.0) * self::PER_TOKEN_SCALE;
            if ($input <= 0.0 && $output <= 0.0) {
                continue;
            }
            $context = null;
            foreach (['max_input_tokens', 'max_tokens'] as $field) {
                if (isset($row[$field]) && (int) $row[$field] > 0) {
                    $context = (int) $row[$field];
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
        if ($skippedmode) {
            $notes[] = $skippedmode . ' entries skipped (unpriceable mode).';
        }
        if ($skippedincomplete) {
            $notes[] = $skippedincomplete . ' entries skipped (missing a cost field).';
        }
        return $out;
    }

    /**
     * OpenRouter /api/v1/models → SOLA price rows.
     *
     * Two things to know about this feed. It prices PER TOKEN as decimal
     * strings ("0.0000025"), so everything is scaled by 1,000,000 into SOLA's
     * per-1M schema. And its ids are vendor-prefixed ("google/gemini-2.5-flash"),
     * while SOLA's rate-card keys are the bare model name the provider API
     * returns — so the prefix becomes the `provider` and the remainder becomes
     * the key, or the drift comparison would match nothing and report the whole
     * catalogue as new.
     *
     * @param array $decoded
     * @param array $spec Optional {scale, capability}.
     * @param string[] $notes Out.
     * @return array<string, array{input: float, output: float, provider: ?string,
     *               capability: string, context: ?int}>
     */
    private static function parse_openrouter(array $decoded, array $spec, array &$notes): array {
        $rows = $decoded['data'] ?? $decoded;
        if (!is_array($rows)) {
            return [];
        }
        $scale = self::spec_scale($spec, self::PER_TOKEN_SCALE);
        $capdefault = isset($spec['capability']) ? (string) $spec['capability'] : 'chat';

        $out = [];
        $free = 0;
        $collisions = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? strtolower(trim((string) $row['id'])) : '';
            if ($id === '') {
                continue;
            }
            $pricing = isset($row['pricing']) && is_array($row['pricing']) ? $row['pricing'] : [];
            $input = self::to_number($pricing['prompt'] ?? null);
            $output = self::to_number($pricing['completion'] ?? null);
            if ($input === null) {
                continue;
            }
            // OpenRouter uses a negative price for "variable / on request".
            if ($input < 0 || ($output !== null && $output < 0)) {
                continue;
            }
            $input *= $scale;
            $output = ($output ?? 0.0) * $scale;
            if ($input <= 0.0 && $output <= 0.0) {
                // The ":free" variants. Real, but not a price we can drift against.
                $free++;
                continue;
            }
            [$provider, $key] = self::split_vendor_prefix($id);
            if (isset($out[$key])) {
                // Same bare model served by several vendors (openai/gpt-4o and
                // azure/gpt-4o). First wins, deterministically, since the feed
                // order is stable; the count tells the admin it happened.
                $collisions++;
                continue;
            }
            $context = null;
            foreach (['context_length', 'top_provider.context_length'] as $path) {
                $v = self::dig($row, $path);
                if ($v !== null && (int) $v > 0) {
                    $context = (int) $v;
                    break;
                }
            }
            $out[$key] = [
                'input'      => $input,
                'output'     => $output,
                'provider'   => $provider,
                'capability' => $capdefault,
                'context'    => $context,
            ];
        }
        if ($free) {
            $notes[] = $free . ' zero-priced (":free") variants skipped.';
        }
        if ($collisions) {
            $notes[] = $collisions . ' duplicate model ids after removing the vendor prefix (first kept).';
        }
        return $out;
    }

    /**
     * Arbitrary JSON → SOLA price rows, driven entirely by dot-paths in the spec.
     *
     * This is the format that makes "add a vendor feed" a form. Spec fields:
     *   rowspath       dot-path to the list or map of rows; "" or absent = root
     *   modelpath      dot-path within a row to the model id; absent means the
     *                  rows are a MAP and its keys are the model ids
     *   inputpath      dot-path to the input price            (required)
     *   outputpath     dot-path to the output price           (optional)
     *   scale          multiply raw prices by this to reach USD per 1M tokens
     *                  (default 1.0 — the feed is already per 1M)
     *   providerpath / capabilitypath / contextpath  optional dot-paths
     *   provider / capability                        optional static values
     *   stripvendorprefix  true to turn "vendor/model" into provider + key
     *
     * @param array $decoded
     * @param array $spec
     * @param string[] $notes Out.
     * @return array{ok: bool, message: string, prices: array}
     */
    private static function parse_json_generic(array $decoded, array $spec, array &$notes): array {
        $inputpath = isset($spec['inputpath']) ? (string) $spec['inputpath'] : '';
        if ($inputpath === '') {
            return self::parse_error('Format json_generic needs an "inputpath" in the parse spec '
                . '(a dot-path to the input price inside one row).');
        }
        $rowspath = isset($spec['rowspath']) ? (string) $spec['rowspath'] : '';
        $rows = self::dig($decoded, $rowspath);
        if (!is_array($rows)) {
            return self::parse_error('Parse spec rowspath "' . self::snippet($rowspath) . '" did not resolve to a '
                . 'list or object in the payload.');
        }

        $modelpath = isset($spec['modelpath']) ? (string) $spec['modelpath'] : '';
        $outputpath = isset($spec['outputpath']) ? (string) $spec['outputpath'] : '';
        $scale = self::spec_scale($spec, 1.0);
        $strip = !empty($spec['stripvendorprefix']);
        $capdefault = isset($spec['capability']) ? (string) $spec['capability'] : 'chat';
        $providerdefault = isset($spec['provider']) ? (string) $spec['provider'] : null;

        $out = [];
        $nokey = 0;
        $noprice = 0;
        foreach ($rows as $rowkey => $row) {
            if ($modelpath === '') {
                // Rows are a map keyed by model id (the litellm shape).
                $model = is_string($rowkey) ? $rowkey : '';
            } else {
                $model = is_array($row) ? (string) (self::dig($row, $modelpath) ?? '') : '';
            }
            $model = strtolower(trim($model));
            if ($model === '') {
                $nokey++;
                continue;
            }
            $rowdata = is_array($row) ? $row : [];
            $input = self::to_number(self::dig($rowdata, $inputpath));
            $output = $outputpath !== '' ? self::to_number(self::dig($rowdata, $outputpath)) : null;
            if ($input === null || $input < 0) {
                $noprice++;
                continue;
            }
            $input *= $scale;
            $output = $output === null || $output < 0 ? 0.0 : $output * $scale;
            if ($input <= 0.0 && $output <= 0.0) {
                $noprice++;
                continue;
            }
            $provider = $providerdefault;
            if (!empty($spec['providerpath'])) {
                $v = self::dig($rowdata, (string) $spec['providerpath']);
                $provider = $v === null ? $provider : (string) $v;
            }
            $capability = $capdefault;
            if (!empty($spec['capabilitypath'])) {
                $v = self::dig($rowdata, (string) $spec['capabilitypath']);
                if ($v !== null && trim((string) $v) !== '') {
                    $capability = strtolower(trim((string) $v));
                }
            }
            $context = null;
            if (!empty($spec['contextpath'])) {
                $v = self::dig($rowdata, (string) $spec['contextpath']);
                $context = $v !== null && (int) $v > 0 ? (int) $v : null;
            }
            if ($strip) {
                [$vendor, $model] = self::split_vendor_prefix($model);
                $provider = $provider ?? $vendor;
            }
            if (isset($out[$model])) {
                continue;
            }
            $out[$model] = [
                'input'      => $input,
                'output'     => $output,
                'provider'   => $provider,
                'capability' => $capability,
                'context'    => $context,
            ];
        }
        if ($nokey) {
            $notes[] = $nokey . ' rows skipped (no model id at modelpath).';
        }
        if ($noprice) {
            $notes[] = $noprice . ' rows skipped (no usable price at inputpath/outputpath).';
        }
        return ['ok' => true, 'message' => '', 'prices' => $out];
    }

    /**
     * A vendor pricing PAGE → SOLA price rows, via a spec-supplied pattern.
     *
     * Spec fields:
     *   pattern   the regex WITHOUT delimiters (we add them, so an admin cannot
     *             smuggle in modifiers); use capture groups
     *   flags     optional subset of i m s u x
     *   groups    {"model": 1, "input": 2, "output": 3} — 1-based group numbers,
     *             or names if the pattern uses named groups
     *   scale     multiply matched prices by this (default 1.0)
     *   provider / capability  optional static values for every matched row
     *
     * Prices are run through the same numeric coercion as the JSON formats, so
     * "$0.30" and "0.30 / 1M" both read as 0.30.
     *
     * @param string $body
     * @param array $spec
     * @param string[] $notes Out.
     * @return array{ok: bool, message: string, prices: array}
     */
    private static function parse_html_regex(string $body, array $spec, array &$notes): array {
        $pattern = isset($spec['pattern']) ? (string) $spec['pattern'] : '';
        if ($pattern === '') {
            return self::parse_error('Format html_regex needs a "pattern" in the parse spec.');
        }
        $groups = isset($spec['groups']) && is_array($spec['groups']) ? $spec['groups'] : [];
        $modelgroup = $groups['model'] ?? 1;
        $inputgroup = $groups['input'] ?? 2;
        $outputgroup = $groups['output'] ?? null;
        $scale = self::spec_scale($spec, 1.0);
        $provider = isset($spec['provider']) ? (string) $spec['provider'] : null;
        $capability = isset($spec['capability']) ? (string) $spec['capability'] : 'chat';

        $compiled = self::compile_pattern($pattern, isset($spec['flags']) ? (string) $spec['flags'] : '');
        if ($compiled === null) {
            return self::parse_error('Could not compile the pattern. Supply it WITHOUT delimiters, '
                . 'avoid all of ' . implode(' ', self::REGEX_DELIMITERS) . ' as literal characters, '
                . 'and limit flags to ' . implode('', self::ALLOWED_REGEX_FLAGS) . '.');
        }
        $matches = [];
        $found = @preg_match_all($compiled, $body, $matches, PREG_SET_ORDER);
        if ($found === false) {
            return self::parse_error('The pattern failed while matching (PCRE error '
                . preg_last_error() . '). A catastrophically backtracking pattern is the usual cause.');
        }

        $out = [];
        $badprice = 0;
        foreach ($matches as $set) {
            $model = strtolower(trim((string) ($set[$modelgroup] ?? '')));
            if ($model === '') {
                continue;
            }
            $input = self::to_number($set[$inputgroup] ?? null);
            $output = $outputgroup !== null ? self::to_number($set[$outputgroup] ?? null) : null;
            if ($input === null || $input < 0) {
                $badprice++;
                continue;
            }
            $input *= $scale;
            $output = $output === null || $output < 0 ? 0.0 : $output * $scale;
            if ($input <= 0.0 && $output <= 0.0) {
                $badprice++;
                continue;
            }
            if (isset($out[$model])) {
                continue;
            }
            $out[$model] = [
                'input'      => $input,
                'output'     => $output,
                'provider'   => $provider,
                'capability' => $capability,
                'context'    => null,
            ];
        }
        if ($badprice) {
            $notes[] = $badprice . ' matches skipped (no usable number in the price group).';
        }
        return ['ok' => true, 'message' => '', 'prices' => $out];
    }

    /**
     * Compare a source's prices against the registry's effective rates.
     *
     * Pure, so the tolerance boundary is unit-testable without a network or a
     * feed. Two finding classes come out of here:
     *
     *  - MISMATCH: the source key EXACTLY equals a registry key and a rate
     *    differs by more than the tolerance. Exact-only is deliberate. Registry
     *    keys are longest-prefix matched, so a source key like
     *    "gemini-2.5-flash-preview-09-2025" prefix-matches the "gemini-2.5-flash"
     *    card; reporting that as a price mismatch would be false — it is a
     *    different model that the card merely covers. Those are reported as
     *    neither mismatch nor new; they are already priced.
     *  - NEW: the source knows a key that matches no registry prefix at all.
     *    Informational: it is a model the site does not run.
     *
     * @param array $prices Parsed source prices.
     * @param array $rates model_registry::effective_rates().
     * @param float $tolerancepct Percent difference tolerated before reporting.
     * @return array{mismatch: array[], new: array[], covered: int}
     */
    public static function compare(array $prices, array $rates, float $tolerancepct): array {
        $tolerancepct = max(0.0, min(100.0, $tolerancepct));
        $mismatch = [];
        $newmodels = [];
        $covered = 0;

        foreach ($prices as $key => $row) {
            $key = strtolower(trim((string) $key));
            if ($key === '') {
                continue;
            }
            if (array_key_exists($key, $rates)) {
                $regin = (float) $rates[$key]['input'];
                $regout = (float) $rates[$key]['output'];
                $dinput = self::delta_pct($regin, (float) $row['input']);
                $doutput = self::delta_pct($regout, (float) $row['output']);
                if ($dinput > $tolerancepct || $doutput > $tolerancepct) {
                    $mismatch[] = [
                        'type'             => 'mismatch',
                        'modelkey'         => $key,
                        'provider'         => $row['provider'] ?? null,
                        'capability'       => $row['capability'] ?? null,
                        'input'            => (float) $row['input'],
                        'output'           => (float) $row['output'],
                        'context'          => $row['context'] ?? null,
                        'registry_input'   => $regin,
                        'registry_output'  => $regout,
                        'delta_pct_input'  => round($dinput, 3),
                        'delta_pct_output' => round($doutput, 3),
                        'calls'            => null,
                    ];
                }
                continue;
            }
            if (self::longest_prefix($key, array_keys($rates)) !== null) {
                // Priced by a shorter card key. Not a finding either way.
                $covered++;
                continue;
            }
            $newmodels[] = [
                'type'             => 'new',
                'modelkey'         => $key,
                'provider'         => $row['provider'] ?? null,
                'capability'       => $row['capability'] ?? null,
                'input'            => (float) $row['input'],
                'output'           => (float) $row['output'],
                'context'          => $row['context'] ?? null,
                'registry_input'   => null,
                'registry_output'  => null,
                'delta_pct_input'  => null,
                'delta_pct_output' => null,
                'calls'            => null,
            ];
        }

        return ['mismatch' => $mismatch, 'new' => $newmodels, 'covered' => $covered];
    }

    /**
     * Percent difference between a registry rate and a source rate.
     *
     * A registry rate of 0 against a nonzero source rate is the worst case, not
     * a divide-by-zero: it is the shape where spend computes as free. It is
     * reported as 100%.
     *
     * @param float $registry
     * @param float $source
     * @return float
     */
    public static function delta_pct(float $registry, float $source): float {
        if (abs($registry - $source) < 0.0000005) {
            return 0.0;
        }
        if ($registry == 0.0) {
            return 100.0;
        }
        return abs($source - $registry) / abs($registry) * 100.0;
    }

    /**
     * Longest key in $keys that $model starts with, or null.
     *
     * Same longest-prefix-wins rule the rate card itself uses, so "does this
     * model already have a price?" is answered the same way here as in
     * {@see model_registry::rate_for()}.
     *
     * @param string $model
     * @param string[] $keys
     * @return string|null
     */
    public static function longest_prefix(string $model, array $keys): ?string {
        $model = strtolower(trim($model));
        if ($model === '') {
            return null;
        }
        $best = null;
        $bestlen = 0;
        foreach ($keys as $key) {
            $key = strtolower((string) $key);
            if ($key !== '' && str_starts_with($model, $key) && strlen($key) > $bestlen) {
                $best = $key;
                $bestlen = strlen($key);
            }
        }
        return $best;
    }

    /**
     * Split "vendor/model" into [vendor, model]; ["", $id] when there is no slash.
     *
     * @param string $id
     * @return array{0: ?string, 1: string}
     */
    private static function split_vendor_prefix(string $id): array {
        $pos = strpos($id, '/');
        if ($pos === false) {
            return [null, $id];
        }
        $vendor = substr($id, 0, $pos);
        $model = substr($id, $pos + 1);
        return [$vendor !== '' ? $vendor : null, $model !== '' ? $model : $id];
    }

    /**
     * Wrap a delimiter-free admin pattern in a delimiter, with vetted flags.
     *
     * The admin supplies the pattern WITHOUT delimiters so they cannot append
     * modifiers of their own choosing; we pick a delimiter the pattern does not
     * contain and validate the result compiles.
     *
     * @param string $pattern
     * @param string $flags
     * @return string|null Compiled pattern, or null when it cannot be used.
     */
    private static function compile_pattern(string $pattern, string $flags): ?string {
        $clean = '';
        foreach (str_split(strtolower($flags)) as $flag) {
            if ($flag !== '' && in_array($flag, self::ALLOWED_REGEX_FLAGS, true)
                    && strpos($clean, $flag) === false) {
                $clean .= $flag;
            }
        }
        foreach (self::REGEX_DELIMITERS as $delim) {
            if (strpos($pattern, $delim) !== false) {
                continue;
            }
            $candidate = $delim . $pattern . $delim . $clean;
            if (@preg_match($candidate, '') !== false) {
                return $candidate;
            }
            return null;
        }
        return null;
    }

    /**
     * Read a scale factor from the spec, rejecting nonsense.
     *
     * @param array $spec
     * @param float $default
     * @return float
     */
    private static function spec_scale(array $spec, float $default): float {
        if (!isset($spec['scale'])) {
            return $default;
        }
        $scale = self::to_number($spec['scale']);
        return $scale !== null && $scale > 0.0 ? $scale : $default;
    }

    /**
     * Coerce a feed value to a number, tolerating "$0.30", "0.30 / 1M", "1,000".
     *
     * @param mixed $value
     * @return float|null
     */
    public static function to_number($value): ?float {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // Keep the first numeric run only: "0.30 per 1M" must not read as 0.301.
        if (!preg_match('/-?\d+(?:[\d,]*\d)?(?:\.\d+)?(?:[eE][-+]?\d+)?/', str_replace('$', '', $value), $m)) {
            return null;
        }
        $number = str_replace(',', '', $m[0]);
        return is_numeric($number) ? (float) $number : null;
    }

    /**
     * Walk a decoded structure with a dot-path. Numeric segments index lists.
     *
     * @param mixed $data
     * @param string $path
     * @return mixed Null when the path does not resolve.
     */
    public static function dig($data, string $path) {
        $path = trim($path);
        if ($path === '') {
            return $data;
        }
        foreach (explode('.', $path) as $segment) {
            if (!is_array($data)) {
                return null;
            }
            if (array_key_exists($segment, $data)) {
                $data = $data[$segment];
                continue;
            }
            if (ctype_digit($segment) && array_key_exists((int) $segment, $data)) {
                $data = $data[(int) $segment];
                continue;
            }
            return null;
        }
        return $data;
    }

    /**
     * json_decode to an array, or null.
     *
     * @param string $body
     * @return array|null
     */
    private static function decode_json(string $body): ?array {
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Clamp an admin-supplied fragment for inclusion in a status message.
     *
     * Deliberately NOT s()-escaped: lastmessage is plain text and the admin UI
     * escapes on output, so escaping here would show &quot; to the admin.
     *
     * @param string $value
     * @return string
     */
    private static function snippet(string $value): string {
        $value = preg_replace('/\s+/', ' ', trim($value));
        return strlen($value) > 80 ? substr($value, 0, 80) . '...' : $value;
    }

    /**
     * Short description of an unparseable body, for the error message.
     *
     * @param string $body
     * @return string
     */
    private static function describe_body(string $body): string {
        $error = json_last_error_msg();
        $head = preg_replace('/\s+/', ' ', substr($body, 0, 120));
        return strlen($body) . ' bytes (' . $error . '), starting "' . $head . '".';
    }

    /**
     * Standard failed-parse return.
     *
     * @param string $message
     * @return array{ok: bool, message: string, prices: array}
     */
    private static function parse_error(string $message): array {
        return ['ok' => false, 'message' => $message, 'prices' => []];
    }
}
