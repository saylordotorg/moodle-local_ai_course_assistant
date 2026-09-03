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
 * Redash analytics export endpoint.
 *
 * Provides JSON export of usage analytics, feedback, and token cost data
 * for external analytics platforms like Redash. Authenticated via API key.
 *
 * GET parameters:
 *   apikey    (required) - must match the configured redash_api_key setting
 *                          (an Authorization: Bearer header is preferred)
 *   courseid  (optional) - specific course ID, or 0 for all courses (default: 0)
 *   since     (optional) - Unix timestamp to filter data from. Absent means the
 *                          configured lookback window (redash_export_window_days,
 *                          default 90 days); an explicit since=0 means all time.
 *   sections  (optional) - comma-separated allow-list of sections to build, e.g.
 *                          sections=courses,feedback,token_costs. Absent (or
 *                          "all") emits everything, which includes raw chat
 *                          transcript text; name the sections you need instead.
 *                          Valid: courses, student_usage, hotspots, feedback,
 *                          token_costs, survey_responses, meta_ai,
 *                          learning_radar_queries.
 *   anonymize (optional) - 1 (default) pseudonymizes learners. anonymize=0 is
 *                          refused with 403 unless an admin has enabled the
 *                          redash_allow_deanonymized setting, and is always
 *                          audit-logged with the requesting IP.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\analytics;
use local_ai_course_assistant\redash_export_request;

// Content type. This endpoint is server-to-server (Redash pulls it with the
// API key); it is NOT meant to be read cross-origin from a browser. A wildcard
// Access-Control-Allow-Origin would let any web page fetch bulk learner data if
// it learned the key. Emit a CORS origin header ONLY when an admin has
// explicitly configured one (redash_allowed_origin), and never the wildcard.
// v7.2.10: the analytics dashboard's "Export CSV" button has always pointed
// here, and this endpoint only ever emitted JSON -- no format parameter, no
// Content-Disposition -- so the button downloaded a JSON body that the browser
// rendered inline. A CSV-producing external function existed but nothing called
// it. `format=csv` now emits one requested section as a real CSV attachment.
$outputformat = optional_param('format', 'json', PARAM_ALPHA) === 'csv' ? 'csv' : 'json';
if ($outputformat === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
} else {
    header('Content-Type: application/json; charset=utf-8');
}
header('Cache-Control: no-cache, no-store, must-revalidate');
$allowedorigin = trim((string) get_config('local_ai_course_assistant', 'redash_allowed_origin'));
if ($allowedorigin !== '') {
    header('Access-Control-Allow-Origin: ' . $allowedorigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

// Handle CORS preflight.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only accept GET requests.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'error' => get_string('redash:err_method_not_allowed', 'local_ai_course_assistant'),
    ]);
    exit;
}

// Validate API key. Prefer an Authorization: Bearer header (which web servers
// do not log by default) over the ?apikey query parameter; the query parameter
// is still accepted for backward compatibility with existing Redash data sources.
$bearer = '';
$authheader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($authheader !== '' && preg_match('/^Bearer\s+(.+)$/i', trim($authheader), $bm)) {
    $bearer = trim($bm[1]);
}
// PARAM_RAW is required: an API key is an opaque secret whose byte sequence must
// reach hash_equals() unaltered - any cleaning would silently rewrite a valid key
// into a failing one. It is never stored, echoed or interpolated; the only thing
// done with it is the constant-time comparison below.
$apikey = $bearer !== '' ? $bearer : optional_param('apikey', '', PARAM_RAW);
$configuredkey = get_config('local_ai_course_assistant', 'redash_api_key');

// v7.0.5: a short-lived token for links the admin UI generates, so the raw key
// stops travelling in query strings, browser history, access logs and Referer
// headers -- and stops being pre-baked into URLs admins paste into Redash,
// where it would be stored in plaintext by a third party. Derived from the key
// by HMAC, scoped to one user, and expiring in minutes.
$downloadtoken = optional_param('t', '', PARAM_RAW_TRIMMED);
$downloaduser = optional_param('u', 0, PARAM_INT);
if ($apikey === '' && $downloadtoken !== ''
        && \local_ai_course_assistant\security::verify_redash_download_token($downloadtoken, $downloaduser)) {
    // Valid derived token stands in for the key for this one request.
    $apikey = (string) $configuredkey;
}

