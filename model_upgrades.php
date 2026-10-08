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
 * Model upgrades (v7.8.0): candidates, evaluations, switches and rollbacks.
 *
 * Access control, parameter cleaning, dispatch and rendering only; the data
 * and every write live in autoupgrade\page.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\autoupgrade\page;
use local_ai_course_assistant\branding;

$syscontext = context_system::instance();
require_login();
require_capability('moodle/site:config', $syscontext);

$pageurl = new moodle_url(page::URL);
$PAGE->set_url($pageurl);
$PAGE->set_context($syscontext);
$PAGE->set_title(branding::str('autoupgrade:title'));
$PAGE->set_heading(branding::str('autoupgrade:title'));
$PAGE->set_pagelayout('admin');

// Every action is a POST with a session key and ends in a redirect, so a reload
// can never repeat a switch, a rollback or a billable evaluation.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = optional_param('action', '', PARAM_ALPHA);
    $result = null;
    if ($action === 'discover') {
        $result = page::discover_now();
    } else if ($action === 'evaluate') {
        $result = page::evaluate_now(optional_param('candidateid', 0, PARAM_INT), (int) $USER->id);
    } else if ($action === 'switch') {
        $result = page::switch_now(optional_param('candidateid', 0, PARAM_INT), (int) $USER->id);
    } else if ($action === 'rollback') {
        $result = page::rollback_now(optional_param('switchid', 0, PARAM_INT), (int) $USER->id);
    }
    if ($result === null) {
        redirect($pageurl);
    }
    $levels = [
        'success' => \core\output\notification::NOTIFY_SUCCESS,
        'warning' => \core\output\notification::NOTIFY_WARNING,
        'error' => \core\output\notification::NOTIFY_ERROR,
    ];
    redirect($pageurl, $result['message'], null, $levels[$result['level']] ?? \core\output\notification::NOTIFY_INFO);
}

$labels = [];
foreach (
    [
    'statusheading', 'mode', 'budget', 'changemode', 'discover', 'rolesheading', 'current', 'profile', 'policy',
    'nocandidates', 'colpasses', 'collast', 'evaluate', 'evaluating', 'switch', 'switchconfirm', 'evalsheading',
    'noevals', 'colwhen', 'colrole', 'colcandidate', 'colincumbent', 'colcost', 'colgate', 'switchesheading',
    'noswitches', 'colfrom', 'colto', 'colmode', 'colreason', 'coluntil', 'rollback', 'rollbackconfirm', 'manage',
    'notinuse',
    ] as $key
) {
    $labels[$key] = get_string('autoupgrade:l_' . $key, 'local_ai_course_assistant');
}
$labels['intro'] = branding::str('autoupgrade:intro');
$labels['emergency'] = get_string('autoupgrade:block_emergency', 'local_ai_course_assistant');
$labels['colmodel'] = get_string('modelregistry:col_model', 'local_ai_course_assistant');
$labels['colstatus'] = get_string('modelregistry:col_status', 'local_ai_course_assistant');
$labels['colactions'] = get_string('modelregistry:col_actions', 'local_ai_course_assistant');
$labels['colprovider'] = get_string('modelregistry:col_provider', 'local_ai_course_assistant');
$labels['colupdated'] = get_string('modelregistry:col_updated', 'local_ai_course_assistant');
$labels['learnedheading'] = get_string('modelregistry:learned_heading', 'local_ai_course_assistant');
$labels['learnednone'] = get_string('modelregistry:learned_none', 'local_ai_course_assistant');
$labels['colfield'] = get_string('modelregistry:col_field', 'local_ai_course_assistant');
$labels['colvalue'] = get_string('modelregistry:col_value', 'local_ai_course_assistant');
$labels['colevidence'] = get_string('modelregistry:col_evidence', 'local_ai_course_assistant');

$data = page::data() + [
    'posturl' => $pageurl->out(false),
    'sesskey' => sesskey(),
    'l' => $labels,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/model_upgrades', $data);
echo $OUTPUT->footer();
