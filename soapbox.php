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
 * Soapbox — learner speech-practice page. The student (optionally) names their
 * speech, picks a topic and a target time, records a longer audio clip, which
 * is transcribed (server Whisper or free in-browser speech recognition), and
 * gets rubric-based AI feedback. Score history is kept per learner; the audio
 * and transcript are never stored.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\feature_flags;
use local_ai_course_assistant\rubric_manager;
use local_ai_course_assistant\security;

require_login();

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('local/ai_course_assistant:use', $context);

if (!feature_flags::resolve('soapbox', $courseid)) {
    throw new \moodle_exception('soapbox:disabled', 'local_ai_course_assistant');
}

$pageurl = new moodle_url('/local/ai_course_assistant/soapbox.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_course($course);
$PAGE->set_title(get_string('soapbox:title', 'local_ai_course_assistant'));
$PAGE->set_heading($course->fullname);

security::send_security_headers(true);

// server = Whisper via voice_registry (self-hosted free, else hosted); browser =
// in-browser Web Speech API (free, no server, Chrome/Safari).
$sttmode = get_config('local_ai_course_assistant', 'soapbox_stt_mode') ?: 'server';
$transcribeurl = (new moodle_url('/local/ai_course_assistant/soapbox_transcribe.php'))->out(false);

$history = rubric_manager::get_user_scores($USER->id, $courseid, rubric_manager::TYPE_SPEECH, 20);

// ---------------------------------------------------------------------
// Rendering (CONTRIB-10574 #273 and #278)
//
// The page markup is templates/soapbox.mustache, the live score card is
// templates/soapbox_result.mustache, and the behaviour is amd/src/soapbox.js.
// Soapbox is deprecated in 7.5.2 and is removed in 8.0, so this was a move and
// nothing else: the fields, the ids the module binds to, both speech-to-text
// paths and every message are exactly what they were when this file echoed
// them.
//
// The module's data travels in the root element's data-config attribute rather
// than as js_call_amd arguments. Sixteen translated strings plus the endpoint
// and the sesskey measures 1,449 characters in English alone, past the
// 1,024-character advisory limit those arguments carry, which is the complaint
// issue #277 raised about the starter admin page. Longer locales only make it
// worse. The remaining strings are resolved by the templates themselves.
// ---------------------------------------------------------------------

$targetoptions = [];
foreach ([0 => 0, 60 => 1, 120 => 2, 180 => 3, 300 => 5, 420 => 7, 600 => 10] as $secs => $minutes) {
    $targetoptions[] = [
        'value' => $secs,
        'label' => $secs === 0
            ? get_string('soapbox:no_target', 'local_ai_course_assistant')
            : get_string('soapbox:target_minutes', 'local_ai_course_assistant', $minutes),
        // 3 minutes is the default, as it was when this was a hard-coded
        // `selected` attribute on the 180-second option.
        'selected' => $secs === 180,
    ];
}

// Resolved in PHP rather than with {{#str}} so the option labels are never
// empty: Mustache Lint renders each template against its own example context
// with no string helper, and an empty <option> fails the build.
$modeoptions = [
    [
        'value' => 'informative',
        'label' => get_string('soapbox:mode_informative', 'local_ai_course_assistant'),
    ],
    [
        'value' => 'persuasive',
        'label' => get_string('soapbox:mode_persuasive', 'local_ai_course_assistant'),
    ],
];

$historyentries = [];
foreach ($history as $h) {
    $meta = json_decode($h->session_meta ?? '', true) ?: [];
    $hname = trim((string) ($meta['name'] ?? '')) ?: get_string('soapbox:untitled', 'local_ai_course_assistant');
    $htopic = trim((string) ($meta['topic'] ?? ''));
    $dur = (int) ($h->session_duration ?? 0);

    $criteria = [];
    foreach ((array) $h->scores as $c) {
        // v7.5.1: a criterion the model was never given evidence for is marked,
        // not printed as a bare 0. This is the STORED-score renderer; the live
        // table in soapbox_result.mustache consumes the same `assessed` field
        // from the scoring response and must stay in step with it.
        $criteria[] = [
            'name' => (string) ($c['name'] ?? ''),
            'assessed' => rubric_manager::is_assessed($c),
            'score' => (int) ($c['score'] ?? 0),
            'feedback' => (string) ($c['feedback'] ?? ''),
        ];
    }

    $historyentries[] = [
        'name' => $hname,
        'hastopic' => $htopic !== '',
        'topic' => $htopic,
        'badge' => get_string('soapbox:overall_badge', 'local_ai_course_assistant', (int) $h->overall_score),
        'when' => userdate((int) $h->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
        'duration' => sprintf('%d:%02d', intdiv($dur, 60), $dur % 60),
        'criteria' => $criteria,
        'hasfeedback' => !empty($h->ai_feedback),
        'feedback' => (string) ($h->ai_feedback ?? ''),
    ];
}

// Only the strings the module sets imperatively. The headings and column
// labels are resolved inside the two templates with {{#str}}.
$jsstrings = [
    'recording' => get_string('soapbox:recording', 'local_ai_course_assistant'),
    'record' => '● ' . get_string('soapbox:record', 'local_ai_course_assistant'),
    'stop' => '■ ' . get_string('soapbox:stop', 'local_ai_course_assistant'),
    'transcribing' => get_string('soapbox:transcribing', 'local_ai_course_assistant'),
    'scoring' => get_string('soapbox:scoring', 'local_ai_course_assistant'),
    'tooShort' => get_string('soapbox:too_short', 'local_ai_course_assistant'),
    'micDenied' => get_string('soapbox:mic_denied', 'local_ai_course_assistant'),
    'noBrowserStt' => get_string('soapbox:no_browser_stt', 'local_ai_course_assistant'),
    'browserNote' => get_string('soapbox:browser_note', 'local_ai_course_assistant'),
    'serverNote' => get_string('soapbox:server_note', 'local_ai_course_assistant'),
    'error' => get_string('soapbox:error', 'local_ai_course_assistant'),
    'noMediaSupport' => get_string('soapbox:no_media_support', 'local_ai_course_assistant'),
    'errProvider' => get_string('soapbox:err_provider', 'local_ai_course_assistant'),
    'errParse' => get_string('soapbox:err_parse', 'local_ai_course_assistant'),
    'errDisabled' => get_string('soapbox:err_disabled', 'local_ai_course_assistant'),
    'errTranscribe' => get_string('soapbox:err_transcribe', 'local_ai_course_assistant'),
];

$templatedata = [
    'sttmode' => $sttmode,
    'configjson' => json_encode([
        'courseid' => $courseid,
        'transcribeurl' => $transcribeurl,
        'sesskey' => sesskey(),
        'strings' => $jsstrings,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    'targetoptions' => $targetoptions,
    'modeoptions' => $modeoptions,
    'history' => $historyentries,
];

$PAGE->requires->js_call_amd('local_ai_course_assistant/soapbox', 'init');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/soapbox', $templatedata);
echo $OUTPUT->footer();
