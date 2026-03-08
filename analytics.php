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
 * SOLA Analytics dashboard — site-admin only.
 *
 * Shows cross-course summary + per-course analytics with SOLA enable/disable toggles.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\analytics;

require_login();

// Site-admin only.
$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);

$courseid = optional_param('courseid', 0, PARAM_INT);
$range    = optional_param('range', 30, PARAM_INT); // 7, 30, 0 = all.
$action   = optional_param('action', '', PARAM_ALPHA);

// ── Handle SOLA enable/disable toggle POST ──────────────────────────────────
if ($action === 'toggle' && confirm_sesskey()) {
    $togglecourseid = required_param('togglecourseid', PARAM_INT);
    $enabled = required_param('enabled', PARAM_INT);
    set_config('sola_enabled_course_' . $togglecourseid, $enabled ? '1' : '0', 'local_ai_course_assistant');
    redirect(new moodle_url('/local/ai_course_assistant/analytics.php',
        ['courseid' => $courseid, 'range' => $range]));
}

$PAGE->set_url(new moodle_url('/local/ai_course_assistant/analytics.php',
    ['courseid' => $courseid, 'range' => $range]));
$PAGE->set_context($syscontext);
$PAGE->set_title('SOLA Analytics');
$PAGE->set_heading('SOLA Analytics');
$PAGE->set_pagelayout('admin');

$since = $range > 0 ? time() - ($range * 86400) : 0;

// ── Build course list with stats ────────────────────────────────────────────
// Get all courses that have at least one SOLA message.
$coursesWithData = $DB->get_records_sql(
    "SELECT DISTINCT m.courseid, c.fullname, c.shortname
       FROM {local_ai_course_assistant_msgs} m
       JOIN {course} c ON c.id = m.courseid
      WHERE c.id > 1
      ORDER BY c.fullname ASC"
);

// Also get all visible courses (for the enable/disable toggle even if no data yet).
$allCourses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, c.shortname
       FROM {course} c
      WHERE c.id > 1 AND c.visible = 1
      ORDER BY c.fullname ASC"
);

// Merge: courses with data + all visible courses.
$courseList = [];
foreach ($allCourses as $c) {
    $hasData = isset($coursesWithData[$c->id]);
    $enabledVal = get_config('local_ai_course_assistant', 'sola_enabled_course_' . $c->id);
    $isEnabled = ($enabledVal !== '0'); // Default: enabled.
    $courseList[] = [
        'id'        => (int) $c->id,
        'fullname'  => $c->fullname,
        'shortname' => $c->shortname,
        'has_data'  => $hasData,
        'enabled'   => $isEnabled,
        'selected'  => ((int) $c->id === $courseid),
        'sesskey'   => sesskey(),
        'range'     => $range,
        'courseid_param' => $courseid,
        'form_action' => (new \moodle_url('/local/ai_course_assistant/analytics.php'))->out(false),
    ];
}

// ── Cross-course summary stats ──────────────────────────────────────────────
$totalMsgsAll = $DB->count_records_sql(
    "SELECT COUNT(m.id) FROM {local_ai_course_assistant_msgs} m
       JOIN {course} c ON c.id = m.courseid WHERE c.id > 1"
);
$activeStudentsAll = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT m.userid) FROM {local_ai_course_assistant_msgs} m
       JOIN {course} c ON c.id = m.courseid WHERE c.id > 1 AND m.role = 'user'"
);
$activeCourses = count($coursesWithData);
$enabledCourses = 0;
foreach ($courseList as $cl) {
    if ($cl['enabled']) {
        $enabledCourses++;
    }
}

