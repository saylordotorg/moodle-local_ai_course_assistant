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

$range    = optional_param('range', 30, PARAM_INT);  // Days: 7, 30, 90, 0 = all.
$courseid = optional_param('courseid', 0, PARAM_INT);  // 0 = all courses.

$syscontext = context_system::instance();
$hassiteconfig = has_capability('moodle/site:config', $syscontext);

// Site-admin only.
require_capability('moodle/site:config', $syscontext);
$pagecontext = $syscontext;

$PAGE->set_url(new moodle_url(
    '/local/ai_course_assistant/token_analytics.php',
    ['range' => $range, 'courseid' => $courseid]
));
$PAGE->set_context($pagecontext);
$pagetitle = \local_ai_course_assistant\branding::str('token_analytics:title');
$PAGE->set_title($pagetitle);
$PAGE->set_heading($pagetitle);
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

// Billable rows, not just chat replies. The category breakdown below was fixed in
// v6.1.0 to include the role='system' cost-log rows, but this clause (which drives
// the per-model table and the totals) still said role='assistant', so two figures
// on the same screen counted different populations and could not be reconciled.
$msgwhere = \local_ai_course_assistant\analytics::spend_rows_predicate('m')
    . " AND m.model_name IS NOT NULL{$timewhere}{$coursewhere}";

// ── Query 0: Per-category breakdown (Chat / Voice / RAG / Analytics) ──────────
// Maps the per-message `interaction_type` field into a single admin-facing
// "category" for cost reporting. This is what lets an institution separate
// spend on student chat from voice/TTS/STT from RAG embeddings from the
// Learning Radar admin chat.
//
// Mapping (the CASE yields a stable slug; the slug is turned into a
// display label by $categorylabels below, so the label is translatable
// and the grouping key never changes with the viewer's language):
// chat, quiz, <empty>           -> chat
// voice                         -> voice_realtime
// openai_tts, xai_tts           -> voice_tts
// openai_whisper, openai_stt,
// xai_stt                       -> voice_stt
// embedding, embed              -> rag
// meta, meta_scheduled          -> analytics
// mastery_signal, student_profile -> personalisation
// speech_score, slide_vision    -> soapbox
// objective_extract             -> authoring
// anything else                 -> other

$categorysql = "CASE
    WHEN m.interaction_type IN ('voice')                                    THEN 'voice_realtime'
    WHEN m.interaction_type IN ('openai_tts','xai_tts')                     THEN 'voice_tts'
    WHEN m.interaction_type IN ('openai_whisper','openai_stt','xai_stt','selfhosted_stt')    THEN 'voice_stt'
    WHEN m.interaction_type IN ('embedding','embed','rerank')               THEN 'rag'
    WHEN m.interaction_type IN ('meta','meta_scheduled')                    THEN 'analytics'
    WHEN m.interaction_type IN ('mastery_signal','student_profile')          THEN 'personalisation'
    WHEN m.interaction_type IN ('speech_score','slide_vision')               THEN 'soapbox'
    WHEN m.interaction_type IN ('objective_extract')                         THEN 'authoring'
    WHEN m.interaction_type IN ('premium_route')                            THEN 'premium_route'
    WHEN m.interaction_type IN ('quiz')                                     THEN 'quiz'
    WHEN m.interaction_type IN ('chat') OR m.interaction_type IS NULL OR m.interaction_type = '' THEN 'chat'
    ELSE 'other'
END";

// Slug -> display label. The single-word Chat and RAG labels reuse the
// already-translated strings from the kill-switch panel rather than adding
// two more English-only keys that say exactly the same thing.
$categorylabels = [
    'chat'           => get_string('emergency:flag_chat', 'local_ai_course_assistant'),
    'voice_realtime' => get_string('token_analytics:cat_voice_realtime', 'local_ai_course_assistant'),
    'voice_tts'      => get_string('token_analytics:cat_voice_tts', 'local_ai_course_assistant'),
    'voice_stt'      => get_string('token_analytics:cat_voice_stt', 'local_ai_course_assistant'),
    'rag'            => get_string('emergency:flag_rag', 'local_ai_course_assistant'),
    'analytics'      => get_string('token_analytics:cat_analytics', 'local_ai_course_assistant'),
    'premium_route'  => get_string('token_analytics:cat_premium_route', 'local_ai_course_assistant'),
    // v7.1.0: reuse the already-translated column heading rather than adding an
    // English-only key, same reasoning as the two above.
    'quiz'           => get_string('quizsettings:colquiz', 'local_ai_course_assistant'),
    'other'          => get_string('token_analytics:cat_other', 'local_ai_course_assistant'),
];

