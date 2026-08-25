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

defined('MOODLE_INTERNAL') || die();

/**
 * Record that a learner opened the practice-quiz panel.
 *
 * v7.1.1. The Quiz Me starter is type 'quiz': clicking it opens a panel instead
 * of sending a message, so it produced no row anywhere and we could measure what
 * quiz generation cost but not how often the button was pressed. A press that
 * never reaches generation -- opened the panel, looked at it, closed it -- was
 * completely invisible, which is exactly the abandonment we wanted to see.
 *
 * Writes role='system' so conversation_manager::get_messages() keeps it out of
 * the learner's history and the model's context, and interaction_type
 * 'quiz_open' so it is countable without being mistaken for spend: the row
 * carries no tokens and no model, so spend_rows_predicate() ignores it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_quiz_open extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course the panel was opened in'),
            'cmid' => new external_value(PARAM_INT, 'Activity the learner was on, 0 if none', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Record the panel open.
     *
     * @param int $courseid
     * @param int $cmid
     * @return array
     */
    public static function execute(int $courseid, int $cmid = 0): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmid' => $cmid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        // Telemetry must never be a way to make the plugin do work on demand.
        if (\local_ai_course_assistant\rate_limiter::is_rate_limited(
                (int) $USER->id, 'quiz_open', 60, 60)) {
            return ['recorded' => false];
        }

        try {
            $conv = \local_ai_course_assistant\conversation_manager::get_or_create_conversation(
                (int) $USER->id,
                (int) $params['courseid']
            );

            $row = new \stdClass();
            $row->conversationid = (int) $conv->id;
            $row->userid = (int) $USER->id;
            $row->courseid = (int) $params['courseid'];
            $row->role = 'system';
            $row->message = '[Quiz panel opened]';
            $row->tokens_used = 0;
            $row->prompt_tokens = null;
            $row->completion_tokens = null;
            $row->model_name = null;
            $row->provider = null;
            $row->interaction_type = 'quiz_open';
            $row->cmid = $params['cmid'] > 0 ? (int) $params['cmid'] : null;
            $row->timecreated = time();
            $DB->insert_record('local_ai_course_assistant_msgs', $row);
        } catch (\Throwable $e) {
            // Telemetry failing must never break the learner's quiz.
            debugging('record_quiz_open failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['recorded' => false];
        }

        return ['recorded' => true];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'recorded' => new external_value(PARAM_BOOL, 'Whether the open was recorded'),
        ]);
    }
}
