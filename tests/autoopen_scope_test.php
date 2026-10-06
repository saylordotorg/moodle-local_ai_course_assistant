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
 * Auto-open applies to course pages only.
 *
 * Support mode renders the widget on the dashboard, profiles and the site home
 * against the support course. Auto-open used to follow that course's setting
 * (or the global one), so the assistant opened over pages the learner came to
 * for something else. These render the real widget through the footer hook and
 * read the attribute the JS acts on.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\hook_callbacks
 */
final class autoopen_scope_test extends \advanced_testcase {

    /** @var \stdClass The designated support course. */
    private $supportcourse;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->supportcourse = $this->getDataGenerator()->create_course();
        set_config('enabled', 1, 'local_ai_course_assistant');
        set_config('support_enabled', 1, 'local_ai_course_assistant');
        set_config('support_courseid', $this->supportcourse->id, 'local_ai_course_assistant');
        set_config('auto_open', 1, 'local_ai_course_assistant');
        support_mode::reset_cache();
    }

    /**
     * Render the widget for the current $PAGE and return its HTML.
     *
     * @return string
     */
    private function render(): string {
        global $OUTPUT, $PAGE;
        $PAGE->set_state(\moodle_page::STATE_PRINTING_HEADER);
        $PAGE->set_state(\moodle_page::STATE_IN_BODY);
        if (class_exists('\tool_usertours\manager')) {
            \tool_usertours\manager::get_current_tours(true);
        }
        $OUTPUT = new \core_renderer($PAGE, RENDERER_TARGET_GENERAL);
        $hook = new \core\hook\output\before_footer_html_generation($OUTPUT);
        hook_callbacks::inject_chat_widget($hook);
        return $hook->get_output();
    }

    /**
     * The dashboard renders the widget but never opens it, even with the global
     * switch on and the support course itself set to auto-open.
     */
    public function test_the_dashboard_does_not_auto_open(): void {
        global $PAGE;
        set_config('sola_autoopen_course_' . $this->supportcourse->id, '1', 'local_ai_course_assistant');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $PAGE = new \moodle_page();
        $PAGE->set_context(\context_user::instance($user->id));
        $PAGE->set_url(new \moodle_url('/my/index.php'));
        $PAGE->set_pagelayout('mydashboard');
        $html = $this->render();

        // Rendered at all, or the assertion below would pass for the wrong reason.
        $this->assertStringContainsString('data-autoopen=', $html);
        $this->assertStringContainsString('data-autoopen=""', $html);
    }

    /**
     * A course page still auto-opens under the global switch.
     */
    public function test_a_course_page_still_auto_opens(): void {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        set_config('sola_enabled_course_' . $course->id, '1', 'local_ai_course_assistant');
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $PAGE = new \moodle_page();
        $PAGE->set_course($course);
        $PAGE->set_context(\context_course::instance($course->id));
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $PAGE->set_pagetype('course-view-topics');
        $html = $this->render();

        $this->assertStringContainsString('data-autoopen="1"', $html);
    }
}
