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
 * Optional vendor webhook receiver for talking-avatar session-end events.
 *
 * URL: /local/ai_course_assistant/talking_avatar_webhook.php?provider=did
 *      (one of did, heygen, tavus, synthesia)
 *
 * Each provider posts a session-ended payload to this endpoint when the
 * institution wires it up in the vendor dashboard. The endpoint verifies
 * the per-provider signing secret (set in plugin settings; default empty
 * = webhook handler off), looks up the local session row by upstream
 * session id, and writes the authoritative duration + cost. Webhook rows
 * win over heartbeat rows.
 *
 * Webhook receivers are intentionally minimal: SOLA does not consume the
 * full payload, only the `session_id` (or vendor-specific equivalent)
 * and an optional `ended_at` timestamp.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\security;
use local_ai_course_assistant\talking_avatar_session_manager;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

security::send_security_headers();
header('Content-Type: application/json');

$provider = optional_param('provider', '', PARAM_ALPHA);
$known = ['did', 'heygen', 'tavus', 'synthesia'];
if (!in_array($provider, $known, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown_provider']);
    exit;
}

$secret = (string) (get_config('local_ai_course_assistant', $provider . '_webhook_secret') ?: '');
if ($secret === '') {
    // Webhook handler is opt-in — default off per provider.
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'webhook_disabled']);
    exit;
}

$body = file_get_contents('php://input');
if ($body === false || $body === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty_body']);
    exit;
}

// Each provider has its own signature header convention. We accept the
// most common: a hex-encoded HMAC-SHA256 of the raw body, named per
// vendor. Mismatches reject with 401.
$expected = hash_hmac('sha256', $body, $secret);
$provided = '';
switch ($provider) {
    case 'did':
        $provided = (string) ($_SERVER['HTTP_X_DID_SIGNATURE'] ?? '');
        break;
    case 'heygen':
        $provided = (string) ($_SERVER['HTTP_X_HEYGEN_SIGNATURE'] ?? '');
        break;
    case 'tavus':
        $provided = (string) ($_SERVER['HTTP_X_TAVUS_SIGNATURE'] ?? '');
        break;
    case 'synthesia':
        $provided = (string) ($_SERVER['HTTP_X_SYNTHESIA_SIGNATURE'] ?? '');
        break;
}
if ($provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'bad_signature']);
    exit;
}

$payload = json_decode($body, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_json']);
    exit;
}

// Extract the upstream session id and optional end timestamp. Each
// vendor uses a slightly different key — try the common ones.
$upstreamid = (string) ($payload['session_id'] ?? $payload['conversation_id']
    ?? $payload['stream_id'] ?? $payload['id'] ?? '');
$endedat = isset($payload['ended_at']) ? (int) $payload['ended_at']
    : (isset($payload['end_time']) ? (int) $payload['end_time'] : null);

if ($upstreamid === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_session_id']);
    exit;
}

$row = talking_avatar_session_manager::find_by_upstream($provider, $upstreamid);
if (!$row) {
    // Unknown — ignore (do not 4xx, vendor may retry).
    echo json_encode(['ok' => true, 'matched' => false]);
    exit;
}

talking_avatar_session_manager::end((int) $row->id, 'webhook', $endedat);
echo json_encode(['ok' => true, 'matched' => true]);
