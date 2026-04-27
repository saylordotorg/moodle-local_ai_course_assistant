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
 * v4.1 / F2 — One-click weekly digest unsubscribe.
 *
 * Two paths land here:
 *   1. Plain GET from the link in the email body — renders a confirmation page.
 *   2. POST from RFC 8058 List-Unsubscribe-Post (Gmail / Outlook native button)
 *      with body `List-Unsubscribe=One-Click` — silent 200 response, no UI.
 *
 * Both validate the HMAC token in `?token=...`, set the per-(user,course)
 * digest opt-in preference to '0', and finish.
 *
 * Auth: token-only. NOT capability-gated and NOT login-gated — that is the
 * whole point of one-click unsubscribe. Replay-safe by design (the result
 * of unsubscribing twice is the same as unsubscribing once).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing
define('NO_MOODLE_COOKIES', false);
require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\digest_unsubscribe_token;
use local_ai_course_assistant\branding;

$token = optional_param('token', '', PARAM_RAW_TRIMMED);
// RFC 8058 also lets clients POST `List-Unsubscribe=One-Click` to the same URL.
// Recognise either method; we don't care which one we got.
$isoneclick = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (string) ($_POST['List-Unsubscribe'] ?? '') === 'One-Click';

if ($token === '') {
    if ($isoneclick) {
        http_response_code(400);
        exit;
    }
    throw new \moodle_exception('invalidaccess', 'error');
}

$verified = digest_unsubscribe_token::verify($token);
if ($verified === null) {
    if ($isoneclick) {
        http_response_code(400);
        exit;
    }
    // Don't leak detail on the public confirmation page.
    $PAGE->set_context(\context_system::instance());
    $PAGE->set_url(new moodle_url('/local/ai_course_assistant/digest_unsubscribe.php'));
    $PAGE->set_title(get_string('learner_digest:unsubscribe_invalid_title', 'local_ai_course_assistant'));
    $PAGE->set_pagelayout('login');
    echo $OUTPUT->header();
    echo $OUTPUT->box(
        get_string('learner_digest:unsubscribe_invalid_body', 'local_ai_course_assistant'),
        'generalbox'
    );
    echo $OUTPUT->footer();
    exit;
}

[$userid, $courseid] = $verified;

// Set preference. Idempotent.
set_user_preference('local_ai_course_assistant_digest_optin_' . $courseid, '0', $userid);

if ($isoneclick) {
    // RFC 8058 client-driven unsubscribe — return 200, no body, no UI.
    http_response_code(200);
    exit;
}

// Plain GET — render a friendly confirmation page.
$PAGE->set_context(\context_system::instance());
$PAGE->set_url(new moodle_url('/local/ai_course_assistant/digest_unsubscribe.php'));
$PAGE->set_title(get_string('learner_digest:unsubscribe_done_title', 'local_ai_course_assistant'));
$PAGE->set_pagelayout('login');

echo $OUTPUT->header();
echo $OUTPUT->box(
    get_string('learner_digest:unsubscribe_done_body', 'local_ai_course_assistant',
        (object)['product' => branding::short_name()]),
    'generalbox'
);
echo $OUTPUT->footer();
