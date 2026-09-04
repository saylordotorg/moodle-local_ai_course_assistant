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

// Per-session UI toggles live in a MODE_SESSION cache rather than the raw
// $_SESSION superglobal.
$uistate = \cache::make('local_ai_course_assistant', 'uistate');

// ── Student mode toggle (session-scoped) ───────────────────────────────────
if ($action === 'togglestudentmode' && confirm_sesskey()) {
    if ($uistate->get('student_mode')) {
        $uistate->delete('student_mode');
    } else {
        $uistate->set('student_mode', 1);
    }
    redirect(new moodle_url(
        '/local/ai_course_assistant/analytics.php',
        ['courseid' => $courseid, 'range' => $range]
    ));
}

// ── Anonymization toggle (session-scoped) ──────────────────────────────────
if ($action === 'togglenames' && confirm_sesskey()) {
    if ($uistate->get('show_real_names')) {
        $uistate->delete('show_real_names');
    } else {
        $uistate->set('show_real_names', 1);
        // FERPA/GDPR: log the moment an admin reveals learner identities
        // so we have an audit trail of who viewed learner data and when.
        \local_ai_course_assistant\audit_logger::log(
            'admin_reveal_learner_identities',
            (int)$USER->id,
            (int)$courseid,
            ['range_days' => (int)$range]
        );
    }
    redirect(new moodle_url(
        '/local/ai_course_assistant/analytics.php',
        ['courseid' => $courseid, 'range' => $range]
    ));
}
$show_real_names = (bool) $uistate->get('show_real_names');

// Per-course enable/disable + UT toggles moved to courses_admin.php in v4.2.
// Any legacy POST that lands here is redirected so old bookmarks still work.
if (in_array($action, ['toggle', 'toggleut', 'bulktoggle', 'bulktoggleut'], true)) {
    redirect(new moodle_url('/local/ai_course_assistant/courses_admin.php'));
}

$PAGE->set_url(new moodle_url(
    '/local/ai_course_assistant/analytics.php',
    ['courseid' => $courseid, 'range' => $range]
));
$PAGE->set_context($syscontext);
$analyticstitle = get_string('analytics:title', 'local_ai_course_assistant');
$PAGE->set_title($analyticstitle);
$PAGE->set_heading($analyticstitle);
$PAGE->set_pagelayout('admin');

$since = $range > 0 ? time() - ($range * 86400) : 0;

// ── Visible courses for the per-course drill-down dropdown ──────────────────
// v7.0.0: hidden courses are included. The filter used to be
// `c.visible = 1`, which meant hiding a course silently removed its AI usage
// and spend from this dashboard while the course carried on costing money —
// a reporting gap for anyone who hides a course between terms, and the reason
// the Testing Environment page could seed data that never appeared here (it
// creates its course hidden on purpose). This page already requires
// :viewanalytics, and an administrator sees hidden courses everywhere else in
// Moodle, so there is nothing to protect by omitting them. The picker labels
// them so nobody mistakes a hidden course for a live one.
// A recordset, not get_records_sql: the directory review asked us not to load
// every course into memory for the admin picker, and this page then iterates
// the list four times. Stream it once, keep only the three small derived
// arrays the page actually renders, and let the recordset close.
$rs = $DB->get_recordset_sql(
    "SELECT c.id, c.fullname, c.shortname, c.visible
       FROM {course} c
      WHERE c.id > 1
      ORDER BY c.fullname ASC"
);
$enabled_courses = 0;
$total_courses = 0;
$tabscourses = [];
$experimentcourses = [];
$expnamea = '';
$expnameb = '';
foreach ($rs as $c) {
    $total_courses++;
    if (\local_ai_course_assistant\course_config_manager::is_enabled_for_course((int) $c->id)) {
        $enabled_courses++;
    }
    // Hidden courses are listed but labelled, so a hidden course is never
    // mistaken for a live one when reading its numbers.
    $label = $c->shortname;
    if (empty($c->visible)) {
        $label .= ' ' . get_string('analytics:course_hidden_suffix', 'local_ai_course_assistant');
    }
    $tabscourses[] = ['id' => $c->id, 'shortname' => $label];
    $experimentcourses[] = ['id' => (int) $c->id, 'shortname' => $c->shortname];
    if ((int) $c->id === $expa) {
        $expnamea = $c->shortname;
    }
    if ((int) $c->id === $expb) {
        $expnameb = $c->shortname;
    }
}
$rs->close();

