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

namespace local_ai_course_assistant\autoupgrade;

use local_ai_course_assistant\bench\jailbreak_suite;
use local_ai_course_assistant\bench\judge;
use local_ai_course_assistant\context_builder;
use local_ai_course_assistant\model_bench;
use local_ai_course_assistant\provider\base_provider;
use local_ai_course_assistant\spend_guard;
use local_ai_course_assistant\token_cost_manager;
use local_ai_course_assistant\token_estimator;

/**
 * Automatic evaluation of one candidate against the model it would replace (v7.8.0).
 *
 * Both sides are measured in the same run under the same conditions, because
 * a comparison against last month's number of the current model is a
 * comparison against a different system prompt, a different provider day and
 * possibly a different model behind the same name:
 *
 *  - golden: the role's prompt set, answered under the DEPLOYED system prompt
 *    of the evaluation course (built for the site guest identity, so no
 *    learner's name, profile or memory is ever sent), at the site's answer
 *    budget, streamed, then judged by the configured judge. The deployed
 *    prompt rather than the short harness prompt makes the cost a
 *    production-shaped cost: a model with cheap output and dear input would
 *    otherwise look cheaper than it is (gemini-3.8-flash measured 0.088 cents
 *    against 0.187 on the short prompt and 0.458 against 0.335 per real turn);
 *  - jailbreak: the suite, three runs, each with its own canary, on the same
 *    course prompt with the hostile retrieved chunk;
 *  - truncation, errors, latency and cost per answer from the golden calls.
 *
 * The run is refused before it starts if the monthly budget cannot cover a
 * high estimate of it, and its actual spend is recorded whether it finishes
 * or not. Nothing here switches anything: switcher reads the verdict.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evaluator {
    /** @var string Table. */
    public const TABLE = 'local_ai_course_assistant_model_eval';

    /** @var string Harness name on the bench rows. */
    public const HARNESS = 'auto_eval';

    /** @var string Waiting for cron. */
    public const QUEUED = 'queued';

    /** @var string Running. */
    public const RUNNING = 'running';

    /** @var string Finished with a verdict. */
    public const COMPLETE = 'complete';

    /** @var string Finished without a verdict; message says why. */
    public const FAILED = 'failed';

    /** @var string Not started: over budget, emergency, or nothing to compare. */
    public const SKIPPED = 'skipped';

    /** @var callable fn(string $provider, string $model): provider_interface */
    private $factory;

    /** @var array<string, array> Spend per bucket, for the msgs ledger. */
    private array $spend = [];

    /** @var float USD spent by this run so far. */
    private float $usd = 0.0;

    /** @var int Evaluation being run, for writing spend as it accrues. */
    private int $evalid = 0;

    /** @var int Calls accounted since spend was last written. */
    private int $unsaved = 0;

    /** @var int Hours after which a running evaluation is presumed dead. */
    public const STALE_HOURS = 6;

    /**
     * Constructor.
     *
     * @param callable|null $factory Builds a provider; tests replace it.
     */
    public function __construct(?callable $factory = null) {
        $this->factory = $factory ?? static function (string $provider, string $model) {
            // With enforcespend false: this measures providers on purpose, and a
            // learner spend cap must not substitute a different model and
            // report its numbers under this one. The emergency stop still
            // applies inside the factory.
            return base_provider::create_for_comparison($provider, $model, 0, false);
        };
    }

    /**
     * Queue an evaluation of a candidate.
     *
     * @param int $candidateid
     * @param int $userid Admin who asked, or 0 for discovery.
     * @return int Evaluation id.
     */
    public static function queue(int $candidateid, int $userid = 0): int {
        global $DB;
        $cand = candidates::get($candidateid);
        if ($cand === null) {
            throw new \coding_exception('No such candidate: ' . $candidateid);
        }
        $current = roles::current((string) $cand->role);
        $id = (int) $DB->insert_record(self::TABLE, (object) [
            'candidateid' => $candidateid,
            'role' => $cand->role,
            'provider' => $cand->provider,
            'model' => $cand->model,
            'variant' => $cand->variant,
            'inc_provider' => $current['provider'],
            'inc_model' => $current['model'],
            'inc_variant' => $current['variant'],
            'status' => self::QUEUED,
            'est_cost_usd' => null,
            'actual_cost_usd' => 0,
            'createdby' => $userid > 0 ? $userid : null,
            'timecreated' => time(),
        ]);
        $task = new \local_ai_course_assistant\task\evaluate_model_candidate();
        $task->set_custom_data(['evalid' => $id]);
        \core\task\manager::queue_adhoc_task($task);
        return $id;
    }

    /**
     * Run one queued evaluation to a verdict.
     *
     * @param int $evalid
     * @return \stdClass The evaluation row as finished.
     */
    public function run(int $evalid): \stdClass {
        global $DB;
        $eval = $DB->get_record(self::TABLE, ['id' => $evalid], '*', MUST_EXIST);
        if ($eval->status !== self::QUEUED) {
            return $eval;
        }
        $DB->update_record(self::TABLE, (object) ['id' => $evalid, 'status' => self::RUNNING, 'timestarted' => time()]);
        $this->evalid = $evalid;

        try {
            $result = $this->measure($eval);
        } catch (\Throwable $e) {
            $result = ['status' => self::FAILED, 'message' => \core_text::substr(
                \local_ai_course_assistant\security::redact_secrets($e->getMessage()),
                0,
                500
            )];
        }
        $this->flush_spend();

        $record = (object) array_merge(['id' => $evalid, 'actual_cost_usd' => round($this->usd, 6),
            'timecompleted' => time()], $result);
        foreach (['metrics', 'gate_detail'] as $json) {
            if (isset($record->$json) && is_array($record->$json)) {
                $record->$json = json_encode($record->$json);
            }
        }
        $DB->update_record(self::TABLE, $record);
        $eval = $DB->get_record(self::TABLE, ['id' => $evalid], '*', MUST_EXIST);

        if ($eval->status === self::COMPLETE) {
            $cand = candidates::record_verdict(
                (int) $eval->candidateid,
                (bool) $eval->gate_passed,
                $evalid,
                gate::REQUIRED_PASSES
            );
            switcher::after_evaluation($cand, $eval);
        }
        return $eval;
    }

    /**
     * Everything between a claimed evaluation and its verdict.
     *
     * @param \stdClass $eval
     * @return array Fields for the evaluation row.
     */
    private function measure(\stdClass $eval): array {
        if (spend_guard::emergency_chat_stopped()) {
            return ['status' => self::SKIPPED, 'message' => 'The emergency chat stop is engaged.'];
        }
        $current = roles::current((string) $eval->role);
        if ($current['model'] !== (string) $eval->inc_model || $current['provider'] !== (string) $eval->inc_provider) {
            return ['status' => self::SKIPPED,
                'message' => 'The role changed model after this evaluation was queued, so it would compare the wrong pair.'];
        }
        $spec = roles::spec((string) $eval->role);
        if (empty($spec['evaluable'])) {
            return ['status' => self::SKIPPED,
                'message' => 'No benchmark measures this role\'s task, so it is never evaluated automatically.'];
        }
        $prompts = self::load_fixture((string) $spec['fixture']);
        if ($prompts === null) {
            return ['status' => self::FAILED, 'message' => 'The fixture set ' . $spec['fixture'] . ' could not be read.'];
        }

        $courseid = self::evaluation_course();
        $guestid = (int) get_config('core', 'siteguest');
        $golden = context_builder::build_system_prompt($courseid, $guestid, '', [], 0, '');
        $jbbase = jailbreak_suite::build_prompt($courseid, $guestid);
        $maxtokens = (int) get_config('local_ai_course_assistant', 'max_tokens');
        $maxtokens = $maxtokens > 0 ? $maxtokens : 1024;
        $judgeprovider = trim((string) get_config('local_ai_course_assistant', 'bench_judge_provider')) ?: 'claude';
        $judgemodel = trim((string) get_config('local_ai_course_assistant', 'bench_judge_model')) ?: 'claude-sonnet-4-6';

        $estimate = budget::estimate(
            [['provider' => $eval->provider, 'model' => $eval->model],
             ['provider' => $eval->inc_provider, 'model' => $eval->inc_model]],
            count($prompts),
            token_estimator::estimate_tokens(strlen($golden) >= strlen($jbbase) ? $golden : $jbbase, 'en'),
            $maxtokens,
            ['provider' => $judgeprovider, 'model' => $judgemodel]
        );
        global $DB;
        $DB->set_field(self::TABLE, 'est_cost_usd', $estimate, ['id' => $eval->id]);
        if ($estimate === null) {
            return ['status' => self::SKIPPED,
                'message' => 'One of the models, or the judge, has no known price, so the run cannot be budgeted.'];
        }
        $remaining = budget::remaining(null, (int) $eval->id);
        if ($estimate > $remaining) {
            return ['status' => self::SKIPPED, 'message' => sprintf(
                'Over the monthly testing budget: this run is estimated at $%.2f and $%.2f of $%.2f is left this month.',
                $estimate,
                $remaining,
                budget::limit()
            )];
        }

        $judge = ($this->factory)($judgeprovider, $judgemodel);
        $sides = [
            'candidate' => ['provider' => $eval->provider, 'model' => $eval->model, 'variant' => $eval->variant],
            'incumbent' => ['provider' => $eval->inc_provider, 'model' => $eval->inc_model, 'variant' => $eval->inc_variant],
        ];
        $metrics = ['course' => $courseid, 'prompts' => count($prompts), 'max_tokens' => $maxtokens,
            'system_prompt_sha' => substr(sha1($golden), 0, 12), 'judge' => $judgeprovider . '/' . $judgemodel];
        foreach ($sides as $name => $side) {
            $metrics[$name] = $this->measure_side(
                $side,
                $name,
                $prompts,
                $golden,
                $jbbase,
                $maxtokens,
                $judge,
                (string) $spec['fixture'],
                (string) $eval->role,
                (int) $eval->id
            );
        }

        $verdict = gate::evaluate($metrics['candidate'], $metrics['incumbent']);
        return [
            'status' => self::COMPLETE,
            'metrics' => $metrics,
            'gate_passed' => $verdict['passed'] ? 1 : 0,
            'gate_detail' => $verdict['checks'],
            'cand_runid' => $metrics['candidate']['runid'] ?? null,
            'inc_runid' => $metrics['incumbent']['runid'] ?? null,
            'message' => null,
        ];
    }

    /**
     * Measure one side: golden answers and judging, then the jailbreak runs.
     *
     * @param array $side provider, model, variant.
     * @param string $bucket 'candidate' or 'incumbent', for the spend ledger.
     * @param array $prompts
     * @param string $golden System prompt for the golden answers.
     * @param string $jbbase System prompt for the jailbreak runs (canary added per run).
     * @param int $maxtokens
     * @param object $judge
     * @param string $fixture
     * @param string $role
     * @param int $evalid
     * @return array
     */
    private function measure_side(
        array $side,
        string $bucket,
        array $prompts,
        string $golden,
        string $jbbase,
        int $maxtokens,
        $judge,
        string $fixture,
        string $role,
        int $evalid
    ): array {
        $provider = ($this->factory)((string) $side['provider'], (string) $side['model']);
        // Each side runs at the reasoning level it runs (or would run) at in
        // production: the current model at today's level, the candidate at the
        // level the switch would write. Measuring a candidate at one level and
        // putting it live at another would make the cost gate meaningless.
        $sitelevel = \local_ai_course_assistant\model_capabilities::site_level();
        $level = $bucket === 'incumbent'
            ? ((string) $side['variant'] === roles::VARIANT_THINKING_OFF ? 'off' : $sitelevel)
            : roles::level_after($role, (string) $side['variant']);
        $options = ['max_tokens' => $maxtokens, 'reasoning' => $level];

        $calls = 0;
        $errors = 0;
        $truncated = 0;
        $costs = [];
        $costunknown = false;
        $ttfts = [];
        $scores = [];
        foreach ($prompts as $p) {
            $calls++;
            $text = '';
            $ttft = null;
            $start = microtime(true);
            try {
                $provider->chat_completion_stream(
                    $golden,
                    [['role' => 'user', 'content' => (string) $p['text']]],
                    function (string $chunk) use (&$text, &$ttft, $start) {
                        if ($ttft === null && $chunk !== '') {
                            $ttft = (int) round((microtime(true) - $start) * 1000);
                        }
                        $text .= $chunk;
                    },
                    $options
                );
                $usage = $provider->get_last_token_usage();
                $this->account($bucket, $usage);
                $cut = base_provider::is_truncation($provider->get_last_finish_reason());
                if ($cut) {
                    $truncated++;
                }
                if (trim($text) === '' && !$cut) {
                    $errors++;
                    continue;
                }
                $cost = self::cost_cents($usage);
                if ($cost === null) {
                    $costunknown = true;
                } else {
                    $costs[] = $cost;
                }
                if ($ttft !== null) {
                    $ttfts[] = $ttft;
                }
                $outcome = judge::score(
                    $judge,
                    (string) $p['text'],
                    jailbreak_suite::strip_markers($text),
                    $cut,
                    function ($u) {
                        $this->account('judge', is_array($u) ? $u : null);
                    }
                );
                if ($outcome['scores'] !== null) {
                    $s = $outcome['scores'];
                    $scores[] = (float) $s['socratic'] + (float) $s['accuracy'] + (float) $s['tone'];
                }
            } catch (\Throwable $e) {
                $errors++;
                try {
                    $this->account($bucket, $provider->get_last_token_usage());
                } catch (\Throwable $ignored) {
                    unset($ignored);
                }
            }
        }

        $jb = ['runs' => 0, 'PASS' => 0, 'FAIL' => 0, 'REVIEW' => 0, 'ERROR' => 0, 'leaks' => 0];
        for ($run = 1; $run <= gate::JAILBREAK_RUNS; $run++) {
            $canary = jailbreak_suite::new_canary();
            $outcome = jailbreak_suite::run(
                $provider,
                jailbreak_suite::with_canary($jbbase, $canary),
                $canary,
                null,
                function ($u) use ($bucket) {
                    $this->account($bucket, is_array($u) ? $u : null);
                },
                // The safety runs use the same reasoning level as the answers,
                // so the gate is measured on the configuration that would ship.
                ['reasoning' => $level]
            );
            $jb['runs']++;
            foreach (['PASS', 'FAIL', 'REVIEW', 'ERROR', 'leaks'] as $k) {
                $jb[$k] += (int) $outcome['counts'][$k];
            }
        }
        $jbcalls = count(jailbreak_suite::TESTS) * gate::JAILBREAK_RUNS;

        $metrics = [
            'provider' => (string) $side['provider'],
            'model' => (string) $side['model'],
            'variant' => (string) $side['variant'],
            'prompts' => count($prompts),
            'judged' => count($scores),
            'quality' => $scores ? array_sum($scores) / count($scores) : null,
            'cost_cents' => ($costunknown || !$costs) ? null : array_sum($costs) / count($costs),
            'truncated' => $truncated,
            'truncated_rate' => $calls > 0 ? $truncated / $calls : null,
            'errors' => $errors + $jb['ERROR'],
            'calls' => $calls + $jbcalls,
            'error_rate' => ($calls + $jbcalls) > 0 ? ($errors + $jb['ERROR']) / ($calls + $jbcalls) : null,
            'p50_ttft_ms' => model_bench::percentile($ttfts, 50),
            'p95_ttft_ms' => model_bench::percentile($ttfts, 95),
            'jailbreak' => $jb,
        ];

        $runid = model_bench::start_run([
            'harness' => self::HARNESS,
            'sola_function' => 'auto_' . $role,
            'provider' => $metrics['provider'],
            'model_name' => $metrics['model'],
            'fixture_set' => $fixture,
            'fixture_n' => count($prompts),
            'quality_metric' => 'rubric_mean',
            'status' => model_bench::STATUS_RUNNING,
            'params' => ['variant' => $metrics['variant'], 'evaluation' => $evalid, 'side' => $bucket],
        ]);
        model_bench::complete_run($runid, [
            'quality_raw' => $metrics['quality'],
            'quality_max' => $metrics['quality'] !== null ? \local_ai_course_assistant\task\run_model_benchmark::RUBRIC_MAX : null,
            'quality_n' => $metrics['judged'],
            'cost_cents_per_call' => $metrics['cost_cents'],
            'p50_ttft_ms' => $metrics['p50_ttft_ms'],
            'p95_ttft_ms' => $metrics['p95_ttft_ms'],
            'calls' => $metrics['calls'],
            'errors' => $metrics['errors'],
        ]);
        $metrics['runid'] = $runid;
        return $metrics;
    }

    /**
     * Mark evaluations that have been running for too long as failed.
     *
     * A worker killed mid-run never reaches its own catch block, so its row
     * would stay RUNNING for ever, blocking its candidate from being queued
     * again and holding its estimate against the budget. What it spent before
     * dying was written as it accrued and still counts.
     *
     * @param int|null $now
     * @return int Rows marked.
     */
    public static function fail_stale(?int $now = null): int {
        global $DB;
        $cutoff = ($now ?? time()) - self::STALE_HOURS * HOURSECS;
        $rows = $DB->get_records_select(
            self::TABLE,
            'status = :running AND COALESCE(timestarted, timecreated) < :cutoff',
            ['running' => self::RUNNING, 'cutoff' => $cutoff],
            '',
            'id'
        );
        foreach ($rows as $row) {
            $DB->update_record(self::TABLE, (object) ['id' => $row->id, 'status' => self::FAILED,
                'message' => 'The run stopped without finishing (the worker was probably killed).',
                'timecompleted' => $now ?? time()]);
        }
        return count($rows);
    }

    /**
     * The prompt set for a role, confined to the plugin directory.
     *
     * @param string $relpath
     * @return array|null
     */
    public static function load_fixture(string $relpath): ?array {
        global $CFG;
        $base = realpath($CFG->dirroot . '/local/ai_course_assistant');
        $real = realpath($CFG->dirroot . '/local/ai_course_assistant/' . ltrim(model_bench::canonical_fixture($relpath), '/'));
        if ($base === false || $real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($real), true);
        if (!is_array($decoded) || empty($decoded['prompts']) || !is_array($decoded['prompts'])) {
            return null;
        }
        return array_values(array_filter($decoded['prompts'], static function ($p) {
            return is_array($p) && trim((string) ($p['text'] ?? '')) !== '';
        }));
    }

    /**
     * The course whose deployed prompt the evaluation uses.
     *
     * The setting when it names a course that exists; otherwise the course with
     * the most chat answers in the last 30 days, which is the prompt most
     * learners actually meet; otherwise the front page.
     *
     * @return int
     */
    public static function evaluation_course(): int {
        global $DB;
        $configured = (int) get_config('local_ai_course_assistant', 'autoupgrade_eval_courseid');
        if ($configured > 0 && $DB->record_exists('course', ['id' => $configured])) {
            return $configured;
        }
        $busiest = $DB->get_records_sql(
            "SELECT m.courseid, COUNT(1) AS n
               FROM {local_ai_course_assistant_msgs} m
              WHERE m.role = :role AND m.timecreated >= :since AND m.courseid <> :site
                AND " . \local_ai_course_assistant\analytics::benchmark_rows_excluded('m') . "
           GROUP BY m.courseid
           ORDER BY COUNT(1) DESC, m.courseid ASC",
            ['role' => 'assistant', 'since' => time() - 30 * DAYSECS, 'site' => SITEID],
            0,
            1
        );
        foreach ($busiest as $row) {
            if ($DB->record_exists('course', ['id' => $row->courseid])) {
                return (int) $row->courseid;
            }
        }
        return SITEID;
    }

    /**
     * One call's cost in cents at registry prices, thinking included.
     *
     * @param array|null $usage
     * @return float|null
     */
    public static function cost_cents(?array $usage): ?float {
        if (empty($usage) || empty($usage['model'])) {
            return null;
        }
        $usd = token_cost_manager::estimate_cost(
            (string) $usage['model'],
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            (int) ($usage['reasoning_tokens'] ?? 0)
        );
        return $usd === null ? null : $usd * 100;
    }

    /**
     * Count one call's spend toward this run and its ledger bucket.
     *
     * @param string $bucket
     * @param array|null $usage
     * @return void
     */
    private function account(string $bucket, ?array $usage): void {
        if (empty($usage) || empty($usage['model'])) {
            return;
        }
        $cents = self::cost_cents($usage);
        if ($cents !== null) {
            $this->usd += $cents / 100;
        }
        // Written as it accrues, so a worker that dies mid-run (a fatal, an
        // out-of-memory kill) still leaves its spend counted in the budget.
        if (++$this->unsaved >= 10 && $this->evalid > 0) {
            global $DB;
            $DB->set_field(self::TABLE, 'actual_cost_usd', round($this->usd, 6), ['id' => $this->evalid]);
            $this->unsaved = 0;
        }
        if (!isset($this->spend[$bucket])) {
            $this->spend[$bucket] = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cached_tokens' => 0,
                'reasoning_tokens' => null, 'model' => (string) $usage['model'], 'provider' => $usage['provider'] ?? null,
                'calls' => 0];
        }
        $acc = &$this->spend[$bucket];
        $acc['calls']++;
        $acc['prompt_tokens'] += (int) ($usage['prompt_tokens'] ?? 0);
        $acc['completion_tokens'] += (int) ($usage['completion_tokens'] ?? 0);
        $acc['cached_tokens'] += (int) ($usage['cached_tokens'] ?? $usage['cache_read_tokens'] ?? 0);
        if (isset($usage['reasoning_tokens'])) {
            $acc['reasoning_tokens'] = (int) $acc['reasoning_tokens'] + (int) $usage['reasoning_tokens'];
        }
    }

    /**
     * Write this run's spend to the ledger, as the benchmark task does.
     *
     * Logged as model_bench rows, which every analytics and spend-cap reader
     * excludes from learner figures but the AI Spend export still sees.
     *
     * @return void
     */
    private function flush_spend(): void {
        $admin = get_admin();
        if (!$admin) {
            $this->spend = [];
            return;
        }
        foreach ($this->spend as $bucket => $acc) {
            if ((int) $acc['calls'] < 1) {
                continue;
            }
            \local_ai_course_assistant\conversation_manager::log_usage_array(
                $acc,
                (int) $admin->id,
                0,
                'model_bench',
                '[Model evaluation] ' . $bucket . ': ' . (int) $acc['calls'] . ' call(s)'
            );
        }
        $this->spend = [];
    }
}
