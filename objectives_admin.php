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
 * Per-course learning objectives admin page.
 *
 * Look-first authoring: if the course has no objectives yet, the page
 * scans Moodle competencies, course summary, and section content to
 * propose a starting list. Instructor approves and edits.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\objective_manager;

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/ai_course_assistant:manage', $context);

$pageurl = new moodle_url('/local/ai_course_assistant/objectives_admin.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('objectives:title', 'local_ai_course_assistant'));
$PAGE->set_heading($course->fullname . ': ' . get_string('objectives:title', 'local_ai_course_assistant'));
$PAGE->set_pagelayout('admin');

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

// ---------------------------------------------------------------------
//  POST actions
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    if ($action === 'toggle_master') {
        $enabled = (bool) optional_param('enabled', 0, PARAM_BOOL);
        objective_manager::set_enabled_for_course($courseid, $enabled);
        redirect($pageurl, get_string('objectives:toggled', 'local_ai_course_assistant'),
            null, \core\output\notification::NOTIFY_SUCCESS);

    } else if ($action === 'toggle_chip') {
        $enabled = (bool) optional_param('enabled', 0, PARAM_BOOL);
        objective_manager::set_chip_enabled_for_course($courseid, $enabled);
        redirect($pageurl, get_string('objectives:toggled', 'local_ai_course_assistant'),
            null, \core\output\notification::NOTIFY_SUCCESS);

    } else if ($action === 'toggle_dashboard') {
        $enabled = (bool) optional_param('enabled', 0, PARAM_BOOL);
        objective_manager::set_dashboard_enabled_for_course($courseid, $enabled);
        redirect($pageurl, get_string('objectives:toggled', 'local_ai_course_assistant'),
            null, \core\output\notification::NOTIFY_SUCCESS);

    } else if ($action === 'import_detected') {
        $source = required_param('source', PARAM_ALPHA);
        $payload = required_param('payload', PARAM_RAW);
        $items = json_decode($payload, true);
        if (!is_array($items)) {
            redirect($pageurl, 'Invalid import payload.',
                null, \core\output\notification::NOTIFY_ERROR);
        }
        $ids = objective_manager::import_batch($courseid, $source, $items);
        redirect($pageurl,
            get_string('objectives:imported', 'local_ai_course_assistant', count($ids)),
            null, \core\output\notification::NOTIFY_SUCCESS);

    } else if ($action === 'import_llm') {
        $items = objective_manager::extract_via_llm($courseid);
        if (empty($items)) {
            redirect($pageurl, get_string('objectives:llm_empty', 'local_ai_course_assistant'),
                null, \core\output\notification::NOTIFY_ERROR);
        }
        $ids = objective_manager::import_batch($courseid, 'llm', $items);
        redirect($pageurl,
            get_string('objectives:imported', 'local_ai_course_assistant', count($ids)),
            null, \core\output\notification::NOTIFY_SUCCESS);

    } else if ($action === 'create') {
        $title = trim(required_param('title', PARAM_TEXT));
        if ($title !== '') {
            objective_manager::create(
                $courseid,
                $title,
                (string) optional_param('description', '', PARAM_TEXT),
                (string) optional_param('code', '', PARAM_ALPHANUMEXT)
            );
        }
        redirect($pageurl, get_string('objectives:saved', 'local_ai_course_assistant'),
            null, \core\output\notification::NOTIFY_SUCCESS);

    } else if ($action === 'update') {
        $id = required_param('id', PARAM_INT);
        $obj = objective_manager::get($id);
        if (!$obj || (int) $obj->courseid !== $courseid) {
            redirect($pageurl, 'Unknown objective.', null, \core\output\notification::NOTIFY_ERROR);
        }
        objective_manager::update($id, [
            'title' => trim((string) required_param('title', PARAM_TEXT)),
            'description' => (string) optional_param('description', '', PARAM_TEXT),
            'code' => (string) optional_param('code', '', PARAM_ALPHANUMEXT),
        ]);
        redirect($pageurl, get_string('objectives:saved', 'local_ai_course_assistant'),
            null, \core\output\notification::NOTIFY_SUCCESS);

    } else if ($action === 'delete') {
        $id = required_param('id', PARAM_INT);
        $obj = objective_manager::get($id);
        if ($obj && (int) $obj->courseid === $courseid) {
            objective_manager::delete($id);
        }
        redirect($pageurl, get_string('objectives:deleted', 'local_ai_course_assistant'),
            null, \core\output\notification::NOTIFY_SUCCESS);

    } else if ($action === 'move_up' || $action === 'move_down') {
        $id = required_param('id', PARAM_INT);
        $all = array_values(objective_manager::list_for_course($courseid));
        $idx = null;
        foreach ($all as $i => $row) {
            if ((int) $row->id === $id) {
                $idx = $i;
                break;
            }
        }
        if ($idx !== null) {
            $target = $action === 'move_up' ? $idx - 1 : $idx + 1;
            if ($target >= 0 && $target < count($all)) {
                $a = $all[$idx];
                $b = $all[$target];
                objective_manager::update((int) $a->id, ['sortorder' => (int) $b->sortorder]);
                objective_manager::update((int) $b->id, ['sortorder' => (int) $a->sortorder]);
            }
        }
        redirect($pageurl);

    } else if ($action === 'delete_all') {
        foreach (objective_manager::list_for_course($courseid) as $row) {
            objective_manager::delete((int) $row->id);
        }
        redirect($pageurl, get_string('objectives:deleted_all', 'local_ai_course_assistant'),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// ---------------------------------------------------------------------
//  Page render
// ---------------------------------------------------------------------
$masterenabled = objective_manager::is_enabled_for_course($courseid);
$chipenabled = objective_manager::is_chip_enabled_for_course($courseid);
$objectives = objective_manager::list_for_course($courseid);
$detected = null;
if (empty($objectives)) {
    // Run look-first detection for an empty course. LLM extraction is not run
    // here because it's expensive; the admin triggers it explicitly.
    $detected = objective_manager::detect_best_source($courseid);
}

echo $OUTPUT->header();

// Toggles block.
echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('objectives:toggles_heading', 'local_ai_course_assistant'),
    ['class' => 'h5 mb-3']);

// Master switch form.
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(), 'class' => 'd-inline-block mr-4']);
echo html_writer::input_hidden_params(new moodle_url('', ['sesskey' => sesskey(), 'action' => 'toggle_master']));
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'enabled', 'value' => $masterenabled ? 0 : 1]);
echo html_writer::tag('label',
    html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'checked' => $masterenabled ? 'checked' : null,
        'onchange' => 'this.form.submit()',
    ]) . ' ' . get_string('objectives:toggle_master', 'local_ai_course_assistant'),
    ['class' => 'mb-0']);
