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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_course_assistant\objective_manager;

/**
 * Return the current mastery summary for the caller in a course.
 *
 * Used to populate the compact Learning Mastery Chip in the widget.
 * Returns an empty payload when mastery isn't enabled so the client
 * can call unconditionally without branching.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_mastery_summary extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function execute(int $courseid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        $empty = [
            'enabled' => false,
            'total' => 0,
            'mastered' => 0,
            'learning' => 0,
            'not_started' => 0,
            'objectives' => [],
        ];

        if (!objective_manager::is_enabled_for_course((int) $params['courseid'])) {
            return $empty;
        }

        $summary = objective_manager::compute_course_summary((int) $USER->id, (int) $params['courseid']);
        $rows = [];
        foreach ($summary['objectives'] as $row) {
            $obj = $row['objective'];
            $m = $row['mastery'];
            $rows[] = [
                'id' => (int) $obj->id,
                'code' => (string) ($obj->code ?? ''),
                'title' => (string) $obj->title,
                'status' => (string) $m['status'],
                'last' => (int) $m['last'],
            ];
        }
        return [
            'enabled' => true,
            'total' => (int) $summary['total'],
            'mastered' => (int) $summary['mastered'],
            'learning' => (int) $summary['learning'],
            'not_started' => (int) $summary['not_started'],
            'objectives' => $rows,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'enabled' => new external_value(PARAM_BOOL, 'Whether mastery is enabled for this course'),
            'total' => new external_value(PARAM_INT, 'Total objectives'),
            'mastered' => new external_value(PARAM_INT, 'Mastered count'),
            'learning' => new external_value(PARAM_INT, 'Learning (in-progress) count'),
            'not_started' => new external_value(PARAM_INT, 'Not-started count'),
            'objectives' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Objective id'),
                    'code' => new external_value(PARAM_RAW, 'Short code or empty'),
                    'title' => new external_value(PARAM_RAW, 'Objective title'),
                    'status' => new external_value(PARAM_ALPHAEXT, 'not_started|learning|mastered'),
                    'last' => new external_value(PARAM_INT, 'Timestamp of last attempt, 0 if none'),
                ])
            ),
        ]);
    }
}
