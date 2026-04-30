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
 * Heartbeat endpoint — called by the SOLA widget when a talking-avatar
 * iframe is closed deliberately (close button, drawer close, beforeunload).
 *
 * Records duration + cost on the matching session row. Webhooks always
 * win over heartbeats; the stale-session sweeper only fires on rows that
 * neither path closed.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\security;
use local_ai_course_assistant\talking_avatar_session_manager;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die();
}

require_login();
require_sesskey();
security::send_security_headers();
header('Content-Type: application/json');

$rowid = required_param('rowid', PARAM_INT);

global $DB, $USER;
$row = $DB->get_record(talking_avatar_session_manager::TABLE, ['id' => $rowid]);
if (!$row) {
    echo json_encode(['ok' => false, 'reason' => 'unknown_session']);
    exit;
}
// Owner-only: a heartbeat from a different user must not close someone
// else's open session. Webhooks (which can come from the provider on
// behalf of any user) take a different path.
if ((int) $row->userid !== (int) $USER->id) {
    echo json_encode(['ok' => false, 'reason' => 'forbidden']);
    exit;
}

$updated = talking_avatar_session_manager::end($rowid, 'heartbeat');
echo json_encode(['ok' => $updated]);
