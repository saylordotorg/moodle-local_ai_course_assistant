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
 * Admin page for managing practice scoring rubrics.
 *
 * Supports editing the global default rubric (courseid=0) and per-course overrides,
 * for both conversation and pronunciation practice types.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', \context_system::instance());

use local_ai_course_assistant\rubric_manager;

$courseid = optional_param('courseid', 0, PARAM_INT);
$type = optional_param('type', 'conversation', PARAM_ALPHA);

// Validate type.
if (!in_array($type, ['conversation', 'pronunciation', 'speech'])) {
    $type = 'conversation';
}

$PAGE->set_context(\context_system::instance());
$PAGE->set_url(new moodle_url('/local/ai_course_assistant/rubric_admin.php', ['courseid' => $courseid, 'type' => $type]));
$PAGE->set_pagelayout('admin');

$coursename = '';
if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname', MUST_EXIST);
    $coursename = $course->fullname;
    $pagetitle = get_string('rubric_admin:title_course', 'local_ai_course_assistant', $coursename);
    $PAGE->set_title($pagetitle);
    $PAGE->set_heading($pagetitle);
} else {
    $pagetitle = get_string('rubric_admin:title_global', 'local_ai_course_assistant');
    $PAGE->set_title($pagetitle);
    $PAGE->set_heading($pagetitle);
}

// Handle POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $action = required_param('action', PARAM_ALPHA);
    $posttype = required_param('rubric_type', PARAM_ALPHA);
    if (!in_array($posttype, ['conversation', 'pronunciation', 'speech'])) {
        $posttype = 'conversation';
    }

    if ($action === 'save') {
        // PARAM_RAW is required to receive the JSON envelope intact; each decoded
        // field is strictly cleaned below (clean_param + integer cast) before use.
        $criteriaraw = required_param('criteria_json', PARAM_RAW);
        $criteria = json_decode($criteriaraw, true);

        if (!is_array($criteria) || empty($criteria)) {
            \core\notification::error(get_string('rubric_admin:err_invalid_criteria', 'local_ai_course_assistant'));
            redirect(new moodle_url($PAGE->url, ['type' => $posttype]));
        }

        // Clean criteria.
        $clean = [];
        foreach ($criteria as $c) {
            if (empty(trim($c['name'] ?? ''))) {
                continue;
            }
            $entry = [
                'name' => clean_param(trim($c['name']), PARAM_TEXT),
                'description' => clean_param(trim($c['description'] ?? ''), PARAM_TEXT),
                'max_score' => max(1, (int) ($c['max_score'] ?? 5)),
            ];
            // v6.8.30: optional outcome mapping. Only meaningful for a per-course
            // rubric (objectives are per-course); kept only if the objective
            // belongs to this course.
            $oid = (int) ($c['objectiveid'] ?? 0);
            if (
                $oid > 0 && $courseid > 0
                    && \local_ai_course_assistant\objective_manager::get($oid)
                    && (int) \local_ai_course_assistant\objective_manager::get($oid)->courseid === $courseid
            ) {
                $entry['objectiveid'] = $oid;
            }
            $clean[] = $entry;
        }

        if (empty($clean)) {
            \core\notification::error(get_string('rubric_admin:err_no_criteria', 'local_ai_course_assistant'));
            redirect(new moodle_url($PAGE->url, ['type' => $posttype]));
        }

        // A rubric with two criteria whose names differ only in case or spacing
        // is broken in three places at once: score_speech's allowlist, its
        // max-score map and its objective map all key on the normalised name, so
        // one row silently shadows the other and the model is handed two
        // indistinguishable rubric lines. Reject it here, where an admin can see
        // and fix it, rather than let it reach a learner's permanent outcome
        // record where nobody can.
        $seennames = [];
        foreach ($clean as $entry) {
            $namekey = \local_ai_course_assistant\external\score_speech::normalise_name($entry['name']);
            if (isset($seennames[$namekey])) {
                \core\notification::error(get_string(
                    'rubric_admin:err_duplicate_names',
                    'local_ai_course_assistant',
                    $entry['name']
                ));
                redirect(new moodle_url($PAGE->url, ['type' => $posttype]));
            }
            $seennames[$namekey] = true;
        }

        // Check if a rubric already exists for this scope and type.
        $title = get_string('rubric_admin:rubric_title_' . $posttype, 'local_ai_course_assistant');
        $existing = rubric_manager::get_rubric($courseid, $posttype);
        if ($existing && (int) $existing->courseid === $courseid) {
            rubric_manager::update_rubric((int) $existing->id, $title, $clean, true);
            \core\notification::success(get_string('rubric_admin:saved_updated', 'local_ai_course_assistant'));
        } else {
            rubric_manager::create_rubric($courseid, $posttype, $title, $clean);
            \core\notification::success(get_string('rubric_admin:saved_created', 'local_ai_course_assistant'));
        }

        redirect(new moodle_url($PAGE->url, ['type' => $posttype]));
    }

    if ($action === 'reset') {
        // Delete course-level rubric (falls back to global).
        if ($courseid > 0) {
            rubric_manager::delete_rubric($courseid, $posttype);
            \core\notification::success(get_string('rubric_admin:reset_course_done', 'local_ai_course_assistant'));
        } else {
            // Reset global to defaults.
            rubric_manager::delete_rubric(0, $posttype);
            rubric_manager::ensure_default_rubric($posttype);
            \core\notification::success(get_string('rubric_admin:reset_global_done', 'local_ai_course_assistant'));
        }
        redirect(new moodle_url($PAGE->url, ['type' => $posttype]));
    }
}

