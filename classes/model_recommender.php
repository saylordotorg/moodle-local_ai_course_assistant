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
 * Model recommendation engine (v7.4.0).
 *
 * Turns persisted benchmark rows ({@see model_bench}) plus live prices
 * ({@see model_registry}) into an admin-readable answer to one question per
 * SOLA function: "should we switch model X to model Y, and what would that
 * cost or save per month?"
 *
 * WHAT THIS DOES DIFFERENTLY FROM {@see llm_optimizer}
 *
 * llm_optimizer ranks only provider+model pairs the site has ALREADY run (it
 * requires >= 30 live rated rows in the trailing 30 days), so it can never
 * name a model you have not deployed — which is the only recommendation worth
 * having. Worse, when ratings are thin it substitutes a neutral 0.5 quality
 * score and keeps ranking, so a model with no evidence behind it is presented
 * beside one with thousands of ratings and nothing in the output says which is
 * which. This class refuses instead. Every comparison that cannot be defended
 * comes back with a reason string naming the specific failure, and a refusal
 * carries no number at all: no imputed score, no imputed cost, no delta.
 *
 * THE DECISION RULE, in the order it is applied.
 *
 * A run (the current model's or a candidate's) is COMPARABLE only if all hold:
 *   1. status is 'complete'          — a queued/running/failed row is not a result;
 *   2. quality_score and cost_cents_per_call are both non-null;
 *   3. quality_n >= rec_min_quality_n (default 20);
 *   4. plugin_release === the running plugin release.
 * Otherwise the run is reported as not comparable with the reason, and it is
 * never scored.
 *
 * If the current model has no run at all, the function is 'unmeasured' — the
 * honest day-one state for most functions — and no recommendation is emitted.
 * If it has a run that is not comparable, that is said too; a candidate cannot
 * be compared against a baseline that does not hold.
 *
 * Given a comparable current run and a comparable candidate, exactly two
 * verdicts justify a switch:
 *   - COST SAVING:   quality_score >= current - epsilon (default 0.02)
 *                AND cost < current_cost * (1 - savings_floor) (default 0.15).
 *                The cost test is STRICT: a candidate landing exactly on the
 *                floor does not qualify, because "15% cheaper" should mean
 *                more than 15%, not "15% after rounding".
 *   - QUALITY GAIN: quality_score >= current + margin (default 0.05)
 *                AND cost <= current cost.
 * Anything else is 'no_material_gain'.
 *
 * The projected monthly delta is (candidate cents - current cents) x observed
 * calls per month, where the call volume comes from the msgs table through
 * {@see analytics::spend_rows_predicate()} and {@see spend_guard::capability_sql()}
 * — never from a hand-rolled predicate, and never from an assumed MAU figure.
 * With no observed volume the delta is reported as unavailable rather than as
 * zero dollars, because those two mean opposite things to an admin.
 *
 * Nothing here writes to the filesystem, and nothing here needs a code deploy
 * to change its answer: prices come from the registry table, benchmarks from
 * the bench table, and all four thresholds are admin settings.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_recommender {

    /** @var string|null Request-scoped cache of the running plugin release. */
    private static ?string $release = null;

    /** @var float Float-comparison tolerance, so an exact threshold hit is not lost to binary rounding. */
    public const TOL = 1.0e-9;

    /** @var int Trailing window, in days, for the observed call-volume query. */
    public const VOLUME_WINDOW_DAYS = 30;

    /** @var float Default quality tolerance: how much quality a cost saving may buy back. */
    public const DEFAULT_QUALITY_EPSILON = 0.02;

    /** @var float Default minimum fractional cost cut before a switch is worth an admin's attention. */
    public const DEFAULT_SAVINGS_FLOOR = 0.15;

    /** @var float Default quality margin that justifies a switch on quality alone. */
    public const DEFAULT_QUALITY_MARGIN = 0.05;

    /** @var int Default floor on scored items behind a quality figure. */
    public const DEFAULT_MIN_QUALITY_N = 20;

    /** @var string Admin setting name: quality epsilon. */
    public const SETTING_EPSILON = 'rec_quality_epsilon';

    /** @var string Admin setting name: savings floor. */
    public const SETTING_SAVINGS_FLOOR = 'rec_savings_floor';

    /** @var string Admin setting name: quality margin. */
    public const SETTING_MARGIN = 'rec_quality_margin';

    /** @var string Admin setting name: minimum quality_n. */
    public const SETTING_MIN_N = 'rec_min_quality_n';

    /** @var string Verdict: cheaper at no material quality loss. */
    public const VERDICT_COST_SAVING = 'cost_saving';

    /** @var string Verdict: materially better at no extra cost. */
    public const VERDICT_QUALITY_GAIN = 'quality_gain';

    /** @var string Verdict: comparable, but not enough better on either axis. */
    public const VERDICT_NO_GAIN = 'no_material_gain';

    /** @var string Verdict: refused, see the reason. */
    public const VERDICT_NOT_COMPARABLE = 'not_comparable';

    /** @var string Refusal: the run never finished, so it is not a result. */
    public const NC_INCOMPLETE_RUN = 'run_not_complete';

    /** @var string Refusal: quality or cost is missing from the row. */
    public const NC_MISSING_METRIC = 'missing_metric';

    /** @var string Refusal: too few scored items behind the quality figure. */
    public const NC_LOW_QUALITY_N = 'low_quality_n';

    /** @var string Refusal: measured on a different plugin release. */
    public const NC_RELEASE_MISMATCH = 'release_mismatch';

    /** @var string Refusal: measured with a different harness/fixture set or metric. */
    public const NC_FIXTURE_MISMATCH = 'fixture_mismatch';

    /** @var string No recommendation: this function has no benchmark rows whatsoever. */
    public const NR_NO_BENCHMARKS = 'no_benchmarks';

    /** @var string No recommendation: the configured model has never been benchmarked. */
    public const NR_CURRENT_UNMEASURED = 'current_unmeasured';

    /** @var string No recommendation: the configured model's only run cannot be compared. */
    public const NR_CURRENT_NOT_COMPARABLE = 'current_not_comparable';

    /** @var string No recommendation: alternatives exist but none of their runs are comparable. */
    public const NR_NO_COMPARABLE_CANDIDATES = 'no_comparable_candidates';

    /** @var string No recommendation: comparable alternatives exist and none clears a threshold. */
    public const NR_NO_MATERIAL_GAIN = 'no_material_gain';

    /** @var string No recommendation: nothing is configured for this function. */
    public const NR_NOT_CONFIGURED = 'not_configured';

    /**
     * The SOLA functions a model can be recommended for.
     *
     * `providerkeys`/`modelkeys` are tried in order, so a function with a
     * dedicated tier reports that tier and a function without one reports the
     * site model it actually inherits — with `inherited` saying which happened.
     * Silently reporting the site model as though it were a deliberate choice
     * for, say, the safety tier would misrepresent the configuration.
     *
     * `capability` is the {@see spend_guard::capability_sql()} bucket used for
     * the volume query. Several functions share the 'chat' bucket because the
     * msgs table has no interaction_type of their own; {@see volume_for()}
     * reports that overlap in `shared_with` rather than pretending the call
     * counts are independent.
     *
     * @var array<string, array{label: string, providerkeys: string[], modelkeys: string[], capability: string}>
     */
    public const FUNCTIONS = [
        'chat' => [
            'label' => 'Chat tutor',
            'providerkeys' => ['provider'],
            'modelkeys' => ['model'],
            'capability' => 'chat',
        ],
        'quiz' => [
            'label' => 'Quiz generation',
            'providerkeys' => ['quiz_provider', 'provider'],
            'modelkeys' => ['quiz_model', 'model'],
            'capability' => 'chat',
        ],
        'classifier' => [
            'label' => 'Mastery classifier',
            'providerkeys' => ['mastery_classifier_provider', 'provider'],
            'modelkeys' => ['mastery_classifier_model', 'model'],
            'capability' => 'chat',
        ],
        'rag' => [
            'label' => 'RAG embeddings',
            'providerkeys' => ['embed_provider'],
            'modelkeys' => ['embed_model'],
            'capability' => 'rag',
        ],
        'analytics' => [
            'label' => 'Analytics and digests',
            'providerkeys' => ['provider'],
            'modelkeys' => ['model'],
            'capability' => 'analytics',
        ],
        'safety' => [
            'label' => 'Safety and integrity reference',
            'providerkeys' => ['safety_provider', 'provider'],
            'modelkeys' => ['safety_model', 'model'],
            'capability' => 'chat',
        ],
        'soapbox' => [
            'label' => 'Soapbox speech scoring',
            'providerkeys' => ['soapbox_vision_provider', 'provider'],
            'modelkeys' => ['soapbox_vision_model', 'model'],
            'capability' => 'chat',
        ],
    ];

    /**
     * Full report: one card's worth of data per SOLA function, plus the
     * unmeasured list.
     *
     * @param array $opts Optional overrides, see {@see for_function()}.
     * @return array {
     *   'release' => string, 'tunables' => array, 'generated' => int,
     *   'functions' => array<string, array>, 'unmeasured' => array
     * }
     */
    public static function report(array $opts = []): array {
        $out = [
            'release' => self::resolve_release($opts),
            'tunables' => self::tunables($opts),
            'generated' => time(),
            'functions' => [],
            'unmeasured' => [],
        ];
        foreach (array_keys(self::FUNCTIONS) as $function) {
            $out['functions'][$function] = self::for_function($function, $opts);
        }
        $out['unmeasured'] = self::unmeasured_from_report($out['functions']);
        return $out;
    }

    /**
     * Evaluate one SOLA function.
     *
     * @param string $function Key of {@see FUNCTIONS}.
     * @param array $opts Optional overrides:
     *        'bench' => array of bench rows (skips the DB read; used by tests
     *                   and by callers that already loaded the rows),
     *        'release' => string plugin release to compare against,
     *        'epsilon'|'savingsfloor'|'margin'|'minqualityn' => tunable overrides,
     *        'callspermonth' => float to override the observed volume,
     *        'novolume' => true to skip the volume query entirely.
     * @return array Card data; see the class docblock.
     */
    public static function for_function(string $function, array $opts = []): array {
        if (!isset(self::FUNCTIONS[$function])) {
            throw new \coding_exception('Unknown SOLA function: ' . $function);
        }
        $spec = self::FUNCTIONS[$function];
        $tunables = self::tunables($opts);
        $release = self::resolve_release($opts);
        $configured = self::configured_model($function);

        $card = [
            'function' => $function,
            'label' => $spec['label'],
            'capability' => $spec['capability'],
            'release' => $release,
            'tunables' => $tunables,
            'current' => $configured,
            'volume' => null,
            'candidates' => [],
            'recommendation' => null,
            'no_recommendation' => null,
            'rationale' => '',
        ];

        if ($configured['model'] === '') {
            $card['no_recommendation'] = ['reason' => self::NR_NOT_CONFIGURED, 'detail' => []];
            $card['rationale'] = self::rationale($card);
            return $card;
        }

        $card['volume'] = self::volume_for($function, $configured['model'], $opts);
        $callspermonth = $card['volume']['calls_per_month'];

        $rows = array_key_exists('bench', $opts)
            ? self::normalize_rows((array) $opts['bench'])
            : self::bench_rows($function, $release);
        $best = self::best_run_per_model($rows, $release);

        // Split into the current model's run and everything else.
        $currentkey = self::model_key($configured['model']);
        $currentrun = null;
        $candidateruns = [];
        foreach ($best as $key => $run) {
            if (self::model_key((string) $run['model_name']) === $currentkey) {
                $currentrun = $run;
            } else {
                $candidateruns[$key] = $run;
            }
        }

        $decision = self::evaluate($currentrun, array_values($candidateruns), $tunables, $release, $callspermonth);

        $card['current'] = array_merge($card['current'], $decision['current']);
        $card['candidates'] = $decision['candidates'];
        $card['recommendation'] = $decision['recommendation'];
        $card['no_recommendation'] = $decision['no_recommendation'];
        $card['rationale'] = self::rationale($card);
        return $card;
    }

    /**
     * The decision core: pure, no DB, no config.
     *
     * Kept side-effect free on purpose. The threshold behaviour is the part of
     * this class most likely to be argued about, so it has to be testable at
     * exact boundary values without seeding a database.
     *
     * @param array|null $currentrun Normalized bench row for the configured model, or null if never benchmarked.
     * @param array $candidateruns Normalized bench rows for other models.
     * @param array $tunables From {@see tunables()}.
     * @param string $release Running plugin release.
     * @param float $callspermonth Observed monthly call volume, 0.0 when unobserved.
     * @return array ['current' => array, 'candidates' => array, 'recommendation' => ?array, 'no_recommendation' => ?array]
     */
    public static function evaluate(
        ?array $currentrun,
        array $candidateruns,
        array $tunables,
        string $release,
        float $callspermonth = 0.0
    ): array {
        // Normalized in-place so the public entry point tolerates raw DB rows,
        // stdClass, and partial arrays from a caller that only has some fields.
        $currentrun = $currentrun !== null ? (self::normalize_rows([$currentrun])[0] ?? null) : null;
        $candidateruns = self::normalize_rows($candidateruns);

        $currentstate = self::describe_current($currentrun, $tunables, $release);

        // Rate every candidate for comparability first. This happens even when
        // the current model is unmeasured, so the admin still sees what has
        // been measured and why it cannot be acted on yet.
        $candidates = [];
        foreach ($candidateruns as $run) {
            $candidates[] = self::describe_candidate($run, $currentstate, $tunables, $release, $callspermonth);
        }
        $candidates = self::rank($candidates);

        $comparablecount = 0;
        foreach ($candidates as $c) {
            if ($c['comparable']) {
                $comparablecount++;
            }
        }

        $recommendation = null;
        $norecommendation = null;

        if (!$currentstate['measured']) {
            $norecommendation = [
                'reason' => $candidates ? self::NR_CURRENT_UNMEASURED : self::NR_NO_BENCHMARKS,
                'detail' => ['candidates' => count($candidates), 'comparable' => $comparablecount],
            ];
        } else if (!$currentstate['comparable']) {
            $norecommendation = [
                'reason' => self::NR_CURRENT_NOT_COMPARABLE,
                'detail' => [
                    'not_comparable_reason' => $currentstate['not_comparable_reason'],
                    'not_comparable_detail' => $currentstate['not_comparable_detail'],
                    'candidates' => count($candidates),
                ],
            ];
        } else if (!$comparablecount) {
            $norecommendation = [
                'reason' => $candidates ? self::NR_NO_COMPARABLE_CANDIDATES : self::NR_NO_BENCHMARKS,
                'detail' => [
                    'candidates' => count($candidates),
                    'refusals' => self::refusal_counts($candidates),
                ],
            ];
        } else {
            foreach ($candidates as $c) {
                if (!empty($c['recommendable'])) {
                    $recommendation = $c;
                    break;
                }
            }
            if ($recommendation === null) {
                $norecommendation = [
                    'reason' => self::NR_NO_MATERIAL_GAIN,
                    'detail' => ['comparable' => $comparablecount],
                ];
            }
        }

        return [
            'current' => $currentstate,
            'candidates' => $candidates,
            'recommendation' => $recommendation,
            'no_recommendation' => $norecommendation,
        ];
    }

    /**
     * Configured models with no usable benchmark, as a flat list for the
     * "unmeasured" panel. No score is invented for these.
     *
     * @param array $opts See {@see for_function()}.
     * @return array List of ['function','label','provider','model','inherited','reason','detail'].
     */
    public static function unmeasured(array $opts = []): array {
        $functions = [];
        foreach (array_keys(self::FUNCTIONS) as $function) {
            $functions[$function] = self::for_function($function, $opts);
        }
        return self::unmeasured_from_report($functions);
    }

    /**
     * Reduce an already-built per-function report to the unmeasured list.
     *
     * @param array $functions Map of function => card.
     * @return array
     */
    private static function unmeasured_from_report(array $functions): array {
        $out = [];
        foreach ($functions as $function => $card) {
            $reason = $card['no_recommendation']['reason'] ?? null;
            $unmeasured = in_array($reason, [
                self::NR_NOT_CONFIGURED,
                self::NR_NO_BENCHMARKS,
                self::NR_CURRENT_UNMEASURED,
                self::NR_CURRENT_NOT_COMPARABLE,
            ], true);
            if (!$unmeasured) {
                continue;
            }
            $out[] = [
                'function' => $function,
                'label' => $card['label'],
                'provider' => $card['current']['provider'],
                'model' => $card['current']['model'],
                'inherited' => $card['current']['inherited'],
                'reason' => $reason,
                'detail' => $card['no_recommendation']['detail'] ?? [],
                'rationale' => $card['rationale'],
            ];
        }
        return $out;
    }

    /**
     * Resolve the four thresholds, admin settings first, then defaults.
     *
     * Deliberately does NOT use `?:` — an admin who sets epsilon to 0 means
     * "no quality may be traded away at all", and `?:` would silently restore
     * 0.02 and trade some anyway.
     *
     * @param array $opts Optional per-call overrides.
     * @return array{epsilon: float, savingsfloor: float, margin: float, minqualityn: int}
     */
    public static function tunables(array $opts = []): array {
        $epsilon = self::number_setting(self::SETTING_EPSILON, self::DEFAULT_QUALITY_EPSILON, 0.0, 1.0);
        $floor = self::number_setting(self::SETTING_SAVINGS_FLOOR, self::DEFAULT_SAVINGS_FLOOR, 0.0, 0.99);
        $margin = self::number_setting(self::SETTING_MARGIN, self::DEFAULT_QUALITY_MARGIN, 0.0, 1.0);
        $minn = (int) round(self::number_setting(self::SETTING_MIN_N, self::DEFAULT_MIN_QUALITY_N, 1.0, 100000.0));

        if (array_key_exists('epsilon', $opts)) {
            $epsilon = max(0.0, min(1.0, (float) $opts['epsilon']));
        }
        if (array_key_exists('savingsfloor', $opts)) {
            $floor = max(0.0, min(0.99, (float) $opts['savingsfloor']));
        }
        if (array_key_exists('margin', $opts)) {
            $margin = max(0.0, min(1.0, (float) $opts['margin']));
        }
        if (array_key_exists('minqualityn', $opts)) {
            $minn = max(1, (int) $opts['minqualityn']);
        }

        return [
            'epsilon' => $epsilon,
            'savingsfloor' => $floor,
            'margin' => $margin,
            'minqualityn' => $minn,
        ];
    }

    /**
     * Read a numeric plugin setting, clamped, honouring a configured zero.
     *
     * @param string $name
     * @param float $default
     * @param float $min
     * @param float $max
     * @return float
     */
    private static function number_setting(string $name, float $default, float $min, float $max): float {
        $raw = get_config('local_ai_course_assistant', $name);
        if ($raw === false || $raw === null || trim((string) $raw) === '' || !is_numeric($raw)) {
            return $default;
        }
        return max($min, min($max, (float) $raw));
    }

    /**
     * Which provider+model a SOLA function currently runs, and where that came from.
     *
     * @param string $function
     * @return array{provider: string, model: string, resolved_from: ?string, inherited: bool,
     *               configured: bool, rate: ?array, rate_provenance: ?array}
     */
    public static function configured_model(string $function): array {
        $spec = self::FUNCTIONS[$function] ?? null;
        if ($spec === null) {
            throw new \coding_exception('Unknown SOLA function: ' . $function);
        }

        $model = '';
        $resolvedfrom = null;
        foreach ($spec['modelkeys'] as $key) {
            $value = trim((string) get_config('local_ai_course_assistant', $key));
            if ($value !== '') {
                $model = $value;
                $resolvedfrom = $key;
                break;
            }
        }
        $provider = '';
        foreach ($spec['providerkeys'] as $key) {
            $value = trim((string) get_config('local_ai_course_assistant', $key));
            if ($value !== '') {
                $provider = $value;
                break;
            }
        }

        $rate = $model !== '' ? model_registry::rate_for($model) : null;

        return [
            'provider' => $provider,
            'model' => $model,
            'resolved_from' => $resolvedfrom,
            'inherited' => $resolvedfrom !== null && $resolvedfrom !== $spec['modelkeys'][0],
            'configured' => $model !== '',
            'rate' => $rate,
            'rate_provenance' => $model !== '' ? model_registry::provenance_for($model) : null,
        ];
    }

    /**
     * Observed billable call volume for a function's configured model.
     *
     * Uses {@see analytics::spend_rows_predicate()} and
     * {@see spend_guard::capability_sql()} verbatim — the only correct way to
     * select billable rows. Hand-rolling the condition is how the RAG-$0.00
     * and premium-router double-count traps were dug in the first place.
     *
     * @param string $function
     * @param string $model
     * @param array $opts 'callspermonth' to override, 'novolume' to skip the query.
     * @return array{calls: int, days: int, calls_per_month: float, observed: bool, basis: string, shared_with: string[]}
     */
    public static function volume_for(string $function, string $model, array $opts = []): array {
        global $DB;

        $spec = self::FUNCTIONS[$function];
        $out = [
            'calls' => 0,
            'days' => 0,
            'calls_per_month' => 0.0,
            'observed' => false,
            'basis' => "billable msgs rows, capability '{$spec['capability']}', model_name = '{$model}', "
                . 'trailing ' . self::VOLUME_WINDOW_DAYS . ' days',
            'shared_with' => self::functions_sharing($function, $model),
        ];

        if (array_key_exists('callspermonth', $opts)) {
            $out['calls_per_month'] = max(0.0, (float) $opts['callspermonth']);
            $out['observed'] = $out['calls_per_month'] > 0.0;
            $out['basis'] = 'caller-supplied monthly call volume';
            return $out;
        }
        if (!empty($opts['novolume']) || $model === '') {
            return $out;
        }

        $since = time() - self::VOLUME_WINDOW_DAYS * 86400;
        $row = $DB->get_record_sql(
            "SELECT COUNT(m.id) AS calls, MIN(m.timecreated) AS firstseen, MAX(m.timecreated) AS lastseen
               FROM {local_ai_course_assistant_msgs} m
              WHERE " . analytics::spend_rows_predicate('m') . "
                AND m.model_name = :model
                AND m.timecreated >= :since
                AND " . spend_guard::capability_sql($spec['capability']),
            ['model' => $model, 'since' => $since]
        );

        $calls = (int) ($row->calls ?? 0);
        if ($calls <= 0) {
            return $out;
        }
        $firstseen = (int) ($row->firstseen ?: $since);
        $days = (int) floor((time() - $firstseen) / 86400);
        $days = max(1, min(self::VOLUME_WINDOW_DAYS, $days));

        $out['calls'] = $calls;
        $out['days'] = $days;
        $out['calls_per_month'] = ($calls / $days) * 30.0;
        $out['observed'] = true;
        return $out;
    }

    /**
     * Other functions whose configured model and capability match this one.
     *
     * The msgs table has no interaction_type for the classifier or for Soapbox,
     * so when two functions run the same model in the same capability bucket
     * their call counts are ONE population counted twice. Saying so is the
     * difference between a projection an admin can add up and one they cannot.
     *
     * @param string $function
     * @param string $model
     * @return string[]
     */
    private static function functions_sharing(string $function, string $model): array {
        if ($model === '') {
            return [];
        }
        $capability = self::FUNCTIONS[$function]['capability'];
        $key = self::model_key($model);
        $shared = [];
        foreach (self::FUNCTIONS as $other => $spec) {
            if ($other === $function || $spec['capability'] !== $capability) {
                continue;
            }
            $othermodel = self::configured_model($other)['model'];
            if ($othermodel !== '' && self::model_key($othermodel) === $key) {
                $shared[] = $other;
            }
        }
        return $shared;
    }

    /**
     * Load benchmark rows for a function.
     *
     * Prefers {@see model_bench::latest_by_function()} when that class is
     * present; falls back to reading the bench table directly so this class
     * stays usable (and unit-testable) on its own.
     *
     * @param string $function SOLA function key (chat, quiz, rag, ...).
     * @param string $release Plugin release to restrict comparable runs to; '' means any.
     * @return array Normalized rows.
     */
    public static function bench_rows(string $function, string $release = ''): array {
        if (!class_exists('\\local_ai_course_assistant\\model_bench')
                || !method_exists('\\local_ai_course_assistant\\model_bench', 'latest_by_function')) {
            return self::normalize_rows(self::bench_rows_fallback($function));
        }

        // Floor of 1, deliberately NOT the admin's rec_min_quality_n.
        // latest_by_function() drops runs below the floor it is given, and a
        // run dropped before it gets here cannot be reported as refused — the
        // model would just be absent from the card, which is the silent
        // behaviour this class exists to replace. The sample-size decision is
        // made in classify_run(), where it comes with a reason.
        $rows = self::normalize_rows((array) model_bench::latest_by_function($function, 1));
        if ($release === '' || !method_exists('\\local_ai_course_assistant\\model_bench', 'history')) {
            return $rows;
        }

        // latest_by_function() keeps only the newest run per model, so a model
        // re-benchmarked after this release shipped hides its own comparable
        // run behind a fresher off-release one. Ask for that model's history
        // and prefer a complete run on the current release when one exists.
        foreach ($rows as $i => $row) {
            if ((string) ($row['plugin_release'] ?? '') === $release) {
                continue;
            }
            $key = trim((string) ($row['registry_key'] ?? '')) !== ''
                ? (string) $row['registry_key']
                : (string) $row['model_name'];
            foreach (self::normalize_rows((array) model_bench::history($key, $function)) as $hist) {
                if ((string) ($hist['plugin_release'] ?? '') === $release
                        && strtolower((string) $hist['status']) === 'complete') {
                    $rows[$i] = $hist;
                    break;
                }
            }
        }
        return $rows;
    }

    /**
     * Direct read of the bench table: every complete run for a function in the
     * trailing window, newest first.
     *
     * Deliberately returns ALL complete runs rather than one per model:
     * {@see best_run_per_model()} needs the older rows so that a model whose
     * newest run is from another release can be reported as a release mismatch
     * instead of vanishing from the comparison.
     *
     * get_recordset_sql, not get_records_sql — the same model benchmarked
     * twice would collapse under any non-unique first column.
     *
     * @param string $function
     * @return array
     */
    private static function bench_rows_fallback(string $function): array {
        global $DB;

        $rs = $DB->get_recordset_sql(
            'SELECT b.id, b.runid, b.harness, b.sola_function, b.registry_key, b.provider, b.model_name,
                    b.fixture_set, b.fixture_n, b.quality_metric, b.quality_raw, b.quality_max,
                    b.quality_score, b.quality_n, b.cost_cents_per_call, b.p50_ttft_ms, b.p95_ttft_ms,
                    b.calls, b.errors, b.plugin_release, b.status, b.timecreated
               FROM {' . model_registry::TABLE_BENCH . '} b
              WHERE b.sola_function = :fn
                AND b.model_name IS NOT NULL
           ORDER BY b.timecreated DESC, b.id DESC',
            ['fn' => $function]
        );
        $rows = [];
        foreach ($rs as $row) {
            $rows[] = (array) $row;
        }
        $rs->close();
        return $rows;
    }

    /**
     * Coerce arbitrary row shapes (stdClass or array, from either source) into
     * the flat arrays the rest of this class expects.
     *
     * @param array $rows
     * @return array
     */
    private static function normalize_rows(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            if (trim((string) ($r['model_name'] ?? '')) === '') {
                continue;
            }
            $out[] = [
                'id' => isset($r['id']) ? (int) $r['id'] : null,
                'runid' => isset($r['runid']) ? (string) $r['runid'] : null,
                'harness' => isset($r['harness']) ? (string) $r['harness'] : null,
                'sola_function' => isset($r['sola_function']) ? (string) $r['sola_function'] : null,
                'registry_key' => isset($r['registry_key']) ? (string) $r['registry_key'] : null,
                'provider' => isset($r['provider']) ? (string) $r['provider'] : null,
                'model_name' => (string) $r['model_name'],
                'fixture_set' => isset($r['fixture_set']) ? (string) $r['fixture_set'] : null,
                'fixture_n' => isset($r['fixture_n']) ? (int) $r['fixture_n'] : null,
                'quality_metric' => isset($r['quality_metric']) ? (string) $r['quality_metric'] : null,
                'quality_raw' => self::nullable_float($r, 'quality_raw'),
                'quality_max' => self::nullable_float($r, 'quality_max'),
                'quality_score' => self::nullable_float($r, 'quality_score'),
                'quality_n' => isset($r['quality_n']) && $r['quality_n'] !== null ? (int) $r['quality_n'] : null,
                'cost_cents_per_call' => self::nullable_float($r, 'cost_cents_per_call'),
                'p50_ttft_ms' => isset($r['p50_ttft_ms']) && $r['p50_ttft_ms'] !== null ? (int) $r['p50_ttft_ms'] : null,
                'p95_ttft_ms' => isset($r['p95_ttft_ms']) && $r['p95_ttft_ms'] !== null ? (int) $r['p95_ttft_ms'] : null,
                'calls' => isset($r['calls']) && $r['calls'] !== null ? (int) $r['calls'] : null,
                'errors' => isset($r['errors']) && $r['errors'] !== null ? (int) $r['errors'] : null,
                'plugin_release' => isset($r['plugin_release']) ? (string) $r['plugin_release'] : null,
                // model_bench exports this as harness/fixture_set/fixture_n. Two
                // runs from different groups are not each other's baseline: a
                // rubric mean over the 50-prompt tutor set and a recall@3 over a
                // RAG fixture set are both "quality 0.9" and mean nothing to
                // each other.
                'comparable_group' => isset($r['comparable_group']) && trim((string) $r['comparable_group']) !== ''
                    ? (string) $r['comparable_group']
                    : self::group_of($r),
                // A row from a source that does not carry status is treated as a
                // finished result; the bench table's own column defaults to
                // 'complete', so absence means "not tracked", not "unfinished".
                'status' => isset($r['status']) && trim((string) $r['status']) !== '' ? (string) $r['status'] : 'complete',
                'timecreated' => isset($r['timecreated']) ? (int) $r['timecreated'] : 0,
            ];
        }
        return $out;
    }

    /**
     * Fallback comparable-group key for a row that did not come from
     * model_bench::export_row() (the direct table read, or an injected row).
     *
     * @param array $r Raw row.
     * @return string
     */
    private static function group_of(array $r): string {
        return implode('/', [
            (string) ($r['harness'] ?? ''),
            (string) ($r['fixture_set'] ?? ''),
            (string) ($r['fixture_n'] ?? ''),
        ]);
    }

    /**
     * Nullable float extraction that keeps a real 0.0 distinct from a missing value.
     *
     * @param array $row
     * @param string $key
     * @return float|null
     */
    private static function nullable_float(array $row, string $key): ?float {
        if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
            return null;
        }
        return (float) $row[$key];
    }

    /**
     * One run per provider+model: the newest run on the current release if there
     * is one, else the newest run overall.
     *
     * The fallback is what makes a release mismatch visible. Picking simply the
     * newest run would hide a perfectly comparable run behind a fresher one
     * from a different release; picking only same-release runs would drop the
     * model from the report entirely and give the admin nothing to read.
     *
     * @param array $rows Normalized rows.
     * @param string $release Running plugin release.
     * @return array Keyed by provider|model.
     */
    private static function best_run_per_model(array $rows, string $release): array {
        $onrelease = [];
        $anyrelease = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string) $row['provider'])) . '|' . self::model_key((string) $row['model_name']);
            $matches = $row['plugin_release'] !== null && (string) $row['plugin_release'] === $release;
            if ($matches) {
                if (!isset($onrelease[$key]) || self::is_newer($row, $onrelease[$key])) {
                    $onrelease[$key] = $row;
                }
            }
            if (!isset($anyrelease[$key]) || self::is_newer($row, $anyrelease[$key])) {
                $anyrelease[$key] = $row;
            }
        }
        // Same-release runs win their key; the newest run of any release is the
        // fallback, so a mismatch is reported rather than silently dropped.
        return array_merge($anyrelease, $onrelease);
    }

    /**
     * Is $a a later run than $b?
     *
     * @param array $a
     * @param array $b
     * @return bool
     */
    private static function is_newer(array $a, array $b): bool {
        if ((int) $a['timecreated'] !== (int) $b['timecreated']) {
            return (int) $a['timecreated'] > (int) $b['timecreated'];
        }
        return (int) ($a['id'] ?? 0) > (int) ($b['id'] ?? 0);
    }

    /**
     * Comparability check for a single run.
     *
     * @param array $run Normalized row.
     * @param array $tunables
     * @param string $release
     * @return array{comparable: bool, reason: ?string, detail: ?string}
     */
    public static function classify_run(array $run, array $tunables, string $release): array {
        $status = strtolower((string) ($run['status'] ?? 'complete'));
        if ($status !== 'complete') {
            return [
                'comparable' => false,
                'reason' => self::NC_INCOMPLETE_RUN,
                'detail' => "the run is '{$status}', not a finished result",
            ];
        }
        if ($run['quality_score'] === null || $run['cost_cents_per_call'] === null) {
            $missing = [];
            if ($run['quality_score'] === null) {
                $missing[] = 'quality score';
            }
            if ($run['cost_cents_per_call'] === null) {
                $missing[] = 'cost per call';
            }
            return [
                'comparable' => false,
                'reason' => self::NC_MISSING_METRIC,
                'detail' => 'the run recorded no ' . implode(' and no ', $missing),
            ];
        }
        $n = (int) ($run['quality_n'] ?? 0);
        if ($n < (int) $tunables['minqualityn']) {
            return [
                'comparable' => false,
                'reason' => self::NC_LOW_QUALITY_N,
                'detail' => "scored on only {$n} item" . ($n === 1 ? '' : 's')
                    . ", below the floor of {$tunables['minqualityn']}",
            ];
        }
        $runrelease = (string) ($run['plugin_release'] ?? '');
        if ($runrelease !== $release) {
            return [
                'comparable' => false,
                'reason' => self::NC_RELEASE_MISMATCH,
                'detail' => 'measured on release '
                    . ($runrelease === '' ? '(none recorded)' : $runrelease)
                    . ", not {$release}",
            ];
        }
        return ['comparable' => true, 'reason' => null, 'detail' => null];
    }

    /**
     * Describe the configured model's own benchmark state.
     *
     * @param array|null $run
     * @param array $tunables
     * @param string $release
     * @return array
     */
    private static function describe_current(?array $run, array $tunables, string $release): array {
        $state = [
            'measured' => false,
            'comparable' => false,
            'not_comparable_reason' => null,
            'not_comparable_detail' => null,
            'quality_score' => null,
            'quality_n' => null,
            'cost_cents_per_call' => null,
            'p50_ttft_ms' => null,
            'plugin_release' => null,
            'runid' => null,
            'harness' => null,
            'quality_metric' => null,
            'comparable_group' => null,
            'run_timecreated' => null,
        ];
        if ($run === null) {
            return $state;
        }
        $verdict = self::classify_run($run, $tunables, $release);
        return array_merge($state, [
            'measured' => true,
            'comparable' => $verdict['comparable'],
            'not_comparable_reason' => $verdict['reason'],
            'not_comparable_detail' => $verdict['detail'],
            'quality_score' => $run['quality_score'],
            'quality_n' => $run['quality_n'],
            'cost_cents_per_call' => $run['cost_cents_per_call'],
            'p50_ttft_ms' => $run['p50_ttft_ms'],
            'plugin_release' => $run['plugin_release'],
            'runid' => $run['runid'],
            'harness' => $run['harness'],
            'quality_metric' => $run['quality_metric'],
            'comparable_group' => $run['comparable_group'],
            'run_timecreated' => $run['timecreated'],
        ]);
    }

    /**
     * Score one candidate against the current model.
     *
     * @param array $run Normalized candidate row.
     * @param array $currentstate From {@see describe_current()}.
     * @param array $tunables
     * @param string $release
     * @param float $callspermonth
     * @return array Candidate record.
     */
    private static function describe_candidate(
        array $run,
        array $currentstate,
        array $tunables,
        string $release,
        float $callspermonth
    ): array {
        $verdict = self::classify_run($run, $tunables, $release);
        $c = [
            'model' => $run['model_name'],
            'provider' => $run['provider'],
            'registry_key' => $run['registry_key'],
            'runid' => $run['runid'],
            'harness' => $run['harness'],
            'quality_score' => $run['quality_score'],
            'quality_metric' => $run['quality_metric'],
            'comparable_group' => $run['comparable_group'],
            'quality_raw' => $run['quality_raw'],
            'quality_max' => $run['quality_max'],
            'quality_n' => $run['quality_n'],
            'cost_cents_per_call' => $run['cost_cents_per_call'],
            'p50_ttft_ms' => $run['p50_ttft_ms'],
            'plugin_release' => $run['plugin_release'],
            'errors' => $run['errors'],
            'run_timecreated' => $run['timecreated'],
            'comparable' => $verdict['comparable'],
            'not_comparable_reason' => $verdict['reason'],
            'not_comparable_detail' => $verdict['detail'],
            'verdict' => self::VERDICT_NOT_COMPARABLE,
            'quality_delta' => null,
            'cost_delta_cents' => null,
            'cost_change_pct' => null,
            'monthly_delta_usd' => null,
            'ttft_delta_ms' => null,
            'recommendable' => false,
            'why' => '',
        ];

        if (!$verdict['comparable']) {
            $c['why'] = 'Not comparable: ' . $verdict['detail'] . '.';
            return $c;
        }
        $groupmismatch = self::group_mismatch($run, $currentstate);
        if ($groupmismatch !== null && $currentstate['comparable']) {
            $c['comparable'] = false;
            $c['not_comparable_reason'] = self::NC_FIXTURE_MISMATCH;
            $c['not_comparable_detail'] = $groupmismatch;
            $c['why'] = 'Not comparable: ' . $groupmismatch . '.';
            return $c;
        }
        if (!$currentstate['comparable']) {
            // The candidate's own run is fine; there is simply no baseline to
            // subtract it from. No delta is invented.
            $c['verdict'] = self::VERDICT_NOT_COMPARABLE;
            $c['why'] = $currentstate['measured']
                ? 'Measured, but the configured model\'s own run is not comparable, so no delta can be computed.'
                : 'Measured, but the configured model has never been benchmarked, so no delta can be computed.';
            return $c;
        }

        $curq = (float) $currentstate['quality_score'];
        $curcost = (float) $currentstate['cost_cents_per_call'];
        $candq = (float) $run['quality_score'];
        $candcost = (float) $run['cost_cents_per_call'];

        $c['quality_delta'] = $candq - $curq;
        $c['cost_delta_cents'] = $candcost - $curcost;
        $c['cost_change_pct'] = $curcost > 0.0 ? (($candcost - $curcost) / $curcost) * 100.0 : null;
        if ($currentstate['p50_ttft_ms'] !== null && $run['p50_ttft_ms'] !== null) {
            $c['ttft_delta_ms'] = (int) $run['p50_ttft_ms'] - (int) $currentstate['p50_ttft_ms'];
        }
        $c['monthly_delta_usd'] = $callspermonth > 0.0
            ? ($c['cost_delta_cents'] * $callspermonth) / 100.0
            : null;

        // Rule 1: cheaper, at no material quality loss. Cost test is strict.
        $qualitywithintolerance = ($candq + self::TOL) >= ($curq - (float) $tunables['epsilon']);
        $costthreshold = $curcost * (1.0 - (float) $tunables['savingsfloor']);
        $materiallycheaper = $candcost < ($costthreshold - self::TOL);

        // Rule 2: materially better, at no worse cost.
        $materiallybetter = ($candq + self::TOL) >= ($curq + (float) $tunables['margin']);
        $notmoreexpensive = $candcost <= ($curcost + self::TOL);

        if ($qualitywithintolerance && $materiallycheaper) {
            $c['verdict'] = self::VERDICT_COST_SAVING;
            $c['recommendable'] = true;
        } else if ($materiallybetter && $notmoreexpensive) {
            $c['verdict'] = self::VERDICT_QUALITY_GAIN;
            $c['recommendable'] = true;
        } else {
            $c['verdict'] = self::VERDICT_NO_GAIN;
        }
        $c['why'] = self::candidate_sentence($c, $currentstate, $tunables, $qualitywithintolerance, $materiallycheaper);
        return $c;
    }

    /**
     * Why a candidate's run is not measured against the same yardstick, or null.
     *
     * Only fires when BOTH runs declare the thing being compared: a run that
     * records no fixture set is not thereby declared incompatible, it is just
     * uninformative, and refusing on absence would refuse everything a
     * hand-entered result can ever say.
     *
     * @param array $run Candidate run.
     * @param array $currentstate From {@see describe_current()}.
     * @return string|null
     */
    private static function group_mismatch(array $run, array $currentstate): ?string {
        $curmetric = (string) ($currentstate['quality_metric'] ?? '');
        $candmetric = (string) ($run['quality_metric'] ?? '');
        if ($curmetric !== '' && $candmetric !== '' && $curmetric !== $candmetric) {
            return "scored on '{$candmetric}', while the configured model was scored on '{$curmetric}'";
        }
        $curgroup = trim((string) ($currentstate['comparable_group'] ?? ''), '/ ');
        $candgroup = trim((string) ($run['comparable_group'] ?? ''), '/ ');
        if ($curgroup !== '' && $candgroup !== '' && $curgroup !== $candgroup) {
            return 'measured with ' . str_replace('/', ' / ', (string) $run['comparable_group'])
                . ', while the configured model was measured with '
                . str_replace('/', ' / ', (string) $currentstate['comparable_group']);
        }
        return null;
    }

    /**
     * One-sentence explanation of a comparable candidate's verdict.
     *
     * @param array $c
     * @param array $currentstate
     * @param array $tunables
     * @param bool $qualityok
     * @param bool $cheapenough
     * @return string
     */
    private static function candidate_sentence(
        array $c,
        array $currentstate,
        array $tunables,
        bool $qualityok,
        bool $cheapenough
    ): string {
        $q = self::fmt_score((float) $c['quality_score']);
        $curq = self::fmt_score((float) $currentstate['quality_score']);
        $cost = self::fmt_cents((float) $c['cost_cents_per_call']);
        $curcost = self::fmt_cents((float) $currentstate['cost_cents_per_call']);

        if ($c['verdict'] === self::VERDICT_COST_SAVING) {
            return "Quality {$q} vs {$curq} (within the "
                . self::fmt_score((float) $tunables['epsilon']) . ' tolerance) at '
                . "{$cost} vs {$curcost} per call, " . self::fmt_pct_change($c['cost_change_pct']) . '.';
        }
        if ($c['verdict'] === self::VERDICT_QUALITY_GAIN) {
            return "Quality {$q} vs {$curq} (+"
                . self::fmt_score((float) $c['quality_delta']) . ', clearing the '
                . self::fmt_score((float) $tunables['margin']) . " margin) at {$cost} vs {$curcost} per call, "
                . 'no more expensive.';
        }
        if (!$qualityok) {
            return "Quality {$q} vs {$curq} is more than the "
                . self::fmt_score((float) $tunables['epsilon'])
                . " tolerance worse, so the {$cost} vs {$curcost} per-call price is not worth taking.";
        }
        if (!$cheapenough) {
            return "Quality {$q} vs {$curq} holds up, but {$cost} vs {$curcost} per call is "
                . self::fmt_pct_change($c['cost_change_pct']) . ', short of the '
                . self::fmt_percent((float) $tunables['savingsfloor']) . ' savings floor.';
        }
        return "Quality {$q} vs {$curq} at {$cost} vs {$curcost} per call clears no threshold.";
    }

    /**
     * Rank candidates: recommendable first, then by the size of the win.
     *
     * Within the recommendable group the biggest monthly saving leads, tie-broken
     * by quality delta; where no volume is observed there is no dollar figure to
     * sort on, so the per-call cost delta stands in. Non-recommendable comparable
     * runs follow, best quality first; refused runs come last so the reasons stay
     * visible at the bottom of the card instead of being dropped.
     *
     * No weighted composite on purpose: llm_optimizer's cost/quality weights make
     * the ordering depend on two more settings nobody can calibrate, and the
     * ordering here only has to surface the item the thresholds already approved.
     *
     * @param array $candidates
     * @return array
     */
    private static function rank(array $candidates): array {
        usort($candidates, function (array $a, array $b) {
            $tier = function (array $c): int {
                if (!empty($c['recommendable'])) {
                    return 0;
                }
                return $c['comparable'] ? 1 : 2;
            };
            $ta = $tier($a);
            $tb = $tier($b);
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            if ($ta === 0) {
                $sa = $a['monthly_delta_usd'] ?? $a['cost_delta_cents'] ?? 0.0;
                $sb = $b['monthly_delta_usd'] ?? $b['cost_delta_cents'] ?? 0.0;
                if (abs($sa - $sb) > self::TOL) {
                    return $sa <=> $sb;
                }
                return ($b['quality_delta'] ?? 0.0) <=> ($a['quality_delta'] ?? 0.0);
            }
            if ($ta === 1) {
                $qa = $a['quality_score'] ?? 0.0;
                $qb = $b['quality_score'] ?? 0.0;
                if (abs($qa - $qb) > self::TOL) {
                    return $qb <=> $qa;
                }
                return ($a['cost_cents_per_call'] ?? 0.0) <=> ($b['cost_cents_per_call'] ?? 0.0);
            }
            return strcmp((string) $a['model'], (string) $b['model']);
        });
        return $candidates;
    }

    /**
     * Count refusals by reason, for the "why can't you tell me anything" line.
     *
     * @param array $candidates
     * @return array<string, int>
     */
    private static function refusal_counts(array $candidates): array {
        $counts = [];
        foreach ($candidates as $c) {
            if ($c['comparable']) {
                continue;
            }
            $reason = (string) ($c['not_comparable_reason'] ?? 'unknown');
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * Plain-English rationale for one function's card.
     *
     * English literals live here rather than in lang strings because this wave
     * must not touch lang/; the manifest carries the string specs for a later
     * stage to wire, and the structured fields beside this string are enough to
     * rebuild it from lang strings without re-deriving any of the arithmetic.
     *
     * @param array $card
     * @return string
     */
    private static function rationale(array $card): string {
        $label = $card['label'];
        $model = $card['current']['model'];
        $rec = $card['recommendation'];
        $no = $card['no_recommendation'];

        if ($rec !== null) {
            $s = "Switch {$label} from {$model} to {$rec['model']}"
                . (!empty($rec['provider']) ? " ({$rec['provider']})" : '') . '. ' . $rec['why'];
            $volume = $card['volume'] ?? null;
            if ($volume !== null && !empty($volume['observed'])) {
                $calls = number_format($volume['calls_per_month'], 0);
                if ($rec['monthly_delta_usd'] !== null && $rec['monthly_delta_usd'] < 0) {
                    $s .= ' At the observed ' . $calls . ' calls a month that is about '
                        . self::fmt_usd(abs((float) $rec['monthly_delta_usd'])) . ' less per month.';
                } else if ($rec['monthly_delta_usd'] !== null && $rec['monthly_delta_usd'] > 0) {
                    $s .= ' At the observed ' . $calls . ' calls a month that is about '
                        . self::fmt_usd((float) $rec['monthly_delta_usd']) . ' more per month.';
                } else {
                    $s .= ' At the observed ' . $calls . ' calls a month the monthly cost is unchanged.';
                }
                if (!empty($volume['shared_with'])) {
                    $s .= ' That volume is shared with ' . implode(', ', $volume['shared_with'])
                        . ', so do not add these projections together.';
                }
            } else {
                $s .= ' No billable calls for this model were observed in the last '
                    . self::VOLUME_WINDOW_DAYS . ' days, so no monthly figure is projected.';
            }
            return $s;
        }

        $reason = $no['reason'] ?? self::NR_NO_MATERIAL_GAIN;
        switch ($reason) {
            case self::NR_NOT_CONFIGURED:
                return "No model is configured for {$label}, so there is nothing to compare.";

            case self::NR_NO_BENCHMARKS:
                return "{$label} runs {$model}, which has never been benchmarked, and no alternative has been "
                    . 'benchmarked for this function either. Unmeasured, not unrecommended: run a benchmark before '
                    . 'reading anything into the cost figures.';

            case self::NR_CURRENT_UNMEASURED:
                $n = (int) ($no['detail']['candidates'] ?? 0);
                return "{$label} runs {$model}, which has no benchmark, so the "
                    . $n . ' benchmarked alternative' . ($n === 1 ? '' : 's')
                    . ' cannot be compared against it. Benchmark ' . $model
                    . ' first; no score is assumed for it here.';

            case self::NR_CURRENT_NOT_COMPARABLE:
                return "{$label} runs {$model}, whose benchmark is not comparable: "
                    . ($no['detail']['not_comparable_detail'] ?? 'the run does not meet the comparability rules')
                    . '. Re-run the benchmark for the current release before comparing alternatives.';

            case self::NR_NO_COMPARABLE_CANDIDATES:
                $parts = [];
                foreach (($no['detail']['refusals'] ?? []) as $r => $count) {
                    $parts[] = $count . ' ' . self::reason_phrase((string) $r);
                }
                return "No recommendation for {$label}: the "
                    . (int) ($no['detail']['candidates'] ?? 0) . ' benchmarked alternative'
                    . (((int) ($no['detail']['candidates'] ?? 0)) === 1 ? '' : 's')
                    . ' cannot be compared'
                    . ($parts ? ' (' . implode('; ', $parts) . ')' : '') . '.';

            case self::NR_NO_MATERIAL_GAIN:
            default:
                $n = (int) ($no['detail']['comparable'] ?? 0);
                return "Keep {$model} for {$label}: "
                    . ($n === 1 ? 'the one comparable alternative clears' : 'none of the ' . $n
                        . ' comparable alternatives clears')
                    . ' the '
                    . self::fmt_percent((float) $card['tunables']['savingsfloor'])
                    . ' savings floor at acceptable quality, or the '
                    . self::fmt_score((float) $card['tunables']['margin'])
                    . ' quality margin at no extra cost.';
        }
    }

    /**
     * Human phrase for a refusal reason, for the counted list.
     *
     * @param string $reason
     * @return string
     */
    private static function reason_phrase(string $reason): string {
        switch ($reason) {
            case self::NC_LOW_QUALITY_N:
                return 'scored on too few items';
            case self::NC_RELEASE_MISMATCH:
                return 'measured on a different plugin release';
            case self::NC_MISSING_METRIC:
                return 'missing a quality score or a cost';
            case self::NC_FIXTURE_MISMATCH:
                return 'measured on a different fixture set or metric';
            case self::NC_INCOMPLETE_RUN:
                return 'never finished running';
            default:
                return 'refused for an unrecognized reason';
        }
    }

    /**
     * Format a 0..1 score.
     *
     * @param float $v
     * @return string
     */
    private static function fmt_score(float $v): string {
        return number_format($v, 3);
    }

    /**
     * Format cents per call.
     *
     * @param float $v
     * @return string
     */
    private static function fmt_cents(float $v): string {
        return number_format($v, $v > 0 && $v < 0.01 ? 5 : 3) . 'c';
    }

    /**
     * Format a fraction as a whole percentage.
     *
     * @param float $v
     * @return string
     */
    private static function fmt_percent(float $v): string {
        return number_format($v * 100, ($v * 100) == (int) ($v * 100) ? 0 : 1) . '%';
    }

    /**
     * Format a signed percentage change as "an X% cost cut/increase".
     *
     * @param float|null $pct
     * @return string
     */
    private static function fmt_pct_change(?float $pct): string {
        if ($pct === null) {
            return 'a cost change that cannot be expressed as a percentage of a zero baseline';
        }
        if ($pct < 0) {
            return 'a ' . number_format(abs($pct), 1) . '% cost cut';
        }
        if ($pct > 0) {
            return 'a ' . number_format($pct, 1) . '% cost increase';
        }
        return 'the same cost';
    }

    /**
     * Format USD.
     *
     * @param float $v
     * @return string
     */
    private static function fmt_usd(float $v): string {
        return '$' . number_format($v, $v < 10 ? 2 : 0);
    }

    /**
     * The running plugin release, used for the comparability gate.
     *
     * core_plugin_manager is the cached, no-filesystem path; the version.php
     * read is a read-only last resort for the case where the plugin info is
     * not yet available (mid-upgrade).
     *
     * @param array $opts 'release' to override.
     * @return string
     */
    public static function resolve_release(array $opts = []): string {
        if (!empty($opts['release'])) {
            return (string) $opts['release'];
        }
        if (self::$release !== null) {
            return self::$release;
        }
        // version.php first, statically cached: it is the file the release
        // string actually lives in, it is a read (this feature writes nothing
        // to disk), and integrity_checker already reads it the same way.
        // core_plugin_manager would also answer, but it is the heavier path and
        // on PHP 8.4+ its plugininfo accessor emits a deprecation notice that
        // turns any caller's phpunit test risky.
        global $CFG;
        $file = $CFG->dirroot . '/local/ai_course_assistant/version.php';
        if (is_readable($file)) {
            $plugin = new \stdClass();
            include($file);
            if (!empty($plugin->release)) {
                self::$release = (string) $plugin->release;
                return self::$release;
            }
        }
        try {
            $info = \core_plugin_manager::instance()->get_plugin_info('local_ai_course_assistant');
            if ($info !== null && !empty($info->release)) {
                self::$release = (string) $info->release;
                return self::$release;
            }
        } catch (\Throwable $e) {
            unset($e);
        }
        self::$release = '';
        return self::$release;
    }

    /**
     * Case-insensitive model identity key.
     *
     * @param string $model
     * @return string
     */
    private static function model_key(string $model): string {
        return strtolower(trim($model));
    }
}
