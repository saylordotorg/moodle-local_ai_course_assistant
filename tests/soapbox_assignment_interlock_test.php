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

require_once(__DIR__ . '/fixtures/presenterai_map_table.php');

/**
 * An editing teacher cannot delete the media of a recording PresenterAI's importer holds.
 *
 * After a migration the obvious tidy-up is to delete the old Soapbox assignment, and anyone with the manage
 * capability can. That used to delete every stored object of every recording on it, so each migrated PresenterAI
 * attempt would have pointed at a deleted file. delete_assignment() now refuses, deleting nothing, while the
 * importer holds any recording of the assignment, and refuses just the same when the lookup fails. Course deletion
 * and privacy erasure keep deleting.
 *
 * The object store is observed through Moodle's curl test hook: each S3 delete consumes one queued mock response,
 * so the number of responses left says how many deletes were attempted, with no network and no production seam.
 *
 * @package    local_ai_course_assistant
 * @covers     \local_ai_course_assistant\soapbox_assignment_manager
 */
final class soapbox_assignment_interlock_test extends \advanced_testcase {
    use presenterai_map_table;

    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $teacher;

    /**
     * An enrolled editing teacher, and Soapbox storage configured so object deletes are attempted.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->set_curl_mocks([]);
        set_config('soapbox_storage_key', 'AKIATESTKEY', 'local_ai_course_assistant');
        set_config('soapbox_storage_secret', 'testsecret', 'local_ai_course_assistant');
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($this->teacher);
    }

    /**
     * Leave no queued curl responses and no temporary map table for another test.
     *
     * @return void
     */
    protected function tearDown(): void {
        $this->set_curl_mocks([]);
        $this->drop_temp_map_table();
        parent::tearDown();
    }

    /**
     * Replace Moodle's queue of mocked curl responses.
     *
     * @param array $responses
     * @return void
     */
    private function set_curl_mocks(array $responses): void {
        $property = new \ReflectionProperty(\curl::class, 'mockresponses');
        $property->setAccessible(true);
        $property->setValue(null, $responses);
    }

    /**
     * Queue one mocked response per S3 request the test expects.
     *
     * @param int $count
     * @return void
     */
    private function queue_s3_responses(int $count): void {
        $this->set_curl_mocks(array_fill(0, $count, ''));
    }

    /**
     * How many queued responses no request has consumed.
     *
     * @return int
     */
    private function s3_responses_left(): int {
        $property = new \ReflectionProperty(\curl::class, 'mockresponses');
        $property->setAccessible(true);
        return count($property->getValue());
    }

    /**
     * An assignment in the test course with one topic.
     *
     * @return int The assignment id.
     */
    private function assignment(): int {
        $id = soapbox_assignment_manager::create_assignment($this->course->id, ['name' => 'Elevator pitch']);
        soapbox_assignment_manager::save_topic($id, ['title' => 'Topic']);
        return $id;
    }

    /**
     * A recording row with all three object keys.
     *
     * @param int $assignid
     * @param string $name Short unique label used in the keys.
     * @return int The recording id.
     */
    private function recording(int $assignid, string $name): int {
        global $DB;
        return (int) $DB->insert_record('local_ai_course_assistant_sbx_rec', (object) [
            'assignid' => $assignid,
            'userid' => $this->teacher->id,
            'mode' => 'video',
            'storage_key' => "sbx/rec/$name.webm",
            'deck_key' => "sbx/deck/$name.pdf",
            'frames_key' => "sbx/frames/$name.jpg",
            'status' => 'scored',
            'expires_at' => time() + (30 * DAYSECS),
            'timecreated' => time(),
        ]);
    }

    /**
     * Mark a recording as moved to PresenterAI, the way the importer's map does.
     *
     * @param int $recid
     * @return void
     */
    private function hold(int $recid): void {
        $this->require_map_table();
        $this->map_row(presenterai_interlock::SOURCE_TABLE, $recid, presenterai_interlock::site_id());
    }

