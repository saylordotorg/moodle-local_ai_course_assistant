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
 * Admin page for managing survey questions.
 *
 * Supports editing the global default survey (courseid=0) and per-course overrides.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', \context_system::instance());

use local_ai_course_assistant\survey_manager;

$courseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_context(\context_system::instance());
$PAGE->set_url(new moodle_url('/local/ai_course_assistant/survey_admin.php', ['courseid' => $courseid]));
$PAGE->set_pagelayout('admin');

$coursename = '';
if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname', MUST_EXIST);
    $coursename = $course->fullname;
    $pagetitle = get_string('survey_admin:title_course', 'local_ai_course_assistant', $coursename);
} else {
    $pagetitle = get_string('survey_admin:title_global', 'local_ai_course_assistant');
}
$PAGE->set_title($pagetitle);
$PAGE->set_heading($pagetitle);

// Handle POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $action = required_param('action', PARAM_ALPHA);

    if ($action === 'save') {
        // Issue #289: required_param() treats an empty string as a missing
        // parameter, so an admin who cleared the title and saved got a raw
        // "A required parameter (survey_title) was missing" error page rather
        // than the notification-and-redirect every other failure here uses.
        $title = trim(optional_param('survey_title', '', PARAM_TEXT));
        // PARAM_RAW is required to receive the JSON envelope intact (it is a
        // json_encode'd array of question objects, not a scalar). Every decoded
        // field is cleaned field-by-field below: the type slug with
        // PARAM_ALPHANUMEXT, prose with PARAM_TEXT, bounds int-cast.
        $questionsraw = required_param('questions_json', PARAM_RAW);
        $questions = json_decode($questionsraw, true);
        $active = optional_param('survey_active', 0, PARAM_INT);

        if ($title === '') {
            \core\notification::error(get_string('survey_admin:err_no_title', 'local_ai_course_assistant'));
            redirect($PAGE->url);
        }

        if (!is_array($questions) || empty($questions)) {
            \core\notification::error(get_string('survey_admin:err_invalid_questions', 'local_ai_course_assistant'));
            redirect($PAGE->url);
        }

        // Clean questions.
        $clean = [];
        foreach ($questions as $q) {
            if (empty($q['text']) || empty($q['type'])) {
                continue;
            }
            $qtype = clean_param((string) $q['type'], PARAM_ALPHANUMEXT);
            $item = [
                'type' => $qtype,
                'text' => clean_param((string) $q['text'], PARAM_TEXT),
            ];
            if ($qtype === 'multiple_choice') {
                $opts = [];
                foreach (($q['options'] ?? []) as $opt) {
                    $opt = clean_param(trim((string) $opt), PARAM_TEXT);
                    if ($opt !== '') {
                        $opts[] = $opt;
                    }
                }
                if (empty($opts)) {
                    continue; // Skip MC without options.
                }
                $item['options'] = $opts;
            }
            if ($qtype === 'rating') {
                // Issue #288: the number inputs carry min/max attributes, but
                // those are advisory and the POST body is the only thing that
                // counts. min > max renders a question with no buttons at all,
                // because the render loop is `for (r = min; r <= max; r++)`,
                // and a crafted max builds one button per step for every
                // learner who opens the survey.
                $ratingmin = (int) ($q['min'] ?? survey_manager::RATING_SCALE_MIN);
                $ratingmax = (int) ($q['max'] ?? survey_manager::RATING_SCALE_DEFAULT_MAX);
                if ($ratingmin < survey_manager::RATING_SCALE_MIN
                        || $ratingmax > survey_manager::RATING_SCALE_MAX
                        || $ratingmin > $ratingmax) {
                    \core\notification::error(get_string('survey_admin:err_invalid_bounds',
                        'local_ai_course_assistant', (object) [
                            'min' => survey_manager::RATING_SCALE_MIN,
                            'max' => survey_manager::RATING_SCALE_MAX,
                        ]));
                    redirect($PAGE->url);
                }
                $item['min'] = $ratingmin;
                $item['max'] = $ratingmax;
                if (!empty($q['min_label'])) {
                    $item['min_label'] = clean_param((string) $q['min_label'], PARAM_TEXT);
                }
                if (!empty($q['max_label'])) {
                    $item['max_label'] = clean_param((string) $q['max_label'], PARAM_TEXT);
                }
            }
            $clean[] = $item;
        }

        if (empty($clean)) {
            \core\notification::error(get_string('survey_admin:err_no_questions', 'local_ai_course_assistant'));
            redirect($PAGE->url);
        }

        // Check if a survey already exists for this scope.
        $existing = survey_manager::get_active_survey_raw($courseid);
        if ($existing && (int) $existing->courseid === $courseid) {
            survey_manager::update_survey((int) $existing->id, $title, $clean, (bool) $active);
            \core\notification::success(get_string('survey_admin:saved_updated', 'local_ai_course_assistant'));
        } else {
            survey_manager::create_survey($courseid, $title, $clean, (bool) $active);
            \core\notification::success(get_string('survey_admin:saved_created', 'local_ai_course_assistant'));
        }

        redirect($PAGE->url);
    }

    if ($action === 'reset') {
        // Delete course-level survey (falls back to global).
        if ($courseid > 0) {
            $existing = $DB->get_records('local_ai_course_assistant_surveys', ['courseid' => $courseid]);
            foreach ($existing as $s) {
                $DB->delete_records('local_ai_course_assistant_surveys', ['id' => $s->id]);
            }
            \core\notification::success(get_string('survey_admin:reset_course_done', 'local_ai_course_assistant'));
        } else {
            // Reset global to defaults.
            $existing = $DB->get_records('local_ai_course_assistant_surveys', ['courseid' => 0]);
            foreach ($existing as $s) {
                $DB->delete_records('local_ai_course_assistant_surveys', ['id' => $s->id]);
            }
            survey_manager::ensure_default_survey();
            \core\notification::success(get_string('survey_admin:reset_global_done', 'local_ai_course_assistant'));
        }
        redirect($PAGE->url);
    }
}

