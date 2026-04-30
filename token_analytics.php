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
 * Token usage and cost analytics — site-admin only.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\token_cost_manager;
use local_ai_course_assistant\talking_avatar_cost_manager;
use local_ai_course_assistant\talking_avatar_session_manager;

require_login();

$range    = optional_param('range',    30, PARAM_INT);  // Days: 7, 30, 90, 0 = all.
$courseid = optional_param('courseid',  0, PARAM_INT);  // 0 = all courses.

$syscontext = context_system::instance();
$hassiteconfig = has_capability('moodle/site:config', $syscontext);

// Site-admin only.
require_capability('moodle/site:config', $syscontext);
$pagecontext = $syscontext;

$PAGE->set_url(new moodle_url('/local/ai_course_assistant/token_analytics.php',
    ['range' => $range, 'courseid' => $courseid]));
$PAGE->set_context($pagecontext);
$PAGE->set_title('AI Course Assistant — Token Usage & Cost');
$PAGE->set_heading('AI Course Assistant — Token Usage & Cost');
$PAGE->set_pagelayout($hassiteconfig ? 'admin' : 'report');

// ── Query helpers ──────────────────────────────────────────────────────────────

$params      = [];
$timewhere   = '';
$coursewhere = '';

if ($range > 0) {
    $timewhere = ' AND m.timecreated >= :since';
    $params['since'] = time() - ($range * 86400);
}
if ($courseid > 0) {
    $coursewhere = ' AND m.courseid = :courseid';
    $params['courseid'] = $courseid;
}

$msgwhere = "m.role = 'assistant' AND m.model_name IS NOT NULL{$timewhere}{$coursewhere}";

// ── Query 0: Per-category breakdown (Chat / Voice / RAG / Analytics) ──────────
// Maps the per-message `interaction_type` field into a single admin-facing
// "category" for cost reporting. This is what lets an institution separate
// spend on student chat from voice/TTS/STT from RAG embeddings from the
// Learning Radar admin chat.
//
// Mapping:
//   chat, quiz, <empty>           -> Chat
//   voice                         -> Voice (Realtime)
//   openai_tts, xai_tts           -> Voice (TTS)
//   openai_whisper, openai_stt,
//   xai_stt                       -> Voice (STT)
//   embedding, embed              -> RAG
//   meta                          -> Analytics
//   anything else                 -> Other

$categorysql = "CASE
    WHEN m.interaction_type IN ('voice')                                    THEN 'Voice (Realtime)'
    WHEN m.interaction_type IN ('openai_tts','xai_tts')                     THEN 'Voice (TTS)'
    WHEN m.interaction_type IN ('openai_whisper','openai_stt','xai_stt')    THEN 'Voice (STT)'
    WHEN m.interaction_type IN ('embedding','embed')                        THEN 'RAG'
    WHEN m.interaction_type IN ('meta')                                     THEN 'Analytics'
    WHEN m.interaction_type IN ('chat','quiz') OR m.interaction_type IS NULL OR m.interaction_type = '' THEN 'Chat'
    ELSE 'Other'
END";

$bycategory = $DB->get_records_sql(
    "SELECT {$categorysql} AS category,
            COUNT(m.id) AS response_count,
            SUM(COALESCE(m.prompt_tokens,0))     AS total_prompt,
            SUM(COALESCE(m.completion_tokens,0)) AS total_completion
       FROM {local_ai_course_assistant_msgs} m
      WHERE m.role = 'assistant' AND m.model_name IS NOT NULL{$timewhere}{$coursewhere}
      GROUP BY {$categorysql}
      ORDER BY SUM(COALESCE(m.prompt_tokens,0)) + SUM(COALESCE(m.completion_tokens,0)) DESC",
    $params
);

$bycategoryrows = [];
$categorytotalcost = 0.0;
foreach ($bycategory as $row) {
    // Category-level cost uses the mean provider rate from the full set for
    // that category. Cheap estimate; the per-model table below is authoritative.
    $rowtokens = (int) $row->total_prompt + (int) $row->total_completion;
    $bycategoryrows[] = [
        'category'          => $row->category,
        'response_count'    => number_format((int) $row->response_count),
        'prompt_tokens'     => number_format((int) $row->total_prompt),
        'completion_tokens' => number_format((int) $row->total_completion),
        'total_tokens'      => number_format($rowtokens),
    ];
}

