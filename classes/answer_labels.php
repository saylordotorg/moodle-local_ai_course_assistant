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
 * Readable labels for the codes the answer audit stores (8.4.0): verdict statuses, severities, claim statuses and
 * types, rule actions, topic categories, and the mode.* and outcome.* entries.
 *
 * For the pages a teacher reads (the answer audit page and the review queue) only. Stored rows, the hash chain and
 * the CSV and JSON exports keep the codes. A code with no string is shown as it is, so a new code never breaks a
 * page. Every label is plain text: the template escapes it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class answer_labels {

    /** @var string */
    private const COMPONENT = 'local_ai_course_assistant';

    /**
     * The string for a code, or the code itself when there is none.
     *
     * @param string $prefix string id up to the code, e.g. "answeraudit:severity_"
     * @param string $code
     * @return string
     */
    private static function label(string $prefix, string $code): string {
        if ($code === '' || !preg_match('/^[a-z0-9_.]+$/', $code)) {
            return $code;
        }
        $id = $prefix . str_replace('.', '_', $code);
        return get_string_manager()->string_exists($id, self::COMPONENT) ? get_string($id, self::COMPONENT) : $code;
    }

    /**
     * A verdict status (ok, warn, blocked, needs_review, check_failed, unchecked).
     *
     * @param string $status
     * @return string
     */
    public static function status(string $status): string {
        return self::label('answeraudit:status_', $status);
    }

    /**
     * A severity (high, medium, low).
     *
     * @param string $severity
     * @return string
     */
    public static function severity(string $severity): string {
        return self::label('answeraudit:severity_', $severity);
    }

    /**
     * A verdict status with its severity, or the status alone when there is no severity.
     *
     * @param string $status
     * @param string $severity
     * @return string
     */
    public static function verdict(string $status, string $severity): string {
        if ($severity === '') {
            return self::status($status);
        }
        return get_string('instructor_dashboard:review_verdict', self::COMPONENT, (object) [
            'status' => self::status($status),
            'severity' => self::severity($severity),
        ]);
    }

    /**
     * A claim's status (supported, not_in_course, contradicted, uncheckable).
     *
     * @param string $status
     * @return string
     */
    public static function claim_status(string $status): string {
        return self::label('answeraudit:claim_', $status);
    }

    /**
     * A claim's type (date, deadline, grade, policy, number, general).
     *
     * @param string $type
     * @return string
     */
    public static function claim_type(string $type): string {
        return self::label('answeraudit:type_', $type);
    }

    /**
     * What a rule did (block, warn, correct, watch).
     *
     * @param string $action
     * @return string
     */
    public static function action(string $action): string {
        return self::label('answeraudit:action_', $action);
    }

    /**
     * A rule with what it did: "deadlines.date: Warning added". The rule id stays as it is (it is the name an
     * administrator gave the rule, and what the Rule filter lists).
     *
     * @param string $id
     * @param string $action
     * @return string
     */
    public static function rule(string $id, string $action): string {
        return get_string('answeraudit:rule_with_action', self::COMPONENT, (object) [
            'rule' => $id,
            'action' => self::action($action),
        ]);
    }

    /**
     * Rules, each with what it did, joined.
     *
     * @param array[] $rules [{id, action}]
     * @return string
     */
    public static function rules(array $rules): string {
        return implode(', ', array_map(static fn(array $r): string => self::rule((string) $r['id'],
            (string) $r['action']), $rules));
    }

    /**
     * A topic category (grades, deadlines, policy, wellbeing, advice, integrity, numbers).
     *
     * @param string $category
     * @return string
     */
    public static function category(string $category): string {
        return self::label('answer_rules:category_', $category);
    }

    /**
     * A mode.* or outcome.* entry (answer_flow::audit_rules()): the course's mode, or what happened to the answer.
     *
     * @param string $id
     * @return string
     */
    public static function flow(string $id): string {
        return self::label('answeraudit:flow_', $id);
    }
}
