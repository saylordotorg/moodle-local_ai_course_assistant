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
use local_ai_course_assistant\rate_limiter;
use local_ai_course_assistant\voice_safety;

/**
 * Check one finished live voice turn against the guidance rules and record it (8.4.0, red team RT3).
 *
 * Live voice runs browser to provider, so the browser posts each finished turn here: the provider's transcript of
 * what the learner said and of what the assistant said. voice_safety::check_turn() runs it through the pipeline a
 * text answer goes through and the reply says what the browser must do (nothing, add a warning line, or stop the
 * audio and show and say the safe message).
 *
 * Who may post what:
 *  - a logged-in user with a session key (the AJAX service layer) and local/ai_course_assistant:use in the course,
 *    the same as get_realtime_token;
 *  - only with the ticket get_realtime_token gave THIS user for THIS course less than an hour ago. There is no user
 *    id parameter: the row is written for $USER. A ticket of another learner, of another course, a made-up one or
 *    an old one is refused, so nothing can be posted into someone else's audit trail and nothing at all where live
 *    voice was not allowed to start;
 *  - a turn number once per ticket (a replay records nothing), numbers 1 to voice_safety::MAX_TURN;
 *  - at most voice_safety::RATE_MAX posts in RATE_WINDOW seconds per learner;
 *  - transcripts over the byte limits are refused before anything is read from them; within them, text past the
 *    character caps is cut and the row says so.
 *
 * A refusal is an ordinary reply (recorded false, action none, a reason code), never an exception: the voice
 * session must not break because a check could not be made. The transcripts are untrusted text. They are never
 * rendered as HTML by this function and reach a model only through the claim check, as a text answer does.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_voice_turn extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'ticket' => new external_value(PARAM_RAW, 'The safety ticket get_realtime_token returned'),
            'turn' => new external_value(PARAM_INT, 'Turn number within the session, from 1'),
            'question' => new external_value(PARAM_RAW, 'Transcript of what the learner said', VALUE_DEFAULT, ''),
            'answer' => new external_value(PARAM_RAW,
                'Transcript of what the assistant said; empty for the learner\'s words alone when the session ended'),
            'pageid' => new external_value(PARAM_INT, 'Course module id of the current page', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Check and record the turn.
     *
     * @param int $courseid
     * @param string $ticket
     * @param int $turn
     * @param string $question
     * @param string $answer
     * @param int $pageid
     * @return array
     */
    public static function execute(int $courseid, string $ticket, int $turn, string $question = '',
            string $answer = '', int $pageid = 0): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'ticket' => $ticket,
            'turn' => $turn,
            'question' => $question,
            'answer' => $answer,
            'pageid' => $pageid,
        ]);
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        $courseid = (int) $params['courseid'];
        $userid = (int) $USER->id;
        if (strlen($params['question']) > voice_safety::MAX_QUESTION_BYTES
                || strlen($params['answer']) > voice_safety::MAX_ANSWER_BYTES) {
            return self::refusal('toolarge');
        }
        if ($params['turn'] < 1 || $params['turn'] > voice_safety::MAX_TURN) {
            return self::refusal('turn');
        }
        $ticketdata = voice_safety::verify_ticket((string) $params['ticket'], $userid, $courseid);
        if ($ticketdata === null) {
            return self::refusal('ticket');
        }
        if (rate_limiter::is_rate_limited($userid, 'voice_turn', voice_safety::RATE_MAX, voice_safety::RATE_WINDOW)) {
            return self::refusal('ratelimited');
        }
        // The activity is recorded only when it belongs to this course.
        $cmid = 0;
        if ($params['pageid'] > 0) {
            $modinfo = get_fast_modinfo($courseid);
            $cmid = isset($modinfo->cms[$params['pageid']]) ? (int) $params['pageid'] : 0;
        }
        $result = voice_safety::check_turn($userid, $courseid, $ticketdata, (int) $params['turn'],
            (string) $params['question'], (string) $params['answer'], $cmid);
        return [
            'recorded' => (bool) $result['recorded'],
            'action' => (string) $result['action'],
            'text' => (string) $result['text'],
            'wellbeing' => (bool) $result['wellbeing'],
            'reason' => (string) $result['reason'],
        ];
    }

    /**
     * A refusal in the shape of a result.
     *
     * @param string $reason
     * @return array
     */
    private static function refusal(string $reason): array {
        return ['recorded' => false, 'action' => 'none', 'text' => '', 'wellbeing' => false, 'reason' => $reason];
    }

    /**
     * Result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'recorded' => new external_value(PARAM_BOOL, 'Whether an answer audit row was written'),
            'action' => new external_value(PARAM_ALPHA, 'none | warn | block'),
            'text' => new external_value(PARAM_RAW, 'block: the safe message; warn: the line to add; else empty'),
            'wellbeing' => new external_value(PARAM_BOOL, 'Whether a wellbeing rule fired'),
            'reason' => new external_value(PARAM_ALPHA,
                'ok, or why nothing was recorded: off | empty | replay | toolarge | turn | ticket | ratelimited | error'),
        ]);
    }
}
