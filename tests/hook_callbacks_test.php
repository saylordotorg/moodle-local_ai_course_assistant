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
 * Tests for hook_callbacks.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\hook_callbacks
 */
final class hook_callbacks_test extends \advanced_testcase {
    public function test_inject_chat_widget_renders_when_learning_outcomes_exist(): void {
        global $OUTPUT, $PAGE;

        $this->resetAfterTest(true);

        set_config('enabled', '1', 'local_ai_course_assistant');

        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Course With Learning Outcomes',
        ]);

        // v3.5.0: per_course is the default course mode, so tests that expect
        // the widget to render on a specific course must opt it in explicitly.
        set_config('sola_enabled_course_' . $course->id, '1', 'local_ai_course_assistant');

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Unit 1 Learning Outcomes',
            'content' => '<ul><li>Explain the main idea in enough detail for testing.</li></ul>',
        ]);

        $this->setUser($student);

        $PAGE = new \moodle_page();
        $PAGE->set_course($course);
        $PAGE->set_context(\context_course::instance($course->id));
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $PAGE->set_pagetype('course-view-topics');
        $PAGE->set_title($course->fullname);
        $PAGE->set_heading($course->fullname);
        $PAGE->set_state(\moodle_page::STATE_PRINTING_HEADER);
        $PAGE->set_state(\moodle_page::STATE_IN_BODY);

        $OUTPUT = new \core_renderer($PAGE, RENDERER_TARGET_GENERAL);
        $hook = new \core\hook\output\before_footer_html_generation($OUTPUT);

        hook_callbacks::inject_chat_widget($hook);

        $html = $hook->get_output();

        $this->assertStringContainsString('id="local-ai-course-assistant"', $html);
        $this->assertStringContainsString('data-courseid="' . $course->id . '"', $html);
    }
}
