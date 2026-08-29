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
 * Deleting a course must not leave plugin data behind.
 *
 * There was no observer at all, so every row and every per-course config key
 * outlived the course permanently and invisibly -- learner conversations
 * included. Course ids are reused, so an orphan can resurface against an
 * unrelated course.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\observer
 */
final class course_deleted_observer_test extends \advanced_testcase {

    public function test_course_data_and_overrides_are_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $keep = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $convid = $DB->insert_record('local_ai_course_assistant_convs', (object) [
            'userid' => $user->id, 'courseid' => $course->id,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => $convid, 'userid' => $user->id, 'courseid' => $course->id,
            'role' => 'user', 'message' => 'probe', 'timecreated' => time(),
        ]);
        set_config('socratic_mode_course_' . $course->id, '1', 'local_ai_course_assistant');

        // A neighbouring course must be untouched, including one whose id ends
        // with the deleted course's digits.
        $DB->insert_record('local_ai_course_assistant_convs', (object) [
            'userid' => $user->id, 'courseid' => $keep->id,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        set_config('socratic_mode_course_' . $keep->id, '1', 'local_ai_course_assistant');

        delete_course($course->id, false);

        $this->assertSame(
            0,
            $DB->count_records('local_ai_course_assistant_convs', ['courseid' => $course->id]),
            'Conversations outlived the course they belonged to.'
        );
        $this->assertSame(
            0,
            $DB->count_records('local_ai_course_assistant_msgs', ['courseid' => $course->id]),
            'Messages outlived the course they belonged to.'
        );
        $this->assertFalse(
            get_config('local_ai_course_assistant', 'socratic_mode_course_' . $course->id),
            'A per-course override outlived its course.'
        );

        $this->assertSame(
            1,
            $DB->count_records('local_ai_course_assistant_convs', ['courseid' => $keep->id]),
            'Deleting one course removed data belonging to another course.'
        );
        $this->assertSame(
            '1',
            get_config('local_ai_course_assistant', 'socratic_mode_course_' . $keep->id)
        );
    }

    /**
     * Every table with a courseid is cleared, and every orphan-able child too.
     *
     * The first version of this observer covered 23 tables and missed five more
     * that carry a courseid -- including learner free text in feedback and
     * message ratings -- plus three children keyed to parents it did delete.
     * Enumerating install.xml rather than re-listing the constant is the point:
     * a table added later fails this test instead of quietly leaking.
     */
    public function test_every_course_scoped_table_is_cleared(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $xml = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/db/install.xml');
        preg_match_all('/<TABLE NAME="([^"]+)"(.*?)<\/TABLE>/s', $xml, $matches, PREG_SET_ORDER);

        // Documented, deliberate exclusions -- both are argued in the observer's
        // own docblock, so a change of mind has to edit that reasoning too.
        $keep = [
            'local_ai_course_assistant_audit',
            'local_ai_course_assistant_email_optout',
        ];

        $observed = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/classes/observer.php');
        $missing = [];
        foreach ($matches as $match) {
            [, $table, $body] = $match;
            if (!str_contains($body, 'NAME="courseid"') || in_array($table, $keep, true)) {
                continue;
            }
            if (!str_contains($observed, "'{$table}'")) {
                $missing[] = $table;
            }
        }

        $this->assertSame([], $missing,
            'These tables carry a courseid and would survive the course being deleted.');
    }

    /**
     * A child row whose parent belonged to the course goes with it.
     *
     * Course ids are reused, so an orphan does not merely waste a row -- it can
     * resurface attached to an unrelated course.
     */
    public function test_child_rows_do_not_outlive_their_parent(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $assignid = $DB->insert_record('local_ai_course_assistant_sbx_assign', (object) [
            'courseid' => $course->id,
            'name' => 'Practice talk',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_ai_course_assistant_sbx_topic', (object) [
            'assignid' => $assignid,
            'title' => 'Topic',
            'instructions' => '',
            'instructionsformat' => 1,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->assertSame(1, $DB->count_records('local_ai_course_assistant_sbx_topic',
            ['assignid' => $assignid]));

        delete_course($course->id, false);

        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_sbx_topic',
            ['assignid' => $assignid]),
            'A Soapbox topic outlived the assignment and the course it belonged to.');
    }

    public function test_the_audit_trail_survives_the_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        audit_logger::log('probe_action', 2, (int) $course->id, ['note' => 'kept']);
        $before = $DB->count_records('local_ai_course_assistant_audit', ['courseid' => $course->id]);
        $this->assertGreaterThan(0, $before);

        delete_course($course->id, false);

        $this->assertSame(
            $before,
            $DB->count_records('local_ai_course_assistant_audit', ['courseid' => $course->id]),
            'The audit log records what happened on this site and must outlive the course.'
        );
    }
}