echo html_writer::end_tag('form');

// Chip toggle form.
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(), 'class' => 'd-inline-block']);
echo html_writer::input_hidden_params(new moodle_url('', ['sesskey' => sesskey(), 'action' => 'toggle_chip']));
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'enabled',
    'value' => objective_manager::is_chip_enabled_for_course($courseid) ? 0 : 1]);
$chipchecked = (bool) get_config('local_ai_course_assistant', 'mastery_chip_enabled_course_' . $courseid);
echo html_writer::tag('label',
    html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'checked' => $chipchecked ? 'checked' : null,
        'disabled' => $masterenabled ? null : 'disabled',
        'onchange' => 'this.form.submit()',
    ]) . ' ' . get_string('objectives:toggle_chip', 'local_ai_course_assistant'),
    ['class' => 'mb-0']);
echo html_writer::tag('small', ' ' . get_string('objectives:toggle_chip_help', 'local_ai_course_assistant'),
    ['class' => 'text-muted']);
echo html_writer::end_tag('form');

// Dashboard tab toggle form (v3.9.18). Gated on the master switch.
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(), 'class' => 'd-inline-block ml-3']);
echo html_writer::input_hidden_params(new moodle_url('', ['sesskey' => sesskey(), 'action' => 'toggle_dashboard']));
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'enabled',
    'value' => objective_manager::is_dashboard_enabled_for_course($courseid) ? 0 : 1]);
$dashboardchecked = (bool) get_config('local_ai_course_assistant',
    'mastery_dashboard_enabled_course_' . $courseid);
