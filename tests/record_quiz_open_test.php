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

/**
 * v7.1.1: the Quiz Me press is now countable.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\record_quiz_open
 */
final class record_quiz_open_test extends \advanced_testcase {

    /** @var \stdClass */
    private $user;
    /** @var \stdClass */
    private $course;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->user->id, $this->course->id, 'student');
        $this->setUser($this->user);
    }

    public function test_a_press_is_recorded(): void {
        global $DB;
        $result = \local_ai_course_assistant\external\record_quiz_open::execute(
            (int) $this->course->id, 0);
        $this->assertTrue($result['recorded']);

        $row = $DB->get_record('local_ai_course_assistant_msgs',
            ['courseid' => $this->course->id, 'interaction_type' => 'quiz_open']);
        $this->assertNotFalse($row);
        $this->assertSame('system', $row->role,
            'must stay out of the learner history and the model context');
        $this->assertNull($row->model_name, 'a panel open is not a model call');
    }

    public function test_the_activity_is_recorded_when_there_is_one(): void {
        global $DB;
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        \local_ai_course_assistant\external\record_quiz_open::execute(
            (int) $this->course->id, (int) $page->cmid);

        $row = $DB->get_record('local_ai_course_assistant_msgs',
            ['courseid' => $this->course->id, 'interaction_type' => 'quiz_open']);
        $this->assertEquals((int) $page->cmid, (int) $row->cmid);
    }

    /**
     * Presses and generations are separate events, which is the entire point:
     * the gap between them is the abandonment we could not previously see.
     */
    public function test_a_press_is_countable_separately_from_a_generation(): void {
        global $DB;
        \local_ai_course_assistant\external\record_quiz_open::execute((int) $this->course->id, 0);
        conversation_manager::record_quiz_usage(
            (int) $this->user->id, (int) $this->course->id,
            '[Quiz] 3 question(s)', 'openai', 'gpt-4o-mini', 900, 200
        );

        $opens = $DB->count_records('local_ai_course_assistant_msgs',
            ['courseid' => $this->course->id, 'interaction_type' => 'quiz_open']);
        $gens = $DB->count_records('local_ai_course_assistant_msgs',
            ['courseid' => $this->course->id, 'interaction_type' => 'quiz']);
        $this->assertSame(1, $opens);
        $this->assertSame(1, $gens);
    }

    /**
     * Telemetry must not become a way to make the plugin write rows on demand.
     */
    public function test_it_is_rate_limited(): void {
        global $DB;
        for ($i = 0; $i < 65; $i++) {
            \local_ai_course_assistant\external\record_quiz_open::execute((int) $this->course->id, 0);
        }
        $written = $DB->count_records('local_ai_course_assistant_msgs',
            ['courseid' => $this->course->id, 'interaction_type' => 'quiz_open']);
        $this->assertLessThanOrEqual(60, $written,
            'the bucket is 60/minute, so 65 presses must not write 65 rows');
    }

    public function test_an_unenrolled_user_cannot_record(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);
        $this->expectException(\moodle_exception::class);
        \local_ai_course_assistant\external\record_quiz_open::execute((int) $this->course->id, 0);
    }
}
