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
 * Deleting a Moodle user must remove their SOLA footprint (v7.3.2, F41/F42).
 *
 * db/hooks.php registered \core\hook\user\deleted, a class that does not exist.
 * PHP resolves ::class lexically without autoloading and Moodle's hook manager
 * stores the name as a plain string with no class_exists() check, so the wrong
 * name registered silently and the callback never ran between v3.9.11 and
 * v7.3.1 -- about four and a half months. Every user hard-deleted in that
 * window left their conversations, messages, profile and, worst, an
 * email_optout row containing their email address behind.
 *
 * These tests go through core's delete_user() deliberately. Calling
 * hook_callbacks::on_user_deleted() directly passes against the broken
 * registration and proves nothing -- that is exactly how this survived review.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\hook_callbacks::on_user_deleted
 */
final class user_deleted_cascade_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * The registered hook class must actually exist. This is the cheap guard
     * that would have caught the original defect on the day it landed.
     */
    public function test_every_registered_hook_class_exists(): void {
        global $CFG;
        $callbacks = [];
        include($CFG->dirroot . '/local/ai_course_assistant/db/hooks.php');

        $this->assertNotEmpty($callbacks, 'db/hooks.php defined no callbacks; the scan is broken');

        $missing = [];
        foreach ($callbacks as $cb) {
            if (!class_exists($cb['hook'])) {
                $missing[] = $cb['hook'];
            }
        }
        $this->assertSame([], $missing,
            'db/hooks.php registers hook classes that do not exist, so those callbacks never fire');
    }

    /**
     * A hard-deleted user leaves no SOLA rows behind.
     */
    public function test_deleting_a_user_purges_their_sola_data(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $convid = $DB->insert_record('local_ai_course_assistant_convs', (object) [
            'userid' => $user->id, 'courseid' => $course->id, 'title' => '',
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => $convid, 'userid' => $user->id, 'courseid' => $course->id,
            'role' => 'user', 'message' => 'my personal question', 'timecreated' => time(),
        ]);
        // The row that matters most: it holds the learner's email address.
        $DB->insert_record('local_ai_course_assistant_email_optout', (object) [
            'email' => $user->email, 'optout_type' => 'all',
            'userid' => $user->id, 'courseid' => $course->id, 'timecreated' => time(),
        ]);
        $DB->insert_record('local_ai_course_assistant_flashcards', (object) [
            'userid' => $user->id, 'courseid' => $course->id,
            'question' => 'q', 'answer' => 'a', 'ease' => 2.5, 'interval_days' => 1,
            'repetitions' => 0, 'next_review' => time(), 'timecreated' => time(), 'timemodified' => time(),
        ]);

        delete_user($DB->get_record('user', ['id' => $user->id]));

        foreach ([
            'local_ai_course_assistant_convs',
            'local_ai_course_assistant_email_optout',
            'local_ai_course_assistant_flashcards',
        ] as $table) {
            $this->assertSame(0, $DB->count_records($table, ['userid' => $user->id]),
                "$table still holds rows for a deleted user");
        }
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_msgs', ['userid' => $user->id]));
    }

    /**
     * The contextlist must be computed BEFORE the row deletion runs.
     *
     * provider::get_contexts_for_userid() derives contexts from convs, plans,
     * reminders, feedback, survey_resp, ut_resp, audit and practice_scores.
     * delete_user_data() empties all of those, so computing the list afterwards
     * returns nothing and the Privacy leg is skipped -- meaning a corrected hook
     * name on its own would still not have finished the job.
     */
    public function test_contextlist_is_non_empty_before_deletion(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_ai_course_assistant_convs', (object) [
            'userid' => $user->id, 'courseid' => $course->id, 'title' => '',
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $before = privacy\provider::get_contexts_for_userid((int) $user->id);
        $this->assertNotEmpty($before->get_contextids(),
            'the fixture must produce at least one context, or this test proves nothing');

        conversation_manager::delete_user_data((int) $user->id);

        $after = privacy\provider::get_contexts_for_userid((int) $user->id);
        $this->assertEmpty($after->get_contextids(),
            'deletion empties the tables the contextlist is derived from -- which is why order matters');
    }
}
