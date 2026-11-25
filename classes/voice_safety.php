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

namespace local_ai_course_assistant;

/**
 * Answer safety for live (realtime) voice and the talking avatar (8.4.0, red team RT3, ruling R21).
 *
 * Live voice (OpenAI and xAI realtime, Gemini Live) and the avatar talk to their provider from the browser, so an
 * answer never passes sse.php and answer_flow there. Three things close what can be closed:
 *
 *  1. realtime_allowed(): in Enforce mode live voice and the avatar are refused by get_realtime_token and
 *     start_avatar_session unless the site setting ALLOW_SETTING is on (off by default). The learner then gets the
 *     regular listen, think, speak voice mode, which goes through sse.php and can hold or block an answer.
 *  2. check_turn(): where live voice does run with the mode Watch or Enforce, the browser posts each finished turn
 *     (the provider's transcripts of both sides) and it goes through the same pipeline as a text answer:
 *     answer_flow::plan() and finalize(), an answer audit row, the review queue and the wellbeing priority (both
 *     read the audit row), and the claim check under the same sampling and caps. The reply tells the browser what
 *     to do: on a block it stops the audio and shows and speaks the safe message.
 *  3. guidance_block(): a short block for the session's system instructions, built here from the rule categories
 *     in use. It is advice to the model, not a control.
 *
 * What this can't do, on purpose stated plainly:
 *  - The check runs AFTER the answer was spoken. In Enforce mode some or all of a blocked answer may be heard.
 *  - The transcript comes from the learner's browser. It is untrusted: a learner can post text the model never
 *    said, or post nothing. Every such row carries the status entry MARKER_RULE and mode 'voice', so a reader
 *    knows what it is. It is no worse than a learner typing the same words, and it goes to the judge model exactly
 *    as a text answer does (the same prompt injection risk, handled in claim_checker).
 *  - The avatar speaks inside the vendor's frame. No transcript reaches this site, so avatar answers are not
 *    checked or recorded at all; in Enforce mode the avatar is simply off unless the setting allows it.
 *  - No retrieved passages are known for a live turn, so the unsupported numbers rule and the two rules that need
 *    course text (an invented date, an invented policy) never fire, and the claim check judges it as an answer
 *    without retrieval.
 *
 * Storage: nothing new. The turn's text lives only in the answer audit row (question, answer), never in the
 * conversation's messages. A one-line marker message (role system, type MARKER_TYPE, no transcript text) is added
 * to the learner's conversation so the audit row has a message and a conversation: every existing path that
 * removes audit text (Clear, retention, erasure, course deletion) finds it by those ids, and claim_checker and
 * correction_notice need them. The conversation cap never trims system rows, so the same bound is applied here:
 * only the newest KEEP_TURNS live turns of a conversation keep their text. No audio is stored anywhere.
 *
 * Session ticket: get_realtime_token hands the browser a ticket signed for this learner, course and provider.
 * check_turn takes turns only with a ticket that verifies for the calling user and course and is younger than
 * TICKET_TTL, so a turn can't be posted for another learner or course, or without a live session having been
 * allowed. A turn number is taken once per ticket (replay). The ticket is not a secret from its owner.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class voice_safety {

    /** @var string Site setting: offer live voice and the avatar in Enforce mode too. Off by default. */
    public const ALLOW_SETTING = 'answer_safety_realtime_in_enforce';

    /** @var string msgs.interaction_type of the marker row a recorded live turn hangs on. */
    public const MARKER_TYPE = 'voice_turn';

    /** @var string Status entry (action none) every live voice row carries. */
    public const MARKER_RULE = 'voice.transcript';

    /** @var string Status entry (action none): a transcript was cut to the size cap before it was checked. */
    public const TRUNCATED_RULE = 'voice.truncated';

    /** @var string Status entry of a turn with the learner's words and no answer (posted when the session ended). */
    public const QUESTION_ONLY_RULE = 'voice.question_only';

    /** @var string The error code get_realtime_token refuses with; the browser turns it into the regular mode. */
    public const REFUSAL_STRING = 'realtime:err_answer_safety';

    /** @var int Characters of the learner's side that are checked and stored. */
    public const MAX_QUESTION_CHARS = 2000;

    /** @var int Characters of the assistant's side that are checked and stored. */
    public const MAX_ANSWER_CHARS = 8000;

    /** @var int Bytes above which a posted side is refused outright (four bytes a character over the caps). */
    public const MAX_QUESTION_BYTES = 8000;

    /** @var int See MAX_QUESTION_BYTES. */
    public const MAX_ANSWER_BYTES = 32000;

    /** @var int Seconds a ticket is good for: longer than any live session (15 minutes; Gemini's cap is a setting). */
    public const TICKET_TTL = 3600;

    /** @var int Highest turn number a ticket takes. */
    public const MAX_TURN = 400;

    /** @var int Posts one learner may make in RATE_WINDOW seconds. A spoken turn takes several seconds. */
    public const RATE_MAX = 40;

    /** @var int Seconds. */
    public const RATE_WINDOW = 300;

    /** @var int Live turns of one conversation that keep their text (the conversation cap is 50 pairs). */
    public const KEEP_TURNS = 50;

    /** @var string[] Realtime providers a ticket may name. */
    private const PROVIDERS = ['openai', 'xai', 'gemini'];

    /**
     * Whether live voice and the avatar may start in this course: always, except in Enforce mode with the setting
     * off. Honors the course's own mode.
     *
     * @param int $courseid
     * @return bool
     */
    public static function realtime_allowed(int $courseid): bool {
        try {
            if (answer_rules::mode($courseid) !== 'enforce') {
                return true;
            }
        } catch (\Throwable $e) {
            // The mode could not be read: answer_flow::plan() treats that as off, and so does this.
            return true;
        }
        return (bool) get_config('local_ai_course_assistant', self::ALLOW_SETTING);
    }

    /**
     * Whether a live session in this course posts its turns for checking: the mode is Watch or Enforce.
     *
     * @param int $courseid
     * @return bool
     */
    public static function posts_turns(int $courseid): bool {
        try {
            return answer_rules::mode($courseid) !== 'off';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The rule categories in use: every category the built-in pack or a saved site rule has a rule for.
     *
     * @return string[] in answer_rules::CATEGORIES order
     */
    public static function active_categories(): array {
        $found = [];
        foreach (array_merge(answer_rules::default_rules(), answer_rules::site_rules()) as $rule) {
            if (in_array($rule['action'] ?? '', answer_rules::RULE_ACTIONS, true)) {
                $found[(string) ($rule['category'] ?? '')] = true;
            }
        }
        return array_values(array_filter(answer_rules::CATEGORIES, static fn(string $c): bool => isset($found[$c])));
    }

    /**
     * The guidance appended to a live voice or avatar session's system instructions. Empty when the course's mode
     * is Off, so such a session's instructions are exactly what they were before 8.4.0.
     *
     * Built only from the mode, the rule categories in use and the site's wellbeing message: nothing from the
     * request. It names no rule and holds no rule pattern. It asks; it can't make the model comply.
     *
     * @param int $courseid
     * @return string '' or a block that starts with two newlines
     */
    public static function guidance_block(int $courseid): string {
        try {
            if (answer_rules::mode($courseid) === 'off') {
                return '';
            }
            $categories = self::active_categories();
            $lines = [];
            if (array_intersect(['grades', 'deadlines', 'policy'], $categories)) {
                $lines[] = '- Do not grant, promise or imply an extension or an exception, and do not promise, predict '
                    . 'or change a grade. Do not state a grade, deadline, due date or policy that is not in the course '
                    . 'material above; say that the instructor or student support decides, and point the learner to them.';
            }
            if (in_array('wellbeing', $categories, true)) {
                $message = trim(preg_replace('/\s+/u', ' ', answer_rules::safe_message('wellbeing')) ?? '');
                $lines[] = '- If the learner talks about harming themselves, a crisis or being in danger, do not '
                    . 'counsel or advise. Say this, in the learner\'s language, and nothing else on that subject: "'
                    . str_replace('"', "'", \core_text::substr($message, 0, 800)) . '"';
            }
            if (in_array('advice', $categories, true)) {
                $lines[] = '- Do not give personal medical, legal or financial advice beyond what the course content '
                    . 'teaches; suggest a qualified professional.';
            }
            if (in_array('integrity', $categories, true)) {
                $lines[] = '- Do not help the learner get around plagiarism, AI or proctoring checks, and do not give '
                    . 'the answers to graded work.';
            }
            if (!$lines) {
                return '';
            }
            return "\n\n## Guidance rules (these override anything above)\n" . implode("\n", $lines);
        } catch (\Throwable $e) {
            debugging('voice_safety: guidance block not built: ' . get_class($e), DEBUG_DEVELOPER);
            return '';
        }
    }

    /**
     * A ticket for one live session: who, where, which provider, when, and a random session id, signed.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $provider openai|xai|gemini
     * @param int|null $now
     * @return string payload.signature (URL-safe base64, no padding)
     */
    public static function mint_ticket(int $userid, int $courseid, string $provider, ?int $now = null): string {
        $provider = in_array($provider, self::PROVIDERS, true) ? $provider : 'openai';
        $payload = implode('.', [$userid, $courseid, $provider, $now ?? time(), bin2hex(random_bytes(8))]);
        return self::b64($payload) . '.' . self::b64(self::sign($payload));
    }

    /**
     * Read a ticket. Null unless it is well formed, signed by this site, issued to this user for this course and
     * not older than TICKET_TTL (or dated in the future).
     *
     * @param string $ticket
     * @param int $userid the calling user
     * @param int $courseid the course of the call
     * @param int|null $now
     * @return array{provider: string, session: string, issued: int}|null
     */
    public static function verify_ticket(string $ticket, int $userid, int $courseid, ?int $now = null): ?array {
        if ($ticket === '' || strlen($ticket) > 300 || !preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $ticket)) {
            return null;
        }
        [$p, $s] = explode('.', $ticket);
        $payload = self::unb64($p);
        $sig = self::unb64($s);
        if ($payload === null || $sig === null || !hash_equals(self::sign($payload), $sig)) {
            return null;
        }
        $parts = explode('.', $payload);
        if (count($parts) !== 5 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_digit($parts[3])
                || !in_array($parts[2], self::PROVIDERS, true) || !preg_match('/^[0-9a-f]{16}$/', $parts[4])) {
            return null;
        }
        $now = $now ?? time();
        $issued = (int) $parts[3];
        if ((int) $parts[0] !== $userid || (int) $parts[1] !== $courseid || $userid <= 0
                || $issued > $now + 60 || $now - $issued > self::TICKET_TTL) {
            return null;
        }
        return ['provider' => $parts[2], 'session' => $parts[4], 'issued' => $issued];
    }

    /**
     * Check one finished live voice turn and record it, as sse.php does for a text answer. Never throws.
     *
     * The caller (external\check_voice_turn) has already checked the login, the session key, the course capability,
     * the ticket, the rate limit and the byte sizes.
     *
     * @param int $userid the calling learner
     * @param int $courseid
     * @param array $ticket verify_ticket()'s result
     * @param int $turn the turn number within the session, from 1
     * @param string $question the provider's transcript of what the learner said (untrusted)
     * @param string $answer the provider's transcript of what the assistant said (untrusted); empty for a question
     *     the browser posted on its own when the session ended (words that came after the last answer): the rules
     *     read the question (a wellbeing rule fires on it as for a text turn), the row has no outcome entry and
     *     says QUESTION_ONLY_RULE, no claim check is queued, and a replacement comes back as warn (there is no
     *     audio to stop, so the browser adds the message to the transcript)
     * @param int $cmid the activity the learner is on, 0 for none
     * @return array{recorded: bool, action: string, text: string, wellbeing: bool, reason: string} action is
     *     none (do nothing), warn (add text to the transcript) or block (stop the audio; show and say text);
     *     reason is ok, or why nothing was recorded: off, empty, replay, error
     */
    public static function check_turn(int $userid, int $courseid, array $ticket, int $turn, string $question,
            string $answer, int $cmid = 0): array {
        $out = ['recorded' => false, 'action' => 'none', 'text' => '', 'wellbeing' => false, 'reason' => ''];
        try {
            $truncated = false;
            $question = self::clean($question, self::MAX_QUESTION_CHARS, $truncated);
            $answer = self::clean($answer, self::MAX_ANSWER_CHARS, $truncated);
            if ($answer === '' && $question === '') {
                $out['reason'] = 'empty';
                return $out;
            }
            $questiononly = $answer === '';
            $plan = answer_flow::plan($question, $courseid);
            if ($plan['mode'] === 'off') {
                $out['reason'] = 'off';
                return $out;
            }
            $marker = self::marker_text($ticket, $turn);
            $conv = conversation_manager::get_or_create_conversation($userid, $courseid);
            if (self::turn_seen((int) $conv->id, $marker)) {
                $out['reason'] = 'replay';
                return $out;
            }

            // Not buffered (the answer was already spoken), no retry, no retrieved passages.
            $final = answer_flow::finalize($answer, $plan, ['question' => $question, 'buffered' => false,
                'validator_results' => [], 'chunks' => [], 'courseid' => $courseid]);
            if ($final['error'] !== null) {
                answer_audit::log_rules_error($userid, $courseid, (string) $final['error']);
            }
            $rules = answer_flow::audit_rules($plan, $final);
            if ($questiononly) {
                // Nothing was answered, so nothing was delivered, warned or blocked: no outcome entry.
                $rules = array_values(array_filter($rules, static fn(array $r): bool =>
                    !str_starts_with((string) ($r['id'] ?? ''), 'outcome.')));
                $rules[] = ['id' => self::QUESTION_ONLY_RULE, 'action' => 'none'];
            }
            $rules[] = ['id' => self::MARKER_RULE, 'action' => 'none'];
            if ($truncated) {
                $rules[] = ['id' => self::TRUNCATED_RULE, 'action' => 'none'];
            }
            $out['wellbeing'] = answer_rules::wellbeing_rule_fired($rules, (string) $plan['mode']);
            if ($final['replace'] && $questiononly) {
                $text = trim((string) $final['text']);
                if ($text !== '') {
                    $out['action'] = 'warn';
                    $out['text'] = $text;
                }
            } else if ($final['replace']) {
                if ($final['action'] === 'block') {
                    $out['action'] = 'block';
                    $out['text'] = (string) $final['text'];
                } else if (str_starts_with((string) $final['text'], $answer)) {
                    // A warning: the answer plus the check line. The answer was heard already; send the line.
                    $out['action'] = 'warn';
                    $out['text'] = trim(substr((string) $final['text'], strlen($answer)));
                }
            }

            // The marker row carries no transcript text. System role: never shown, never sent to a model, never
            // counted as spend (its type is not in the spend predicate) and no tokens.
            $messageid = conversation_manager::add_message((int) $conv->id, $userid, $courseid, 'system', $marker, 0,
                '', null, null, null, self::MARKER_TYPE, $cmid > 0 ? $cmid : null);
            // What is stored is what was said, also when it was blocked: the learner may have heard it, and the
            // reviewer has to see it. The status entries say what happened next.
            $auditid = answer_audit::record_answer([
                'messageid' => $messageid,
                'conversationid' => (int) $conv->id,
                'userid' => $userid,
                'courseid' => $courseid,
                'cmid' => $cmid > 0 ? $cmid : null,
                'mode' => 'voice',
                'question' => $question,
                'answer' => $answer,
                'model' => null,
                'provider' => (string) ($ticket['provider'] ?? ''),
            ], [], [], [], ['categories' => $final['categories'], 'rules' => $rules]);
            if ($auditid) {
                $out['recorded'] = true;
                $out['reason'] = 'ok';
                if (!$questiononly) {
                    claim_checker::queue_for($auditid);
                }
                self::trim_conversation((int) $conv->id);
            }
            return $out;
        } catch (\Throwable $e) {
            // Fail safe, as the text path: nothing is done to the session, and the failure is counted.
            // The class only: the message can quote the transcript.
            debugging('voice_safety: turn not checked: ' . get_class($e), DEBUG_DEVELOPER);
            answer_audit::log_rules_error($userid, $courseid, get_class($e));
            return ['recorded' => false, 'action' => 'none', 'text' => '', 'wellbeing' => false, 'reason' => 'error'];
        }
    }

    /**
     * Keep the text of only the newest KEEP_TURNS live turns of a conversation: older ones are redacted in the audit
     * and their marker rows deleted. Never throws.
     *
     * @param int $conversationid
     * @return int marker rows removed
     */
    public static function trim_conversation(int $conversationid): int {
        global $DB;
        try {
            $select = "conversationid = ? AND role = 'system' AND interaction_type = ?";
            $params = [$conversationid, self::MARKER_TYPE];
            $excess = $DB->count_records_select('local_ai_course_assistant_msgs', $select, $params) - self::KEEP_TURNS;
            if ($excess <= 0) {
                return 0;
            }
            $oldest = $DB->get_records_select('local_ai_course_assistant_msgs', $select, $params,
                'timecreated ASC, id ASC', 'id', 0, $excess);
            if (!$oldest) {
                return 0;
            }
            // Redact first; never delete a marker whose audit text could not be removed.
            answer_audit::redact_for_messages(array_keys($oldest));
            [$in, $inparams] = $DB->get_in_or_equal(array_keys($oldest));
            $DB->delete_records_select('local_ai_course_assistant_msgs', "id $in", $inparams);
            return count($oldest);
        } catch (\Throwable $e) {
            debugging('voice_safety: live turn trim skipped: ' . get_class($e), DEBUG_DEVELOPER);
            return 0;
        }
    }

    /**
     * The marker message of one turn: the session id from the ticket and the turn number. No transcript text.
     *
     * @param array $ticket
     * @param int $turn
     * @return string
     */
    public static function marker_text(array $ticket, int $turn): string {
        return '[Live voice turn ' . preg_replace('/[^0-9a-f]/', '', (string) ($ticket['session'] ?? '')) . ':'
            . $turn . ']';
    }

    /**
     * Whether this turn of this session was already taken (a replayed post).
     *
     * @param int $conversationid
     * @param string $marker
     * @return bool
     */
    private static function turn_seen(int $conversationid, string $marker): bool {
        global $DB;
        return $DB->record_exists_select('local_ai_course_assistant_msgs',
            "conversationid = ? AND role = 'system' AND interaction_type = ? AND "
            . $DB->sql_compare_text('message', 64) . ' = ?', [$conversationid, self::MARKER_TYPE, $marker]);
    }

    /**
     * A transcript as it is checked and stored: valid UTF-8, no control characters, trimmed, cut to the cap.
     *
     * @param string $text
     * @param int $maxchars
     * @param bool $truncated set to true when the text was cut
     * @return string
     */
    private static function clean(string $text, int $maxchars, bool &$truncated): string {
        $text = fix_utf8($text);
        $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text));
        if (\core_text::strlen($text) > $maxchars) {
            $text = \core_text::substr($text, 0, $maxchars);
            $truncated = true;
        }
        return $text;
    }

    /**
     * HMAC-SHA256 with the site identifier as the key, under a label of its own so a ticket is not any other
     * signed value of this plugin.
     *
     * @param string $payload
     * @return string raw bytes
     */
    private static function sign(string $payload): string {
        global $CFG;
        $key = (string) ($CFG->siteidentifier ?? '');
        if ($key === '') {
            $key = (string) get_site_identifier();
        }
        return hash_hmac('sha256', 'sola-voice-turn-ticket:' . $payload, $key, true);
    }

    /**
     * URL-safe base64 without padding.
     *
     * @param string $bytes
     * @return string
     */
    private static function b64(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Decode b64(); null when malformed.
     *
     * @param string $s
     * @return string|null
     */
    private static function unb64(string $s): ?string {
        $padded = strtr($s, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $out = base64_decode($padded, true);
        return $out === false ? null : $out;
    }
}
