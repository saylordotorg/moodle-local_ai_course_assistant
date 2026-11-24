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
 * The correction message (8.4.0): a short assistant message telling the learner an earlier answer may be wrong.
 *
 * claim_checker::check() calls send_safely() right after it appends a verdict that has a claim contradicted at high
 * severity. The message is stored with conversation_manager::add_correction_message() as role `assistant`,
 * interaction type `correction`, in the SAME conversation as the answer, and is seen the next time the widget loads the history or
 * the learner sends a message; nothing is pushed. It carries no tokens, no provider, no model and no source pill,
 * so no analytics count it as spend or as a model answer, and the classifier never sees it (that runs on the chat
 * path only). The conversation cap neither counts nor trims it (conversation_manager::add_message()); it goes when
 * the conversation is cleared, deleted or aged out.
 *
 * Sent only when ALL hold: the course is in `enforce` mode (watch records the verdict and sends nothing); the verdict
 * has a claim with status contradicted AND severity high (never not_in_course, which is a content-gap signal, and
 * never a verdict carrying the wellbeing.model_flag rule, which is a review item for a person); the answer row and
 * the verdict are not redacted and the claim text is there; the answer's message and conversation still exist and
 * the conversation is the learner's (a cleared chat gets nothing, and no conversation is created); and the learner
 * had no correction in the last RATE_LIMIT_SECONDS. The audit store is not written.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class correction_notice {

    /** @var string msgs.interaction_type of a correction. Only conversation_manager::add_correction_message() writes
     * it; add_message() maps it (any case) to 'chat' for every other caller, and sse.php normalises the request. */
    public const INTERACTION_TYPE = 'correction';

    /** @var int At most one correction per learner in this many seconds. */
    public const RATE_LIMIT_SECONDS = 600;

    /** @var int The claim text is cut to this many characters. */
    public const MAX_CLAIM_CHARS = 300;

    /** @var string audit_logger action for a correction that failed. */
    public const FAILED_ACTION = 'answer_correction_failed';

    /** @var string The reason written on FAILED_ACTION rows. */
    public const FAILED_REASON = 'correction_error';

    /** @var \Closure|null Test hook: called inside send() before anything is read; may throw. */
    public static ?\Closure $beforesend = null;

    /**
     * Whether a verdict's claims call for a correction: a claim contradicted at high severity.
     *
     * @param array $claims the verdict's claims [{text, type, status, evidence_ref, severity}]
     * @return bool
     */
    public static function wanted(array $claims): bool {
        foreach ($claims as $claim) {
            if (is_array($claim) && ($claim['status'] ?? null) === 'contradicted' && ($claim['severity'] ?? null) === 'high') {
                return true;
            }
        }
        return false;
    }

    /**
     * Send a correction, never throwing: a failure is counted as FAILED_ACTION (exception class only, no text).
     *
     * @param int $auditid the verdict row (or its answer row)
     * @param int $userid for the failure log
     * @param int $courseid for the failure log
     * @return int|null the new message id, or null when skipped or failed
     */
    public static function send_safely(int $auditid, int $userid = 0, int $courseid = 0): ?int {
        try {
            return self::send($auditid);
        } catch (\Throwable $e) {
            debugging('correction_notice: not sent: ' . get_class($e), DEBUG_DEVELOPER);
            try {
                audit_logger::log(self::FAILED_ACTION, $userid, $courseid, ['reason' => self::FAILED_REASON,
                    'auditid' => $auditid, 'error_class' => substr(get_class($e), 0, 120)]);
            } catch (\Throwable $ignore) {
                debugging('correction_notice: could not log a failure: ' . get_class($ignore), DEBUG_DEVELOPER);
            }
            return null;
        }
    }

    /**
     * Send the correction for one verdict, when every condition holds (see the class comment).
     *
     * @param int $auditid the verdict row; an answer row is accepted too and means its newest verdict
     * @return int|null the new message id, or null when skipped
     */
    public static function send(int $auditid): ?int {
        global $DB;
        if (self::$beforesend) {
            (self::$beforesend)();
        }
        $row = $DB->get_record(answer_audit::TABLE, ['id' => $auditid]);
        if (!$row) {
            return null;
        }
        if ($row->kind === 'answer') {
            $answer = $row;
            $verdicts = $DB->get_records(answer_audit::TABLE, ['supersedes' => $row->id, 'kind' => 'verdict'],
                'id DESC', '*', 0, 1);
            $verdict = $verdicts ? reset($verdicts) : null;
        } else {
            $verdict = $row;
            $answer = $row->supersedes ? $DB->get_record(answer_audit::TABLE, ['id' => (int) $row->supersedes]) : null;
        }
        if (!$verdict || !$answer || $answer->kind !== 'answer') {
            return null;
        }
        // Cleared, erased, trimmed or aged out: the text is gone and so is the reason to write.
        if (!empty($answer->redacted) || !empty($verdict->redacted) || $answer->answer === null) {
            return null;
        }
        $courseid = (int) $answer->courseid;
        $userid = (int) $answer->userid;
        if (answer_rules::mode($courseid) !== 'enforce') {
            return null;
        }
        $rules = json_decode((string) $verdict->rules, true);
        foreach (is_array($rules) ? $rules : [] as $rule) {
            if (is_array($rule) && ($rule['id'] ?? null) === claim_checker::WELLBEING_MODEL_RULE) {
                return null;
            }
        }
        $claims = json_decode((string) $verdict->claims, true);
        $claim = self::claim_to_correct(is_array($claims) ? $claims : []);
        if ($claim === null) {
            return null;
        }

        // The learner's own conversation, still there, still holding the answer. Never created here.
        $convid = (int) $answer->conversationid;
        $msgid = (int) $answer->messageid;
        if ($convid <= 0 || $msgid <= 0) {
            return null;
        }
        $conv = $DB->get_record('local_ai_course_assistant_convs', ['id' => $convid]);
        if (!$conv || (int) $conv->userid !== $userid || (int) $conv->courseid !== $courseid) {
            return null;
        }
        if (!$DB->record_exists('local_ai_course_assistant_msgs', ['id' => $msgid, 'conversationid' => $convid])) {
            return null;
        }
        if (self::recently_sent($userid)) {
            return null;
        }

        // Role assistant, no tokens, no provider, no model, no cmid, no source: not spend, not a model answer,
        // no source pill.
        return conversation_manager::add_correction_message($convid, $userid, $courseid, self::message_text($claim));
    }

    /**
     * Whether this learner had a correction in the last RATE_LIMIT_SECONDS, in any course.
     *
     * @param int $userid
     * @param int|null $now
     * @return bool
     */
    public static function recently_sent(int $userid, ?int $now = null): bool {
        global $DB;
        $now = $now ?? time();
        return $DB->record_exists_select('local_ai_course_assistant_msgs',
            "userid = :userid AND role = 'assistant' AND interaction_type = :type AND timecreated > :since",
            ['userid' => $userid, 'type' => self::INTERACTION_TYPE, 'since' => $now - self::RATE_LIMIT_SECONDS]);
    }

    /**
     * The first claim contradicted at high severity whose plain text is not empty.
     *
     * @param array $claims
     * @return string|null the plain claim text
     */
    public static function claim_to_correct(array $claims): ?string {
        foreach ($claims as $claim) {
            if (!is_array($claim) || ($claim['status'] ?? null) !== 'contradicted'
                    || ($claim['severity'] ?? null) !== 'high' || !is_string($claim['text'] ?? null)) {
                continue;
            }
            $text = self::plain_claim($claim['text']);
            if ($text !== '') {
                return $text;
            }
        }
        return null;
    }

    /**
     * A claim as one line of plain text: protocol markers and markdown punctuation removed, whitespace collapsed,
     * cut to MAX_CLAIM_CHARS. HTML is left as text; the widget's markdown renderer escapes it.
     *
     * @param string $text
     * @return string
     */
    public static function plain_claim(string $text): string {
        $text = protocol_markers::strip($text);
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = str_replace(['[', ']', '*', '`', '#', '_', '~', '|'], '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (\core_text::strlen($text) > self::MAX_CLAIM_CHARS) {
            $text = rtrim(\core_text::substr($text, 0, self::MAX_CLAIM_CHARS - 3)) . '...';
        }
        return $text;
    }

    /**
     * The message: lead, the claim, the next step. Built from language strings (English only for now).
     *
     * @param string $claim plain claim text
     * @return string
     */
    public static function message_text(string $claim): string {
        $c = 'local_ai_course_assistant';
        return get_string('correction:lead', $c) . ' ' . get_string('correction:claim', $c, $claim) . "\n\n"
            . get_string('correction:nextstep', $c);
    }
}
