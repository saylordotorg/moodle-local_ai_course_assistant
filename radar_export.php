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
use local_ai_course_assistant\branding;

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
        // Print-friendly view — the admin uses the browser's "Save as PDF".
        // v7.0.1: rendered through the Output API on an embedded page layout
        // instead of echoing a detached document with an inline <style> block,
        // so the markup lives in a template and the rules live in styles.css.
        $metarows = [];
        foreach ($meta as $k => $v) {
            $metarows[] = ['key' => (string) $k, 'value' => (string) $v];
        }
        $templatedata = [
            'title' => branding::str('radar_report:title'),
            'generated' => get_string(
                'radar_report:generated',
                'local_ai_course_assistant',
                userdate(time(), '%Y-%m-%d %H:%M')
            ),
            'printlabel' => get_string('radar_report:print', 'local_ai_course_assistant'),
            'querylabel' => get_string('radar_report:query', 'local_ai_course_assistant'),
            'responselabel' => get_string('radar_report:response', 'local_ai_course_assistant'),
            'privacynote' => get_string('radar_report:privacy_note', 'local_ai_course_assistant'),
            'query' => $query,
            'response' => $response,
            'hasmeta' => !empty($metarows),
            'meta' => $metarows,
        ];

        $PAGE->set_context($syscontext);
        $PAGE->set_url(new moodle_url('/local/ai_course_assistant/radar_export.php'));
        $PAGE->set_pagelayout('embedded');
        $PAGE->set_title($templatedata['title']);
        echo $OUTPUT->header();
        echo $OUTPUT->render_from_template(
            'local_ai_course_assistant/radar_report',
            $templatedata
        );
        echo $OUTPUT->footer();
        return;
    }

    $payload = radar_delivery::format($format, $query, $response, $meta);
    [$filename, $contenttype] = radar_delivery::format_meta($format);
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
