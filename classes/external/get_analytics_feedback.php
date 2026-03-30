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
 * Get combined feedback analytics data.
 *
 * Returns message rating summary, negative feedback details,
 * feedback survey summary, and messages-to-resolution data.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_analytics_feedback extends external_api {

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

        // Message rating summary.
        if (method_exists(analytics::class, 'get_message_rating_summary')) {
            $result['rating_summary'] = analytics::get_message_rating_summary($courseid, $since);
        } else {
            $result['rating_summary'] = [];
        }

        // Negative feedback details.
        if (method_exists(analytics::class, 'get_negative_feedback_details')) {
            $result['negative_feedback'] = analytics::get_negative_feedback_details($courseid, $since);
        } else {
            $result['negative_feedback'] = [];
        }

        // Feedback survey summary.
        if (method_exists(analytics::class, 'get_feedback_survey_summary')) {
            $result['survey_summary'] = analytics::get_feedback_survey_summary($courseid, $since);
        } else {
            $result['survey_summary'] = [];
        }

        // Messages to resolution.
        if (method_exists(analytics::class, 'get_messages_to_resolution')) {
            $result['messages_to_resolution'] = analytics::get_messages_to_resolution($courseid, $since);
        } else {
            $result['messages_to_resolution'] = [];
        }

        return ['data' => json_encode($result)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'data' => new external_value(PARAM_RAW, 'JSON-encoded feedback analytics data'),
        ]);
    }
}
