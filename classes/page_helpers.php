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
 * Shared page-render helpers for plugin admin / per-course pages.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_helpers {

    /**
     * Render a friendly "Select a course" landing for per-course admin pages
     * that previously required a `courseid` query param. Lists every course
     * the current user has the requested capability in, with a clickable
     * link back to the same page with `?courseid=N` filled in.
     *
     * Replaces the hard 404 that Moodle throws when `required_param`
     * encounters a missing param. Keeps direct links with ?courseid=N
     * working unchanged — this only fires when courseid is absent or 0.
     *
     * @param string $pagepath   e.g. '/local/ai_course_assistant/instructor_dashboard.php'
     * @param string $pagetitle  Display title for the picker page.
     * @param string $capability Capability the learner must have on a course
     *                           to see it in the list (e.g.
     *                           'local/ai_course_assistant:viewanalytics').
     * @return void Writes header + content + footer to output and returns.
     */
    public static function render_course_picker_landing(
        string $pagepath,
        string $pagetitle,
        string $capability
    ): void {
        global $PAGE, $OUTPUT, $USER, $DB;

        $PAGE->set_url($pagepath);
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_pagelayout('admin');
        $PAGE->set_title($pagetitle);
        $PAGE->set_heading($pagetitle);

        // Find every visible course the current user has the requested
        // capability in. Sorted alphabetically; deduped by id.
        $courses = enrol_get_users_courses($USER->id, true, ['fullname', 'shortname', 'visible'], 'fullname');
        $eligible = [];
        foreach ($courses as $c) {
            if (empty($c->visible)) {
                continue;
            }
            $context = \context_course::instance($c->id);
            if (has_capability($capability, $context)) {
                $eligible[] = $c;
            }
        }

        // Site admins see every course (enrol_get_users_courses excludes
        // courses the user is not enrolled in, but admins should see all).
        if (is_siteadmin($USER) && empty($eligible)) {
            // Avoid loading the entire course table into memory on very large
            // sites: stream with a recordset and cap the picker list.
            $maxcourses = 500;
            $rs = $DB->get_recordset('course',
                ['visible' => 1], 'fullname',
                'id, fullname, shortname, visible', 0, $maxcourses);
            foreach ($rs as $c) {
                if ((int) $c->id === SITEID) {
                    continue;
                }
                $eligible[] = $c;
            }
            $rs->close();
        }

        echo $OUTPUT->header();
        echo $OUTPUT->heading($pagetitle, 2);
        echo \html_writer::tag('p',
            get_string('coursepicker:intro', 'local_ai_course_assistant'),
            ['class' => 'text-muted']);

        if (empty($eligible)) {
            echo $OUTPUT->notification(
                get_string('coursepicker:nocourses', 'local_ai_course_assistant'),
                \core\output\notification::NOTIFY_INFO);
        } else {
            $items = [];
            foreach ($eligible as $c) {
                $url = new \moodle_url($pagepath, ['courseid' => (int) $c->id]);
                $label = format_string($c->fullname);
                if (!empty($c->shortname)) {
                    $label .= ' ' . \html_writer::tag('span',
                        '(' . format_string($c->shortname) . ')',
                        ['class' => 'text-muted small']);
                }
                $items[] = \html_writer::link($url, $label);
            }
            echo \html_writer::alist($items, ['class' => 'list-unstyled']);
        }
        echo $OUTPUT->footer();
    }
}