echo html_writer::tag('label',
    html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'checked' => $dashboardchecked ? 'checked' : null,
        'disabled' => $masterenabled ? null : 'disabled',
        'onchange' => 'this.form.submit()',
    ]) . ' ' . get_string('objectives:toggle_dashboard', 'local_ai_course_assistant'),
    ['class' => 'mb-0']);
echo html_writer::tag('small', ' ' . get_string('objectives:toggle_dashboard_help', 'local_ai_course_assistant'),
    ['class' => 'text-muted']);
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

// Look-first banner (empty course only).
if ($detected !== null && $detected['source'] !== 'none') {
    $sourcelabel = get_string('objectives:source_' . $detected['source'], 'local_ai_course_assistant');
    $count = count($detected['objectives']);
    echo html_writer::start_div('alert alert-info');
    echo html_writer::tag('strong',
        get_string('objectives:detected_heading', 'local_ai_course_assistant',
            (object)['count' => $count, 'source' => $sourcelabel]));
    echo html_writer::start_tag('ul', ['class' => 'mt-2 mb-2']);
    foreach (array_slice($detected['objectives'], 0, 10) as $item) {
        echo html_writer::tag('li', s($item['title']));
    }
    if ($count > 10) {
        echo html_writer::tag('li', '… ' . ($count - 10) . ' more',
            ['class' => 'text-muted']);
    }
    echo html_writer::end_tag('ul');
    // Import form.
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(), 'class' => 'd-inline']);
    echo html_writer::input_hidden_params(new moodle_url('', [
        'sesskey' => sesskey(),
        'action' => 'import_detected',
        'source' => $detected['source'],
    ]));
    echo html_writer::empty_tag('input', [
        'type' => 'hidden', 'name' => 'payload',
        'value' => json_encode($detected['objectives']),
    ]);
    echo html_writer::tag('button', get_string('objectives:import_detected', 'local_ai_course_assistant'),
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');

    // LLM alt path.
    echo ' ';
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(), 'class' => 'd-inline']);
    echo html_writer::input_hidden_params(new moodle_url('', [
        'sesskey' => sesskey(), 'action' => 'import_llm',
    ]));
    echo html_writer::tag('button', get_string('objectives:import_llm', 'local_ai_course_assistant'),
        ['type' => 'submit', 'class' => 'btn btn-outline-secondary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
} else if (empty($objectives)) {
    echo html_writer::start_div('alert alert-warning');
    echo get_string('objectives:none_detected', 'local_ai_course_assistant');
    echo ' ';
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(), 'class' => 'd-inline']);
    echo html_writer::input_hidden_params(new moodle_url('', [
        'sesskey' => sesskey(), 'action' => 'import_llm',
    ]));
    echo html_writer::tag('button', get_string('objectives:import_llm', 'local_ai_course_assistant'),
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
}

// Mini form builder for inline action buttons (move/delete).
$miniform = function (string $action, int $id, string $label,
        string $btnclass = 'btn-light', string $confirm = '') use ($pageurl): string {
    $attrs = ['method' => 'post', 'action' => $pageurl->out(), 'class' => 'd-inline'];
    if ($confirm !== '') {
        $attrs['onsubmit'] = 'return confirm(' . json_encode($confirm) . ');';
    }
    return html_writer::start_tag('form', $attrs)
        . html_writer::input_hidden_params(new \moodle_url('', [
            'sesskey' => sesskey(), 'action' => $action, 'id' => $id,
        ]))
        . html_writer::tag('button', $label,
            ['type' => 'submit', 'class' => 'btn btn-sm ' . $btnclass])
        . html_writer::end_tag('form');
};

