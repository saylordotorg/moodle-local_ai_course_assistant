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

/**
 * v4.1 / F1 — Thin wrapper around next_best_action::recommend(). Single
 * structured recommendation source for the chat focus-next starter, the
 * weekly digest task, and any third-party integration.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_next_best_action extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'count'    => new external_value(PARAM_INT, 'Max recommendations to return', VALUE_DEFAULT, 3),
        ]);
    }

    public static function execute(int $courseid, int $count = 3): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'count'    => $count,
        ]);
        $courseid = (int) $params['courseid'];
        $count = (int) $params['count'];

        $coursecontext = \context_course::instance($courseid);
        self::validate_context($coursecontext);
        require_capability('local/ai_course_assistant:use', $coursecontext);

        $recs = \local_ai_course_assistant\next_best_action::recommend((int) $USER->id, $courseid, $count);

        // Coerce nullable strings to '' for the external_value scaffolding —
        // PARAM_URL / PARAM_TEXT can't be null at the wire format.
        $out = [];
        foreach ($recs as $r) {
            $out[] = [
                'objectiveid' => (int) $r['objectiveid'],
                'title'       => (string) $r['title'],
                'status'      => (string) $r['status'],
                'score'       => (float) $r['score'],
                'action'      => (string) $r['action'],
                'suggestion'  => (string) $r['suggestion'],
                'moduleurl'   => (string) ($r['moduleurl'] ?? ''),
                'modulename'  => (string) ($r['modulename'] ?? ''),
            ];
        }
        return [
            'success'         => true,
            'recommendations' => $out,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the call succeeded'),
            'recommendations' => new external_multiple_structure(
                new external_single_structure([
                    'objectiveid' => new external_value(PARAM_INT, 'Objective id'),
                    'title'       => new external_value(PARAM_TEXT, 'Objective title (with optional [code] prefix)'),
                    'status'      => new external_value(PARAM_ALPHAEXT, 'not_started or learning'),
                    'score'       => new external_value(PARAM_FLOAT, 'Adjusted mastery score, 0.0–1.0'),
                    'action'      => new external_value(PARAM_ALPHAEXT,
                        'Recommended action: get_started, review, practice, or quiz'),
                    'suggestion'  => new external_value(PARAM_TEXT, 'One-line natural-language nudge'),
                    'moduleurl'   => new external_value(PARAM_URL, 'Best-fit module URL or empty string'),
                    'modulename'  => new external_value(PARAM_TEXT, 'Best-fit module name or empty string'),
                ])
            ),
        ]);
    }
}
