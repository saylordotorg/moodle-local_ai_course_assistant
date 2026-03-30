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
 * Get overall analytics data (Tab 1 overview).
 *
 * Combines overview stats, enrollment counts, session stats, return rate,
 * time distribution, and daily usage into one response.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_analytics_overall extends external_api {

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

        // Overview stats.
        $result['overview'] = analytics::get_overview($courseid, $since);

        // Enrollment counts.
        if (method_exists(analytics::class, 'get_enrollment_counts')) {
            $result['enrollment'] = analytics::get_enrollment_counts($courseid, $since);
        } else {
            $result['enrollment'] = [];
        }

        // Session stats.
        if (method_exists(analytics::class, 'get_session_stats')) {
            $result['sessions'] = analytics::get_session_stats($courseid, $since);
        } else {
            $result['sessions'] = [];
        }

        // Return rate.
        if (method_exists(analytics::class, 'get_return_rate')) {
            $result['return_rate'] = analytics::get_return_rate($courseid, $since);
        } else {
            $result['return_rate'] = [];
        }

        // Time distribution.
        if (method_exists(analytics::class, 'get_time_distribution')) {
            $result['time_distribution'] = analytics::get_time_distribution($courseid, $since);
        } else {
            $result['time_distribution'] = [];
        }

        // Daily usage (last 30 days).
        $result['daily_usage'] = analytics::get_daily_usage($courseid);

        return ['data' => json_encode($result)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'data' => new external_value(PARAM_RAW, 'JSON-encoded analytics data'),
        ]);
    }
}
