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

/**
 * Learner-facing essay feedback page (v3.9.25).
 *
 * Paste essay + optional rubric → AI returns rubric-scored feedback with
 * concrete revision suggestions. Per-course toggle.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\security;

require_login();

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('local/ai_course_assistant:use', $context);

if (!\local_ai_course_assistant\feature_flags::resolve('essay_feedback', $courseid)) {
    throw new \moodle_exception('essay_feedback:disabled', 'local_ai_course_assistant');
}

$pageurl = new moodle_url('/local/ai_course_assistant/essay_feedback.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_course($course);
$PAGE->set_title(get_string('essay_feedback:title', 'local_ai_course_assistant'));
$PAGE->set_heading($course->fullname);

security::send_security_headers(true);

$PAGE->requires->js_call_amd('local_ai_course_assistant/essay_feedback', 'init');

// Page markup lives in templates/essay_feedback.mustache and the behaviour in
// amd/src/essay_feedback.js (CONTRIB-10574 #273). The course id is the only
// value the module needs from PHP, and it rides on a data attribute rather
// than a js_call_amd argument so the template stays self-contained.
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/essay_feedback', [
    'courseid' => $courseid,
]);
echo $OUTPUT->footer();
