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
 * External function to get the active user testing task set for a course.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_usertesting extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function execute(int $courseid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        $taskset = usertesting_manager::get_active_taskset($params['courseid']);

        if (!$taskset) {
            return [
                'has_taskset' => false,
                'taskset_id' => 0,
                'title' => '',
                'tasks' => '',
                'external_url' => '',
                'completed_count' => 0,
                'total_tasks' => 0,
            ];
        }

        $completed = usertesting_manager::get_completed_count(
            $taskset->id,
            $USER->id,
            $params['courseid']
        );

        return [
            'has_taskset' => true,
            'taskset_id' => (int) $taskset->id,
            'title' => $taskset->title,
            'tasks' => json_encode($taskset->tasks),
            'external_url' => $taskset->external_url ?: '',
            'completed_count' => $completed,
            'total_tasks' => count($taskset->tasks),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'has_taskset' => new external_value(PARAM_BOOL, 'Whether an active task set exists'),
            'taskset_id' => new external_value(PARAM_INT, 'Task set ID (0 if none)'),
            'title' => new external_value(PARAM_TEXT, 'Task set title'),
            'tasks' => new external_value(PARAM_RAW, 'JSON-encoded array of task definitions'),
            'external_url' => new external_value(PARAM_RAW, 'External form URL (blank if none)'),
            'completed_count' => new external_value(PARAM_INT, 'Number of tasks already completed by this user'),
            'total_tasks' => new external_value(PARAM_INT, 'Total number of tasks in the set'),
        ]);
    }
}
