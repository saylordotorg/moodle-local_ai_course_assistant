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
 * Admin page to create a hidden testing course and seed fake student
 * conversation data so admins can preview the Analytics Dashboard and
 * the widget behaviour without exposing anything to real students.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_ai_course_assistant_demoadmin');

$PAGE->set_url(new moodle_url('/local/ai_course_assistant/demo_admin.php'));
$PAGE->set_title('SOLA: Testing Environment');
$PAGE->set_heading('SOLA: Testing Environment');

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$users = optional_param('users', 15, PARAM_INT);
$weeks = optional_param('weeks', 4, PARAM_INT);
$clear = optional_param('clear', 0, PARAM_BOOL);

$output = $PAGE->get_renderer('core');
echo $output->header();
echo $output->heading('Testing Environment');

$existing = $DB->get_record('course', ['shortname' => 'SOLATEST'], 'id,fullname,shortname,visible');

if ($action === 'create' && confirm_sesskey()) {
    try {
        $course = \local_ai_course_assistant\demo_seeder::create_testing_course(
            'SOLATEST',
            'SOLA Testing Course (hidden)',
            1,
            true
        );
        $msg = 'Testing course ready: ' . format_string($course->fullname) . ' (id ' . $course->id . ').';
        echo $output->notification($msg, \core\output\notification::NOTIFY_SUCCESS);
        $existing = $DB->get_record('course', ['id' => $course->id], 'id,fullname,shortname,visible');
    } catch (\Throwable $e) {
        echo $output->notification('Failed to create course: ' . $e->getMessage(),
            \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'seed' && confirm_sesskey() && $courseid > 0) {
    try {
        $counts = \local_ai_course_assistant\demo_seeder::seed_demo_students(
            $courseid,
            $users,
            $weeks,
            (bool) $clear
        );
        $msg = sprintf(
            'Seeded: %d users, %d conversations, %d messages, %d ratings, %d feedback entries.',
            $counts['users'], $counts['conversations'], $counts['messages'],
            $counts['ratings'], $counts['feedback']
        );
        echo $output->notification($msg, \core\output\notification::NOTIFY_SUCCESS);
        purge_caches(['theme' => false, 'lang' => false]);
    } catch (\Throwable $e) {
        echo $output->notification('Failed to seed data: ' . $e->getMessage(),
            \core\output\notification::NOTIFY_ERROR);
    }
}

echo html_writer::tag('p',
    'This page creates a testing course that is <strong>hidden from students</strong> '
    . '(visible=0) and seeds it with fake students, AI conversations, ratings, and feedback. '
    . 'Useful for previewing the Analytics Dashboard or validating plugin changes without '
    . 'affecting any real enrolled student.');

$sesskey = sesskey();
$pageurl = new moodle_url('/local/ai_course_assistant/demo_admin.php');

echo html_writer::start_tag('div', ['class' => 'card mb-3', 'style' => 'max-width:720px']);
echo html_writer::start_tag('div', ['class' => 'card-body']);
echo html_writer::tag('h3', 'Step 1: testing course', ['class' => 'mb-2', 'style' => 'font-size:18px']);

if ($existing) {
    $hidden = !$existing->visible ? ' <span class="badge badge-secondary">hidden</span>' : ' <span class="badge badge-warning">visible to students</span>';
    echo html_writer::tag('p',
        'Testing course exists: <strong>' . s($existing->fullname) . '</strong> (shortname <code>'
        . s($existing->shortname) . '</code>, id ' . $existing->id . ')' . $hidden);
    echo html_writer::link(
        new moodle_url('/course/view.php', ['id' => $existing->id]),
        'Open course &rarr;',
        ['class' => 'btn btn-sm btn-outline-primary']
    );
} else {
    echo html_writer::tag('p', 'No testing course found. Click below to create one.');
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $sesskey]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'create']);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => 'Create hidden testing course',
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');
}
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

if ($existing) {
    echo html_writer::start_tag('div', ['class' => 'card mb-3', 'style' => 'max-width:720px']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo html_writer::tag('h3', 'Step 2: seed fake students and AI chats', ['class' => 'mb-2', 'style' => 'font-size:18px']);
    echo html_writer::tag('p',
        'Creates demo_student_001, demo_student_002, ... enrols them in the testing course, '
        . 'and inserts synthetic conversations, messages, ratings, and feedback. Run again '
        . 'to add more data, or tick "clear first" to start over.');

    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'form-inline']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $sesskey]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'seed']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $existing->id]);

    echo html_writer::start_tag('div', ['class' => 'form-group mr-2']);
    echo html_writer::label('Users', 'users', false, ['class' => 'mr-1']);
    echo html_writer::empty_tag('input', [
        'type' => 'number', 'id' => 'users', 'name' => 'users',
        'value' => 15, 'min' => 1, 'max' => 100,
        'class' => 'form-control form-control-sm', 'style' => 'width:80px',
    ]);
    echo html_writer::end_tag('div');

    echo html_writer::start_tag('div', ['class' => 'form-group mr-2']);
    echo html_writer::label('Weeks', 'weeks', false, ['class' => 'mr-1']);
    echo html_writer::empty_tag('input', [
        'type' => 'number', 'id' => 'weeks', 'name' => 'weeks',
        'value' => 4, 'min' => 1, 'max' => 52,
        'class' => 'form-control form-control-sm', 'style' => 'width:80px',
    ]);
    echo html_writer::end_tag('div');

    echo html_writer::start_tag('div', ['class' => 'form-check mr-3']);
    echo html_writer::empty_tag('input', [
        'type' => 'checkbox', 'id' => 'clear', 'name' => 'clear', 'value' => 1,
        'class' => 'form-check-input',
    ]);
    echo html_writer::tag('label', 'Clear existing demo_* users first',
        ['for' => 'clear', 'class' => 'form-check-label']);
    echo html_writer::end_tag('div');

    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => 'Seed students and chats',
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');

    echo html_writer::tag('p',
        '<a href="' . (new moodle_url('/local/ai_course_assistant/analytics.php',
            ['courseid' => $existing->id]))->out() . '" class="btn btn-sm btn-outline-secondary mt-3">'
        . 'View Analytics for this course &rarr;</a>',
        ['class' => 'mt-2']);

    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

echo html_writer::start_tag('div', ['style' => 'max-width:720px']);
echo html_writer::tag('p',
    '<small class="text-muted">Data created here lives in the standard Moodle user / enrolment tables '
    . 'and the plugin\'s own conversation tables. The fake users all have usernames starting with '
    . '<code>demo_student_</code> so they are easy to filter or remove. To remove them, run the seed '
    . 'step again with "Clear existing demo_* users first" checked.</small>');
echo html_writer::end_tag('div');

echo $output->footer();
