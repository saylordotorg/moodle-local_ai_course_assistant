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

use local_ai_course_assistant\task\model_price_drift_check;
use local_ai_course_assistant\task\run_model_benchmark;

/**
 * Data and actions behind the model-registry admin page.
 *
 * Everything the page renders and everything a form submit does lives here
 * rather than in model_registry.php, for one reason: a page file cannot be
 * exercised by a test (it requires config.php, calls require_login() and
 * echoes), and the parts most likely to break are the ones that decide what an
 * operator is told — which layer supplied a price, whether a write was refused,
 * whether a benchmark is comparable. Those are the parts under test.
 *
 * Two rules hold throughout:
 *
 *  1. Nothing here writes a file. After the next release there is no
 *     filesystem and no deploy, so every result goes to a database table or a
 *     config value.
 *  2. Nothing here renders. Every method returns arrays of already-resolved
 *     strings and already-escaped-by-Mustache values; the page passes them to
 *     render_from_template(). No HTML is assembled in PHP.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_registry_page {
    /** @var string Page-relative URL, used for form targets and redirects. */
    public const PAGE_URL = '/local/ai_course_assistant/model_registry.php';

    /** @var string[] Recognized POST actions. Anything else is ignored. */
    public const ACTIONS = [
        'savemodel', 'deletemodel', 'savesource', 'togglesource', 'deletesource',
        'driftnow', 'applydrift', 'queuebench',
    ];

    /** @var string[] Statuses a registry row may carry. */
    public const STATUSES = ['active', 'deprecated', 'candidate'];

    /** @var string Notification level: success. */
    public const OK = 'success';

    /** @var string Notification level: error. */
    public const ERROR = 'error';

    /** @var string Notification level: warning. */
    public const WARN = 'warning';

    /**
     * Lookback window for the unpriced-models banner, in days.
     *
     * Deliberately the registry's own default rather than a separate setting:
     * the drift check and this page must answer the same question over the same
     * window, or an operator reading one and acting on the other is comparing
     * two different sets.
     *
     * @var int
     */
    public const UNPRICED_WINDOW_DAYS = model_registry::DEFAULT_WINDOW_DAYS;

    // ---------------------------------------------------------------- Sections.

    /**
     * Models in billable traffic that resolve to no price.
     *
     * This is the severity headline of the page. The state it describes is the
     * one that made 100% of production chat spend compute as $0.00 for a whole
     * release: gemini-2.5-flash was in every message row, in no rate card, and
     * nothing compared the two sets.
     *
     * @param int $days Lookback window.
     * @return array {
     *   'has' => bool, 'count' => int, 'days' => int, 'rows' => array[]
     * }
     */
    public static function unpriced_block(int $days = self::UNPRICED_WINDOW_DAYS): array {
        $observed = model_registry::unpriced_models($days);
        $proposals = self::drift_proposals();

        $rows = [];
        foreach ($observed as $row) {
            $key = strtolower((string) $row['model_name']);
            $proposal = $proposals[$key] ?? null;
            $rows[] = [
                'modelkey'    => $key,
                'model'       => (string) $row['model_name'],
                'provider'    => (string) ($row['provider'] ?? ''),
                'calls'       => number_format((int) $row['calls']),
                'tokens'      => number_format((int) $row['tokens']),
                'lastseen'    => self::date((int) $row['lastseen']),
                'hasproposal' => $proposal !== null,
                'proposal'    => $proposal,
            ];
        }

        return [
            'has'   => !empty($rows),
            'count' => count($rows),
            'days'  => $days,
            'rows'  => $rows,
        ];
    }

    /**
     * The merged rate card, one row per key, with provenance.
     *
     * The prices come from model_registry::effective_rates(), which is the same
     * resolver token_cost_manager bills with — this page cannot show a price
     * that is not the price being charged. The capability/context/status/notes
     * columns exist only on table-layer rows, so they are read separately and
     * left blank for baseline and legacy rows rather than invented.
     *
     * @return array {
     *   'has' => bool, 'count' => int, 'rows' => array[]
     * }
     */
    public static function effective_rows(): array {
        global $DB;

        $rates = model_registry::effective_rates();
        ksort($rates);

        // Keyed on the unique modelkey via get_records (primary-key keyed), so
        // the get_records_sql first-column trap does not apply.
        $tablerows = [];
        try {
            foreach ($DB->get_records(model_registry::TABLE_MODELS) as $row) {
                $tablerows[strtolower((string) $row->modelkey)] = $row;
            }
        } catch (\Throwable $e) {
            $tablerows = [];
        }

        $userids = [];
        $provenance = [];
        foreach (array_keys($rates) as $prefix) {
            $prov = model_registry::provenance_for($prefix);
            $provenance[$prefix] = $prov;
            if (!empty($prov['addedby'])) {
                $userids[(int) $prov['addedby']] = true;
            }
        }
        $names = self::user_names(array_keys($userids));

        $rows = [];
        foreach ($rates as $prefix => $rate) {
            $prov = $provenance[$prefix];
            $tablerow = $tablerows[$prefix] ?? null;
            $addedby = (int) ($prov['addedby'] ?? 0);
            $rows[] = [
                'modelkey'   => $prefix,
                'input'      => self::rate($rate['input']),
                'output'     => self::rate($rate['output']),
                'inputraw'   => self::numberfield($rate['input']),
                'outputraw'  => self::numberfield($rate['output']),
                'layer'      => (string) $prov['layer'],
                'layerlabel' => self::layer_label((string) $prov['layer']),
                'source'     => (string) ($prov['source'] ?? ''),
                'sourcelabel' => self::source_label($prov['source']),
                'intable'    => $tablerow !== null,
                'capability' => $tablerow !== null ? (string) ($tablerow->capability ?? '') : '',
                'context'    => ($tablerow !== null && !empty($tablerow->context_tokens))
                    ? number_format((int) $tablerow->context_tokens) : '',
                'contextraw' => ($tablerow !== null && !empty($tablerow->context_tokens))
                    ? (string) (int) $tablerow->context_tokens : '',
                'provider'   => $tablerow !== null ? (string) ($tablerow->provider ?? '') : '',
                'status'     => $tablerow !== null ? (string) $tablerow->status : '',
                'statuslabel' => $tablerow !== null ? self::status_label((string) $tablerow->status) : '',
                'notes'      => $tablerow !== null ? (string) ($tablerow->notes ?? '') : '',
                'setby'      => $addedby > 0
                    ? ($names[$addedby] ?? get_string('modelregistry:notrecorded', 'local_ai_course_assistant'))
                    : ($prov['layer'] === model_registry::LAYERS[2]
                        ? get_string('modelregistry:setby_feed', 'local_ai_course_assistant')
                        : ''),
                'updated'    => !empty($prov['timemodified']) ? self::date((int) $prov['timemodified']) : '',
            ];
        }

        return ['has' => !empty($rows), 'count' => count($rows), 'rows' => $rows];
    }

    /**
     * Every pricing source, with its last outcome.
     *
     * @return array {
     *   'has' => bool, 'rows' => array[], 'formats' => array[]
     * }
     */
    public static function source_rows(): array {
        global $DB;

        $rows = [];
        try {
            // get_records is keyed on the primary key, which is unique.
            $records = $DB->get_records(model_registry::TABLE_SOURCES, null, 'name ASC, id ASC');
        } catch (\Throwable $e) {
            $records = [];
        }

        foreach ($records as $row) {
            $status = strtolower((string) ($row->laststatus ?? ''));
            $rows[] = [
                'id'          => (int) $row->id,
                'name'        => (string) $row->name,
                'url'         => (string) $row->url,
                'format'      => (string) $row->format,
                'formatlabel' => self::format_label((string) $row->format),
                'spec'        => (string) ($row->spec ?? ''),
                'enabled'     => !empty($row->enabled),
                'enabledlabel' => !empty($row->enabled)
                    ? get_string('modelregistry:yes', 'local_ai_course_assistant')
                    : get_string('modelregistry:no', 'local_ai_course_assistant'),
                'lastfetch'   => !empty($row->lastfetch)
                    ? self::date((int) $row->lastfetch)
                    : get_string('modelregistry:never_fetched', 'local_ai_course_assistant'),
                'statusok'    => $status === 'ok',
                'statuserror' => $status === 'error',
                'statuslabel' => $status === ''
                    ? ''
                    : ($status === 'ok'
                        ? get_string('modelregistry:status_ok', 'local_ai_course_assistant')
                        : get_string('modelregistry:status_error', 'local_ai_course_assistant')),
                // Rendered verbatim. price_source writes full operator-facing
                // sentences here, including the REGRESSION prefix that says a
                // source used to return prices and now returns none; truncating
                // it would remove the only explanation there is.
                'lastmessage' => (string) ($row->lastmessage ?? ''),
            ];
        }

        return ['has' => !empty($rows), 'rows' => $rows, 'formats' => self::format_options()];
    }

    /**
     * The last drift check's findings, ready to render and to apply.
     *
     * @return array {
     *   'hasrun' => bool, 'lastrun' => string, 'status' => string,
     *   'statusnote' => string, 'tolerance' => string, 'counts' => array,
     *   'rows' => array[], 'truncated' => int
     * }
     */
    public static function drift_block(): array {
        $summary = model_price_drift_check::last_summary();
        $out = [
            'hasrun'     => false,
            'lastrun'    => get_string('modelregistry:drift_never', 'local_ai_course_assistant'),
            'statusnote' => '',
            'tolerance'  => self::plainnumber(model_price_drift_check::tolerance_pct()),
            'counts'     => ['missing' => 0, 'mismatch' => 0, 'new' => 0],
            'rows'       => [],
            'truncated'  => 0,
            'has'        => false,
        ];
        if ($summary === null) {
            return $out;
        }

        $out['hasrun'] = true;
        $out['lastrun'] = get_string(
            'modelregistry:drift_lastrun',
            'local_ai_course_assistant',
            self::date((int) ($summary['timerun'] ?? 0))
        );
        $status = (string) ($summary['status'] ?? '');
        if ($status === 'no_sources') {
            $out['statusnote'] = get_string('modelregistry:drift_status_no_sources', 'local_ai_course_assistant');
        } else if ($status === 'all_sources_failed') {
            $out['statusnote'] = get_string(
                'modelregistry:drift_status_all_sources_failed',
                'local_ai_course_assistant'
            );
        }
        $counts = (array) ($summary['counts'] ?? []);
        $out['counts'] = [
            'missing'  => (int) ($counts['missing'] ?? 0),
            'mismatch' => (int) ($counts['mismatch'] ?? 0),
            'new'      => (int) ($counts['new'] ?? 0),
        ];
        $truncated = (array) ($summary['truncated'] ?? []);
        $out['truncated'] = (int) ($truncated['missing'] ?? 0)
            + (int) ($truncated['new'] ?? 0)
            + (int) ($truncated['stored'] ?? 0);

        foreach ((array) ($summary['findings'] ?? []) as $finding) {
            $type = (string) ($finding['type'] ?? '');
            $hasrates = ($finding['input'] ?? null) !== null || ($finding['output'] ?? null) !== null;
            $out['rows'][] = [
                'type'         => $type,
                'typelabel'    => self::finding_label($type),
                'ismissing'    => $type === 'missing',
                'ismismatch'   => $type === 'mismatch',
                'isnew'        => $type === 'new',
                'modelkey'     => (string) ($finding['modelkey'] ?? ''),
                'provider'     => (string) ($finding['provider'] ?? ''),
                'capability'   => (string) ($finding['capability'] ?? ''),
                'proposed'     => $hasrates
                    ? self::rate($finding['input']) . ' / ' . self::rate($finding['output'])
                    : '',
                'registry'     => ($finding['registry_input'] ?? null) !== null
                    ? self::rate($finding['registry_input']) . ' / ' . self::rate($finding['registry_output'])
                    : '',
                'delta'        => ($finding['delta_pct_input'] ?? null) !== null
                    ? self::plainnumber((float) $finding['delta_pct_input']) . '% / '
                        . self::plainnumber((float) ($finding['delta_pct_output'] ?? 0)) . '%'
                    : '',
                'calls'        => ($finding['calls'] ?? null) !== null
                    ? number_format((int) $finding['calls']) : '',
                'sourcename'   => (string) ($finding['sourcename'] ?? ''),
                'hasrates'     => $hasrates,
                // Everything apply() needs, so the button posts the numbers the
                // operator can see rather than re-deriving them from a rerun.
                'applyinput'   => $hasrates ? self::numberfield($finding['input']) : '',
                'applyoutput'  => $hasrates ? self::numberfield($finding['output']) : '',
                'applycontext' => ($finding['context'] ?? null) !== null
                    ? (string) (int) $finding['context'] : '',
            ];
        }
        $out['has'] = !empty($out['rows']);

        return $out;
    }

    /**
     * Benchmark results and recommendations, one card per SOLA function.
     *
     * Refusals are first-class here. A function whose configured model has
     * never been benchmarked, or whose only run scored six items, gets a card
     * saying exactly that — not an empty card, and never a substituted neutral
     * score. That substitution is what llm_optimizer does (0.5 when ratings are
     * sparse) and it is why it can rank only models already in production.
     *
     * @return array {
     *   'cards' => array[], 'unmeasured' => array[], 'tunables' => string,
     *   'release' => string, 'floor' => int
     * }
     */
    public static function bench_block(): array {
        $report = model_recommender::report();
        $tunables = (array) $report['tunables'];
        $floor = model_bench::configured_floor();

        $cards = [];
        foreach ((array) $report['functions'] as $function => $card) {
            $cards[] = self::bench_card((string) $function, (array) $card, $tunables, $floor);
        }

        $unmeasured = [];
        foreach ((array) $report['unmeasured'] as $entry) {
            $unmeasured[] = [
                'function' => (string) ($entry['function'] ?? ''),
                'label'    => self::function_label((string) ($entry['function'] ?? '')),
                'provider' => (string) ($entry['provider'] ?? ''),
                'model'    => (string) ($entry['model'] ?? ''),
                'inherited' => !empty($entry['inherited']),
                'reason'   => self::no_recommendation_label(
                    (string) ($entry['reason'] ?? ''),
                    (array) ($entry['detail'] ?? []),
                    $entry
                ),
            ];
        }

        return [
            'cards'      => $cards,
            'unmeasured' => $unmeasured,
            'hasunmeasured' => !empty($unmeasured),
            'release'    => (string) $report['release'],
            'floor'      => $floor,
            'tunables'   => get_string('modelregistry:tunables', 'local_ai_course_assistant', (object) [
                'epsilon'      => self::plainnumber((float) $tunables['epsilon']),
                'savingsfloor' => self::plainnumber((float) $tunables['savingsfloor']),
                'margin'       => self::plainnumber((float) $tunables['margin']),
                'minqualityn'  => (int) $tunables['minqualityn'],
            ]),
        ];
    }

    /**
     * One function's card: configured model, candidates, refusals, stored runs.
     *
     * @param string $function
     * @param array $card From model_recommender::for_function().
     * @param array $tunables
     * @param int $floor model_bench comparability floor.
     * @return array
     */
    private static function bench_card(string $function, array $card, array $tunables, int $floor): array {
        $current = (array) ($card['current'] ?? []);
        $volume = $card['volume'] ?? null;

        $out = [
            'function'   => $function,
            'label'      => self::function_label($function),
            'current'    => get_string(
                'rec:currentmodel',
                'local_ai_course_assistant',
                trim(($current['provider'] ?? '') . ' ' . ($current['model'] ?? '')) !== ''
                    ? trim(($current['provider'] ?? '') . ' / ' . ($current['model'] ?? ''), ' /')
                    : '—'
            ),
            'inherited'  => !empty($current['inherited']),
            'inheritedlabel' => get_string('rec:inherited', 'local_ai_course_assistant'),
            'currentquality' => self::quality_cell($current, $floor),
            'currentcost' => self::cents($current['cost_cents_per_call'] ?? null),
            'currentmeasured' => !empty($current['measured']),
            'candidates' => [],
            'recommendation' => null,
            'norecommendation' => null,
            'volumenote' => '',
            'sharednote' => '',
            'runs'       => [],
            'modelkey'   => strtolower(trim((string) ($current['model'] ?? ''))),
        ];

        foreach ((array) ($card['candidates'] ?? []) as $candidate) {
            $out['candidates'][] = self::candidate_row($candidate, $tunables, $floor);
        }
        $out['hascandidates'] = !empty($out['candidates']);

        if (!empty($card['recommendation'])) {
            $out['recommendation'] = self::candidate_row($card['recommendation'], $tunables, $floor);
        }
        if (!empty($card['no_recommendation'])) {
            $nr = (array) $card['no_recommendation'];
            $out['norecommendation'] = self::no_recommendation_label(
                (string) ($nr['reason'] ?? ''),
                (array) ($nr['detail'] ?? []),
                $current
            );
        }

        if (is_array($volume)) {
            if (empty($volume['observed'])) {
                $out['volumenote'] = get_string('rec:novolume', 'local_ai_course_assistant');
            }
            if (!empty($volume['shared_with'])) {
                $labels = [];
                foreach ((array) $volume['shared_with'] as $shared) {
                    $labels[] = self::function_label((string) $shared);
                }
                $out['sharednote'] = get_string(
                    'rec:sharedvolume',
                    'local_ai_course_assistant',
                    implode(', ', $labels)
                );
            }
        }

        // Stored runs for this function, floor 1 so a below-floor run is shown
        // and labelled rather than silently dropped.
        foreach (model_bench::latest_by_function($function, 1) as $run) {
            $out['runs'][] = self::run_row($run, $floor);
        }
        $out['hasruns'] = !empty($out['runs']);

        return $out;
    }

    /**
     * A candidate model's row on a recommendation card.
     *
     * @param array $candidate
     * @param array $tunables
     * @param int $floor
     * @return array
     */
    private static function candidate_row(array $candidate, array $tunables, int $floor): array {
        $verdict = (string) ($candidate['verdict'] ?? '');
        return [
            'model'        => (string) ($candidate['model'] ?? ''),
            'provider'     => (string) ($candidate['provider'] ?? ''),
            'registrykey'  => (string) ($candidate['registry_key'] ?? ''),
            'verdict'      => $verdict,
            'verdictlabel' => self::verdict_label($verdict),
            'recommendable' => !empty($candidate['recommendable']),
            'comparable'   => !empty($candidate['comparable']),
            'quality'      => self::quality_cell($candidate, $floor),
            'cost'         => self::cents($candidate['cost_cents_per_call'] ?? null),
            'ttft'         => ($candidate['p50_ttft_ms'] ?? null) !== null
                ? get_string('modelregistry:ms', 'local_ai_course_assistant', number_format((int) $candidate['p50_ttft_ms']))
                : '',
            'qualitydelta' => ($candidate['quality_delta'] ?? null) !== null
                ? self::signed((float) $candidate['quality_delta']) : '',
            'costdelta'    => ($candidate['cost_delta_cents'] ?? null) !== null
                ? self::signed((float) $candidate['cost_delta_cents'], 4) : '',
            'monthly'      => ($candidate['monthly_delta_usd'] ?? null) !== null
                ? get_string(
                    'rec:projectedmonthly',
                    'local_ai_course_assistant',
                    '$' . self::signed((float) $candidate['monthly_delta_usd'], 2)
                )
                : '',
            'group'        => trim((string) ($candidate['comparable_group'] ?? ''), '/') !== ''
                ? get_string(
                    'bench:comparable_group',
                    'local_ai_course_assistant',
                    (string) $candidate['comparable_group']
                )
                : '',
            'release'      => !empty($candidate['plugin_release'])
                ? \local_ai_course_assistant\branding::str(
                    'bench:release_stamp',
                    (string) $candidate['plugin_release']
                )
                : '',
            'refusal'      => empty($candidate['comparable'])
                ? self::not_comparable_label(
                    (string) ($candidate['not_comparable_reason'] ?? ''),
                    $candidate,
                    $tunables
                )
                : '',
        ];
    }

    /**
     * One stored benchmark run, as a table row.
     *
     * Carries the fixture set, the fixture count and the SMOKE/DECISION-GRADE
     * stamp next to the numbers, because a rubric mean over 40 two-course
     * fixtures and one over the 816-row production-shaped set are not the same
     * measurement. The identity column is never truncated: an eight-character
     * truncation is what made the 2026-09-08 per-course readout unusable.
     *
     * @param array $run From model_bench::export_row().
     * @param int $floor
     * @return array
     */
    private static function run_row(array $run, int $floor): array {
        $n = (int) ($run['quality_n'] ?? 0);
        $fixturen = (int) ($run['fixture_n'] ?? 0);
        return [
            'model'      => (string) ($run['model_name'] ?? ''),
            'provider'   => (string) ($run['provider'] ?? ''),
            'registrykey' => (string) ($run['registry_key'] ?? ''),
            'harness'    => (string) ($run['harness'] ?? ''),
            'fixtureset' => (string) ($run['fixture_set'] ?? ''),
            'quality'    => self::quality_cell($run, $floor),
            'cost'       => self::cents($run['cost_cents_per_call'] ?? null),
            'ttft'       => ($run['p50_ttft_ms'] ?? null) !== null
                ? get_string('modelregistry:ms', 'local_ai_course_assistant', number_format((int) $run['p50_ttft_ms']))
                : '',
            'calls'      => ($run['calls'] ?? null) !== null ? number_format((int) $run['calls']) : '',
            'errors'     => (int) ($run['errors'] ?? 0),
            'status'     => (string) ($run['status'] ?? ''),
            'statuslabel' => self::run_status_label((string) ($run['status'] ?? '')),
            'message'    => (string) ($run['message'] ?? ''),
            'measured'   => self::date((int) ($run['timecreated'] ?? 0)),
            'release'    => !empty($run['plugin_release'])
                ? \local_ai_course_assistant\branding::str('bench:release_stamp', (string) $run['plugin_release'])
                : '',
            'belowfloor' => $n > 0 && $n < $floor,
            'floorlabel' => get_string('bench:not_comparable', 'local_ai_course_assistant', $n),
            'grade'      => $fixturen > 0 ? self::grade_stamp((string) ($run['fixture_set'] ?? ''), $fixturen) : '',
        ];
    }

    /**
     * Registry keys a benchmark can be filed against, for the queue form.
     *
     * @return array[] List of ['value' => key, 'label' => key + provider].
     */
    public static function queue_options(): array {
        global $DB;

        $out = [];
        try {
            $rs = $DB->get_recordset_sql(
                'SELECT id, modelkey, provider, capability, status
                   FROM {' . model_registry::TABLE_MODELS . '}
                  ORDER BY modelkey ASC'
            );
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($rs as $row) {
            $provider = trim((string) ($row->provider ?? ''));
            $out[] = [
                'value' => (string) $row->modelkey,
                'label' => $provider !== ''
                    ? $row->modelkey . ' (' . $provider . ')'
                    : (string) $row->modelkey,
            ];
        }
        $rs->close();

        return $out;
    }

    /**
     * SOLA functions a benchmark or recommendation can be filed against.
     *
     * @return array[] List of ['value' => key, 'label' => localized label].
     */
    public static function function_options(): array {
        $out = [];
        foreach (array_keys(model_recommender::FUNCTIONS) as $function) {
            $out[] = ['value' => $function, 'label' => self::function_label((string) $function)];
        }
        return $out;
    }

    /**
     * Status options for the model form.
     *
     * @return array[]
     */
    public static function status_options(): array {
        $out = [];
        foreach (self::STATUSES as $status) {
            $out[] = ['value' => $status, 'label' => self::status_label($status)];
        }
        return $out;
    }

    /**
     * Parse-format options for the source form.
     *
     * @return array[]
     */
    public static function format_options(): array {
        $out = [];
        foreach (price_source::FORMATS as $format) {
            $out[] = ['value' => $format, 'label' => self::format_label($format)];
        }
        return $out;
    }

    // ---------------------------------------------------------------- Actions.

    /**
     * Add or correct one model, as a human.
     *
     * Recorded with source 'manual', which is load-bearing: model_registry
     * refuses to let the weekly upstream refresh or a drift finding overwrite a
     * manual row afterwards. That refusal is the fix for the pre-v7.4.0 defect
     * where the weekly run wrote the whole override blob and destroyed every
     * hand-entered price.
     *
     * @param array $form Raw, already type-cleaned form values.
     * @param int $userid Author.
     * @return array ['level' => string, 'message' => string]
     */
    public static function save_model(array $form, int $userid): array {
        $key = strtolower(trim((string) ($form['modelkey'] ?? '')));
        if ($key === '') {
            return self::result(self::ERROR, get_string('modelregistry:err_nokey', 'local_ai_course_assistant'));
        }
        foreach (['input_rate', 'output_rate'] as $field) {
            $value = trim((string) ($form[$field] ?? ''));
            if ($value !== '' && !is_numeric($value)) {
                return self::result(
                    self::ERROR,
                    get_string('modelregistry:err_badrate', 'local_ai_course_assistant')
                );
            }
        }

        $row = [
            'modelkey'       => $key,
            'provider'       => trim((string) ($form['provider'] ?? '')),
            'capability'     => trim((string) ($form['capability'] ?? '')),
            'input_rate'     => trim((string) ($form['input_rate'] ?? '')),
            'output_rate'    => trim((string) ($form['output_rate'] ?? '')),
            'context_tokens' => trim((string) ($form['context_tokens'] ?? '')),
            'notes'          => trim((string) ($form['notes'] ?? '')),
        ];
        $status = strtolower(trim((string) ($form['status'] ?? '')));
        if (in_array($status, self::STATUSES, true)) {
            $row['status'] = $status;
        }

        $outcome = null;
        model_registry::upsert($row, 'manual', $userid ?: null, $outcome);

        if ($outcome === 'inserted') {
            return self::result(
                self::OK,
                get_string('modelregistry:saved_inserted', 'local_ai_course_assistant', $key)
            );
        }
        if ($outcome === 'skipped_manual') {
            // Unreachable for a manual write today (a manual source may update a
            // manual row), reported rather than assumed so a future change to
            // the refusal rule cannot make this page lie about having saved.
            return self::result(
                self::WARN,
                get_string('modelregistry:saved_skipped', 'local_ai_course_assistant', $key)
            );
        }
        return self::result(
            self::OK,
            get_string('modelregistry:saved_updated', 'local_ai_course_assistant', $key)
        );
    }

    /**
     * Delete one registry row.
     *
     * The price then falls back to whatever the lower layers say, which may be
     * a different number or none at all — the confirm text says so.
     *
     * @param string $key
     * @return array ['level' => string, 'message' => string]
     */
    public static function delete_model(string $key): array {
        global $DB;

        $key = strtolower(trim($key));
        if ($key === '') {
            return self::result(self::ERROR, get_string('modelregistry:err_nokey', 'local_ai_course_assistant'));
        }
        if (!$DB->record_exists(model_registry::TABLE_MODELS, ['modelkey' => $key])) {
            return self::result(self::ERROR, get_string('modelregistry:err_norow', 'local_ai_course_assistant'));
        }
        $DB->delete_records(model_registry::TABLE_MODELS, ['modelkey' => $key]);
        model_registry::reset_cache();

        return self::result(
            self::OK,
            get_string('modelregistry:deleted', 'local_ai_course_assistant', $key)
        );
    }

    /**
     * Add or edit a pricing source.
     *
     * A source is a row, never a class: that is the whole point of the format +
     * spec pair. The spec is validated as JSON here so a typo is refused at the
     * form rather than discovered as a failed cron fetch a day later.
     *
     * @param array $form
     * @param int $userid
     * @return array ['level' => string, 'message' => string]
     */
    public static function save_source(array $form, int $userid): array {
        global $DB;

        $name = trim((string) ($form['name'] ?? ''));
        $url = trim((string) ($form['url'] ?? ''));
        $format = strtolower(trim((string) ($form['format'] ?? '')));
        $spec = trim((string) ($form['spec'] ?? ''));
        $id = (int) ($form['sourceid'] ?? 0);

        if ($name === '') {
            return self::result(self::ERROR, get_string('modelregistry:err_sourcename', 'local_ai_course_assistant'));
        }
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return self::result(self::ERROR, get_string('modelregistry:err_sourceurl', 'local_ai_course_assistant'));
        }
        if (!in_array($format, price_source::FORMATS, true)) {
            return self::result(self::ERROR, get_string('modelregistry:err_sourceformat', 'local_ai_course_assistant'));
        }
        if ($spec !== '' && price_source::decode_spec($spec) === null) {
            return self::result(self::ERROR, get_string('modelregistry:err_sourcespec', 'local_ai_course_assistant'));
        }

        $record = new \stdClass();
        $record->name = $name;
        $record->url = $url;
        $record->format = $format;
        $record->spec = $spec !== '' ? $spec : null;
        $record->enabled = !empty($form['enabled']) ? 1 : 0;
        $record->timemodified = time();

        if ($id > 0) {
            $existing = $DB->get_record(model_registry::TABLE_SOURCES, ['id' => $id]);
            if (!$existing) {
                return self::result(self::ERROR, get_string('modelregistry:err_nosource', 'local_ai_course_assistant'));
            }
            $record->id = $id;
            $DB->update_record(model_registry::TABLE_SOURCES, $record);
        } else {
            $record->addedby = $userid ?: null;
            $record->timecreated = time();
            $DB->insert_record(model_registry::TABLE_SOURCES, $record);
        }

        return self::result(
            self::OK,
            get_string('modelregistry:source_saved', 'local_ai_course_assistant', $name)
        );
    }

    /**
     * Enable or disable one source.
     *
     * @param int $id
     * @param bool $enable
     * @return array ['level' => string, 'message' => string]
     */
    public static function toggle_source(int $id, bool $enable): array {
        global $DB;

        $row = $DB->get_record(model_registry::TABLE_SOURCES, ['id' => $id], 'id, name');
        if (!$row) {
            return self::result(self::ERROR, get_string('modelregistry:err_nosource', 'local_ai_course_assistant'));
        }
        $DB->set_field(model_registry::TABLE_SOURCES, 'enabled', $enable ? 1 : 0, ['id' => $id]);
        $DB->set_field(model_registry::TABLE_SOURCES, 'timemodified', time(), ['id' => $id]);

        return self::result(
            self::OK,
            get_string(
                $enable ? 'modelregistry:source_enabled' : 'modelregistry:source_disabled',
                'local_ai_course_assistant',
                (string) $row->name
            )
        );
    }

    /**
     * Delete one source. Its stored price count goes with it.
     *
     * @param int $id
     * @return array ['level' => string, 'message' => string]
     */
    public static function delete_source(int $id): array {
        global $DB;

        $row = $DB->get_record(model_registry::TABLE_SOURCES, ['id' => $id], 'id, name');
        if (!$row) {
            return self::result(self::ERROR, get_string('modelregistry:err_nosource', 'local_ai_course_assistant'));
        }
        $DB->delete_records(model_registry::TABLE_SOURCES, ['id' => $id]);
        // The per-source previous-count marker would otherwise outlive the row
        // and make a brand-new source with a recycled id report a regression.
        unset_config(price_source::count_key($id), 'local_ai_course_assistant');

        return self::result(
            self::OK,
            get_string('modelregistry:source_deleted', 'local_ai_course_assistant', (string) $row->name)
        );
    }

    /**
     * Run the drift check now, from the web.
     *
     * The scheduled task's enabled flag is deliberately not consulted: an
     * operator who has just added a source needs to see whether it parses
     * without waiting a day, and the check writes no prices — only findings.
     *
     * @return array ['level' => string, 'message' => string]
     */
    public static function run_drift(): array {
        $summary = model_price_drift_check::run();
        $counts = (array) ($summary['counts'] ?? []);
        $level = (($counts['missing'] ?? 0) > 0 || ($counts['mismatch'] ?? 0) > 0) ? self::WARN : self::OK;

        return self::result($level, get_string(
            'modelregistry:drift_ran',
            'local_ai_course_assistant',
            (object) [
                'missing'  => (int) ($counts['missing'] ?? 0),
                'mismatch' => (int) ($counts['mismatch'] ?? 0),
                'new'      => (int) ($counts['new'] ?? 0),
            ]
        ));
    }

    /**
     * Apply one proposed price from a drift finding.
     *
     * Written with source 'drift', not 'manual': the number came from a feed,
     * and recording it as hand-entered would make the provenance column lie and
     * would wrongly pin the row against future upstream corrections. A finding
     * with no proposal is refused rather than written as zero — pricing a model
     * at zero is the exact failure this release exists to remove.
     *
     * @param array $form
     * @param int $userid Ignored for the row's addedby (a feed price has no
     *                    human author), used only to authorize the click.
     * @return array ['level' => string, 'message' => string]
     */
    public static function apply_drift(array $form, int $userid = 0): array {
        $key = strtolower(trim((string) ($form['modelkey'] ?? '')));
        if ($key === '') {
            return self::result(self::ERROR, get_string('modelregistry:err_nokey', 'local_ai_course_assistant'));
        }
        $input = trim((string) ($form['input_rate'] ?? ''));
        $output = trim((string) ($form['output_rate'] ?? ''));
        if ($input === '' && $output === '') {
            return self::result(
                self::ERROR,
                get_string('modelregistry:drift_apply_norates', 'local_ai_course_assistant')
            );
        }
        if (($input !== '' && !is_numeric($input)) || ($output !== '' && !is_numeric($output))) {
            return self::result(self::ERROR, get_string('modelregistry:err_badrate', 'local_ai_course_assistant'));
        }

        $outcome = null;
        model_registry::upsert([
            'modelkey'       => $key,
            'provider'       => trim((string) ($form['provider'] ?? '')),
            'capability'     => trim((string) ($form['capability'] ?? '')),
            'input_rate'     => $input,
            'output_rate'    => $output,
            'context_tokens' => trim((string) ($form['context_tokens'] ?? '')),
        ], 'drift', null, $outcome);

        if ($outcome === 'skipped_manual') {
            return self::result(
                self::WARN,
                get_string('modelregistry:saved_skipped', 'local_ai_course_assistant', $key)
            );
        }
        return self::result(
            self::OK,
            get_string('modelregistry:drift_applied', 'local_ai_course_assistant', $key)
        );
    }

    /**
     * Queue a benchmark from the web.
     *
     * The row is created as `queued` BEFORE the task, so an operator who queues
     * a run and then watches cron do nothing can see that the request was
     * recorded. The task claims that row by runid, so a duplicated cron pick-up
     * cannot bill the run twice.
     *
     * @param string $key Registry key.
     * @param string $function SOLA function the result stands for.
     * @param int $samples Prompts; 0 takes the configured default.
     * @param int $userid Who asked.
     * @return array ['level' => string, 'message' => string, 'runid' => string]
     */
    public static function queue_benchmark(string $key, string $function, int $samples, int $userid): array {
        global $DB;

        $key = strtolower(trim($key));
        if ($key === '') {
            return self::result(self::ERROR, get_string('modelregistry:queue_err_nokey', 'local_ai_course_assistant'));
        }
        if (!isset(model_recommender::FUNCTIONS[$function])) {
            return self::result(
                self::ERROR,
                get_string('modelregistry:queue_err_function', 'local_ai_course_assistant')
            );
        }

        $registry = $DB->get_record(
            model_registry::TABLE_MODELS,
            ['modelkey' => $key],
            'id, modelkey, provider'
        );
        if (!$registry) {
            return self::result(self::ERROR, get_string('modelregistry:err_norow', 'local_ai_course_assistant'));
        }

        $samples = $samples > 0
            ? min(run_model_benchmark::MAX_SAMPLES, $samples)
            : 0;

        $runid = model_bench::start_run([
            'harness'        => run_model_benchmark::HARNESS_TUTOR_GOLDEN,
            'sola_function'  => $function,
            'registry_key'   => $key,
            'provider'       => (string) ($registry->provider ?? ''),
            'model_name'     => (string) $registry->modelkey,
            'fixture_set'    => run_model_benchmark::DEFAULT_FIXTURE,
            'quality_metric' => 'rubric_mean',
            'status'         => model_bench::STATUS_QUEUED,
            'createdby'      => $userid,
            'params'         => $samples > 0 ? ['samples' => $samples] : [],
        ]);

        $task = new run_model_benchmark();
        $data = [
            'registry_key'  => $key,
            'sola_function' => $function,
            'harness'       => run_model_benchmark::HARNESS_TUTOR_GOLDEN,
            'runid'         => $runid,
            'createdby'     => $userid,
        ];
        if ($samples > 0) {
            $data['samples'] = $samples;
        }
        $task->set_custom_data($data);
        \core\task\manager::queue_adhoc_task($task);

        $result = self::result(self::OK, get_string('bench:queued', 'local_ai_course_assistant'));
        $result['runid'] = $runid;
        return $result;
    }

    // ------------------------------------------------------------- Formatting.

    /**
     * Proposed prices from the last drift run, keyed by model key.
     *
     * Read from the stored summary rather than by re-fetching every source: the
     * unpriced banner must not make network calls on a page load.
     *
     * @return array<string, array>
     */
    private static function drift_proposals(): array {
        $summary = model_price_drift_check::last_summary();
        if ($summary === null) {
            return [];
        }
        $out = [];
        foreach ((array) ($summary['findings'] ?? []) as $finding) {
            $key = strtolower((string) ($finding['modelkey'] ?? ''));
            if ($key === '' || ($finding['input'] ?? null) === null) {
                continue;
            }
            $out[$key] = [
                'input'      => self::numberfield($finding['input']),
                'output'     => self::numberfield($finding['output']),
                'inputlabel' => self::rate($finding['input']),
                'outputlabel' => self::rate($finding['output']),
                'provider'   => (string) ($finding['provider'] ?? ''),
                'capability' => (string) ($finding['capability'] ?? ''),
                'context'    => ($finding['context'] ?? null) !== null ? (string) (int) $finding['context'] : '',
                'sourcename' => (string) ($finding['sourcename'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Quality as an operator can read it: native units, with the sample size.
     *
     * Never the normalized 0..1 score alone. "0.97" hides that the scale was 15
     * and that three items were scored.
     *
     * @param array $row Anything carrying quality_raw/quality_max/quality_n.
     * @param int $floor Comparability floor, for the reference-only label.
     * @return array ['has' => bool, 'value' => string, 'n' => string, 'belowfloor' => bool]
     */
    private static function quality_cell(array $row, int $floor): array {
        $raw = $row['quality_raw'] ?? null;
        $max = $row['quality_max'] ?? null;
        $score = $row['quality_score'] ?? null;
        $n = (int) ($row['quality_n'] ?? 0);

        if ($raw !== null && $max !== null && (float) $max > 0.0) {
            $value = get_string('bench:quality_of', 'local_ai_course_assistant', (object) [
                'raw' => self::plainnumber((float) $raw),
                'max' => self::plainnumber((float) $max),
            ]);
        } else if ($score !== null) {
            $value = self::plainnumber((float) $score);
        } else {
            return ['has' => false, 'value' => '', 'n' => '', 'belowfloor' => false];
        }

        return [
            'has'        => true,
            'value'      => $value,
            'n'          => $n > 0 ? get_string('bench:quality_n', 'local_ai_course_assistant', $n) : '',
            'belowfloor' => $n > 0 && $n < $floor,
        ];
    }

    /**
     * The fixture-set grade stamp, mirroring the CLI harness's rule.
     *
     * Floor of 200 fixtures, plus a name-based demotion of the two-course
     * bus101_pol101 smoke set so padding it cannot promote it: two courses
     * cannot represent the 13-course production shape at any size.
     *
     * @param string $fixtureset
     * @param int $n
     * @return string
     */
    public static function grade_stamp(string $fixtureset, int $n): string {
        $smoke = $n < 200 || stripos($fixtureset, 'bus101_pol101') !== false;
        return $smoke
            ? get_string('bench:grade_smoke', 'local_ai_course_assistant', $n)
            : get_string('bench:grade_decision', 'local_ai_course_assistant', $n);
    }

    /**
     * Localized label for a not-comparable reason, rebuilt from the run's own
     * numbers rather than from the recommender's English sentence.
     *
     * @param string $reason model_recommender::NC_* value.
     * @param array $row The candidate/current record.
     * @param array $tunables
     * @return string
     */
    public static function not_comparable_label(string $reason, array $row, array $tunables): string {
        if ($reason === model_recommender::NC_LOW_QUALITY_N) {
            return get_string('rec:nc_low_quality_n', 'local_ai_course_assistant', (object) [
                'n'     => (int) ($row['quality_n'] ?? 0),
                'floor' => (int) ($tunables['minqualityn'] ?? 0),
            ]);
        }
        if ($reason === model_recommender::NC_RELEASE_MISMATCH) {
            return get_string('rec:nc_release_mismatch', 'local_ai_course_assistant', (object) [
                'runrelease' => (string) ($row['plugin_release'] ?? ''),
                'release'    => (string) ($tunables['release'] ?? model_recommender::resolve_release()),
            ]);
        }
        $map = [
            model_recommender::NC_INCOMPLETE_RUN => 'rec:nc_run_not_complete',
            model_recommender::NC_MISSING_METRIC => 'rec:nc_missing_metric',
            model_recommender::NC_FIXTURE_MISMATCH => 'rec:nc_fixture_mismatch',
        ];
        return isset($map[$reason]) ? get_string($map[$reason], 'local_ai_course_assistant') : '';
    }

    /**
     * Localized label for a no-recommendation reason.
     *
     * @param string $reason model_recommender::NR_* value.
     * @param array $detail The refusal's detail payload.
     * @param array $row Current-model record, for the nested refusal reason.
     * @return string
     */
    public static function no_recommendation_label(string $reason, array $detail, $row = []): string {
        $row = (array) $row;
        if ($reason === model_recommender::NR_CURRENT_NOT_COMPARABLE) {
            // The nested reason is the useful part: "not comparable" on its own
            // does not tell an operator whether to run a bigger benchmark or to
            // re-run it on this release. Rebuilt from the run's own numbers so
            // the sentence is localizable; the recommender's English detail
            // string is the fallback only when the code is one this page does
            // not know, which would otherwise render as an empty reason.
            $nested = (string) ($detail['not_comparable_reason'] ?? '');
            $inner = self::not_comparable_label($nested, $row, model_recommender::tunables());
            if ($inner === '') {
                $inner = (string) ($detail['not_comparable_detail'] ?? '');
            }
            return get_string('rec:nr_current_not_comparable', 'local_ai_course_assistant', $inner);
        }
        $map = [
            model_recommender::NR_NOT_CONFIGURED => 'rec:nr_not_configured',
            model_recommender::NR_NO_BENCHMARKS => 'rec:nr_no_benchmarks',
            model_recommender::NR_CURRENT_UNMEASURED => 'rec:nr_current_unmeasured',
            model_recommender::NR_NO_COMPARABLE_CANDIDATES => 'rec:nr_no_comparable_candidates',
            model_recommender::NR_NO_MATERIAL_GAIN => 'rec:nr_no_material_gain',
        ];
        return isset($map[$reason]) ? get_string($map[$reason], 'local_ai_course_assistant') : '';
    }

    /**
     * Localized verdict label.
     *
     * @param string $verdict
     * @return string
     */
    public static function verdict_label(string $verdict): string {
        $map = [
            model_recommender::VERDICT_COST_SAVING => 'rec:verdict_cost_saving',
            model_recommender::VERDICT_QUALITY_GAIN => 'rec:verdict_quality_gain',
            model_recommender::VERDICT_NO_GAIN => 'rec:verdict_no_material_gain',
            model_recommender::VERDICT_NOT_COMPARABLE => 'rec:verdict_not_comparable',
        ];
        return isset($map[$verdict]) ? get_string($map[$verdict], 'local_ai_course_assistant') : '';
    }

    /**
     * Localized label for a SOLA function.
     *
     * The English labels in model_recommender::FUNCTIONS are for logs and
     * exports; an admin page must not render a PHP literal.
     *
     * @param string $function
     * @return string
     */
    public static function function_label(string $function): string {
        $key = 'modelregistry:function_' . $function;
        if (get_string_manager()->string_exists($key, 'local_ai_course_assistant')) {
            return get_string($key, 'local_ai_course_assistant');
        }
        return $function;
    }

    /**
     * Localized label for a provenance layer.
     *
     * @param string $layer
     * @return string
     */
    public static function layer_label(string $layer): string {
        $map = [
            'baseline'         => 'modelregistry:layer_baseline',
            'legacy_overrides' => 'modelregistry:layer_legacy_overrides',
            'table'            => 'modelregistry:layer_table',
            'none'             => 'modelregistry:layer_none',
        ];
        return isset($map[$layer])
            ? get_string($map[$layer], 'local_ai_course_assistant')
            : $layer;
    }

    /**
     * Localized label for a row's source.
     *
     * @param string|null $source
     * @return string
     */
    public static function source_label(?string $source): string {
        $map = [
            'manual'   => 'modelregistry:source_manual',
            'upstream' => 'modelregistry:source_upstream',
            'drift'    => 'modelregistry:source_drift',
            'bundle'   => 'modelregistry:source_bundle',
        ];
        $source = strtolower(trim((string) $source));
        return isset($map[$source]) ? get_string($map[$source], 'local_ai_course_assistant') : '';
    }

    /**
     * Localized label for a registry-row status.
     *
     * @param string $status
     * @return string
     */
    public static function status_label(string $status): string {
        $status = strtolower(trim($status));
        $key = 'modelregistry:status_' . $status;
        if (in_array($status, self::STATUSES, true)
                && get_string_manager()->string_exists($key, 'local_ai_course_assistant')) {
            return get_string($key, 'local_ai_course_assistant');
        }
        return $status;
    }

    /**
     * Localized label for a benchmark run status.
     *
     * @param string $status
     * @return string
     */
    public static function run_status_label(string $status): string {
        $status = strtolower(trim($status));
        $key = 'bench:status_' . $status;
        if (get_string_manager()->string_exists($key, 'local_ai_course_assistant')) {
            return get_string($key, 'local_ai_course_assistant');
        }
        return $status;
    }

    /**
     * Localized label for a source parse format.
     *
     * @param string $format
     * @return string
     */
    public static function format_label(string $format): string {
        $key = 'modelregistry:format_' . strtolower(trim($format));
        if (get_string_manager()->string_exists($key, 'local_ai_course_assistant')) {
            return get_string($key, 'local_ai_course_assistant');
        }
        return $format;
    }

    /**
     * Localized label for a drift finding class.
     *
     * @param string $type
     * @return string
     */
    public static function finding_label(string $type): string {
        $key = 'modelregistry:finding_' . strtolower(trim($type));
        if (get_string_manager()->string_exists($key, 'local_ai_course_assistant')) {
            return get_string($key, 'local_ai_course_assistant');
        }
        return $type;
    }

    /**
     * A rate, or an em dash when it is unknown.
     *
     * Unknown and zero are rendered differently on purpose: an empty price
     * means nobody has said what this costs, and a zero price is a claim that
     * it is free.
     *
     * @param mixed $value
     * @return string
     */
    public static function rate($value): string {
        if ($value === null || $value === '') {
            return '—';
        }
        return '$' . self::plainnumber((float) $value, 6);
    }

    /**
     * A cents-per-call figure for a benchmark card.
     *
     * @param mixed $value
     * @return string
     */
    public static function cents($value): string {
        if ($value === null || $value === '') {
            return '';
        }
        return get_string(
            'modelregistry:cents',
            'local_ai_course_assistant',
            self::plainnumber((float) $value, 4)
        );
    }

    /**
     * A number with trailing zeros trimmed, so 0.30 reads 0.3 and 2.5 reads 2.5.
     *
     * @param float $value
     * @param int $decimals Maximum decimals to keep.
     * @return string
     */
    public static function plainnumber(float $value, int $decimals = 4): string {
        $formatted = number_format($value, $decimals, '.', '');
        if (strpos($formatted, '.') !== false) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }
        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    /**
     * A signed delta, so "no change" and "cheaper" are visibly different.
     *
     * @param float $value
     * @param int $decimals
     * @return string
     */
    public static function signed(float $value, int $decimals = 4): string {
        $text = self::plainnumber(abs($value), $decimals);
        if (abs($value) < 1.0e-9) {
            return $text;
        }
        return ($value > 0 ? '+' : '-') . $text;
    }

    /**
     * A form-field value for a rate: full precision, no currency, no dash.
     *
     * @param mixed $value
     * @return string
     */
    public static function numberfield($value): string {
        if ($value === null || $value === '') {
            return '';
        }
        return self::plainnumber((float) $value, 6);
    }

    /**
     * A timestamp in the site's short date format, or an empty string.
     *
     * @param int $time
     * @return string
     */
    public static function date(int $time): string {
        if ($time <= 0) {
            return '';
        }
        return userdate($time, get_string('strftimedatetimeshort', 'langconfig'));
    }

    /**
     * Full names for a set of user ids.
     *
     * @param int[] $ids
     * @return array<int, string>
     */
    private static function user_names(array $ids): array {
        global $DB;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'uid');
        $out = [];
        // EVERY name field, not just firstname/lastname: fullname() emits a
        // debugging notice for each field it was not given, which turns an
        // ordinary page load into a screenful of warnings on a developer site
        // and fails any test that touches this method.
        $fields = 'id,' . implode(',', \core_user\fields::get_name_fields());
        // Leads with the unique id, so the array key cannot collide.
        $rs = $DB->get_recordset_select('user', "id {$insql}", $params, '', $fields);
        foreach ($rs as $row) {
            $out[(int) $row->id] = fullname($row);
        }
        $rs->close();

        return $out;
    }

    /**
     * Uniform action result.
     *
     * @param string $level
     * @param string $message
     * @return array
     */
    private static function result(string $level, string $message): array {
        return ['level' => $level, 'message' => $message];
    }
}
