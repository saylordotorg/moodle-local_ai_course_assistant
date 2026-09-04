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
 * Anonymized chat transcripts and summaries, by course, unit, topic, date range
 * and learning outcome. View in the browser or download as CSV.
 *
 * Learner identities are replaced with per-report pseudonyms before anything is
 * rendered or written, and the download is audited.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\transcript_report;
use local_ai_course_assistant\audit_logger;

$courseid = required_param('courseid', PARAM_INT);
$mode     = optional_param('mode', 'transcripts', PARAM_ALPHA);
$section  = optional_param('section', '', PARAM_RAW_TRIMMED);
$topic    = optional_param('topic', '', PARAM_TEXT);
$fromraw  = optional_param('from', '', PARAM_RAW_TRIMMED);
$toraw    = optional_param('to', '', PARAM_RAW_TRIMMED);
$objid    = optional_param('objectiveid', 0, PARAM_INT);
$outcome  = optional_param('outcome', '', PARAM_RAW_TRIMMED);
$download = optional_param('download', 0, PARAM_BOOL);

$course  = get_course($courseid);
$context = context_course::instance($courseid);
require_login($course);
require_capability('local/ai_course_assistant:viewanalytics', $context);

if (!in_array($mode, ['transcripts', 'summaries'], true)) {
    $mode = 'transcripts';
}
$outcome = ($outcome === '0' || $outcome === '1') ? $outcome : '';
$section = ($section === '' || preg_match('/^\d+$/', $section) === 1) ? $section : '';

$filters = [
    'courseid'    => $courseid,
    'section'     => $section,
    'topic'       => $topic,
    'from'        => $fromraw !== '' ? strtotime($fromraw) : 0,
    'to'          => $toraw !== '' ? strtotime($toraw . ' 23:59:59') : 0,
    'objectiveid' => $objid,
    'outcome'     => $outcome,
];

// One salt per request: pseudonyms are stable within a report and meaningless
// across reports, so two downloads cannot be joined on the label.
$salt = transcript_report::new_salt();
$rows = $mode === 'summaries'
    ? transcript_report::summaries($filters, $salt)
    : transcript_report::transcripts($filters, $salt);

if ($download) {
    require_sesskey();
    // Exporting learner conversation, even anonymized, is worth a record.
    audit_logger::log('transcript_export', (int) $USER->id, $courseid, [
        'mode'    => $mode,
        'rows'    => count($rows),
        'section' => $section === '' ? null : (int) $section,
        'topic'   => $topic === '' ? null : $topic,
        'from'    => $filters['from'] ?: null,
        'to'      => $filters['to'] ?: null,
        'objectiveid' => $objid ?: null,
        'outcome' => $outcome === '' ? null : (int) $outcome,
    ]);
    $filename = clean_filename(
        'sola-' . $mode . '-' . $course->shortname . '-' . date('Y-m-d')) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo transcript_report::to_csv($rows);
    exit;
}

$pageurl = new moodle_url('/local/ai_course_assistant/transcript_report.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('transcripts:title', 'local_ai_course_assistant'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('transcripts:title', 'local_ai_course_assistant'));
echo $OUTPUT->notification(
    get_string('transcripts:privacynote', 'local_ai_course_assistant'),
    \core\output\notification::NOTIFY_INFO);

// ---- filter form -----------------------------------------------------------
$modinfo = get_fast_modinfo($courseid);
$sections = [];
foreach ($modinfo->get_section_info_all() as $sec) {
    $sections[(string) $sec->section] = get_section_name($courseid, $sec);
}
// Offer only objectives that HAVE conversation-linked attempts. The outcome
// filter joins through obj_att.msgid, which only the conversation classifier
// populates; quiz and rubric attempts carry msgid NULL. An objective assessed
// solely by quiz or Soapbox rubric therefore always returned an empty table,
// which an instructor reads as "no learner discussed this" -- a wrong
// conclusion from a correct query, on a WSCUC accreditation artifact.
$objectives = $DB->get_records_sql_menu(
    "SELECT o.id, o.code
       FROM {local_ai_course_assistant_objs} o
      WHERE o.courseid = :courseid
        AND EXISTS (SELECT 1
                      FROM {local_ai_course_assistant_obj_att} a
                     WHERE a.objectiveid = o.id AND a.msgid IS NOT NULL)
   ORDER BY o.sortorder, o.code",
    ['courseid' => $courseid]
);

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $pageurl->out(false),
    'class' => 'mb-4']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
echo html_writer::start_div('form-row align-items-end');