// Load current survey.
//
// Raw, not repaired: this page seeds its editor from whatever it is handed and
// posts that back on the next save, so reading the normalised copy would turn
// the #288 display repair into a silent one-way migration fired by an
// unrelated edit. The admin sees what is really stored, and the save-path
// bounds check makes them choose a valid scale on purpose.
survey_manager::ensure_default_survey();
$survey = survey_manager::get_active_survey_raw($courseid);
$is_inherited = ($survey && (int) $survey->courseid !== $courseid && $courseid > 0);
$questions = $survey ? $survey->questions : survey_manager::DEFAULT_QUESTIONS;
$title = $survey
    ? $survey->title
    : \local_ai_course_assistant\branding::str('survey_admin:default_title');

// Get list of courses for the scope selector.
$courses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, c.shortname FROM {course} c WHERE c.id > 1 AND c.visible = 1 ORDER BY c.fullname ASC"
);

// Labels for the question cards the browser builds. Same shape as the rubric
// editor: one bundle handed to amd/src/survey_admin.js as JSON, below.
$jsstrings = [
    'typemultiplechoice' => get_string('survey_admin:type_multiple_choice', 'local_ai_course_assistant'),
    'typerating'         => get_string('survey_admin:type_rating', 'local_ai_course_assistant'),
    'typeopentext'       => get_string('survey_admin:type_open_text', 'local_ai_course_assistant'),
    'moveup'             => get_string('rubric_admin:move_up', 'local_ai_course_assistant'),
    'movedown'           => get_string('rubric_admin:move_down', 'local_ai_course_assistant'),
    'deletequestion'     => get_string('survey_admin:delete_question', 'local_ai_course_assistant'),
    'confirmdelete'      => get_string('survey_admin:confirm_delete_question', 'local_ai_course_assistant'),
    'questiontype'       => get_string('survey_admin:question_type', 'local_ai_course_assistant'),
    'questiontext'       => get_string('survey_admin:question_text', 'local_ai_course_assistant'),
    'options'            => get_string('survey_admin:options', 'local_ai_course_assistant'),
    'optionn'            => get_string('survey_admin:option_n', 'local_ai_course_assistant', '{n}'),
    'removeoption'       => get_string('survey_admin:remove_option', 'local_ai_course_assistant'),
    'addoption'          => get_string('survey_admin:add_option', 'local_ai_course_assistant'),
    'newoption'          => get_string('survey_admin:new_option', 'local_ai_course_assistant'),
    'minvalue'           => get_string('survey_admin:min_value', 'local_ai_course_assistant'),
    'minvaluearia'       => get_string('survey_admin:min_value_aria', 'local_ai_course_assistant'),
    'maxvalue'           => get_string('survey_admin:max_value', 'local_ai_course_assistant'),
    'maxvaluearia'       => get_string('survey_admin:max_value_aria', 'local_ai_course_assistant'),
    'minlabel'           => get_string('survey_admin:min_label', 'local_ai_course_assistant'),
    'minlabelaria'       => get_string('survey_admin:min_label_aria', 'local_ai_course_assistant'),
    'minlabelplaceholder' => get_string('survey_admin:min_label_placeholder', 'local_ai_course_assistant'),
    'maxlabel'           => get_string('survey_admin:max_label', 'local_ai_course_assistant'),
    'maxlabelaria'       => get_string('survey_admin:max_label_aria', 'local_ai_course_assistant'),
    'maxlabelplaceholder' => get_string('survey_admin:max_label_placeholder', 'local_ai_course_assistant'),
    'confirmreset'       => $courseid > 0
        ? get_string('survey_admin:confirm_reset_course', 'local_ai_course_assistant')
        : get_string('survey_admin:confirm_reset_global', 'local_ai_course_assistant'),
    'previewtitle'       => get_string('survey_admin:preview_title', 'local_ai_course_assistant'),
    'previewnotext'      => get_string('survey_admin:preview_no_text', 'local_ai_course_assistant'),
    'previewanswerhint'  => get_string('survey_admin:preview_answer_hint', 'local_ai_course_assistant'),
    'previewclose'       => get_string('rubric_admin:preview_close', 'local_ai_course_assistant'),
];