    /**
     * Assert the assignment, its topic and its recordings are all still there.
     *
     * @param int $assignid
     * @param int $recordings How many recording rows should remain.
     * @return void
     */
    private function assert_nothing_deleted(int $assignid, int $recordings): void {
        global $DB;
        $this->assertNotNull(soapbox_assignment_manager::get_assignment($assignid));
        $this->assertSame(1, $DB->count_records(soapbox_assignment_manager::T_TOPIC, ['assignid' => $assignid]));
        $this->assertSame($recordings, $DB->count_records(soapbox_assignment_manager::T_REC, ['assignid' => $assignid]));
    }

    /**
     * A held assignment is refused and nothing is deleted, rows or objects.
     *
     * @return void
     */
    public function test_a_held_assignment_is_refused_and_nothing_is_deleted(): void {
        $assignid = $this->assignment();
        $recid = $this->recording($assignid, 'held');
        $this->hold($recid);
        $this->queue_s3_responses(3);

        try {
            soapbox_assignment_manager::delete_assignment($assignid);
            $this->fail('an assignment whose recording PresenterAI holds must not be deletable');
        } catch (\moodle_exception $e) {
            $this->assertSame(soapbox_assignment_manager::ERR_HELD, $e->errorcode);
            $this->assertStringContainsString('PresenterAI', $e->getMessage());
        }

        $this->assert_nothing_deleted($assignid, 1);
        $this->assertSame(3, $this->s3_responses_left(), 'no object delete may be attempted');
    }

    /**
     * One held recording among several refuses the whole assignment.
     *
     * @return void
     */
    public function test_a_mixed_assignment_is_refused_entirely(): void {
        $assignid = $this->assignment();
        $this->recording($assignid, 'free');
        $heldrec = $this->recording($assignid, 'held');
        $this->hold($heldrec);
        $this->queue_s3_responses(6);

        try {
            soapbox_assignment_manager::delete_assignment($assignid);
            $this->fail('one held recording must refuse the whole assignment');
        } catch (\moodle_exception $e) {
            $this->assertSame(soapbox_assignment_manager::ERR_HELD, $e->errorcode);
        }

        $this->assert_nothing_deleted($assignid, 2);
        $this->assertSame(6, $this->s3_responses_left(), 'the unheld recording\'s objects stay too');
    }

    /**
     * An assignment nobody holds is deleted exactly as before, objects first.
     *
     * A map row for ANOTHER recording must not hold this one.
     *
     * @return void
     */
    public function test_an_unheld_assignment_is_deleted_as_before(): void {
        global $DB;
        $assignid = $this->assignment();
        $this->recording($assignid, 'free');
        $this->hold(987654);
        $this->queue_s3_responses(3);

        soapbox_assignment_manager::delete_assignment($assignid);

        $this->assertNull(soapbox_assignment_manager::get_assignment($assignid));
        $this->assertSame(0, $DB->count_records(soapbox_assignment_manager::T_TOPIC, ['assignid' => $assignid]));
        $this->assertSame(0, $DB->count_records(soapbox_assignment_manager::T_REC, ['assignid' => $assignid]));
        $this->assertSame(0, $this->s3_responses_left(), 'all three objects of the recording were deleted');
    }

    /**
     * An assignment with no recordings is deleted without asking the interlock.
     *
     * @return void
     */
    public function test_an_assignment_with_no_recordings_is_deleted(): void {
        $assignid = $this->assignment();

        soapbox_assignment_manager::delete_assignment($assignid);

        $this->assertNull(soapbox_assignment_manager::get_assignment($assignid));
    }

