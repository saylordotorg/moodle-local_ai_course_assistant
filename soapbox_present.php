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
if (!$assign || !$assign->visible) {
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
if (!feature_flags::resolve('soapbox', $courseid)) {
    throw new \moodle_exception('soapbox:disabled', 'local_ai_course_assistant');
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
        if ($storage && $r->storage_key && $r->status !== 'deleted') {
            $row['viewurl'] = $storage->presign_get($r->storage_key, 3600);
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
echo $OUTPUT->heading(format_string($assign->name));
echo $OUTPUT->render_from_template('local_ai_course_assistant/soapbox_present', $templatedata);
echo $OUTPUT->footer();
