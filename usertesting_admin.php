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
 * Admin page for managing user testing tasks.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', \context_system::instance());

use local_ai_course_assistant\usertesting_manager;

$courseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_context(\context_system::instance());
$PAGE->set_url(new moodle_url('/local/ai_course_assistant/usertesting_admin.php', ['courseid' => $courseid]));
$PAGE->set_pagelayout('admin');

$coursename = '';
if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname', MUST_EXIST);
    $coursename = $course->fullname;
    $pagetitle = get_string('usertesting_admin:title_course', 'local_ai_course_assistant', $coursename);
} else {
    $pagetitle = get_string('usertesting_admin:title_global', 'local_ai_course_assistant');
}
$PAGE->set_title($pagetitle);
$PAGE->set_heading($pagetitle);

// Handle POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);

    if ($action === 'save') {
        $title = required_param('taskset_title', PARAM_TEXT);
        // PARAM_RAW is required to receive the JSON envelope intact (it is a
        // json_encode'd array of task objects, not a scalar). Every decoded field
        // is cleaned field-by-field below: the type slug with PARAM_ALPHANUMEXT,
        // prose with PARAM_TEXT, bounds int-cast.
        $tasksraw = required_param('tasks_json', PARAM_RAW);
        $tasks = json_decode($tasksraw, true);
        $externalurl = optional_param('external_url', '', PARAM_URL);

        if (!is_array($tasks) || empty($tasks)) {
            \core\notification::error(get_string('usertesting_admin:err_invalid_tasks', 'local_ai_course_assistant'));
            redirect($PAGE->url);
        }

        // Clean tasks.
        $clean = [];
        foreach ($tasks as $t) {
            if (empty($t['instruction']) || empty($t['type'])) {
                continue;
            }
            $ttype = clean_param((string) $t['type'], PARAM_ALPHANUMEXT);
            $item = [
                'type' => $ttype,
                'instruction' => clean_param((string) $t['instruction'], PARAM_TEXT),
            ];
            if ($ttype === 'action_then_rate') {
                $item['rating_label'] = clean_param(
                    (string) ($t['rating_label']
                        ?? get_string('usertesting_admin:rating_label_default', 'local_ai_course_assistant')),
                    PARAM_TEXT
                );
                // The same defect as issue #288, in this sibling page. Tasks
                // are posted as one PARAM_RAW JSON blob, so the min/max
                // attributes on the number inputs are not merely advisory,
                // the browser never sees these values at all. min > max
                // renders zero rating buttons and ui.js will not let the
                // learner advance without picking one, and a crafted max
                // builds a button and a listener per step for every learner.
                $ratingmin = (int) ($t['min'] ?? usertesting_manager::RATING_SCALE_MIN);
                $ratingmax = (int) ($t['max'] ?? usertesting_manager::RATING_SCALE_DEFAULT_MAX);
                if ($ratingmin < usertesting_manager::RATING_SCALE_MIN
                        || $ratingmax > usertesting_manager::RATING_SCALE_MAX
                        || $ratingmin > $ratingmax) {
                    // Borrowed from survey_admin, as this page already borrows
                    // several of its labels; the wording is scale-generic.
                    \core\notification::error(get_string('survey_admin:err_invalid_bounds',
                        'local_ai_course_assistant', (object) [
                            'min' => usertesting_manager::RATING_SCALE_MIN,
                            'max' => usertesting_manager::RATING_SCALE_MAX,
                        ]));
                    redirect($PAGE->url);
                }
                $item['min'] = $ratingmin;
                $item['max'] = $ratingmax;
                $item['min_label'] = clean_param((string) ($t['min_label'] ?? ''), PARAM_TEXT);
                $item['max_label'] = clean_param((string) ($t['max_label'] ?? ''), PARAM_TEXT);
                $item['follow_up'] = clean_param((string) ($t['follow_up'] ?? ''), PARAM_TEXT);
            }
            if ($ttype === 'free_response') {
                $item['follow_up'] = clean_param((string) ($t['follow_up'] ?? ''), PARAM_TEXT);
            }
            if ($ttype === 'multiple_choice') {
                $opts = [];
                foreach (($t['options'] ?? []) as $opt) {
                    $opt = clean_param(trim((string) $opt), PARAM_TEXT);
                    if ($opt !== '') {
                        $opts[] = $opt;
                    }
                }
                if (empty($opts)) {
                    continue;
                }
                $item['options'] = $opts;
            }
            $clean[] = $item;
        }

        if (empty($clean)) {
            \core\notification::error(get_string('usertesting_admin:err_no_tasks', 'local_ai_course_assistant'));
            redirect($PAGE->url);
        }

        $existing = usertesting_manager::get_active_taskset_raw($courseid);
        if ($existing && (int) $existing->courseid === $courseid) {
            usertesting_manager::update_taskset((int) $existing->id, $title, $clean, $externalurl, true);
            \core\notification::success(get_string('usertesting_admin:saved_updated', 'local_ai_course_assistant'));
        } else {
            usertesting_manager::create_taskset($courseid, $title, $clean, $externalurl);
            \core\notification::success(get_string('usertesting_admin:saved_created', 'local_ai_course_assistant'));
        }
        redirect($PAGE->url);
    }

    if ($action === 'reset') {
        if ($courseid > 0) {
            $existing = $DB->get_records('local_ai_course_assistant_ut_tasks', ['courseid' => $courseid]);
            foreach ($existing as $s) {
                $DB->delete_records('local_ai_course_assistant_ut_tasks', ['id' => $s->id]);
            }
            \core\notification::success(get_string('usertesting_admin:reset_course_done', 'local_ai_course_assistant'));
        } else {
            $existing = $DB->get_records('local_ai_course_assistant_ut_tasks', ['courseid' => 0]);
            foreach ($existing as $s) {
                $DB->delete_records('local_ai_course_assistant_ut_tasks', ['id' => $s->id]);
            }
            usertesting_manager::ensure_default_taskset();
            \core\notification::success(get_string('usertesting_admin:reset_global_done', 'local_ai_course_assistant'));
        }
        redirect($PAGE->url);
    }
}

