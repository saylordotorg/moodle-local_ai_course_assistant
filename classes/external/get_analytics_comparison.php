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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_course_assistant\analytics;

/**
 * Get AI users vs non-users comparison data.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_analytics_comparison extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID (0 = all courses)', VALUE_DEFAULT, 0),
            'since' => new external_value(PARAM_INT, 'Unix timestamp filter (0 = all time)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $courseid = 0, int $since = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'since' => $since,
        ]);
        $courseid = $params['courseid'];
        $since = $params['since'];

        // Admin-only.
        $syscontext = \context_system::instance();
        self::validate_context($syscontext);
        require_capability('moodle/site:config', $syscontext);

        $result = [];

        if (method_exists(analytics::class, 'get_ai_vs_nonusers')) {
            $result = analytics::get_ai_vs_nonusers($courseid, $since);
        }

        // Also include provider comparison data from existing method.
        if ($courseid > 0) {
            $result['provider_comparison'] = analytics::get_provider_comparison($courseid, $since);
        }

        return ['data' => json_encode($result)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'data' => new external_value(PARAM_RAW, 'JSON-encoded comparison data'),
        ]);
    }
}
