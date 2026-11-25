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
 * Aggregates the three signals that an instructor would want to triage —
 * negative ratings, off-topic flags, integrity flags — into a single
 * per-course queue with a "Mark resolved" action.
 *
 * Each row in the queue is keyed by `(source, sourceid)` where source is
 * one of:
 *   - 'rating'     : a thumbs-down on an assistant message
 *                    (`local_ai_course_assistant_msg_ratings.id`)
 *   - 'offtopic'   : a conversation that hit the off-topic threshold
 *                    (`local_ai_course_assistant_convs.id`)
 *   - 'integrity'  : an audit row flagged by the integrity classifier
 *                    (`local_ai_course_assistant_audit.id`)
 *   - 'claim'      : 8.4.0, an answer whose latest background verdict found a
 *                    serious contradicted claim or the wellbeing model flag
 *   - 'rule'       : 8.4.0, an answer that recorded a guidance rule
 *                    (both keyed by the ANSWER row's `answer_audit.id`, and
 *                    derived from the audit rather than stored)
 *
 * Resolution state (with the teacher's outcome since 8.4.0) lives in `local_ai_course_assistant_review_res`. A
 * UNIQUE index on `(source, sourceid)` guarantees idempotent resolves.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review_queue {
    /** @var string */
    private const COMPONENT = 'local_ai_course_assistant';

    /** @var string[] every source a row can have */
    public const SOURCES = ['rating', 'offtopic', 'integrity', 'claim', 'rule'];

    /** @var string[] 8.4.0 sources derived from the answer audit; their sourceid is the answer row's id */
    public const ANSWER_SOURCES = ['claim', 'rule'];

    /** @var string[] a teacher's outcome on resolve (required for claim and rule items) */
    public const OUTCOMES = ['confirmed_bad', 'false_alarm', 'content_gap', 'rule_too_strict'];

    /** @var string[] verdict statuses that can make a claim item */
    private const CLAIM_STATUSES = ['needs_review', 'blocked'];

    /** @var int the answer is cut to this many characters in the detail */
    public const ANSWER_CHARS = 1500;

    /** @var int Most wellbeing rule items one course's list shows (the newest; the rest appear as these are resolved). */
    public const WELLBEING_MAX = 200;

    /** @var int WELLBEING_MAX; tests lower it. */
    public static int $wellbeingmax = self::WELLBEING_MAX;

    /**
     * Pending (unresolved) queue rows for a course, newest first.
     *
     * @param int $courseid
     * @param int $limit Maximum rows to return.
     * @return array<int, array{source:string, sourceid:int, when:int, summary:string, who:string}>
     */
    public static function pending_for_course(int $courseid, int $limit = 50): array {
        global $DB;

        $resolved = $DB->get_records(
            'local_ai_course_assistant_review_res',
            ['courseid' => $courseid],
            '',
            'id, source, sourceid'
        );
        $resolvedkeys = [];
        foreach ($resolved as $r) {
            $resolvedkeys[$r->source . ':' . $r->sourceid] = true;
        }

        $rows = [];

        // 1. Negative ratings on assistant messages in this course.
        // Issue #283: LEFT JOIN, and filter on the rating's own courseid.
        //
        // The conversation cap deletes the oldest messages once a conversation
        // passes 100, and it does not delete their ratings. With an INNER JOIN
        // and a filter on m.courseid, a learner's thumbs-down silently left
        // this queue the moment their message was trimmed, which is backwards:
        // the longer and more engaged the conversation, the more likely the
        // feedback disappeared. rated_excerpt keeps the text the learner was
        // reacting to, so the row still says something an instructor can act on.
        $rsql = "SELECT r.id AS sourceid, r.timecreated AS twhen, r.rating, r.userid,
                        COALESCE(m.message, r.rated_excerpt) AS message, r.courseid
                   FROM {local_ai_course_assistant_msg_ratings} r
              LEFT JOIN {local_ai_course_assistant_msgs} m ON m.id = r.messageid
                  WHERE r.courseid = :courseid AND r.rating = -1
                  ORDER BY r.timecreated DESC";
        try {
            foreach ($DB->get_records_sql($rsql, ['courseid' => $courseid], 0, $limit) as $r) {
                if (isset($resolvedkeys['rating:' . $r->sourceid])) {
                    continue;
                }
                $rows[] = [
                    'source'   => 'rating',
                    'sourceid' => (int) $r->sourceid,
                    'when'     => (int) $r->twhen,
                    'summary'  => mb_substr((string) $r->message, 0, 200),
                    'who'      => anonymizer::name((int) $r->userid),
                ];
            }
        } catch (\Throwable $e) {
            /* tables may not exist on stripped installs */
        }

        // 2. Conversations that hit the off-topic threshold.
        $rawofftopic = get_config('local_ai_course_assistant', 'offtopic_max_per_session');
        $offtopicmin = ($rawofftopic === false || $rawofftopic === '') ? 3 : (int) $rawofftopic;
        try {
            $convs = $DB->get_records_sql(
                "SELECT id AS sourceid, userid, offtopic_count, timemodified AS twhen
                   FROM {local_ai_course_assistant_convs}
                  WHERE courseid = :courseid AND offtopic_count >= :minct
                  ORDER BY timemodified DESC",
                ['courseid' => $courseid, 'minct' => $offtopicmin],
                0,
                $limit
            );
            foreach ($convs as $c) {
                if (isset($resolvedkeys['offtopic:' . $c->sourceid])) {
                    continue;
                }
                $rows[] = [
                    'source'   => 'offtopic',
                    'sourceid' => (int) $c->sourceid,
                    'when'     => (int) $c->twhen,
                    'summary'  => 'Off-topic count: ' . (int) $c->offtopic_count,
                    'who'      => anonymizer::name((int) $c->userid),
                ];
            }
        } catch (\Throwable $e) {
            /* ignored */
        }

        // 3. Integrity audit flags for this course.
        try {
            $audit = $DB->get_records_sql(
                // The audit table has `action` and `details`, not `event` and
                // `payload` -- so this threw a dml_exception into the swallow
                // below and the instructor review queue's integrity section has
                // always been empty. NOTE: nothing writes 'integrity_flagged'
                // either, so correcting the columns removes a latent trap rather
                // than making a number move.
                "SELECT id AS sourceid, userid, details AS payload, timecreated AS twhen
                   FROM {local_ai_course_assistant_audit}
                  WHERE action = :event AND courseid = :courseid
                  ORDER BY timecreated DESC",
                ['event' => 'integrity_flagged', 'courseid' => $courseid],
                0,
                $limit
            );
            foreach ($audit as $a) {
                if (isset($resolvedkeys['integrity:' . $a->sourceid])) {
                    continue;
                }
                $payload = is_string($a->payload) ? json_decode($a->payload, true) : null;
                $kind = is_array($payload) && isset($payload['kind']) ? (string) $payload['kind'] : 'integrity';
                $rows[] = [
                    'source'   => 'integrity',
                    'sourceid' => (int) $a->sourceid,
                    'when'     => (int) $a->twhen,
                    'summary'  => 'Integrity: ' . $kind,
                    'who'      => anonymizer::name((int) $a->userid),
                ];
            }
        } catch (\Throwable $e) {
            /* ignored */
        }

        // 4. 8.4.0: claim and rule items, derived from the answer audit (never inserted on their own).
        foreach (self::answer_items($courseid, $limit, $resolvedkeys) as $item) {
            $rows[] = $item;
        }

        // Wellbeing flags first, then newest-first across all sources, then cap.
        usort($rows, static function ($a, $b) {
            $pa = (int) ($a['priority'] ?? 0);
            $pb = (int) ($b['priority'] ?? 0);
            if ($pa !== $pb) {
                return $pb - $pa;
            }
            return $b['when'] - $a['when'];
        });
        return array_slice($rows, 0, $limit);
    }

    /**
     * Claim and rule items for a course, from the answer audit.
     *
     * One item per answer row, keyed by the ANSWER row's id (never a verdict row's). `claim`: the answer's latest
     * verdict is needs_review or blocked and carries a contradicted claim of high or medium severity, or the
     * wellbeing model flag (priority 1, listed first). `rule`: the answer row recorded a guidance rule with action
     * block, warn or correct, or (only while the course's mode is watch) watch; never when a claim item exists for
     * that answer, resolved or not. Whether a wellbeing rule makes an item is decided by the mode recorded on the
     * answer row (answer_rules::recorded_mode(); the course's mode now for a row without one), so it stays when
     * the course changes mode. An item whose answer recorded a wellbeing rule that makes an item
     * (answer_rules::wellbeing_rule_fired()), rule or claim, has priority 1 and is not counted against the cap, as the
     * wellbeing model flag it stands in for. Failed checks (status check_failed, rule ids starting claim_check.) are
     * not content findings and never make an item.
     *
     * @param int $courseid
     * @param int $limit
     * @param array $resolvedkeys "source:sourceid" => true
     * @return array[]
     */
    private static function answer_items(int $courseid, int $limit, array $resolvedkeys): array {
        global $DB;
        $items = [];
        $claimanswers = [];
        try {
            $mode = answer_rules::mode($courseid);
            $cms = null;

            // Latest verdict per answer, of this course, with an answer row of this course.
            [$statusin, $statusparams] = $DB->get_in_or_equal(self::CLAIM_STATUSES, SQL_PARAMS_NAMED, 'st');
            $vsql = "SELECT v.id AS vid, v.supersedes AS aid, v.status, v.severity, v.claims, v.rules AS vrules,
                            v.timecreated AS vwhen, a.rules AS arules
                       FROM {" . answer_audit::TABLE . "} v
                       JOIN {" . answer_audit::TABLE . "} a ON a.id = v.supersedes AND a.kind = :akind
                                AND a.courseid = v.courseid
                      WHERE v.courseid = :courseid AND v.kind = :vkind AND v.status $statusin
                            AND v.id = (SELECT MAX(v2.id) FROM {" . answer_audit::TABLE . "} v2
                                         WHERE v2.kind = :v2kind AND v2.supersedes = v.supersedes)
                   ORDER BY v.id DESC";
            $params = ['akind' => 'answer', 'vkind' => 'verdict', 'v2kind' => 'verdict', 'courseid' => $courseid]
                + $statusparams;
            $verdicts = [];
            // Wellbeing items are never capped here: they sort first, so a busy course must not push them out
            // before pending_for_course() applies priority. Only the other claim items count toward $limit. A claim
            // item is a wellbeing item when the judge flagged the question, or when the answer recorded a wellbeing
            // rule that makes an item (the judge's flag is suppressed then, and this claim item takes the answer).
            $capped = 0;
            $rs = $DB->get_recordset_sql($vsql, $params);
            foreach ($rs as $v) {
                $claims = self::json_list($v->claims);
                $vrules = self::content_rules(self::json_list($v->vrules));
                $flag = in_array(claim_checker::WELLBEING_MODEL_RULE, array_column($vrules, 'id'), true);
                $arules = self::json_list($v->arules);
                $wellbeing = $flag || answer_rules::wellbeing_rule_fired(self::content_rules($arules),
                    answer_rules::recorded_mode($arules, $mode));
                $serious = false;
                foreach ($claims as $c) {
                    if (($c['status'] ?? '') === 'contradicted' && in_array($c['severity'] ?? '', ['high', 'medium'], true)) {
                        $serious = true;
                        break;
                    }
                }
                if (!$flag && !$serious) {
                    // A wellbeing rule alone is a rule item, below.
                    continue;
                }
                $claimanswers[(int) $v->aid] = true;
                if (isset($resolvedkeys['claim:' . (int) $v->aid]) || (!$wellbeing && $capped >= $limit)) {
                    continue;
                }
                if (!$wellbeing) {
                    $capped++;
                }
                $verdicts[(int) $v->aid] = ['verdict' => $v, 'claims' => $claims, 'vrules' => $vrules,
                    'wellbeing' => $wellbeing];
            }
            $rs->close();

            if ($verdicts) {
                [$in, $inparams] = $DB->get_in_or_equal(array_keys($verdicts), SQL_PARAMS_NAMED, 'aid');
                $answers = $DB->get_records_select(answer_audit::TABLE,
                    "id $in AND kind = :kind AND courseid = :courseid",
                    $inparams + ['kind' => 'answer', 'courseid' => $courseid]);
                foreach ($verdicts as $aid => $found) {
                    if (!isset($answers[$aid])) {
                        continue;
                    }
                    $cms = $cms ?? self::course_cms($courseid);
                    $items[] = self::claim_item($answers[$aid], $found, $cms);
                }
            }

            // Answer rows that recorded a guidance rule with an action that can make an item. Status entries
            // (mode.*, outcome.*, action none) are on nearly every row, so the filter names the actions.
            $actionlikes = [];
            $ruleparams = ['courseid' => $courseid, 'kind' => 'answer'];
            foreach (['block', 'warn', 'correct', 'watch'] as $i => $act) {
                $actionlikes[] = $DB->sql_like('rules', ':act' . $i);
                $ruleparams['act' . $i] = '%"action":"' . $act . '"%';
            }
            $rulebase = "SELECT id, userid, mode, question, answer, chunks, rules, redacted, timecreated
                           FROM {" . answer_audit::TABLE . "}
                          WHERE courseid = :courseid AND kind = :kind AND rules IS NOT NULL
                                AND (" . implode(' OR ', $actionlikes) . ")";
            $seen = [];
            // A rule item for a wellbeing rule (id wellbeing.*) is what the claim check's wellbeing model flag would
            // have been (claim_checker::wellbeing_caught() suppresses the flag then): priority 1, listed first and,
            // like the flag, never counted toward $limit, so a busy course can't hide it. Taken first, newest first,
            // and bounded in SQL (8.4.0 red team, RT4): the newest $wellbeingmax unresolved rows, so a learner or a
            // script that trips the rule thousands of times cannot make this page load every one of those
            // questions and answers. Resolved rows are left out in SQL, so resolving the ones shown brings up the
            // next ones.
            $wblike = $DB->sql_like('rules', ':wb');
            $notresolved = "NOT EXISTS (SELECT 1 FROM {local_ai_course_assistant_review_res} rr
                                         WHERE rr.source = :rsource AND rr.sourceid = {" . answer_audit::TABLE . "}.id)";
            $wbparams = ['wb' => '%"id":"wellbeing.%'];
            $wellbeingitems = 0;
            $max = max(1, self::$wellbeingmax);
            $rs = $DB->get_recordset_sql($rulebase . " AND $wblike AND $notresolved ORDER BY id DESC",
                $ruleparams + $wbparams + ['rsource' => 'rule'], 0, $max);
            foreach ($rs as $a) {
                $item = self::rule_candidate($a, $mode, $claimanswers, $resolvedkeys);
                if ($item !== null && $item['wellbeing']) {
                    $seen[(int) $a->id] = true;
                    $items[] = self::rule_item($a, $item['rules'], self::latest_verdict((int) $a->id), true);
                    $wellbeingitems++;
                }
            }
            $rs->close();

            // Every other rule item. Once the wellbeing cap is full this pass leaves the wellbeing rows out in SQL,
            // so a flood is not read a second time; below the cap it still picks up a wellbeing item the first pass
            // did not reach (rows that matched its filter without making an item took its places), up to the cap.
            $full = $wellbeingitems >= $max;
            $rs = $DB->get_recordset_sql($rulebase . ($full ? ' AND NOT (' . $wblike . ')' : '') . " ORDER BY id DESC",
                $ruleparams + ($full ? $wbparams : []));
            $ruleitems = 0;
            foreach ($rs as $a) {
                if ($ruleitems >= $limit) {
                    break;
                }
                if (isset($seen[(int) $a->id])) {
                    continue;
                }
                $item = self::rule_candidate($a, $mode, $claimanswers, $resolvedkeys);
                if ($item === null || ($item['wellbeing'] && $wellbeingitems >= $max)) {
                    continue;
                }
                $items[] = self::rule_item($a, $item['rules'], self::latest_verdict((int) $a->id), $item['wellbeing']);
                if ($item['wellbeing']) {
                    $wellbeingitems++;
                } else {
                    $ruleitems++;
                }
            }
            $rs->close();
        } catch (\Throwable $e) {
            debugging('review_queue: answer items skipped: ' . get_class($e), DEBUG_DEVELOPER);
        }
        return $items;
    }

    /**
     * A claim item.
     *
     * @param \stdClass $a the answer row
     * @param array $found verdict, claims, vrules, wellbeing
     * @param array $cms cmid => activity name
     * @return array
     */
    private static function claim_item(\stdClass $a, array $found, array $cms): array {
        $v = $found['verdict'];
        $rules = array_merge(self::content_rules(self::json_list($a->rules)), $found['vrules']);
        $kind = get_string($found['wellbeing'] ? 'instructor_dashboard:review_kind_wellbeing'
            : 'instructor_dashboard:review_kind_claim', self::COMPONENT);
        $detail = self::detail($a, (string) $v->status, (string) ($v->severity ?? ''), $found['claims'], $rules, $cms);
        return [
            'source' => 'claim',
            'sourceid' => (int) $a->id,
            'when' => (int) $v->vwhen,
            'summary' => self::summary($kind, $detail),
            'who' => anonymizer::name((int) $a->userid),
            'priority' => $found['wellbeing'] ? 1 : 0,
            'detail' => $detail,
        ];
    }

    /**
     * Whether an answer row that recorded rules makes a rule item: not when a claim item exists for the answer or the
     * item is resolved, and only for a block, warn or correct rule, a watch rule while the course mode is watch, or
     * a wellbeing rule that made an item under the mode recorded on the row (answer_rules::recorded_mode()).
     *
     * @param \stdClass $a the answer row
     * @param string $mode the course's answer safety mode
     * @param array $claimanswers answer id => true for answers with a claim item
     * @param array $resolvedkeys "source:sourceid" => true
     * @return array{rules: array[], wellbeing: bool}|null
     */
    private static function rule_candidate(\stdClass $a, string $mode, array $claimanswers, array $resolvedkeys): ?array {
        $aid = (int) $a->id;
        if (isset($claimanswers[$aid]) || isset($resolvedkeys['rule:' . $aid])) {
            return null;
        }
        $recorded = self::json_list($a->rules);
        $rules = self::content_rules($recorded);
        $actions = array_column($rules, 'action');
        $strong = (bool) array_intersect($actions, ['block', 'warn', 'correct']);
        $watch = in_array('watch', $actions, true) && $mode === 'watch';
        // A wellbeing rule is judged by the mode the answer was recorded under, as claim_checker::wellbeing_caught()
        // judged it when it suppressed the judge's flag: a watch-action wellbeing rule recorded in Watch stays an
        // item (first, with the badge) after the course moves to Enforce.
        $wellbeing = answer_rules::wellbeing_rule_fired($rules, answer_rules::recorded_mode($recorded, $mode));
        if (!$strong && !$watch && !$wellbeing) {
            return null;
        }
        return ['rules' => $rules, 'wellbeing' => $wellbeing];
    }

    /**
     * An answer's latest verdict (id, status, severity), or null.
     *
     * @param int $aid
     * @return \stdClass|null
     */
    private static function latest_verdict(int $aid): ?\stdClass {
        global $DB;
        $latest = $DB->get_records(answer_audit::TABLE, ['kind' => 'verdict', 'supersedes' => $aid],
            'id DESC', 'id, status, severity', 0, 1);
        return $latest ? reset($latest) : null;
    }

    /**
     * A rule item.
     *
     * @param \stdClass $a the answer row
     * @param array $rules the content rules it recorded
     * @param \stdClass|null $latest its latest verdict, if any
     * @param bool $wellbeing a wellbeing rule fired: priority 1 (listed first, with the badge)
     * @return array
     */
    private static function rule_item(\stdClass $a, array $rules, ?\stdClass $latest, bool $wellbeing = false): array {
        $status = $latest && $latest->status !== 'check_failed' ? (string) $latest->status : '';
        $severity = $latest && $latest->status !== 'check_failed' ? (string) ($latest->severity ?? '') : '';
        $detail = self::detail($a, $status, $severity, [], $rules, []);
        return [
            'source' => 'rule',
            'sourceid' => (int) $a->id,
            'when' => (int) $a->timecreated,
            'summary' => self::summary(get_string('instructor_dashboard:review_kind_rule', self::COMPONENT), $detail),
            'who' => anonymizer::name((int) $a->userid),
            'priority' => $wellbeing ? 1 : 0,
            'detail' => $detail,
        ];
    }

    /**
     * The detail of an answer item: text only when the row is not redacted.
     *
     * @param \stdClass $a the answer row
     * @param string $status verdict status, '' for none
     * @param string $severity verdict severity, '' for none
     * @param array $claims verdict claims
     * @param array $rules [{id, action}]
     * @param array $cms cmid => activity name
     * @return array{redacted: bool, voice: bool, question: string, answer: string, status: string, severity: string,
     *     claims: array, rules: array, passages: int} voice: a live voice turn, whose transcript the learner's browser
     *     reported
     */
    private static function detail(\stdClass $a, string $status, string $severity, array $claims, array $rules,
            array $cms): array {
        $redacted = !empty($a->redacted) || ($a->question === null && $a->answer === null);
        $chunkcms = [];
        $passages = 0;
        foreach (self::json_list($a->chunks) as $chunk) {
            if (is_string($chunk['ref'] ?? null) && $chunk['ref'] !== '') {
                $passages++;
                $chunkcms[$chunk['ref']] = (int) ($chunk['cmid'] ?? 0);
            }
        }
        $outclaims = [];
        foreach ($claims as $c) {
            $ref = is_string($c['evidence_ref'] ?? null) ? $c['evidence_ref'] : '';
            $cmid = $chunkcms[$ref] ?? 0;
            if ($ref === '') {
                $evidence = get_string('instructor_dashboard:review_evidence_none', self::COMPONENT);
            } else if ($cmid > 0 && isset($cms[$cmid])) {
                $evidence = $cms[$cmid];
            } else {
                $evidence = get_string('instructor_dashboard:review_evidence_passage', self::COMPONENT);
            }
            $outclaims[] = [
                'text' => $redacted ? '' : (string) ($c['text'] ?? ''),
                'status' => (string) ($c['status'] ?? ''),
                'severity' => (string) ($c['severity'] ?? ''),
                'evidence' => $evidence,
            ];
        }
        $outrules = [];
        $seen = [];
        foreach ($rules as $r) {
            $key = $r['id'] . '|' . $r['action'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $outrules[] = ['id' => $r['id'], 'action' => $r['action']];
            }
        }
        return [
            'redacted' => $redacted,
            'voice' => answer_audit_report::source($a) === answer_audit_report::SOURCE_VOICE,
            'question' => $redacted ? '' : (string) $a->question,
            'answer' => $redacted ? '' : \core_text::substr((string) $a->answer, 0, self::ANSWER_CHARS),
            'status' => $status,
            'severity' => $severity,
            'claims' => $outclaims,
            'rules' => $outrules,
            'passages' => $passages,
        ];
    }

    /**
     * The one-line summary: kind, verdict, rule ids, then the question or "text removed". At most 200 characters.
     *
     * @param string $kind
     * @param array $detail
     * @return string
     */
    private static function summary(string $kind, array $detail): string {
        $parts = [$kind];
        if ($detail['status'] !== '') {
            $parts[] = answer_labels::verdict($detail['status'], $detail['severity']);
        }
        if ($detail['rules']) {
            $parts[] = answer_labels::rules($detail['rules']);
        }
        $parts[] = $detail['redacted'] ? get_string('instructor_dashboard:review_text_removed', self::COMPONENT)
            : trim(preg_replace('/\s+/u', ' ', $detail['question']));
        return \core_text::substr(implode(' · ', $parts), 0, 200);
    }

    /**
     * Rules that are content findings: [{id, action}] with a string id that does not start claim_check., and an
     * action other than none.
     *
     * @param array $rules
     * @return array[]
     */
    private static function content_rules(array $rules): array {
        $out = [];
        foreach ($rules as $r) {
            if (!is_array($r) || !is_string($r['id'] ?? null) || !is_string($r['action'] ?? null)) {
                continue;
            }
            if (str_starts_with($r['id'], claim_checker::FAILED_RULE_PREFIX) || $r['action'] === 'none') {
                continue;
            }
            $out[] = ['id' => $r['id'], 'action' => $r['action']];
        }
        return $out;
    }

    /**
     * A JSON list column decoded, or [].
     *
     * @param string|null $json
     * @return array
     */
    private static function json_list(?string $json): array {
        $decoded = $json === null ? null : json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * Activity names of a course, by cmid.
     *
     * @param int $courseid
     * @return array<int, string>
     */
    private static function course_cms(int $courseid): array {
        try {
            $out = [];
            foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
                // The raw name: the template escapes it (a formatted name would be escaped twice).
                $out[(int) $cm->id] = (string) $cm->name;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Mark a queue row resolved. Idempotent: a duplicate resolve is a no-op.
     *
     * An unknown source is ignored, as before 8.4.0. An outcome other than '' or one of OUTCOMES is refused. A
     * `claim` or `rule` item needs an outcome, and its answer row must belong to $courseid (so a teacher cannot
     * resolve another course's item by editing the request).
     *
     * @param string $source 'rating' | 'offtopic' | 'integrity' | 'claim' | 'rule'
     * @param int $sourceid
     * @param int $courseid
     * @param int $userid Resolver's user id.
     * @param string $note Optional free-text note.
     * @param string $outcome '' or one of OUTCOMES
     * @return void
     * @throws \moodle_exception instructor_dashboard:review_bad_outcome, review_outcome_required, review_not_in_course
     */
    public static function mark_resolved(string $source, int $sourceid, int $courseid, int $userid, string $note = '',
            string $outcome = ''): void {
        global $DB;
        if (!in_array($source, self::SOURCES, true)) {
            return;
        }
        if ($outcome !== '' && !in_array($outcome, self::OUTCOMES, true)) {
            throw new \moodle_exception('instructor_dashboard:review_bad_outcome', self::COMPONENT);
        }
        if (in_array($source, self::ANSWER_SOURCES, true)) {
            if ($outcome === '') {
                throw new \moodle_exception('instructor_dashboard:review_outcome_required', self::COMPONENT);
            }
            if (!$DB->record_exists(answer_audit::TABLE, ['id' => $sourceid, 'kind' => 'answer',
                    'courseid' => $courseid])) {
                throw new \moodle_exception('instructor_dashboard:review_not_in_course', self::COMPONENT);
            }
        }
        if (
            $DB->record_exists(
                'local_ai_course_assistant_review_res',
                ['source' => $source, 'sourceid' => $sourceid]
            )
        ) {
            return;
        }
        $row = new \stdClass();
        $row->source = $source;
        $row->sourceid = $sourceid;
        $row->courseid = $courseid;
        $row->resolved_by = $userid;
        $row->note = $note !== '' ? $note : null;
        $row->outcome = $outcome;
        $row->timecreated = time();
        $DB->insert_record('local_ai_course_assistant_review_res', $row);
    }

    /**
     * Add the outcome column to the resolutions table if it is missing (upgrade step 2026112302). Safe to run twice.
     *
     * @return void
     */
    public static function add_outcome_field(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_ai_course_assistant_review_res');
        $field = new \xmldb_field('outcome', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null, 'timecreated');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
    }

    /**
     * The review part of the instructor dashboard's template context: the rows (with a resolve form each and, for
     * claim and rule items, the detail) and their labels. All text goes through Mustache escaping.
     *
     * @param array $pending pending_for_course() rows
     * @param int $courseid
     * @param int $range
     * @param int $gapdays
     * @return array
     */
    public static function template_context(array $pending, int $courseid, int $range, int $gapdays): array {
        $str = static fn(string $id, $a = null): string => get_string('instructor_dashboard:' . $id, self::COMPONENT, $a);
        $rows = [];
        foreach ($pending as $row) {
            $answeritem = in_array($row['source'], self::ANSWER_SOURCES, true);
            $outcomes = [];
            foreach (self::OUTCOMES as $outcome) {
                $outcomes[] = ['value' => $outcome, 'label' => $str('review_outcome_' . $outcome)];
            }
            $out = [
                'when' => userdate($row['when'], '%Y-%m-%d %H:%M'),
                'sourcelabel' => $str('review_source_' . $row['source']),
                'who' => $row['who'],
                'summary' => $row['summary'],
                'priority_high' => !empty($row['priority']),
                'source' => $row['source'],
                'sourceid' => (int) $row['sourceid'],
                'formid' => 'aica-review-' . $row['source'] . '-' . (int) $row['sourceid'],
                'outcome_required' => $answeritem,
                'outcomes' => $outcomes,
                'has_detail' => false,
                'voice' => $answeritem && !empty($row['detail']['voice']),
            ];
            if ($answeritem && isset($row['detail'])) {
                $d = $row['detail'];
                $out['has_detail'] = true;
                $out['detail'] = [
                    'redacted' => $d['redacted'],
                    'question' => $d['question'],
                    'answer' => $d['answer'],
                    'has_verdict' => $d['status'] !== '',
                    'verdict' => $d['status'] !== '' ? answer_labels::verdict($d['status'], $d['severity']) : '',
                    'has_claims' => !empty($d['claims']),
                    'claims' => array_map(static fn(array $c): array => [
                        'text' => $c['text'],
                        'has_text' => $c['text'] !== '',
                        'meta' => $str('review_claim_meta', (object) [
                            'status' => answer_labels::claim_status($c['status']),
                            'severity' => $c['severity'] !== '' ? answer_labels::severity($c['severity']) : '-',
                            'evidence' => $c['evidence'],
                        ]),
                    ], $d['claims']),
                    'has_rules' => !empty($d['rules']),
                    'rules' => array_map(static fn(array $r): array => [
                        'id' => $r['id'],
                        'action' => answer_labels::action($r['action']),
                    ], $d['rules']),
                    'passages' => $str('review_passages', $d['passages']),
                ];
            }
            $rows[] = $out;
        }
        return [
            'review_rows' => $rows,
            'review_formaction' => (new \moodle_url('/local/ai_course_assistant/instructor_dashboard.php'))->out(false),
            'review_courseid' => $courseid,
            'review_range' => $range,
            'review_gapdays' => $gapdays,
            'review_sesskey' => sesskey(),
            'review_labels' => [
                'resolve' => $str('review_resolve'),
                'outcome' => $str('review_outcome'),
                'outcome_none' => $str('review_outcome_none'),
                'note' => $str('review_note'),
                'detail' => $str('review_detail'),
                'question' => $str('review_detail_question'),
                'answer' => $str('review_detail_answer'),
                'claims' => $str('review_detail_claims'),
                'rules' => $str('review_detail_rules'),
                'text_removed' => $str('review_text_removed'),
                'priority' => $str('review_priority_high'),
                'col_resolve' => $str('review_col_resolve'),
                'caption' => $str('review_caption'),
                'audit_link' => $str('review_audit_link'),
                'voice' => get_string('answeraudit:source_voice', self::COMPONENT),
                'voice_note' => get_string('answeraudit:source_voice_note', self::COMPONENT),
            ],
        ];
    }
}
