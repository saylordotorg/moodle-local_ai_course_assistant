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
 * Shows Moodle's own AI usage policy and records the learner's acceptance.
 *
 * When chat goes through the Moodle AI (core_ai) provider, core expects the
 * learner to have accepted the site's AI usage policy first. Core's own
 * placements ask in a browser dialog; SOLA's chat links here instead. The
 * policy text, the button labels and the acceptance record are core's own
 * (get_string('userpolicy', 'core_ai'), \core_ai\manager::user_policy_accepted()),
 * so it is the same acceptance the site's policy acceptance report lists.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\branding;

$courseid = optional_param('courseid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

if ($courseid > 0 && $courseid != SITEID) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($course->id);
    $returnurl = new moodle_url('/course/view.php', ['id' => $course->id]);
} else {
    require_login();
    $courseid = 0;
    $context = context_system::instance();
    $returnurl = new moodle_url('/');
}
require_capability('moodle/ai:acceptpolicy', context_user::instance($USER->id));

$pageurl = new moodle_url('/local/ai_course_assistant/coreai_policy.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);

// Moodle 4.5 to 5.3 all carry these core_ai strings (checked on 4.5.15 and 5.3.0).
if (!class_exists('\\core_ai\\manager') || !get_string_manager()->string_exists('userpolicy', 'core_ai')) {
    redirect($returnurl, branding::str('coreai:policy_unavailable'), null, \core\output\notification::NOTIFY_WARNING);
}
if (\core_ai\manager::get_user_policy_status((int) $USER->id)) {
    redirect($returnurl, get_string('coreai:policy_already', 'local_ai_course_assistant'), null,
        \core\output\notification::NOTIFY_INFO);
}
if ($confirm) {
    require_sesskey();
    \core_ai\manager::user_policy_accepted((int) $USER->id, (int) $context->id);
    redirect($returnurl, branding::str('coreai:policy_accepted'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$title = get_string('aiusagepolicy', 'core_ai');
$PAGE->set_title($title);
$PAGE->set_heading($title);

$accept = new single_button(
    new moodle_url($pageurl, ['confirm' => 1, 'sesskey' => sesskey()]),
    get_string('acceptai', 'core_ai'),
    'post',
    single_button::BUTTON_PRIMARY
);
$decline = new single_button(
    $returnurl,
    get_string('declineaipolicy', 'core_ai'),
    'get'
);

echo $OUTPUT->header();
echo $OUTPUT->confirm(format_text(get_string('userpolicy', 'core_ai'), FORMAT_HTML, ['context' => $context]), $accept, $decline);
echo $OUTPUT->footer();
