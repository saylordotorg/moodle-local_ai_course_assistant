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
use local_ai_course_assistant\usertesting_manager;

/**
 * External function to submit a user testing task response.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_usertesting_response extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'tasksetid' => new external_value(PARAM_INT, 'Task set ID'),
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'task_index' => new external_value(PARAM_INT, 'Task index within the set'),
            'rating' => new external_value(PARAM_INT, 'Rating value (0 if N/A)', VALUE_DEFAULT, 0),
            'answer' => new external_value(PARAM_RAW, 'Free text answer', VALUE_DEFAULT, ''),
            'message_count' => new external_value(PARAM_INT, 'Messages sent this session', VALUE_DEFAULT, 0),
            'session_minutes' => new external_value(PARAM_INT, 'Minutes in session', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $tasksetid,
        int $courseid,
        int $task_index,
        int $rating = 0,
        string $answer = '',
        int $message_count = 0,
        int $session_minutes = 0
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'tasksetid' => $tasksetid,
            'courseid' => $courseid,
            'task_index' => $task_index,
            'rating' => $rating,
            'answer' => $answer,
            'message_count' => $message_count,
            'session_minutes' => $session_minutes,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        usertesting_manager::save_response(
            $params['tasksetid'],
            $USER->id,
            $params['courseid'],
            $params['task_index'],
            $params['rating'] > 0 ? $params['rating'] : null,
            $params['answer'],
            $params['message_count'],
            $params['session_minutes']
        );

        return ['success' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the response was saved'),
        ]);
    }
}