// ---------------------------------------------------------------------
// Rendering (CONTRIB-10574 #273 and #278)
//
// The page markup is templates/survey_admin.mustache and the editor behaviour
// is amd/src/survey_admin.js, which renders its question cards and its preview
// dialog from templates/survey_admin_questions.mustache and
// templates/survey_admin_preview.mustache. Nothing about the page changed: the
// scope picker, the inherited badge and the two posted forms work exactly as
// they did when this file echoed them.
//
// The editor's data travels in the root element's data-config attribute rather
// than as js_call_amd arguments. The five default questions plus the 29
// translated strings are 1,816 characters in English, well past the
// 1,024-character advisory limit those arguments carry, and the payload grows
// with every question an admin adds. That is the complaint issue #277 raised
// about the starter admin page, where the same payload shape printed a warning
// on every page load.
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
        // array_values so a question list that ever arrives with gappy keys
        // still encodes as a JSON array; the editor iterates it as one.
        //
        // (array) first, because survey_manager::get_active_survey_raw() returns
        // whatever json_decode() gave it, which is null for a NULL or truncated
        // questions column. Under PHP 8 array_values(null) is a fatal TypeError
        // and the whole page becomes a white screen. The pre-migration code
        // json_encoded the same value and still rendered the chrome, the scope
        // picker and the links, so this would have been a new hard-fail
        // introduced by moving the data into the template.
        'questions' => array_values((array) $questions),
        'strings' => $jsstrings,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    'settingsurl' => (new moodle_url('/admin/category.php', ['category' => 'local_ai_course_assistant']))->out(false),
    'settingslabel' => get_string('courses_admin:plugin_settings', 'local_ai_course_assistant'),
    'analyticsurl' => (new moodle_url('/local/ai_course_assistant/analytics.php'))->out(false),
    'analyticslabel' => get_string('rubric_admin:analytics_link', 'local_ai_course_assistant'),
    'scopeformaction' => $PAGE->url->out_omit_querystring(),
    'scopelabel' => get_string('survey_admin:scope_label', 'local_ai_course_assistant'),
    'scopeglobal' => get_string('rubric_admin:scope_global', 'local_ai_course_assistant'),
    'globalselected' => $courseid === 0,
    'courses' => $courseoptions,
    'isinherited' => (bool) $is_inherited,
    'inheritednotice' => get_string('survey_admin:inherited_notice', 'local_ai_course_assistant'),
    'formaction' => $PAGE->url->out(false),
    'sesskey' => sesskey(),
    'titlelabel' => get_string('survey_admin:survey_title', 'local_ai_course_assistant'),
    'title' => $title,
    'titleplaceholder' => \local_ai_course_assistant\branding::str('survey_admin:title_placeholder'),
    'addquestion' => get_string('survey_admin:add_question', 'local_ai_course_assistant'),
    'savelabel' => get_string('survey_admin:save', 'local_ai_course_assistant'),
    'previewlabel' => get_string('rubric_admin:preview', 'local_ai_course_assistant'),
    'hasreset' => ($courseid > 0 && !$is_inherited) || $courseid === 0,
    'resetlabel' => $courseid > 0
        ? get_string('rubric_admin:remove_override', 'local_ai_course_assistant')
        : get_string('rubric_admin:reset_defaults', 'local_ai_course_assistant'),
];

$PAGE->requires->js_call_amd('local_ai_course_assistant/survey_admin', 'init');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/survey_admin', $templatedata);
echo $OUTPUT->footer();