// Load current task set.
usertesting_manager::ensure_default_taskset();
// Raw, not repaired: this editor posts back whatever it is seeded with.
$taskset = usertesting_manager::get_active_taskset_raw($courseid);
$is_inherited = ($taskset && (int) $taskset->courseid !== $courseid && $courseid > 0);
$tasks = $taskset ? $taskset->tasks : usertesting_manager::DEFAULT_TASKS;
$title = $taskset
    ? $taskset->title
    : \local_ai_course_assistant\branding::str('usertesting_admin:default_title');
$externalurl = ($taskset && isset($taskset->external_url)) ? $taskset->external_url : '';

// Get list of courses.
$courses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, c.shortname FROM {course} c WHERE c.id > 1 AND c.visible = 1 ORDER BY c.fullname ASC"
);

// Labels for the task cards the browser builds. Same shape as the rubric
// editor: one bundle handed to amd/src/usertesting_admin.js as JSON, below.
// The multiple-choice type label is shared with the survey editor rather than
// duplicated, since it is the same word for the same concept.
$jsstrings = [
    'typeactionrate'      => get_string('usertesting_admin:type_action_then_rate', 'local_ai_course_assistant'),
    'typemultiplechoice'  => get_string('survey_admin:type_multiple_choice', 'local_ai_course_assistant'),
    'typefreeresponse'    => get_string('usertesting_admin:type_free_response', 'local_ai_course_assistant'),
    'moveup'              => get_string('rubric_admin:move_up', 'local_ai_course_assistant'),
    'movedown'            => get_string('rubric_admin:move_down', 'local_ai_course_assistant'),
    'deletetask'          => get_string('usertesting_admin:delete_task', 'local_ai_course_assistant'),
    'confirmdelete'       => get_string('usertesting_admin:confirm_delete_task', 'local_ai_course_assistant'),
    'tasktype'            => get_string('usertesting_admin:task_type', 'local_ai_course_assistant'),
    'instruction'         => get_string('usertesting_admin:task_instruction', 'local_ai_course_assistant'),
    'ratinglabel'         => get_string('usertesting_admin:rating_label', 'local_ai_course_assistant'),
    'minvalue'            => get_string('usertesting_admin:min_value', 'local_ai_course_assistant'),
    'minvaluearia'        => get_string('usertesting_admin:min_value_aria', 'local_ai_course_assistant'),
    'maxvalue'            => get_string('usertesting_admin:max_value', 'local_ai_course_assistant'),
    'maxvaluearia'        => get_string('usertesting_admin:max_value_aria', 'local_ai_course_assistant'),
    'minlabel'            => get_string('usertesting_admin:min_label', 'local_ai_course_assistant'),
    'minlabelplaceholder' => get_string('usertesting_admin:min_label_placeholder', 'local_ai_course_assistant'),
    'maxlabel'            => get_string('usertesting_admin:max_label', 'local_ai_course_assistant'),
    'maxlabelplaceholder' => get_string('usertesting_admin:max_label_placeholder', 'local_ai_course_assistant'),
    'followup'            => get_string('usertesting_admin:follow_up', 'local_ai_course_assistant'),
    'followuparia'        => get_string('usertesting_admin:follow_up_aria', 'local_ai_course_assistant'),
    'options'             => get_string('survey_admin:options', 'local_ai_course_assistant'),
    'optionn'             => get_string('survey_admin:option_n', 'local_ai_course_assistant', '{n}'),
    'addoption'           => get_string('survey_admin:add_option', 'local_ai_course_assistant'),
    'newoption'           => get_string('survey_admin:new_option', 'local_ai_course_assistant'),
    'ratinglabeldefault'  => get_string('usertesting_admin:rating_label_default', 'local_ai_course_assistant'),
    'addprompt'           => get_string('usertesting_admin:additional_prompt', 'local_ai_course_assistant'),
    'addpromptaria'       => get_string('usertesting_admin:additional_prompt_aria', 'local_ai_course_assistant'),
    'confirmreset'        => $courseid > 0
        ? get_string('usertesting_admin:confirm_reset_course', 'local_ai_course_assistant')
        : get_string('usertesting_admin:confirm_reset_global', 'local_ai_course_assistant'),
    'previewtitle'        => get_string('usertesting_admin:preview_title', 'local_ai_course_assistant'),
    'previewtasklabel'    => get_string('usertesting_admin:preview_task_label', 'local_ai_course_assistant', [
        'num' => '{n}',
        'type' => '{t}',
    ]),
    'previewnoinstruction' => get_string('usertesting_admin:preview_no_instruction', 'local_ai_course_assistant'),
    'previewratefallback'  => get_string('usertesting_admin:preview_rate_fallback', 'local_ai_course_assistant'),
    'previewraterange'     => get_string('usertesting_admin:preview_rate_range', 'local_ai_course_assistant', [
        'label' => '{l}',
        'min' => '{min}',
        'max' => '{max}',
    ]),
    'previewfollowup'      => get_string('usertesting_admin:preview_follow_up', 'local_ai_course_assistant', '{t}'),
    'previewclose'         => get_string('rubric_admin:preview_close', 'local_ai_course_assistant'),
];