// Header summary counts are built in the recordset pass above.

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
            $feedbackparams,
            0,
            50
        );
        $feedbackentries = [];
        foreach ($recentfeedback as $fb) {
            $stars = '';
            for ($s = 0; $s < 5; $s++) {
                $stars .= $s < (int) $fb->rating ? '&#9733;' : '&#9734;';
            }
            // No htmlspecialchars here or below: these render through {{ }} in the
            // Mustache template, which escapes (escape => 's'). Escaping twice made
            // O'Brien display as O&#39;Brien in the feedback table.
            $realname = $fb->firstname . ' ' . $fb->lastname;
            $anonname = \local_ai_course_assistant\anonymizer::name((int) $fb->userid);
            $feedbackentries[] = [
                'name'        => $show_real_names ? $realname : $anonname,
                'stars'       => $stars,
                'rating'      => (int) $fb->rating,
                'comment'     => $fb->comment ?: '',
                'has_comment' => !empty($fb->comment),
                'browser'     => $fb->browser ?: '',
                'os'          => $fb->os ?: '',
                'device'      => $fb->device ?: '',
                'screen'      => $fb->screen_size ?: '',
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
                            $text_answers[] = ['text' => $a];
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
                            $comments[] = ['text' => $c];
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
                            $answers[] = ['text' => $a];
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
            'students' => array_values(array_map(function ($s) use ($show_real_names) {
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
            'token_analytics_url' => (new moodle_url(
                '/local/ai_course_assistant/token_analytics.php',
                ['courseid' => $courseid, 'range' => $range]
            ))->out(false),
        ];
    }
}

// ── Past Learning Radar queries (most recent 50, paired by conversation) ────
// Bounded two-step fetch. This used to pull EVERY radar row ever written --
// full multi-KB message bodies for both query and answer, no LIMIT, no window --
// on every load of this page, then truncate to 200/280 chars and slice to 50 in
// PHP. Radar rows bypass the 100-row conversation cap and reuse one long-lived
// conversation per admin, so the scan grew without bound (56k-row msgs table in
// production). 200 newest ids = 100 pairs, comfortably past the 50 rendered.
// The pairing walk needs conversationid ASC, id ASC ordering, so the ids are
// selected newest-first and the bodies re-fetched in pairing order -- a naive
// ORDER BY id DESC LIMIT interleaves conversations and breaks the walk.
$radarids = $DB->get_fieldset_sql(
    "SELECT id FROM {local_ai_course_assistant_msgs}
      WHERE interaction_type IN ('meta', 'meta_scheduled')
      ORDER BY id DESC", [], 0, 200);
$radarpastraw = [];
if (!empty($radarids)) {
    [$radarinsql, $radarinparams] = $DB->get_in_or_equal($radarids, SQL_PARAMS_NAMED, 'rid');
    $radarpastraw = $DB->get_records_sql(
        "SELECT id, conversationid, role, message, prompt_tokens, completion_tokens,
                model_name, provider, interaction_type, timecreated
           FROM {local_ai_course_assistant_msgs}
          WHERE id {$radarinsql}
          ORDER BY conversationid ASC, id ASC", $radarinparams);
}
$radar_past = [];
$pendinguser = null;
foreach ($radarpastraw as $row) {
    if ($row->role === 'user') {
        $pendinguser = $row;
        continue;
    }
    if (
        $row->role === 'assistant' && $pendinguser !== null
            && (int) $pendinguser->conversationid === (int) $row->conversationid
    ) {
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
    'url_7'  => (new moodle_url(
        '/local/ai_course_assistant/analytics.php',
        ['courseid' => $courseid, 'range' => 7]
    ))->out(false),
    'url_30' => (new moodle_url(
        '/local/ai_course_assistant/analytics.php',
        ['courseid' => $courseid, 'range' => 30]
    ))->out(false),
    'url_all' => (new moodle_url(
        '/local/ai_course_assistant/analytics.php',
        ['courseid' => $courseid, 'range' => 0]
    ))->out(false),

    'sesskey'        => sesskey(),
    'form_action'    => (new moodle_url('/local/ai_course_assistant/analytics.php'))->out(false),
    'courseid_param' => $courseid,

    // Links.
    'token_analytics_url' => (new moodle_url(
        '/local/ai_course_assistant/token_analytics.php',
        ['range' => $range]
    ))->out(false),
    'settings_url' => (new moodle_url(
        '/admin/category.php',
        ['category' => 'local_ai_course_assistant']
    ))->out(false),
    'analytics_base_url' => (new moodle_url(
        '/local/ai_course_assistant/analytics.php',
        ['range' => $range]
    ))->out(false),
    'courses_admin_url' => (new moodle_url('/local/ai_course_assistant/courses_admin.php'))->out(false),
    'radar_schedule_url' => (new moodle_url('/local/ai_course_assistant/radar_schedule.php'))->out(false),
    'radar_export_url' => (new moodle_url('/local/ai_course_assistant/radar_export.php'))->out(false),
    // v7.0.5: no key in this URL. It is the address an admin pastes into Redash,
    // and pre-filling the credential is how it ended up stored in plaintext
    // inside a third-party system. Redash should send it as an
    // Authorization: Bearer header, which is what the endpoint documents.
    'redash_pull_url' => (new moodle_url('/local/ai_course_assistant/redash_export.php'))->out(false),
    'has_redash_key' => !empty(get_config('local_ai_course_assistant', 'redash_api_key')),

    // CSV export (uses the Redash endpoint with the configured API key).
    // A browser following a link cannot set a header, so this carries a
    // short-lived HMAC token derived from the key rather than the key itself.
    'export_csv_url' => (new moodle_url('/local/ai_course_assistant/redash_export.php', [
        't' => \local_ai_course_assistant\security::redash_download_token((int) $USER->id),
        'u' => (int) $USER->id,
        'courseid' => $courseid,
        'since' => $since,
        // v7.2.10: ask for CSV. Without this the button downloaded JSON, which
        // the browser rendered inline because no Content-Disposition was set.
        'format' => 'csv',
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
    'student_mode' => (bool) $uistate->get('student_mode'),

    // Learning Radar.
    'meta_ai_sse_url' => (new moodle_url('/local/ai_course_assistant/meta_ai_sse.php'))->out(false),
    'meta_ai_providers' => (function () {
        $providers = [];
        $configprovider = get_config('local_ai_course_assistant', 'provider') ?: 'openai';
        $configmodel = get_config('local_ai_course_assistant', 'model') ?: '';
        $providers[] = [
            'id' => $configprovider,
            'label' => get_string('analytics:provider_primary', 'local_ai_course_assistant', ucfirst($configprovider)),
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

        // Tokens this month. Site-wide, so it includes the background RAG spend
        // ledger (embedding + rerank) as well as chat. This used to filter
        // role='assistant' inline and therefore undercounted by the whole of RAG.
        $toktotal = \local_ai_course_assistant\analytics::get_total_tokens(0, $since30);
        $chips[] = [
            'value' => number_format($toktotal),
            'label' => get_string('analytics:chip_tokens_label', 'local_ai_course_assistant'),
            'query' => get_string('analytics:chip_tokens_query', 'local_ai_course_assistant'),
        ];

        // Top-cost course (30d). Deliberately still role='assistant': this chip
        // attributes spend to a course, and the embedding/rerank ledger is written
        // against SITEID rather than the course whose content was indexed, so
        // including it would crown the site course as top consumer. Consequence
        // to expect, not a bug: "Tokens (30d)" above is larger than the sum of
        // per-course token totals, by the amount of background RAG spend.
        $topcourse = $DB->get_record_sql(
            "SELECT c.id, c.shortname, c.fullname,
                    SUM(COALESCE(m.prompt_tokens, 0) + COALESCE(m.completion_tokens, 0)) AS tokens
               FROM {local_ai_course_assistant_msgs} m
               JOIN {course} c ON c.id = m.courseid
              WHERE m.role = 'assistant' AND m.timecreated > ?
              GROUP BY c.id, c.shortname, c.fullname
              ORDER BY tokens DESC LIMIT 1",
            [$since30]
        );
        $chips[] = [
            'value' => $topcourse ? format_string($topcourse->shortname) : '—',
            'label' => get_string('analytics:chip_topcourse_label', 'local_ai_course_assistant'),
            'query' => $topcourse
                ? get_string(
                    'analytics:chip_topcourse_query',
                    'local_ai_course_assistant',
                    $topcourse->shortname
                )
                : get_string('analytics:chip_topcourse_query_empty', 'local_ai_course_assistant'),
        ];

        // Active students this week.
        $activeusers = (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT userid)
               FROM {local_ai_course_assistant_msgs}
              WHERE role = 'user' AND timecreated > ?",
            [$since7]
        ) ?: 0;
        $chips[] = [
            'value' => number_format($activeusers),
            'label' => get_string('analytics:chip_activestudents_label', 'local_ai_course_assistant'),
            'query' => get_string('analytics:chip_activestudents_query', 'local_ai_course_assistant'),
        ];

        // Voice minutes (30d). Approximate via interaction_type=voice row count
        // times average session length (safer than parsing comments).
        $voicemsgs = (int) $DB->get_field_sql(
            "SELECT COUNT(id) FROM {local_ai_course_assistant_msgs}
              WHERE interaction_type = 'voice' AND timecreated > ?",
            [$since30]
        ) ?: 0;
        $chips[] = [
            'value' => number_format((int) ceil($voicemsgs * 0.5)),
            'label' => get_string('analytics:chip_voiceminutes_label', 'local_ai_course_assistant'),
            'query' => get_string('analytics:chip_voiceminutes_query', 'local_ai_course_assistant'),
        ];

        // Lowest-rated responses (7d).
        $neg = (int) $DB->get_field_sql(
            "SELECT COUNT(id) FROM {local_ai_course_assistant_msg_ratings}
              WHERE rating = -1 AND timecreated > ?",
            [$since7]
        ) ?: 0;
        $chips[] = [
            'value' => number_format($neg),
            'label' => get_string('analytics:chip_negratings_label', 'local_ai_course_assistant'),
            'query' => get_string('analytics:chip_negratings_query', 'local_ai_course_assistant'),
        ];

        // Integrity flags open.
        $flags = 0;
        try {
            $flags = (int) $DB->count_records_select(
                'local_ai_course_assistant_audit',
                // action, not event -- see classes/review_queue.php.
                'action = ?',
                ['integrity_flagged']
            );
        } catch (\Throwable $e) {
            $flags = 0;
        }
        $chips[] = [
            'value' => number_format($flags),
            'label' => get_string('analytics:chip_integrity_label', 'local_ai_course_assistant'),
            'query' => get_string('analytics:chip_integrity_query', 'local_ai_course_assistant'),
        ];

        return $chips;
    })(),

    // Suggested starter questions: a fixed set of useful queries shown
    // before any data-derived metric chips. Helps admins discover what
    // Learning Radar can answer without staring at a blank input.
    'learning_radar_starters' => [
        [
            'label' => get_string('analytics:radar_starter_topics_label', 'local_ai_course_assistant'),
            'query' => get_string('analytics:radar_starter_topics_query', 'local_ai_course_assistant'),
        ],
        [
            'label' => get_string('analytics:radar_starter_provider_label', 'local_ai_course_assistant'),
            'query' => get_string('analytics:radar_starter_provider_query', 'local_ai_course_assistant'),
        ],
        [
            'label' => get_string('analytics:radar_starter_bounce_label', 'local_ai_course_assistant'),
            'query' => get_string('analytics:radar_starter_bounce_query', 'local_ai_course_assistant'),
        ],
        [
            'label' => get_string('analytics:radar_starter_frustrated_label', 'local_ai_course_assistant'),
            'query' => get_string('analytics:radar_starter_frustrated_query', 'local_ai_course_assistant'),
        ],
        [
            'label' => get_string('analytics:radar_starter_review_label', 'local_ai_course_assistant'),
            'query' => get_string('analytics:radar_starter_review_query', 'local_ai_course_assistant'),
        ],
        [
            'label' => get_string('analytics:radar_starter_trending_label', 'local_ai_course_assistant'),
            'query' => \local_ai_course_assistant\branding::str('analytics:radar_starter_trending_query'),
        ],
        [
            'label' => get_string('analytics:radar_starter_voice_label', 'local_ai_course_assistant'),
            'query' => get_string('analytics:radar_starter_voice_query', 'local_ai_course_assistant'),
        ],
        [
            'label' => get_string('analytics:radar_starter_quiet_label', 'local_ai_course_assistant'),
            'query' => \local_ai_course_assistant\branding::str('analytics:radar_starter_quiet_query'),
        ],
    ],

    // LLM provider list reused by the schedule modal and compare mode.
    'meta_ai_providers_json' => json_encode((function () {
        $providers = [];
        $configprovider = get_config('local_ai_course_assistant', 'provider') ?: 'openai';
        $configmodel = get_config('local_ai_course_assistant', 'model') ?: '';
        $providers[] = [
            'id' => $configprovider,
            'label' => get_string('analytics:provider_primary', 'local_ai_course_assistant', ucfirst($configprovider)),
            'models' => $configmodel ? [$configmodel] : [],
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
            $models = !empty($parts[2]) ? array_values(array_filter(array_map('trim', explode(',', $parts[2])))) : [];
            $providers[] = ['id' => $pid, 'label' => ucfirst($pid), 'models' => $models];
        }
        return $providers;
    })()),
];

// Load Chart.js and analytics dashboard AMD module.
//
// v7.2.1: the Chart.js UMD bundle is bracketed by a pair of guards that hide
// window.define across its load. It prefers AMD when it sees a loader, and
// Moodle always has RequireJS on the page, so it was registering an anonymous
// module that RequireJS then rejected ("Mismatched anonymous define()") instead
// of installing window.Chart. Every canvas on this page rendered empty while
// the headline numbers populated normally, which made it look like a data
// problem. Order matters: these three are classic scripts and run in sequence.
$PAGE->requires->js(new moodle_url('/local/ai_course_assistant/cdn/chartjs/amd-guard-before.js'));
$PAGE->requires->js(new moodle_url('/local/ai_course_assistant/cdn/chartjs/chart.umd.min.js'));
$PAGE->requires->js(new moodle_url('/local/ai_course_assistant/cdn/chartjs/amd-guard-after.js'));
// Resolve the dashboard's JS-rendered labels server-side so they are translatable
// (CONTRIB-10574 #79); the JS reads config.strings.<key>.
$jsstringkeys = [
    'total_students', 'active_ai_users', 'msgs_per_student', 'avg_session', 'return_rate',
    'course', 'section',
    'total_sessions', 'ai_users', 'non_users', 'thumbs_up', 'thumbs_down', 'hallucination_flags',
    'avg_star_rating', 'avg_msgs_resolution', 'survey_respondents', 'messages', 'students',
    'frequency', 'responses', 'error_loading', 'loading', 'no_course_data', 'no_unit_data',
    'no_keyword_data',
];
$jsstrings = [];
foreach ($jsstringkeys as $jsk) {
    $jsstrings[$jsk] = get_string('analytics_js:' . $jsk, 'local_ai_course_assistant');
}
$PAGE->requires->js_call_amd('local_ai_course_assistant/analytics_dashboard', 'init', [[
    'courseid' => $courseid,
    'since' => $since,
    'strings' => $jsstrings,
]]);

// $tabscourses is built in the recordset pass above.

// ── A/B experiment comparison (server-rendered, GET-driven) ────────────────
$templatedata['experiment_form_action'] = (new moodle_url('/local/ai_course_assistant/analytics.php'))->out(false);
$templatedata['experiment_range'] = $range;
$templatedata['experiment_courses_a'] = [];
$templatedata['experiment_courses_b'] = [];
foreach ($experimentcourses as $c) {
    $templatedata['experiment_courses_a'][] = ['id' => $c['id'], 'shortname' => $c['shortname'], 'sel' => ($c['id'] === $expa)];
    $templatedata['experiment_courses_b'][] = ['id' => $c['id'], 'shortname' => $c['shortname'], 'sel' => ($c['id'] === $expb)];
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
    // $expnamea / $expnameb are resolved in the recordset pass above.
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
