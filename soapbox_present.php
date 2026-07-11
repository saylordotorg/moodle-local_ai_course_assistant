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
 * audio presentation, upload it, and see past attempts.
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
    throw new \moodle_exception('invalidrecord', 'error');
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

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($assign->name));

if (!empty($assign->intro)) {
    echo html_writer::div(
        format_text($assign->intro, (int) $assign->introformat, ['context' => $context]),
        'sbx-intro mb-3');
}

// A short at-a-glance line.
$mins = ceil((int) $assign->min_seconds / 60);
$maxs = ceil((int) $assign->max_seconds / 60);
echo html_writer::div(
    ($assign->mode === 'audio' ? 'Audio presentation' : 'Video presentation')
    . ' &middot; ' . s(ucfirst($assign->ptype))
    . ' &middot; target ' . $mins . '-' . $maxs . ' min',
    'sbx-meta text-muted mb-3');

// Topic picker.
if ($hastopics) {
    $options = [];
    foreach ($topics as $t) {
        $options[$t->id] = format_string($t->title);
    }
    echo html_writer::start_div('sbx-topics mb-3');
    echo html_writer::tag('label', 'Choose a topic', ['for' => 'sbx-topic', 'class' => 'font-weight-bold d-block']);
    echo html_writer::select($options, 'sbx-topic', '', false, ['id' => 'sbx-topic']);
    foreach ($topics as $t) {
        if (!empty($t->instructions)) {
            echo html_writer::div(
                html_writer::tag('strong', format_string($t->title) . ': ')
                . format_text($t->instructions, (int) $t->instructionsformat, ['context' => $context]),
                'sbx-topic-detail small text-muted mt-2');
        }
    }
    echo html_writer::end_div();
}

// Recorder widget.
if (!$storageready) {
    echo $OUTPUT->notification(
        get_string('soapbox:storage_unconfigured', 'local_ai_course_assistant'), 'warning');
} else {
    $modeclass = 'sbx-recorder card p-3 mb-4 sbx-mode-' . ($assign->mode === 'audio' ? 'audio' : 'video');
    echo html_writer::start_div($modeclass, ['id' => 'sbx-recorder']);

    // Slides: deck upload + viewer. The learner uploads a PDF deck, which is
    // rendered to page images they advance while recording (the advance timeline
    // is captured with the recording).
    if (!empty($assign->slides_enabled)) {
        echo html_writer::start_div('sbx-deck mb-3');
        echo html_writer::tag('label', get_string('soapbox:deck_label', 'local_ai_course_assistant'),
            ['for' => 'sbx-deck-input', 'class' => 'font-weight-bold d-block']);
        echo html_writer::empty_tag('input', [
            'type' => 'file', 'accept' => 'application/pdf,.pdf',
            'id' => 'sbx-deck-input', 'class' => 'sbx-deck-input form-control-file',
        ]);
        echo html_writer::div('', 'sbx-deck-status small text-muted mt-1');
        echo html_writer::div('', 'sbx-slide-viewer mt-2',
            ['style' => 'max-width:640px']);
        echo html_writer::end_div();
    }

    if ($assign->mode !== 'audio') {
        echo html_writer::empty_tag('video', [
            'class' => 'sbx-preview w-100 mb-2', 'playsinline' => 'playsinline',
            'style' => 'max-height:360px;background:#000;border-radius:6px',
        ]);
    } else {
        // Audio-only: no camera. A mic indicator that pulses while recording
        // gives the learner clear feedback that audio is being captured.
        echo html_writer::div(
            html_writer::tag('span', '', ['class' => 'sbx-mic-dot'])
            . html_writer::tag('span', get_string('soapbox:audio_ready', 'local_ai_course_assistant'),
                ['class' => 'sbx-mic-label']),
            'sbx-audio-indicator d-flex align-items-center mb-2', ['style' => 'gap:10px']);
    }
    echo html_writer::start_div('sbx-controls d-flex align-items-center mb-2', ['style' => 'gap:12px']);
    echo html_writer::tag('button', 'Record', ['type' => 'button', 'class' => 'sbx-record btn btn-primary']);
    echo html_writer::tag('button', 'Stop', ['type' => 'button', 'class' => 'sbx-stop btn btn-outline-secondary']);
    echo html_writer::tag('span', '0:00', ['class' => 'sbx-timer font-weight-bold']);
    echo html_writer::end_div();
    echo html_writer::div('', 'sbx-status text-muted');
    echo html_writer::div('', 'sbx-result mt-2');
    echo html_writer::end_div();

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
    'timecreated DESC');

echo $OUTPUT->heading('My recordings', 4);
if (empty($recs)) {
    echo html_writer::div('No recordings yet.', 'text-muted');
} else {
    $storage = $storageready ? new soapbox_storage() : null;
    $table = new html_table();
    $table->head = ['Recorded', 'Length', 'Status', ''];
    $table->attributes['class'] = 'generaltable';
    foreach ($recs as $r) {
        $len = gmdate('i:s', (int) $r->duration_seconds);
        $view = '';
        if ($storage && $r->storage_key && $r->status !== 'deleted') {
            $url = $storage->presign_get($r->storage_key, 3600);
            $view = html_writer::link($url, 'View / download', ['target' => '_blank', 'rel' => 'noopener']);
        } else if ($r->status === 'deleted') {
            $view = html_writer::span('Expired', 'text-muted');
        }
        $table->data[] = [
            userdate((int) $r->timecreated, get_string('strftimedatetimeshort')),
            $len,
            s($r->status),
            $view,
        ];
    }
    echo html_writer::table($table);
    echo html_writer::div(
        'Recordings are available to view and download for ' . soapbox_config::retention_days()
        . ' days, then automatically deleted.', 'small text-muted mt-1');
}

echo $OUTPUT->footer();
