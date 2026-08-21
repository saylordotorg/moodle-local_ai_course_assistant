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
 * Course management for SOLA — admin-only.
 *
 * Per-course AI Assistant enable/disable, Usability Testing toggles,
 * bulk actions, search and filters. Lives separate from the analytics
 * dashboard so that page can stay focused on data.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);

$action = optional_param('action', '', PARAM_ALPHA);

$selfurl = new moodle_url('/local/ai_course_assistant/courses_admin.php');

// Per-course SOLA enable/disable toggle.
if ($action === 'toggle' && confirm_sesskey()) {
    $togglecourseid = required_param('togglecourseid', PARAM_INT);
    $enabled = required_param('enabled', PARAM_INT);
    set_config('sola_enabled_course_' . $togglecourseid, $enabled ? '1' : '0', 'local_ai_course_assistant');
    redirect($selfurl);
}

// Per-course Usability Testing toggle ('' = inherit, '1' = on, '0' = off).
if ($action === 'toggleut' && confirm_sesskey()) {
    $togglecourseid = required_param('togglecourseid', PARAM_INT);
    // Three-way override: '' = inherit the site default, '1' = on, '0' = off.
    // PARAM_ALPHANUMEXT (not PARAM_INT) because the empty string must survive as
    // a distinct value; anything outside the three known tokens is treated as
    // inherit rather than written to config.
    $utvalue = required_param('utvalue', PARAM_ALPHANUMEXT);
    if (!in_array($utvalue, ['', '0', '1'], true)) {
        $utvalue = '';
    }
    if ($utvalue === '') {
        unset_config('sola_usertesting_course_' . $togglecourseid, 'local_ai_course_assistant');
    } else {
        set_config('sola_usertesting_course_' . $togglecourseid, $utvalue, 'local_ai_course_assistant');
    }
    redirect($selfurl);
}

// Bulk SOLA enable/disable.
if ($action === 'bulktoggle' && confirm_sesskey()) {
    $enabled = required_param('enabled', PARAM_INT);
    $scope = optional_param('scope', 'all', PARAM_ALPHA);
    // Comma-separated course IDs from the row checkboxes; PARAM_SEQUENCE is
    // exactly "digits and commas" and is still int-cast below.
    $selectedids = optional_param('selected_ids', '', PARAM_SEQUENCE);
    if ($scope === 'selected' && !empty($selectedids)) {
        $ids = array_map('intval', explode(',', $selectedids));
    } else {
        $ids = $DB->get_fieldset_sql("SELECT c.id FROM {course} c WHERE c.id > 1 AND c.visible = 1");
    }
    foreach ($ids as $cid) {
        set_config('sola_enabled_course_' . (int) $cid, $enabled ? '1' : '0', 'local_ai_course_assistant');
    }
    redirect($selfurl);
}

// Bulk Usability Testing toggle.
if ($action === 'bulktoggleut' && confirm_sesskey()) {
    // Same three-way override as the single-course toggle above.
    $utvalue = required_param('utvalue', PARAM_ALPHANUMEXT);
    if (!in_array($utvalue, ['', '0', '1'], true)) {
        $utvalue = '';
    }
    $scope = optional_param('scope', 'all', PARAM_ALPHA);
    // Comma-separated course IDs from the row checkboxes; PARAM_SEQUENCE is
    // exactly "digits and commas" and is still int-cast below.
    $selectedids = optional_param('selected_ids', '', PARAM_SEQUENCE);
    if ($scope === 'selected' && !empty($selectedids)) {
        $ids = array_map('intval', explode(',', $selectedids));
    } else {
        $ids = $DB->get_fieldset_sql("SELECT c.id FROM {course} c WHERE c.id > 1 AND c.visible = 1");
    }
    foreach ($ids as $cid) {
        $cid = (int) $cid;
        if ($utvalue === '') {
            unset_config('sola_usertesting_course_' . $cid, 'local_ai_course_assistant');
        } else {
            set_config('sola_usertesting_course_' . $cid, $utvalue, 'local_ai_course_assistant');
        }
    }
    redirect($selfurl);
}

$PAGE->set_url($selfurl);
$PAGE->set_context($syscontext);
$PAGE->set_title(get_string('courses_admin:title', 'local_ai_course_assistant'));
$PAGE->set_heading(get_string('courses_admin:title', 'local_ai_course_assistant'));
$PAGE->set_pagelayout('admin');

// Build course list.
$courses_with_data = $DB->get_records_sql(
    "SELECT DISTINCT m.courseid, c.fullname, c.shortname
       FROM {local_ai_course_assistant_msgs} m
       JOIN {course} c ON c.id = m.courseid
      WHERE c.id > 1
      ORDER BY c.fullname ASC"
);

$all_courses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, c.shortname
       FROM {course} c
      WHERE c.id > 1 AND c.visible = 1
      ORDER BY c.fullname ASC"
);

$course_list = [];
$enabled_count = 0;
foreach ($all_courses as $c) {
    $has_data = isset($courses_with_data[$c->id]);
    $is_enabled = \local_ai_course_assistant\course_config_manager::is_enabled_for_course((int) $c->id);
    if ($is_enabled) {
        $enabled_count++;
    }
    $ut_val = get_config('local_ai_course_assistant', 'sola_usertesting_course_' . $c->id);
    $ut_state = ($ut_val === '1') ? 'on' : (($ut_val === '0') ? 'off' : 'inherit');
    $ut_global = (bool) get_config('local_ai_course_assistant', 'usertesting_enabled');
    $ut_effective = ($ut_val === '1') || ($ut_val !== '0' && $ut_global);
    $course_list[] = [
        'id'           => (int) $c->id,
        'fullname'     => $c->fullname,
        'shortname'    => $c->shortname,
        'has_data'     => $has_data,
        'enabled'      => $is_enabled,
        'ut_on'        => $ut_state === 'on',
        'ut_off'       => $ut_state === 'off',
        'ut_inherit'   => $ut_state === 'inherit',
        'ut_effective' => $ut_effective,
        'sesskey'      => sesskey(),
        'form_action'  => $selfurl->out(false),
        'analytics_url' => (new moodle_url(
            '/local/ai_course_assistant/analytics.php',
            ['courseid' => (int) $c->id]
        ))->out(false),
    ];
}

$templatedata = [
    'sesskey'         => sesskey(),
    'form_action'     => $selfurl->out(false),
    'courses'         => $course_list,
    'has_courses'     => !empty($course_list),
    'enabled_count'   => $enabled_count,
    'total_count'     => count($course_list),
    'analytics_url'   => (new moodle_url('/local/ai_course_assistant/analytics.php'))->out(false),
    'settings_url'    => (new moodle_url(
        '/admin/category.php',
        ['category' => 'local_ai_course_assistant']
    ))->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/courses_admin', $templatedata);
echo $OUTPUT->footer();
