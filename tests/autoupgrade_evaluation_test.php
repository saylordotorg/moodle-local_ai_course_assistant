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

use local_ai_course_assistant\autoupgrade\budget;
use local_ai_course_assistant\autoupgrade\candidates;
use local_ai_course_assistant\autoupgrade\evaluator;
use local_ai_course_assistant\autoupgrade\roles;
use local_ai_course_assistant\autoupgrade\switcher;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_eval_provider.php');

/**
 * v7.8.0 automatic evaluation, end to end, with a scripted provider.
 *
 * Pinned: both sides are measured in the same run on the same prompts; the
 * monthly budget stops a run before any call is made; a canary leak fails the
 * gate whatever the quality; two consecutive passes are needed before a switch;
 * Automatic mode switches and Recommend mode only emails.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\autoupgrade\evaluator
 * @covers     \local_ai_course_assistant\autoupgrade\budget
 * @covers     \local_ai_course_assistant\autoupgrade\switcher
 */
final class autoupgrade_evaluation_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        fake_eval_provider::reset();
        model_registry::reset_cache();
        $course = $this->getDataGenerator()->create_course();
        set_config('autoupgrade_eval_courseid', $course->id, 'local_ai_course_assistant');
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('model', 'inc-model', 'local_ai_course_assistant');
        set_config('bench_judge_provider', 'claude', 'local_ai_course_assistant');
        set_config('bench_judge_model', 'judge-model', 'local_ai_course_assistant');
        foreach (['inc-model' => [0.30, 2.50], 'cand-model' => [0.30, 2.50], 'judge-model' => [3.0, 15.0]] as $key => $p) {
            model_registry::upsert(['modelkey' => $key, 'input_rate' => $p[0], 'output_rate' => $p[1]], 'manual', 2);
        }
        fake_eval_provider::$behaviour = [
            'inc-model' => ['quality' => 14, 'tokens' => 300],
            'cand-model' => ['quality' => 14, 'tokens' => 150],
            'judge-model' => ['judge' => true],
        ];
    }

    /**
     * An evaluator wired to the scripted provider.
     *
     * @return evaluator
     */
    private function evaluator(): evaluator {
        return new evaluator(function (string $provider, string $model) {
            return new fake_eval_provider($model);
        });
    }

    /**
     * A candidate and a queued evaluation of it.
     *
     * @param string $model
     * @param string $variant
     * @return array [candidate id, evaluation id]
     */
    private function queued(string $model = 'cand-model', string $variant = ''): array {
        $cid = candidates::upsert(roles::CHAT, 'openai', $model, $variant, 'test');
        return [$cid, evaluator::queue($cid, 0)];
    }

    /**
     * Move the candidate's last verdict back past the gap between passes.
     *
     * @param int $cid
     * @return void
     */
    private function a_day_later(int $cid): void {
        global $DB;
        $DB->set_field(candidates::TABLE, 'timestatus', time() - (candidates::PASS_GAP_HOURS + 1) * HOURSECS, ['id' => $cid]);
    }

    public function test_both_sides_are_measured_in_one_run(): void {
        global $DB;
        [$cid, $eid] = $this->queued();
        $eval = $this->evaluator()->run($eid);

        $this->assertSame(evaluator::COMPLETE, $eval->status, (string) $eval->message);
        $this->assertSame(1, (int) $eval->gate_passed, (string) $eval->gate_detail);
        $m = json_decode($eval->metrics, true);
        $this->assertSame(50, $m['candidate']['prompts']);
        $this->assertSame(50, $m['incumbent']['prompts'], 'The current model is re-measured on the same prompts.');
        $this->assertSame(50, $m['incumbent']['judged']);
        $this->assertSame(3, $m['candidate']['jailbreak']['runs']);
        $this->assertLessThan($m['incumbent']['cost_cents'], $m['candidate']['cost_cents']);
        $answers = array_count_values(fake_eval_provider::$calls);
        $this->assertSame(50, $answers['inc-model:answer:low']);
        $this->assertSame(50, $answers['cand-model:answer:low']);
        $this->assertSame(96, $answers['cand-model:probe:low'], 'Three jailbreak runs of 32 probes, at the answers\' level.');
        $this->assertGreaterThan(0, (float) $eval->actual_cost_usd);
        $this->assertSame(2, $DB->count_records(model_bench::TABLE, ['harness' => evaluator::HARNESS]));
        $this->assertSame(candidates::PASSED, candidates::get($cid)->status);
        $this->assertSame('inc-model', get_config('local_ai_course_assistant', 'model'), 'One pass never switches.');
        $this->assertGreaterThan(
            0,
            $DB->count_records('local_ai_course_assistant_msgs', ['interaction_type' => 'model_bench']),
            'Evaluation spend reaches the ledger.'
        );
    }

    public function test_two_passes_switch_in_automatic_mode(): void {
        global $DB;
        $sink = $this->redirectEmails();
        $events = $this->redirectEvents();
        [$cid, $first] = $this->queued();
        $this->evaluator()->run($first);
        $this->a_day_later($cid);
        $second = evaluator::queue($cid, 0);
        $this->evaluator()->run($second);

        $this->assertSame('cand-model', get_config('local_ai_course_assistant', 'model'));
        $switch = $DB->get_record(switcher::TABLE, ['role' => roles::CHAT]);
        $this->assertSame(switcher::WATCHING, $switch->status);
        $this->assertSame(['model' => 'inc-model'], json_decode($switch->prevconfig, true));
        $this->assertSame(candidates::SWITCHED, candidates::get($cid)->status);
        $this->assertGreaterThan(0, count($sink->get_messages()), 'The spend alert recipients are told.');
        $switched = array_filter($events->get_events(), function ($e) {
            return $e instanceof event\model_switched;
        });
        $this->assertCount(1, $switched);
    }

    public function test_a_second_pass_too_soon_does_not_count(): void {
        [$cid, $first] = $this->queued();
        $this->evaluator()->run($first);
        // Evaluate now, minutes later: the run is recorded but is not a second draw.
        $this->evaluator()->run(evaluator::queue($cid, 0));
        $row = candidates::get($cid);
        $this->assertSame(candidates::PASSED, $row->status);
        $this->assertSame(1, (int) $row->passes);
        $this->assertSame('inc-model', get_config('local_ai_course_assistant', 'model'));
        $this->a_day_later($cid);
        $this->evaluator()->run(evaluator::queue($cid, 0));
        $this->assertSame('cand-model', get_config('local_ai_course_assistant', 'model'));
    }

    public function test_a_run_given_up_as_stale_keeps_failed(): void {
        global $DB;
        [$cid, $eid] = $this->queued();
        $evaluator = new evaluator(function (string $provider, string $model) use ($eid) {
            global $DB;
            // Here fail_stale() marks it FAILED while it is still measuring.
            $DB->set_field(evaluator::TABLE, 'status', evaluator::FAILED, ['id' => $eid]);
            return new fake_eval_provider($model);
        });
        $eval = $evaluator->run($eid);
        $this->assertSame(evaluator::FAILED, $eval->status);
        $this->assertGreaterThan(0, (float) $eval->actual_cost_usd, 'What it spent is still written.');
        $this->assertSame(0, (int) candidates::get($cid)->passes, 'No verdict from a run already given up on.');
    }

    public function test_recommend_mode_emails_and_does_not_switch(): void {
        set_config('autoupgrade_mode', switcher::MODE_RECOMMEND, 'local_ai_course_assistant');
        $sink = $this->redirectEmails();
        [$cid, $first] = $this->queued();
        $this->evaluator()->run($first);
        $this->a_day_later($cid);
        $this->evaluator()->run(evaluator::queue($cid, 0));
        $this->assertSame('inc-model', get_config('local_ai_course_assistant', 'model'));
        $this->assertSame(candidates::ELIGIBLE, candidates::get($cid)->status);
        $subjects = array_map(function ($m) {
            return $m->subject;
        }, $sink->get_messages());
        $this->assertNotEmpty(array_filter($subjects, function ($s) {
            return str_contains($s, 'Recommended');
        }));
    }

    public function test_a_leaking_candidate_fails_however_good_it_is(): void {
        fake_eval_provider::$behaviour['cand-model'] = ['quality' => 15, 'tokens' => 50, 'leak' => true];
        [$cid, $eid] = $this->queued();
        $eval = $this->evaluator()->run($eid);
        $gate = json_decode($eval->gate_detail, true);
        $this->assertFalse($gate['jailbreak']['ok']);
        $this->assertSame(0, (int) $eval->gate_passed);
        $this->assertSame(candidates::FAILED, candidates::get($cid)->status);
    }

    public function test_a_worse_candidate_fails_on_quality(): void {
        fake_eval_provider::$behaviour['cand-model']['quality'] = 12;
        [, $eid] = $this->queued();
        $eval = $this->evaluator()->run($eid);
        $this->assertFalse(json_decode($eval->gate_detail, true)['quality']['ok']);
    }

    public function test_the_budget_stops_a_run_before_any_call(): void {
        set_config('autoupgrade_budget_usd', '0.01', 'local_ai_course_assistant');
        [, $eid] = $this->queued();
        $eval = $this->evaluator()->run($eid);
        $this->assertSame(evaluator::SKIPPED, $eval->status);
        $this->assertSame([], fake_eval_provider::$calls, 'Nothing is spent once the budget says no.');
        $this->assertGreaterThan(0.01, (float) $eval->est_cost_usd);
    }

    public function test_spend_this_month_counts_against_the_budget(): void {
        global $DB;
        [, $eid] = $this->queued();
        $this->evaluator()->run($eid);
        $spent = budget::spent();
        $this->assertGreaterThan(0, $spent);
        $this->assertEqualsWithDelta((float) $DB->get_field(evaluator::TABLE, 'actual_cost_usd', ['id' => $eid]), $spent, 1e-6);
        $this->assertEqualsWithDelta(budget::limit() - $spent, budget::remaining(), 1e-6);
    }

    public function test_an_unpriced_model_is_never_run(): void {
        [, $eid] = $this->queued('unpriced-model');
        $eval = $this->evaluator()->run($eid);
        $this->assertSame(evaluator::SKIPPED, $eval->status);
        $this->assertSame([], fake_eval_provider::$calls);
    }

    public function test_the_thinking_off_variant_is_measured_with_thinking_off(): void {
        fake_eval_provider::$behaviour['inc-model']['quality'] = 14;
        [, $eid] = $this->queued('inc-model', roles::VARIANT_THINKING_OFF);
        $this->evaluator()->run($eid);
        $calls = array_count_values(fake_eval_provider::$calls);
        $this->assertSame(50, $calls['inc-model:answer:off']);
        $this->assertSame(50, $calls['inc-model:answer:low']);
        $this->assertSame(96, $calls['inc-model:probe:off'], 'The variant\'s safety runs are taken with thinking off too.');
        $this->assertSame(96, $calls['inc-model:probe:low']);
    }

    public function test_a_candidate_is_measured_at_the_level_it_would_run_at(): void {
        // The site already runs the thinking-off variant. A candidate of another
        // model would be switched in at the default level, so that is the level
        // it is measured at; the current model keeps its own.
        set_config('reasoning_effort', 'off', 'local_ai_course_assistant');
        [, $eid] = $this->queued();
        $this->evaluator()->run($eid);
        $calls = array_count_values(fake_eval_provider::$calls);
        $this->assertSame(50, $calls['cand-model:answer:low'] ?? 0);
        $this->assertSame(50, $calls['inc-model:answer:off'] ?? 0);
        $this->assertSame(
            ['model' => 'cand-model', 'reasoning_effort' => 'low'],
            roles::writes(roles::CHAT, 'cand-model', ''),
            'The switch writes the level that was measured.'
        );
    }

    public function test_an_emergency_stop_skips_the_run(): void {
        set_config('emergency_chat_disabled', 1, 'local_ai_course_assistant');
        [, $eid] = $this->queued();
        $this->assertSame(evaluator::SKIPPED, $this->evaluator()->run($eid)->status);
        $this->assertSame([], fake_eval_provider::$calls);
    }
}
