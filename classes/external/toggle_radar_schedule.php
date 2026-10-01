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
 * Pause or resume a Learning Radar schedule.
 *
 * Replaces the `action=toggle` branch of the former radar_schedule.php
 * AJAX_SCRIPT endpoint. Site-admin only, at system context, exactly as that
 * endpoint was.
 *
 * The whole row is written back through radar_schedule_manager::save() rather
 * than set_field on one column, which is what the endpoint did: save() is what
 * stamps timemodified, and range_days has to travel as null (absent, not '')
 * so the "site default" case is not flattened to 0.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class toggle_radar_schedule extends external_api {
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Schedule id to toggle'),
            'enabled' => new external_value(PARAM_BOOL, 'New enabled state'),
        ]);
    }

    /**
     * Toggle a schedule on or off.
     *
     * @param int $id Schedule id.
     * @param bool $enabled New enabled state.
     * @return array
     * @throws \moodle_exception When no schedule carries that id.
     */
    public static function execute(int $id, bool $enabled): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'id' => $id,
            'enabled' => $enabled,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $row = radar_schedule_manager::get($params['id']);
        if (!$row) {
            throw new \moodle_exception('radar:err_schedule_not_found', 'local_ai_course_assistant');
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
            'enabled' => $params['enabled'] ? 1 : 0,
        ], (int) $USER->id);

        return ['status' => true];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Always true when the toggle ran'),
        ]);
    }
}
