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
 * Tests for outreach_sender (v5.3.0 / regression coverage v5.3.8).
 *
 * The privacy posture of this class is the entire reason it exists, so
 * the bulk of these tests assert what it does NOT do under various
 * configurations — the full sequence of six gates each call must clear
 * before any email leaves the building.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\outreach_sender
 */
final class outreach_sender_test extends \advanced_testcase {

    /**
     * Common helper: enable everything that needs to be on, except the
     * specific gate under test.
     */
    private function enable_everything(int $userid): void {
        set_config('outreach_master_enabled', '1', 'local_ai_course_assistant');
        set_config('milestones_feature_enabled', '1', 'local_ai_course_assistant');
        set_user_preferences(['sola_outreach_milestones' => '1'], $userid);
    }

    public function test_master_kill_switch_blocks_send(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->enable_everything($user->id);
        set_config('outreach_master_enabled', '0', 'local_ai_course_assistant');
        // Dry-run too, so even if it slipped past nothing would actually email.
        set_config('outreach_dryrun', '1', 'local_ai_course_assistant');

        $ok = outreach_sender::send($user->id, $course->id,
            outreach_sender::CH_STREAK7, 'subj', 'text', 'html', 'reason');

        $this->assertFalse($ok);
    }

    public function test_per_channel_admin_disable_blocks_send(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->enable_everything($user->id);
        set_config('milestones_feature_enabled', '0', 'local_ai_course_assistant');
        set_config('outreach_dryrun', '1', 'local_ai_course_assistant');

        $ok = outreach_sender::send($user->id, $course->id,
            outreach_sender::CH_STREAK7, 'subj', 'text', 'html', 'reason');

        $this->assertFalse($ok);
    }

    public function test_learner_consent_default_off_blocks_send(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->enable_everything($user->id);
        // Explicitly remove consent.
        set_user_preferences(['sola_outreach_milestones' => '0'], $user->id);
        set_config('outreach_dryrun', '1', 'local_ai_course_assistant');

        $ok = outreach_sender::send($user->id, $course->id,
            outreach_sender::CH_STREAK7, 'subj', 'text', 'html', 'reason');

        $this->assertFalse($ok);
    }

    public function test_dryrun_succeeds_without_emailing(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->enable_everything($user->id);
        set_config('outreach_dryrun', '1', 'local_ai_course_assistant');

        $ok = outreach_sender::send($user->id, $course->id,
            outreach_sender::CH_STREAK7, 'subj', 'text', 'html', 'streak7 reason');

        $this->assertTrue($ok);
        $row = $DB->get_record('local_ai_course_assistant_outreach_log',
            ['userid' => $user->id]);
        $this->assertNotFalse($row);
        $this->assertEquals('streak7 reason', $row->trigger_reason);
        $this->assertStringStartsWith('dryrun_', $row->message_id);
    }

    public function test_cooldown_blocks_second_send_within_window(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->enable_everything($user->id);
        set_config('outreach_dryrun', '1', 'local_ai_course_assistant');

        $first = outreach_sender::send($user->id, $course->id,
            outreach_sender::CH_STREAK7, 'subj', 'text', 'html', 'first');
        $second = outreach_sender::send($user->id, $course->id,
            outreach_sender::CH_STREAK30, 'subj', 'text', 'html', 'second');

        $this->assertTrue($first);
        $this->assertFalse($second,
            'Cooldown is 7 days across ALL channels. Second send must be blocked.');
    }

    public function test_cooldown_clear_returns_true_for_fresh_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->assertTrue(outreach_sender::cooldown_clear($user->id));
    }

    public function test_get_log_for_learner_returns_only_their_rows(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $now = time();
        $DB->insert_record('local_ai_course_assistant_outreach_log', (object)[
            'userid' => $user1->id, 'courseid' => $course->id,
            'channel' => outreach_sender::CH_STREAK7,
            'trigger_reason' => 'r1', 'message_id' => 'm1', 'timesent' => $now,
        ]);
        $DB->insert_record('local_ai_course_assistant_outreach_log', (object)[
            'userid' => $user2->id, 'courseid' => $course->id,
            'channel' => outreach_sender::CH_STREAK7,
            'trigger_reason' => 'r2', 'message_id' => 'm2', 'timesent' => $now,
        ]);

        $rows = outreach_sender::get_log_for_learner($user1->id);

        $this->assertCount(1, $rows);
        $this->assertEquals($user1->id, reset($rows)->userid);
    }

    /**
     * v5.3.0 design choice: struggle channel was removed entirely from
     * outreach_sender so no email about a struggle session can ever be
     * sent. This test enforces that the constant does not exist.
     */
    public function test_struggle_channel_does_not_exist_on_outreach_sender(): void {
        $this->assertFalse(defined('\local_ai_course_assistant\outreach_sender::CH_STRUGGLE'),
            'Struggle channel must NEVER be reintroduced to outreach_sender. '
            . 'Struggle signals stay inside the chat by design (private memory note).');
    }
}