// ---------------------------------------------------------------------
// Rendering (CONTRIB-10574 #273 and #278)
//
// The page markup is templates/usertesting_admin.mustache and the editor
// behaviour is amd/src/usertesting_admin.js, which renders its task cards and
// its preview dialog from templates/usertesting_admin_tasks.mustache and
// templates/usertesting_admin_preview.mustache. Nothing about the page
// changed: the scope picker, the inherited badge and the two posted forms work
// exactly as they did when this file echoed them.
//
// The editor's data travels in the root element's data-config attribute rather
// than as js_call_amd arguments. The default task set plus the 35 translated
// strings is 4,955 characters, well past the 1,024-character advisory limit
// those arguments carry, and the payload grows with every task an admin adds.
// That is the complaint issue #277 raised about the starter admin page, where
// the same payload shape printed a warning on every page load.
// ---------------------------------------------------------------------

$courseoptions = [];
foreach ($courses as $c) {
    $courseoptions[] = [
        'id' => (int) $c->id,
        // Escaped by the template, as htmlspecialchars() did when this was echoed.
        'label' => $c->fullname . ' (' . $c->shortname . ')',
        'selected' => (int) $c->id === $courseid,
    ];
}

$templatedata = [
    'configjson' => json_encode([
        // array_values so a task list that ever arrives with gappy keys still
        // encodes as a JSON array; the editor iterates it as one.
        'tasks' => array_values($tasks),
        'strings' => $jsstrings,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    'settingsurl' => (new moodle_url('/admin/category.php', ['category' => 'local_ai_course_assistant']))->out(false),
    'settingslabel' => get_string('courses_admin:plugin_settings', 'local_ai_course_assistant'),
    'analyticsurl' => (new moodle_url('/local/ai_course_assistant/analytics.php'))->out(false),
    'analyticslabel' => get_string('rubric_admin:analytics_link', 'local_ai_course_assistant'),
    'scopeformaction' => $PAGE->url->out_omit_querystring(),
    'scopelabel' => get_string('usertesting_admin:scope_label', 'local_ai_course_assistant'),
    'scopeglobal' => get_string('rubric_admin:scope_global', 'local_ai_course_assistant'),
    'globalselected' => $courseid === 0,
    'courses' => $courseoptions,
    'isinherited' => (bool) $is_inherited,
    'inheritednotice' => get_string('usertesting_admin:inherited_notice', 'local_ai_course_assistant'),
    'formaction' => $PAGE->url->out(false),
    'sesskey' => sesskey(),
    'titlelabel' => get_string('usertesting_admin:taskset_title', 'local_ai_course_assistant'),
    'title' => $title,
    'titleplaceholder' => \local_ai_course_assistant\branding::str('usertesting_admin:taskset_title_placeholder'),
    'exturllabel' => get_string('usertesting_admin:external_url', 'local_ai_course_assistant'),
    'exturl' => $externalurl,
    // The example URL stays a literal here rather than in the template: it
    // contains {{userid}} and {{courseid}}, which Mustache would resolve away
    // to nothing. It is the same literal this page printed before.
    'exturlplaceholder' => 'e.g. https://forms.google.com/d/e/xxx/viewform'
        . '?entry.1={{userid}}&entry.2={{courseid}}',
    // Raw in the template: this lang string documents the supported
    // placeholders inside <code> tags and was echoed unescaped before.
    'externalurlhelp' => get_string('usertesting_admin:external_url_help', 'local_ai_course_assistant'),
    'tasksheading' => get_string('usertesting_admin:tasks_heading', 'local_ai_course_assistant'),
    'addtask' => get_string('usertesting_admin:add_task', 'local_ai_course_assistant'),
    'savelabel' => get_string('usertesting_admin:save', 'local_ai_course_assistant'),
    'previewlabel' => get_string('rubric_admin:preview', 'local_ai_course_assistant'),
    'hasreset' => ($courseid > 0 && !$is_inherited) || $courseid === 0,
    'resetlabel' => $courseid > 0
        ? get_string('rubric_admin:remove_override', 'local_ai_course_assistant')
        : get_string('rubric_admin:reset_defaults', 'local_ai_course_assistant'),
];

$PAGE->requires->js_call_amd('local_ai_course_assistant/usertesting_admin', 'init');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/usertesting_admin', $templatedata);
echo $OUTPUT->footer();
