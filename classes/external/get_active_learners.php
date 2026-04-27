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

/**
 * v4.1 / F3 — Active-learners-online indicator.
 *
 * Returns the count of *other* learners (excluding the calling user) who
 * have sent a SOLA chat message on this course in the last 15 minutes.
 * Aggregate-only, no names ever cross the wire.
 *
 * Designed to make a learner feel less alone without exposing identities
 * or letting anyone enumerate enrolment. The UI suppresses the indicator
 * entirely when the count is 0 or 1 — a "0 others studying" badge would
 * undercut the whole point.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_active_learners extends external_api {

    /** @var int Active window in seconds (15 minutes). */
    private const ACTIVE_WINDOW_SECS = 15 * 60;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    public static function execute(int $courseid): array {
        global $DB, $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $courseid = (int) $params['courseid'];

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        $since = time() - self::ACTIVE_WINDOW_SECS;
        $count = (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT userid)
               FROM {local_ai_course_assistant_msgs}
              WHERE courseid = :courseid
                AND timecreated >= :since
                AND userid <> :selfid",
            [
                'courseid' => $courseid,
                'since' => $since,
                'selfid' => (int) $USER->id,
            ]
        );

        return [
            'count' => $count,
            'window_secs' => self::ACTIVE_WINDOW_SECS,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'Count of other learners active in the window'),
            'window_secs' => new external_value(PARAM_INT, 'Active window length in seconds'),
        ]);
    }
}
