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
 * Tests for conversation_manager.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\conversation_manager
 */
final class conversation_manager_test extends \advanced_testcase {
    public function test_get_or_create_conversation_creates_new(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);

        $this->assertNotEmpty($conv->id);
        $this->assertEquals($user->id, $conv->userid);
        $this->assertEquals($course->id, $conv->courseid);
    }

    public function test_get_or_create_conversation_returns_existing(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $conv1 = conversation_manager::get_or_create_conversation($user->id, $course->id);
        $conv2 = conversation_manager::get_or_create_conversation($user->id, $course->id);

        $this->assertEquals($conv1->id, $conv2->id);
    }

    public function test_add_and_get_messages(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);

        conversation_manager::add_message($conv->id, $user->id, $course->id, 'user', 'Hello');
        conversation_manager::add_message($conv->id, $user->id, $course->id, 'assistant', 'Hi there!');

        $messages = conversation_manager::get_messages($conv->id);
        $messages = array_values($messages);

        $this->assertCount(2, $messages);
        $this->assertEquals('user', $messages[0]->role);
        $this->assertEquals('Hello', $messages[0]->message);
        $this->assertEquals('assistant', $messages[1]->role);
        $this->assertEquals('Hi there!', $messages[1]->message);
    }

    public function test_get_history_for_api_respects_maxhistory(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);

        // Set max to 2 pairs (4 messages).
        set_config('maxhistory', '2', 'local_ai_course_assistant');

        // Add 6 messages (3 pairs).
        for ($i = 0; $i < 3; $i++) {
            conversation_manager::add_message($conv->id, $user->id, $course->id, 'user', "Q{$i}");
            conversation_manager::add_message($conv->id, $user->id, $course->id, 'assistant', "A{$i}");
        }

        $history = conversation_manager::get_history_for_api($conv->id);

        $this->assertCount(4, $history);
        // Should have the last 2 pairs.
        $this->assertEquals('Q1', $history[0]['content']);
        $this->assertEquals('A2', $history[3]['content']);
    }

    public function test_clear_conversation(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);

        conversation_manager::add_message($conv->id, $user->id, $course->id, 'user', 'Test');
        conversation_manager::clear_conversation($conv->id, $user->id);

        $this->assertFalse($DB->record_exists('local_ai_course_assistant_convs', ['id' => $conv->id]));
        $this->assertFalse($DB->record_exists('local_ai_course_assistant_msgs', ['conversationid' => $conv->id]));
    }

    public function test_clear_conversation_blocks_other_users(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $conv = conversation_manager::get_or_create_conversation($user1->id, $course->id);

        $this->expectException(\moodle_exception::class);
        conversation_manager::clear_conversation($conv->id, $user2->id);
    }

    public function test_delete_user_data(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);
        conversation_manager::add_message($conv->id, $user->id, $course->id, 'user', 'Test');

        conversation_manager::delete_user_data($user->id);

        $this->assertFalse($DB->record_exists('local_ai_course_assistant_convs', ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists('local_ai_course_assistant_msgs', ['conversationid' => $conv->id]));
    }

    /**
     * v5.3.7: delete_user_data must sweep every SOLA table that holds
     * per-user state, not just convs+msgs. Regression coverage for the
     * coverage gap Tom hit on Catalyst staging.
     */
    public function test_delete_user_data_covers_all_tables(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        // Seed at least one row in every per-user table the delete is now
        // required to clean. Each row is constructed with the fields the
        // schema actually requires (see db/install.xml).
        $now = time();
        $DB->insert_record('local_ai_course_assistant_learner_goals', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'q1_answer' => 'I want to be a nurse.', 'consented_at' => $now,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_ai_course_assistant_learner_memory', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'notes_json' => '{"sticking":[],"style_prefs":{},"last_active":0}',
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_ai_course_assistant_streak', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'current_streak_days' => 1, 'longest_streak_days' => 1,
            'last_active_date' => date('Y-m-d', $now),
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_ai_course_assistant_struggle_signal', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'session_id' => 'sess1', 'topic_hint' => 'Photosynthesis',
            'stage1_score' => 2, 'stage2_label' => 'unprocessed',
            'followup_sent_at' => 0, 'timecreated' => $now,
        ]);
        $DB->insert_record('local_ai_course_assistant_outreach_log', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'channel' => 'milestone_streak7',
            'trigger_reason' => '7-day streak', 'message_id' => 'msg1',
            'timesent' => $now,
        ]);

        // Conversation + message also seeded so the messages branch is
        // exercised end-to-end.
        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);
        conversation_manager::add_message($conv->id, $user->id, $course->id, 'user', 'Hi');

        // Sanity-check that everything was inserted.
        $this->assertEquals(1, $DB->count_records('local_ai_course_assistant_learner_goals',
            ['userid' => $user->id]));
        $this->assertEquals(1, $DB->count_records('local_ai_course_assistant_learner_memory',
            ['userid' => $user->id]));
        $this->assertEquals(1, $DB->count_records('local_ai_course_assistant_streak',
            ['userid' => $user->id]));
        $this->assertEquals(1, $DB->count_records('local_ai_course_assistant_struggle_signal',
            ['userid' => $user->id]));
        $this->assertEquals(1, $DB->count_records('local_ai_course_assistant_outreach_log',
            ['userid' => $user->id]));
        $this->assertEquals(1, $DB->count_records('local_ai_course_assistant_convs',
            ['userid' => $user->id]));

        // The act under test.
        $counts = conversation_manager::delete_user_data($user->id);

        // Every per-user table must be empty for this learner now.
        foreach ([
            'local_ai_course_assistant_convs',
            'local_ai_course_assistant_learner_goals',
            'local_ai_course_assistant_learner_memory',
            'local_ai_course_assistant_streak',
            'local_ai_course_assistant_struggle_signal',
            'local_ai_course_assistant_outreach_log',
        ] as $table) {
            $this->assertEquals(0, $DB->count_records($table, ['userid' => $user->id]),
                "Table {$table} still has rows for the user after delete_user_data");
        }
        $this->assertFalse($DB->record_exists('local_ai_course_assistant_msgs',
            ['conversationid' => $conv->id]));

        // The returned counts dict must include each table label so the
        // audit log can record the per-table impact.
        $this->assertArrayHasKey('learner_goals', $counts);
        $this->assertArrayHasKey('learner_memory', $counts);
        $this->assertArrayHasKey('streak', $counts);
        $this->assertArrayHasKey('struggle_signal', $counts);
        $this->assertArrayHasKey('outreach_log', $counts);
        $this->assertEquals(1, $counts['learner_goals']);
        $this->assertEquals(1, $counts['streak']);
    }

    /**
     * v5.3.7: delete_course_data must cover the same set of tables and
     * scope to (courseid[, userid]) — never wipe other users' data.
     */
    public function test_delete_course_data_scopes_to_user_when_supplied(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $now = time();
        foreach ([$user1->id, $user2->id] as $uid) {
            $DB->insert_record('local_ai_course_assistant_learner_goals', (object)[
                'userid' => $uid, 'courseid' => $course->id,
                'q1_answer' => 'goal', 'consented_at' => $now,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
            $DB->insert_record('local_ai_course_assistant_streak', (object)[
                'userid' => $uid, 'courseid' => $course->id,
                'current_streak_days' => 3, 'longest_streak_days' => 3,
                'last_active_date' => date('Y-m-d', $now),
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }

        // Delete only user1's data in this course.
        conversation_manager::delete_course_data($course->id, $user1->id);

        $this->assertEquals(0, $DB->count_records('local_ai_course_assistant_learner_goals',
            ['userid' => $user1->id, 'courseid' => $course->id]));
        $this->assertEquals(0, $DB->count_records('local_ai_course_assistant_streak',
            ['userid' => $user1->id, 'courseid' => $course->id]));

        // user2's data in the same course MUST remain untouched.
        $this->assertEquals(1, $DB->count_records('local_ai_course_assistant_learner_goals',
            ['userid' => $user2->id, 'courseid' => $course->id]));
        $this->assertEquals(1, $DB->count_records('local_ai_course_assistant_streak',
            ['userid' => $user2->id, 'courseid' => $course->id]));
    }
}
