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
 * Admin page — view the assembled prompt debug log in the browser.
 *
 * v5.0.0 patch 10 — surfaces the rolling log written by sse.php when
 * the `prompt_debug_enabled` admin flag is on. Saves admins from SSHing
 * into the server to read `moodledata/temp/sola_prompt_debug.log` by
 * hand. Each entry is rendered as a collapsible card with the per-turn
 * metadata, per-section breakdown, full assembled system prompt,
 * conversation history, and current user message — i.e. the exact
 * payload the model received that turn.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);

$limit = optional_param('limit', 10, PARAM_INT);
$limit = max(1, min(50, $limit));

$pageurl = new moodle_url('/local/ai_course_assistant/prompt_debug_view.php', ['limit' => $limit]);
$PAGE->set_url($pageurl);
$PAGE->set_context($syscontext);
$PAGE->set_title(get_string('prompt_debug_view:title', 'local_ai_course_assistant'));
$PAGE->set_heading(get_string('prompt_debug_view:title', 'local_ai_course_assistant'));
$PAGE->set_pagelayout('admin');

$enabled = (bool) get_config('local_ai_course_assistant', 'prompt_debug_enabled');
$logpath = \local_ai_course_assistant\prompt_debug::log_path();
$logexists = file_exists($logpath);
$logsize = $logexists ? filesize($logpath) : 0;

$entries = [];
if ($logexists && $logsize > 0) {
    $content = file_get_contents($logpath);
    if ($content !== false) {
        $entries = \local_ai_course_assistant\prompt_debug::parse_entries($content, $limit);
    }
}

$settingsurl = new moodle_url('/admin/category.php', ['category' => 'local_ai_course_assistant']);

$templatedata = [
    'enabled'      => $enabled,
    'log_exists'   => $logexists,
    'log_size_kb'  => $logexists ? number_format($logsize / 1024, 1) : '0',
    'has_entries'  => !empty($entries),
    'entries'      => $entries,
    'limit'        => $limit,
    'next_limit'   => min(50, $limit + 10),
    'can_show_more' => count($entries) === $limit,
    'settings_url' => $settingsurl->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/prompt_debug_view', $templatedata);
echo $OUTPUT->footer();
