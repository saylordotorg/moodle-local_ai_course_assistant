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
 * Admin page — on-demand backend self-test (v5.10.0).
 *
 * Round-trips a tiny completion, detects the backend context window, checks
 * it against the configured backend_context_tokens, and (when RAG is on)
 * checks embeddings. Aimed at self-hosted admins verifying their setup
 * without the author's help. Live network calls run only when the admin
 * presses Run, never on page load.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);

$run = optional_param('run', 0, PARAM_BOOL);
$runstorage = optional_param('runstorage', 0, PARAM_BOOL);

$pageurl = new moodle_url('/local/ai_course_assistant/backend_selftest.php');
$PAGE->set_url($pageurl);
$PAGE->set_context($syscontext);
$PAGE->set_title(get_string('selftest:title', 'local_ai_course_assistant'));
$PAGE->set_heading(get_string('selftest:title', 'local_ai_course_assistant'));
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('selftest:title', 'local_ai_course_assistant'));
echo html_writer::tag('p', get_string('selftest:intro', 'local_ai_course_assistant'));

// Both probes report the same row shape, so they share one renderer.
$rendertable = function (array $rows): void {
    $badge = [
        'pass' => 'badge badge-success',
        'warn' => 'badge badge-warning',
        'fail' => 'badge badge-danger',
    ];

    $table = new html_table();
    $table->head = [
        get_string('selftest:check', 'local_ai_course_assistant'),
        get_string('selftest:status', 'local_ai_course_assistant'),
        get_string('selftest:detail', 'local_ai_course_assistant'),
    ];
    foreach ($rows as $row) {
        $cls = $badge[$row['status']] ?? 'badge badge-secondary';
        $table->data[] = [
            s($row['label']),
            html_writer::span(strtoupper($row['status']), $cls),
            s($row['message']),
        ];
    }
    echo html_writer::table($table);
};

if ($run && confirm_sesskey()) {
    $rendertable(\local_ai_course_assistant\backend_probe::run_all());
}

echo $OUTPUT->single_button(
    new moodle_url($pageurl, ['run' => 1, 'sesskey' => sesskey()]),
    get_string('selftest:run', 'local_ai_course_assistant'),
    'get'
);

// Soapbox object storage. Separate button because it performs real writes and
// deletes against the configured bucket, which an admin should opt into rather
// than have happen as a side effect of testing the chat backend.
echo $OUTPUT->heading(get_string('selftest:storage_title', 'local_ai_course_assistant'), 3);
echo html_writer::tag('p', get_string('selftest:storage_intro', 'local_ai_course_assistant'));

if ($runstorage && confirm_sesskey()) {
    $rendertable(\local_ai_course_assistant\soapbox_storage_probe::run_all());
}

echo $OUTPUT->single_button(
    new moodle_url($pageurl, ['runstorage' => 1, 'sesskey' => sesskey()]),
    get_string('selftest:storage_run', 'local_ai_course_assistant'),
    'get'
);

echo $OUTPUT->footer();
