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
 * Manual Learning Radar response export endpoint.
 *
 * Admin-only. Accepts a query+response pair and either streams it back as
 * a file in the requested format (download) or pushes it to email/Slack/
 * Teams. The PDF format produces a printable HTML page rather than a
 * binary PDF — the browser's print-to-PDF handles the actual conversion,
 * which avoids dragging in TCPDF for a feature most admins use rarely.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\radar_delivery;

require_login();
$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);
require_sesskey();

$action = required_param('action', PARAM_ALPHA);
$format = optional_param('format', 'text', PARAM_ALPHA);
$query = required_param('query', PARAM_RAW);
$response = required_param('response', PARAM_RAW);
$metaraw = optional_param('meta', '{}', PARAM_RAW);

$meta = json_decode($metaraw, true);
if (!is_array($meta)) {
    $meta = [];
}

if ($action === 'download') {
    if ($format === 'pdf') {
        // Print-friendly HTML view — user uses browser "Save as PDF".
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $when = userdate(time(), '%Y-%m-%d %H:%M');
        $qsafe = s($query);
        $rsafe = nl2br(s($response));
        $msafe = '';
        foreach ($meta as $k => $v) {
            $msafe .= '<dt>' . s((string) $k) . '</dt><dd>' . s((string) $v) . '</dd>';
        }
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        echo '<title>SOLA Learning Radar Report</title>';
        echo '<style>body{font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:780px;margin:24px auto;padding:0 16px;color:#222}';
        echo 'h1{font-size:20px;margin:0 0 4px}h2{font-size:16px;margin:24px 0 8px}';
        echo 'dl{display:grid;grid-template-columns:140px 1fr;gap:4px 12px;font-size:13px;color:#555}';
        echo 'dt{font-weight:600}.q{background:#f4f6f9;padding:12px 14px;border-radius:6px;white-space:pre-wrap}';
        echo '.r{padding:12px 0}.foot{margin-top:32px;font-size:12px;color:#888;border-top:1px solid #e5e7eb;padding-top:12px}';
        echo '@media print{body{margin:0}.noprint{display:none}}';
        echo '</style></head><body>';
        echo '<button class="noprint" onclick="window.print()">Print / save as PDF</button>';
        echo '<h1>SOLA Learning Radar Report</h1>';
        echo '<p style="color:#666;margin:0 0 12px">Generated ' . s($when) . '</p>';
        if ($msafe !== '') {
            echo '<dl>' . $msafe . '</dl>';
        }
        echo '<h2>Query</h2><div class="q">' . $qsafe . '</div>';
        echo '<h2>Response</h2><div class="r">' . $rsafe . '</div>';
        echo '<div class="foot">All student data in this report is anonymized. Do not share publicly.</div>';
        echo '</body></html>';
        return;
    }

    $payload = radar_delivery::format($format, $query, $response, $meta);
    list($filename, $contenttype) = radar_delivery::format_meta($format);
    header('Content-Type: ' . $contenttype);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($payload));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $payload;
    return;
}

// Send actions return JSON to the AMD module.
header('Content-Type: application/json; charset=utf-8');

if ($action === 'email') {
    $to = required_param('to', PARAM_EMAIL);
    if ($to === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Recipient email is required']);
        return;
    }
    $sent = radar_delivery::send_email($to, $query, $response, $format, 'On-demand', $meta);
    echo json_encode(['ok' => $sent]);
    return;
}

if ($action === 'slack') {
    $url = required_param('webhook', PARAM_URL);
    if ($url === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Slack webhook URL is required']);
        return;
    }
    $sent = radar_delivery::send_slack($url, $query, $response, $meta);
    echo json_encode(['ok' => $sent]);
    return;
}

if ($action === 'teams') {
    $url = required_param('webhook', PARAM_URL);
    if ($url === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Teams webhook URL is required']);
        return;
    }
    $sent = radar_delivery::send_teams($url, $query, $response, $meta);
    echo json_encode(['ok' => $sent]);
    return;
}

// v4.3.0: push the query/response to Redash as a saved Redash query, using
// the admin-configured base URL + user API key + data source id. Returns the
// new Redash query URL so the caller can open it in a new tab.
if ($action === 'push_redash') {
    $name = optional_param('name', '', PARAM_TEXT);
    if ($name === '') {
        $name = 'SOLA Learning Radar — ' . userdate(time(), '%Y-%m-%d %H:%M');
    }
    $result = \local_ai_course_assistant\redash_client::push_query($name, $query, $response);
    if (!$result['ok']) {
        http_response_code(400);
        echo json_encode($result);
        return;
    }
    echo json_encode($result);
    return;
}

// v4.3.0: return the configured Redash setup details so the AMD module can
// render the Setup Redash helper without re-fetching settings via webservice.
if ($action === 'redash_setup') {
    $configured = \local_ai_course_assistant\redash_client::is_configured();
    $pullurl = (new moodle_url('/local/ai_course_assistant/redash_export.php', [
        'apikey' => get_config('local_ai_course_assistant', 'redash_api_key') ?: '',
    ]))->out(false);
    echo json_encode([
        'ok' => true,
        'configured' => $configured,
        'base_url' => (string) (get_config('local_ai_course_assistant', 'redash_base_url') ?: ''),
        'data_source_id' => (int) (get_config('local_ai_course_assistant', 'redash_data_source_id') ?: 0),
        'pull_url' => $pullurl,
        'has_redash_api_key' => !empty(get_config('local_ai_course_assistant', 'redash_api_key')),
    ]);
    return;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
