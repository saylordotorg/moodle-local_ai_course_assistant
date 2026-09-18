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

namespace local_ai_course_assistant\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy coverage for Soapbox presentation recordings (v6.8.20). The sbx_rec
 * table is keyed on the assignment, not directly on the course, so context
 * discovery, export, and erasure all go through a join; this pins that.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\privacy\provider
 */
final class provider_soapbox_test extends \advanced_testcase {
    /**
     * Seed an assignment and one recording for a user. Returns [course, user, recid].
     *
     * @return array
     */
    private function seed(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $now = time();
        $assignid = $DB->insert_record('local_ai_course_assistant_sbx_assign', (object) [
            'courseid' => $course->id, 'name' => 'Pitch', 'intro' => '', 'introformat' => 1,
            'ptype' => 'persuasive', 'mode' => 'video', 'min_seconds' => 60, 'max_seconds' => 300,
            'max_attempts' => 0, 'stored_attempts' => 2, 'visible' => 1, 'usermodified' => $user->id,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $recid = $DB->insert_record('local_ai_course_assistant_sbx_rec', (object) [
            'assignid' => $assignid, 'userid' => $user->id, 'topicid' => null, 'mode' => 'video',
            'storage_key' => 'soapbox/' . $course->id . '/' . $user->id . '/x.mp4',
            'frames_key' => 'soapbox/' . $course->id . '/' . $user->id . '/frames/x.jpg',
            'duration_seconds' => 120, 'size_bytes' => 999, 'status' => 'scored',
            'transcript' => 'Hello, this is my persuasive pitch about recycling.',
            'scoreid' => null, 'expires_at' => $now + 604800, 'timecreated' => $now,
        ]);
        return [$course, $user, $recid];
    }

    public function test_context_discovery_finds_recording_only_user(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->seed();
        $contexts = array_map('intval', provider::get_contexts_for_userid((int) $user->id)->get_contextids());
        $coursectx = \context_course::instance($course->id);
        $this->assertContains((int) $coursectx->id, $contexts);
    }

    public function test_export_includes_recording_transcript(): void {
        $this->resetAfterTest();
        [$course, $user, $recid] = $this->seed();
        $ctx = \context_course::instance($course->id);
        $this->setUser($user);
        provider::export_user_data(
            new approved_contextlist($user, 'local_ai_course_assistant', [$ctx->id])
        );
        $writer = writer::with_context($ctx);
        $this->assertTrue($writer->has_any_data());
        $data = $writer->get_data(
            [get_string('pluginname', 'local_ai_course_assistant'), 'soapbox_recordings', $recid]
        );
        $this->assertNotEmpty($data);
        $this->assertStringContainsString('persuasive pitch', $data->transcript);
    }

    public function test_erase_removes_recording_row(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $user] = $this->seed();
        $ctx = \context_course::instance($course->id);
        // Storage is unconfigured in the test, so no object delete is attempted.
        provider::delete_data_for_user(
            new approved_contextlist($user, 'local_ai_course_assistant', [$ctx->id])
        );
        $this->assertEquals(0, $DB->count_records(
            'local_ai_course_assistant_sbx_rec',
            ['userid' => $user->id]
        ));
    }

    /**
     * Erasure collects every object key on the row, not just the media.
     *
     * The failure this guards is silent and permanent. purge_soapbox_recordings()
     * deletes the objects it selected and then deletes the row, so any key it did
     * not select is stranded in the bucket with nothing left pointing at it: the
     * cleanup task walks sbx_rec rows, and there is no longer a row. For
     * frames_key that means a contact sheet of stills of the learner's face
     * surviving their own erasure request.
     *
     * Storage is unconfigured under test, so the delete calls are skipped and
     * this cannot assert on the bucket. What it can assert is the part that
     * actually regressed: the SELECT carries every key column, so the delete
     * loop has something to iterate. A new key column added without touching
     * the query fails here.
     *
     * @return void
     */
    public function test_erasure_selects_every_object_key_on_the_row(): void {
        $src = file_get_contents(__DIR__ . '/../../classes/privacy/provider.php');
        $this->assertNotFalse($src);

        $start = strpos($src, 'function purge_soapbox_recordings');
        $this->assertNotFalse($start, 'purge_soapbox_recordings has been renamed');
        $select = substr($src, $start, 2600);

        foreach (['r.storage_key', 'r.deck_key', 'r.frames_key'] as $col) {
            $this->assertStringContainsString(
                $col,
                $select,
                $col . ' is not selected by the erasure query, so its object would be stranded'
            );
        }
        foreach (['$rec->storage_key', '$rec->deck_key', '$rec->frames_key'] as $prop) {
            $this->assertStringContainsString(
                $prop,
                $select,
                $prop . ' is not passed to the object delete loop'
            );
        }
    }
}
