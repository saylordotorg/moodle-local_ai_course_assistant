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
 * Soapbox presentation assignment — learner page (v6.8.16). Record a video or
 * audio presentation, upload it, and see past attempts. Rendered via the
 * Templates API (templates/soapbox_present.mustache).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\soapbox_assignment_manager;
use local_ai_course_assistant\soapbox_config;
use local_ai_course_assistant\soapbox_storage;
use local_ai_course_assistant\feature_flags;

require_login();

$id = required_param('id', PARAM_INT);
$assign = soapbox_assignment_manager::get_assignment($id);
if (!$assign) {
    // Core's 'invalidrecord' string interpolates a table name via {$a}; thrown
    // without one it renders literally as "Can't find data record in database
    // table {$a}." A learner following a stale or hand-edited link saw the raw
    // placeholder. Use a plugin string that reads as a sentence instead.
    throw new \moodle_exception('soapbox:assignment_notfound', 'local_ai_course_assistant');
}
$courseid = (int) $assign->courseid;
$course = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('local/ai_course_assistant:use', $context);

// v7.4.3: this page is now reachable from a url activity on the course page, and
// mod_url enforces neither :manage nor the Soapbox feature flag -- it only knows
// it points somewhere. So the two conditions below stopped being "impossible
// unless the link was hand-edited" and became an ordinary mis-click: a teacher
// hiding the assignment, or the feature being switched off for the course, while
// the activity is still sitting on the page.
//
// Rendering a notice with a way back beats an error page for both. A hidden
// assignment is still shown to anyone who can manage it, so a teacher can preview
// before revealing it -- which is the normal Moodle expectation for hidden things.
$blocked = null;
if (!$assign->visible && !has_capability('local/ai_course_assistant:manage', $context)) {
    $blocked = get_string('soapbox:assignment_hidden', 'local_ai_course_assistant');
} else if (!feature_flags::resolve('soapbox', $courseid)) {
    $blocked = get_string('soapbox:disabled', 'local_ai_course_assistant');
}