// v6.1.0: this breakdown includes role='system' cost-log rows (embedding,
// rerank) and premium_route decision rows — previously the role='assistant'
// filter silently excluded them, so the RAG category was always empty and
// the v5.12 premium router was unobservable here. Premium-routing rows
// carry zero tokens (the escalated chat call itself is logged as a normal
// assistant message under its Opus model); the response_count column is
// the number of escalation decisions in the window.
$bycategory = $DB->get_records_sql(
    "SELECT {$categorysql} AS category,
            COUNT(m.id) AS response_count,
            SUM(COALESCE(m.prompt_tokens,0))     AS total_prompt,
            SUM(COALESCE(m.completion_tokens,0)) AS total_completion,
            SUM(" . token_cost_manager::extra_output_tokens_sql('m') . ") AS extra_output
       FROM {local_ai_course_assistant_msgs} m
      WHERE m.role IN ('assistant','system') AND m.model_name IS NOT NULL{$timewhere}{$coursewhere}
      GROUP BY {$categorysql}
      ORDER BY SUM(COALESCE(m.prompt_tokens,0)) + SUM(COALESCE(m.completion_tokens,0)) DESC",
    $params
);

$bycategoryrows = [];
$categorytotalcost = 0.0;
foreach ($bycategory as $row) {
    // Category-level cost uses the mean provider rate from the full set for
    // that category. Cheap estimate; the per-model table below is authoritative.
    //
    // extra_output_tokens_sql() is summed rather than a plain SUM of
    // reasoning_tokens: this query groups by CATEGORY, so no model name reaches
    // PHP and the "is this thinking already inside completion_tokens?" rule has
    // to be applied in SQL. It is the same rule
    // analytics::get_total_tokens() uses, so the two now agree by construction
    // instead of by coincidence.
    $completionshown = (int) $row->total_completion + (int) $row->extra_output;
    $rowtokens = (int) $row->total_prompt + $completionshown;
    $bycategoryrows[] = [
        'category'          => $categorylabels[$row->category] ?? $row->category,
        'response_count'    => number_format((int) $row->response_count),
        'prompt_tokens'     => number_format((int) $row->total_prompt),
        'completion_tokens' => number_format($completionshown),
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
            SUM(COALESCE(m.completion_tokens,0))  AS total_completion,
            SUM(COALESCE(m.reasoning_tokens,0))   AS total_reasoning
       FROM {local_ai_course_assistant_msgs} m
      WHERE {$msgwhere}
      GROUP BY m.model_name, m.provider
      ORDER BY SUM(COALESCE(m.prompt_tokens,0)) + SUM(COALESCE(m.completion_tokens,0)) DESC",
    $params
);

$bymodelrows    = [];
$grandcost      = 0.0;
$unpricedtokens = 0;
$unpricedmodels = [];
$grandprompt    = 0;
$grandcompl     = 0;
$grandresponses = 0;

foreach ($bymodel as $row) {
    // Reasoning tokens must reach estimate_cost or this page disagrees with the
    // external export by the whole Gemini thinking share -- analytics.php has
    // passed the 4th argument since v7.4.2 and these two call sites did not.
    //
    // The SELECT above must actually name total_reasoning. It did not when this
    // comment was first written: `(int) ($row->total_reasoning ?? 0)` reads an
    // undefined property on a stdClass, which yields null with no warning, so
    // the argument was a hard zero on every row and $grandcost -- the page's
    // headline dollar figure -- still carried the pre-v7.4.2 undercount while
    // analytics.php reported the corrected one.
    $reasoning = (int) ($row->total_reasoning ?? 0);
    $cost = token_cost_manager::estimate_cost(
        $row->model,
        (int) $row->total_prompt,
        (int) $row->total_completion,
        $reasoning
    );
    // Thinking that the provider bills as output but does NOT report inside
    // completion_tokens is billable output, so it belongs in the token columns
    // too -- otherwise the cost column moves and the tokens beside it do not,
    // and this table cannot be reconciled against analytics::get_total_tokens(),
    // which adds exactly these tokens via extra_output_tokens_sql().
    $extraoutput = token_cost_manager::reasoning_billed_as_extra_output((string) $row->model)
        ? $reasoning
        : 0;
    $completionshown = (int) $row->total_completion + $extraoutput;
    if ($cost !== null) {
        $grandcost += $cost;
    } else {
        // Track what the headline is NOT counting. The rate card will always lag
        // some model, and the primary chat tier is currently one of them -- so a
        // clean dollar figure built from the priced rows only is a confident
        // understatement with nothing on screen to say so.
        $unpricedtokens += (int) $row->total_prompt + $completionshown;
        $unpricedmodels[] = (string) $row->model;
    }
    $grandprompt    += (int) $row->total_prompt;
    $grandcompl     += $completionshown;
    $grandresponses += (int) $row->response_count;

    $bymodelrows[] = [
        'model'              => $row->model,
        'provider'           => $row->provider,
        'response_count'     => number_format((int) $row->response_count),
        'prompt_tokens'      => number_format((int) $row->total_prompt),
        'completion_tokens'  => number_format($completionshown),
        'total_tokens'       => number_format((int) $row->total_prompt + $completionshown),
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
            SUM(COALESCE(m.completion_tokens,0))  AS total_completion
       FROM {local_ai_course_assistant_msgs} m
       JOIN {user} u ON u.id = m.userid
      WHERE {$msgwhere}
      GROUP BY m.userid, u.firstname, u.lastname,
               u.firstnamephonetic, u.lastnamephonetic,
               u.middlename, u.alternatename
      ORDER BY SUM(COALESCE(m.prompt_tokens,0)) + SUM(COALESCE(m.completion_tokens,0)) DESC",
    $params,
    0,
    100
);

// Price each learner against their ACTUAL model mix, in one extra query.
//
// This used MIN(m.model_name) -- the alphabetically-first model in the learner's
// rows -- and applied that single rate to their entire token total. Alphabetical
// order puts claude-* ahead of gemini-*, gpt-* and text-embedding-*, so one
// premium-router escalation re-priced a learner's whole month of chat and quiz
// traffic at Sonnet rates: a 20-25x overstatement on exactly the top-spender
// rows an admin acts on. The table caption also claimed "the model of their most
// recent session", which MIN() is not.
$permodelcost = [];
$permodelpartial = [];
if (!empty($bystudent)) {
    [$insql, $inparams] = $DB->get_in_or_equal(array_keys($bystudent), SQL_PARAMS_NAMED, 'tu');
    $rs = $DB->get_recordset_sql(
        "SELECT m.userid, m.model_name,
                SUM(COALESCE(m.prompt_tokens,0))     AS p,
                SUM(COALESCE(m.completion_tokens,0)) AS c,
                SUM(COALESCE(m.reasoning_tokens,0))  AS r
           FROM {local_ai_course_assistant_msgs} m
          WHERE {$msgwhere} AND m.userid {$insql}
          GROUP BY m.userid, m.model_name",
        $params + $inparams
    );
    foreach ($rs as $r) {
        $uid = (int) $r->userid;
        $c = token_cost_manager::estimate_cost(
            (string) ($r->model_name ?? ''), (int) $r->p, (int) $r->c, (int) ($r->r ?? 0)
        );
        if ($c === null) {
            // An unpriced model contributes tokens but no dollars. Flag it
            // rather than letting the row read as a complete figure.
            $permodelpartial[$uid] = true;
            continue;
        }
        $permodelcost[$uid] = ($permodelcost[$uid] ?? 0.0) + $c;
    }
    $rs->close();
}

$bystudentrows = [];
foreach ($bystudent as $row) {
    $uid = (int) $row->userid;
    $cost = $permodelcost[$uid] ?? null;
    $bystudentrows[] = [
        'name'              => fullname($row),
        'response_count'    => number_format((int) $row->response_count),
        'prompt_tokens'     => number_format((int) $row->total_prompt),
        'completion_tokens' => number_format((int) $row->total_completion),
        'total_tokens'      => number_format((int)$row->total_prompt + (int)$row->total_completion),
        'estimated_cost'    => token_cost_manager::format_cost($cost)
            . (!empty($permodelpartial[$uid]) && $cost !== null ? '+' : ''),
    ];
}

// ── Query 3: Missing-data audit ───────────────────────────────────────────────

// Same population as the spend tables above, so "calls we could not price" counts
// every billable row missing a model rather than only chat replies.
$missingwhere = \local_ai_course_assistant\analytics::spend_rows_predicate('m')
    . " AND m.model_name IS NULL{$timewhere}{$coursewhere}";
$missingcount = (int) $DB->count_records_sql(
    "SELECT COUNT(m.id) FROM {local_ai_course_assistant_msgs} m WHERE {$missingwhere}",
    $params
);

// ── Course filter options ─────────────────────────────────────────────────────

$courses = $DB->get_records_sql(
    "SELECT c.id, c.shortname, c.fullname
       FROM {course} c
      WHERE EXISTS (SELECT 1 FROM {local_ai_course_assistant_msgs} m
                     WHERE m.courseid = c.id AND m.role = 'assistant')
      ORDER BY c.shortname"
);
$courseoptions = [[
    'id' => 0,
    'name' => get_string('token_analytics:all_courses', 'local_ai_course_assistant'),
    'selected' => ($courseid === 0),
]];
foreach ($courses as $c) {
    $courseoptions[] = [
        'id'       => (int) $c->id,
        'name'     => $c->shortname . ': ' . $c->fullname,
        'selected' => ($courseid === (int) $c->id),
    ];
}

// ── Build URL helpers ─────────────────────────────────────────────────────────

$makeurl = function (int $r, int $c) {
    return (new moodle_url(
        '/local/ai_course_assistant/token_analytics.php',
        ['range' => $r, 'courseid' => $c]
    ))->out(false);
};

// ── Spend guard status + Optimizer recommendations (v3.9.9+) ─────────────────

$spendstatus = [];
foreach (\local_ai_course_assistant\spend_guard::status_rows() as $row) {
    $cap = (float) $row['cap'];
    $spent = (float) $row['spent'];
    $pctnum = $cap > 0 ? min(100, (int) round($spent / $cap * 100)) : 0;
    $color = '#6c757d';
    if ($row['level'] === \local_ai_course_assistant\spend_guard::CAP_BLOCKED) {
        $color = '#dc3545';
    } else if ($row['level'] === \local_ai_course_assistant\spend_guard::CAP_WARN_95) {
        $color = '#fd7e14';
    } else if ($row['level'] === \local_ai_course_assistant\spend_guard::CAP_WARN_80) {
        $color = '#ffc107';
    } else if ($cap > 0) {
        $color = '#198754';
    }
    $spendstatus[] = [
        'label'        => $row['label'],
        'spent_fmt'    => \local_ai_course_assistant\token_cost_manager::format_cost($spent),
        'cap_fmt'      => $cap > 0
            ? \local_ai_course_assistant\token_cost_manager::format_cost($cap)
            : get_string('token_analytics:cap_unlimited', 'local_ai_course_assistant'),
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
            : get_string('prompt_playground:score_na', 'local_ai_course_assistant');
        $ranklines[] = get_string('token_analytics:opt_rank_line', 'local_ai_course_assistant', [
            'rank'         => $i + 1,
            'provider'     => $r['provider'],
            'model'        => $r['model'],
            'cost'         => \local_ai_course_assistant\token_cost_manager::format_cost($r['cost_per_request']),
            'satisfaction' => $satstr,
            'samples'      => $r['sample'],
            'confidence'   => $r['confidence'],
        ]);
    }
    $optrows[] = [
        'capability' => ucfirst($cap['capability']),
        'active'     => $cap['active'],
        'rankings_html' => $ranklines
            ? implode('<br>', $ranklines)
            : '<em>' . get_string('token_analytics:opt_no_data', 'local_ai_course_assistant') . '</em>',
        'has_data'   => !empty($ranklines),
    ];
}

$projection = [
    'amount'     => $optimizerdata['projected_monthly'] > 0
        ? \local_ai_course_assistant\token_cost_manager::format_cost($optimizerdata['projected_monthly'])
        : get_string('token_analytics:opt_no_projection', 'local_ai_course_assistant'),
    'confidence' => $optimizerdata['projection_confidence'],
    'days'       => $optimizerdata['projection_days'],
];

// ── Query: prompt-cache visibility (v6.1.0) ──────────────────────────────────
// Sums the per-call cached-token counts persisted since v6.1.0 (OpenAI
// prompt_tokens_details.cached_tokens / Anthropic cache_read_input_tokens,
// normalized into the one cached_tokens column at write time). Rows from
// before the column existed are NULL and excluded — the hit-rate denominator
// only counts calls that reported cache data, so the percentage is honest
// rather than diluted by pre-v6.1 history.
$cachestats = $DB->get_record_sql(
    "SELECT SUM(COALESCE(m.cached_tokens,0))            AS cached_total,
            SUM(COALESCE(m.prompt_tokens,0))             AS prompt_total,
            COUNT(m.id)                                  AS calls_reporting
       FROM {local_ai_course_assistant_msgs} m
      WHERE m.role = 'assistant' AND m.cached_tokens IS NOT NULL{$timewhere}{$coursewhere}",
    $params
);
$cachedtotal = (int) ($cachestats->cached_total ?? 0);
$cachepct = ($cachestats && (int) $cachestats->prompt_total > 0)
    ? round(100 * $cachedtotal / (int) $cachestats->prompt_total)
    : 0;

// ── Template data ─────────────────────────────────────────────────────────────

$grandtotal = $grandprompt + $grandcompl;
$templatedata = [
    'grand_responses'    => number_format($grandresponses),
    'grand_prompt'       => number_format($grandprompt),
    'grand_completion'   => number_format($grandcompl),
    'grand_total_tokens' => number_format($grandtotal),
    'grand_cost'         => $grandcost > 0
                                ? token_cost_manager::format_cost($grandcost)
                                : ($grandtotal > 0
                                    ? get_string('token_analytics:unknown_model', 'local_ai_course_assistant')
                                    : '—'),
    // True when a dollar figure IS shown but some models had no published rate,
    // so the headline is a floor rather than the total.
    'cost_partial'         => ($grandcost > 0 && $unpricedtokens > 0),
    // Pre-rendered server-side: Moodle's {{#str}} helper takes a literal, so a
    // nested {{var}} inside its argument would not resolve.
    'cost_partial_note'    => ($grandcost > 0 && $unpricedtokens > 0)
        ? get_string('token_analytics:cost_partial', 'local_ai_course_assistant', (object) [
            'tokens' => number_format($unpricedtokens),
            'models' => implode(', ', array_unique(array_filter($unpricedmodels))),
        ])
        : '',
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
    'cached_tokens'      => number_format($cachedtotal),
    'cached_pct'         => $cachepct,
    'has_cache_data'     => $cachedtotal > 0,
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
    'url_7'              => $makeurl(7, $courseid),
    'url_30'             => $makeurl(30, $courseid),
    'url_90'             => $makeurl(90, $courseid),
    'url_all'            => $makeurl(0, $courseid),
    'rate_cards'         => array_map(function (array $rc) {
        $rc['price_label'] = get_string('token_analytics:rate_in_out', 'local_ai_course_assistant', (object) [
            'in'  => $rc['input_per_1m'],
            'out' => $rc['output_per_1m'],
        ]);
        return $rc;
    }, token_cost_manager::get_all_rates()),
    'analytics_url'      => (new moodle_url('/local/ai_course_assistant/analytics.php'))->out(false),
];

// Static UI strings, resolved server-side so the template stays free of
// {{#str}} helpers (every label below is plain data by the time the
// template sees it). Parameterized notes are pre-rendered here for the
// same reason the cost_partial_note above is.
$strs = [
    'str_back'                  => 'token_analytics:back_to_analytics',
    'str_heading'               => 'token_analytics:heading',
    'str_period'                => 'token_analytics:filter_period',
    'str_range_all'             => 'token_analytics:range_all',
    'str_course'                => 'token_analytics:filter_course',
    'str_card_responses'        => 'token_analytics:card_responses',
    'str_card_prompt'           => 'token_analytics:card_prompt_tokens',
    'str_card_completion'       => 'token_analytics:card_completion_tokens',
    'str_card_total'            => 'token_analytics:card_total_tokens',
    'str_card_cost'             => 'token_analytics:card_estimated_cost',
    'str_spend_title'           => 'token_analytics:spend_title',
    'str_col_scope'             => 'token_analytics:col_scope',
    'str_col_spent'             => 'token_analytics:col_spent',
    'str_col_cap'               => 'token_analytics:col_cap',
    'str_col_status'            => 'token_analytics:col_status',
    'str_no_cap'                => 'token_analytics:no_cap',
    'str_opt_title'             => 'token_analytics:opt_title',
    'str_col_capability'        => 'token_analytics:col_capability',
    'str_col_active'            => 'token_analytics:col_active',
    'str_col_recommendations'   => 'token_analytics:col_recommendations',
    'str_bycat_title'           => 'token_analytics:bycat_title',
    'str_bycat_sub'             => 'token_analytics:bycat_sub',
    'str_col_category'          => 'token_analytics:col_category',
    'str_col_responses'         => 'token_analytics:col_responses',
    'str_col_prompt_tokens'     => 'token_analytics:col_prompt_tokens',
    'str_col_completion_tokens' => 'token_analytics:col_completion_tokens',
    'str_col_total_tokens'      => 'token_analytics:col_total_tokens',
    'str_bymodel_title'         => 'token_analytics:bymodel_title',
    'str_bymodel_sub'           => 'token_analytics:bymodel_sub',
    'str_col_model'             => 'token_analytics:col_model',
    'str_col_provider'          => 'token_analytics:col_provider',
    'str_col_est_cost'          => 'token_analytics:col_est_cost',
    'str_no_data_tracking'      => 'token_analytics:no_data_tracking',
    'str_no_data'               => 'token_analytics:no_data',
    'str_avatar_sub'            => 'analytics:avatar_cost_sub',
    'str_avatar_total_row'      => 'analytics:avatar_cost_total_row',
    'str_bystudent_title'       => 'token_analytics:bystudent_title',
    'str_bystudent_sub'         => 'token_analytics:bystudent_sub',
    'str_col_student'           => 'token_analytics:col_student',
    'str_ratecard_title'        => 'token_analytics:ratecard_title',
];
foreach ($strs as $key => $identifier) {
    $templatedata[$key] = get_string($identifier, 'local_ai_course_assistant');
}
$templatedata['str_range_7']  = get_string('token_analytics:range_days', 'local_ai_course_assistant', 7);
$templatedata['str_range_30'] = get_string('token_analytics:range_days', 'local_ai_course_assistant', 30);
$templatedata['str_range_90'] = get_string('token_analytics:range_days', 'local_ai_course_assistant', 90);
$templatedata['str_card_cached'] = get_string('token_analytics:card_cached_tokens', 'local_ai_course_assistant', $cachepct);
$templatedata['str_spend_sub'] = get_string('token_analytics:spend_sub', 'local_ai_course_assistant', (object) [
    'period' => $templatedata['spend_period_label'],
    'start'  => $templatedata['spend_period_start'],
]);
// Rendered unescaped in the template ({{{ }}}): the string carries a <strong>
// wrapper / a <code> literal; every interpolated value is escaped here first.
$templatedata['str_opt_sub'] = get_string('token_analytics:opt_sub', 'local_ai_course_assistant', (object) [
    'amount'     => s($projection['amount']),
    'days'       => s($projection['days']),
    'confidence' => s($projection['confidence']),
]);
$templatedata['str_missing_note'] = get_string(
    'token_analytics:missing_note',
    'local_ai_course_assistant',
    s(number_format($missingcount))
);
$templatedata['str_ratecard_sub'] = get_string('token_analytics:ratecard_sub', 'local_ai_course_assistant');

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
