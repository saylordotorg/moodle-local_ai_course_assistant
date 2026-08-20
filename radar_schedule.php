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
 * Learning Radar schedule CRUD endpoint.
 *
 * Admin-only. Accepts POST {action=save|delete|toggle} for write operations
 * and GET {action=list|get} for reads. Used by the analytics dashboard's
 * schedule modal.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_ai_course_assistant\radar_schedule_manager;

require_login();
$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

$action = required_param('action', PARAM_ALPHA);

try {
    switch ($action) {
        case 'list':
            $rows = radar_schedule_manager::all(false);
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'id' => (int) $r->id,
                    'name' => $r->name,
                    'query' => $r->query,
                    'provider' => $r->provider,
                    'model' => $r->model,
                    'frequency' => $r->frequency,
                    'recipient_email' => $r->recipient_email,
                    'slack_webhook' => $r->slack_webhook,
                    'teams_webhook' => $r->teams_webhook,
                    'format' => $r->format,
                    'courseids' => $r->courseids,
                    'filterprovider' => $r->filterprovider,
                    'range_days' => $r->range_days !== null ? (int) $r->range_days : null,
                    'enabled' => (int) $r->enabled,
                    'last_run' => $r->last_run !== null ? (int) $r->last_run : null,
                    'last_status' => $r->last_status,
                    'last_error' => $r->last_error,
                ];
            }
            echo json_encode(['ok' => true, 'schedules' => $out]);
            return;

        case 'get':
            $id = required_param('id', PARAM_INT);
            $row = radar_schedule_manager::get($id);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Schedule not found']);
                return;
            }
            echo json_encode(['ok' => true, 'schedule' => $row]);
            return;

        case 'save':
            // Blank means "no explicit range" (stored as NULL = site default), so
            // this cannot be read as PARAM_INT, which would coerce '' to 0. Read
            // it raw, keep the blank/non-blank distinction, and clean the
            // non-blank case to an integer explicitly.
            $rangedaysraw = optional_param('range_days', '', PARAM_RAW_TRIMMED);
            $rangedays = $rangedaysraw === '' ? '' : (string) clean_param($rangedaysraw, PARAM_INT);
            $data = [
                'id' => optional_param('id', 0, PARAM_INT),
                'name' => optional_param('name', '', PARAM_TEXT),
                // PARAM_RAW is required: the scheduled Learning Radar query is
                // free-form analyst prose that routinely carries newlines,
                // quotes, LaTeX and fenced code blocks. It is stored verbatim,
                // sent to the LLM by the cron task, and returned to the admin UI
                // as JSON where the browser writes it into an input .value - it
                // is never interpolated into HTML.
                'query' => optional_param('query', '', PARAM_RAW),
                'provider' => optional_param('provider', '', PARAM_ALPHANUMEXT),
                // Vendor model slugs contain dots and slashes ("gemini-2.5-flash",
                // "meta-llama/Llama-3.1-8B-Instruct"), which PARAM_ALPHANUMEXT
                // would strip. PARAM_TEXT is lossless for those while stripping
                // markup; trim() preserves the previous trimming behaviour.
                'model' => trim(optional_param('model', '', PARAM_TEXT)),
                'frequency' => optional_param('frequency', 'weekly', PARAM_ALPHA),
                'recipient_email' => optional_param('recipient_email', '', PARAM_EMAIL),
                'slack_webhook' => optional_param('slack_webhook', '', PARAM_URL),
                'teams_webhook' => optional_param('teams_webhook', '', PARAM_URL),
                'format' => optional_param('format', 'text', PARAM_ALPHA),
                // Comma-separated course IDs (UI placeholder "2,5,12").
                'courseids' => optional_param('courseids', '', PARAM_SEQUENCE),
                'filterprovider' => optional_param('filterprovider', '', PARAM_ALPHANUMEXT),
                'range_days' => $rangedays,
                'enabled' => optional_param('enabled', 0, PARAM_INT),
            ];
            if ($data['name'] === '' || $data['query'] === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Name and query are required']);
                return;
            }
            $id = radar_schedule_manager::save($data, (int) $USER->id);
            echo json_encode(['ok' => true, 'id' => $id]);
            return;

        case 'delete':
            $id = required_param('id', PARAM_INT);
            radar_schedule_manager::delete($id);
            echo json_encode(['ok' => true]);
            return;

        case 'toggle':
            $id = required_param('id', PARAM_INT);
            $enabled = required_param('enabled', PARAM_INT) ? 1 : 0;
            $row = radar_schedule_manager::get($id);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Schedule not found']);
                return;
            }
            radar_schedule_manager::save([
                'id' => $row->id,
                'name' => $row->name,
                'query' => $row->query,
                'provider' => $row->provider,
                'model' => $row->model,
                'frequency' => $row->frequency,
                'recipient_email' => $row->recipient_email,
                'slack_webhook' => $row->slack_webhook,
                'teams_webhook' => $row->teams_webhook,
                'format' => $row->format,
                'courseids' => $row->courseids,
                'filterprovider' => $row->filterprovider,
                'range_days' => $row->range_days,
                'enabled' => $enabled,
            ], (int) $USER->id);
            echo json_encode(['ok' => true]);
            return;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
