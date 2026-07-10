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
 * Instructor list of Soapbox presentation assignments in a course (v6.8.12).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\soapbox_assignment_manager;

require_login();

$courseid = required_param('courseid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

$course = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('local/ai_course_assistant:manage', $context);

$pageurl = new moodle_url('/local/ai_course_assistant/soapbox_assign.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_course($course);
$PAGE->set_title('Soapbox assignments');
$PAGE->set_heading($course->fullname);

// Delete action (sesskey-protected, with a confirm step).
if ($action === 'delete' && $id) {
    $assign = soapbox_assignment_manager::get_assignment($id);
    if (!$assign || (int) $assign->courseid !== $courseid) {
        throw new \moodle_exception('invalidrecord', 'error');
    }
    if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
        soapbox_assignment_manager::delete_assignment($id);
        redirect($pageurl, 'Assignment deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    $confirmurl = new moodle_url($pageurl, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]);
    echo $OUTPUT->confirm(
        'Delete "' . format_string($assign->name) . '" and all its recordings? This cannot be undone.',
        $confirmurl, $pageurl);
    echo $OUTPUT->footer();
    exit;
}

$assignments = soapbox_assignment_manager::get_course_assignments($courseid);

echo $OUTPUT->header();
echo $OUTPUT->heading('Soapbox assignments');

echo html_writer::div(
    $OUTPUT->single_button(
        new moodle_url('/local/ai_course_assistant/soapbox_assign_edit.php', ['courseid' => $courseid]),
        'Add assignment', 'get'),
    'mb-3');

if (empty($assignments)) {
    echo $OUTPUT->notification('No Soapbox assignments yet.', 'info');
} else {
    $table = new html_table();
    $table->head = ['Name', 'Type', 'Recording', 'Length', 'Kept', 'Visible', 'Actions'];
    $table->attributes['class'] = 'generaltable';
    foreach ($assignments as $a) {
        $editurl = new moodle_url('/local/ai_course_assistant/soapbox_assign_edit.php',
            ['courseid' => $courseid, 'id' => $a->id]);
        $delurl = new moodle_url($pageurl, ['action' => 'delete', 'id' => $a->id]);
        $actions = html_writer::link($editurl, 'Edit')
            . ' &middot; '
            . html_writer::link($delurl, 'Delete');
        $length = ((int) $a->min_seconds) . '-' . ((int) $a->max_seconds) . 's';
        $table->data[] = [
            format_string($a->name),
            s($a->ptype),
            $a->mode === 'audio' ? 'Audio' : 'Video',
            $length,
            (int) $a->stored_attempts,
            $a->visible ? 'Yes' : 'No',
            $actions,
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