if (empty($configuredkey)) {
    http_response_code(403);
    echo json_encode([
        'error' => get_string('redash:err_not_configured', 'local_ai_course_assistant'),
    ]);
    exit;
}

if (empty($apikey) || !hash_equals($configuredkey, $apikey)) {
    http_response_code(401);
    echo json_encode([
        'error' => get_string('redash:err_invalid_key', 'local_ai_course_assistant'),
    ]);
    exit;
}

// Parse parameters.
$courseid = optional_param('courseid', 0, PARAM_INT);

// -1 is the "parameter absent" sentinel: absent means the configured lookback
// window, while an explicit since=0 still means an all-time export.
$since = redash_export_request::resolve_since(
    optional_param('since', -1, PARAM_INT),
    time()
);

// Section allow-list. Building only what the caller asked for keeps the heavy
// blocks (raw transcript excerpt, Learning Radar query/response bodies) out of
// a payload that only wants usage, feedback and cost.
// Comma-separated section names. PARAM_TEXT is lossless for the slug list this
// accepts (verified against every value in redash_export_request::SECTIONS) and
// strips markup; parse_sections() then trims, lowercases and allow-lists each
// name against that constant, so an unknown name can never widen the payload.
$rawsections = trim(optional_param('sections', '', PARAM_TEXT));
$sections = redash_export_request::parse_sections($rawsections);
if (empty($sections)) {
    // Every name supplied was unrecognized. Fail loudly rather than falling
    // back to the full export, so a typo cannot quietly widen the payload.
    http_response_code(400);
    echo json_encode([
        'error' => get_string('redash:err_no_sections', 'local_ai_course_assistant'),
        'unknown_sections' => redash_export_request::unknown_sections($rawsections),
        'valid_sections' => redash_export_request::SECTIONS,
    ]);
    exit;
}
// `student_usage` and `hotspots` live inside a course row, so they are only built
// during the per-course walk. Requested without `courses`, they used to return a
// 200 carrying no learner rows at all, which is exactly the quiet-wrong-answer
// this endpoint refuses to give for an unknown section name. Same treatment: say
// what is missing rather than hand back an empty payload.
$missingparents = redash_export_request::missing_parents($sections);
if (!empty($missingparents)) {
    http_response_code(400);
    echo json_encode([
        'error' => get_string('redash:err_nested_sections', 'local_ai_course_assistant'),
        'requires' => $missingparents,
        'hint' => get_string(
            'redash:hint_add_parent',
            'local_ai_course_assistant',
            implode(',', array_unique(array_merge(
                array_values($missingparents),
                array_keys($missingparents)
            )))
        ),
    ]);
    exit;
}

$wants = function (string $section) use ($sections): bool {
    return in_array($section, $sections, true);
};

// v5.10.x (security finding #3.7): a de-anonymized export (anonymize=0) reveals
// real learner names. This endpoint authenticates by API key, not a logged-in
// admin, so the key alone must not be enough to de-anonymize: require an admin
// to have enabled it, and audit the access with the requesting IP either way.
$anonymize = optional_param('anonymize', 1, PARAM_INT) !== 0;
if (!$anonymize) {
    if (!redash_export_request::deanonymize_allowed()) {
        http_response_code(403);
        echo json_encode([
            'error' => get_string('redash:err_deanonymized_disabled', 'local_ai_course_assistant'),
        ]);
        exit;
    }
    try {
        \local_ai_course_assistant\audit_logger::log(
            'redash_export_deanonymized',
            0,
            $courseid,
            ['ip' => getremoteaddr(), 'since' => $since]
        );
    } catch (\Throwable $e) {
        // Best-effort audit; never block the export on a logging failure.
        $unused = $e;
    }
}

// Get plugin version from version.php.
$plugin = new stdClass();
require(__DIR__ . '/version.php');
$pluginversion = $plugin->release ?? 'unknown';

