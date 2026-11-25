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
 * The answer audit store (8.4.0): one append-only row per assistant answer, one per background verdict.
 *
 * Rows are never edited except by redaction. A verdict is a NEW row of kind 'verdict' whose `supersedes` is the
 * answer row's id, so the original stays exactly as written.
 *
 * Each course's rows form a hash chain: `rowhash` = sha256 of the canonical JSON of the row's fixed fields, which
 * include `prevhash` (the rowhash of the course's previous row, '' for the first). The fixed fields include
 * `texthash` (sha256 of the question, answer and claim text) but never the text itself, so redaction, which nulls
 * the text and sets `redacted` = 1, leaves every hash intact and `verify_chain()` still passes. Reading the
 * previous hash and inserting happen under a per-course \core\lock so two answers at the same moment cannot fork
 * the chain.
 *
 * Nothing key-like is ever stored: every field is on an allowlist, and a row or verdict carrying a field named
 * like a key, secret, token, password or prompt (at any depth) is refused with a coding_exception.
 *
 * Failure: record() and append_verdict() can throw (a refused field is a coding_exception, a busy course lock is
 * moodle_exception 'locktimeout' after LOCK_WAIT_SECONDS, and the database can fail). The audit must never cost the
 * learner an answer, so callers MUST wrap both in try/catch on \Throwable and carry on (Task 3: record the failure,
 * deliver the answer). Text that is not valid UTF-8 is not a failure: it is scrubbed (invalid bytes become U+FFFD)
 * before it is hashed and stored, so the row is still written and its chain still verifies.
 *
 * Erasure and verdicts: a verdict row carries its answer row's messageid, conversationid, userid and courseid, so
 * every redact_for_*() path reaches it. append_verdict() re-reads the answer row AFTER inserting the verdict and
 * redacts the verdict if the answer was redacted in the meantime; a redaction that runs after that re-read finds
 * the verdict by those same identifiers. Either way no claim text outlives its answer.
 *
 * Known limits: the chain proves rows were not edited, reordered or removed from the middle; it cannot prove the
 * newest rows were not removed (no external anchor). Redaction is not itself tamper-evident (it is the permitted
 * change). A caller inside an open delegated transaction holds the lock only until record() returns, not until
 * its commit; call record() outside a transaction.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_audit {

    /** @var string The table. */
    public const TABLE = 'local_ai_course_assistant_answer_audit';

    /** @var string The \core\lock factory type. */
    public const LOCK_TYPE = 'local_ai_course_assistant_answer_audit';

    /** @var int Seconds record() waits for the course lock. Short: it runs in the learner's request path. */
    public const LOCK_WAIT_SECONDS = 3;

    /** @var int Kept for the tests' reset; the same as LOCK_WAIT_SECONDS. */
    public const DEFAULT_LOCK_TIMEOUT = self::LOCK_WAIT_SECONDS;

    /** @var int Seconds record() waits for the course lock (tests lower it). */
    public static int $locktimeout = self::DEFAULT_LOCK_TIMEOUT;

    /**
     * @var \Closure|null Test seam: called at the start of every redaction with ($select, $params), before any
     *     row is changed. Tests use it to see what still exists when redaction runs, or to make it fail. Never
     *     set outside tests.
     */
    public static ?\Closure $beforeredact = null;

    /** @var string audit_logger action for an answer that could not be recorded. */
    public const RECORD_FAILED_ACTION = 'answer_audit_record_failed';

    /** @var string audit_logger action for a guidance-rule check that failed on the chat path (answer delivered). */
    public const RULES_FAILED_ACTION = 'answer_rules_failed';

    /** @var string[] Verdict statuses. */
    public const STATUSES = ['ok', 'warn', 'blocked', 'needs_review', 'check_failed'];

    /** @var string[] Keys an answer row may carry. */
    private const ROW_KEYS = ['messageid', 'conversationid', 'userid', 'courseid', 'cmid', 'mode', 'question', 'answer',
        'model', 'provider', 'chunks', 'citations', 'categories', 'rules', 'validators'];

    /** @var string[] Keys a verdict may carry. */
    private const VERDICT_KEYS = ['status', 'severity', 'claims', 'rules', 'judge_model', 'judge_cost'];

    /** @var string[] Keys of one retrieved chunk. */
    private const CHUNK_KEYS = ['cmid', 'score', 'ref'];

    /** @var string[] Keys of one rule fired. */
    private const RULE_KEYS = ['id', 'action'];

    /** @var string[] Keys of one claim. */
    private const CLAIM_KEYS = ['text', 'type', 'status', 'evidence_ref', 'severity'];

    /** @var string A field name that may carry a credential or prompt text. */
    private const FORBIDDEN_NAME = '/^(key|keys|token|tokens|prompt|auth|pass|pwd)$'
        . '|api[_-]?key|private[_-]?key|secret|passw|(access|refresh|id|auth|bearer|session)[_-]?token'
        . '|system[_-]?prompt|full[_-]?prompt|credential|bearer|authori[sz]ation/i';

    /** @var string[] Fields covered by rowhash, in a fixed order. Text is covered through texthash only. */
    private const HASHED_FIELDS = ['kind', 'supersedes', 'messageid', 'conversationid', 'userid', 'courseid', 'cmid',
        'mode', 'model', 'provider', 'chunks', 'citations', 'categories', 'rules', 'validators', 'status', 'severity',
        'claims', 'judge_model', 'judge_cost', 'texthash', 'timecreated', 'prevhash'];

    /** @var string[] Filters rows_for_export() accepts. */
    private const EXPORT_FILTERS = ['userid', 'kind', 'status', 'since', 'until', 'limit'];

    /**
     * Record one assistant answer.
     *
     * @param array $row messageid, conversationid, userid, courseid, cmid, mode, question, answer, model, provider,
     *     chunks [{cmid, score, ref}], citations, categories, rules [{id, action}], validators
     * @return int the new row id
     * @throws \coding_exception for an unknown or key-like field, or a missing user or course
     * @throws \moodle_exception locktimeout when the course lock cannot be taken
     */
    public static function record(array $row): int {
        self::assert_no_forbidden_names($row, 'row');
        $unknown = array_diff(array_keys($row), self::ROW_KEYS);
        if ($unknown) {
            throw new \coding_exception('answer_audit: unknown row field(s): ' . implode(', ', $unknown));
        }
        if (empty($row['userid']) || empty($row['courseid'])) {
            throw new \coding_exception('answer_audit: a row needs a userid and a courseid');
        }

        $row = self::scrub_utf8($row);
        $rec = (object) [
            'kind' => 'answer',
            'supersedes' => null,
            'messageid' => self::int_or_null($row['messageid'] ?? null),
            'conversationid' => self::int_or_null($row['conversationid'] ?? null),
            'userid' => (int) $row['userid'],
            'courseid' => (int) $row['courseid'],
            'cmid' => self::int_or_null($row['cmid'] ?? null),
            'mode' => self::str_or_null($row['mode'] ?? null, 10),
            'model' => self::str_or_null($row['model'] ?? null, 100),
            'provider' => self::str_or_null($row['provider'] ?? null, 50),
            'question' => isset($row['question']) ? (string) $row['question'] : null,
            'answer' => isset($row['answer']) ? (string) $row['answer'] : null,
            'chunks' => self::json_or_null(self::checked_list($row['chunks'] ?? null, self::CHUNK_KEYS, 'chunks')),
            'citations' => self::json_or_null($row['citations'] ?? null),
            'categories' => self::json_or_null($row['categories'] ?? null),
            'rules' => self::json_or_null(self::checked_list($row['rules'] ?? null, self::RULE_KEYS, 'rules')),
            'validators' => self::json_or_null($row['validators'] ?? null),
            'status' => null,
            'severity' => null,
            'claims' => null,
            'judge_model' => null,
            'judge_cost' => null,
        ];
        return self::insert_chained($rec);
    }

    /**
     * Append a background verdict for an answer row. The answer row is not changed.
     *
     * @param int $auditid the answer row
     * @param array $verdict status (ok|warn|blocked|needs_review|check_failed), severity,
     *     claims [{text, type, status, evidence_ref, severity}], rules [{id, action}] (what the check flagged, or why
     *     it failed), judge_model, judge_cost
     * @return int the new verdict row id
     */
    public static function append_verdict(int $auditid, array $verdict): int {
        global $DB;
        self::assert_no_forbidden_names($verdict, 'verdict');
        $unknown = array_diff(array_keys($verdict), self::VERDICT_KEYS);
        if ($unknown) {
            throw new \coding_exception('answer_audit: unknown verdict field(s): ' . implode(', ', $unknown));
        }
        $status = (string) ($verdict['status'] ?? '');
        if (!in_array($status, self::STATUSES, true)) {
            throw new \coding_exception('answer_audit: verdict status must be one of ' . implode(', ', self::STATUSES));
        }
        $verdict = self::scrub_utf8($verdict);
        $claims = self::checked_list($verdict['claims'] ?? null, self::CLAIM_KEYS, 'claims');
        $rules = self::checked_list($verdict['rules'] ?? null, self::RULE_KEYS, 'rules');

        $original = $DB->get_record(self::TABLE, ['id' => $auditid], '*', MUST_EXIST);
        if ($original->kind !== 'answer') {
            throw new \coding_exception('answer_audit: a verdict must point at an answer row');
        }

        $rec = (object) [
            'kind' => 'verdict',
            'supersedes' => (int) $original->id,
            'messageid' => self::int_or_null($original->messageid),
            'conversationid' => self::int_or_null($original->conversationid),
            'userid' => (int) $original->userid,
            'courseid' => (int) $original->courseid,
            'cmid' => self::int_or_null($original->cmid),
            'mode' => $original->mode,
            'model' => $original->model,
            'provider' => $original->provider,
            'question' => null,
            'answer' => null,
            'chunks' => null,
            'citations' => null,
            'categories' => null,
            'rules' => self::json_or_null($rules),
            'validators' => null,
            'status' => $status,
            'severity' => self::str_or_null($verdict['severity'] ?? null, 20),
            'claims' => self::json_or_null($claims),
            'judge_model' => self::str_or_null($verdict['judge_model'] ?? null, 100),
            'judge_cost' => isset($verdict['judge_cost']) ? round((float) $verdict['judge_cost'], 6) : null,
        ];
        // A redacted original stays redacted: its verdict is written with no claim text either.
        if (!empty($original->redacted)) {
            $rec->claims = self::strip_claim_text($rec->claims);
            $rec->redacted = 1;
            $rec->timeredacted = time();
        }
        $id = self::insert_chained($rec);

        // The answer may have been redacted between the read above and the insert (the learner cleared the
        // conversation while the judge ran). Check again now that the verdict exists: any redaction from here on
        // finds the verdict through the identifiers it shares with its answer.
        if (empty($rec->redacted) && !empty($DB->get_field(self::TABLE, 'redacted', ['id' => $original->id]))) {
            self::redact_where('id = ?', [$id]);
        }
        return $id;
    }

    /**
     * Redact the rows of these assistant messages.
     *
     * @param int[] $msgids
     * @return int rows redacted
     */
    public static function redact_for_messages(array $msgids): int {
        global $DB;
        $msgids = array_values(array_unique(array_filter(array_map('intval', $msgids))));
        if (!$msgids) {
            return 0;
        }
        $total = 0;
        foreach (array_chunk($msgids, 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk);
            $total += self::redact_where("messageid $in", $params);
        }
        return $total;
    }

    /**
     * Redact the rows of many conversations, in chunks of 500 (the retention task's bulk path).
     *
     * @param int[] $convids
     * @return int rows redacted
     */
    public static function redact_for_conversations(array $convids): int {
        global $DB;
        $convids = array_values(array_unique(array_filter(array_map('intval', $convids))));
        if (!$convids) {
            return 0;
        }
        $total = 0;
        foreach (array_chunk($convids, 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk);
            $total += self::redact_where("conversationid $in", $params);
        }
        return $total;
    }

    /**
     * Redact the rows of one conversation.
     *
     * @param int $conversationid
     * @return int rows redacted
     */
    public static function redact_for_conversation(int $conversationid): int {
        return self::redact_where('conversationid = ?', [$conversationid]);
    }

    /**
     * Redact every row of one user, in every course.
     *
     * @param int $userid
     * @return int rows redacted
     */
    public static function redact_for_user(int $userid): int {
        return self::redact_where('userid = ?', [$userid]);
    }

    /**
     * Redact a course's rows, or one user's rows in it.
     *
     * @param int $courseid
     * @param int|null $userid
     * @return int rows redacted
     */
    public static function redact_for_course(int $courseid, ?int $userid = null): int {
        if ($userid === null) {
            return self::redact_where('courseid = ?', [$courseid]);
        }
        return self::redact_where('courseid = ? AND userid = ?', [$courseid, $userid]);
    }

    /**
     * Check a course's chain: every link, every row hash, and the text of every unredacted row.
     *
     * @param int $courseid
     * @return array{ok: bool, brokenat: ?int} brokenat is the id of the first row that fails
     */
    public static function verify_chain(int $courseid): array {
        global $DB;
        $prev = '';
        $rs = $DB->get_recordset(self::TABLE, ['courseid' => $courseid], 'id ASC');
        try {
            foreach ($rs as $rec) {
                $ok = (string) $rec->prevhash === $prev
                    && hash_equals((string) $rec->rowhash, self::compute_rowhash($rec));
                if ($ok) {
                    if (empty($rec->redacted)) {
                        $ok = hash_equals((string) $rec->texthash,
                            self::compute_texthash($rec->question, $rec->answer, $rec->claims));
                    } else {
                        // A redaction drops all the text; text left or put back is not a redaction.
                        $ok = $rec->question === null && $rec->answer === null
                            && self::strip_claim_text($rec->claims) === $rec->claims;
                    }
                }
                if (!$ok) {
                    return ['ok' => false, 'brokenat' => (int) $rec->id];
                }
                $prev = (string) $rec->rowhash;
            }
        } finally {
            $rs->close();
        }
        return ['ok' => true, 'brokenat' => null];
    }

    /**
     * Whether answers in this course are recorded (8.4.0): whenever answer_rules::mode() is watch or enforce
     * (the course's own override, else the site setting `answer_safety_mode`, off by default).
     *
     * @param int $courseid
     * @return bool
     */
    public static function is_enabled(int $courseid): bool {
        return answer_rules::mode($courseid) !== 'off';
    }

    /**
     * Record one answer from the chat path (sse.php), never throwing.
     *
     * Records nothing when is_enabled() is false, or when the message or conversation id is missing (a row without
     * them would escape the cap trim and the retention redaction, which find rows by those ids). Any failure,
     * including a PHP Error, is caught, written to the developer debug log and counted as an audit_logger
     * RECORD_FAILED_ACTION row (a skip for missing ids is counted too): the audit must never cost the learner the
     * answer or the done event. sse.php calls it after the done event is sent, because record() can wait for the
     * course lock. Call it outside any delegated transaction (see record()).
     *
     * @param array $row the record() fields except chunks, citations, categories, rules and validators
     * @param array $retrievedchunks rag_retriever::retrieve() rows
     * @param array $citations the citation entries sent in the meta event
     * @param array $validatorresults runtime_guard::last_results()
     * @param array $checked what the guidance rules decided (answer_flow::finalize()): categories (string[]) and
     *     rules ([{id, action}]); empty when the rules did not run
     * @return int|null the new row id, or null when nothing was recorded
     */
    public static function record_answer(array $row, array $retrievedchunks, array $citations,
            array $validatorresults, array $checked = []): ?int {
        try {
            $courseid = (int) ($row['courseid'] ?? 0);
            if (!self::is_enabled($courseid)) {
                return null;
            }
            if (empty($row['messageid']) || empty($row['conversationid'])) {
                debugging('answer_audit: answer not recorded, message or conversation id missing (course '
                    . $courseid . ')', DEBUG_DEVELOPER);
                self::log_dropped($row, ['reason' => 'missing_ids']);
                return null;
            }
            $row['chunks'] = self::chunk_entries($retrievedchunks);
            $row['citations'] = self::citation_entries($citations);
            $row['categories'] = array_values(array_map('strval', is_array($checked['categories'] ?? null)
                ? $checked['categories'] : []));
            $row['rules'] = [];
            foreach (is_array($checked['rules'] ?? null) ? $checked['rules'] : [] as $rule) {
                if (is_array($rule) && isset($rule['id'], $rule['action'])) {
                    $row['rules'][] = ['id' => (string) $rule['id'], 'action' => (string) $rule['action']];
                }
            }
            $row['validators'] = self::validator_entries($validatorresults);
            $id = self::record($row);
            self::redact_if_turn_is_gone($id, (int) $row['messageid'], (int) $row['conversationid']);
            return $id;
        } catch (\Throwable $e) {
            debugging('answer_audit: answer not recorded: ' . get_class($e) . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            self::log_dropped($row, ['reason' => 'error', 'error_class' => get_class($e)]);
            return null;
        }
    }

    /**
     * Redact an answer row just written when its message or conversation no longer exists (8.4.0 red team, RT6).
     *
     * The chat path records the answer after the done event, so the learner can clear the conversation (or the
     * conversation cap can trim the message) first. Those deletions redact the audit rows they find, and this row
     * did not exist yet: without this check it would keep the question and answer for good. Looked up after the
     * insert, as append_verdict() looks again at its answer row. If the lookup itself fails the row is redacted
     * too: text that can't be shown to belong to a live message is not kept. Never throws.
     *
     * @param int $id the new audit row
     * @param int $messageid
     * @param int $conversationid
     * @return void
     */
    private static function redact_if_turn_is_gone(int $id, int $messageid, int $conversationid): void {
        global $DB;
        try {
            $gone = !$DB->record_exists('local_ai_course_assistant_msgs', ['id' => $messageid,
                    'conversationid' => $conversationid])
                || !$DB->record_exists('local_ai_course_assistant_convs', ['id' => $conversationid]);
        } catch (\Throwable $e) {
            debugging('answer_audit: could not confirm the turn still exists: ' . get_class($e), DEBUG_DEVELOPER);
            $gone = true;
        }
        if (!$gone) {
            return;
        }
        try {
            self::redact_where('id = ?', [$id]);
        } catch (\Throwable $e) {
            debugging('answer_audit: could not redact a row whose turn is gone: ' . get_class($e), DEBUG_DEVELOPER);
        }
    }

    /**
     * Count a guidance-rule check that failed on the chat path, in the SOC2 audit log as RULES_FAILED_ACTION with
     * reason rules_error (the answer was delivered as it was, or blocked when a held correct's retry failed), or
     * reason retry_skipped_spend (the held answer was blocked because the course is at its chat spend cap). Details are the course id, the reason and an
     * exception class only: never question, answer or exception text. Never throws.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $errorclass an exception class, or answer_flow::RETRY_SKIPPED_SPEND (logged as that reason, with
     *     no error class)
     * @return void
     */
    public static function log_rules_error(int $userid, int $courseid, string $errorclass): void {
        try {
            $details = $errorclass === answer_flow::RETRY_SKIPPED_SPEND
                ? ['courseid' => $courseid, 'reason' => answer_flow::RETRY_SKIPPED_SPEND]
                : ['courseid' => $courseid, 'reason' => 'rules_error', 'error_class' => substr($errorclass, 0, 120)];
            audit_logger::log(self::RULES_FAILED_ACTION, $userid, $courseid, $details);
        } catch (\Throwable $ignore) {
            debugging('answer_audit: could not log a rules error: ' . get_class($ignore), DEBUG_DEVELOPER);
        }
    }

    /**
     * Count a dropped answer row in the SOC2 audit log (action RECORD_FAILED_ACTION), so watch mode can show how
     * many answers went unrecorded without developer debugging on. Details are the course id, a reason and an
     * exception class only: never question, answer or exception text. Never throws.
     *
     * @param array $row the row that was not recorded
     * @param array $details reason, and error_class for an error
     */
    private static function log_dropped(array $row, array $details): void {
        try {
            $courseid = (int) ($row['courseid'] ?? 0);
            audit_logger::log(self::RECORD_FAILED_ACTION, (int) ($row['userid'] ?? 0), $courseid,
                ['courseid' => $courseid] + $details);
        } catch (\Throwable $ignore) {
            debugging('answer_audit: could not log a dropped row: ' . get_class($ignore), DEBUG_DEVELOPER);
        }
    }

    /**
     * The audit's chunk entries: cmid, score (4 places) and a ref, `chunk:<id>` when the retriever gave the chunk's
     * id, else `sha1:<hash of its text>` (a merged parent passage has no single id).
     *
     * @param array $retrievedchunks
     * @return array[]
     */
    public static function chunk_entries(array $retrievedchunks): array {
        $out = [];
        foreach ($retrievedchunks as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $cmid = (int) ($chunk['cmid'] ?? 0);
            $out[] = [
                'cmid' => $cmid ?: null,
                'score' => round((float) ($chunk['score'] ?? 0), 4),
                'ref' => !empty($chunk['id']) ? 'chunk:' . (int) $chunk['id'] : 'sha1:' . sha1((string) ($chunk['content'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * The audit's citation entries: index and cmid only (no urls or titles).
     *
     * @param array $citations
     * @return array[]
     */
    public static function citation_entries(array $citations): array {
        $out = [];
        foreach ($citations as $c) {
            if (!is_array($c)) {
                continue;
            }
            $cmid = (int) ($c['cmid'] ?? 0);
            $out[] = ['index' => (int) ($c['index'] ?? 0), 'cmid' => $cmid ?: null];
        }
        return $out;
    }

    /**
     * The audit's validator entries: [{name, severity}]. A list, not a map keyed by validator, because a name such
     * as credential_leak is refused as a field name; messages are left out because they can quote what was caught.
     *
     * @param array $results runtime_guard::last_results()
     * @return array[]
     */
    public static function validator_entries(array $results): array {
        $out = [];
        foreach ($results as $r) {
            if (!is_array($r) || !isset($r['validator'])) {
                continue;
            }
            $out[] = ['name' => (string) $r['validator'], 'severity' => (string) ($r['severity'] ?? '')];
        }
        return $out;
    }

    /**
     * A course's rows for export, oldest first, JSON columns decoded.
     *
     * @param int $courseid
     * @param array $filters userid, kind, status, since, until (unix times), limit
     * @return array[]
     */
    public static function rows_for_export(int $courseid, array $filters): array {
        global $DB;
        $unknown = array_diff(array_keys($filters), self::EXPORT_FILTERS);
        if ($unknown) {
            throw new \coding_exception('answer_audit: unknown export filter(s): ' . implode(', ', $unknown));
        }
        $where = ['courseid = :courseid'];
        $params = ['courseid' => $courseid];
        if (isset($filters['userid'])) {
            $where[] = 'userid = :userid';
            $params['userid'] = (int) $filters['userid'];
        }
        if (isset($filters['kind'])) {
            $where[] = 'kind = :kind';
            $params['kind'] = (string) $filters['kind'];
        }
        if (isset($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (isset($filters['since'])) {
            $where[] = 'timecreated >= :since';
            $params['since'] = (int) $filters['since'];
        }
        if (isset($filters['until'])) {
            $where[] = 'timecreated <= :until';
            $params['until'] = (int) $filters['until'];
        }
        $limit = isset($filters['limit']) ? max(0, (int) $filters['limit']) : 0;

        $out = [];
        $rs = $DB->get_recordset_select(self::TABLE, implode(' AND ', $where), $params, 'id ASC', '*', 0, $limit);
        try {
            foreach ($rs as $rec) {
                $row = [];
                foreach ((array) $rec as $field => $value) {
                    if (in_array($field, ['chunks', 'citations', 'categories', 'rules', 'validators', 'claims'], true)) {
                        $row[$field] = $value === null ? null : json_decode($value, true);
                    } else if (in_array($field, ['id', 'supersedes', 'messageid', 'conversationid', 'userid',
                            'courseid', 'cmid', 'redacted', 'timeredacted', 'timecreated'], true)) {
                        $row[$field] = $value === null ? null : (int) $value;
                    } else if ($field === 'judge_cost') {
                        $row[$field] = $value === null ? null : (float) $value;
                    } else {
                        $row[$field] = $value;
                    }
                }
                $out[] = $row;
            }
        } finally {
            $rs->close();
        }
        return $out;
    }

    /**
     * The \core\lock resource for one course's chain.
     *
     * @param int $courseid
     * @return string
     */
    public static function lock_resource(int $courseid): string {
        return 'course_' . $courseid;
    }

    /**
     * The rowhash a stored (or about to be stored) row should carry.
     *
     * @param \stdClass $rec a row as read from the database (all values may be strings)
     * @return string sha256 hex
     */
    public static function compute_rowhash(\stdClass $rec): string {
        $fixed = [];
        foreach (self::HASHED_FIELDS as $field) {
            $value = $rec->$field ?? null;
            if ($field === 'claims') {
                // Claims are hashed without their text, so redaction leaves the hash unchanged.
                $value = self::strip_claim_text($value);
            }
            if ($field === 'prevhash') {
                $value = (string) $value;
            } else if ($field === 'judge_cost') {
                $value = $value === null || $value === '' ? null : sprintf('%.6f', (float) $value);
            } else if ($value !== null) {
                $value = (string) $value;
            }
            $fixed[$field] = $value;
        }
        return hash('sha256', self::canonical_json($fixed));
    }

    /**
     * sha256 of the question, answer and claim text.
     *
     * @param string|null $question
     * @param string|null $answer
     * @param string|null $claimsjson stored claims JSON
     * @return string
     */
    public static function compute_texthash(?string $question, ?string $answer, ?string $claimsjson): string {
        $claimtext = [];
        $claims = $claimsjson === null ? null : json_decode($claimsjson, true);
        if (is_array($claims)) {
            foreach ($claims as $claim) {
                $claimtext[] = is_array($claim) && array_key_exists('text', $claim) ? (string) $claim['text'] : null;
            }
        }
        return hash('sha256', self::canonical_json([
            'question' => $question,
            'answer' => $answer,
            'claims' => $claimtext,
        ]));
    }

    /**
     * Insert a row at the end of its course's chain, under the course lock.
     *
     * @param \stdClass $rec
     * @return int
     */
    private static function insert_chained(\stdClass $rec): int {
        global $DB;
        $rec->texthash = self::compute_texthash($rec->question, $rec->answer, $rec->claims);
        $rec->redacted = $rec->redacted ?? 0;
        $rec->timeredacted = $rec->timeredacted ?? null;

        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        // The lock factory and resource are fixed; only the wait is adjustable (tests shorten it).
        $lock = $factory->get_lock(self::lock_resource($rec->courseid), self::$locktimeout);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $last = $DB->get_records(self::TABLE, ['courseid' => $rec->courseid], 'id DESC', 'id, rowhash', 0, 1);
            $rec->prevhash = $last ? (string) reset($last)->rowhash : '';
            $rec->timecreated = time();
            $rec->rowhash = self::compute_rowhash($rec);
            return (int) $DB->insert_record(self::TABLE, $rec);
        } finally {
            $lock->release();
        }
    }

    /**
     * Redact every unredacted row matching a condition.
     *
     * @param string $select
     * @param array $params
     * @return int
     */
    private static function redact_where(string $select, array $params): int {
        global $DB;
        if (self::$beforeredact) {
            (self::$beforeredact)($select, $params);
        }
        $count = 0;
        $now = time();
        $rs = $DB->get_recordset_select(self::TABLE, "redacted = 0 AND $select", $params, 'id ASC', 'id, claims');
        try {
            foreach ($rs as $rec) {
                $DB->update_record(self::TABLE, (object) [
                    'id' => $rec->id,
                    'question' => null,
                    'answer' => null,
                    'claims' => self::strip_claim_text($rec->claims),
                    'redacted' => 1,
                    'timeredacted' => $now,
                ]);
                $count++;
            }
        } finally {
            $rs->close();
        }
        return $count;
    }

    /**
     * Claims JSON with each claim's text removed (canonical form).
     *
     * @param string|null $claimsjson
     * @return string|null
     */
    private static function strip_claim_text(?string $claimsjson): ?string {
        if ($claimsjson === null) {
            return null;
        }
        $claims = json_decode($claimsjson, true);
        if (!is_array($claims)) {
            return $claimsjson;
        }
        foreach ($claims as $i => $claim) {
            if (is_array($claim)) {
                unset($claim['text']);
                $claims[$i] = $claim;
            }
        }
        return self::canonical_json($claims);
    }

    /**
     * Refuse a field named like a credential or prompt, at any depth.
     *
     * @param array $data
     * @param string $where
     */
    private static function assert_no_forbidden_names(array $data, string $where): void {
        foreach ($data as $name => $value) {
            if (is_string($name) && preg_match(self::FORBIDDEN_NAME, $name)) {
                throw new \coding_exception("answer_audit: {$where} field '{$name}' looks like a key or prompt; "
                    . 'the audit never stores either');
            }
            if (is_array($value)) {
                self::assert_no_forbidden_names($value, $where);
            } else if (is_object($value)) {
                self::assert_no_forbidden_names((array) $value, $where);
            }
        }
    }

    /**
     * Check a list of items against an allowlist of keys.
     *
     * @param mixed $list
     * @param string[] $allowed
     * @param string $name
     * @return array|null
     */
    private static function checked_list($list, array $allowed, string $name): ?array {
        if ($list === null) {
            return null;
        }
        if (!is_array($list)) {
            throw new \coding_exception("answer_audit: {$name} must be a list");
        }
        foreach ($list as $item) {
            if (!is_array($item)) {
                throw new \coding_exception("answer_audit: each of {$name} must be an object");
            }
            $unknown = array_diff(array_keys($item), $allowed);
            if ($unknown) {
                throw new \coding_exception("answer_audit: unknown {$name} field(s): " . implode(', ', $unknown));
            }
        }
        return array_values($list);
    }

    /**
     * JSON with object keys sorted at every depth, no escaped slashes or unicode.
     *
     * @param mixed $value
     * @return string
     */
    private static function canonical_json($value): string {
        return json_encode(self::sort_keys($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * Replace invalid UTF-8 in every string (values and keys, at any depth) with U+FFFD.
     *
     * Done before anything is hashed or stored, so the stored text is exactly the hashed text.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function scrub_utf8($value) {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : self::utf8_substitute($value);
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[is_string($k) ? self::scrub_utf8($k) : $k] = self::scrub_utf8($v);
        }
        return $out;
    }

    /**
     * A string's invalid UTF-8 bytes replaced with U+FFFD.
     *
     * @param string $value
     * @return string
     */
    private static function utf8_substitute(string $value): string {
        $prev = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($prev);
        }
    }

    /**
     * Sort associative keys recursively; lists keep their order.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function sort_keys($value) {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::sort_keys($v);
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }

    /**
     * Canonical JSON, or null for null.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function json_or_null($value): ?string {
        return $value === null ? null : self::canonical_json($value);
    }

    /**
     * An int, or null for empty.
     *
     * @param mixed $value
     * @return int|null
     */
    private static function int_or_null($value): ?int {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * A trimmed string cut to the column length, or null.
     *
     * @param mixed $value
     * @param int $max
     * @return string|null
     */
    private static function str_or_null($value, int $max): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        return \core_text::substr(trim((string) $value), 0, $max);
    }
}