// ── Query 1: Aggregate by model/provider ──────────────────────────────────────

$bymodel = $DB->get_records_sql(
    "SELECT " . $DB->sql_concat("COALESCE(m.model_name,'unknown')", "'-'", "COALESCE(m.provider,'unknown')") . " AS id,
            COALESCE(m.model_name,'unknown') AS model,
            COALESCE(m.provider,'unknown')   AS provider,
            COUNT(m.id)                       AS response_count,
            SUM(COALESCE(m.prompt_tokens,0))      AS total_prompt,
            SUM(COALESCE(m.completion_tokens,0))  AS total_completion
       FROM {local_ai_course_assistant_msgs} m
      WHERE {$msgwhere}
      GROUP BY m.model_name, m.provider
      ORDER BY SUM(COALESCE(m.prompt_tokens,0)) + SUM(COALESCE(m.completion_tokens,0)) DESC",
    $params
);

$bymodelrows    = [];
$grandcost      = 0.0;
$grandprompt    = 0;
$grandcompl     = 0;
$grandresponses = 0;

foreach ($bymodel as $row) {
    $cost = token_cost_manager::estimate_cost(
        $row->model,
        (int) $row->total_prompt,
        (int) $row->total_completion
    );
    if ($cost !== null) { $grandcost += $cost; }
    $grandprompt    += (int) $row->total_prompt;
    $grandcompl     += (int) $row->total_completion;
    $grandresponses += (int) $row->response_count;

    $bymodelrows[] = [
        'model'              => htmlspecialchars($row->model),
        'provider'           => htmlspecialchars($row->provider),
        'response_count'     => number_format((int) $row->response_count),
        'prompt_tokens'      => number_format((int) $row->total_prompt),
        'completion_tokens'  => number_format((int) $row->total_completion),
        'total_tokens'       => number_format((int)$row->total_prompt + (int)$row->total_completion),
        'estimated_cost'     => token_cost_manager::format_cost($cost),
        'cost_unknown'       => ($cost === null),
    ];
}

// ── Query 2: Per-student breakdown (top 100 by token usage) ───────────────────

// Moodle's fullname() emits a debugging() warning when the user object is
// missing any of firstnamephonetic, lastnamephonetic, middlename,
// alternatename — fetch them all so the warning doesn't break page render.
$bystudent = $DB->get_records_sql(
    "SELECT m.userid,
            u.firstname, u.lastname,
            u.firstnamephonetic, u.lastnamephonetic,
            u.middlename, u.alternatename,
            COUNT(m.id)                           AS response_count,
            SUM(COALESCE(m.prompt_tokens,0))      AS total_prompt,
            SUM(COALESCE(m.completion_tokens,0))  AS total_completion,
            MIN(m.model_name)                     AS sample_model
       FROM {local_ai_course_assistant_msgs} m
       JOIN {user} u ON u.id = m.userid
      WHERE {$msgwhere}
      GROUP BY m.userid, u.firstname, u.lastname,
               u.firstnamephonetic, u.lastnamephonetic,
               u.middlename, u.alternatename
      ORDER BY SUM(COALESCE(m.prompt_tokens,0)) + SUM(COALESCE(m.completion_tokens,0)) DESC",
    $params, 0, 100
);

$bystudentrows = [];
foreach ($bystudent as $row) {
    $cost = token_cost_manager::estimate_cost(
        $row->sample_model ?? '',
        (int) $row->total_prompt,
        (int) $row->total_completion
    );
    $bystudentrows[] = [
        'name'              => htmlspecialchars(fullname($row)),
        'response_count'    => number_format((int) $row->response_count),
        'prompt_tokens'     => number_format((int) $row->total_prompt),
        'completion_tokens' => number_format((int) $row->total_completion),
        'total_tokens'      => number_format((int)$row->total_prompt + (int)$row->total_completion),
        'estimated_cost'    => token_cost_manager::format_cost($cost),
    ];
}

