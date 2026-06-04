<?php
// This file is part of Moodle - http://moodle.org/

namespace local_ai_course_assistant\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_course_assistant\program\learning_path;

/**
 * External function returning the learner's program learning path for a course.
 *
 * Powers the v5.9.0 path-map panel. Reads only the requesting learner's own
 * allocation via {@see learning_path::full_path()}; returns an empty path
 * (has_path=false) when no program applies or the feature is off.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_learning_path extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    public static function execute(int $courseid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        $empty = ['has_path' => false, 'program_name' => '', 'index' => 0, 'total' => 0, 'courses' => []];
        $model = (new learning_path())->full_path((int) $USER->id, (int) $params['courseid']);
        if ($model === null) {
            return $empty;
        }

        $courses = array_map(static function (array $c): array {
            return [
                'courseid' => (int) $c['courseid'],
                'name' => (string) $c['name'],
                'position' => (int) $c['position'],
                'status' => (string) $c['status'],
                'ordered' => (bool) $c['ordered'],
                'is_current' => (bool) $c['is_current'],
                'objectives' => array_map(static function (array $o): array {
                    return ['title' => (string) $o['title'], 'mastery' => (string) $o['mastery']];
                }, $c['objectives']),
            ];
        }, $model['courses']);

        return [
            'has_path' => true,
            'program_name' => (string) $model['program']['name'],
            'index' => (int) $model['position']['index'],
            'total' => (int) $model['position']['total'],
            'courses' => $courses,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'has_path' => new external_value(PARAM_BOOL, 'Whether a program path applies'),
            'program_name' => new external_value(PARAM_TEXT, 'Program name'),
            'index' => new external_value(PARAM_INT, '1-based position of the current course'),
            'total' => new external_value(PARAM_INT, 'Total visible courses in the program'),
            'courses' => new external_multiple_structure(new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Course id'),
                'name' => new external_value(PARAM_TEXT, 'Course name'),
                'position' => new external_value(PARAM_INT, '1-based position'),
                'status' => new external_value(PARAM_ALPHA, 'done | current | upcoming'),
                'ordered' => new external_value(PARAM_BOOL, 'Sits in an ordered sequence'),
                'is_current' => new external_value(PARAM_BOOL, 'Is the current course'),
                'objectives' => new external_multiple_structure(new external_single_structure([
                    'title' => new external_value(PARAM_TEXT, 'Objective title'),
                    'mastery' => new external_value(PARAM_ALPHAEXT,
                        'mastered | in_progress | not_started | demonstrated_elsewhere'),
                ])),
            ])),
        ]);
    }
}
