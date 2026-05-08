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
 * Tests for quiz_config_manager (v5.2.0 / regression coverage v5.3.8).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\quiz_config_manager
 */
final class quiz_config_manager_test extends \advanced_testcase {

    /**
     * Helper to create a quiz with a specific grade. Returns the cmid.
     */
    private function make_quiz(int $courseid, float $grade): int {
        $generator = $this->getDataGenerator();
        $quizgen = $generator->get_plugin_generator('mod_quiz');
        $quiz = $quizgen->create_instance(['course' => $courseid, 'grade' => $grade]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);
        return (int)$cm->id;
    }

    public function test_get_returns_null_when_no_row(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $cmid = $this->make_quiz((int)$course->id, 0.0);
        $this->assertNull(quiz_config_manager::get($cmid));
    }

    public function test_get_assistance_level_default_grade_zero_means_full(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $cmid = $this->make_quiz((int)$course->id, 0.0); // Ungraded.
        $this->assertEquals('full', quiz_config_manager::get_assistance_level($cmid));
    }

    public function test_get_assistance_level_default_grade_positive_means_hidden(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $cmid = $this->make_quiz((int)$course->id, 100.0);
        $this->assertEquals('hidden', quiz_config_manager::get_assistance_level($cmid));
    }

    public function test_save_coach_then_read_returns_coach(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $cmid = $this->make_quiz((int)$course->id, 100.0);

        quiz_config_manager::save($cmid, (int)$course->id, 'coach');

        $this->assertEquals('coach', quiz_config_manager::get_assistance_level($cmid));
    }

    public function test_save_default_deletes_row(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $cmid = $this->make_quiz((int)$course->id, 0.0);
        quiz_config_manager::save($cmid, (int)$course->id, 'coach');
        $this->assertNotNull(quiz_config_manager::get($cmid));

        quiz_config_manager::save($cmid, (int)$course->id, 'default');

        $this->assertNull(quiz_config_manager::get($cmid),
            'Saving "default" must delete the row so absence == fall back to grade-based heuristic.');
        $this->assertEquals(0, $DB->count_records('local_ai_course_assistant_quiz_cfg',
            ['cmid' => $cmid]));
    }

    public function test_save_invalid_level_falls_back_to_default(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $cmid = $this->make_quiz((int)$course->id, 100.0);
        quiz_config_manager::save($cmid, (int)$course->id, 'coach');

        // Invalid level. Manager should treat as 'default' and clear the row.
        quiz_config_manager::save($cmid, (int)$course->id, 'invalid-level');

        $this->assertNull(quiz_config_manager::get($cmid));
    }

    public function test_get_assistance_level_returns_full_for_unknown_cmid(): void {
        // Defensive fallback when a stale cmid arrives.
        $this->resetAfterTest();
        $this->assertEquals('full', quiz_config_manager::get_assistance_level(999999));
    }

    public function test_list_for_course_includes_stored_and_effective(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $cmid1 = $this->make_quiz((int)$course->id, 0.0);    // Ungraded.
        $cmid2 = $this->make_quiz((int)$course->id, 100.0);  // Graded.
        quiz_config_manager::save($cmid2, (int)$course->id, 'coach');

        $rows = quiz_config_manager::list_for_course((int)$course->id);

        $this->assertCount(2, $rows);
        $bycm = [];
        foreach ($rows as $r) {
            $bycm[(int)$r->cmid] = $r;
        }
        $this->assertEquals('default', $bycm[$cmid1]->stored_level);
        $this->assertEquals('full', $bycm[$cmid1]->effective_level);
        $this->assertEquals('coach', $bycm[$cmid2]->stored_level);
        $this->assertEquals('coach', $bycm[$cmid2]->effective_level);
    }
}