$pageurl = new moodle_url('/local/ai_course_assistant/soapbox_present.php', ['id' => $id]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_course($course);
$PAGE->set_title(format_string($assign->name));
$PAGE->set_heading($course->fullname);

$topics = soapbox_assignment_manager::get_topics($id);
$hastopics = !empty($topics);
$storageready = soapbox_storage::is_configured();
$quality = soapbox_config::quality();

// A short at-a-glance line.
$mins = ceil((int) $assign->min_seconds / 60);
$maxs = ceil((int) $assign->max_seconds / 60);

$templatedata = [
    'hasintro' => !empty($assign->intro),
    'introhtml' => !empty($assign->intro)
        ? format_text($assign->intro, (int) $assign->introformat, ['context' => $context])
        : '',
    'modelabel' => $assign->mode === 'audio'
        ? get_string('soapbox:present_audio', 'local_ai_course_assistant')
        : get_string('soapbox:present_video', 'local_ai_course_assistant'),
    'ptype' => ucfirst($assign->ptype),
    'targetlabel' => get_string(
        'soapbox:present_target',
        'local_ai_course_assistant',
        (object) ['min' => $mins, 'max' => $maxs]
    ),
    'hastopics' => $hastopics,
    'choosetopic' => get_string('soapbox:choose_topic', 'local_ai_course_assistant'),
    'topics' => [],
    'topicdetails' => [],
    'storageready' => $storageready,
    'storagewarninghtml' => $storageready ? '' : $OUTPUT->notification(
        get_string('soapbox:storage_unconfigured', 'local_ai_course_assistant'),
        'warning'
    ),
    'modeclass' => $assign->mode === 'audio' ? 'sbx-mode-audio' : 'sbx-mode-video',
    'isaudio' => $assign->mode === 'audio',
    'isvideo' => $assign->mode !== 'audio',
    'slidesenabled' => !empty($assign->slides_enabled),
    'decklabel' => get_string('soapbox:deck_label', 'local_ai_course_assistant'),
    'audioready' => get_string('soapbox:audio_ready', 'local_ai_course_assistant'),
    'recordlabel' => get_string('soapbox:record_short', 'local_ai_course_assistant'),
    'stoplabel' => get_string('soapbox:stop', 'local_ai_course_assistant'),
    'recordingsheadinghtml' => $OUTPUT->heading(
        get_string('soapbox:my_recordings', 'local_ai_course_assistant'),
        4
    ),
    'hasrecordings' => false,
    'norecordingstext' => get_string('soapbox:no_recordings', 'local_ai_course_assistant'),
    'colrecorded' => get_string('soapbox:col_recorded', 'local_ai_course_assistant'),
    'collength' => get_string('soapbox:col_length', 'local_ai_course_assistant'),
    'colstatus' => get_string('status'),
    'viewlabel' => get_string('soapbox:view_download', 'local_ai_course_assistant'),
    'playlabel' => get_string('soapbox:play_slides', 'local_ai_course_assistant'),
    'expiredlabel' => get_string('soapbox:expired', 'local_ai_course_assistant'),
    'recordings' => [],
    'retentionnote' => get_string(
        'soapbox:retention_note',
        'local_ai_course_assistant',
        soapbox_config::retention_days()
    ),
    // v7.5.1: setup guidance, shown before a learner records rather than after.
    // These courses are fully online and self-paced, so there is no instructor
    // to ask how to frame a shot, and a learner who fills the frame with their
    // forehead cannot be given body-language feedback at all.
    'howtoheading' => get_string('soapbox:howto_heading', 'local_ai_course_assistant'),
    'howto' => [
        ['text' => get_string('soapbox:howto_frame', 'local_ai_course_assistant')],
        ['text' => get_string('soapbox:howto_light', 'local_ai_course_assistant')],
        ['text' => get_string('soapbox:howto_eyes', 'local_ai_course_assistant')],
        ['text' => get_string('soapbox:howto_hands', 'local_ai_course_assistant')],
        ['text' => get_string('soapbox:howto_feedback', 'local_ai_course_assistant')],
    ],
    // The retention statement derives the number from the setting rather than
    // hardcoding 7, so a site that raises the window does not start lying.
    // branding::str(), not get_string(): this string carries [[uniname]], and
    // nothing downstream resolves brand tokens. Resolution happens at the output
    // boundary, and this page is one. get_string() here rendered the literal
    // "[[uniname]] storage" to every learner, in all 46 locales.
    //
    // tests/branding_test.php cannot catch this: it asserts no string RETAINS a
    // token after apply() runs, not that a caller remembered to run it.
    'privacynote' => \local_ai_course_assistant\branding::str(
        'soapbox:present_privacy',
        soapbox_config::retention_days()
    ),
    'coldeletes' => get_string('soapbox:col_deletes', 'local_ai_course_assistant'),
    'watchlabel' => get_string('soapbox:watch', 'local_ai_course_assistant'),
    'downloadlabel' => get_string('soapbox:download', 'local_ai_course_assistant'),
    'downloadaria' => get_string('soapbox:download_aria', 'local_ai_course_assistant'),
    'feedbacktoggle' => get_string('soapbox:feedback_toggle', 'local_ai_course_assistant'),
    'feedbackpending' => get_string('soapbox:feedback_pending', 'local_ai_course_assistant'),
    'notassessed' => get_string('soapbox:not_assessed', 'local_ai_course_assistant'),
    'notassessedaria' => get_string('soapbox:not_assessed_aria', 'local_ai_course_assistant'),
    'colcriterion' => get_string('soapbox:col_criterion', 'local_ai_course_assistant'),
    'colscore' => get_string('soapbox:col_score', 'local_ai_course_assistant'),
    'colfeedback' => get_string('soapbox:col_feedback', 'local_ai_course_assistant'),
];

// Topic picker.
if ($hastopics) {
    foreach ($topics as $t) {
        $templatedata['topics'][] = [
            'id' => (int) $t->id,
            'title' => format_string($t->title),
        ];
        if (!empty($t->instructions)) {
            $templatedata['topicdetails'][] = [
                'title' => format_string($t->title),
                'instructions' => format_text($t->instructions, (int) $t->instructionsformat, ['context' => $context]),
            ];
        }
    }
}

// Recorder widget.
if ($storageready) {
    $PAGE->requires->js_call_amd('local_ai_course_assistant/soapbox_present', 'init', [
        [
            'assignid'      => (int) $assign->id,
            'mode'          => $assign->mode,
            'minSeconds'    => (int) $assign->min_seconds,
            'maxSeconds'    => (int) $assign->max_seconds,
            'quality'       => [
                'width'     => (int) $quality['width'],
                'height'    => (int) $quality['height'],
                'videoKbps' => (int) $quality['video_kbps'],
                'audioKbps' => (int) $quality['audio_kbps'],
            ],
            // v7.5.1: whether to sample still frames for body-language
            // feedback. Off means the recorder skips the work entirely rather
            // than uploading frames nothing will read.
            'gestureEnabled' => \local_ai_course_assistant\soapbox_gesture_vision::is_enabled($assign),
            'topicid'       => 0,
            'topicSelector' => $hastopics ? '#sbx-topic' : null,
            'slidesEnabled' => !empty($assign->slides_enabled),
            'prevLabel'     => get_string('soapbox:slide_prev', 'local_ai_course_assistant'),
            'nextLabel'     => get_string('soapbox:slide_next', 'local_ai_course_assistant'),
        ],
        [
            'root' => '#sbx-recorder', 'preview' => '.sbx-preview', 'record' => '.sbx-record',
            'stop' => '.sbx-stop', 'timer' => '.sbx-timer', 'status' => '.sbx-status', 'result' => '.sbx-result',
            'deckInput' => '.sbx-deck-input', 'deckStatus' => '.sbx-deck-status', 'slideViewer' => '.sbx-slide-viewer',
        ],
    ]);
}

// Past attempts.
$recs = $DB->get_records_select(
    'local_ai_course_assistant_sbx_rec',
    'assignid = :a AND userid = :u AND status <> :d',
    ['a' => $id, 'u' => $USER->id, 'd' => 'deleted'],
    'timecreated DESC'
);

if (!empty($recs)) {
    $templatedata['hasrecordings'] = true;
    $storage = $storageready ? new soapbox_storage() : null;
    foreach ($recs as $r) {
        $row = [
            'recorded' => userdate((int) $r->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            'length' => gmdate('i:s', (int) $r->duration_seconds),
            'viewurl' => '',
            'hasplay' => false,
            'recid' => (int) $r->id,
            'expired' => false,
        ];
        // v7.5.1: the date this recording disappears, taken from the row rather
        // than from "7 days" in prose, so it stays true if an admin changes the
        // window or a row was written under a different one.
        $row['deleteson'] = ((int) ($r->expires_at ?? 0) > 0)
            ? userdate((int) $r->expires_at, get_string('strftimedatefullshort', 'langconfig'))
            : '';
        $row['downloadurl'] = '';

        // v7.5.1: a learner's own score and feedback. Every one of these has
        // been generated, paid for and written to practice_scores since v6.7.0,
        // and none of it has ever been rendered: score_recording() keeps the
        // scoreid and discards the rest, and this page never read the table. A
        // self-paced learner with no instructor had no way to see any of it.
        $row['hasfeedback'] = false;
        $row['criteria'] = [];
        $row['tips'] = [];
        $row['hastips'] = false;
        $row['overall'] = '';
        $row['scoredon'] = '';
        $row['visualnote'] = '';
        if (!empty($r->scoreid)) {
            try {
                $score = $DB->get_record(
                    'local_ai_course_assistant_practice_scores',
                    ['id' => (int) $r->scoreid, 'userid' => $USER->id]
                );
                if ($score) {
                    $criteria = json_decode((string) $score->scores, true);
                    $meta = json_decode((string) ($score->session_meta ?? ''), true);
                    if (is_array($criteria) && !empty($criteria)) {
                        foreach ($criteria as $c) {
                            // A row written before v7.5.1 carries no `assessed`
                            // key. Absent means assessed, or every historic
                            // score would render as "not assessed".
                            $assessed = !isset($c['assessed']) || (bool) $c['assessed'];
                            $row['criteria'][] = [
                                'name' => (string) ($c['name'] ?? ''),
                                'score' => (int) ($c['score'] ?? 0),
                                'feedback' => (string) ($c['feedback'] ?? ''),
                                'assessed' => $assessed,
                            ];
                        }
                        $totals = \local_ai_course_assistant\rubric_manager::compute_overall($criteria);
                        $row['overall'] = (string) ($score->ai_feedback ?? '');
                        $row['scoredon'] = get_string(
                            'soapbox:scored_on',
                            'local_ai_course_assistant',
                            (object) [
                                'assessed' => $totals['assessed'],
                                'total' => count($criteria),
                                'pct' => $totals['pct'],
                            ]
                        );
                        if ($totals['assessed'] < count($criteria)) {
                            $row['visualnote'] = get_string(
                                'soapbox:visual_not_assessed',
                                'local_ai_course_assistant'
                            );
                        }
                        if (is_array($meta) && !empty($meta['tips']) && is_array($meta['tips'])) {
                            foreach ($meta['tips'] as $tip) {
                                $row['tips'][] = ['text' => (string) $tip];
                            }
                        }
                        $row['hastips'] = !empty($row['tips']);
                        $row['hasfeedback'] = true;
                    }
                }
            } catch (\Throwable $e) {
                // A missing or malformed score row renders the pending message.
                // A learner losing the page entirely over one bad JSON blob
                // would be a worse outcome than losing one attempt's feedback.
                $row['hasfeedback'] = false;
            }
        }

        if ($storage && $r->storage_key && $r->status !== 'deleted') {
            $row['viewurl'] = $storage->presign_get($r->storage_key, 3600);
            // Same object, but as a download the learner can keep after the
            // retention window closes.
            $ext = pathinfo((string) $r->storage_key, PATHINFO_EXTENSION);
            $row['downloadurl'] = $storage->presign_get(
                $r->storage_key,
                3600,
                'presentation-' . userdate((int) $r->timecreated, '%Y-%m-%d')
                    . ($ext !== '' ? '.' . $ext : '')
            );
            // Slides playback: recordings that carry a deck can be played back
            // with the slides advancing in sync.
            if (!empty($r->deck_key) && !empty($r->slide_timeline)) {
                $row['hasplay'] = true;
            }
        } else if ($r->status === 'deleted') {
            $row['expired'] = true;
        }
        // Human status, not the raw DB value: a learner whose row said
        // `failed` got no guidance, and with v7.3.3's cap change a failed
        // attempt no longer counts, which the label should say.
        $statuskey = in_array($r->status, ['uploaded', 'scored', 'failed'], true)
            ? 'soapbox:status_' . $r->status
            : null;
        $row['status'] = $statuskey
            ? get_string($statuskey, 'local_ai_course_assistant')
            : s($r->status);
        $templatedata['recordings'][] = $row;
    }
    $PAGE->requires->js_call_amd(
        'local_ai_course_assistant/soapbox_player',
        'init',
        ['#sbx-playback', '.sbx-play-btn']
    );
}

echo $OUTPUT->header();

if ($blocked !== null) {
    echo $OUTPUT->notification($blocked, \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid]));
    echo $OUTPUT->footer();
    exit;
}

if (!$assign->visible) {
    // Visible only to someone who can manage it -- see above.
    echo $OUTPUT->notification(
        get_string('soapbox:assignment_hidden_preview', 'local_ai_course_assistant'),
        \core\output\notification::NOTIFY_WARNING
    );
}
echo $OUTPUT->heading(format_string($assign->name));
echo $OUTPUT->render_from_template('local_ai_course_assistant/soapbox_present', $templatedata);
echo $OUTPUT->footer();
