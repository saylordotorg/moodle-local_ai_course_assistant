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
 * Model registry (v7.4.0) — the single source of truth for model pricing.
 *
 * Pricing resolves through three layers, later layers winning:
 *
 *   1. `baseline`          — the committed table in {@see token_cost_manager}.
 *                            Ships with the code; corrected only by a release.
 *   2. `legacy_overrides`  — the pre-v7.4.0 single-JSON-blob `rate_card_overrides`
 *                            config setting. Still read so existing sites keep
 *                            their overrides; nothing writes it wholesale any more.
 *   3. `table`             — rows in local_ai_course_assistant_models, editable
 *                            from the admin UI with no code deploy.
 *
 * Two properties make this trustworthy rather than just another opaque blob:
 *
 *   - PROVENANCE. {@see provenance_for()} reports which layer supplied a rate,
 *     which row wrote it, who wrote it and when, so an admin looking at a
 *     surprising cost figure can see where the number came from.
 *   - A HUMAN OUTRANKS A FEED. {@see upsert()} refuses to let an 'upstream' or
 *     'drift' write clobber a row whose source is 'manual'. The v7.3.x refresher
 *     did the opposite: it called set_config('rate_card_overrides', <whole
 *     blob>) every Monday, destroying every hand-entered correction weekly.
 *
 * Why the registry exists at all: the committed baseline had no
 * 'gemini-2.5-flash' prefix while gemini-2.5-flash was the production chat
 * model. get_rates() returned null, estimate_cost() returned null, and every
 * consumer treats null as "skip" — so 100% of production chat spend computed
 * as $0.00, silently, with no error surface anywhere. A price must be fixable
 * by a form, not by a deploy.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_registry {
    /** @var string Pricing/capability overlay table. */
    public const TABLE_MODELS = 'local_ai_course_assistant_models';

    /** @var string Admin-editable pricing-source table. */
    public const TABLE_SOURCES = 'local_ai_course_assistant_pricesrc';

    /** @var string Benchmark-result table. */
    public const TABLE_BENCH = 'local_ai_course_assistant_bench';

    /** @var string Legacy single-blob override setting, read-only from v7.4.0. */
    public const LEGACY_SETTING = 'rate_card_overrides';

    /** @var string[] Sources that a human correction outranks. */
    public const FEED_SOURCES = ['upstream', 'drift'];

    /** @var string[] Recognized provenance layers, weakest first. */
    public const LAYERS = ['baseline', 'legacy_overrides', 'table'];

    /** @var int Default lookback window for {@see unpriced_models()}, in days. */
    public const DEFAULT_WINDOW_DAYS = 30;

    /** @var array<string, array{input: float, output: float}>|null Request-scoped rate cache. */
    private static ?array $ratecache = null;

    /**
     * Request-scoped provenance cache, parallel to {@see $ratecache}.
     *
     * @var array<string, array{layer: string, source: ?string, addedby: ?int, timemodified: ?int}>|null
     */
    private static ?array $provcache = null;

    /**
     * The raw legacy blob the caches were built from.
     *
     * The pre-v7.4.0 resolver re-read `rate_card_overrides` on every single
     * lookup, so a set_config() taking effect immediately within the same
     * request was part of its observable behavior (and is pinned by
     * token_cost_manager_voyage_test::test_admin_override_still_wins). Caching
     * the merged map without this signature check would have silently changed
     * that. The config read itself is a MUC hit, not a query, so it costs no
     * more than the old code did.
     *
     * @var string|null
     */
    private static ?string $legacysig = null;

    /**
     * The merged prefix => rates map, baseline < legacy blob < models table.
     *
     * Cached for the life of the request: get_rates() is called once per
     * message row rendered on the token-analytics page, and this would
     * otherwise be a DB hit each time.
     *
     * @return array<string, array{input: float, output: float}> Keyed by lowercase model prefix.
     */
    public static function effective_rates(): array {
        $raw = self::legacy_raw();
        if (self::$ratecache === null || self::$legacysig !== $raw) {
            self::build_cache($raw);
        }
        return self::$ratecache;
    }

    /**
     * Look up a model's rates, longest matching prefix wins.
     *
     * Identical semantics to the pre-v7.4.0 token_cost_manager::get_rates():
     * the model name is lowercased and trimmed, every key is tested with
     * str_starts_with, and the longest matching key supplies the rates. Null
     * means "unknown", which callers treat as "do not attribute a cost".
     *
     * @param string $model Exact model identifier from the provider response.
     * @return array{input: float, output: float}|null Rates per 1M tokens, or null if unknown.
     */
    public static function rate_for(string $model): ?array {
        $prefix = self::match_prefix($model);
        if ($prefix === null) {
            return null;
        }
        return self::effective_rates()[$prefix];
    }

    /**
     * Report which layer supplied a model's rates, and who put it there.
     *
     * This is what the admin UI renders next to each price. On no match the
     * layer is 'none' and every other field is null — that is the state that
     * makes a model's spend read as $0.00.
     *
     * @param string $model Exact model identifier.
     * @return array{prefix: ?string, input: ?float, output: ?float, layer: string,
     *               source: ?string, addedby: ?int, timemodified: ?int}
     */
    public static function provenance_for(string $model): array {
        $prefix = self::match_prefix($model);
        if ($prefix === null) {
            return [
                'prefix'       => null,
                'input'        => null,
                'output'       => null,
                'layer'        => 'none',
                'source'       => null,
                'addedby'      => null,
                'timemodified' => null,
            ];
        }
        $rates = self::effective_rates()[$prefix];
        $prov = self::$provcache[$prefix] ?? ['layer' => 'baseline', 'source' => null,
            'addedby' => null, 'timemodified' => null];
        return [
            'prefix'       => $prefix,
            'input'        => (float) $rates['input'],
            'output'       => (float) $rates['output'],
            'layer'        => (string) $prov['layer'],
            'source'       => $prov['source'] !== null ? (string) $prov['source'] : null,
            'addedby'      => $prov['addedby'] !== null ? (int) $prov['addedby'] : null,
            'timemodified' => $prov['timemodified'] !== null ? (int) $prov['timemodified'] : null,
        ];
    }

    /**
     * Insert or update one registry row, keyed on modelkey.
     *
     * REFUSAL RULE: when the stored row's source is 'manual' and the incoming
     * source is a feed ('upstream' or 'drift'), nothing is written and the
     * existing id comes back unchanged with $outcome = 'skipped_manual'. A
     * human correction outranks a scraper, permanently — that is the fix for
     * the weekly wholesale overwrite. A 'manual' or 'bundle' write may still
     * update a manual row (an admin correcting their own earlier entry, or a
     * signed policy bundle, are both authorized).
     *
     * @param array $row Fields to write. 'modelkey' is required; provider,
     *                   capability, input_rate, output_rate, context_tokens,
     *                   status and notes are optional and only overwrite when
     *                   present in the array.
     * @param string $source One of manual|upstream|drift|bundle.
     * @param int|null $userid User to stamp as addedby; null for feed writes.
     * @param string|null $outcome Out-param: 'inserted', 'updated' or 'skipped_manual'.
     * @return int Row id.
     * @throws \coding_exception When modelkey is missing or empty.
     */
    public static function upsert(array $row, string $source, ?int $userid = null, ?string &$outcome = null): int {
        global $DB;

        $key = strtolower(trim((string) ($row['modelkey'] ?? '')));
        if ($key === '') {
            throw new \coding_exception('model_registry::upsert requires a non-empty modelkey');
        }
        $source = strtolower(trim($source));
        $now = time();

        $existing = $DB->get_record(self::TABLE_MODELS, ['modelkey' => $key]);

        if ($existing && strtolower((string) $existing->source) === 'manual'
                && in_array($source, self::FEED_SOURCES, true)) {
            // A feed may not undo a human. Report it so the caller can count
            // and surface the skips rather than believing it wrote the price.
            $outcome = 'skipped_manual';
            return (int) $existing->id;
        }

        $record = new \stdClass();
        foreach (['provider', 'capability', 'notes'] as $field) {
            if (array_key_exists($field, $row)) {
                $record->$field = $row[$field] !== null && $row[$field] !== ''
                    ? (string) $row[$field] : null;
            }
        }
        foreach (['input_rate', 'output_rate'] as $field) {
            if (array_key_exists($field, $row)) {
                $record->$field = $row[$field] === null || $row[$field] === ''
                    ? null : (float) $row[$field];
            }
        }
        if (array_key_exists('context_tokens', $row)) {
            $record->context_tokens = $row['context_tokens'] === null || $row['context_tokens'] === ''
                ? null : (int) $row['context_tokens'];
        }
        if (array_key_exists('status', $row) && trim((string) $row['status']) !== '') {
            $record->status = strtolower(trim((string) $row['status']));
        }
        $record->source = $source;
        $record->addedby = $userid;
        $record->timemodified = $now;

        self::reset_cache();

        if ($existing) {
            $record->id = (int) $existing->id;
            $DB->update_record(self::TABLE_MODELS, $record);
            $outcome = 'updated';
            return (int) $existing->id;
        }

        $record->modelkey = $key;
        $record->status = $record->status ?? 'active';
        $record->timecreated = $now;
        $outcome = 'inserted';
        return (int) $DB->insert_record(self::TABLE_MODELS, $record);
    }

    /**
     * Models actually observed in the message log that resolve to no rate.
     *
     * This is the query whose absence let gemini-2.5-flash bill as $0.00 for a
     * whole production release: the model was in every msgs row, in no rate
     * card, and nothing ever compared the two sets. The admin registry page and
     * the price-drift check both read this.
     *
     * Billable rows are selected with analytics::spend_rows_predicate() — never
     * a hand-rolled role/interaction_type condition, which is how the RAG-$0.00
     * and premium-router double-count traps were introduced.
     *
     * @param int $days Lookback window in days.
     * @return array<int, array{model_name: string, provider: ?string, calls: int,
     *               tokens: int, lastseen: int}> Busiest unpriced model first.
     */
    public static function unpriced_models(int $days = self::DEFAULT_WINDOW_DAYS): array {
        global $DB;

        $since = time() - ($days * DAYSECS);
        // get_records_sql would key this by model_name and collapse the
        // per-provider rows behind it (the same model can be served by two
        // providers), so this deliberately uses a recordset.
        $sql = "SELECT m.model_name, m.provider, COUNT(*) AS calls,
                       SUM(COALESCE(m.prompt_tokens, 0) + COALESCE(m.completion_tokens, 0)) AS tokens,
                       MAX(m.timecreated) AS lastseen
                  FROM {local_ai_course_assistant_msgs} m
                 WHERE m.timecreated >= :since
                   AND m.model_name IS NOT NULL
                   AND m.model_name <> :empty
                   AND " . analytics::spend_rows_predicate('m') . "
              GROUP BY m.model_name, m.provider
              ORDER BY COUNT(*) DESC, m.model_name ASC";

        $out = [];
        $rs = $DB->get_recordset_sql($sql, ['since' => $since, 'empty' => '']);
        foreach ($rs as $row) {
            if (self::rate_for((string) $row->model_name) !== null) {
                continue;
            }
            $out[] = [
                'model_name' => (string) $row->model_name,
                'provider'   => $row->provider !== null ? (string) $row->provider : null,
                'calls'      => (int) $row->calls,
                'tokens'     => (int) $row->tokens,
                'lastseen'   => (int) $row->lastseen,
            ];
        }
        $rs->close();

        return $out;
    }

    /**
     * Drop the request-scoped caches.
     *
     * Called automatically by {@see upsert()}; tests and long-running CLI
     * scripts that mutate the table directly must call it themselves.
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::$ratecache = null;
        self::$provcache = null;
        self::$legacysig = null;
    }

    /**
     * Longest matching prefix for a model name, or null when nothing matches.
     *
     * @param string $model
     * @return string|null
     */
    private static function match_prefix(string $model): ?string {
        $model = strtolower(trim($model));
        if ($model === '') {
            return null;
        }
        $best = null;
        $bestlen = 0;
        foreach (self::effective_rates() as $prefix => $unused) {
            if (str_starts_with($model, $prefix) && strlen($prefix) > $bestlen) {
                $best = $prefix;
                $bestlen = strlen($prefix);
            }
        }
        return $best;
    }

    /**
     * Populate the rate and provenance caches by merging the three layers.
     *
     * @param string $legacyraw The legacy blob to merge, and to record as the
     *                          cache signature.
     * @return void
     */
    private static function build_cache(string $legacyraw): void {
        $rates = [];
        $prov = [];

        // Layer 1: the committed baseline.
        foreach (token_cost_manager::baseline_rate_cards() as $prefix => $row) {
            $prefix = strtolower(trim((string) $prefix));
            if ($prefix === '') {
                continue;
            }
            $rates[$prefix] = ['input' => (float) $row['input'], 'output' => (float) $row['output']];
            $prov[$prefix] = ['layer' => 'baseline', 'source' => null, 'addedby' => null, 'timemodified' => null];
        }

        // Layer 2: the legacy single-blob override setting. Malformed JSON is
        // ignored at runtime so a bad paste cannot break cost estimation; the
        // admin sees the parse error when saving the setting.
        foreach (self::legacy_overrides($legacyraw) as $prefix => $row) {
            $rates[$prefix] = ['input' => (float) $row['input'], 'output' => (float) $row['output']];
            $prov[$prefix] = ['layer' => 'legacy_overrides', 'source' => null,
                'addedby' => null, 'timemodified' => null];
        }

        // Layer 3: the models table. Wins outright — it is the layer an admin
        // can reach with no deploy, so it must be able to correct both others.
        foreach (self::table_rows() as $row) {
            $prefix = strtolower(trim((string) $row->modelkey));
            if ($prefix === '' || $row->input_rate === null) {
                // A row with no input rate carries capability/status metadata
                // only; leaving the price to the lower layer is correct, and
                // beats overwriting a real number with null.
                continue;
            }
            $rates[$prefix] = [
                'input'  => (float) $row->input_rate,
                'output' => $row->output_rate === null ? 0.0 : (float) $row->output_rate,
            ];
            $prov[$prefix] = [
                'layer'        => 'table',
                'source'       => (string) $row->source,
                'addedby'      => $row->addedby !== null ? (int) $row->addedby : null,
                'timemodified' => (int) $row->timemodified,
            ];
        }

        self::$ratecache = $rates;
        self::$provcache = $prov;
        self::$legacysig = $legacyraw;
    }

    /**
     * Decode the legacy `rate_card_overrides` blob into the internal shape.
     *
     * Kept readable for back-compat with sites upgraded from v7.3.x and
     * earlier. Nothing writes it wholesale from v7.4.0 onward.
     *
     * @param string $raw The raw setting value.
     * @return array<string, array{input: float, output: float}>
     */
    private static function legacy_overrides(string $raw): array {
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $prefix => $rates) {
            if (!is_string($prefix) || !is_array($rates)) {
                continue;
            }
            if (!isset($rates['input']) || !isset($rates['output'])) {
                continue;
            }
            $key = strtolower(trim($prefix));
            if ($key === '') {
                continue;
            }
            $out[$key] = ['input' => (float) $rates['input'], 'output' => (float) $rates['output']];
        }
        return $out;
    }

    /**
     * Read the legacy override blob as a raw string.
     *
     * @return string
     */
    private static function legacy_raw(): string {
        return (string) (get_config('local_ai_course_assistant', self::LEGACY_SETTING) ?: '');
    }

    /**
     * All overlay rows, tolerating the table not existing yet (mid-upgrade).
     *
     * @return \stdClass[]
     */
    private static function table_rows(): array {
        global $DB;
        try {
            // Leads with the unique id so the array keys cannot collapse rows.
            return $DB->get_records(self::TABLE_MODELS, null, 'modelkey ASC');
        } catch (\Throwable $e) {
            // token_cost_manager is reachable from the upgrade path itself, so a
            // missing table must degrade to "baseline only", not fatal.
            return [];
        }
    }
}
