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

namespace local_ai_course_assistant\task;

use local_ai_course_assistant\struggle_classifier;
use local_ai_course_assistant\learner_memory_manager;

/**
 * Tests for the struggle_signal_review scheduled task (v5.3.18).
 *
 * The most important assertion in this file is the privacy guarantee:
 * the task NEVER writes to the outreach_log. Struggle signals stay
 * inside the chat by design — no email is ever sent about a struggle
 * session. If anyone in the future accidentally adds an email path to
 * this code, this test fails.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\task\struggle_signal_review
 */
final class struggle_signal_review_test extends \advanced_testcase {

    public function test_disabled_classifier_short_circuits(): void {
        $this->resetAfterTest();
        global $DB;
        set_config('struggle_classifier_enabled', '0', 'local_ai_course_assistant');

        // Plant some signal rows that would otherwise be processed.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $now = time();
        for ($i = 0; $i < 4; $i++) {
            $DB->insert_record('local_ai_course_assistant_struggle_signal', (object)[
                'userid' => $user->id, 'courseid' => $course->id,
                'session_id' => 'sess-disabled', 'topic_hint' => 'Photosynthesis',
                'stage1_score' => 2, 'stage2_label' => 'unprocessed',
                'followup_sent_at' => 0, 'timecreated' => $now,
            ]);
        }

        $task = new struggle_signal_review();
        ob_start();
        $task->execute();
        ob_end_clean();

        // Signals must remain unprocessed when the classifier is off.
        $unprocessed = $DB->count_records('local_ai_course_assistant_struggle_signal',
            ['stage2_label' => 'unprocessed']);
        $this->assertEquals(4, $unprocessed);
    }

    public function test_enabled_classifier_writes_memory_note(): void {
        $this->resetAfterTest();
        global $DB;
        set_config('struggle_classifier_enabled', '1', 'local_ai_course_assistant');
        set_config('memory_feature_enabled', '1', 'local_ai_course_assistant');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        for ($i = 0; $i < 4; $i++) {
            struggle_classifier::record_stage1($user->id, $course->id,
                'sess-enabled', 'Cellular respiration', 2);
        }

        $task = new struggle_signal_review();
        ob_start();
        $task->execute();
        ob_end_clean();

        // Memory note must have been recorded for the frustrated session.
        $notes = learner_memory_manager::get_notes($user->id, $course->id);
        $this->assertCount(1, $notes['sticking']);
        $this->assertEquals('Cellular respiration', $notes['sticking'][0]['topic']);
    }

    /**
     * Critical privacy guarantee: this task NEVER writes outreach.
     * Struggle signals stay inside the chat by design — no email is
     * ever sent about a struggle session. This is a hard contract.
     */
    public function test_task_never_writes_outreach_log(): void {
        $this->resetAfterTest();
        global $DB;
        set_config('struggle_classifier_enabled', '1', 'local_ai_course_assistant');
        set_config('memory_feature_enabled', '1', 'local_ai_course_assistant');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        for ($i = 0; $i < 5; $i++) {
            struggle_classifier::record_stage1($user->id, $course->id,
                'sess-no-email', 'Topic', 3);
        }

        $task = new struggle_signal_review();
        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertEquals(0,
            $DB->count_records('local_ai_course_assistant_outreach_log'),
            'struggle_signal_review must NEVER write to outreach_log. '
            . 'Struggle signals stay inside the chat by design.');
    }

    public function test_old_signals_purged(): void {
        $this->resetAfterTest();
        global $DB;
        set_config('struggle_classifier_enabled', '1', 'local_ai_course_assistant');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        // Plant a row older than the 7-day TTL.
        $oldtime = time() - (8 * 86400);
        $DB->insert_record('local_ai_course_assistant_struggle_signal', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'session_id' => 'old-sess', 'topic_hint' => 'Old topic',
            'stage1_score' => 2, 'stage2_label' => 'fine',
            'followup_sent_at' => 0, 'timecreated' => $oldtime,
        ]);

        $task = new struggle_signal_review();
        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertEquals(0,
            $DB->count_records('local_ai_course_assistant_struggle_signal',
                ['session_id' => 'old-sess']),
            'Signals older than the TTL must be purged by the task.');
    }
}
