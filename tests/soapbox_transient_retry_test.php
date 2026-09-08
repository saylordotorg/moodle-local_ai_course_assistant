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
 * Transient scoring failures must retry, not burn a learner attempt (v7.3.3).
 *
 * The score_recording adhoc task's docblock promised transient failures would
 * be "retried by the task runner", but the scorer never threw: any STT
 * non-200 -- a cold start, a 429, a timeout -- marked the row 'failed', the
 * task reported success so nothing retried, and both attempt-cap queries
 * counted the failed row. One Whisper hiccup permanently consumed one of the
 * learner's (default three) recordings.
 *
 * These tests run with STT unconfigured, which exercises the transcription-
 * failure path with no network involved.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\soapbox_scorer::score_recording
 */
final class soapbox_transient_retry_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Seed an assignment and an uploaded recording; return the rec id.
     *
     * @return int
     */
    private function seed_uploaded_recording(): int {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assignid = $DB->insert_record('local_ai_course_assistant_sbx_assign', (object) [
            'courseid' => $course->id, 'name' => 'Talk', 'intro' => '', 'introformat' => 1,
            'ptype' => 'speech', 'mode' => 'audio', 'min_seconds' => 10, 'max_seconds' => 300,
            'max_attempts' => 3, 'stored_attempts' => 1, 'rubricid' => 0,
            'speaking_level' => 'general', 'slides_enabled' => 0, 'slide_vision' => 0,
            'visible' => 1, 'usermodified' => 2, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        return (int) $DB->insert_record('local_ai_course_assistant_sbx_rec', (object) [
            'assignid' => $assignid, 'userid' => $user->id, 'topicid' => null,
            'mode' => 'audio', 'storage_key' => 'soapbox/' . $course->id . '/' . $user->id . '/x.webm',
            'duration_seconds' => 60, 'size_bytes' => 100000, 'status' => 'uploaded',
            'transcript' => null, 'deck_key' => null, 'slide_timeline' => null,
            'scoreid' => null, 'expires_at' => time() + DAYSECS, 'timecreated' => time(),
        ]);
    }

    /**
     * With retry enabled, a transcription failure must THROW so the adhoc
     * runner reschedules -- and must leave the row 'uploaded', not 'failed'.
     */
    public function test_transient_transcription_failure_throws_for_retry(): void {
        global $DB;
        $recid = $this->seed_uploaded_recording();

        try {
            soapbox_scorer::score_recording($recid, true);
            $this->fail('expected a moodle_exception so the task runner retries');
        } catch (\moodle_exception $e) {
            // Expected.
            $this->assertNotEmpty($e);
        }

        $this->assertSame('uploaded',
            $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $recid]),
            'the row must stay uploaded while retries are pending');
    }

    /**
     * After the retry window closes, the same failure marks the row failed --
     * a permanently unconfigured STT server must not loop forever.
     */
    public function test_final_failure_marks_the_row_failed(): void {
        global $DB;
        $recid = $this->seed_uploaded_recording();

        soapbox_scorer::score_recording($recid, false);

        $this->assertSame('failed',
            $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $recid]));
    }
}