// Existing objectives table.
if (!empty($objectives)) {
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', get_string('objectives:list_heading', 'local_ai_course_assistant',
        count($objectives)), ['class' => 'h5']);

    $table = new html_table();
    $table->head = [
        '#',
        get_string('objectives:col_code', 'local_ai_course_assistant'),
        get_string('objectives:col_title', 'local_ai_course_assistant'),
        get_string('objectives:col_source', 'local_ai_course_assistant'),
        get_string('objectives:col_actions', 'local_ai_course_assistant'),
    ];
    $i = 1;
    foreach ($objectives as $obj) {
        $editform = html_writer::start_tag('details') .
            html_writer::tag('summary', get_string('edit')) .
            html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(),
                'class' => 'mt-2']) .
            html_writer::input_hidden_params(new moodle_url('', [
                'sesskey' => sesskey(), 'action' => 'update', 'id' => $obj->id,
            ])) .
            html_writer::tag('label', get_string('objectives:col_code', 'local_ai_course_assistant') . ' ' .
                html_writer::empty_tag('input', [
                    'type' => 'text', 'name' => 'code', 'value' => (string) $obj->code,
                    'maxlength' => 40, 'class' => 'form-control form-control-sm',
                ])) .
            html_writer::tag('label', get_string('objectives:col_title', 'local_ai_course_assistant') . ' ' .
                html_writer::empty_tag('input', [
                    'type' => 'text', 'name' => 'title', 'value' => (string) $obj->title,
                    'maxlength' => 255, 'required' => 'required',
                    'class' => 'form-control form-control-sm',
                ])) .
            html_writer::tag('label', get_string('description') . ' ' .
                html_writer::tag('textarea', s((string) $obj->description), [
                    'name' => 'description', 'rows' => 2,
                    'class' => 'form-control form-control-sm',
                ])) .
            html_writer::tag('button', get_string('savechanges'),
                ['type' => 'submit', 'class' => 'btn btn-sm btn-primary mt-2']) .
            html_writer::end_tag('form') .
            html_writer::end_tag('details');

        $actions = implode(' ', [
            $miniform('move_up', (int) $obj->id, '↑', 'btn-light'),
            $miniform('move_down', (int) $obj->id, '↓', 'btn-light'),
            $miniform('delete', (int) $obj->id,
                get_string('delete'), 'btn-danger',
                get_string('objectives:delete_confirm', 'local_ai_course_assistant')),
        ]);

        $titlecell = html_writer::tag('div', s($obj->title), ['class' => 'font-weight-bold']);
        if (!empty($obj->description)) {
            $titlecell .= html_writer::tag('div', shorten_text(s($obj->description), 160),
                ['class' => 'text-muted small']);
        }
        $titlecell .= $editform;

        $table->data[] = [
            $i++,
            $obj->code ?: '—',
            $titlecell,
            get_string('objectives:source_' . $obj->source, 'local_ai_course_assistant'),
            $actions,
        ];
    }
    echo html_writer::table($table);
    echo html_writer::end_div();
    echo html_writer::end_div();

    // Delete-all escape hatch.
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(),
        'onsubmit' => 'return confirm(' . json_encode(get_string('objectives:delete_all_confirm',
            'local_ai_course_assistant')) . ');']);
    echo html_writer::input_hidden_params(new moodle_url('', [
        'sesskey' => sesskey(), 'action' => 'delete_all',
    ]));
    echo html_writer::tag('button',
        get_string('objectives:delete_all', 'local_ai_course_assistant'),
        ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-danger']);
    echo html_writer::end_tag('form');
}

// Add-new form (always visible).
echo html_writer::start_div('card mt-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('objectives:add_heading', 'local_ai_course_assistant'),
    ['class' => 'h5']);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out()]);
echo html_writer::input_hidden_params(new moodle_url('', [
    'sesskey' => sesskey(), 'action' => 'create',
]));
echo html_writer::tag('label', get_string('objectives:col_code', 'local_ai_course_assistant'),
    ['for' => 'obj-new-code']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'code', 'id' => 'obj-new-code',
    'maxlength' => 40, 'class' => 'form-control',
]);
echo html_writer::tag('label', get_string('objectives:col_title', 'local_ai_course_assistant'),
    ['for' => 'obj-new-title', 'class' => 'mt-2']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'title', 'id' => 'obj-new-title',
    'maxlength' => 255, 'required' => 'required', 'class' => 'form-control',
]);
echo html_writer::tag('label', get_string('description'),
    ['for' => 'obj-new-desc', 'class' => 'mt-2']);
echo html_writer::tag('textarea', '', [
    'name' => 'description', 'id' => 'obj-new-desc',
    'rows' => 2, 'class' => 'form-control',
]);
echo html_writer::tag('button', get_string('objectives:add_submit', 'local_ai_course_assistant'),
    ['type' => 'submit', 'class' => 'btn btn-primary mt-3']);
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
