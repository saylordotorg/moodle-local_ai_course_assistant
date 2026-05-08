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
use core_privacy\local\request\approved_userlist;

/**
 * Privacy provider delete-coverage tests (v5.3.18).
 *
 * v5.3.17 declared 12 new tables in the privacy provider's metadata.
 * This file proves the *delete* handlers also touch them — without it,
 * a learner's Article 17 erasure request via Moodle's standard
 * Site administration → Users → Privacy flow would silently leave
 * carryover-memory and outreach state behind. The bug we shipped in
 * v5.3.17's metadata-only fix is closed in v5.3.18 and this test
 * locks the fix in place.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\privacy\provider
 */
final class provider_delete_test extends \advanced_testcase {

    /**
     * Seed at least one row in every table the privacy provider declares
     * for the (userid, courseid) pair so we can verify cleanup. Returns
     * the seeded course and user.
     *
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function seed_user_data(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $now = time();

        // v5.3.0 carryover-personalisation tables.
        $DB->insert_record('local_ai_course_assistant_learner_goals', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'q1_answer' => 'goal', 'consented_at' => $now,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_ai_course_assistant_learner_memory', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'notes_json' => '{}',
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
            'session_id' => 's', 'topic_hint' => 't',
            'stage1_score' => 1, 'stage2_label' => 'unprocessed',
            'followup_sent_at' => 0, 'timecreated' => $now,
        ]);
        $DB->insert_record('local_ai_course_assistant_outreach_log', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'channel' => 'milestone_streak7',
            'trigger_reason' => 'r', 'message_id' => 'm',
            'timesent' => $now,
        ]);
        return [$course, $user];
    }

    /**
     * Build an approved_contextlist for the given (user, courseContext).
     *
     * @param \stdClass $user
     * @param \context $context
     * @return approved_contextlist
     */
    private function approved_list(\stdClass $user, \context $context): approved_contextlist {
        // approved_contextlist constructor takes the user, the component
        // name, and the list of context ids the user has approved for
        // deletion. No need for the intermediate contextlist — the
        // privacy provider only reads the contextlist's ->get_user()
        // and ->get_contexts(), both of which approved_contextlist
        // satisfies directly.
        return new approved_contextlist($user, 'local_ai_course_assistant', [$context->id]);
    }

    public function test_delete_data_for_user_purges_v530_tables(): void {
        $this->resetAfterTest();
        global $DB;

        [$course, $user] = $this->seed_user_data();
        $coursecontext = \context_course::instance($course->id);

        $this->assertEquals(1, $DB->count_records('local_ai_course_assistant_learner_goals',
            ['userid' => $user->id, 'courseid' => $course->id]));

        // Run the provider delete via Moodle's standard flow.
        provider::delete_data_for_user($this->approved_list($user, $coursecontext));

        // Every v5.3.0 table must be empty for this learner now.
        foreach ([
            'local_ai_course_assistant_learner_goals',
            'local_ai_course_assistant_learner_memory',
            'local_ai_course_assistant_streak',
            'local_ai_course_assistant_struggle_signal',
            'local_ai_course_assistant_outreach_log',
        ] as $table) {
            $this->assertEquals(0,
                $DB->count_records($table, ['userid' => $user->id, 'courseid' => $course->id]),
                "Table {$table} still has rows for the user after provider::delete_data_for_user");
        }
    }

    public function test_delete_data_for_users_purges_v530_tables(): void {
        $this->resetAfterTest();
        global $DB;

        [$course, $user] = $this->seed_user_data();
        $coursecontext = \context_course::instance($course->id);

        $userlist = new approved_userlist($coursecontext, 'local_ai_course_assistant', [$user->id]);

        provider::delete_data_for_users($userlist);

        foreach ([
            'local_ai_course_assistant_learner_goals',
            'local_ai_course_assistant_learner_memory',
            'local_ai_course_assistant_streak',
            'local_ai_course_assistant_struggle_signal',
            'local_ai_course_assistant_outreach_log',
        ] as $table) {
            $this->assertEquals(0,
                $DB->count_records($table, ['userid' => $user->id, 'courseid' => $course->id]),
                "Table {$table} still has rows for the user after provider::delete_data_for_users");
        }
    }

    public function test_delete_does_not_touch_other_users_data(): void {
        $this->resetAfterTest();
        global $DB;

        [$course, $user] = $this->seed_user_data();
        $coursecontext = \context_course::instance($course->id);

        // Seed a SECOND user in the same course; they must remain untouched.
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'student');
        $now = time();
        $DB->insert_record('local_ai_course_assistant_learner_goals', (object)[
            'userid' => $other->id, 'courseid' => $course->id,
            'q1_answer' => 'other-goal', 'consented_at' => $now,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        provider::delete_data_for_user($this->approved_list($user, $coursecontext));

        $this->assertEquals(1,
            $DB->count_records('local_ai_course_assistant_learner_goals',
                ['userid' => $other->id, 'courseid' => $course->id]),
            'Provider delete must not touch other learners in the same course.');
    }
}
