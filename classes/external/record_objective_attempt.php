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
use local_ai_course_assistant\objective_manager;

/**
 * Record a single mastery attempt from a quiz answer.
 *
 * Invoked by the frontend each time a student answers a quiz question.
 * Silently no-ops if mastery is disabled for the course or if the
 * objective id doesn't belong to this course, so the client can call
 * unconditionally without branching.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_objective_attempt extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'    => new external_value(PARAM_INT, 'Course ID'),
            'objectiveid' => new external_value(PARAM_INT, 'Learning objective ID'),
            'iscorrect'   => new external_value(PARAM_BOOL, 'Whether the student answered correctly'),
        ]);
    }

    public static function execute(int $courseid, int $objectiveid, bool $iscorrect): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'objectiveid' => $objectiveid,
            'iscorrect' => $iscorrect,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        if (!objective_manager::is_enabled_for_course((int) $params['courseid'])) {
            return ['recorded' => false, 'status' => 'not_started', 'score' => 0.0];
        }

        // Confirm the objective exists and belongs to this course. An
        // id mismatch means a hallucinated or stale reference — silently drop.
        $obj = objective_manager::get((int) $params['objectiveid']);
        if (!$obj || (int) $obj->courseid !== (int) $params['courseid']) {
            return ['recorded' => false, 'status' => 'not_started', 'score' => 0.0];
        }

        objective_manager::record_attempt(
            (int) $USER->id,
            (int) $params['courseid'],
            (int) $params['objectiveid'],
            (bool) $params['iscorrect'],
            'quiz',
            1.0
        );

        $mastery = objective_manager::compute_mastery((int) $USER->id, (int) $params['objectiveid']);
        return [
            'recorded' => true,
            'status' => $mastery['status'],
            'score' => (float) $mastery['score'],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'recorded' => new external_value(PARAM_BOOL, 'Whether the attempt was recorded'),
            'status'   => new external_value(PARAM_ALPHAEXT, 'Current mastery status for this objective'),
            'score'    => new external_value(PARAM_FLOAT, 'Current mastery score 0..1'),
        ]);
    }
}
