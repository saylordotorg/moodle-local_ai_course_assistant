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

        // Every key the return structure declares, including the two added for the
        // program-outcomes panel. A declared key that is ABSENT and VALUE_REQUIRED
        // makes clean_returnvalue() throw, and this early return fires on every
        // course with mastery disabled, which is most of them, so omitting one here
        // would break the common path rather than the rare one.
        //
        // The other direction is not symmetric, whatever this comment used to say:
        // an UNDECLARED key is silently dropped, never rejected. Both rules are
        // pinned in external_return_semantics_test.
        // The program-outcomes panel is a SEPARATE question from course mastery and
        // is answered before the mastery gate rather than after it. A course can
        // have program outcomes in Outcome Map and have SOLA's own objectives
        // switched off; suppressing the panel in that case would hide a learner's
        // degree progress because of an unrelated setting on one course. The
        // browser has always had a branch for "mastery off, programs present"; for
        // one release the server could not produce it.
        $programs = \local_ai_course_assistant\outcomemap_bridge::course_panel(
            (int) $USER->id,
            (int) $params['courseid']
        );

        $empty = [
            'enabled' => false,
            'total' => 0,
            'mastered' => 0,
            'learning' => 0,
            'not_started' => 0,
            'objectives' => [],
            'showprograms' => $programs !== null,
            'programs' => $programs ?? [],
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
        // $programs was resolved above the mastery gate; see the note there. It is
        // rendered as its own panel, not folded into the objectives above, and is
        // null unless this learner has attainment in a program this course
        // contributes to, so a course with no outcome mapping renders nothing
        // rather than an empty box.
        return [
            'enabled' => true,
            'total' => (int) $summary['total'],
            'mastered' => (int) $summary['mastered'],
            'learning' => (int) $summary['learning'],
            'not_started' => (int) $summary['not_started'],
            'objectives' => $rows,
            'showprograms' => $programs !== null,
            'programs' => $programs ?? [],
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
            // Declared, not optional. external_single_structure throws
            // invalid_parameter_exception on any key it was not told about, on an
            // ajax endpoint, AFTER the work is done. v7.5.1 shipped exactly that
            // defect by returning max_score without declaring it, which broke every
            // successful scoring call in production.
            'showprograms' => new external_value(PARAM_BOOL, 'Whether to render the program outcomes panel'),
            'programs' => new external_multiple_structure(
                new external_single_structure([
                    'code' => new external_value(PARAM_RAW, 'Program code'),
                    'name' => new external_value(PARAM_RAW, 'Program name'),
                    'outcomes' => new external_multiple_structure(
                        new external_single_structure([
                            'itemid' => new external_value(PARAM_INT, 'Stable outcome item id'),
                            'code' => new external_value(PARAM_RAW, 'Outcome code, e.g. PLO1'),
                            'statement' => new external_value(PARAM_RAW, 'Outcome statement'),
                            'shortstatement' => new external_value(PARAM_RAW, 'Short form of the statement'),
                            'state' => new external_value(PARAM_ALPHAEXT, 'calculated or a state carrying no figure'),
                            // NULL_ALLOWED is the point. Only a calculated result has
                            // a number; 525 of 546 rows on the production site do not.
                            // A zero here would tell a learner they failed an outcome
                            // nobody has measured.
                            'percent' => new external_value(
                                PARAM_FLOAT,
                                'Attainment percentage, null unless the state is calculated',
                                VALUE_REQUIRED,
                                null,
                                NULL_ALLOWED
                            ),
                            'explanation' => new external_value(
                                PARAM_RAW,
                                'Plain-language reason there is no figure; empty when calculated'
                            ),
                            'expectedpercent' => new external_value(
                                PARAM_FLOAT,
                                'Expected threshold, or null',
                                VALUE_REQUIRED,
                                null,
                                NULL_ALLOWED
                            ),
                            'strongpercent' => new external_value(
                                PARAM_FLOAT,
                                'Strong threshold, or null',
                                VALUE_REQUIRED,
                                null,
                                NULL_ALLOWED
                            ),
                            'coursesassessed' => new external_value(PARAM_INT, 'Courses contributing evidence'),
                            'coursestotal' => new external_value(PARAM_INT, 'Courses the program promises'),
                            'gradeditems' => new external_value(PARAM_INT, 'Distinct graded items'),
                            'timecalculated' => new external_value(PARAM_INT, 'Last calculation time, 0 if none'),
                        ])
                    ),
                ])
            ),
        ]);
    }
}