// Determine which courses to export. Skipped entirely when the caller did not
// ask for the courses section (the per-course analytics are the bulk of a
// full-site export).
$courseids = [];
if ($wants('courses')) {
    if ($courseid > 0) {
        $courseids = [$courseid];
    } else {
        // Courses with at least one real conversation row in the window.
        //
        // This used to be an unfiltered SELECT DISTINCT courseid, which had two
        // consequences, both showing up as course rows whose every metric is
        // zero. First, embedding telemetry is written against SITEID, so the site
        // course appeared as a "course" whose entire content was the RAG indexing
        // ledger. Filtering on the conversation roles rather than excluding
        // SITEID outright is deliberate: the widget does appear on non-course
        // pages, and that chat legitimately carries courseid = SITEID, so a
        // blanket SITEID exclusion would discard real usage.
        //
        // Second, the list ignored `since` while every metric in the row honours
        // it, so a course last used years ago still appeared, empty. The window
        // now applies to both.
        $listparams = [];
        $listwhere = analytics::conversation_rows_predicate('m');
        if ($since > 0) {
            $listwhere .= ' AND m.timecreated >= :since';
            $listparams['since'] = $since;
        }
        $courseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT m.courseid
               FROM {local_ai_course_assistant_msgs} m
              WHERE {$listwhere}",
            $listparams
        );
    }
}

// Build course data.
$courses = [];
foreach ($courseids as $cid) {
    $cid = (int) $cid;

    // v7.2.4: course id 0 now means "every course" to the analytics class, so a
    // stray courseid=0 row in the messages table would make this entry report
    // whole-site totals under a single course heading. The ids here come
    // straight from SELECT DISTINCT courseid, so nothing upstream guarantees
    // they are real courses.
    if ($cid <= 0) {
        continue;
    }

    // Get course name.
    $coursename = $DB->get_field('course', 'fullname', ['id' => $cid]);
    if ($coursename === false) {
        $coursename = get_string('redash:unknown_course', 'local_ai_course_assistant', $cid);
    }

    $coursedata = [
        'courseid' => $cid,
        'coursename' => $coursename,
        'overview' => analytics::get_overview($cid, $since),
        'daily_usage' => analytics::get_daily_usage($cid, 30),
        'common_prompts' => analytics::get_common_prompts($cid, $since),
        'provider_comparison' => analytics::get_provider_comparison($cid, $since),
    ];

    // Hotspots require course modinfo, which may fail for deleted/broken courses.
    if ($wants('hotspots')) {
        try {
            $coursedata['hotspots'] = analytics::get_hotspots($cid, $since);
        } catch (\Throwable $e) {
            $coursedata['hotspots'] = [];
        }
    }

    // Student usage: one row per learner, so it is opt-in. Anonymized unless an
    // admin has enabled de-anonymized exports and the caller asked for one.
    if ($wants('student_usage')) {
        $studentrecords = analytics::get_student_usage($cid, $since);
        $studentusage = [];
        foreach ($studentrecords as $record) {
            // Identity via the shared helper: this block used to emit the real
            // userid next to the pseudonym, which defeated the pseudonym.
            $studentusage[] = redash_export_request::learner_identity(
                (int) $record->userid,
                $anonymize,
                $record->firstname,
                $record->lastname
            ) + [
                'message_count' => (int) $record->message_count,
                'last_active' => (int) $record->last_active,
            ];
        }
        $coursedata['student_usage'] = $studentusage;
    }

    $courses[] = $coursedata;
}

// Feedback data.
$feedbackparams = [];
$feedbackwhere = '';
if ($courseid > 0) {
    $feedbackwhere .= ' WHERE courseid = :courseid';
    $feedbackparams['courseid'] = $courseid;
}
if ($since > 0) {
    $feedbackwhere .= ($feedbackwhere ? ' AND' : ' WHERE') . ' timecreated >= :since';
    $feedbackparams['since'] = $since;
}

$feedbackrecords = $wants('feedback') ? $DB->get_records_sql(
    "SELECT id, userid, courseid, rating, comment, browser, os, device,
            screen_size, user_agent, page_url, timecreated
       FROM {local_ai_course_assistant_feedback}" . $feedbackwhere .
    " ORDER BY timecreated DESC",
    $feedbackparams
) : [];

