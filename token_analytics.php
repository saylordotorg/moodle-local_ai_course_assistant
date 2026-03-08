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
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\token_cost_manager;

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
$PAGE->set_title('SOLA — Token Usage & Cost');
$PAGE->set_heading('SOLA — Token Usage & Cost');
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

$bystudent = $DB->get_records_sql(
    "SELECT m.userid,
            u.firstname, u.lastname,
            COUNT(m.id)                           AS response_count,
            SUM(COALESCE(m.prompt_tokens,0))      AS total_prompt,
            SUM(COALESCE(m.completion_tokens,0))  AS total_completion,
            MIN(m.model_name)                     AS sample_model
       FROM {local_ai_course_assistant_msgs} m
       JOIN {user} u ON u.id = m.userid
      WHERE {$msgwhere}
      GROUP BY m.userid, u.firstname, u.lastname
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

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/token_analytics', $templatedata);
echo $OUTPUT->footer();
