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
 * External function to submit survey responses.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_survey_response extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'surveyid' => new external_value(PARAM_INT, 'Survey ID'),
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'answers' => new external_value(PARAM_RAW, 'JSON-encoded array of {question_index, answer}'),
        ]);
    }

    /**
     * Submit survey answers.
     *
     * @param int $surveyid
     * @param int $courseid
     * @param string $answers JSON-encoded answers.
     * @return array
     */
    public static function execute(int $surveyid, int $courseid, string $answers): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'surveyid' => $surveyid,
            'courseid' => $courseid,
            'answers' => $answers,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        $decoded = json_decode($params['answers'], true);
        if (!is_array($decoded)) {
            return ['success' => false];
        }

        // Validate each answer entry has the required keys.
        $clean = [];
        foreach ($decoded as $entry) {
            if (!isset($entry['question_index']) || !isset($entry['answer'])) {
                continue;
            }
            $clean[] = [
                'question_index' => (int) $entry['question_index'],
                'answer' => (string) $entry['answer'],
            ];
        }

        if (empty($clean)) {
            return ['success' => false];
        }

        survey_manager::save_response(
            $params['surveyid'],
            $USER->id,
            $params['courseid'],
            $clean
        );

        return ['success' => true];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the response was saved'),
        ]);
    }
}
