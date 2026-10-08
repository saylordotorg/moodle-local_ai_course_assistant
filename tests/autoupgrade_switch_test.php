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

use local_ai_course_assistant\autoupgrade\candidates;
use local_ai_course_assistant\autoupgrade\evaluator;
use local_ai_course_assistant\autoupgrade\roles;
use local_ai_course_assistant\autoupgrade\switcher;
use local_ai_course_assistant\autoupgrade\watcher;

/**
 * v7.8.0 switching, its guards, the 48-hour watch and rollback.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\autoupgrade\switcher
 * @covers     \local_ai_course_assistant\autoupgrade\watcher
 */
final class autoupgrade_switch_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('provider', 'gemini', 'local_ai_course_assistant');
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');
    }

    /**
     * A candidate with a completed, passing evaluation against the current chat model.
     *
     * @param string $role
     * @param string $provider
     * @param string $model
     * @param string $variant
     * @param string $incumbent
     * @return array [candidate row, evaluation row]
     */
    private function passed(
        string $role = roles::CHAT,
        string $provider = 'gemini',
        string $model = 'gemini-3.5-flash-lite',
        string $variant = '',
        string $incumbent = 'gemini-2.5-flash'
    ): array {
        global $DB;
        $cid = candidates::upsert($role, $provider, $model, $variant, 'test');
        $eid = (int) $DB->insert_record(evaluator::TABLE, (object) [
            'candidateid' => $cid, 'role' => $role, 'provider' => $provider, 'model' => $model, 'variant' => $variant,
            'inc_provider' => $provider, 'inc_model' => $incumbent, 'inc_variant' => '', 'status' => evaluator::COMPLETE,
            'actual_cost_usd' => 0.5, 'gate_passed' => 1, 'gate_detail' => json_encode(['cost' => ['ok' => true, 'detail' => 'x']]),
            'metrics' => json_encode(['candidate' => ['quality' => 14.3, 'cost_cents' => 0.18]]), 'timecreated' => time(),
        ]);
        candidates::record_verdict($cid, true, $eid, 1);
        return [candidates::get($cid), $DB->get_record(evaluator::TABLE, ['id' => $eid])];
    }

    /**
     * Assistant rows for a model, as the msgs table would hold them.
     *
     * @param string $model
     * @param int $n
     * @param int $errors
     * @param int $when
     * @return void
     */
    private function traffic(string $model, int $n, int $errors, int $when): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $conv = $DB->insert_record('local_ai_course_assistant_convs', (object) ['userid' => $user->id,
            'courseid' => $course->id, 'title' => '', 'timecreated' => $when, 'timemodified' => $when]);
        for ($i = 0; $i < $n; $i++) {
            $failed = $i < $errors;
            $DB->insert_record('local_ai_course_assistant_msgs', (object) [
                'conversationid' => $conv, 'userid' => $user->id, 'courseid' => $course->id, 'role' => 'assistant',
                'message' => 'a', 'tokens_used' => 0, 'prompt_tokens' => $failed ? 0 : 5000,
                'completion_tokens' => $failed ? 0 : 300, 'model_name' => $model, 'provider' => 'gemini',
                'interaction_type' => 'chat', 'stream_outcome' => $failed ? 'provider_error' : 'complete',
                'timecreated' => $when + $i,
            ]);
        }
    }

    public function test_a_switch_writes_only_the_site_model_and_records_the_old_value(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('local_ai_course_assistant_course_cfg', (object) ['courseid' => $course->id,
            'provider' => 'gemini', 'model' => 'gemini-2.5-pro', 'timecreated' => time(), 'timemodified' => time()]);
        [$cand, $eval] = $this->passed();
        $out = switcher::switch_to($cand, $eval, 'auto', 0);
        $this->assertTrue($out['ok'], $out['message']);
        $this->assertSame('gemini-3.5-flash-lite', get_config('local_ai_course_assistant', 'model'));
        $this->assertSame('gemini', get_config('local_ai_course_assistant', 'provider'));
        $this->assertSame('gemini-2.5-pro', $DB->get_field(
            'local_ai_course_assistant_course_cfg',
            'model',
            ['courseid' => $course->id]
        ), 'A course with its own model keeps it.');
        $row = $DB->get_record(switcher::TABLE, []);
        $this->assertSame(['model' => 'gemini-2.5-flash'], json_decode($row->prevconfig, true));
    }

    public function test_the_thinking_off_variant_writes_the_reasoning_setting(): void {
        [$cand, $eval] = $this->passed(roles::CHAT, 'gemini', 'gemini-2.5-flash', roles::VARIANT_THINKING_OFF);
        $this->assertTrue(switcher::switch_to($cand, $eval, 'auto', 0)['ok']);
        $this->assertSame('off', get_config('local_ai_course_assistant', 'reasoning_effort'));
        $this->assertSame('gemini-2.5-flash', get_config('local_ai_course_assistant', 'model'));
    }

    public function test_the_premium_tier_changes_its_model_and_never_its_triggers(): void {
        set_config('premium_escalation_enabled', 1, 'local_ai_course_assistant');
        set_config('premium_escalation_provider', 'claude', 'local_ai_course_assistant');
        set_config('premium_escalation_model', 'claude-sonnet-5', 'local_ai_course_assistant');
        set_config('premium_escalation_triggers', 'derive', 'local_ai_course_assistant');
        [$cand, $eval] = $this->passed(roles::PREMIUM, 'claude', 'claude-sonnet-5-5', '', 'claude-sonnet-5');
        $this->assertTrue(switcher::switch_to($cand, $eval, 'auto', 0)['ok']);
        $this->assertSame('claude-sonnet-5-5', get_config('local_ai_course_assistant', 'premium_escalation_model'));
        $this->assertSame('derive', get_config('local_ai_course_assistant', 'premium_escalation_triggers'));
    }

    public function test_no_switch_during_any_emergency_control(): void {
        [$cand, $eval] = $this->passed();
        emergency_control::disable([emergency_control::FLAG_RAG], 'test', 'test');
        $out = switcher::switch_to($cand, $eval, 'auto', 0);
        $this->assertFalse($out['ok']);
        $this->assertSame('gemini-2.5-flash', get_config('local_ai_course_assistant', 'model'));
    }

    public function test_the_failover_role_is_never_switched_automatically(): void {
        set_config('comparison_providers', 'openai|sk-test|gpt-4o-mini', 'local_ai_course_assistant');
        set_config('spend_failover_chain', 'chat:openai', 'local_ai_course_assistant');
        [$cand, $eval] = $this->passed(roles::FAILOVER, 'openai', 'gpt-6-luna', '', 'gpt-4o-mini');
        $out = switcher::switch_to($cand, $eval, 'auto', 0);
        $this->assertFalse($out['ok']);
        $this->assertSame(
            'openai|sk-test|gpt-4o-mini',
            get_config('local_ai_course_assistant', 'comparison_providers'),
            'The setting that holds an API key is never written.'
        );
    }

    public function test_a_setting_the_policy_bundle_manages_is_not_switched(): void {
        set_config('policy_bundle_enabled', 1, 'local_ai_course_assistant');
        set_config('policy_bundle_managed_keys', 'model,provider', 'local_ai_course_assistant');
        [$cand, $eval] = $this->passed();
        $this->assertFalse(switcher::switch_to($cand, $eval, 'auto', 0)['ok']);
        set_config('policy_bundle_managed_keys', 'cost_anomaly_multiplier', 'local_ai_course_assistant');
        $this->assertTrue(switcher::switch_to($cand, $eval, 'auto', 0)['ok']);
    }

    public function test_a_model_changed_since_the_evaluation_is_not_switched(): void {
        [$cand, $eval] = $this->passed();
        set_config('model', 'gemini-2.5-pro', 'local_ai_course_assistant');
        $this->assertFalse(switcher::switch_to($cand, $eval, 'auto', 0)['ok']);
    }

    public function test_rollback_restores_the_old_model(): void {
        global $DB;
        $sink = $this->redirectEmails();
        [$cand, $eval] = $this->passed();
        $id = switcher::switch_to($cand, $eval, 'auto', 0)['switchid'];
        $out = switcher::rollback($id, 'test', 2);
        $this->assertTrue($out['ok']);
        $this->assertSame('gemini-2.5-flash', get_config('local_ai_course_assistant', 'model'));
        $this->assertSame(switcher::ROLLEDBACK, $DB->get_field(switcher::TABLE, 'status', ['id' => $id]));
        $this->assertSame(candidates::ROLLEDBACK, candidates::get((int) $cand->id)->status);
        $this->assertGreaterThanOrEqual(2, count($sink->get_messages()));
    }

    public function test_rollback_never_overwrites_a_later_human_change(): void {
        global $DB;
        [$cand, $eval] = $this->passed();
        $id = switcher::switch_to($cand, $eval, 'auto', 0)['switchid'];
        set_config('model', 'gemini-2.5-pro', 'local_ai_course_assistant');
        $this->assertFalse(switcher::rollback($id, 'test', 0)['ok']);
        $this->assertSame('gemini-2.5-pro', get_config('local_ai_course_assistant', 'model'));
        $this->assertSame(switcher::SUPERSEDED, $DB->get_field(switcher::TABLE, 'status', ['id' => $id]));
    }

    public function test_the_watcher_rolls_back_an_error_spike(): void {
        global $DB;
        $now = time();
        $this->traffic('gemini-2.5-flash', 200, 2, $now - 3 * DAYSECS);
        [$cand, $eval] = $this->passed();
        $id = switcher::switch_to($cand, $eval, 'auto', 0)['switchid'];
        $this->traffic('gemini-3.5-flash-lite', 100, 15, $now + 10);
        $this->assertSame('rolledback', watcher::check_one($DB->get_record(switcher::TABLE, ['id' => $id]), $now + 3600));
        $this->assertSame('gemini-2.5-flash', get_config('local_ai_course_assistant', 'model'));
    }

    public function test_the_watcher_keeps_a_healthy_switch_after_48_hours(): void {
        global $DB;
        $now = time();
        $this->traffic('gemini-2.5-flash', 200, 2, $now - 3 * DAYSECS);
        [$cand, $eval] = $this->passed();
        $id = switcher::switch_to($cand, $eval, 'auto', 0)['switchid'];
        $this->traffic('gemini-3.5-flash-lite', 100, 1, $now + 10);
        $row = $DB->get_record(switcher::TABLE, ['id' => $id]);
        $this->assertSame('watching', watcher::check_one($row, $now + 3600));
        $this->assertSame('kept', watcher::check_one($row, $now + 49 * HOURSECS));
        $this->assertSame('gemini-3.5-flash-lite', get_config('local_ai_course_assistant', 'model'));
    }

    public function test_decide_needs_a_real_rise_not_noise(): void {
        $base = ['turns' => 200, 'error' => 2, 'truncated' => 0, 'refused' => 0, 'cost_cents' => 0.30];
        $this->assertNull(watcher::decide($base, ['turns' => 29, 'error' => 29, 'truncated' => 0, 'refused' => 0,
            'cost_cents' => 0.3]), 'Nothing is judged before 30 live answers.');
        $this->assertNull(watcher::decide($base, ['turns' => 100, 'error' => 2, 'truncated' => 0, 'refused' => 0,
            'cost_cents' => 0.3]), 'Two errors in 100 is the baseline rate.');
        $this->assertNotNull(watcher::decide($base, ['turns' => 100, 'error' => 10, 'truncated' => 0, 'refused' => 0,
            'cost_cents' => 0.3]));
        $this->assertNotNull(watcher::decide($base, ['turns' => 100, 'error' => 1, 'truncated' => 9, 'refused' => 0,
            'cost_cents' => 0.3]), 'A truncation spike rolls back.');
        $this->assertNotNull(watcher::decide($base, ['turns' => 100, 'error' => 1, 'truncated' => 0, 'refused' => 0,
            'cost_cents' => 0.40]), 'Cost more than 1.25x the baseline rolls back.');
        $this->assertNull(watcher::decide($base, ['turns' => 100, 'error' => 1, 'truncated' => 0, 'refused' => 0,
            'cost_cents' => 0.37]));
        $nobase = ['turns' => 3, 'error' => 0, 'truncated' => 0, 'refused' => 0, 'cost_cents' => null];
        $this->assertNotNull(watcher::decide($nobase, ['turns' => 40, 'error' => 5, 'truncated' => 0, 'refused' => 0,
            'cost_cents' => null]), 'With no baseline, a 10% error floor still applies.');
    }

    public function test_a_refusal_is_its_own_outcome(): void {
        $this->assertSame('refused', conversation_manager::turn_outcome(false, 'refusal'));
        $this->assertSame('refused', conversation_manager::turn_outcome(false, 'content_filter'));
        $this->assertSame('complete', conversation_manager::turn_outcome(false, 'stop'));
        $this->assertSame('truncated', conversation_manager::turn_outcome(false, 'length'));
    }
}
