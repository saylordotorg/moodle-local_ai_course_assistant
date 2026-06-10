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
 * v6.1.0 web emergency panel.
 *
 * Thin web wrapper around emergency_control (the class that has powered
 * admin/cli/emergency_disable.php since v5.4.5, with the --chat real-fix
 * from v5.13.0). Before this page, stopping SOLA traffic required SSH —
 * in an incident the on-call admin had to find the runbook, open a shell,
 * and type the CLI invocation. Now it's reachable from the Moodle admin
 * UI in two clicks, with the same per-subsystem granularity as the CLI:
 *
 *   chat      — block chat traffic (widget keeps rendering, learners see
 *               the friendly "SOLA paused" message)
 *   voice     — clear the active realtime voice provider
 *   rag       — disable retrieval + indexing
 *   outreach  — stop digest / milestone / reminder emails
 *   all       — master kill (widget gone, scheduled tasks stop)
 *
 * Every action requires sesskey + a confirm checkbox, writes the same
 * audit row as the CLI, and is reversible from this page.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', \context_system::instance());

use local_ai_course_assistant\emergency_control;

$PAGE->set_context(\context_system::instance());
$PAGE->set_url(new moodle_url('/local/ai_course_assistant/emergency_admin.php'));
$PAGE->set_title(get_string('emergency:title', 'local_ai_course_assistant'));
$PAGE->set_heading(get_string('emergency:title', 'local_ai_course_assistant'));
$PAGE->set_pagelayout('admin');

$validflags = [
    emergency_control::FLAG_CHAT,
    emergency_control::FLAG_VOICE,
    emergency_control::FLAG_RAG,
    emergency_control::FLAG_OUTREACH,
    emergency_control::FLAG_ALL,
];

// Handle POST: disable or restore one subsystem.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);     // 'disable' | 'restore'.
    $flag = required_param('flag', PARAM_ALPHA);         // One of the FLAG_* values.
    $confirm = optional_param('confirm', 0, PARAM_INT);
    $reason = trim(optional_param('reason', '', PARAM_TEXT));

    if (!in_array($flag, $validflags, true)) {
        throw new moodle_exception('invalidparameter', 'debug');
    }
    if ($action === 'disable' && !$confirm) {
        \core\notification::error(get_string('emergency:confirm_required', 'local_ai_course_assistant'));
        redirect($PAGE->url);
    }

    global $USER;
    $invoker = 'web:' . $USER->username;
    if ($action === 'disable') {
        $touched = emergency_control::disable([$flag], $reason, $invoker);
        \core\notification::warning(get_string('emergency:disabled_notice', 'local_ai_course_assistant',
            (object) ['flag' => $flag, 'touched' => implode(', ', $touched)]));
    } else if ($action === 'restore') {
        $touched = emergency_control::restore([$flag], $reason, $invoker);
        \core\notification::success(get_string('emergency:restored_notice', 'local_ai_course_assistant',
            (object) ['flag' => $flag, 'touched' => implode(', ', $touched)]));
    }
    purge_all_caches();
    redirect($PAGE->url);
}

// Current state of each subsystem, so the page shows which are disabled.
$state = [
    emergency_control::FLAG_ALL => !((bool) get_config('local_ai_course_assistant', 'enabled')),
    emergency_control::FLAG_CHAT => (bool) get_config('local_ai_course_assistant', 'emergency_chat_disabled'),
    emergency_control::FLAG_VOICE => ((string) get_config('local_ai_course_assistant', 'voice_active_realtime') === ''
        && (string) get_config('local_ai_course_assistant', 'voice_active_realtime_backup') !== ''),
    emergency_control::FLAG_RAG => !((bool) get_config('local_ai_course_assistant', 'rag_enabled')),
    emergency_control::FLAG_OUTREACH => !((bool) get_config('local_ai_course_assistant', 'outreach_master_enabled')),
];

$rows = [];
foreach ($validflags as $flag) {
    $rows[] = [
        'flag' => $flag,
        'label' => get_string('emergency:flag_' . $flag, 'local_ai_course_assistant'),
        'description' => get_string('emergency:flag_' . $flag . '_desc', 'local_ai_course_assistant'),
        'disabled' => $state[$flag],
        'is_all' => ($flag === emergency_control::FLAG_ALL),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/emergency_admin', [
    'rows' => $rows,
    'sesskey' => sesskey(),
    'actionurl' => $PAGE->url->out(false),
    'settings_url' => (new moodle_url('/admin/settings.php', ['section' => 'local_ai_course_assistant']))->out(false),
    'cli_path' => 'php local/ai_course_assistant/admin/cli/emergency_disable.php',
]);
echo $OUTPUT->footer();