// ── Query 3: Missing-data audit ───────────────────────────────────────────────

$missingwhere = "m.role = 'assistant' AND m.model_name IS NULL{$timewhere}{$coursewhere}";
$missingcount = (int) $DB->count_records_sql(
    "SELECT COUNT(m.id) FROM {local_ai_course_assistant_msgs} m WHERE {$missingwhere}",
    $params
);

// ── Course filter options ─────────────────────────────────────────────────────

$courses = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.shortname, c.fullname
       FROM {course} c
       JOIN {local_ai_course_assistant_msgs} m ON m.courseid = c.id
      WHERE m.role = 'assistant'
      ORDER BY c.shortname"
);
$courseoptions = [['id' => 0, 'name' => 'All courses', 'selected' => ($courseid === 0)]];
foreach ($courses as $c) {
    $courseoptions[] = [
        'id'       => (int) $c->id,
        'name'     => htmlspecialchars($c->shortname . ': ' . $c->fullname),
        'selected' => ($courseid === (int) $c->id),
    ];
}

// ── Build URL helpers ─────────────────────────────────────────────────────────

$makeurl = function(int $r, int $c) {
    return (new moodle_url('/local/ai_course_assistant/token_analytics.php',
        ['range' => $r, 'courseid' => $c]))->out(false);
};

// ── Spend guard status + Optimizer recommendations (v3.9.9+) ─────────────────

$spendstatus = [];
foreach (\local_ai_course_assistant\spend_guard::status_rows() as $row) {
    $cap = (float) $row['cap'];
    $spent = (float) $row['spent'];
    $pctnum = $cap > 0 ? min(100, (int) round($spent / $cap * 100)) : 0;
    $color = '#6c757d';
    if ($row['level'] === \local_ai_course_assistant\spend_guard::CAP_BLOCKED) { $color = '#dc3545'; }
    else if ($row['level'] === \local_ai_course_assistant\spend_guard::CAP_WARN_95) { $color = '#fd7e14'; }
    else if ($row['level'] === \local_ai_course_assistant\spend_guard::CAP_WARN_80) { $color = '#ffc107'; }
    else if ($cap > 0) { $color = '#198754'; }
    $spendstatus[] = [
        'label'        => $row['label'],
        'spent_fmt'    => \local_ai_course_assistant\token_cost_manager::format_cost($spent),
        'cap_fmt'      => $cap > 0 ? \local_ai_course_assistant\token_cost_manager::format_cost($cap) : 'unlimited',
        'pct'          => $pctnum,
        'color'        => $color,
        'cap_is_set'   => $cap > 0,
        'level_label'  => ucfirst(str_replace(['warn80', 'warn95'], ['80%+', '95%+'], $row['level'])),
    ];
}

$optimizerdata = \local_ai_course_assistant\llm_optimizer::recommend();
$optrows = [];
foreach ($optimizerdata['capabilities'] as $cap) {
    $toprecs = array_slice($cap['rankings'], 0, 3);
    $ranklines = [];
    foreach ($toprecs as $i => $r) {
        $satstr = $r['satisfaction'] !== null
            ? sprintf('%d%%', (int) round($r['satisfaction'] * 100))
            : 'n/a';
        $ranklines[] = sprintf('%d. %s (%s) — %s/req, satisfaction %s, %d samples (%s confidence)',
            $i + 1,
            htmlspecialchars($r['provider']),
            htmlspecialchars($r['model']),
            \local_ai_course_assistant\token_cost_manager::format_cost($r['cost_per_request']),
            $satstr,
            $r['sample'],
            $r['confidence']
        );
    }
    $optrows[] = [
        'capability' => ucfirst($cap['capability']),
        'active'     => htmlspecialchars($cap['active']),
        'rankings_html' => $ranklines ? implode('<br>', $ranklines) : '<em>Not enough data yet — need at least 30 messages of this capability in the last 30 days.</em>',
        'has_data'   => !empty($ranklines),
    ];
}

