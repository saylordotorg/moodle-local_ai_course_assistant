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
 * Outcomes-based assessment report (v6.8.28): per-outcome benchmark and the
 * percentage of assessed students who met it, with a CSV export for
 * accreditation reporting. Aligned to WSCUC practice.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\outcomes_report;

require_login();

$courseid = required_param('courseid', PARAM_INT);
$export = optional_param('export', '', PARAM_ALPHA);

$course = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('local/ai_course_assistant:viewanalytics', $context);

$rows = outcomes_report::course_report($courseid);

// CSV export for accreditation reporting.
if ($export === 'csv') {
    $filename = clean_filename('outcomes-' . $course->shortname . '-' . date('Y-m-d')) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Outcome code', 'Outcome', 'Benchmark (%)', 'Students assessed', 'Met benchmark', 'Percent met']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['code'], $r['title'], $r['benchmark_pct'], $r['n'], $r['met'], $r['pct']]);
    }
    fclose($out);
    exit;
}

$pageurl = new moodle_url('/local/ai_course_assistant/outcomes_report.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_course($course);
$PAGE->set_title(get_string('outcomes:title', 'local_ai_course_assistant'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('outcomes:title', 'local_ai_course_assistant'));

echo html_writer::div(
    get_string('outcomes:intro', 'local_ai_course_assistant', outcomes_report::default_benchmark_pct()),
    'outcomes-intro text-muted mb-3');

if (empty($rows)) {
    echo $OUTPUT->notification(get_string('outcomes:none', 'local_ai_course_assistant'), 'info');
} else {
    echo html_writer::div(
        $OUTPUT->single_button(new moodle_url($pageurl, ['export' => 'csv']),
            get_string('outcomes:export', 'local_ai_course_assistant'), 'get'),
        'mb-3');

    $table = new html_table();
    $table->head = [
        get_string('outcomes:col_outcome', 'local_ai_course_assistant'),
        get_string('outcomes:col_benchmark', 'local_ai_course_assistant'),
        get_string('outcomes:col_assessed', 'local_ai_course_assistant'),
        get_string('outcomes:col_met', 'local_ai_course_assistant'),
        get_string('outcomes:col_pct', 'local_ai_course_assistant'),
    ];
    $table->attributes['class'] = 'generaltable';
    // Screen-reader caption (visually hidden; the visible <h2> already names the table).
    $table->caption = get_string('outcomes:title', 'local_ai_course_assistant');
    $table->captionhide = true;
    foreach ($rows as $r) {
        $label = format_string($r['title']);
        if ($r['code'] !== '') {
            $label = html_writer::span(s($r['code']) . ' ', 'text-muted') . $label;
        }
        // Outcome name is the row header so screen readers associate each data cell with it.
        $labelcell = new html_table_cell($label);
        $labelcell->header = true;
        $labelcell->scope = 'row';
        $table->data[] = [
            $labelcell,
            $r['benchmark_pct'] . '%',
            $r['n'],
            $r['met'],
            html_writer::tag('strong', $r['pct'] . '%'),
        ];
    }
    echo html_writer::table($table);
    echo html_writer::div(
        get_string('outcomes:footnote', 'local_ai_course_assistant'), 'small text-muted mt-1');
}

echo $OUTPUT->footer();