// Load current rubric.
rubric_manager::ensure_default_rubric($type);
$rubric = rubric_manager::get_rubric($courseid, $type);
$is_inherited = ($rubric && (int) $rubric->courseid !== $courseid && $courseid > 0);
$criteria = $rubric ? $rubric->criteria : rubric_manager::get_default_criteria($type);

// v6.8.30: course outcomes offered for per-criterion mapping (per-course rubrics
// only; objectives are per-course). Empty for the global rubric, which hides the
// picker in the editor JS.
$outcomesforjs = [];
if ($courseid > 0) {
    foreach (\local_ai_course_assistant\objective_manager::list_for_course($courseid) as $o) {
        $outcomesforjs[] = ['id' => (int) $o->id, 'title' => (string) $o->title, 'code' => (string) ($o->code ?? '')];
    }
}

// v6.7.0: Soapbox sample-rubric loader. ?preset=<level> seeds the editor with a
// built-in sample (General Speech / ESL beginner / ESL advanced) which the admin
// then reviews and Saves to apply to this scope. Editor-only; nothing is written
// until Save. Only valid for the speech type.
$loadedpreset = '';
if ($type === rubric_manager::TYPE_SPEECH) {
    $presetparam = optional_param('preset', '', PARAM_ALPHANUMEXT);
    if ($presetparam !== '' && array_key_exists($presetparam, rubric_manager::speech_presets())) {
        $criteria = rubric_manager::speech_preset($presetparam)['criteria'];
        $loadedpreset = $presetparam;
    }
}

// Get list of courses for the scope selector.
$courses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, c.shortname FROM {course} c WHERE c.id > 1 AND c.visible = 1 ORDER BY c.fullname ASC"
);

// Practice-type tab labels, reused by the tab strip and the preview dialog.
$typelabels = [
    'conversation'  => get_string('rubric_admin:tab_conversation', 'local_ai_course_assistant'),
    'pronunciation' => get_string('rubric_admin:tab_pronunciation', 'local_ai_course_assistant'),
    'speech'        => get_string('rubric_admin:tab_speech', 'local_ai_course_assistant'),
];

// Strings the criterion editor and the preview dialog build client-side. Handed
// to the script as one JSON blob so no English literal is left in the JS.
$jsstrings = [
    'criterion'        => get_string('rubric_admin:criterion', 'local_ai_course_assistant'),
    'moveup'           => get_string('rubric_admin:move_up', 'local_ai_course_assistant'),
    'movedown'         => get_string('rubric_admin:move_down', 'local_ai_course_assistant'),
    'deletecriterion'  => get_string('rubric_admin:delete_criterion', 'local_ai_course_assistant'),
    'confirmdelete'    => get_string('rubric_admin:confirm_delete_criterion', 'local_ai_course_assistant'),
    'name'             => get_string('rubric_admin:criterion_name', 'local_ai_course_assistant'),
    'nameplaceholder'  => get_string('rubric_admin:criterion_name_placeholder', 'local_ai_course_assistant'),
    'maxscore'         => get_string('rubric_admin:max_score', 'local_ai_course_assistant'),
    'maxscorearia'     => get_string('rubric_admin:max_score_aria', 'local_ai_course_assistant'),
    'description'      => get_string('rubric_admin:description', 'local_ai_course_assistant'),
    'descriptionaria'  => get_string('rubric_admin:description_aria', 'local_ai_course_assistant'),
    'descplaceholder'  => get_string('rubric_admin:description_placeholder', 'local_ai_course_assistant'),
    'mapsoutcome'      => get_string('rubric_admin:maps_to_outcome', 'local_ai_course_assistant'),
    'outcomearia'      => get_string('rubric_admin:outcome_aria', 'local_ai_course_assistant'),
    'outcomenone'      => get_string('rubric_admin:outcome_none', 'local_ai_course_assistant'),
    'confirmreset'     => $courseid > 0
        ? get_string('rubric_admin:confirm_reset_course', 'local_ai_course_assistant')
        : get_string('rubric_admin:confirm_reset_global', 'local_ai_course_assistant'),
    'previewtitle'     => get_string('rubric_admin:preview_title', 'local_ai_course_assistant'),
    'previewtype'      => get_string('rubric_admin:preview_type', 'local_ai_course_assistant', $typelabels[$type]),
    'previewunnamed'   => get_string('rubric_admin:preview_unnamed', 'local_ai_course_assistant'),
    'previewscore'     => get_string('rubric_admin:preview_score', 'local_ai_course_assistant'),
    // Substituted client-side: Moodle's string cache hands back the raw {$a}.
    'previewtotal'     => get_string('rubric_admin:preview_total', 'local_ai_course_assistant'),
    'previewclose'     => get_string('rubric_admin:preview_close', 'local_ai_course_assistant'),
];