// Feedback PII gate: under the default anonymized export, do not emit the
// fields that re-identify or fingerprint a learner (real userid, user agent,
// page URL, device/browser/OS/screen). Keep the rating, the free-text comment,
// and a stable pseudonymous id so dashboards still work. Only a deliberate
// anonymize=0 request (already audit-logged above) sees raw values.
$feedback = [];
foreach ($feedbackrecords as $record) {
    $row = ['id' => (int) $record->id]
        + redash_export_request::learner_identity((int) $record->userid, $anonymize)
        + [
            'courseid' => (int) $record->courseid,
            'rating' => (int) $record->rating,
            'comment' => $record->comment,
        ];
    if (!$anonymize) {
        // Fingerprinting fields, only on a deliberate de-anonymized export.
        $row += [
            'browser' => $record->browser,
            'os' => $record->os,
            'device' => $record->device,
            'screen_size' => $record->screen_size,
            'user_agent' => $record->user_agent,
            'page_url' => $record->page_url,
        ];
    }
    $row['timecreated'] = (int) $record->timecreated;
    $feedback[] = $row;
}

// Token costs: aggregate by model, chat plus the background RAG spend ledger.
// The aggregation lives in analytics::get_token_costs() so it is unit-testable;
// see that method for why embedding/rerank rows are included and premium_router
// rows are not, and for the SITEID scoping of background spend.
$tokencosts = $wants('token_costs') ? analytics::get_token_costs($courseid, $since) : [];

// Survey response data.
$surveydata = [];
try {
    $surveywhere = '';
    $surveyparams = [];
    if ($courseid > 0) {
        $surveywhere .= ' WHERE r.courseid = :courseid';
        $surveyparams['courseid'] = $courseid;
    }
    if ($since > 0) {
        $surveywhere .= ($surveywhere ? ' AND' : ' WHERE') . ' r.timecreated >= :since';
        $surveyparams['since'] = $since;
    }

    $surveyrecords = $wants('survey_responses') ? $DB->get_records_sql(
        "SELECT r.id, r.surveyid, r.userid, r.courseid, r.question_index, r.answer, r.timecreated,
                s.title AS survey_title
           FROM {local_ai_course_assistant_survey_resp} r
           JOIN {local_ai_course_assistant_surveys} s ON s.id = r.surveyid" .
        $surveywhere . " ORDER BY r.timecreated DESC",
        $surveyparams
    ) : [];

    foreach ($surveyrecords as $record) {
        // Same PII gate as feedback, via the shared helper. This block used to
        // put the raw userid under the `user_ref` key when not anonymizing, so
        // `user_ref` did not reliably mean "pseudonym".
        $surveydata[] = [
            'id' => (int) $record->id,
            'surveyid' => (int) $record->surveyid,
            'survey_title' => $record->survey_title,
        ] + redash_export_request::learner_identity((int) $record->userid, $anonymize) + [
            'courseid' => (int) $record->courseid,
            'question_index' => (int) $record->question_index,
            'answer' => $record->answer,
            'timecreated' => (int) $record->timecreated,
        ];
    }
} catch (\Throwable $e) {
    // Table may not exist yet on older installs.
    $surveydata = [];
}

// Learning Radar analytics: anonymized stats and transcript excerpt for Redash
// dashboards. The transcript excerpt is up to 50,000 characters of raw learner
// conversation, so this section is only built when explicitly requested.
$metaai = [];
if ($wants('meta_ai')) {
    $metaai = [
        'summary' => \local_ai_course_assistant\meta_ai_data_builder::build_stats_summary($courseid, $since),
        'transcript_excerpt' => \local_ai_course_assistant\meta_ai_data_builder::build_transcript($courseid, $since, 50000),
    ];
}

// Learning Radar query log: every admin query (ad-hoc + scheduled) and its
// response, paired by (conversationid, sequential timecreated). Each record
// is one query/response pair with provider, model, approximate token counts,
// and a `scheduled` flag distinguishing cron-driven runs from ad-hoc ones.
$radarwhere = "interaction_type IN ('meta', 'meta_scheduled')";
$radarparams = [];
if ($since > 0) {
    $radarwhere .= ' AND timecreated >= :since';
    $radarparams['since'] = $since;
}
// Sort by `id` (auto-increment sequence) rather than `timecreated`. Two
// queries persisted within the same wall-clock second would otherwise
// interleave (all `user` rows before all `assistant` rows when sharing a
// timestamp), and the pairing walk below would cross-pair them. `id` is
// monotonic at insertion and preserves insertion order regardless of
// timestamp ties.
$radarrecords = $wants('learning_radar_queries') ? $DB->get_records_sql(
    "SELECT id, conversationid, userid, role, message, prompt_tokens, completion_tokens,
            model_name, provider, interaction_type, timecreated
       FROM {local_ai_course_assistant_msgs}
      WHERE {$radarwhere}
      ORDER BY conversationid ASC, id ASC",
    $radarparams
) : [];

