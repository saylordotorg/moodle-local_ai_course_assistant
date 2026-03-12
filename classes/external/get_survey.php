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
use local_ai_course_assistant\survey_manager;

/**
 * External function to get the active survey for a course.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_survey extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Get the active survey for a course.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        $survey = survey_manager::get_active_survey($params['courseid']);

        if (!$survey) {
            return [
                'has_survey' => false,
                'survey_id' => 0,
                'title' => '',
                'questions' => '',
                'already_responded' => false,
            ];
        }

        $responded = survey_manager::has_user_responded(
            $survey->id,
            $USER->id,
            $params['courseid']
        );

        return [
            'has_survey' => true,
            'survey_id' => (int) $survey->id,
            'title' => $survey->title,
            'questions' => json_encode($survey->questions),
            'already_responded' => $responded,
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'has_survey' => new external_value(PARAM_BOOL, 'Whether an active survey exists'),
            'survey_id' => new external_value(PARAM_INT, 'Survey ID (0 if none)'),
            'title' => new external_value(PARAM_TEXT, 'Survey title'),
            'questions' => new external_value(PARAM_RAW, 'JSON-encoded array of question definitions'),
            'already_responded' => new external_value(PARAM_BOOL, 'Whether the user has already responded'),
        ]);
    }
}