$projection = [
    'amount'     => $optimizerdata['projected_monthly'] > 0
        ? \local_ai_course_assistant\token_cost_manager::format_cost($optimizerdata['projected_monthly'])
        : 'Not enough data yet',
    'confidence' => $optimizerdata['projection_confidence'],
    'days'       => $optimizerdata['projection_days'],
];

// ── Template data ─────────────────────────────────────────────────────────────

$grandtotal = $grandprompt + $grandcompl;
$templatedata = [
    'grand_responses'    => number_format($grandresponses),
    'grand_prompt'       => number_format($grandprompt),
    'grand_completion'   => number_format($grandcompl),
    'grand_total_tokens' => number_format($grandtotal),
    'grand_cost'         => $grandcost > 0
                                ? token_cost_manager::format_cost($grandcost)
                                : ($grandtotal > 0 ? 'Unknown model' : '—'),
    'spend_status'       => $spendstatus,
    'has_spend_status'   => !empty($spendstatus),
    'spend_period_label' => \local_ai_course_assistant\spend_guard::period_label(),
    'spend_period_start' => userdate(\local_ai_course_assistant\spend_guard::period_start(), '%e %B %Y'),
    'opt_rows'           => $optrows,
    'opt_projection_amount'     => $projection['amount'],
    'opt_projection_confidence' => $projection['confidence'],
    'opt_projection_days'       => $projection['days'],
    'by_category'        => $bycategoryrows,
    'has_by_category'    => !empty($bycategoryrows),
    'by_model'           => $bymodelrows,
    'has_by_model'       => !empty($bymodelrows),
    'by_student'         => $bystudentrows,
    'has_by_student'     => !empty($bystudentrows),
    'missing_count'      => number_format($missingcount),
    'has_missing'        => $missingcount > 0,
    'courses'            => $courseoptions,
    'range'              => $range,
    'range_7_active'     => $range === 7,
    'range_30_active'    => $range === 30,
    'range_90_active'    => $range === 90,
    'range_all_active'   => $range === 0,
    'url_7'              => $makeurl(7,  $courseid),
    'url_30'             => $makeurl(30, $courseid),
    'url_90'             => $makeurl(90, $courseid),
    'url_all'            => $makeurl(0,  $courseid),
    'rate_cards'         => token_cost_manager::get_all_rates(),
    'analytics_url'      => (new moodle_url('/local/ai_course_assistant/analytics.php'))->out(false),
];

// v4.10.0: talking-avatar cost rollup. Same range + courseid filters as
// the LLM rollup; uses the dedicated avatar session log + per-minute rate
// card. Empty when no sessions in the period.
$avatarfrom = $range > 0 ? (time() - ($range * 86400)) : 0;
$avatarto = time();
$avatartotals = talking_avatar_session_manager::totals_by_provider(
    $avatarfrom,
    $avatarto,
    $courseid > 0 ? $courseid : null
);
$avatarrows = [];
$avatargrandcost = 0.0;
$avatargrandminutes = 0.0;
$avatargrandsessions = 0;
foreach ($avatartotals as $provider => $t) {
    $rate = talking_avatar_cost_manager::rate_for($provider);
    $avatarrows[] = [
        'provider' => ucfirst($provider),
        'sessions' => $t['sessions'],
        'minutes'  => number_format($t['minutes'], 1),
        'rate'     => $rate !== null ? '$' . number_format($rate, 2) : '—',
        'cost'     => talking_avatar_cost_manager::format_cost($t['cost']),
    ];
    $avatargrandcost += $t['cost'];
    $avatargrandminutes += $t['minutes'];
    $avatargrandsessions += $t['sessions'];
}
$templatedata['avatar_rows'] = $avatarrows;
$templatedata['avatar_has_rows'] = !empty($avatarrows);
$templatedata['avatar_grand_cost'] = talking_avatar_cost_manager::format_cost($avatargrandcost);
$templatedata['avatar_grand_minutes'] = number_format($avatargrandminutes, 1);
$templatedata['avatar_grand_sessions'] = $avatargrandsessions;

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/token_analytics', $templatedata);
echo $OUTPUT->footer();
