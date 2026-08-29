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
use core_external\external_multiple_structure;
use core_external\external_value;
use local_ai_course_assistant\conversation_manager;
use local_ai_course_assistant\attachment_manager;

/**
 * Get conversation history for a course.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_history extends external_api {
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

        $userid = $USER->id;
        $conv = conversation_manager::get_or_create_conversation($userid, $params['courseid']);
        $messages = conversation_manager::get_messages($conv->id);

        $result = [];
        foreach ($messages as $msg) {
            // Assistant rows are laundered on the way out, never on the way in:
            // rows written before v7.2.4 can hold "[no response: <class>]", and
            // a timeout or an exhausted failover chain can leave the column
            // empty, both of which reached the learner verbatim. User rows are
            // returned exactly as typed -- a learner asking about an exception
            // class must see their own question back.
            $text = $msg->role === 'assistant'
                ? conversation_manager::display_turn_text((string) $msg->message)
                : $msg->message;
            $entry = [
                'id' => (int) $msg->id,
                'role' => $msg->role,
                'message' => $text,
                'timecreated' => (int) $msg->timecreated,
                'attachment' => [
                    'filename' => '',
                    'mime' => '',
                    'size' => 0,
                    'url' => '',
                ],
            ];
            if ($msg->role === 'user') {
                $file = attachment_manager::get_for_message((int) $params['courseid'], (int) $msg->id);
                if ($file) {
                    $entry['attachment'] = [
                        'filename' => $file->get_filename(),
                        'mime' => (string) ($file->get_mimetype() ?: ''),
                        'size' => (int) $file->get_filesize(),
                        'url' => attachment_manager::build_pluginfile_url(
                            (int) $params['courseid'],
                            (int) $msg->id,
                            $file
                        ),
                    ];
                }
            }
            $result[] = $entry;
        }

        return ['messages' => $result];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'messages' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Message ID'),
                    'role' => new external_value(PARAM_ALPHA, 'Message role'),
                    'message' => new external_value(PARAM_RAW, 'Message content'),
                    'timecreated' => new external_value(PARAM_INT, 'Timestamp'),
                    'attachment' => new external_single_structure([
                        'filename' => new external_value(PARAM_RAW, 'Attachment filename or empty'),
                        'mime' => new external_value(PARAM_RAW, 'Attachment MIME type or empty'),
                        'size' => new external_value(PARAM_INT, 'Attachment size in bytes'),
                        'url' => new external_value(PARAM_URL, 'Pluginfile URL or empty'),
                    ]),
                ])
            ),
        ]);
    }
}
