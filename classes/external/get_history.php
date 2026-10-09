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

        // validate_context() ends in require_login(..., $preventredirect = true),
        // which enforces ENROLMENT for a course context and throws before this
        // function body runs. A support learner is not enrolled in the support
        // course, so on a support turn the request is validated at system context
        // instead; require_use() below still decides whether they may be here.
        $context = \context_course::instance($params['courseid']);
        self::validate_context(
            \local_ai_course_assistant\support_mode::validation_context((int) $courseid, $context)
        );
        \local_ai_course_assistant\support_mode::require_use(
            (int) $courseid,
            $context
        );

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
            ];
            if ($msg->role === 'assistant') {
                $entry += self::source_pill((int) $params['courseid'], (string) ($msg->source ?? ''), (int) ($msg->cmid ?? 0));
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
                    'source_type' => new external_value(PARAM_ALPHA, 'Source pill type: page, course, general, activity or empty', VALUE_DEFAULT, ''),
                    'source_cmid' => new external_value(PARAM_INT, 'Activity the pill links to, 0 when none', VALUE_DEFAULT, 0),
                    'source_url' => new external_value(PARAM_URL, 'Where the pill links, empty when it does not', VALUE_DEFAULT, ''),
                    'source_title' => new external_value(PARAM_TEXT, 'Activity name for the pill label', VALUE_DEFAULT, ''),
                ])
            ),
        ]);
    }

    /**
     * What the learner's history needs to draw an answer's source pill again.
     *
     * The stored value only says which pill the answer showed. Whether it can
     * link is decided now, for this learner: an activity that was deleted, hidden
     * since, or never visible to them gives a pill that links to the course
     * instead, and never discloses the name of an activity they cannot open.
     *
     * @param int $courseid
     * @param string $source Stored msgs.source.
     * @param int $viewedcmid msgs.cmid, the activity the learner was on, used by a 'page' pill.
     * @return array{source_type: string, source_cmid: int, source_url: string, source_title: string}
     */
    public static function source_pill(int $courseid, string $source, int $viewedcmid): array {
        $none = ['source_type' => '', 'source_cmid' => 0, 'source_url' => '', 'source_title' => ''];
        if (!preg_match('/^(page|course|general|activity)(?::(\d+))?$/', $source, $m)) {
            return $none;
        }
        $type = $m[1];
        $cmid = $type === 'activity' ? (int) ($m[2] ?? 0) : ($type === 'page' ? $viewedcmid : 0);
        $out = ['source_type' => $type, 'source_cmid' => 0, 'source_url' => '', 'source_title' => ''];
        if ($type === 'general') {
            return $out;
        }
        $courseurl = (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
        if ($cmid > 0) {
            try {
                $cm = get_fast_modinfo($courseid)->get_cm($cmid);
                if ($cm->uservisible && $cm->has_view() && !empty($cm->name)) {
                    $out['source_cmid'] = (int) $cm->id;
                    $out['source_url'] = (new \moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]))->out(false);
                    $out['source_title'] = format_string($cm->name, true, ['context' => \context_module::instance($cm->id), 'escape' => false]);
                    return $out;
                }
            } catch (\Throwable $e) {
                // Deleted, or in another course: fall through to the course link.
                unset($e);
            }
        }
        $out['source_type'] = $type === 'page' ? 'course' : $type;
        $out['source_url'] = $courseurl;
        return $out;
    }
}
