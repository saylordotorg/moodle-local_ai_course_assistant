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
 * Tests for the auto-open / user-tour collision guard.
 *
 * Auto-open and a Moodle user tour both claim the screen on a first visit and
 * the drawer wins, covering the tour. The widget therefore advertises whether a
 * tour is pending so the JS can wait for it instead of racing it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\hook_callbacks
 */
final class usertour_guard_test extends \advanced_testcase {
    /**
     * Render the widget on a course page as an enrolled student.
     *
     * @param \stdClass $course Course to render on.
     * @return string Widget HTML.
     */
    private function render_widget_on_course(\stdClass $course): string {
        global $OUTPUT, $PAGE;

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
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

        // tool_usertours\manager::get_current_tours() memoises into a process
        // static, which is right for a real request (one page, one URL) but
        // wrong here: PHPUnit runs every test in one process, so the first
        // test's "no tours" result would be handed to all the others. Reset it
        // once $PAGE is set, which is the state a fresh request starts from.
        if (class_exists('\tool_usertours\manager')) {
            \tool_usertours\manager::get_current_tours(true);
        }

        $OUTPUT = new \core_renderer($PAGE, RENDERER_TARGET_GENERAL);
        $hook = new \core\hook\output\before_footer_html_generation($OUTPUT);
        hook_callbacks::inject_chat_widget($hook);

        return $hook->get_output();
    }

    /**
     * Create a user tour carrying one step.
     *
     * The step is not decoration. Core only ever serves a tour that has at
     * least one step (tool_usertours\cache::get_enabled_tourdata filters on
     * it), so a stepless tour would silently never match and every assertion
     * built on it would pass for the wrong reason.
     *
     * @param string $pathmatch Page pattern the tour applies to.
     * @param bool $enabled Whether the tour is switched on.
     * @return \tool_usertours\tour
     */
    private function create_tour(string $pathmatch, bool $enabled): \tool_usertours\tour {
        $tour = new \tool_usertours\tour();
        $tour->set_name('Course assistant tour')
            ->set_description('Collides with auto-open.')
            ->set_pathmatch($pathmatch)
            ->set_enabled($enabled)
            ->persist();

        \tool_usertours\step::load_from_record((object) [
            'id' => null,
            'tourid' => $tour->get_id(),
            'title' => 'Meet the assistant',
            'content' => 'Here is the assistant.',
            'targettype' => \tool_usertours\target::TARGET_UNATTACHED,
            'targetvalue' => '',
            'sortorder' => 0,
            'configdata' => '',
        ], true)->persist(true);

        return $tour;
    }

    /**
     * Enable the widget on a fresh course and return it.
     *
     * @return \stdClass
     */
    private function create_sola_course(): \stdClass {
        set_config('enabled', '1', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Tour Guard Course']);
        // per_course is the default course mode, so the course must opt in.
        set_config('sola_enabled_course_' . $course->id, '1', 'local_ai_course_assistant');
        return $course;
    }

    public function test_no_tour_means_the_drawer_opens_immediately(): void {
        $this->resetAfterTest(true);
        $course = $this->create_sola_course();

        $html = $this->render_widget_on_course($course);

        // Empty, not absent: the JS reads dataset.tourpending === '1', and an
        // absent attribute on a legacy template would read as undefined. Pin
        // that the attribute is always emitted.
        $this->assertStringContainsString('data-tourpending=""', $html);
    }

    public function test_a_pending_tour_defers_auto_open(): void {
        $this->resetAfterTest(true);

        if (!class_exists('\tool_usertours\tour')) {
            $this->markTestSkipped('tool_usertours is not installed.');
        }

        $course = $this->create_sola_course();

        $this->create_tour('/course/view.php%', true);

        $html = $this->render_widget_on_course($course);

        $this->assertStringContainsString('data-tourpending="1"', $html);
    }

    public function test_a_disabled_tour_does_not_defer_auto_open(): void {
        $this->resetAfterTest(true);

        if (!class_exists('\tool_usertours\tour')) {
            $this->markTestSkipped('tool_usertours is not installed.');
        }

        $course = $this->create_sola_course();

        // The Saylor fix was to disable the stale tour. A disabled tour must
        // not keep suppressing auto-open afterwards.
        $this->create_tour('/course/view.php%', false);

        $html = $this->render_widget_on_course($course);

        $this->assertStringContainsString('data-tourpending=""', $html);
    }

    public function test_a_tour_on_another_page_does_not_defer_auto_open(): void {
        $this->resetAfterTest(true);

        if (!class_exists('\tool_usertours\tour')) {
            $this->markTestSkipped('tool_usertours is not installed.');
        }

        $course = $this->create_sola_course();

        $this->create_tour('/my/%', true);

        $html = $this->render_widget_on_course($course);

        $this->assertStringContainsString('data-tourpending=""', $html);
    }
}
