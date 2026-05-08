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
 * Tests for struggle_classifier (v5.3.0 / regression coverage v5.3.8).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\struggle_classifier
 */
final class struggle_classifier_test extends \advanced_testcase {

    public function test_stage1_score_zero_for_neutral_message(): void {
        $this->assertEquals(0, struggle_classifier::stage1_score('Hello, can you help me with chapter 3?'));
    }

    public function test_stage1_score_two_for_explicit_phrase(): void {
        $this->assertEquals(2, struggle_classifier::stage1_score("I don't get this at all."));
        $this->assertEquals(2, struggle_classifier::stage1_score('this makes no sense'));
        $this->assertEquals(2, struggle_classifier::stage1_score("I'm so lost"));
    }

    public function test_stage1_score_capped_at_three(): void {
        $msg = "I don't get this. I'm so lost. I cant do this. I won't ever understand?? Why??";
        $this->assertLessThanOrEqual(3, struggle_classifier::stage1_score($msg));
    }

    public function test_stage1_score_pure_function_no_db_writes(): void {
        $this->resetAfterTest();
        global $DB;
        $before = $DB->count_records('local_ai_course_assistant_struggle_signal');
        struggle_classifier::stage1_score('I dont understand this at all');
        $after = $DB->count_records('local_ai_course_assistant_struggle_signal');
        $this->assertEquals($before, $after, 'stage1_score must not write to the database.');
    }

    public function test_record_stage1_skipped_when_classifier_disabled(): void {
        $this->resetAfterTest();
        global $DB;
        set_config('struggle_classifier_enabled', '0', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        struggle_classifier::record_stage1($user->id, $course->id, 'sess-1', 'Topic', 2);

        $this->assertEquals(0, $DB->count_records('local_ai_course_assistant_struggle_signal'));
    }

    public function test_record_stage1_skipped_when_score_zero(): void {
        $this->resetAfterTest();
        global $DB;
        set_config('struggle_classifier_enabled', '1', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        struggle_classifier::record_stage1($user->id, $course->id, 'sess-1', 'Topic', 0);

        $this->assertEquals(0, $DB->count_records('local_ai_course_assistant_struggle_signal'));
    }

    public function test_record_stage1_inserts_unprocessed_row(): void {
        $this->resetAfterTest();
        global $DB;
        set_config('struggle_classifier_enabled', '1', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        struggle_classifier::record_stage1($user->id, $course->id, 'sess-abc', 'Photosynthesis', 2);

        $row = $DB->get_record('local_ai_course_assistant_struggle_signal',
            ['userid' => $user->id, 'courseid' => $course->id]);
        $this->assertNotFalse($row);
        $this->assertEquals('unprocessed', $row->stage2_label);
        $this->assertEquals(2, $row->stage1_score);
        $this->assertEquals('sess-abc', $row->session_id);
        $this->assertEquals('Photosynthesis', $row->topic_hint);
    }

    public function test_process_pending_writes_sticking_point_for_frustrated_session(): void {
        $this->resetAfterTest();
        set_config('struggle_classifier_enabled', '1', 'local_ai_course_assistant');
        set_config('memory_feature_enabled', '1', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        // Threshold is 3 candidates per session with maxscore >= 2.
        for ($i = 0; $i < 4; $i++) {
            struggle_classifier::record_stage1($user->id, $course->id, 'sess-X',
                'Cellular respiration', 2);
        }

        $notesrecorded = struggle_classifier::process_pending();

        $this->assertEquals(1, $notesrecorded);
        $notes = learner_memory_manager::get_notes($user->id, $course->id);
        $this->assertCount(1, $notes['sticking']);
        $this->assertEquals('Cellular respiration', $notes['sticking'][0]['topic']);
    }

    public function test_process_pending_does_not_write_for_below_threshold_session(): void {
        $this->resetAfterTest();
        set_config('struggle_classifier_enabled', '1', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        // Two candidates is below the threshold of three.
        struggle_classifier::record_stage1($user->id, $course->id, 'sess-Y', 'Topic', 2);
        struggle_classifier::record_stage1($user->id, $course->id, 'sess-Y', 'Topic', 2);

        $notesrecorded = struggle_classifier::process_pending();

        $this->assertEquals(0, $notesrecorded);
        $notes = learner_memory_manager::get_notes($user->id, $course->id);
        $this->assertSame([], $notes['sticking']);
    }

    /**
     * Critical privacy guarantee: the struggle classifier must never
     * trigger an outreach email. Output goes to learner_memory only.
     */
    public function test_process_pending_never_writes_outreach_log(): void {
        $this->resetAfterTest();
        global $DB;
        set_config('struggle_classifier_enabled', '1', 'local_ai_course_assistant');
        set_config('memory_feature_enabled', '1', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        for ($i = 0; $i < 4; $i++) {
            struggle_classifier::record_stage1($user->id, $course->id, 'sess-Z',
                'Topic', 2);
        }

        struggle_classifier::process_pending();

        $this->assertEquals(0, $DB->count_records('local_ai_course_assistant_outreach_log'),
            'Struggle classifier must NEVER write to the outreach log — the only path '
            . 'is a private memory note.');
    }
}
