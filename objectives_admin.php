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
use local_ai_course_assistant\cross_course_mastery;

// v5.1.7: was required_param + 404. Now optional_param with a friendly
// course picker landing when accessed bare. Direct links unchanged.
$courseid = optional_param('courseid', 0, PARAM_INT);
if ($courseid <= 0) {
    require_login();
    \local_ai_course_assistant\page_helpers::render_course_picker_landing(
        '/local/ai_course_assistant/objectives_admin.php',
        get_string(
            'coursepicker:title',
            'local_ai_course_assistant',
            get_string('objectives:title', 'local_ai_course_assistant')
        ),
        'local/ai_course_assistant:manage'
    );
    exit;
}
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
// POST actions
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    if ($action === 'toggle_master') {
        $enabled = (bool) optional_param('enabled', 0, PARAM_BOOL);
        objective_manager::set_enabled_for_course($courseid, $enabled);
        redirect(
            $pageurl,
            get_string('objectives:toggled', 'local_ai_course_assistant'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else if ($action === 'toggle_chip') {
        $enabled = (bool) optional_param('enabled', 0, PARAM_BOOL);
        objective_manager::set_chip_enabled_for_course($courseid, $enabled);
        redirect(
            $pageurl,
            get_string('objectives:toggled', 'local_ai_course_assistant'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else if ($action === 'toggle_dashboard') {
        $enabled = (bool) optional_param('enabled', 0, PARAM_BOOL);
        objective_manager::set_dashboard_enabled_for_course($courseid, $enabled);
        redirect(
            $pageurl,
            get_string('objectives:toggled', 'local_ai_course_assistant'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else if ($action === 'import_detected') {
        $source = required_param('source', PARAM_ALPHA);
        // PARAM_RAW is required to receive the JSON envelope of detected
        // objectives intact (it is a json_encode'd array, not a scalar). Each
        // decoded field is cleaned below with clean_param(PARAM_TEXT) - the same
        // treatment the manual "add objective" form applies to title and
        // description - before anything reaches the DB. Codes stay PARAM_TEXT
        // rather than the manual form's PARAM_ALPHANUMEXT because a detected code
        // legitimately contains dots ("1.2") that ALPHANUMEXT would silently eat.
        $payload = required_param('payload', PARAM_RAW);
        $items = json_decode($payload, true);
        if (is_array($items)) {
            $cleanitems = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $cleanitems[] = [
                    'title' => clean_param((string) ($item['title'] ?? ''), PARAM_TEXT),
                    'description' => clean_param((string) ($item['description'] ?? ''), PARAM_TEXT),
                    'code' => clean_param((string) ($item['code'] ?? ''), PARAM_TEXT),
                    'external_ref' => clean_param((string) ($item['external_ref'] ?? ''), PARAM_TEXT),
                ];
            }
            $items = $cleanitems;
        }
        if (!is_array($items)) {
            redirect(
                $pageurl,
                get_string('objectives:err_invalid_import', 'local_ai_course_assistant'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        $ids = objective_manager::import_batch($courseid, $source, $items);
        redirect(
            $pageurl,
            get_string('objectives:imported', 'local_ai_course_assistant', count($ids)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else if ($action === 'import_llm') {
        $items = objective_manager::extract_via_llm($courseid);
        if (empty($items)) {
            redirect(
                $pageurl,
                get_string('objectives:llm_empty', 'local_ai_course_assistant'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        $ids = objective_manager::import_batch($courseid, 'llm', $items);
        redirect(
            $pageurl,
            get_string('objectives:imported', 'local_ai_course_assistant', count($ids)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
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
        redirect(
            $pageurl,
            get_string('objectives:saved', 'local_ai_course_assistant'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else if ($action === 'update') {
        $id = required_param('id', PARAM_INT);
        $obj = objective_manager::get($id);
        if (!$obj || (int) $obj->courseid !== $courseid) {
            redirect($pageurl, get_string('objectives:err_unknown', 'local_ai_course_assistant'), null, \core\output\notification::NOTIFY_ERROR);
        }
        objective_manager::update($id, [
            'title' => trim((string) required_param('title', PARAM_TEXT)),
            'description' => (string) optional_param('description', '', PARAM_TEXT),
            'code' => (string) optional_param('code', '', PARAM_ALPHANUMEXT),
        ]);
        redirect(
            $pageurl,
            get_string('objectives:saved', 'local_ai_course_assistant'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else if ($action === 'set_prereqs') {
        $id = required_param('id', PARAM_INT);
        $obj = objective_manager::get($id);
        if (!$obj || (int) $obj->courseid !== $courseid) {
            redirect($pageurl, get_string('objectives:err_unknown', 'local_ai_course_assistant'), null, \core\output\notification::NOTIFY_ERROR);
        }
        $raw = optional_param_array('prereqs', [], PARAM_INT);
        objective_manager::set_prereq_ids($id, $raw);
        redirect(
            $pageurl,
            get_string('objectives:saved', 'local_ai_course_assistant'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else if ($action === 'delete') {
        $id = required_param('id', PARAM_INT);
        $obj = objective_manager::get($id);
        if ($obj && (int) $obj->courseid === $courseid) {
            objective_manager::delete($id);
        }
        redirect(
            $pageurl,
            get_string('objectives:deleted', 'local_ai_course_assistant'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
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
        redirect(
            $pageurl,
            get_string('objectives:deleted_all', 'local_ai_course_assistant'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else if ($action === 'rebuild_links') {
        // v5.7.0: rebuild cross-course mastery links on demand instead of
        // waiting for the daily scheduled task. The rebuild is global (all
        // courses) but triggered contextually from this course's page.
        $counts = cross_course_mastery::rebuild_links();
        redirect(
            $pageurl,
            get_string(
                'objectives:rebuild_links_done',
                'local_ai_course_assistant',
                (object) [
                    'total' => (int) $counts['total'],
                    'ref' => (int) $counts['ref'],
                    'exact' => (int) $counts['title_exact'],
                    'fuzzy' => (int) $counts['title_fuzzy'],
                ]
            ),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// ---------------------------------------------------------------------
// Page render
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

// The checkbox display state reads the raw per-course flag (unset => unchecked)
// while the hidden "enabled" value the form posts is the inverse of the
// resolver's effective value — both carried over from the pre-template page.
$chipchecked = (bool) get_config('local_ai_course_assistant', 'mastery_chip_enabled_course_' . $courseid);
$dashboardchecked = (bool) get_config(
    'local_ai_course_assistant',
    'mastery_dashboard_enabled_course_' . $courseid
);

$templatedata = [
    'formaction' => $pageurl->out(false),
    'sesskey' => sesskey(),

    // Toggles card.
    'togglesheading' => get_string('objectives:toggles_heading', 'local_ai_course_assistant'),
    'masterenabled' => $masterenabled,
    'togglemastervalue' => $masterenabled ? 0 : 1,
    'togglemasterlabel' => get_string('objectives:toggle_master', 'local_ai_course_assistant'),
    'chipchecked' => $chipchecked,
    'togglechipvalue' => $chipenabled ? 0 : 1,
    'togglechiplabel' => get_string('objectives:toggle_chip', 'local_ai_course_assistant'),
    'togglechiphelp' => get_string('objectives:toggle_chip_help', 'local_ai_course_assistant'),
    'dashboardchecked' => $dashboardchecked,
    'toggledashboardvalue' => objective_manager::is_dashboard_enabled_for_course($courseid) ? 0 : 1,
    'toggledashboardlabel' => get_string('objectives:toggle_dashboard', 'local_ai_course_assistant'),
    'toggledashboardhelp' => get_string('objectives:toggle_dashboard_help', 'local_ai_course_assistant'),

    // Cross-course mastery rebuild card (v5.7.0); populated below when enabled.
    'rebuild' => false,

    // Look-first banners; populated below.
    'detected' => false,
    'nonedetected' => false,
    'nonedetectedtext' => get_string('objectives:none_detected', 'local_ai_course_assistant'),
    'importllmlabel' => get_string('objectives:import_llm', 'local_ai_course_assistant'),

    // Objectives table.
    'hasobjectives' => !empty($objectives),
    'listheading' => get_string('objectives:list_heading', 'local_ai_course_assistant', count($objectives)),
    'colcode' => get_string('objectives:col_code', 'local_ai_course_assistant'),
    'coltitle' => get_string('objectives:col_title', 'local_ai_course_assistant'),
    'colsource' => get_string('objectives:col_source', 'local_ai_course_assistant'),
    'colactions' => get_string('objectives:col_actions', 'local_ai_course_assistant'),
    'editlabel' => get_string('edit'),
    'descriptionlabel' => get_string('description'),
    'savechangeslabel' => get_string('savechanges'),
    'deletelabel' => get_string('delete'),
    'moveuplabel' => get_string('objectives:move_up', 'local_ai_course_assistant'),
    'movedownlabel' => get_string('objectives:move_down', 'local_ai_course_assistant'),
    'deleteconfirmjs' => 'return confirm('
        . json_encode(get_string('objectives:delete_confirm', 'local_ai_course_assistant')) . ');',
    'saveprereqslabel' => get_string('save', 'core') . ' '
        . get_string('objectives:prereqs_label', 'local_ai_course_assistant'),
    'objectives' => [],
    'deletealllabel' => get_string('objectives:delete_all', 'local_ai_course_assistant'),
    'deleteallconfirmjs' => 'return confirm('
        . json_encode(get_string('objectives:delete_all_confirm', 'local_ai_course_assistant')) . ');',

    // Add-new card.
    'addheading' => get_string('objectives:add_heading', 'local_ai_course_assistant'),
    'addsubmitlabel' => get_string('objectives:add_submit', 'local_ai_course_assistant'),
];

// Cross-course mastery rebuild (v5.7.0). Shown only when cross-course
// mastery is enabled for this course. The daily task keeps links fresh;
// this button forces an immediate rebuild after editing objectives.
if (cross_course_mastery::is_enabled_for_course($courseid)) {
    $templatedata['rebuild'] = [
        'heading' => get_string('objectives:rebuild_links_heading', 'local_ai_course_assistant'),
        'help' => \local_ai_course_assistant\branding::apply(
            get_string('objectives:rebuild_links_help', 'local_ai_course_assistant')
        ),
        'button' => get_string('objectives:rebuild_links_button', 'local_ai_course_assistant'),
    ];
}

// Look-first banner (empty course only).
if ($detected !== null && $detected['source'] !== 'none') {
    $sourcelabel = get_string('objectives:source_' . $detected['source'], 'local_ai_course_assistant');
    $count = count($detected['objectives']);
    $titles = [];
    foreach (array_slice($detected['objectives'], 0, 10) as $item) {
        $titles[] = ['title' => (string) $item['title']];
    }
    $templatedata['detected'] = [
        'heading' => get_string(
            'objectives:detected_heading',
            'local_ai_course_assistant',
            (object) ['count' => $count, 'source' => $sourcelabel]
        ),
        'titles' => $titles,
        'hasmore' => $count > 10,
        'moretext' => $count > 10
            ? get_string('objectives:more_items', 'local_ai_course_assistant', $count - 10)
            : '',
        'source' => $detected['source'],
        'payload' => json_encode($detected['objectives']),
        'importlabel' => get_string('objectives:import_detected', 'local_ai_course_assistant'),
    ];
} else if (empty($objectives)) {
    $templatedata['nonedetected'] = true;
}

// Existing objectives table rows.
$rowcount = count($objectives);
$i = 1;
foreach ($objectives as $obj) {
    // v3.9.24: per-objective prerequisite editor. Multi-select of
    // sibling objectives; saving writes the comma-separated ids to the
    // objective row.
    $currentprereqs = objective_manager::get_prereq_ids($obj);
    $preqsummary = [];
    foreach ($currentprereqs as $pid) {
        if (isset($objectives[$pid])) {
            $p = $objectives[$pid];
            $preqsummary[] = $p->code ? "[{$p->code}] " . shorten_text($p->title, 40)
                : shorten_text($p->title, 40);
        }
    }
    $prereqoptions = [];
    foreach ($objectives as $sibid => $sibobj) {
        if ((int) $sibid === (int) $obj->id) {
            continue;
        }
        $prereqoptions[] = [
            'value' => (int) $sibid,
            'selected' => in_array((int) $sibid, $currentprereqs, true),
            'label' => ($sibobj->code ? "[{$sibobj->code}] " : '') . shorten_text($sibobj->title, 60),
        ];
    }

    $templatedata['objectives'][] = [
        'index' => $i,
        'id' => (int) $obj->id,
        'codedisplay' => $obj->code ?: '—',
        'code' => (string) $obj->code,
        'title' => (string) $obj->title,
        'description' => (string) $obj->description,
        'hasdescription' => !empty($obj->description),
        // Escaped BEFORE shortening (s() then shorten_text), matching the
        // pre-template page, so the template renders it unescaped.
        'descriptionshort' => !empty($obj->description)
            ? shorten_text(s((string) $obj->description), 160)
            : '',
        'sourcelabel' => get_string('objectives:source_' . $obj->source, 'local_ai_course_assistant'),
        'prereqsummary' => get_string(
            'objectives:prereqs_summary',
            'local_ai_course_assistant',
            empty($preqsummary)
                ? get_string('objectives:prereqs_none', 'local_ai_course_assistant')
                : implode('; ', $preqsummary)
        ),
        'prereqoptions' => $prereqoptions,
        'rowclass' => $i === $rowcount ? 'lastrow' : '',
    ];
    $i++;
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/objectives_admin', $templatedata);
echo $OUTPUT->footer();
