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
 * The notice shown at the old Soapbox page addresses.
 *
 * PresenterAI replaces Soapbox, and its UI is hidden everywhere in SOLA (7.12.5).
 * The old pages are not deleted: the data, the cleanup task and the privacy
 * provider are all still live. A person who follows an old link or bookmark gets
 * this short notice and a way back to the course instead of the old tool.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_retired {
    /**
     * Render the notice with a link back to the course, then stop the request.
     *
     * The caller has already done require_login() and its capability check, so
     * this never tells a person who could not have seen the page anything.
     *
     * @param \stdClass $course The course the old page belonged to.
     * @param \moodle_url $pageurl The old page's own URL.
     * @return void Never returns.
     */
    public static function notice_and_exit(\stdClass $course, \moodle_url $pageurl): void {
        global $OUTPUT, $PAGE;
        $PAGE->set_url($pageurl);
        $PAGE->set_context(\context_course::instance($course->id));
        $PAGE->set_pagelayout('incourse');
        $PAGE->set_course($course);
        $PAGE->set_title(get_string('soapbox:replaced_title', 'local_ai_course_assistant'));
        $PAGE->set_heading($course->fullname);

        echo $OUTPUT->header();
        echo $OUTPUT->notification(
            get_string('soapbox:replaced', 'local_ai_course_assistant'),
            \core\output\notification::NOTIFY_INFO
        );
        echo $OUTPUT->continue_button(new \moodle_url('/course/view.php', ['id' => $course->id]));
        echo $OUTPUT->footer();
        exit;
    }
}
