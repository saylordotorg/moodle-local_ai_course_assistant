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
 * Numbers the analytics dashboard reports must be true (v7.3.2, F49/F51).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\analytics::get_unit_usage
 * @covers     \local_ai_course_assistant\analytics::meta_rows_excluded
 */
final class analytics_accuracy_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Insert a message row.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $role
     * @param int|null $cmid
     * @param string $type
     * @return void
     */
    private function msg(int $userid, int $courseid, string $role, ?int $cmid = null, string $type = 'chat'): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => 1, 'userid' => $userid, 'courseid' => $courseid,
            'role' => $role, 'message' => 'text', 'cmid' => $cmid,
            'interaction_type' => $type, 'timecreated' => time(),
        ]);
    }

    /**
     * F49: one learner active in several activities of one section is ONE
     * student in that section, not one per activity.
     */
    public function test_unit_usage_counts_each_learner_once_per_section(): void {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $user = $this->getDataGenerator()->create_user();

        // Three activities, all in the same section.
        $cms = [];
        for ($i = 0; $i < 3; $i++) {
            $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
            $cms[] = (int) $page->cmid;
        }
        foreach ($cms as $cmid) {
            $this->msg((int) $user->id, (int) $course->id, 'user', $cmid);
        }

        $rows = analytics::get_unit_usage((int) $course->id);
        $this->assertNotEmpty($rows, 'the fixture produced no section rows');

        $row = null;
        foreach ($rows as $r) {
            if ((int) $r['section_num'] === 1) {
                $row = $r;
            }
        }
        $this->assertNotNull($row, 'section 1 should appear');
        $this->assertSame(1, $row['student_count'],
            'one learner across three activities in a section is one student, not three');
        $this->assertSame(3, $row['message_count'],
            'messages do sum across activities');
    }

    /**
     * Two learners in the same section are two students.
     */
    public function test_unit_usage_still_counts_distinct_learners(): void {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);

        $this->msg((int) $a->id, (int) $course->id, 'user', (int) $page->cmid);
        $this->msg((int) $b->id, (int) $course->id, 'user', (int) $page->cmid);

        $rows = analytics::get_unit_usage((int) $course->id);
        $row = null;
        foreach ($rows as $r) {
            if ((int) $r['section_num'] === 1) {
                $row = $r;
            }
        }
        $this->assertSame(2, $row['student_count']);
    }

    /**
     * F51: an admin running a Learning Radar query is not an active learner.
     */
    public function test_radar_meta_rows_are_not_counted_as_learner_activity(): void {
        $admin = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();

        // A real learner message, site-wide scope.
        $this->msg((int) $learner->id, SITEID, 'user');

        $baseline = analytics::get_overview(0);

        // Radar rows: role user/assistant, but interaction_type meta.
        $this->msg((int) $admin->id, SITEID, 'user', null, 'meta');
        $this->msg((int) $admin->id, SITEID, 'assistant', null, 'meta');
        $this->msg((int) $admin->id, SITEID, 'user', null, 'meta_scheduled');

        $after = analytics::get_overview(0);

        $this->assertSame($baseline['active_students'], $after['active_students'],
            'running a radar query must not add an active student');
        $this->assertSame($baseline['total_messages'], $after['total_messages'],
            'radar prose must not land in the learner message count');
    }

    /**
     * The exclusion must not drop pre-v6 rows, whose interaction_type is NULL.
     * NOT IN is NULL-propagating, so this is the easy way to get it wrong.
     */
    public function test_rows_with_a_null_interaction_type_still_count(): void {
        global $DB;
        $learner = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => 1, 'userid' => $learner->id, 'courseid' => SITEID,
            'role' => 'user', 'message' => 'legacy row', 'cmid' => null,
            'interaction_type' => null, 'timecreated' => time(),
        ]);

        $this->assertSame(1, analytics::get_overview(0)['active_students'],
            'a legacy row with a NULL interaction_type is still a real learner message');
    }

    /**
     * F55: "messages to resolution" must be able to match at all.
     *
     * Ratings land on ASSISTANT message ids, but the session walk fetched only
     * role='user' rows and tested their ids against the rated set -- one shared
     * id sequence, so the intersection was permanently empty and the Feedback
     * tab showed a confident 0.0 on sites with thousands of thumbs-ups.
     */
    public function test_resolution_metric_matches_rated_assistant_replies(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $u = $this->getDataGenerator()->create_user();
        $t = time() - 600;

        // Three learner turns, each answered; thumbs-up on the THIRD answer.
        $aid = 0;
        for ($i = 0; $i < 3; $i++) {
            $this->msg((int) $u->id, (int) $course->id, 'user');
            $DB->execute("UPDATE {local_ai_course_assistant_msgs} SET timecreated = ? WHERE id = (SELECT * FROM (SELECT MAX(id) FROM {local_ai_course_assistant_msgs}) x)", [$t + $i * 20]);
            $aid = $DB->insert_record('local_ai_course_assistant_msgs', (object) [
                'conversationid' => 1, 'userid' => $u->id, 'courseid' => $course->id,
                'role' => 'assistant', 'message' => 'answer', 'cmid' => null,
                'interaction_type' => 'chat', 'timecreated' => $t + $i * 20 + 5,
            ]);
        }
        $DB->insert_record('local_ai_course_assistant_msg_ratings', (object) [
            'messageid' => $aid, 'userid' => $u->id, 'courseid' => $course->id,
            'rating' => 1, 'is_hallucination' => 0, 'comment' => '', 'timecreated' => time(),
        ]);

        $r = analytics::get_messages_to_resolution((int) $course->id);
        $this->assertSame(1, $r['sample_size'], 'one rated session must yield one data point');
        $this->assertSame(3.0, $r['avg_messages'], 'it took three learner turns to reach the rated answer');
    }
}
