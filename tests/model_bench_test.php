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
 * Tests for the v7.4.0 benchmark store and the web-triggerable benchmark task.
 *
 * The comparability rules are what these tests mostly pin, because they are the
 * part that decides wrongly rather than failing loudly: a rubric mean over
 * three prompts and a rubric mean over fifty are both "a number out of 15", and
 * a recommendation engine handed both will happily rank them against each other.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\model_bench
 * @covers     \local_ai_course_assistant\task\run_model_benchmark
 */
final class model_bench_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Insert a completed run directly, with control over its age.
     *
     * @param array $meta start_run() metadata.
     * @param array $result complete_run() results.
     * @param int $agedays How long ago the run was created.
     * @return string runid
     */
    private function make_run(array $meta, array $result, int $agedays = 0): string {
        global $DB;
        $runid = model_bench::start_run($meta + ['status' => model_bench::STATUS_RUNNING]);
        model_bench::complete_run($runid, $result);
        if ($agedays > 0) {
            $DB->set_field(model_bench::TABLE, 'timecreated', time() - ($agedays * DAYSECS),
                ['runid' => $runid]);
        }
        return $runid;
    }

    // ------------------------------------------------------------------
    // Lifecycle.
    // ------------------------------------------------------------------

    public function test_start_then_complete_lifecycle(): void {
        $user = $this->getDataGenerator()->create_user();

        $runid = model_bench::start_run([
            'harness'        => 'tutor_golden',
            'sola_function'  => 'chat',
            'registry_key'   => 'Claude-Sonnet-5',
            'provider'       => 'claude',
            'model_name'     => 'claude-sonnet-5',
            'fixture_set'    => 'tests/golden/tutor_prompts.json',
            'fixture_n'      => 50,
            'judge_provider' => 'claude',
            'judge_model'    => 'claude-sonnet-4-6',
            'quality_metric' => 'rubric_mean',
            'createdby'      => $user->id,
            'params'         => ['samples' => 50],
        ]);

        $this->assertNotEmpty($runid);
        $this->assertLessThanOrEqual(40, strlen($runid), 'runid must fit the 40-char column');

        // Queued is visible before any model is called: an operator who queues
        // a run from the web must be able to see that it was recorded.
        $queued = model_bench::get_run($runid);
        $this->assertSame(model_bench::STATUS_QUEUED, $queued['status']);
        $this->assertNull($queued['timecompleted']);
        $this->assertNull($queued['quality_score']);
        $this->assertSame('claude-sonnet-5', $queued['registry_key'], 'registry keys are stored lowercased');
        $this->assertSame((int) $user->id, $queued['createdby']);
        $this->assertSame(['samples' => 50], $queued['params']);

        // plugin_release is stamped at start, because the prompts and the rubric
        // ship in the code: a score is only interpretable next to its release.
        $this->assertNotEmpty($queued['plugin_release']);

        model_bench::complete_run($runid, [
            'quality_raw'         => 14.56,
            'quality_max'         => 15.0,
            'quality_n'           => 50,
            'cost_cents_per_call' => 0.352,
            'p50_ttft_ms'         => 640,
            'p95_ttft_ms'         => 1210,
            'p50_total_ms'        => 3400,
            'calls'               => 50,
            'errors'              => 0,
        ]);

        $done = model_bench::get_run($runid);
        $this->assertSame(model_bench::STATUS_COMPLETE, $done['status']);
        $this->assertNotNull($done['timecompleted']);
        $this->assertEqualsWithDelta(14.56, $done['quality_raw'], 1e-9);
        $this->assertEqualsWithDelta(0.352, $done['cost_cents_per_call'], 1e-9);
        $this->assertSame(640, $done['p50_ttft_ms']);
        $this->assertSame(1210, $done['p95_ttft_ms']);
        $this->assertSame(50, $done['calls']);
        $this->assertSame(0, $done['errors']);
        // Both are recorded, always: without them the row is not comparable
        // with anything and cannot be known to be incomparable either.
        $this->assertSame(50, $done['quality_n']);
        $this->assertSame(50, $done['fixture_n']);
    }

    public function test_start_run_refuses_to_open_a_finished_run(): void {
        $this->expectException(\coding_exception::class);
        model_bench::start_run(['harness' => 'tutor_golden', 'status' => model_bench::STATUS_COMPLETE]);
    }

    public function test_claim_run_is_once_only(): void {
        $runid = model_bench::start_run(['harness' => 'tutor_golden', 'sola_function' => 'chat']);
        $this->assertTrue(model_bench::claim_run($runid), 'a queued run can be claimed');
        $this->assertSame(model_bench::STATUS_RUNNING, model_bench::get_run($runid)['status']);
        // A second runner must not re-run it: that would bill twice and
        // overwrite the first runner's result.
        $this->assertFalse(model_bench::claim_run($runid));
        $this->assertFalse(model_bench::claim_run('no-such-run'));
    }

    // ------------------------------------------------------------------
    // Normalization.
    // ------------------------------------------------------------------

    public function test_quality_score_is_raw_over_max(): void {
        $runid = $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat', 'model_name' => 'm'],
            ['quality_raw' => 14.56, 'quality_max' => 15.0, 'quality_n' => 50]
        );
        // The column is number(12,6), so the stored score is the ratio rounded
        // to six decimals — plenty for ranking, and worth knowing before
        // someone writes an equality assertion against it.
        $this->assertEqualsWithDelta(14.56 / 15.0, model_bench::get_run($runid)['quality_score'], 1e-6);
    }

    public function test_quality_score_normalizes_a_different_metric_onto_the_same_axis(): void {
        // recall@3 = 0.725 out of 1.0 and a rubric mean of 14.56 out of 15 must
        // land on one axis, or a cross-harness chart is meaningless.
        $runid = $this->make_run(
            ['harness' => 'rag_fixture', 'sola_function' => 'rag', 'model_name' => 'voyage-3.5'],
            ['quality_metric' => 'recall_at_3', 'quality_raw' => 0.725, 'quality_max' => 1.0, 'quality_n' => 40]
        );
        $row = model_bench::get_run($runid);
        $this->assertEqualsWithDelta(0.725, $row['quality_score'], 1e-9);
        $this->assertSame('recall_at_3', $row['quality_metric']);
    }

    public function test_quality_score_is_null_not_zero_when_unnormalizable(): void {
        // A run whose judge was unavailable has cost and latency but no quality.
        // Scoring it 0.0 would rank it dead last on quality it never measured.
        $runid = $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat', 'model_name' => 'm'],
            ['quality_raw' => null, 'quality_max' => null, 'quality_n' => 0,
                'cost_cents_per_call' => 0.11, 'message' => 'judge unavailable']
        );
        $row = model_bench::get_run($runid);
        $this->assertNull($row['quality_score']);
        $this->assertEqualsWithDelta(0.11, $row['cost_cents_per_call'], 1e-9);
    }

    // ------------------------------------------------------------------
    // latest_by_function.
    // ------------------------------------------------------------------

    public function test_latest_by_function_returns_newest_per_model(): void {
        $old = $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat',
                'registry_key' => 'gemini-2.5-flash', 'provider' => 'gemini',
                'model_name' => 'gemini-2.5-flash'],
            ['quality_raw' => 12.0, 'quality_max' => 15.0, 'quality_n' => 50],
            10
        );
        $new = $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat',
                'registry_key' => 'gemini-2.5-flash', 'provider' => 'gemini',
                'model_name' => 'gemini-2.5-flash'],
            ['quality_raw' => 13.4, 'quality_max' => 15.0, 'quality_n' => 50],
            1
        );

        $rows = model_bench::latest_by_function('chat');
        $this->assertCount(1, $rows, 'one row per model, not one per run');
        $this->assertSame($new, $rows[0]['runid']);
        $this->assertNotSame($old, $rows[0]['runid']);
    }

    public function test_latest_by_function_does_not_collapse_models_sharing_a_provider(): void {
        // The get_records_sql first-column trap: keyed on provider (or on
        // model_name for a model served by two providers) one of these rows
        // silently disappears, and the admin card shows a model that was never
        // benchmarked next to one that was.
        $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat',
                'provider' => 'claude', 'model_name' => 'claude-sonnet-5'],
            ['quality_raw' => 14.5, 'quality_max' => 15.0, 'quality_n' => 50]
        );
        $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat',
                'provider' => 'claude', 'model_name' => 'claude-haiku-4-5'],
            ['quality_raw' => 12.1, 'quality_max' => 15.0, 'quality_n' => 50]
        );
        // Same model name, two vendors serving it.
        $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat',
                'provider' => 'openai', 'model_name' => 'llama-3.1-8b'],
            ['quality_raw' => 9.0, 'quality_max' => 15.0, 'quality_n' => 50]
        );
        $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat',
                'provider' => 'together', 'model_name' => 'llama-3.1-8b'],
            ['quality_raw' => 9.4, 'quality_max' => 15.0, 'quality_n' => 50]
        );

        $rows = model_bench::latest_by_function('chat');
        $this->assertCount(4, $rows);

        $seen = [];
        foreach ($rows as $r) {
            $seen[] = $r['provider'] . '/' . $r['model_name'];
        }
        sort($seen);
        $this->assertSame([
            'claude/claude-haiku-4-5',
            'claude/claude-sonnet-5',
            'openai/llama-3.1-8b',
            'together/llama-3.1-8b',
        ], $seen);
    }

    public function test_latest_by_function_is_scoped_to_one_function(): void {
        $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat', 'model_name' => 'a'],
            ['quality_raw' => 14.0, 'quality_max' => 15.0, 'quality_n' => 50]
        );
        $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'quiz', 'model_name' => 'a'],
            ['quality_raw' => 11.0, 'quality_max' => 15.0, 'quality_n' => 50]
        );
        $this->assertCount(1, model_bench::latest_by_function('chat'));
        $this->assertCount(1, model_bench::latest_by_function('quiz'));
        $this->assertSame([], model_bench::latest_by_function('embedding'));
    }

    public function test_small_run_is_excluded_from_the_comparable_set(): void {
        $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat', 'model_name' => 'smoke-only'],
            ['quality_raw' => 15.0, 'quality_max' => 15.0, 'quality_n' => 3]
        );
        $rows = model_bench::latest_by_function('chat');
        $this->assertSame([], $rows,
            'a 3-prompt run scoring a perfect 15 must not enter the comparable set');

        // The row itself is not lost — it is queryable, just not comparable.
        $this->assertCount(1, model_bench::history('smoke-only'));
        // And an explicit floor can let it in.
        $this->assertCount(1, model_bench::latest_by_function('chat', 1));
    }

    public function test_comparability_floor_is_admin_settable(): void {
        // After the last deploy the floor still has to be adjustable, so it
        // reads a setting rather than only the constant.
        $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat', 'model_name' => 'twenty-prompts'],
            ['quality_raw' => 14.0, 'quality_max' => 15.0, 'quality_n' => 20]
        );
        $this->assertCount(1, model_bench::latest_by_function('chat'), 'default floor is 5');

        set_config('bench_min_quality_n', 50, 'local_ai_course_assistant');
        $this->assertSame([], model_bench::latest_by_function('chat'),
            'a stricter configured floor must exclude the 20-item run');
        $this->assertSame(50, model_bench::configured_floor());

        // Zero would defeat the guard entirely, so it falls back to the default.
        set_config('bench_min_quality_n', 0, 'local_ai_course_assistant');
        $this->assertSame(model_bench::MIN_QUALITY_N, model_bench::configured_floor());
    }

    public function test_a_small_new_run_does_not_shadow_a_large_older_one(): void {
        $big = $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat',
                'registry_key' => 'claude-sonnet-5', 'model_name' => 'claude-sonnet-5'],
            ['quality_raw' => 14.56, 'quality_max' => 15.0, 'quality_n' => 50],
            7
        );
        $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat',
                'registry_key' => 'claude-sonnet-5', 'model_name' => 'claude-sonnet-5'],
            ['quality_raw' => 15.0, 'quality_max' => 15.0, 'quality_n' => 2],
            0
        );

        $rows = model_bench::latest_by_function('chat');
        $this->assertCount(1, $rows);
        $this->assertSame($big, $rows[0]['runid'],
            'the floor is applied before "newest per model", so noise cannot hide real evidence');
    }

    public function test_incomplete_runs_are_not_comparable(): void {
        model_bench::start_run(['harness' => 'tutor_golden', 'sola_function' => 'chat',
            'model_name' => 'queued-model']);
        $running = model_bench::start_run(['harness' => 'tutor_golden', 'sola_function' => 'chat',
            'model_name' => 'running-model', 'status' => model_bench::STATUS_RUNNING]);
        model_bench::fail_run($running, 'nope');
        $this->assertSame([], model_bench::latest_by_function('chat'));
    }

    public function test_comparable_group_distinguishes_fixture_sets(): void {
        $a = $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat', 'model_name' => 'm1',
                'fixture_set' => 'tests/golden/tutor_prompts.json', 'fixture_n' => 50],
            ['quality_raw' => 14.0, 'quality_max' => 15.0, 'quality_n' => 50]
        );
        $b = $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat', 'model_name' => 'm2',
                'fixture_set' => 'tests/golden/tutor_prompts_domains.json', 'fixture_n' => 20],
            ['quality_raw' => 14.0, 'quality_max' => 15.0, 'quality_n' => 20]
        );
        $groups = [];
        foreach (model_bench::latest_by_function('chat') as $row) {
            $groups[$row['runid']] = $row['comparable_group'];
        }
        $this->assertNotSame($groups[$a], $groups[$b],
            'identical means over different question sets must not read as comparable');
    }

    // ------------------------------------------------------------------
    // Failures and history.
    // ------------------------------------------------------------------

    public function test_failed_run_is_queryable_with_its_message(): void {
        $runid = model_bench::start_run([
            'harness'      => 'tutor_golden',
            'sola_function' => 'chat',
            'registry_key' => 'claude-sonnet-5',
            'model_name'   => 'claude-sonnet-5',
            'status'       => model_bench::STATUS_RUNNING,
        ]);
        model_bench::fail_run($runid, 'No credentials are reachable for provider "claude"');

        $row = model_bench::get_run($runid);
        $this->assertSame(model_bench::STATUS_FAILED, $row['status']);
        $this->assertStringContainsString('No credentials', $row['message']);
        $this->assertNotNull($row['timecompleted']);

        // history() returns failures deliberately: "why did my benchmark not
        // produce a number" is the question an operator actually has.
        $history = model_bench::history('claude-sonnet-5');
        $this->assertCount(1, $history);
        $this->assertSame(model_bench::STATUS_FAILED, $history[0]['status']);
        $this->assertStringContainsString('No credentials', $history[0]['message']);
    }

    public function test_fail_run_on_unknown_runid_throws(): void {
        $this->expectException(\dml_exception::class);
        model_bench::fail_run('does-not-exist', 'x');
    }

    public function test_history_matches_registry_key_or_model_name_and_filters_by_function(): void {
        // A run recorded before the model was registered carries only a model
        // name; the same model's later runs carry the registry key. An operator
        // asking for that model's history wants both.
        $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat', 'model_name' => 'gemini-2.5-flash'],
            ['quality_raw' => 12.0, 'quality_max' => 15.0, 'quality_n' => 50],
            30
        );
        $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'chat',
                'registry_key' => 'gemini-2.5-flash', 'model_name' => 'gemini-2.5-flash-002'],
            ['quality_raw' => 13.0, 'quality_max' => 15.0, 'quality_n' => 50],
            1
        );
        $this->make_run(
            ['harness' => 'tutor_golden', 'sola_function' => 'quiz',
                'registry_key' => 'gemini-2.5-flash', 'model_name' => 'gemini-2.5-flash'],
            ['quality_raw' => 10.0, 'quality_max' => 15.0, 'quality_n' => 50],
            2
        );

        $all = model_bench::history('gemini-2.5-flash');
        $this->assertCount(3, $all);
        // Newest first.
        $this->assertGreaterThanOrEqual($all[1]['timecreated'], $all[0]['timecreated']);
        $this->assertGreaterThanOrEqual($all[2]['timecreated'], $all[1]['timecreated']);

        $this->assertCount(2, model_bench::history('gemini-2.5-flash', 'chat'));
        $this->assertCount(1, model_bench::history('gemini-2.5-flash', 'quiz'));
        $this->assertSame([], model_bench::history('never-benchmarked'));
    }

    public function test_percentile_matches_the_cli_definition(): void {
        $this->assertNull(model_bench::percentile([], 50));
        $this->assertSame(3, model_bench::percentile([1, 2, 3, 4, 5], 50));
        $this->assertSame(5, model_bench::percentile([1, 2, 3, 4, 5], 95));
        $this->assertSame(1, model_bench::percentile([5, 1, 3], 1));
    }

    // ------------------------------------------------------------------
    // The ad-hoc task: useful failures, no shell required.
    // ------------------------------------------------------------------

    /**
     * Run the task with the given custom data and return the resulting row.
     *
     * @param array $data
     * @return array<string, mixed>|null
     */
    private function run_task(array $data): ?array {
        $task = new \local_ai_course_assistant\task\run_model_benchmark();
        $task->set_custom_data($data);
        ob_start();
        $task->execute();
        ob_end_clean();
        $rows = model_bench::history((string) ($data['registry_key'] ?? ''));
        return $rows[0] ?? null;
    }

    public function test_task_failure_names_the_comparison_providers_row_to_add(): void {
        global $DB;
        // A registry entry an admin created on the form, for a vendor whose key
        // the site does not hold. create_for_comparison() refuses to send
        // another vendor's key (v6.9.7), so the run cannot proceed — and the
        // operator has no shell, so the row has to say what to do about it.
        $DB->insert_record(model_registry::TABLE_MODELS, (object) [
            'modelkey' => 'claude-sonnet-5', 'provider' => 'claude', 'capability' => 'chat',
            'input_rate' => 3.0, 'output_rate' => 15.0, 'status' => 'candidate',
            'source' => 'manual', 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $row = $this->run_task([
            'registry_key'  => 'claude-sonnet-5',
            'sola_function' => 'chat',
            'harness'       => 'tutor_golden',
            'samples'       => 2,
        ]);

        $this->assertNotNull($row, 'a failed benchmark must still leave a row');
        $this->assertSame(model_bench::STATUS_FAILED, $row['status']);
        $this->assertStringContainsString('Comparison providers', $row['message']);
        $this->assertStringContainsString('claude|', $row['message'],
            'the message must contain the literal row to paste');
        $this->assertStringContainsString('claude-sonnet-5', $row['message']);
        $this->assertSame('claude-sonnet-5', $row['registry_key']);
    }

    public function test_task_rejects_an_unimplemented_harness_instead_of_scoring_it(): void {
        global $DB;
        $DB->insert_record(model_registry::TABLE_MODELS, (object) [
            'modelkey' => 'voyage-3.5', 'provider' => 'voyage', 'capability' => 'embedding',
            'input_rate' => 0.06, 'output_rate' => 0.0, 'status' => 'active',
            'source' => 'manual', 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $row = $this->run_task([
            'registry_key'  => 'voyage-3.5',
            'sola_function' => 'embedding',
            'harness'       => 'rag_fixture',
        ]);

        $this->assertSame(model_bench::STATUS_FAILED, $row['status']);
        $this->assertStringContainsString('rag_fixture', $row['message']);
        $this->assertStringContainsString('tutor_golden', $row['message']);
    }

    public function test_task_reports_a_registry_row_with_no_provider(): void {
        global $DB;
        $DB->insert_record(model_registry::TABLE_MODELS, (object) [
            'modelkey' => 'mystery-model', 'provider' => null, 'capability' => 'chat',
            'status' => 'candidate', 'source' => 'manual',
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $row = $this->run_task([
            'registry_key'  => 'mystery-model',
            'sola_function' => 'chat',
            'harness'       => 'tutor_golden',
        ]);

        $this->assertSame(model_bench::STATUS_FAILED, $row['status']);
        $this->assertStringContainsString('no provider', $row['message']);
    }

    public function test_task_claims_a_queued_row_rather_than_opening_a_second(): void {
        global $DB;
        $DB->insert_record(model_registry::TABLE_MODELS, (object) [
            'modelkey' => 'claude-sonnet-5', 'provider' => 'claude', 'capability' => 'chat',
            'input_rate' => 3.0, 'output_rate' => 15.0, 'status' => 'candidate',
            'source' => 'manual', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        // This is the web path: the admin page inserts the queued row so the
        // operator sees it immediately, and passes its runid to the task.
        $runid = model_bench::start_run([
            'harness'       => 'tutor_golden',
            'sola_function' => 'chat',
            'registry_key'  => 'claude-sonnet-5',
            'model_name'    => 'claude-sonnet-5',
            'provider'      => 'claude',
        ]);

        $this->run_task([
            'registry_key'  => 'claude-sonnet-5',
            'sola_function' => 'chat',
            'harness'       => 'tutor_golden',
            'runid'         => $runid,
        ]);

        $this->assertEquals(1, $DB->count_records(model_bench::TABLE),
            'the queued row must be the row that fills in, not a second row');
        $row = model_bench::get_run($runid);
        $this->assertSame(model_bench::STATUS_FAILED, $row['status']);
        $this->assertStringContainsString('Comparison providers', $row['message']);

        // Re-running the same task must not touch the finished row again.
        $this->run_task([
            'registry_key'  => 'claude-sonnet-5',
            'sola_function' => 'chat',
            'harness'       => 'tutor_golden',
            'runid'         => $runid,
        ]);
        $this->assertEquals(1, $DB->count_records(model_bench::TABLE));
    }

    public function test_task_without_a_registry_key_records_nothing(): void {
        global $DB;
        $task = new \local_ai_course_assistant\task\run_model_benchmark();
        $task->set_custom_data(['sola_function' => 'chat']);
        ob_start();
        $task->execute();
        ob_end_clean();
        $this->assertEquals(0, $DB->count_records(model_bench::TABLE));
    }
}
