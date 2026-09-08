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
 * Course id 0 means every course, in every analytics counter.
 *
 * The Overall Usage panel reported zero students, zero sessions and zero
 * messages on a dev site carrying real traffic, because get_overview() and four
 * of its siblings interpolated "WHERE courseid = :courseid" unconditionally.
 * Site-wide therefore matched only rows stored against course 0, of which there
 * are none. Each counter was correct per-course, so the bug was invisible
 * anywhere an administrator could cross-check it.
 *
 * These tests write activity into two real courses and assert the site-wide
 * call sees both. A counter that regresses to a literal course-0 filter returns
 * zero here and fails.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\analytics
 */
final class analytics_sitewide_test extends \advanced_testcase {

    /** @var \stdClass First course with activity. */
    private $coursea;

    /** @var \stdClass Second course with activity. */
    private $courseb;

    /** @var \stdClass Learner in course A. */
    private $usera;

    /** @var \stdClass Learner in course B. */
    private $userb;

    /**
     * Two courses, two learners, four user turns and four replies.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $gen = $this->getDataGenerator();
        $this->coursea = $gen->create_course();
        $this->courseb = $gen->create_course();
        $this->usera = $gen->create_user();
        $this->userb = $gen->create_user();
        $gen->enrol_user($this->usera->id, $this->coursea->id);
        $gen->enrol_user($this->userb->id, $this->courseb->id);

        $this->seed($this->coursea->id, $this->usera->id, 2);
        $this->seed($this->courseb->id, $this->userb->id, 2);
    }

    /**
     * Write conversation rows for one learner in one course.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $turns
     */
    private function seed(int $courseid, int $userid, int $turns): void {
        global $DB;

        $convid = $DB->insert_record('local_ai_course_assistant_convs', (object) [
            'userid' => $userid,
            'courseid' => $courseid,
            'timecreated' => time() - 3600,
            'timemodified' => time(),
            'offtopic_count' => 0,
        ]);

        for ($i = 0; $i < $turns; $i++) {
            foreach (['user', 'assistant'] as $role) {
                $DB->insert_record('local_ai_course_assistant_msgs', (object) [
                    'conversationid' => $convid,
                    'userid' => $userid,
                    'courseid' => $courseid,
                    'role' => $role,
                    'message' => $role . ' turn ' . $i,
                    'timecreated' => time() - 1800 + ($i * 60),
                ]);
            }
        }
    }

    /**
     * The Overall Usage panel's own numbers.
     */
    public function test_overview_counts_every_course(): void {
        $overview = analytics::get_overview(0);

        $this->assertSame(2, (int) $overview['total_conversations']);
        $this->assertSame(8, (int) $overview['total_messages']);
        $this->assertSame(2, (int) $overview['active_students']);
        $this->assertGreaterThan(0, (float) $overview['avg_messages_per_student']);
    }

    /**
     * Site-wide is the sum of the parts, not a third answer.
     */
    public function test_sitewide_equals_the_sum_of_the_courses(): void {
        $a = analytics::get_overview($this->coursea->id);
        $b = analytics::get_overview($this->courseb->id);
        $all = analytics::get_overview(0);

        $this->assertSame(
            (int) $a['total_messages'] + (int) $b['total_messages'],
            (int) $all['total_messages']
        );
        $this->assertSame(
            (int) $a['active_students'] + (int) $b['active_students'],
            (int) $all['active_students']
        );
    }

    /**
     * The counters behind the charts and tables see both courses too.
     */
    public function test_sibling_counters_see_every_course(): void {
        $daily = analytics::get_daily_usage(0, 30);
        $this->assertGreaterThan(0, array_sum(array_column($daily, 'count')));

        $students = analytics::get_student_usage(0);
        $this->assertCount(2, $students);

        $sessions = analytics::get_session_stats(0);
        $this->assertSame(2, (int) $sessions['total_sessions']);
    }

    /**
     * A named course still reports only its own activity.
     *
     * The fix must widen site-wide without leaking one course's numbers into
     * another's page.
     */
    public function test_named_course_is_still_scoped(): void {
        $a = analytics::get_overview($this->coursea->id);
        $this->assertSame(4, (int) $a['total_messages']);
        $this->assertSame(1, (int) $a['active_students']);
        $this->assertSame(1, (int) $a['total_conversations']);

        $students = analytics::get_student_usage($this->coursea->id);
        $this->assertCount(1, $students);
        $this->assertArrayHasKey($this->usera->id, $students);
    }

    /**
     * The TOTAL STUDENTS tile has a number site-wide, not just per course.
     *
     * get_enrollment_counts() was the counter v7.2.4 missed: site-wide it
     * returned a bare list of per-course rows with no top-level total_enrolled,
     * so the tile read 0 next to a site-wide 25.3 messages per student. The
     * total can never be smaller than any single course's, nor smaller than the
     * count of learners who actually sent a message.
     */
    public function test_total_students_counts_every_course(): void {
        $all = analytics::get_enrollment_counts(0);
        $a = analytics::get_enrollment_counts($this->coursea->id);
        $b = analytics::get_enrollment_counts($this->courseb->id);

        $this->assertArrayHasKey(
            'total_enrolled',
            $all,
            'The site-wide call returns no total_enrolled key, so the tile renders 0.'
        );
        $this->assertGreaterThan(0, (int) $all['total_enrolled']);
        $this->assertGreaterThanOrEqual(
            max((int) $a['total_enrolled'], (int) $b['total_enrolled']),
            (int) $all['total_enrolled'],
            'Site-wide TOTAL STUDENTS is below a single course\'s count.'
        );

        $active = (int) analytics::get_overview(0)['active_students'];
        $this->assertGreaterThanOrEqual(
            $active,
            (int) $all['total_enrolled'],
            'Fewer students than active AI users: the zero-students-with-traffic signature.'
        );
    }

    /**
     * A named course still reports only its own enrolments.
     */
    public function test_named_course_enrolment_is_still_scoped(): void {
        $this->assertSame(1, (int) analytics::get_enrollment_counts($this->coursea->id)['total_enrolled']);
        $this->assertSame(1, (int) analytics::get_enrollment_counts($this->courseb->id)['total_enrolled']);
    }

    /**
     * The tile survives the trip through the web service the dashboard calls.
     *
     * The payload is JSON-encoded PARAM_RAW, so no return-structure check would
     * have caught the shape change; only reading the key the way the dashboard
     * reads it does.
     */
    public function test_sitewide_payload_carries_the_total_students_tile(): void {
        $this->setAdminUser();

        $raw = \local_ai_course_assistant\external\get_analytics_overall::execute(0, 0);
        $data = json_decode($raw['data'], true);

        $this->assertArrayHasKey('enrollment', $data);
        $this->assertArrayHasKey('total_enrolled', $data['enrollment']);
        $this->assertGreaterThanOrEqual(
            (int) $data['overview']['active_students'],
            (int) $data['enrollment']['total_enrolled']
        );
    }
}
