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
 * The Overall Usage payload must contain what the dashboard reads.
 *
 * All six tiles rendered 0 on a course with a hundred participants and
 * conversations logged the same day, because the dashboard read
 * data.total_enrolled, data.active_students and so on at the top level while
 * the server nests them under enrollment, overview, sessions and return_rate.
 * Nothing was wrong with any query; the two sides disagreed about the shape.
 *
 * This asserts the server half of that contract. The JS half is a matching set
 * of reads in amd/src/analytics_dashboard.js.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\analytics
 */
final class analytics_overall_payload_test extends \advanced_testcase {

    /**
     * One enrolled student with one conversation and two messages.
     *
     * @return array [courseid, userid]
     */
    private function seed(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $convid = $DB->insert_record('local_ai_course_assistant_convs', (object) [
            'userid' => $student->id,
            'courseid' => $course->id,
            'timecreated' => time() - 600,
            'timemodified' => time(),
        ]);
        foreach ([['user', time() - 600], ['assistant', time() - 590]] as [$role, $when]) {
            $DB->insert_record('local_ai_course_assistant_msgs', (object) [
                'conversationid' => $convid,
                'userid' => $student->id,
                'courseid' => $course->id,
                'role' => $role,
                'message' => 'seeded',
                'timecreated' => $when,
            ]);
        }
        return [(int) $course->id, (int) $student->id];
    }

    public function test_enrolment_is_counted(): void {
        $this->resetAfterTest();
        [$courseid] = $this->seed();

        $counts = analytics::get_enrollment_counts($courseid);
        $this->assertArrayHasKey('total_enrolled', $counts);
        $this->assertGreaterThan(
            0,
            $counts['total_enrolled'],
            'TOTAL STUDENTS is a plain enrolment count and must not read zero for an enrolled learner.'
        );
    }

    public function test_the_dashboard_can_find_every_tile_in_the_payload(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$courseid] = $this->seed();

        $raw = \local_ai_course_assistant\external\get_analytics_overall::execute($courseid, 0);
        $data = json_decode($raw['data'], true);
        $this->assertIsArray($data);

        // Each tile, as the dashboard reaches it: container => key.
        $tiles = [
            'enrollment' => 'total_enrolled',
            'overview' => 'active_students',
            'sessions' => 'total_sessions',
            'return_rate' => 'return_rate_pct',
        ];
        foreach ($tiles as $container => $key) {
            $this->assertArrayHasKey(
                $container,
                $data,
                "The payload has no '{$container}' container; the dashboard reads its tile from there."
            );
            $this->assertArrayHasKey(
                $key,
                $data[$container],
                "'{$container}' exists but has no '{$key}'."
            );
        }
        $this->assertArrayHasKey('avg_messages_per_student', $data['overview']);
        $this->assertArrayHasKey('avg_duration_minutes', $data['sessions']);
    }

    public function test_a_seeded_conversation_produces_non_zero_activity(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$courseid] = $this->seed();

        $raw = \local_ai_course_assistant\external\get_analytics_overall::execute($courseid, 0);
        $data = json_decode($raw['data'], true);

        $this->assertGreaterThan(0, $data['enrollment']['total_enrolled']);
        $this->assertGreaterThan(
            0,
            $data['overview']['active_students'],
            'A learner who sent a message is not counted as an active AI user.'
        );
        $this->assertGreaterThan(
            0,
            $data['sessions']['total_sessions'],
            'A conversation with messages produced no session.'
        );
    }
}
