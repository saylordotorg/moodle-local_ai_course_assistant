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

namespace local_ai_course_assistant\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_course_assistant\radar_schedule_manager;

/**
 * Load one Learning Radar schedule for the admin dashboard's edit modal.
 *
 * Replaces the `action=get` branch of the former radar_schedule.php
 * AJAX_SCRIPT endpoint. Site-admin only, at system context, exactly as that
 * endpoint was.
 *
 * Stored free-text columns are declared PARAM_RAW on the way out rather than
 * PARAM_EMAIL / PARAM_URL. external_value::validate() throws on a value that
 * does not survive validate_param(), and rows migrated from the legacy
 * metaai_cron_* settings by db/upgrade.php never passed through the save
 * endpoint's cleaning, so a typed return would make the edit modal throw on
 * exactly the oldest schedules an admin most needs to fix.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_radar_schedule extends external_api {
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Schedule id to load'),
        ]);
    }

    /**
     * Return one schedule.
     *
     * @param int $id Schedule id.
     * @return array
     * @throws \moodle_exception When no schedule carries that id.
     */
    public static function execute(int $id): array {
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $row = radar_schedule_manager::get($params['id']);
        if (!$row) {
            throw new \moodle_exception('radar:err_schedule_not_found', 'local_ai_course_assistant');
        }

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'query' => (string) $row->query,
            'provider' => (string) $row->provider,
            'model' => (string) $row->model,
            'frequency' => (string) $row->frequency,
            'recipient_email' => (string) $row->recipient_email,
            'slack_webhook' => (string) $row->slack_webhook,
            'teams_webhook' => (string) $row->teams_webhook,
            'format' => (string) $row->format,
            'courseids' => (string) $row->courseids,
            'filterprovider' => (string) $row->filterprovider,
            'range_days' => $row->range_days !== null ? (int) $row->range_days : null,
            'enabled' => (int) $row->enabled,
            'last_run' => $row->last_run !== null ? (int) $row->last_run : null,
            'last_status' => (string) $row->last_status,
            'last_error' => (string) $row->last_error,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Schedule id'),
            'name' => new external_value(PARAM_TEXT, 'Schedule name'),
            'query' => new external_value(PARAM_RAW, 'Analyst prose query run on the schedule'),
            'provider' => new external_value(PARAM_RAW, 'AI provider key'),
            'model' => new external_value(PARAM_RAW, 'Model slug, empty for the provider default'),
            'frequency' => new external_value(PARAM_ALPHA, 'daily | weekly | monthly'),
            'recipient_email' => new external_value(PARAM_RAW, 'Report recipient, empty if unset'),
            'slack_webhook' => new external_value(PARAM_RAW, 'Slack incoming webhook, empty if unset'),
            'teams_webhook' => new external_value(PARAM_RAW, 'Teams incoming webhook, empty if unset'),
            'format' => new external_value(PARAM_ALPHA, 'text | csv | json | markdown'),
            'courseids' => new external_value(PARAM_RAW, 'Comma-separated course ids, empty for all'),
            'filterprovider' => new external_value(PARAM_RAW, 'Provider filter, empty for none'),
            'range_days' => new external_value(PARAM_INT, 'Reporting window in days, null for the site default',
                VALUE_REQUIRED, null, NULL_ALLOWED),
            'enabled' => new external_value(PARAM_INT, '1 when the schedule runs'),
            'last_run' => new external_value(PARAM_INT, 'Timestamp of the last run, null if never run',
                VALUE_REQUIRED, null, NULL_ALLOWED),
            'last_status' => new external_value(PARAM_RAW, 'Status of the last run'),
            'last_error' => new external_value(PARAM_RAW, 'Error from the last run, empty on success'),
        ]);
    }
}