$selects = [
    ['mode', get_string('transcripts:mode', 'local_ai_course_assistant'),
        ['transcripts' => get_string('messages', 'core_message'),
         'summaries'   => get_string('summary', 'core')], $mode],
    ['section', get_string('section', 'core'),
        ['' => get_string('all', 'core')] + $sections, $section],
    ['objectiveid', get_string('outcome', 'grades'),
        [0 => get_string('all', 'core')] + $objectives, $objid],
    ['outcome', get_string('status', 'core'),
        ['' => get_string('all', 'core'),
         '1' => get_string('transcripts:met', 'local_ai_course_assistant'),
         '0' => get_string('transcripts:notmet', 'local_ai_course_assistant')], $outcome],
];
foreach ($selects as [$name, $label, $options, $selected]) {
    echo html_writer::start_div('col-auto mb-2');
    echo html_writer::label($label, 'id_' . $name, true, ['class' => 'd-block small']);
    echo html_writer::select($options, $name, $selected, false,
        ['id' => 'id_' . $name, 'class' => 'custom-select']);
    echo html_writer::end_div();
}
foreach ([['topic', get_string('topic', 'core'), $topic, 'text'],
          ['from', get_string('from', 'core'), $fromraw, 'date'],
          ['to', get_string('transcripts:to', 'local_ai_course_assistant'), $toraw, 'date']] as [$n, $l, $v, $t]) {
    echo html_writer::start_div('col-auto mb-2');
    echo html_writer::label($l, 'id_' . $n, true, ['class' => 'd-block small']);
    echo html_writer::empty_tag('input', ['type' => $t, 'name' => $n, 'id' => 'id_' . $n,
        'value' => $v, 'class' => 'form-control']);
    echo html_writer::end_div();
}
echo html_writer::start_div('col-auto mb-2');
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary',
    'value' => get_string('apply', 'core')]);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_tag('form');

// ---- results ---------------------------------------------------------------
if (empty($rows)) {
    echo $OUTPUT->notification(
        get_string('nothingtodisplay', 'core'),
        \core\output\notification::NOTIFY_WARNING);
} else {
    // Rebuild the download URL from the already-validated locals rather than
    // from raw $_GET. PHP's array union keeps the LEFT operand on a key
    // collision, so `$_GET + ['download' => 1]` let an incoming `download=0`
    // (PARAM_BOOL reads '0' as false, so the page renders) override the 1 this
    // button needs -- the button just re-rendered the page. A stale sesskey in
    // a shared or bookmarked link won the same way and tripped require_sesskey.
    // Raw $_GET also carries integer keys (?7=x) and array values (?foo[]=x),
    // both of which moodle_url::params() rejects with a coding_exception.
    $dlparams = ['courseid' => $courseid, 'mode' => $mode];
    foreach (['section' => $section, 'topic' => $topic, 'from' => $fromraw,
              'to' => $toraw, 'outcome' => $outcome] as $k => $v) {
        if ($v !== '') {
            $dlparams[$k] = $v;
        }
    }
    if ($objid > 0) {
        $dlparams['objectiveid'] = $objid;
    }
    // download/sesskey last so they cannot be displaced by a filter value.
    $dlparams['download'] = 1;
    $dlparams['sesskey'] = sesskey();
    $dl = new moodle_url($pageurl, $dlparams);
    echo html_writer::div(
        $OUTPUT->single_button($dl, get_string('download', 'core'), 'get'),
        'mb-3');

    if (count($rows) >= transcript_report::MAX_ROWS) {
        echo $OUTPUT->notification(get_string('transcripts:truncated', 'local_ai_course_assistant',
            transcript_report::MAX_ROWS), \core\output\notification::NOTIFY_WARNING);
    }

    // Column headings. Most reuse a Moodle core or grades string, which is
    // already translated in every language pack, so this feature adds only the
    // labels that genuinely have no equivalent.
    $colstrings = [
        'conversation' => ['transcripts:col_conversation', 'local_ai_course_assistant'],
        'learner'      => ['transcripts:col_learner', 'local_ai_course_assistant'],
        'role'         => ['role', 'core'],
        'unit'         => ['section', 'core'],
        'type'         => ['transcripts:col_type', 'local_ai_course_assistant'],
        'when'         => ['date', 'core'],
        'message'      => ['transcripts:col_message', 'local_ai_course_assistant'],
        'messages'     => ['messages', 'core_message'],
        'first'        => ['first', 'core'],
        'last'         => ['last', 'core'],
        'outcomes'     => ['outcomes', 'grades'],
    ];
    $table = new html_table();
    $table->head = array_map(static function ($k) use ($colstrings) {
        [$id, $comp] = $colstrings[$k] ?? ['transcripts:col_' . $k, 'local_ai_course_assistant'];
        return get_string($id, $comp);
    }, array_keys($rows[0]));
    $table->attributes['class'] = 'generaltable table-sm';
    foreach ($rows as $r) {
        $table->data[] = array_map(static function ($v) {
            return s((string) $v);
        }, array_values($r));
    }
    echo html_writer::table($table);
    echo html_writer::div(get_string('transcripts:rowcount', 'local_ai_course_assistant',
        count($rows)), 'text-muted small');
}

echo $OUTPUT->footer();
