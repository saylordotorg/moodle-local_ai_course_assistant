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
 * Open a real-time talking-avatar session for the configured provider.
 *
 * Returns JSON: {ok, embed_url, session_token, provider, expires_in,
 * extras?} or {ok:false, error, reason}. The widget calls this when the
 * student opens the avatar surface; the embed_url is rendered inside an
 * iframe.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/lib/filelib.php');

use local_ai_course_assistant\feature_flags;
use local_ai_course_assistant\security;
use local_ai_course_assistant\talking_avatar\provider_factory;
use local_ai_course_assistant\talking_avatar_session_manager;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die();
}

require_login();
require_sesskey();
security::send_security_headers();
header('Content-Type: application/json');

$courseid = required_param('courseid', PARAM_INT);
$lang     = optional_param('lang', 'en', PARAM_ALPHA);
$greeting = optional_param('greeting', '', PARAM_TEXT);

$context = context_course::instance($courseid);
require_capability('local/ai_course_assistant:use', $context);

if (!feature_flags::resolve('talking_avatar', $courseid)) {
    echo json_encode(['ok' => false, 'reason' => 'disabled',
        'error' => get_string('talking_avatar:disabled', 'local_ai_course_assistant')]);
    exit;
}

$driver = provider_factory::make();
if ($driver === null || !$driver->is_configured()) {
    echo json_encode(['ok' => false, 'reason' => 'unconfigured',
        'error' => get_string('talking_avatar:unconfigured', 'local_ai_course_assistant')]);
    exit;
}

try {
    $session = $driver->start_session([
        'courseid' => $courseid,
        'userid'   => $USER->id,
        'lang'     => $lang ?: 'en',
        'greeting' => $greeting,
    ]);
    $rowid = talking_avatar_session_manager::start(
        $USER->id,
        $courseid,
        (string) ($session['provider'] ?? $driver->get_key()),
        (string) (get_config('local_ai_course_assistant', $driver->get_key() . '_persona_id') ?: ''),
        (string) ($session['session_token'] ?? '')
    );
    echo json_encode(array_merge(['ok' => true, 'session_rowid' => $rowid], $session));
} catch (\Throwable $e) {
    debugging('SOLA talking-avatar session error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    echo json_encode([
        'ok' => false,
        'reason' => 'upstream',
        'error' => get_string('talking_avatar:session_failed', 'local_ai_course_assistant'),
    ]);
}