// ── Per-course analytics (if a course is selected) ──────────────────────────
$courseData = null;
$courseName = '';
if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid]);
    if ($course) {
        $courseName = $course->fullname;
        $overview = analytics::get_overview($courseid, $since);
        $dailyusage = analytics::get_daily_usage($courseid, $range > 0 ? $range : 90);
        $hotspots = analytics::get_hotspots($courseid, $since);
        $commonprompts = analytics::get_common_prompts($courseid, $since);
        $studentusage = analytics::get_student_usage($courseid, $since);
        $providercomparison = analytics::get_provider_comparison($courseid, $since);

        // Feedback.
        $feedbackparams = ['courseid' => $courseid];
        $feedbacktimewhere = '';
        if ($since > 0) {
            $feedbacktimewhere = ' AND f.timecreated >= :since';
            $feedbackparams['since'] = $since;
        }
        $feedbacksummary = $DB->get_record_sql(
            "SELECT COUNT(f.id) AS total_count, AVG(f.rating) AS avg_rating
               FROM {local_ai_course_assistant_feedback} f
              WHERE f.courseid = :courseid{$feedbacktimewhere}",
            $feedbackparams
        );
        $ratingdist = $DB->get_records_sql(
            "SELECT f.rating AS id, f.rating, COUNT(f.id) AS cnt
               FROM {local_ai_course_assistant_feedback} f
              WHERE f.courseid = :courseid{$feedbacktimewhere}
              GROUP BY f.rating ORDER BY f.rating DESC",
            $feedbackparams
        );
        $ratingrows = [];
        for ($r = 5; $r >= 1; $r--) {
            $cnt = 0;
            foreach ($ratingdist as $row) {
                if ((int) $row->rating === $r) {
                    $cnt = (int) $row->cnt;
                    break;
                }
            }
            $ratingrows[] = ['stars' => $r, 'count' => $cnt];
        }
        $recentfeedback = $DB->get_records_sql(
            "SELECT f.id, f.rating, f.comment, f.browser, f.os, f.device,
                    f.screen_size, f.timecreated, u.firstname, u.lastname
               FROM {local_ai_course_assistant_feedback} f
               JOIN {user} u ON u.id = f.userid
              WHERE f.courseid = :courseid{$feedbacktimewhere}
              ORDER BY f.timecreated DESC",
            $feedbackparams, 0, 50
        );
        $feedbackentries = [];
        foreach ($recentfeedback as $fb) {
            $stars = '';
            for ($s = 0; $s < 5; $s++) {
                $stars .= $s < (int) $fb->rating ? '&#9733;' : '&#9734;';
            }
            $feedbackentries[] = [
                'name'        => htmlspecialchars($fb->firstname . ' ' . $fb->lastname),
                'stars'       => $stars,
                'rating'      => (int) $fb->rating,
                'comment'     => htmlspecialchars($fb->comment ?: ''),
                'has_comment' => !empty($fb->comment),
                'browser'     => htmlspecialchars($fb->browser ?: ''),
                'os'          => htmlspecialchars($fb->os ?: ''),
                'device'      => htmlspecialchars($fb->device ?: ''),
                'screen'      => htmlspecialchars($fb->screen_size ?: ''),
                'date'        => userdate($fb->timecreated),
            ];
        }

        $feedbacktotal = $feedbacksummary ? (int) $feedbacksummary->total_count : 0;
        $feedbackavg = $feedbacksummary && $feedbacksummary->avg_rating
            ? round((float) $feedbacksummary->avg_rating, 1) : 0;

        $courseData = [
            'courseid'   => $courseid,
            'coursename' => $courseName,
            'overview'   => $overview,
            'has_data'   => $overview['total_messages'] > 0,
            'daily_usage_json' => json_encode($dailyusage),
            'hotspots'         => $hotspots,
            'has_hotspots'     => !empty($hotspots),
            'common_prompts'   => $commonprompts,
            'has_common_prompts' => !empty($commonprompts),
            'provider_comparison' => $providercomparison,
            'has_provider_comparison' => !empty($providercomparison),
            'students' => array_values(array_map(function($s) {
                return [
                    'name'          => $s->firstname . ' ' . $s->lastname,
                    'message_count' => $s->message_count,
                    'last_active'   => userdate($s->last_active),
                ];
            }, $studentusage)),
            'has_students'    => !empty($studentusage),
            'feedback_total'  => $feedbacktotal,
            'feedback_avg'    => $feedbackavg,
            'feedback_ratings' => $ratingrows,
            'feedback_entries' => $feedbackentries,
            'has_feedback'    => $feedbacktotal > 0,
            'token_analytics_url' => (new moodle_url('/local/ai_course_assistant/token_analytics.php',
                ['courseid' => $courseid, 'range' => $range]))->out(false),
        ];
    }
}

// ── Build template data ─────────────────────────────────────────────────────
$templatedata = [
    // Cross-course summary.
    'total_messages_all'  => $totalMsgsAll,
    'active_students_all' => $activeStudentsAll,
    'active_courses'      => $activeCourses,
    'enabled_courses'     => $enabledCourses,
    'total_courses'       => count($courseList),

    // Course list for selector + toggle table.
    'courses'     => $courseList,
    'has_courses' => !empty($courseList),

    // Per-course analytics (null if none selected).
    'has_course_selected' => ($courseData !== null),
    'course'              => $courseData,

    // Time range.
    'range'              => $range,
    'range_7_selected'   => $range == 7,
    'range_30_selected'  => $range == 30,
    'range_all_selected' => $range == 0,
    'url_7'  => (new moodle_url('/local/ai_course_assistant/analytics.php',
        ['courseid' => $courseid, 'range' => 7]))->out(false),
    'url_30' => (new moodle_url('/local/ai_course_assistant/analytics.php',
        ['courseid' => $courseid, 'range' => 30]))->out(false),
    'url_all' => (new moodle_url('/local/ai_course_assistant/analytics.php',
        ['courseid' => $courseid, 'range' => 0]))->out(false),

    // Toggle form helpers.
    'sesskey'     => sesskey(),
    'form_action' => (new moodle_url('/local/ai_course_assistant/analytics.php'))->out(false),

    // Links.
    'token_analytics_url' => (new moodle_url('/local/ai_course_assistant/token_analytics.php',
        ['range' => $range]))->out(false),
    'settings_url' => (new moodle_url('/admin/settings.php',
        ['section' => 'local_ai_course_assistant']))->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/analytics_dashboard', $templatedata);
echo $OUTPUT->footer();
