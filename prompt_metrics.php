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
 * Admin page — per-section prompt metrics + budget recommendation.
 *
 * v5.0.0 patch 3 — surfaces the rolling 7-day per-category averages
 * captured by {@see prompt_metrics_logger}, plus a recommendation for
 * `prompt_budget_chars` based on observed truncation / headroom. An
 * "Apply recommendation" button is shown when the recommendation
 * differs from the current setting; admins can also opt into daily
 * auto-tune via the plugin settings.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);

$apply = optional_param('apply', 0, PARAM_BOOL);

$pageurl = new moodle_url('/local/ai_course_assistant/prompt_metrics.php');
$PAGE->set_url($pageurl);
$PAGE->set_context($syscontext);
$PAGE->set_title(get_string('prompt_metrics:title', 'local_ai_course_assistant'));
$PAGE->set_heading(get_string('prompt_metrics:title', 'local_ai_course_assistant'));
$PAGE->set_pagelayout('admin');

if ($apply && confirm_sesskey()) {
    $result = \local_ai_course_assistant\prompt_metrics_logger::apply_recommendation();
    if ($result['applied']) {
        redirect($pageurl,
            get_string('prompt_metrics:applied', 'local_ai_course_assistant', (object) $result),
            null,
            \core\output\notification::NOTIFY_SUCCESS);
    } else {
        redirect($pageurl,
            get_string('prompt_metrics:noop', 'local_ai_course_assistant', s($result['reason'])),
            null,
            \core\output\notification::NOTIFY_INFO);
    }
}

$agg = \local_ai_course_assistant\prompt_metrics_logger::aggregate();
$rec = \local_ai_course_assistant\prompt_metrics_logger::recommend($agg);
$current = (int) (get_config('local_ai_course_assistant', 'prompt_budget_chars') ?: 12000);

$by_cat_rows = [];
foreach ($agg['by_cat_avg'] as $cat => $chars) {
    $by_cat_rows[] = [
        'cat'   => ucfirst($cat),
        'chars' => number_format($chars),
    ];
}

$auto_tune_on = (bool) get_config('local_ai_course_assistant', 'prompt_budget_auto_tune');

$templatedata = [
    'samples'         => number_format($agg['samples']),
    'has_data'        => $agg['samples'] > 0,
    'enough_for_rec'  => $agg['samples'] >= 30,
    'avg_total'       => number_format($agg['avg_total']),
    'max_total'       => number_format($agg['max_total']),
    'avg_budget'      => number_format($agg['avg_budget']),
    'pct_truncated'   => $agg['pct_truncated'],
    'pct_dropped'     => $agg['pct_dropped'],
    'last_seen'       => $agg['last_seen'] ? userdate($agg['last_seen'], '%Y-%m-%d %H:%M') : '—',
    'by_cat'          => $by_cat_rows,
    'current_budget'  => number_format($current),
    'has_rec'         => !empty($rec),
    'rec_budget'      => $rec ? number_format($rec['budget']) : '',
    'rec_rationale'   => $rec ? $rec['rationale'] : '',
    'rec_diff'        => $rec ? ($rec['budget'] !== $current) : false,
    'apply_url'       => (new moodle_url($pageurl, ['apply' => 1, 'sesskey' => sesskey()]))->out(false),
    'auto_tune_on'    => $auto_tune_on,
    'settings_url'    => (new moodle_url('/admin/category.php', ['category' => 'local_ai_course_assistant']))->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/prompt_metrics', $templatedata);
echo $OUTPUT->footer();
