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
 * Create or edit a Soapbox presentation assignment (v6.8.12).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\soapbox_assignment_manager;
use local_ai_course_assistant\form\soapbox_assignment_form;

require_login();

$courseid = required_param('courseid', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);

$course = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('local/ai_course_assistant:manage', $context);

$listurl = new moodle_url('/local/ai_course_assistant/soapbox_assign.php', ['courseid' => $courseid]);
$pageurl = new moodle_url('/local/ai_course_assistant/soapbox_assign_edit.php',
    ['courseid' => $courseid, 'id' => $id]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_course($course);
$PAGE->set_title('Soapbox assignment');
$PAGE->set_heading($course->fullname);

$existing = null;
if ($id) {
    $existing = soapbox_assignment_manager::get_assignment($id);
    if (!$existing || (int) $existing->courseid !== $courseid) {
        throw new \moodle_exception('invalidrecord', 'error');
    }
}

$form = new soapbox_assignment_form($pageurl->out(false));

if ($form->is_cancelled()) {
    redirect($listurl);
}

if ($data = $form->get_data()) {
    $payload = [
        'name'            => $data->name,
        'intro'           => $data->intro['text'] ?? '',
        'introformat'     => $data->intro['format'] ?? FORMAT_HTML,
        'ptype'           => $data->ptype,
        'mode'            => $data->mode,
        'min_seconds'     => $data->min_seconds,
        'max_seconds'     => $data->max_seconds,
        'max_attempts'    => $data->max_attempts,
        'stored_attempts' => $data->stored_attempts,
        'visible'         => $data->visible,
    ];
    if ($id) {
        soapbox_assignment_manager::update_assignment($id, $payload);
        $notice = 'Assignment updated.';
    } else {
        soapbox_assignment_manager::create_assignment($courseid, $payload);
        $notice = 'Assignment created.';
    }
    redirect($listurl, $notice, null, \core\output\notification::NOTIFY_SUCCESS);
}

// Pre-fill for editing.
if ($existing) {
    $form->set_data([
        'id'              => $existing->id,
        'courseid'        => $courseid,
        'name'            => $existing->name,
        'intro'           => ['text' => (string) $existing->intro, 'format' => (int) $existing->introformat],
        'ptype'           => $existing->ptype,
        'mode'            => $existing->mode,
        'min_seconds'     => $existing->min_seconds,
        'max_seconds'     => $existing->max_seconds,
        'max_attempts'    => $existing->max_attempts,
        'stored_attempts' => $existing->stored_attempts,
        'visible'         => $existing->visible,
    ]);
} else {
    $form->set_data(['courseid' => $courseid]);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($id ? 'Edit Soapbox assignment' : 'New Soapbox assignment');
$form->display();
echo $OUTPUT->footer();