    /**
     * When the lookup fails the deletion is refused, with its own message, and nothing is deleted.
     *
     * The failure carries a fake SQL fragment: neither the refusal nor the audit row may show it, and the audit row
     * names only the exception class.
     *
     * @return void
     */
    public function test_a_failing_lookup_refuses_the_delete_and_deletes_nothing(): void {
        global $DB;
        $assignid = $this->assignment();
        $this->recording($assignid, 'free');
        $this->queue_s3_responses(3);

        $failing = new class extends soapbox_assignment_manager {
            /**
             * A lookup that throws.
             *
             * @param array $recids
             * @return array
             */
            protected static function held_recordings(array $recids): array {
                throw new \RuntimeException('SELECT secret FROM {map} WHERE token = abc123');
            }
        };

        try {
            $failing::delete_assignment($assignid);
            $this->fail('a lookup that cannot be made must refuse the deletion');
        } catch (\moodle_exception $e) {
            $this->assertSame(soapbox_assignment_manager::ERR_HELD_UNCHECKED, $e->errorcode);
            $this->assertStringNotContainsString('abc123', $e->getMessage());
            $this->assertStringNotContainsString('SELECT', $e->getMessage());
        }

        $this->assert_nothing_deleted($assignid, 1);
        $this->assertSame(3, $this->s3_responses_left());
        $rows = array_values($DB->get_records('local_ai_course_assistant_audit', ['action' => 'soapbox_delete_interlock_failed']));
        $this->assertCount(1, $rows);
        $details = json_decode($rows[0]->details, true);
        $this->assertSame('RuntimeException', $details['error_class']);
        $this->assertStringNotContainsString('abc123', $rows[0]->details);
        $this->assertSame((int) $this->teacher->id, (int) $rows[0]->userid);
    }

    /**
     * The delete page shows a refusal as an error notice and goes back to the list.
     *
     * The page is a script, so this reads its source: the delete handler must catch the two refusal codes (and only
     * those: anything else is rethrown) and redirect to the list with an error notice, before the success redirect.
     *
     * @return void
     */
    public function test_the_delete_page_turns_a_refusal_into_an_error_notice_and_returns_to_the_list(): void {
        $source = (string) file_get_contents(__DIR__ . '/../soapbox_assign.php');
        $start = strpos($source, "if (\$action === 'delete'");
        $this->assertNotFalse($start, 'the delete handler exists');
        $block = substr($source, $start, strpos($source, 'echo $OUTPUT->header();', $start) - $start);

        $call = strpos($block, 'soapbox_assignment_manager::delete_assignment($id);');
        $catch = strpos($block, 'catch (\moodle_exception $e)');
        $success = strpos($block, "get_string('soapbox:assign_deleted'");
        $this->assertNotFalse($call);
        $this->assertNotFalse($catch, 'the refusal is caught');
        $this->assertGreaterThan($call, $catch);
        $this->assertGreaterThan($catch, $success, 'the success redirect comes after the catch');
        $this->assertStringContainsString('soapbox_assignment_manager::ERR_HELD,', $block);
        $this->assertStringContainsString('soapbox_assignment_manager::ERR_HELD_UNCHECKED,', $block);
        $this->assertStringContainsString('throw $e;', $block, 'any other failure is not turned into a notice');
        $this->assertMatchesRegularExpression(
            '/redirect\(\$pageurl, \$e->getMessage\(\), null, \\\\core\\\\output\\\\notification::NOTIFY_ERROR\);/',
            $block
        );
    }

    /**
     * A user without the manage capability is refused before the interlock is asked.
     *
     * @return void
     */
    public function test_the_capability_check_still_comes_first(): void {
        $assignid = $this->assignment();
        $this->recording($assignid, 'free');
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        soapbox_assignment_manager::delete_assignment($assignid);
    }

    /**
     * Deleting a whole course still deletes a held recording's objects.
     *
     * The interlock guards the teacher's delete button, not course deletion: when a course goes, its media goes.
     * Both recordings (one held) lose all three objects, the rows go, and the map is not written to.
     *
     * @return void
     */
    public function test_course_deletion_still_deletes_a_held_recordings_objects(): void {
        global $DB;
        $assignid = $this->assignment();
        $this->recording($assignid, 'free');
        $heldrec = $this->recording($assignid, 'held');
        $this->hold($heldrec);
        $mapbefore = $DB->count_records(presenterai_interlock::MAP_TABLE);
        $this->queue_s3_responses(6);
        $this->setAdminUser();

        delete_course($this->course->id, false);

        $this->assertSame(0, $this->s3_responses_left(), 'all six objects were deleted, held or not');
        $this->assertSame(0, $DB->count_records(soapbox_assignment_manager::T_REC, ['assignid' => $assignid]));
        $this->assertSame($mapbefore, $DB->count_records(presenterai_interlock::MAP_TABLE), 'the map is never written');
    }
}
