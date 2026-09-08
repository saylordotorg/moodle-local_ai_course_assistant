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
 * Monthly AI spend export endpoint (Saylor AI Spend dashboard pull contract).
 *
 * The dashboard is pull-only, so this is the whole integration: it calls
 *
 *   GET .../local/ai_course_assistant/spend_export.php?month=2026-09
 *   Authorization: Bearer <spend_export_key>
 *
 * and receives
 *
 *   {"by_provider": {"openai": 12.34, "google": 5.67}, "meta": {...}}
 *
 * `by_provider` is keyed by billing vendor and is the only key the dashboard
 * reads. `meta` carries month, generated_at, per-model breakdown, and the
 * unpriced_rows / unpriced_models counters so the dashboard can show a caveat
 * when a figure is a floor rather than the truth. Speaking this contract
 * natively is what lets the Cloud Run FastAPI/Redash shim be retired.
 *
 * GET parameters:
 *   month (optional) - YYYY-MM. Absent means the current month in UTC. Anything
 *                      else is a 400; it is never coerced into a valid month.
 *
 * Authentication is an Authorization: Bearer header ONLY, compared with
 * hash_equals() against the spend_export_key setting. The key is never accepted
 * in the query string: a secret in a URL is copied into access logs, browser
 * history, Referer headers and any third-party dashboard config it is pasted
 * into. (redash_export.php still accepts ?apikey= for backward compatibility
 * with existing Redash data sources; this endpoint has no such history and
 * therefore no such excuse.)
 *
 * An empty spend_export_key means the endpoint is OFF and every request gets a
 * bare 404, so a site that never configured this does not advertise the URL.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\audit_logger;
use local_ai_course_assistant\rate_limiter;
use local_ai_course_assistant\spend_export;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');
// No Access-Control-Allow-Origin, and no OPTIONS preflight handling: this is a
// server-to-server pull holding a bearer key. Nothing in a browser should ever
// be able to read it cross-origin, so there is no origin to allow.

/**
 * Emit an error body and stop.
 *
 * Bodies are deliberately terse and never echo the request back: this endpoint
 * answers unauthenticated callers, so anything it reflects is attacker-supplied.
 *
 * @param int $status HTTP status code.
 * @param string $message Short machine-readable reason, or '' for an empty body.
 * @return void
 */
function local_ai_course_assistant_spend_export_fail(int $status, string $message = ''): void {
    http_response_code($status);
    if ($message !== '') {
        echo json_encode(['error' => $message]);
    }
    exit;
}

// Off means invisible. This check is FIRST and covers every request method, so
// on a site without a key configured the endpoint is indistinguishable from a
// file that is not there -- a 403 or a 405 would confirm it exists.
if (!spend_export::enabled()) {
    local_ai_course_assistant_spend_export_fail(404);
}

// GET only. There is nothing to create or update here.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    local_ai_course_assistant_spend_export_fail(405, 'method_not_allowed');
}

// Rate limit by IP BEFORE comparing the key, so this endpoint cannot be used as
// an unmetered oracle for guessing it. A real dashboard pull happens once a
// month, so the ceiling is generous even for a debugging admin with curl.
if (rate_limiter::is_ip_rate_limited(spend_export::RATE_BUCKET, spend_export::RATE_MAX, spend_export::RATE_WINDOW)) {
    header('Retry-After: ' . spend_export::RATE_WINDOW);
    local_ai_course_assistant_spend_export_fail(429, 'rate_limited');
}

// Bearer header only -- see the file docblock.
$presentedkey = spend_export::bearer_from_server($_SERVER);
if (!spend_export::authenticate($presentedkey)) {
    header('WWW-Authenticate: Bearer');
    local_ai_course_assistant_spend_export_fail(401, 'unauthorized');
}

// PARAM_RAW_TRIMMED, then a strict regex in spend_export::month_range(). Cleaning
// with PARAM_ALPHANUMEXT would turn "2026-09; DROP" into something that still
// looks month-ish, and an invalid month must be refused rather than repaired --
// the dashboard's yearly total is a sum of these responses, so answering a
// different month than the one asked for is worse than answering nothing.
$month = optional_param('month', '', PARAM_RAW_TRIMMED);
if ($month === '') {
    $month = spend_export::current_month();
}
if (spend_export::month_range($month) === null) {
    local_ai_course_assistant_spend_export_fail(400, 'invalid_month');
}

try {
    $payload = spend_export::build($month);
} catch (\Throwable $e) {
    // Never leak an SQL or DML message to an external caller.
    debugging('Spend export failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    local_ai_course_assistant_spend_export_fail(500, 'export_failed');
}

// Audit every successful export, following the precedent set by
// redash_export.php for its de-anonymized path: a key-authenticated caller is
// not a logged-in admin, so the access log is the only record of who pulled
// what. Whole-site spend is commercially sensitive even though it names no
// learner. Best-effort -- a logging failure must not fail the export.
try {
    audit_logger::log(
        spend_export::AUDIT_ACTION,
        0,
        0,
        [
            'ip' => getremoteaddr(),
            'month' => $month,
            'providers' => count($payload['by_provider']),
            'unpriced_rows' => $payload['meta']['unpriced_rows'],
        ]
    );
} catch (\Throwable $e) {
    $unused = $e;
}

echo spend_export::encode($payload);
