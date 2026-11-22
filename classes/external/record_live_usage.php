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
use local_ai_course_assistant\voice\gemini_live;

/**
 * Record how long a Gemini Live session ran and the tokens Google reported for it (8.2.0).
 *
 * The browser is the only party that sees the Live API's usageMetadata (the session runs browser to Google), so it
 * reports the totals here when the session ends. The report lands on the session row get_realtime_token wrote at
 * mint time, once, and is clamped: the length to the session's cap and the time since the mint, the token counts to
 * a sane range. A browser that never reports still counts its full cap against the learner's daily allowance.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_live_usage extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sessionid' => new external_value(PARAM_INT, 'Session id returned by get_realtime_token'),
            'durationsec' => new external_value(PARAM_INT, 'Session length in seconds', VALUE_DEFAULT, 0),
            'prompttokens' => new external_value(PARAM_INT, 'Input tokens Google reported', VALUE_DEFAULT, 0),
            'responsetokens' => new external_value(PARAM_INT, 'Output tokens Google reported', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Record the usage.
     *
     * @param int $courseid
     * @param int $sessionid
     * @param int $durationsec
     * @param int $prompttokens
     * @param int $responsetokens
     * @return array ['recorded' => bool]
     */
    public static function execute(int $courseid, int $sessionid, int $durationsec = 0, int $prompttokens = 0,
            int $responsetokens = 0): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sessionid' => $sessionid,
            'durationsec' => $durationsec,
            'prompttokens' => $prompttokens,
            'responsetokens' => $responsetokens,
        ]);
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        // One report per session; a loop gets nothing but this refusal.
        if (\local_ai_course_assistant\rate_limiter::is_rate_limited((int) $USER->id, 'live_usage', 12, 300)) {
            return ['recorded' => false];
        }
        $ok = gemini_live::close_session(
            (int) $params['sessionid'],
            (int) $USER->id,
            (int) $params['durationsec'],
            (int) $params['prompttokens'],
            (int) $params['responsetokens']
        );
        return ['recorded' => $ok];
    }

    /**
     * Result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'recorded' => new external_value(PARAM_BOOL, 'Whether the report was recorded'),
        ]);
    }
}
