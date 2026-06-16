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
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
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
$expa     = optional_param('expa', 0, PARAM_INT); // Experiment comparison course A.
$expb     = optional_param('expb', 0, PARAM_INT); // Experiment comparison course B.

// ── Student mode toggle (session-scoped) ───────────────────────────────────
if ($action === 'togglestudentmode' && confirm_sesskey()) {
    if (!empty($_SESSION['sola_student_mode'])) {
        unset($_SESSION['sola_student_mode']);
    } else {
        $_SESSION['sola_student_mode'] = true;
    }
    redirect(new moodle_url('/local/ai_course_assistant/analytics.php',
        ['courseid' => $courseid, 'range' => $range]));
}

// ── Anonymization toggle (session-scoped) ──────────────────────────────────
if ($action === 'togglenames' && confirm_sesskey()) {
    if (!empty($_SESSION['sola_show_real_names'])) {
        unset($_SESSION['sola_show_real_names']);
    } else {
        $_SESSION['sola_show_real_names'] = true;
        // FERPA/GDPR: log the moment an admin reveals learner identities
        // so we have an audit trail of who viewed learner data and when.
        \local_ai_course_assistant\audit_logger::log(
            'admin_reveal_learner_identities',
            (int)$USER->id,
            (int)$courseid,
            ['range_days' => (int)$range]
        );
    }
    redirect(new moodle_url('/local/ai_course_assistant/analytics.php',
        ['courseid' => $courseid, 'range' => $range]));
}
$show_real_names = !empty($_SESSION['sola_show_real_names']);

// Per-course enable/disable + UT toggles moved to courses_admin.php in v4.2.
// Any legacy POST that lands here is redirected so old bookmarks still work.
if (in_array($action, ['toggle', 'toggleut', 'bulktoggle', 'bulktoggleut'], true)) {
    redirect(new moodle_url('/local/ai_course_assistant/courses_admin.php'));
}

$PAGE->set_url(new moodle_url('/local/ai_course_assistant/analytics.php',
    ['courseid' => $courseid, 'range' => $range]));
$PAGE->set_context($syscontext);
$PAGE->set_title('AI Course Assistant Analytics');
$PAGE->set_heading('AI Course Assistant Analytics');
$PAGE->set_pagelayout('admin');

$since = $range > 0 ? time() - ($range * 86400) : 0;

// ── Visible courses for the per-course drill-down dropdown ──────────────────
$all_courses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, c.shortname
       FROM {course} c
      WHERE c.id > 1 AND c.visible = 1
      ORDER BY c.fullname ASC"
);

// ── Header summary (small inline stat, not the old card grid) ───────────────
$enabled_courses = 0;
foreach ($all_courses as $c) {
    if (\local_ai_course_assistant\course_config_manager::is_enabled_for_course((int) $c->id)) {
        $enabled_courses++;
    }
}
$total_courses = count($all_courses);

