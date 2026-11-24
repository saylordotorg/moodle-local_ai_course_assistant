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
 * The answer audit page (8.4.0): one course's recorded answers, filters, one answer in detail, CSV and JSON export,
 * and the counts report. Teachers of the course (viewanalytics) and site admins.
 *
 * Read-only: the page writes nothing but an audit_logger row for each export. An export needs the sesskey.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\answer_audit;
use local_ai_course_assistant\answer_audit_report;
use local_ai_course_assistant\audit_logger;

require_login();

$courseid = optional_param('courseid', 0, PARAM_INT);
if ($courseid <= 0) {
    \local_ai_course_assistant\page_helpers::render_course_picker_landing(
        '/local/ai_course_assistant/answer_audit.php',
        get_string('coursepicker:title', 'local_ai_course_assistant',
            get_string('answeraudit:title', 'local_ai_course_assistant')),
        answer_audit_report::CAPABILITY
    );
    exit;
}

$course = get_course($courseid);
require_login($course);
$context = answer_audit_report::require_view($courseid);

$filters = answer_audit_report::parse_filters(
    optional_param('verdict', '', PARAM_ALPHAEXT),
    optional_param('rule', '', PARAM_TEXT),
    optional_param('from', '', PARAM_RAW_TRIMMED),
    optional_param('to', '', PARAM_RAW_TRIMMED)
);
$detailid = optional_param('id', 0, PARAM_INT);
$format = optional_param('format', '', PARAM_ALPHA);

if ($format === 'csv' || $format === 'json') {
    require_sesskey();
    $rows = answer_audit_report::export_rows($courseid, $filters);
    $chain = answer_audit::verify_chain($courseid);
    audit_logger::log('answer_audit_export', (int) $USER->id, $courseid, [
        'format' => $format,
        'rows' => count($rows),
        'verdict' => $filters['verdict'] === '' ? null : $filters['verdict'],
        'rule' => $filters['rule'] === '' ? null : $filters['rule'],
        'since' => $filters['since'] ?: null,
        'until' => $filters['until'] ?: null,
        'chain_verified' => !empty($chain['ok']),
    ]);
    $filename = clean_filename('sola-answer-audit-' . $course->shortname . '-' . date('Y-m-d')) . '.' . $format;
    \core\session\manager::write_close();
    header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'application/json') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $format === 'csv'
        ? answer_audit_report::to_csv($rows, $chain)
        : answer_audit_report::to_json($rows, $chain, $filters);
    exit;
}

$params = ['courseid' => $courseid];
foreach (['verdict', 'rule', 'from', 'to'] as $k) {
    if ($filters[$k] !== '') {
        $params[$k] = $filters[$k];
    }
}
if ($detailid > 0) {
    $params['id'] = $detailid;
}
$PAGE->set_url(new moodle_url('/local/ai_course_assistant/answer_audit.php', $params));
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('answeraudit:title', 'local_ai_course_assistant'));
$PAGE->set_heading($course->fullname);

// Build the context first: an answer id outside this course throws answeraudit:notfound before any output.
$templatecontext = answer_audit_report::template_context($courseid, $filters, $detailid);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('answeraudit:title', 'local_ai_course_assistant'));
echo $OUTPUT->render_from_template('local_ai_course_assistant/answer_audit', $templatecontext);
echo $OUTPUT->footer();
