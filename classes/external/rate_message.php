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
 * Rate an AI message (thumbs up/down).
 *
 * Student-facing endpoint that records a rating for a specific assistant
 * message, optionally flagging it as a hallucination with a comment.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rate_message extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'messageid' => new external_value(PARAM_INT, 'Message ID to rate'),
            'rating' => new external_value(PARAM_INT, 'Rating: 1 (thumbs up) or -1 (thumbs down)'),
            'is_hallucination' => new external_value(PARAM_INT, 'Hallucination flag: 0 or 1', VALUE_DEFAULT, 0),
            'comment' => new external_value(PARAM_RAW, 'Optional comment about the rating', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $messageid, int $rating, int $is_hallucination = 0, string $comment = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'messageid' => $messageid,
            'rating' => $rating,
            'is_hallucination' => $is_hallucination,
            'comment' => $comment,
        ]);
        $messageid = $params['messageid'];
        $rating = $params['rating'];
        $is_hallucination = $params['is_hallucination'];
        $comment = clean_param($params['comment'], PARAM_TEXT);

        // Validate rating value.
        if (!in_array($rating, [1, -1], true)) {
            throw new \invalid_parameter_exception('Rating must be 1 or -1');
        }

        // Validate hallucination flag.
        if (!in_array($is_hallucination, [0, 1], true)) {
            throw new \invalid_parameter_exception('is_hallucination must be 0 or 1');
        }

        // Verify the message exists and get its course.
        $message = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $messageid], '*', MUST_EXIST);

        // Validate context — user must have access to the course.
        $coursecontext = \context_course::instance($message->courseid);
        self::validate_context($coursecontext);
        require_capability('local/ai_course_assistant:use', $coursecontext);

        // Check for existing rating by this user on this message.
        $existing = $DB->get_record('local_ai_course_assistant_msg_ratings', [
            'messageid' => $messageid,
            'userid' => $USER->id,
        ]);

        $now = time();

        if ($existing) {
            // Update existing rating.
            $existing->rating = $rating;
            $existing->is_hallucination = $is_hallucination;
            $existing->comment = $comment;
            $existing->timemodified = $now;
            $DB->update_record('local_ai_course_assistant_msg_ratings', $existing);
        } else {
            // Insert new rating.
            $record = new \stdClass();
            $record->messageid = $messageid;
            $record->userid = $USER->id;
            $record->courseid = $message->courseid;
            $record->rating = $rating;
            $record->is_hallucination = $is_hallucination;
            $record->comment = $comment;
            $record->timecreated = $now;
            $record->timemodified = $now;
            $DB->insert_record('local_ai_course_assistant_msg_ratings', $record);
        }

        return ['success' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the rating was saved'),
        ]);
    }
}