// ── Per-course analytics (if a course is selected) ──────────────────────────
$course_data = null;
$course_name = '';
if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid]);
    if ($course) {
        $course_name = $course->fullname;
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
            "SELECT f.id, f.userid, f.rating, f.comment, f.browser, f.os, f.device,
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
            $realname = htmlspecialchars($fb->firstname . ' ' . $fb->lastname);
            $anonname = \local_ai_course_assistant\anonymizer::name((int) $fb->userid);
            $feedbackentries[] = [
                'name'        => $show_real_names ? $realname : $anonname,
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

        // Survey results.
        $survey_data = null;
        try {
            $survey_results = \local_ai_course_assistant\survey_manager::get_survey_results($courseid, $since);
            if ($survey_results['total_responses'] > 0) {
                $survey_questions = [];
                foreach ($survey_results['questions'] as $q) {
                    $sq = [
                        'text' => $q['text'],
                        'type' => $q['type'],
                        'response_count' => $q['response_count'],
                        'is_multiple_choice' => $q['type'] === 'multiple_choice',
                        'is_rating' => $q['type'] === 'rating',
                        'is_long_text' => $q['type'] === 'long_text',
                    ];
                    if ($q['type'] === 'multiple_choice' && !empty($q['option_counts'])) {
                        $opts = [];
                        foreach ($q['option_counts'] as $label => $cnt) {
                            $opts[] = ['label' => $label, 'count' => $cnt];
                        }
                        $sq['options'] = $opts;
                    }
                    if ($q['type'] === 'rating') {
                        $sq['average'] = $q['average'] ?? 0;
                        $dist = [];
                        if (!empty($q['distribution'])) {
                            foreach ($q['distribution'] as $val => $cnt) {
                                $dist[] = ['value' => $val, 'count' => $cnt];
                            }
                        }
                        $sq['distribution'] = $dist;
                    }
                    if ($q['type'] === 'long_text' && !empty($q['answers'])) {
                        $text_answers = [];
                        foreach (array_slice($q['answers'], 0, 20) as $a) {
                            $text_answers[] = ['text' => htmlspecialchars($a)];
                        }
                        $sq['answers'] = $text_answers;
                        $sq['has_answers'] = true;
                    }
                    $survey_questions[] = $sq;
                }
                $survey_data = [
                    'total_responses' => $survey_results['total_responses'],
                    'questions' => $survey_questions,
                ];
            }
        } catch (\Throwable $e) {
            // Survey tables may not exist yet on older installs.
        }

        // User testing results.
        $ut_data = null;
        try {
            $ut_results = \local_ai_course_assistant\usertesting_manager::get_results($courseid);
            if ($ut_results['total_respondents'] > 0) {
                $ut_tasks = [];
                foreach ($ut_results['tasks'] as $t) {
                    $ut = [
                        'instruction' => $t['instruction'],
                        'type' => $t['type'],
                        'response_count' => $t['response_count'],
                        'avg_messages' => $t['avg_messages'],
                        'avg_session_minutes' => $t['avg_session_minutes'],
                        'is_action_then_rate' => $t['type'] === 'action_then_rate',
                        'is_multiple_choice' => $t['type'] === 'multiple_choice',
                        'is_free_response' => $t['type'] === 'free_response',
                    ];
                    if ($t['type'] === 'action_then_rate') {
                        $ut['avg_rating'] = $t['avg_rating'] ?? 0;
                        $comments = [];
                        foreach (array_slice($t['comments'] ?? [], 0, 20) as $c) {
                            $comments[] = ['text' => htmlspecialchars($c)];
                        }
                        $ut['comments'] = $comments;
                        $ut['has_comments'] = !empty($comments);
                    }
                    if ($t['type'] === 'multiple_choice' && !empty($t['option_counts'])) {
                        $opts = [];
                        foreach ($t['option_counts'] as $label => $cnt) {
                            $opts[] = ['label' => $label, 'count' => $cnt];
                        }
                        $ut['options'] = $opts;
                    }
                    if ($t['type'] === 'free_response' && !empty($t['answers'])) {
                        $answers = [];
                        foreach (array_slice($t['answers'], 0, 20) as $a) {
                            $answers[] = ['text' => htmlspecialchars($a)];
                        }
                        $ut['answers'] = $answers;
                        $ut['has_answers'] = !empty($answers);
                    }
                    $ut_tasks[] = $ut;
                }
                $ut_data = [
                    'total_respondents' => $ut_results['total_respondents'],
                    'tasks' => $ut_tasks,
                ];
            }
        } catch (\Throwable $e) {
            // User testing tables may not exist yet.
        }

        $course_data = [
            'courseid'   => $courseid,
            'coursename' => $course_name,
            'overview'   => $overview,
            'has_data'   => $overview['total_messages'] > 0,
            'daily_usage_json' => json_encode($dailyusage),
            'hotspots'         => $hotspots,
            'has_hotspots'     => !empty($hotspots),
            'common_prompts'   => $commonprompts,
            'has_common_prompts' => !empty($commonprompts),
            'provider_comparison' => $providercomparison,
            'has_provider_comparison' => !empty($providercomparison),
            'students' => array_values(array_map(function($s) use ($show_real_names) {
                $realname = $s->firstname . ' ' . $s->lastname;
                $anonname = \local_ai_course_assistant\anonymizer::name((int) $s->userid);
                return [
                    'name'          => $show_real_names ? $realname : $anonname,
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
            'survey_data'     => $survey_data,
            'has_survey_data' => ($survey_data !== null),
            'usertesting_data' => $ut_data,
            'has_usertesting_data' => ($ut_data !== null),
            'has_any_feedback_data' => ($feedbacktotal > 0 || $survey_data !== null || $ut_data !== null),
            'token_analytics_url' => (new moodle_url('/local/ai_course_assistant/token_analytics.php',
                ['courseid' => $courseid, 'range' => $range]))->out(false),
        ];
    }
}

// ── Past Learning Radar queries (most recent 50, paired by conversation) ────
$radarpastraw = $DB->get_records_sql(
    "SELECT id, conversationid, role, message, prompt_tokens, completion_tokens,
            model_name, provider, interaction_type, timecreated
       FROM {local_ai_course_assistant_msgs}
      WHERE interaction_type IN ('meta', 'meta_scheduled')
      ORDER BY conversationid ASC, id ASC"
);
$radar_past = [];
$pendinguser = null;
foreach ($radarpastraw as $row) {
    if ($row->role === 'user') {
        $pendinguser = $row;
        continue;
    }
    if ($row->role === 'assistant' && $pendinguser !== null
            && (int) $pendinguser->conversationid === (int) $row->conversationid) {
        $radar_past[] = [
            'id'        => (int) $row->id,
            'query'     => mb_substr((string) $pendinguser->message, 0, 200),
            'response'  => mb_substr((string) $row->message, 0, 280),
            'provider'  => $row->provider ?: '—',
            'model'     => $row->model_name ?: '—',
            'scheduled' => $row->interaction_type === 'meta_scheduled',
            'date'      => userdate($row->timecreated, '%Y-%m-%d %H:%M'),
            'asked_at'  => (int) $pendinguser->timecreated,
        ];
        $pendinguser = null;
    }
}
usort($radar_past, function ($a, $b) {
    return $b['asked_at'] - $a['asked_at'];
});
$radar_past = array_slice($radar_past, 0, 50);

// ── Existing schedules for the panel below the chat ─────────────────────────
$radar_schedules = [];
try {
    foreach (\local_ai_course_assistant\radar_schedule_manager::all(false) as $s) {
        $statusclass = '';
        if ($s->last_status === 'success') {
            $statusclass = 'success';
        } else if ($s->last_status === 'error') {
            $statusclass = 'danger';
        }
        $radar_schedules[] = [
            'id'                => (int) $s->id,
            'name'              => $s->name,
            'frequency'         => $s->frequency,
            'enabled'           => (int) $s->enabled === 1,
            'recipient'         => $s->recipient_email ?: '',
            'has_slack'         => !empty($s->slack_webhook),
            'has_teams'         => !empty($s->teams_webhook),
            'last_run'          => $s->last_run !== null ? userdate((int) $s->last_run) : '',
            'last_status'       => $s->last_status ?: '',
            'last_status_class' => $statusclass,
        ];
    }
} catch (\Throwable $e) {
    // Fresh installs may not have the table yet on the very first request.
    $radar_schedules = [];
}

// ── Build template data ─────────────────────────────────────────────────────
$templatedata = [
    // Header summary line (small inline counts, not card grid).
    'enabled_courses' => $enabled_courses,
    'total_courses'   => $total_courses,

    // Per-course analytics (null if none selected).
    'has_course_selected' => ($course_data !== null),
    'course'              => $course_data,

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

    'sesskey'        => sesskey(),
    'form_action'    => (new moodle_url('/local/ai_course_assistant/analytics.php'))->out(false),
    'courseid_param' => $courseid,

    // Links.
    'token_analytics_url' => (new moodle_url('/local/ai_course_assistant/token_analytics.php',
        ['range' => $range]))->out(false),
    'settings_url' => (new moodle_url('/admin/category.php',
        ['category' => 'local_ai_course_assistant']))->out(false),
    'analytics_base_url' => (new moodle_url('/local/ai_course_assistant/analytics.php',
        ['range' => $range]))->out(false),
    'courses_admin_url' => (new moodle_url('/local/ai_course_assistant/courses_admin.php'))->out(false),
    'radar_schedule_url' => (new moodle_url('/local/ai_course_assistant/radar_schedule.php'))->out(false),
    'radar_export_url' => (new moodle_url('/local/ai_course_assistant/radar_export.php'))->out(false),
    'radar_cite_url' => (new moodle_url('/local/ai_course_assistant/radar_cite.php'))->out(false),
    'redash_pull_url' => (new moodle_url('/local/ai_course_assistant/redash_export.php', [
        'apikey' => get_config('local_ai_course_assistant', 'redash_api_key') ?: '',
    ]))->out(false),
    'has_redash_key' => !empty(get_config('local_ai_course_assistant', 'redash_api_key')),

    // CSV export (uses the Redash endpoint with the configured API key).
    'export_csv_url' => (new moodle_url('/local/ai_course_assistant/redash_export.php', [
        'apikey' => get_config('local_ai_course_assistant', 'redash_api_key') ?: '',
        'courseid' => $courseid,
        'since' => $since,
    ]))->out(false),

    // Past Learning Radar queries (most recent 50).
    'radar_past' => $radar_past,
    'has_radar_past' => !empty($radar_past),

    // Existing scheduled queries.
    'radar_schedules' => $radar_schedules,
    'has_radar_schedules' => !empty($radar_schedules),
    'radar_schedule_count' => count($radar_schedules),

    // Anonymization toggle.
    'show_real_names' => $show_real_names,

    // Student mode.
    'student_mode' => !empty($_SESSION['sola_student_mode']),

    // Learning Radar.
    'meta_ai_sse_url' => (new moodle_url('/local/ai_course_assistant/meta_ai_sse.php'))->out(false),
    'meta_ai_providers' => (function () {
        $providers = [];
        $configprovider = get_config('local_ai_course_assistant', 'provider') ?: 'openai';
        $configmodel = get_config('local_ai_course_assistant', 'model') ?: '';
        $providers[] = [
            'id' => $configprovider,
            'label' => ucfirst($configprovider) . ' (primary)',
            'models_json' => json_encode($configmodel ? [$configmodel] : []),
        ];
        $seen = [$configprovider => true];
        $compraw = get_config('local_ai_course_assistant', 'comparison_providers') ?: '';
        foreach (explode("\n", $compraw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 2) {
                continue;
            }
            $pid = strtolower($parts[0]);
            if (!empty($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $models = [];
            if (!empty($parts[2])) {
                $models = array_filter(array_map('trim', explode(',', $parts[2])));
            }
            $providers[] = [
                'id' => $pid,
                'label' => ucfirst($pid),
                'models_json' => json_encode(array_values($models)),
            ];
        }
        return $providers;
    })(),
    'has_meta_ai_providers' => true,

    // Learning Radar metric chips (v3.9.9+). Six at-a-glance numbers plus a
    // templated follow-up query each chip submits to Learning Radar when
    // clicked. Computed once per page load; sub-second on a healthy DB.
    'learning_radar_chips' => (function () {
        global $DB;
        $chips = [];
        $now = time();
        $since30 = $now - 30 * 86400;
        $since7 = $now - 7 * 86400;

        // Tokens this month.
        $toktotal = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(COALESCE(prompt_tokens, 0) + COALESCE(completion_tokens, 0)), 0)
               FROM {local_ai_course_assistant_msgs}
              WHERE role = 'assistant' AND timecreated > ?",
            [$since30]) ?: 0;
        $chips[] = [
            'value' => number_format($toktotal),
            'label' => 'Tokens (30d)',
            'query' => 'Break down token spend by provider and course for the last 30 days.',
        ];

        // Top-cost course (30d).
        $topcourse = $DB->get_record_sql(
            "SELECT c.id, c.shortname, c.fullname,
                    SUM(COALESCE(m.prompt_tokens, 0) + COALESCE(m.completion_tokens, 0)) AS tokens
               FROM {local_ai_course_assistant_msgs} m
               JOIN {course} c ON c.id = m.courseid
              WHERE m.role = 'assistant' AND m.timecreated > ?
              GROUP BY c.id, c.shortname, c.fullname
              ORDER BY tokens DESC LIMIT 1",
            [$since30]);
        $chips[] = [
            'value' => $topcourse ? format_string($topcourse->shortname) : '—',
            'label' => 'Top-cost course (30d)',
            'query' => $topcourse
                ? 'Why is course ' . $topcourse->shortname . ' the top token consumer this month?'
                : 'Which courses are using the most tokens recently?',
        ];

        // Active students this week.
        $activeusers = (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT userid)
               FROM {local_ai_course_assistant_msgs}
              WHERE role = 'user' AND timecreated > ?",
            [$since7]) ?: 0;
        $chips[] = [
            'value' => number_format($activeusers),
            'label' => 'Active students (7d)',
            'query' => 'Profile the active students this week: which topics are they asking about?',
        ];

        // Voice minutes (30d). Approximate via interaction_type=voice row count
        // times average session length (safer than parsing comments).
        $voicemsgs = (int) $DB->get_field_sql(
            "SELECT COUNT(id) FROM {local_ai_course_assistant_msgs}
              WHERE interaction_type = 'voice' AND timecreated > ?",
            [$since30]) ?: 0;
        $chips[] = [
            'value' => number_format((int) ceil($voicemsgs * 0.5)),
            'label' => 'Voice minutes (30d)',
            'query' => 'Which courses and topics are students using voice mode for?',
        ];

        // Lowest-rated responses (7d).
        $neg = (int) $DB->get_field_sql(
            "SELECT COUNT(id) FROM {local_ai_course_assistant_msg_ratings}
              WHERE rating = -1 AND timecreated > ?",
            [$since7]) ?: 0;
        $chips[] = [
            'value' => number_format($neg),
            'label' => 'Negative ratings (7d)',
            'query' => 'Summarise the lowest-rated responses from the last week. What patterns explain them?',
        ];

        // Integrity flags open.
        $flags = 0;
        try {
            $flags = (int) $DB->count_records_select(
                'local_ai_course_assistant_audit',
                'event = ?', ['integrity_flagged']);
        } catch (\Throwable $e) {
            $flags = 0;
        }
        $chips[] = [
            'value' => number_format($flags),
            'label' => 'Integrity flags (open)',
            'query' => 'What types of academic integrity concerns are flagged and how should I respond?',
        ];

        return $chips;
    })(),

    // Suggested starter questions: a fixed set of useful queries shown
    // before any data-derived metric chips. Helps admins discover what
    // Learning Radar can answer without staring at a blank input.
    'learning_radar_starters' => [
        ['label' => 'Top topics students struggle with',
         'query' => 'Which topics have the highest off-topic rate or generate the most clarification requests across all courses?'],
        ['label' => 'Best provider per dollar',
         'query' => 'Compare cost-per-helpful-answer across providers using rating signal as the helpful proxy. Which provider gives the best satisfaction per dollar this month?'],
        ['label' => 'Where students bounce',
         'query' => 'Identify courses where conversation drop-off is highest after the first 2 turns. What pattern explains the drop?'],
        ['label' => 'Most-frustrated students',
         'query' => 'Find anonymized students whose recent feedback shifted from positive to negative this week. What triggered the change?'],
        ['label' => 'Courses ready for instructor review',
         'query' => 'Which courses have accumulated the most negative ratings or integrity flags this period and would benefit from instructional designer review?'],
        ['label' => 'Trending questions',
         'query' => 'List the 10 most frequently asked questions this week, and how SOLA answered them on average.'],
        ['label' => 'Voice mode breakdown',
         'query' => 'Profile voice mode usage: which courses, which topics, and what is the average session length?'],
        ['label' => 'Quiet courses',
         'query' => 'Which courses have SOLA enabled but very low engagement? What might be missing in those courses to drive more use?'],
    ],

    // LLM provider list reused by the schedule modal and compare mode.
    'meta_ai_providers_json' => json_encode((function () {
        $providers = [];
        $configprovider = get_config('local_ai_course_assistant', 'provider') ?: 'openai';
        $configmodel = get_config('local_ai_course_assistant', 'model') ?: '';
        $providers[] = ['id' => $configprovider, 'label' => ucfirst($configprovider) . ' (primary)',
            'models' => $configmodel ? [$configmodel] : []];
        $seen = [$configprovider => true];
        $compraw = get_config('local_ai_course_assistant', 'comparison_providers') ?: '';
        foreach (explode("\n", $compraw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 2) {
                continue;
            }
            $pid = strtolower($parts[0]);
            if (!empty($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $models = !empty($parts[2]) ? array_values(array_filter(array_map('trim', explode(',', $parts[2])))) : [];
            $providers[] = ['id' => $pid, 'label' => ucfirst($pid), 'models' => $models];
        }
        return $providers;
    })()),
];

// Load Chart.js and analytics dashboard AMD module.
$PAGE->requires->js(new moodle_url('/local/ai_course_assistant/cdn/chartjs/chart.umd.min.js'));
$PAGE->requires->js_call_amd('local_ai_course_assistant/analytics_dashboard', 'init', [[
    'courseid' => $courseid,
    'since' => $since,
]]);

// Build course list for tabs filter dropdown.
$tabscourses = [];
foreach ($all_courses as $c) {
    $tabscourses[] = ['id' => $c->id, 'shortname' => $c->shortname];
}

// ── A/B experiment comparison (server-rendered, GET-driven) ────────────────
$templatedata['experiment_form_action'] = (new moodle_url('/local/ai_course_assistant/analytics.php'))->out(false);
$templatedata['experiment_range'] = $range;
$templatedata['experiment_courses_a'] = [];
$templatedata['experiment_courses_b'] = [];
foreach ($all_courses as $c) {
    $templatedata['experiment_courses_a'][] = ['id' => $c->id, 'shortname' => $c->shortname, 'sel' => ((int) $c->id === $expa)];
    $templatedata['experiment_courses_b'][] = ['id' => $c->id, 'shortname' => $c->shortname, 'sel' => ((int) $c->id === $expb)];
}
if ($expa > 0 && $expb > 0 && $expa !== $expb) {
    $ma = analytics::get_experiment_metrics($expa, $since);
    $mb = analytics::get_experiment_metrics($expb, $since);
    $metriclabels = [
        'enrolled' => get_string('analytics:exp_enrolled', 'local_ai_course_assistant'),
        'active_users' => \local_ai_course_assistant\branding::apply(get_string('analytics:exp_active_users', 'local_ai_course_assistant')),
        'usage_rate_pct' => get_string('analytics:exp_usage_rate', 'local_ai_course_assistant'),
        'sessions' => get_string('analytics:exp_sessions', 'local_ai_course_assistant'),
        'messages' => get_string('analytics:exp_messages', 'local_ai_course_assistant'),
        'avg_messages_per_session' => get_string('analytics:exp_avg_msgs_session', 'local_ai_course_assistant'),
        'avg_session_minutes' => get_string('analytics:exp_avg_session_minutes', 'local_ai_course_assistant'),
        'return_rate_pct' => get_string('analytics:exp_return_rate', 'local_ai_course_assistant'),
        'tts_plays' => get_string('analytics:exp_tts_plays', 'local_ai_course_assistant'),
        'tts_per_active_user' => get_string('analytics:exp_tts_per_active', 'local_ai_course_assistant'),
    ];
    $exprows = [];
    foreach ($metriclabels as $key => $label) {
        $a = $ma[$key];
        $b = $mb[$key];
        $delta = '–';
        if (is_numeric($a) && (float) $a != 0.0) {
            $pct = ((float) $b - (float) $a) / (float) $a * 100;
            $delta = ($pct >= 0 ? '+' : '') . round($pct, 1) . '%';
        }
        $exprows[] = ['label' => $label, 'a' => $a, 'b' => $b, 'delta' => $delta];
    }
    $expnamea = '';
    $expnameb = '';
    foreach ($all_courses as $c) {
        if ((int) $c->id === $expa) {
            $expnamea = $c->shortname;
        }
        if ((int) $c->id === $expb) {
            $expnameb = $c->shortname;
        }
    }
    $templatedata['experiment'] = [
        'course_a_name' => $expnamea,
        'course_b_name' => $expnameb,
        'rows' => $exprows,
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/analytics_tabs', ['courses' => $tabscourses]);
echo $OUTPUT->render_from_template('local_ai_course_assistant/analytics_dashboard', $templatedata);
echo $OUTPUT->footer();