$radarpairs = [];
$pendinguser = null;
foreach ($radarrecords as $row) {
    if ($row->role === 'user') {
        $pendinguser = $row;
        continue;
    }
    if (
        $row->role === 'assistant' && $pendinguser !== null
            && (int) $pendinguser->conversationid === (int) $row->conversationid
    ) {
        // The person here is the admin who ran the Learning Radar query rather
        // than a learner, but it is still a real user id and was emitted raw
        // regardless of the anonymize flag. Same helper, same gate.
        $radarpairs[] = ['id' => (int) $row->id]
            + redash_export_request::learner_identity((int) $row->userid, $anonymize)
            + [
            'query'             => (string) $pendinguser->message,
            'response'          => (string) $row->message,
            'provider'          => $row->provider,
            'model'             => $row->model_name,
            'prompt_tokens'     => (int) ($pendinguser->prompt_tokens ?? 0),
            'completion_tokens' => (int) ($row->completion_tokens ?? 0),
            'scheduled'         => $row->interaction_type === 'meta_scheduled',
            'asked_at'          => (int) $pendinguser->timecreated,
            'answered_at'       => (int) $row->timecreated,
        ];
        $pendinguser = null;
    }
}

// Build response. `sections` and `since` are echoed back so a consumer can
// assert that the endpoint actually honoured its allow-list: a site still
// running a pre-sections version of the plugin ignores the parameter and
// returns everything, and the echoed values are how that is detected.
$response = [
    'generated_at' => date('c'),
    'plugin_version' => $pluginversion,
    'anonymized' => $anonymize,
    'sections' => $sections,
    'since' => $since,
];

// Only emit the sections that were requested. `student_usage` and `hotspots`
// are nested inside each course row, so they have no top-level key here.
$payload = [
    'courses' => $courses,
    'feedback' => $feedback,
    'token_costs' => $tokencosts,
    'survey_responses' => $surveydata,
    'meta_ai' => $metaai,
    'learning_radar_queries' => $radarpairs,
];
foreach ($payload as $key => $value) {
    if ($wants($key)) {
        $response[$key] = $value;
    }
}

if ($outputformat === 'csv') {
    // One CSV holds one table. Emit the first requested dataset that has rows,
    // preferring `courses`, and say in the filename which one it was so a
    // download is never ambiguous about what it contains.
    $chosenkey = null;
    $chosenrows = [];
    $order = array_merge(['courses'], array_keys($payload));
    foreach ($order as $key) {
        if (!array_key_exists($key, $response) || !is_array($response[$key]) || empty($response[$key])) {
            continue;
        }
        $chosenkey = $key;
        $chosenrows = $response[$key];
        break;
    }

    $filename = clean_filename('sola-' . ($chosenkey ?? 'export') . '-' . date('Y-m-d')) . '.csv';
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    if ($chosenkey === null) {
        echo "no_rows\n";
        exit;
    }

    // Flatten one level: a nested array becomes compact JSON in its cell rather
    // than "Array", which is what naive casting produces.
    $flatten = static function (array $row): array {
        $out = [];
        foreach ($row as $k => $v) {
            $out[$k] = is_scalar($v) || $v === null
                ? (string) $v
                : json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        return $out;
    };

    $rows = array_map($flatten, array_map(static function ($r) {
        return (array) $r;
    }, array_values($chosenrows)));

    $handle = fopen('php://output', 'w');
    fputcsv($handle, array_keys($rows[0]));
    foreach ($rows as $row) {
        // Same formula-injection escaping as the transcript report: learner text
        // reaches these cells, so a leading = + - @ would execute in Excel.
        fputcsv($handle, array_map(
            [\local_ai_course_assistant\transcript_report::class, 'csv_cell'],
            array_values($row)
        ));
    }
    fclose($handle);
    exit;
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