// ---------------------------------------------------------------------
// Rendering (CONTRIB-10574 #273 and #278)
//
// The page markup is templates/rubric_admin.mustache and the editor behaviour
// is amd/src/rubric_admin.js, which renders its criterion cards and its preview
// dialog from templates/rubric_admin_criteria.mustache and
// templates/rubric_admin_preview.mustache. Nothing about the page changed: the
// preset flow, the scope and type pickers and the two posted forms work exactly
// as they did when this file echoed them.
//
// The editor's data travels in the root element's data-config attribute rather
// than as js_call_amd arguments. A rubric plus 22 translated strings is already
// 1,488 characters for the five-criterion default, past the 1,024-character
// advisory limit those arguments carry, and it grows with every criterion an
// admin adds. That is the complaint issue #277 raised about the starter admin
// page, where the same payload shape printed a warning on every page load.
// ---------------------------------------------------------------------

$typetabs = [];
foreach (['conversation', 'pronunciation', 'speech'] as $tabtype) {
    $typetabs[] = [
        'url' => (new moodle_url(
            '/local/ai_course_assistant/rubric_admin.php',
            ['courseid' => $courseid, 'type' => $tabtype]
        ))->out(false),
        'label' => $typelabels[$tabtype],
        'active' => $type === $tabtype,
    ];
}

$courseoptions = [];
foreach ($courses as $c) {
    $courseoptions[] = [
        'id' => (int) $c->id,
        // Escaped by the template, as htmlspecialchars() did when this was echoed.
        'label' => $c->fullname . ' (' . $c->shortname . ')',
        'selected' => (int) $c->id === $courseid,
    ];
}

$sampleoptions = [];
if ($type === 'speech') {
    foreach (rubric_manager::speech_presets() as $lvkey => $lvdef) {
        $sampleoptions[] = [
            'url' => (new moodle_url(
                '/local/ai_course_assistant/rubric_admin.php',
                ['courseid' => $courseid, 'type' => 'speech', 'preset' => $lvkey]
            ))->out(false),
            'label' => get_string($lvdef['label_key'], 'local_ai_course_assistant'),
            'selected' => $loadedpreset === $lvkey,
        ];
    }
}

$templatedata = [
    'configjson' => json_encode([
        // array_values so a criteria list that ever arrives with gappy keys
        // still encodes as a JSON array; the editor iterates it as one.
        'criteria' => array_values($criteria),
        'strings' => $jsstrings,
        'objectives' => $outcomesforjs,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    'settingsurl' => (new moodle_url('/admin/category.php', ['category' => 'local_ai_course_assistant']))->out(false),
    'settingslabel' => get_string('courses_admin:plugin_settings', 'local_ai_course_assistant'),
    'analyticsurl' => (new moodle_url('/local/ai_course_assistant/analytics.php'))->out(false),
    'analyticslabel' => get_string('rubric_admin:analytics_link', 'local_ai_course_assistant'),
    'scopeformaction' => $PAGE->url->out_omit_querystring(),
    'type' => $type,
    'scopelabel' => get_string('rubric_admin:scope_label', 'local_ai_course_assistant'),
    'scopeglobal' => get_string('rubric_admin:scope_global', 'local_ai_course_assistant'),
    'globalselected' => $courseid === 0,
    'courses' => $courseoptions,
    'typetabs' => $typetabs,
    'isspeech' => $type === 'speech',
    'samplelabel' => get_string('soapbox:sample_label', 'local_ai_course_assistant'),
    'samplechoose' => get_string('soapbox:sample_choose', 'local_ai_course_assistant'),
    'samplehint' => get_string('soapbox:sample_hint', 'local_ai_course_assistant'),
    'sampleoptions' => $sampleoptions,
    'isinherited' => (bool) $is_inherited,
    'inheritednotice' => get_string('rubric_admin:inherited_notice', 'local_ai_course_assistant'),
    'formaction' => $PAGE->url->out(false),
    'sesskey' => sesskey(),
    'addcriterion' => get_string('rubric_admin:add_criterion', 'local_ai_course_assistant'),
    'savelabel' => get_string('rubric_admin:save', 'local_ai_course_assistant'),
    'previewlabel' => get_string('rubric_admin:preview', 'local_ai_course_assistant'),
    'hasreset' => ($courseid > 0 && !$is_inherited) || $courseid === 0,
    'resetlabel' => $courseid > 0
        ? get_string('rubric_admin:remove_override', 'local_ai_course_assistant')
        : get_string('rubric_admin:reset_defaults', 'local_ai_course_assistant'),
];

$PAGE->requires->js_call_amd('local_ai_course_assistant/rubric_admin', 'init');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/rubric_admin', $templatedata);
echo $OUTPUT->footer();
