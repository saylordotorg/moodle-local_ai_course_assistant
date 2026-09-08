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
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_ai_course_assistant_demoadmin');

$PAGE->set_url(new moodle_url('/local/ai_course_assistant/demo_admin.php'));
$PAGE->set_title(get_string('demo:title', 'local_ai_course_assistant'));
$PAGE->set_heading(get_string('demo:heading', 'local_ai_course_assistant'));

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$users = optional_param('users', 15, PARAM_INT);
$weeks = optional_param('weeks', 4, PARAM_INT);
$clear = optional_param('clear', 0, PARAM_BOOL);

$output = $PAGE->get_renderer('core');

$existing = $DB->get_record('course', ['shortname' => 'SOLATEST'], 'id,fullname,shortname,visible');

$notifications = [];

if ($action === 'create' && confirm_sesskey()) {
    try {
        $course = \local_ai_course_assistant\demo_seeder::create_testing_course(
            'SOLATEST',
            get_string('demo:course_fullname', 'local_ai_course_assistant'),
            1,
            true
        );
        $msg = get_string('demo:notify_created', 'local_ai_course_assistant', (object) [
            'fullname' => format_string($course->fullname),
            'id' => $course->id,
        ]);
        $notifications[] = ['html' => $output->notification($msg, \core\output\notification::NOTIFY_SUCCESS)];
        $existing = $DB->get_record('course', ['id' => $course->id], 'id,fullname,shortname,visible');
    } catch (\Throwable $e) {
        $notifications[] = ['html' => $output->notification(
            get_string('demo:notify_create_fail', 'local_ai_course_assistant', $e->getMessage()),
            \core\output\notification::NOTIFY_ERROR
        )];
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
        $msg = get_string('demo:notify_seeded', 'local_ai_course_assistant', (object) $counts);
        $notifications[] = ['html' => $output->notification($msg, \core\output\notification::NOTIFY_SUCCESS)];
        purge_caches(['theme' => false, 'lang' => false]);
    } catch (\Throwable $e) {
        $notifications[] = ['html' => $output->notification(
            get_string('demo:notify_seed_fail', 'local_ai_course_assistant', $e->getMessage()),
            \core\output\notification::NOTIFY_ERROR
        )];
    }
}

$pageurl = new moodle_url('/local/ai_course_assistant/demo_admin.php');

$templatedata = [
    'notifications' => $notifications,
    'intro' => get_string('demo:intro', 'local_ai_course_assistant'),
    'formaction' => $pageurl->out(false),
    'sesskey' => sesskey(),
    'step1heading' => get_string('demo:step1', 'local_ai_course_assistant'),
    'nocoursetext' => get_string('demo:no_course', 'local_ai_course_assistant'),
    'createbtn' => get_string('demo:create_btn', 'local_ai_course_assistant'),
    'footer' => get_string('demo:footer', 'local_ai_course_assistant'),
    'course' => false,
];

if ($existing) {
    $templatedata['course'] = [
        'id' => $existing->id,
        'existsmsg' => get_string('demo:course_exists', 'local_ai_course_assistant', (object) [
            'fullname' => s($existing->fullname),
            'shortname' => s($existing->shortname),
            'id' => $existing->id,
        ]),
        'badgeclass' => !$existing->visible ? 'badge badge-secondary' : 'badge badge-warning',
        'badgetext' => !$existing->visible
            ? get_string('demo:badge_hidden', 'local_ai_course_assistant')
            : get_string('demo:badge_visible', 'local_ai_course_assistant'),
        'courseurl' => (new moodle_url('/course/view.php', ['id' => $existing->id]))->out(false),
        'opencourse' => get_string('demo:open_course', 'local_ai_course_assistant'),
        'step2heading' => get_string('demo:step2', 'local_ai_course_assistant'),
        'seedintro' => get_string('demo:seed_intro', 'local_ai_course_assistant'),
        'userslabel' => get_string('demo:users_label', 'local_ai_course_assistant'),
        'weekslabel' => get_string('demo:weeks_label', 'local_ai_course_assistant'),
        'clearlabel' => get_string('demo:clear_label', 'local_ai_course_assistant'),
        'seedbtn' => get_string('demo:seed_btn', 'local_ai_course_assistant'),
        'analyticsurl' => (new moodle_url(
            '/local/ai_course_assistant/analytics.php',
            ['courseid' => $existing->id]
        ))->out(false),
        'viewanalytics' => get_string('demo:view_analytics', 'local_ai_course_assistant'),
    ];
}

echo $output->header();
echo $output->heading(get_string('demo:heading', 'local_ai_course_assistant'));
echo $output->render_from_template('local_ai_course_assistant/demo_admin', $templatedata);
echo $output->footer();
