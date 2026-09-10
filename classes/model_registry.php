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
 * v7.4.4 adds a THIRD property: LIFECYCLE. A row may carry an announced
 * end-of-life date and the API surface that date applies to, so the drift check
 * can warn about a retirement before it becomes an outage. Price and lifecycle
 * are stored together and read together because they are the two facts an
 * operator needs about a model, and the surface is stored because a date
 * without one is a false alarm: gemini-2.5-flash's reported 2026-10-16
 * retirement was a Vertex AI lifecycle event, not the Gemini Developer API this
 * plugin calls.
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

    /**
     * Surface value meaning "every surface this model is served on".
     *
     * A row whose eol_surface is this, or is empty, alerts on any site. That is
     * the fail-loud direction: an EOL nobody scoped is treated as ours until a
     * human says otherwise.
     *
     * @var string
     */
    public const EOL_SURFACE_ANY = 'any';

    /**
     * Config key listing the API surfaces this site actually calls.
     *
     * Empty (the shipped default) means "no surface filtering" — every recorded
     * EOL alerts. Populating it is what lets a site record a Vertex AI
     * retirement for a model it reaches through the Gemini Developer API and
     * NOT be paged about it.
     *
     * @var string
     */
    public const EOL_SURFACES_SETTING = 'model_eol_surfaces';

    /** @var array<string, array{input: float, output: float}>|null Request-scoped rate cache. */
    private static ?array $ratecache = null;

    /**
     * Request-scoped provenance cache, parallel to {@see $ratecache}.
     *
     * @var array<string, array{layer: string, source: ?string, addedby: ?int, timemodified: ?int}>|null
     */
    private static ?array $provcache = null;

    /**
     * Request-scoped lifecycle cache: prefix => announced end-of-life.
     *
     * Deliberately NOT folded into $ratecache. A registry row carrying an EOL
     * date but no price is legal and useful — "this model is being switched off
     * on the 15th" is worth recording whether or not anyone has typed its
     * price — and build_cache() skips priceless rows when merging rates so it
     * cannot clobber a lower layer's real number. Keeping lifecycle in its own
     * map is what lets a priceless row still be seen.
     *
     * @var array<string, array{modelkey: string, eol_date: int, eol_surface: ?string}>|null
     */
    private static ?array $eolcache = null;

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
     * v7.4.4: a model string carrying token_cost_manager::BATCH_MODEL_PREFIX
     * resolves against the UNMARKED name and then has both rates multiplied by
     * BATCH_DISCOUNT. This one function is the only place the offline-batch
     * discount is applied, and it is deliberately here rather than at the
     * writer: every spend consumer in the plugin -- the dashboard, spend_guard's
     * cap accounting, the anomaly detector, llm_optimizer, token_analytics, the
     * CSV export, the price-drift check -- reaches a price through this call, so
     * applying it here means none of them can report the batch line at list
     * price. Halving at write time instead would have baked the discount into a
     * stored number, thrown away the provenance, and left every one of those
     * consumers free to disagree.
     *
     * The registry itself never holds a `batch/` key, so an admin still edits
     * one price per model and the discounted rate follows automatically.
     *
     * @param string $model Exact model identifier from the provider response.
     * @return array{input: float, output: float}|null Rates per 1M tokens, or null if unknown.
     */
    public static function rate_for(string $model): ?array {
        $batched = token_cost_manager::is_batch_model($model);
        $prefix = self::match_prefix($batched ? token_cost_manager::strip_batch_prefix($model) : $model);
        if ($prefix === null) {
            return null;
        }
        $rates = self::effective_rates()[$prefix];
        if (!$batched) {
            return $rates;
        }
        return [
            'input'  => (float) $rates['input'] * token_cost_manager::BATCH_DISCOUNT,
            'output' => (float) $rates['output'] * token_cost_manager::BATCH_DISCOUNT,
        ];
    }

    /**
     * The merged prefix => announced-EOL map. Table layer only.
     *
     * Lifecycle has exactly one source of truth, and it is this table. Neither
     * the committed baseline nor the legacy JSON blob carries a retirement date
     * — they are price cards — so there is nothing to merge and no layering to
     * explain.
     *
     * @return array<string, array{modelkey: string, eol_date: int, eol_surface: ?string}>
     */
    public static function effective_eol(): array {
        $raw = self::legacy_raw();
        if (self::$eolcache === null || self::$legacysig !== $raw) {
            self::build_cache($raw);
        }
        return self::$eolcache;
    }

    /**
     * The announced end-of-life for a model, longest matching prefix wins.
     *
     * Matched against the LIFECYCLE keys, not the rate keys. A registry row can
     * carry an EOL date and no price at all — that row never enters
     * effective_rates(), so resolving lifecycle through match_prefix() would
     * silently miss exactly the rows an operator entered in a hurry because a
     * vendor had just sent a shutdown notice.
     *
     * Batch-marked model strings resolve against the unmarked name, the same
     * way {@see rate_for()} handles them: `batch/gpt-5-mini` is gpt-5-mini, and
     * it retires when gpt-5-mini retires.
     *
     * @param string $model Exact model identifier from the provider response.
     * @return array{modelkey: string, eol_date: int, eol_surface: ?string}|null
     */
    public static function eol_for(string $model): ?array {
        if (token_cost_manager::is_batch_model($model)) {
            $model = token_cost_manager::strip_batch_prefix($model);
        }
        $map = self::effective_eol();
        $prefix = self::longest_key($model, array_keys($map));
        return $prefix === null ? null : $map[$prefix];
    }

    /**
     * The API surfaces this site actually calls, lowercased.
     *
     * @return string[] Empty when the site has not said, which means "do not filter".
     */
    public static function site_surfaces(): array {
        $raw = (string) (get_config('local_ai_course_assistant', self::EOL_SURFACES_SETTING) ?: '');
        $out = [];
        foreach (explode(',', $raw) as $item) {
            $item = strtolower(trim($item));
            if ($item !== '') {
                $out[$item] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Does an announced EOL apply to a surface this site actually calls?
     *
     * THIS IS THE FALSE-ALARM GATE, and it exists because of a specific
     * near-miss. In the 2026-09-08 model review gemini-2.5-flash was reported as
     * retiring on 2026-10-16. That was true — of the Vertex AI lifecycle. This
     * plugin reaches the model through the Gemini Developer API, where no such
     * retirement was announced, so a bare date would have raised a
     * production-outage alarm about a model that was not going anywhere. An
     * operator who is paged about a non-event once stops reading the pages.
     *
     * The rule fails LOUD in both unknown directions: a site that has not
     * listed its surfaces filters nothing, and an EOL row with no surface (or
     * the explicit 'any') applies everywhere. Silence is only ever the result
     * of two positive statements — this site calls these surfaces, and this
     * retirement is on that one.
     *
     * @param string|null $surface Surface recorded on the registry row.
     * @return bool
     */
    public static function eol_applies(?string $surface): bool {
        $surface = strtolower(trim((string) $surface));
        if ($surface === '' || $surface === self::EOL_SURFACE_ANY) {
            return true;
        }
        $site = self::site_surfaces();
        if (empty($site)) {
            return true;
        }
        return in_array($surface, $site, true);
    }

    /**
     * Report which layer supplied a model's rates, and who put it there.
     *
     * This is what the admin UI renders next to each price. On no match the
     * layer is 'none' and every other field is null — that is the state that
     * makes a model's spend read as $0.00.
     *
     * v7.4.4 adds a `batch` flag. It reports the DISCOUNTED rates for a
     * `batch/`-marked model, matching what rate_for() returns, so the admin page
     * cannot show a price the spend figures were not computed from -- and the
     * flag says why the two numbers differ for the same underlying model.
     *
     * v7.4.4 also carries the announced end-of-life, so the admin page can put
     * lifecycle next to the price rather than on a separate screen: the two
     * facts an operator needs about a model are what it costs and how long it
     * will exist, and splitting them is how a retirement goes unread.
     * `eol_applies` is already resolved against this site's surfaces, so no
     * caller has to remember the Vertex-vs-Developer-API distinction itself.
     *
     * @param string $model Exact model identifier.
     * @return array{prefix: ?string, input: ?float, output: ?float, layer: string,
     *               source: ?string, addedby: ?int, timemodified: ?int, batch: bool,
     *               eol_date: ?int, eol_surface: ?string, eol_applies: bool}
     */
    public static function provenance_for(string $model): array {
        $batched = token_cost_manager::is_batch_model($model);
        $prefix = self::match_prefix($batched ? token_cost_manager::strip_batch_prefix($model) : $model);
        $eol = self::eol_for($model);
        $lifecycle = [
            'eol_date'    => $eol !== null ? (int) $eol['eol_date'] : null,
            'eol_surface' => $eol !== null && (string) ($eol['eol_surface'] ?? '') !== ''
                ? (string) $eol['eol_surface'] : null,
            'eol_applies' => $eol !== null && self::eol_applies($eol['eol_surface'] ?? null),
        ];
        if ($prefix === null) {
            return $lifecycle + [
                'prefix'       => null,
                'input'        => null,
                'output'       => null,
                'layer'        => 'none',
                'source'       => null,
                'addedby'      => null,
                'timemodified' => null,
                'batch'        => $batched,
            ];
        }
        $rates = self::rate_for($model);
        $prov = self::$provcache[$prefix] ?? ['layer' => 'baseline', 'source' => null,
            'addedby' => null, 'timemodified' => null];
        return $lifecycle + [
            'prefix'       => $batched ? token_cost_manager::BATCH_MODEL_PREFIX . $prefix : $prefix,
            'input'        => (float) $rates['input'],
            'output'       => (float) $rates['output'],
            'layer'        => (string) $prov['layer'],
            'source'       => $prov['source'] !== null ? (string) $prov['source'] : null,
            'addedby'      => $prov['addedby'] !== null ? (int) $prov['addedby'] : null,
            'timemodified' => $prov['timemodified'] !== null ? (int) $prov['timemodified'] : null,
            'batch'        => $batched,
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
     *                   eol_date, eol_surface, status and notes are optional and
     *                   only overwrite when present in the array.
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
        foreach (['provider', 'capability', 'notes', 'eol_surface'] as $field) {
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
        // Its own branch, not the float loop and not the string loop: a
        // timestamp cast through (float) loses precision past 2038 on 32-bit
        // and reads back as a wrong date, and cast through (string) stores
        // "1760572800" in an int column by luck rather than by intent.
        if (array_key_exists('eol_date', $row)) {
            $record->eol_date = $row['eol_date'] === null || $row['eol_date'] === ''
                ? null : (int) $row['eol_date'];
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
        $out = [];
        foreach (self::models_in_traffic($days) as $row) {
            if (self::rate_for($row['model_name']) !== null) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * EVERY model observed in billable traffic, priced or not.
     *
     * This is the shared traffic query, and it is deliberately the only place
     * in the plugin that pairs analytics::spend_rows_predicate() with the msgs
     * table for this purpose. {@see unpriced_models()} is a filter over it, and
     * so is the v7.4.4 end-of-life check — which could NOT have reused
     * unpriced_models(), because that method skips every model that resolves to
     * a rate and a model being retired almost always has one. Copying the SQL
     * to work around that would have produced a second copy of the predicate,
     * which is exactly the drift the predicate's own docblock warns about.
     *
     * @param int $days Lookback window in days.
     * @return array<int, array{model_name: string, provider: ?string, calls: int,
     *               tokens: int, lastseen: int}> Busiest model first.
     */
    public static function models_in_traffic(int $days = self::DEFAULT_WINDOW_DAYS): array {
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
        self::$eolcache = null;
        self::$legacysig = null;
    }

    /**
     * Longest matching prefix for a model name, or null when nothing matches.
     *
     * @param string $model
     * @return string|null
     */
    private static function match_prefix(string $model): ?string {
        return self::longest_key($model, array_keys(self::effective_rates()));
    }

    /**
     * Longest key in $keys that prefixes $model, or null when none does.
     *
     * Extracted from match_prefix() so the lifecycle map can be searched with
     * exactly the same rule as the rate map. Two copies of "longest prefix
     * wins" would eventually disagree, and the one that disagreed would be the
     * one nobody reads.
     *
     * @param string $model
     * @param string[] $keys
     * @return string|null
     */
    private static function longest_key(string $model, array $keys): ?string {
        $model = strtolower(trim($model));
        if ($model === '') {
            return null;
        }
        $best = null;
        $bestlen = 0;
        foreach ($keys as $prefix) {
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
        $eol = [];

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
            if ($prefix === '') {
                continue;
            }
            // Lifecycle is read BEFORE the priceless-row skip below. A row that
            // says only "this retires on the 15th" carries no input_rate, so
            // reading it after the skip would drop precisely the rows entered
            // in response to a vendor shutdown notice.
            if (!empty($row->eol_date)) {
                $eol[$prefix] = [
                    'modelkey'    => $prefix,
                    'eol_date'    => (int) $row->eol_date,
                    'eol_surface' => isset($row->eol_surface) && trim((string) $row->eol_surface) !== ''
                        ? strtolower(trim((string) $row->eol_surface)) : null,
                ];
            }
            if ($row->input_rate === null) {
                // A row with no input rate carries capability/status/lifecycle
                // metadata only; leaving the price to the lower layer is
                // correct, and beats overwriting a real number with null.
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
        self::$eolcache = $eol;
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
