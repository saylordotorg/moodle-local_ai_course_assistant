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
 * Per-course Instructor & Instructional Designer Dashboard.
 *
 * Aggregate-only by default. Reveal-real-names toggle requires the same
 * `viewanalytics` capability and writes a FERPA audit row on every reveal.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\instructor_analytics;
use local_ai_course_assistant\audit_logger;
use local_ai_course_assistant\security;

require_login();

// v5.1.7: was required_param, which threw and rendered a hard 404 when an
// admin opened the page bare. Now optional_param + a friendly "pick a
// course" landing if missing. Direct links with ?courseid=N are unchanged.
$courseid = optional_param('courseid', 0, PARAM_INT);
if ($courseid <= 0) {
    \local_ai_course_assistant\page_helpers::render_course_picker_landing(
        '/local/ai_course_assistant/instructor_dashboard.php',
        get_string('coursepicker:title', 'local_ai_course_assistant',
            get_string('instructor_dashboard:short', 'local_ai_course_assistant')),
        'local/ai_course_assistant:viewanalytics'
    );
    exit;
}
$range    = optional_param('range', 30, PARAM_INT);   // 7, 30, 90, 0=all
$gapdays  = optional_param('gapdays', 7, PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
$coursecontext = context_course::instance($courseid);
require_capability('local/ai_course_assistant:viewanalytics', $coursecontext);

$pageurl = new moodle_url('/local/ai_course_assistant/instructor_dashboard.php',
    ['courseid' => $courseid, 'range' => $range, 'gapdays' => $gapdays]);

$PAGE->set_url($pageurl);
$PAGE->set_context($coursecontext);
$PAGE->set_pagelayout('incourse');
$PAGE->set_course($course);
$PAGE->set_title(get_string('instructor_dashboard:title', 'local_ai_course_assistant',
    \local_ai_course_assistant\branding::short_name()));
$PAGE->set_heading($course->fullname);

security::send_security_headers(true);

// v4.8.0: needs-review queue resolve action.
if ($action === 'resolvereview' && confirm_sesskey()) {
    $source = required_param('source', PARAM_ALPHA);
    $sourceid = required_param('sourceid', PARAM_INT);
    $note = optional_param('note', '', PARAM_TEXT);
    \local_ai_course_assistant\review_queue::mark_resolved(
        $source, $sourceid, $courseid, (int) $USER->id, $note
    );
    redirect($pageurl, get_string('instructor_dashboard:review_resolved', 'local_ai_course_assistant'),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

// Reveal-real-names toggle (session-scoped + audit logged). Stored in a
// MODE_SESSION cache rather than the raw $_SESSION superglobal.
$uistate = \cache::make('local_ai_course_assistant', 'uistate');
$realnameskey = 'show_real_names_' . $courseid;
if ($action === 'togglenames' && confirm_sesskey()) {
    if ($uistate->get($realnameskey)) {
        $uistate->delete($realnameskey);
    } else {
        $uistate->set($realnameskey, 1);
        audit_logger::log(
            'instructor_reveal_learner_identities',
            (int) $USER->id,
            $courseid,
            ['range_days' => (int) $range]
        );
    }
    redirect($pageurl);
}
$showrealnames = (bool) $uistate->get($realnameskey);

$since = $range > 0 ? (time() - ($range * 86400)) : 0;

// Compute everything up front so the page renders deterministically.
$summary       = instructor_analytics::summary($courseid, $since);
$mastery       = instructor_analytics::mastery_aggregate($courseid);
$topics        = instructor_analytics::top_topics($courseid, $since, 10);
$confusion     = instructor_analytics::confusion_heatmap($courseid, $since, 15);
$ratings       = instructor_analytics::ratings_summary($courseid, $since);
$gap           = instructor_analytics::engagement_gap($courseid, $gapdays);
$reviewqueue   = \local_ai_course_assistant\review_queue::pending_for_course($courseid, 50);


echo $OUTPUT->header();

$strman = 'local_ai_course_assistant';

// Top navigation strip.
$nav = [
    ['url' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
     'label' => get_string('instructor_dashboard:nav_back_course', $strman)],
    ['url' => (new moodle_url('/local/ai_course_assistant/course_settings.php', ['courseid' => $courseid]))->out(false),
     'label' => get_string('instructor_dashboard:nav_settings', $strman)],
    ['url' => (new moodle_url('/local/ai_course_assistant/analytics.php', ['courseid' => $courseid, 'range' => $range]))->out(false),
     'label' => get_string('instructor_dashboard:nav_analytics', $strman)],
];

// Period selector options.
$periodmap = [7 => '7d', 30 => '30d', 90 => '90d', 0 => get_string('instructor_dashboard:period_all', $strman)];
$periodoptions = [];
foreach ($periodmap as $val => $label) {
    $periodoptions[] = ['value' => (int) $val, 'label' => $label, 'selected' => ((int) $range === (int) $val)];
}

// Summary tiles.
$tiles = [
    ['label' => get_string('instructor_dashboard:active_learners', $strman), 'value' => number_format($summary['active'])],
    ['label' => get_string('instructor_dashboard:total_messages', $strman), 'value' => number_format($summary['total_messages'])],
    ['label' => get_string('instructor_dashboard:avg_per_learner', $strman), 'value' => number_format($summary['avg_per_learner'], 1)],
    ['label' => get_string('instructor_dashboard:last_activity', $strman),
     'value' => $summary['last_activity'] > 0 ? userdate($summary['last_activity'], '%b %e, %H:%M') : '-'],
];

// Mastery rows.
$masteryrows = [];
foreach ($mastery as $row) {
    $masteryrows[] = [
        'code' => $row['code'] !== '' ? $row['code'] : '',
        'title' => $row['title'],
        'mastered' => $row['mastered'], 'mastered_pct' => $row['mastered_pct'],
        'learning' => $row['learning'], 'learning_pct' => $row['learning_pct'],
        'notstarted' => $row['not_started'], 'notstarted_pct' => $row['not_started_pct'],
        'attempts' => number_format($row['attempts']),
    ];
}

// Most-asked topics.
$topicrows = [];
foreach ($topics as $t) {
    $topicrows[] = ['keyword' => $t['keyword'], 'frequency' => $t['frequency'],
        'category' => $t['category'] !== '' ? $t['category'] : ''];
}

// Confusion heatmap rows.
$confusionrows = [];
foreach ($confusion as $row) {
    $label = $row['name'] !== '' ? $row['name'] : ('cmid ' . $row['cmid']);
    $linkurl = '';
    if ($row['cmid'] > 0 && $row['name'] !== '' && $row['modname'] !== '') {
        $linkurl = (new moodle_url('/mod/' . $row['modname'] . '/view.php', ['id' => $row['cmid']]))->out(false);
        $label = $row['name'];
    }
    $confusionrows[] = ['linkurl' => $linkurl, 'label' => $label,
        'questions' => number_format($row['question_count']), 'learners' => number_format($row['distinct_learners'])];
}

// Helpful / unhelpful rates.
$total = $ratings['positive'] + $ratings['negative'];
$ratingssummary = get_string('instructor_dashboard:ratings_summary', $strman, (object) [
    'positive' => $ratings['positive'], 'negative' => $ratings['negative'],
    'hallucinations' => $ratings['hallucinations'],
    'pct' => $total > 0 ? round(($ratings['positive'] / $total) * 100, 1) : 0,
]);
$lowrows = [];
foreach (($ratings['low_rated_by_module'] ?? []) as $row) {
    $name = $row['name'] !== '' ? $row['name'] : ('cmid ' . $row['cmid']);
    $lowrows[] = ['name' => $name, 'count' => $row['low_rated']];
}

// Engagement gap.
$gapsummary = get_string('instructor_dashboard:gap_summary', $strman, (object) [
    'not_seen' => $gap['not_seen'], 'enrolled' => $gap['enrolled'], 'days' => $gapdays,
]);
$gapsample = [];
if ($showrealnames && !empty($gap['sample_userids'])) {
    list($insql, $inparams) = $DB->get_in_or_equal($gap['sample_userids'], SQL_PARAMS_NAMED, 'uid');
    $gapusers = $DB->get_records_sql(
        "SELECT id, firstname, lastname FROM {user} WHERE id $insql ORDER BY lastname, firstname", $inparams);
    foreach ($gapusers as $u) {
        $gapsample[] = ['name' => fullname($u)];
    }
}

// Needs-review queue rows.
$reviewrows = [];
foreach ($reviewqueue as $row) {
    $reviewrows[] = [
        'when' => userdate($row['when'], '%Y-%m-%d %H:%M'),
        'sourcelabel' => get_string('instructor_dashboard:review_source_' . $row['source'], $strman),
        'who' => $row['who'],
        'summary' => $row['summary'],
        'resolveurl' => (new moodle_url('/local/ai_course_assistant/instructor_dashboard.php', [
            'courseid' => $courseid, 'range' => $range, 'gapdays' => $gapdays,
            'action' => 'resolvereview', 'source' => $row['source'], 'sourceid' => $row['sourceid'],
            'sesskey' => sesskey(),
        ]))->out(false),
        'resolve_label' => get_string('instructor_dashboard:review_resolve', $strman),
    ];
}

echo $OUTPUT->render_from_template('local_ai_course_assistant/instructor_dashboard', [
    'nav' => $nav,
    'title' => get_string('instructor_dashboard:title', $strman, \local_ai_course_assistant\branding::short_name()),
    'intro' => get_string('instructor_dashboard:intro', $strman),
    'formaction' => $pageurl->out_omit_querystring(),
    'courseid' => $courseid, 'range' => $range, 'gapdays' => $gapdays, 'sesskey' => sesskey(),
    'period_label' => get_string('instructor_dashboard:period', $strman),
    'periodoptions' => $periodoptions,
    'gap_label' => get_string('instructor_dashboard:gap_days', $strman),
    'toggle_label' => $showrealnames
        ? get_string('instructor_dashboard:hide_names', $strman)
        : get_string('instructor_dashboard:show_names', $strman),
    'toggle_class' => $showrealnames ? 'btn-warning' : 'btn-secondary',
    'tiles' => $tiles,
    'has_mastery' => !empty($masteryrows),
    'mastery_heading' => get_string('instructor_dashboard:mastery_heading', $strman),
    'mastery_empty' => get_string('instructor_dashboard:mastery_off', $strman),
    'mastery_cols' => [
        'objective' => get_string('instructor_dashboard:col_objective', $strman),
        'mastered' => get_string('instructor_dashboard:col_mastered', $strman),
        'learning' => get_string('instructor_dashboard:col_learning', $strman),
        'notstarted' => get_string('instructor_dashboard:col_not_started', $strman),
        'attempts' => get_string('instructor_dashboard:col_attempts', $strman),
    ],
    'mastery_rows' => $masteryrows,
    'has_topics' => !empty($topicrows),
    'topics_heading' => get_string('instructor_dashboard:topics_heading', $strman),
    'topics_empty' => get_string('instructor_dashboard:topics_empty', $strman),
    'topics' => $topicrows,
    'has_confusion' => !empty($confusionrows),
    'confusion_heading' => get_string('instructor_dashboard:confusion_heading', $strman),
    'confusion_empty' => get_string('instructor_dashboard:confusion_empty', $strman),
    'confusion_cols' => [
        'module' => get_string('instructor_dashboard:col_module', $strman),
        'questions' => get_string('instructor_dashboard:col_questions', $strman),
        'learners' => get_string('instructor_dashboard:col_distinct_learners', $strman),
    ],
    'confusion_rows' => $confusionrows,
    'ratings_heading' => get_string('instructor_dashboard:ratings_heading', $strman),
    'ratings_summary' => $ratingssummary,
    'has_low_rated' => !empty($lowrows),
    'ratings_low_label' => get_string('instructor_dashboard:ratings_low_module', $strman),
    'ratings_low_module' => get_string('instructor_dashboard:col_module', $strman),
    'ratings_low_count' => get_string('instructor_dashboard:col_low_rated', $strman),
    'ratings_low_rows' => $lowrows,
    'gap_heading' => get_string('instructor_dashboard:gap_heading', $strman),
    'gap_summary' => $gapsummary,
    'has_gap_sample' => !empty($gapsample),
    'gap_sample_label' => get_string('instructor_dashboard:gap_show_sample', $strman),
    'gap_sample' => $gapsample,
    'has_review' => !empty($reviewrows),
    'review_heading' => get_string('instructor_dashboard:review_heading', $strman),
    'review_intro' => get_string('instructor_dashboard:review_intro', $strman),
    'review_empty' => get_string('instructor_dashboard:review_empty', $strman),
    'review_cols' => [
        'when' => get_string('instructor_dashboard:review_col_when', $strman),
        'source' => get_string('instructor_dashboard:review_col_source', $strman),
        'who' => get_string('instructor_dashboard:review_col_who', $strman),
        'summary' => get_string('instructor_dashboard:review_col_summary', $strman),
    ],
    'review_rows' => $reviewrows,
]);

echo $OUTPUT->footer();
