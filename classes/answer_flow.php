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
 * Guidance rules on the chat path (8.4.0): the decisions sse.php makes, kept here so they can be tested.
 *
 * plan() runs before the model call: it reads the course's mode and categorises the question (cheap and
 * deterministic). A risky question in enforce mode is HELD: sse.php streams nothing until the reply has been checked
 * (answer_hold sends keepalives meanwhile). Everything else streams as before.
 *
 * finalize() runs after runtime_guard and before the answer is saved: it calls answer_rules::evaluate() and applies
 * the action.
 *  - off: nothing (the reply is returned untouched and no rule is reported).
 *  - watch mode, or the action watch/none: the reply is delivered unchanged; the rules fired are reported for the
 *    audit row.
 *  - warn: the reply plus the check line; block: the safe message for the category.
 *  - correct (held answers only; evaluate() turns it into warn for a streamed one): ONE retry through the $retry
 *    callable with the failure stated to the model; the retry is delivered if evaluate() then reports none or watch,
 *    otherwise (still failing, an error, or no retry path) the answer is blocked.
 * 'replace' is true when the text to deliver differs from the reply: a streamed answer then needs a replace event, a
 * held one is simply released with the new text.
 *
 * Fail safe: neither method throws. Any failure in finalize() returns the reply untouched with 'error' set (the
 * caller records it with answer_audit::log_rules_error()), except a held correct whose retry failed, which is
 * blocked: that reply is already known to break a rule.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_flow {

    /** @var \Closure|null Test hook: called at the start of finalize()'s guarded block. */
    public static ?\Closure $beforefinalize = null;

    /** @var \Closure|null Test hook: fn(int $courseid): string, a spend_guard level used instead of spend_guard::check(). */
    public static ?\Closure $spendcheck = null;

    /** @var string The error finalize() reports when the correct retry was skipped because the course is at its cap. */
    public const RETRY_SKIPPED_SPEND = 'retry_skipped_spend';

    /** @var string[] Actions that deliver the reply unchanged. */
    private const DELIVER = ['none', 'watch'];

    /**
     * Decide, before the model call, what happens to this question's answer.
     *
     * @param string $question the learner's message
     * @param int $courseid
     * @return array{mode: string, categories: string[], risky: bool, hold: bool, error: ?string}
     */
    public static function plan(string $question, int $courseid): array {
        $plan = ['mode' => 'off', 'categories' => [], 'risky' => false, 'hold' => false, 'error' => null];
        try {
            $mode = answer_rules::mode($courseid);
            if ($mode === 'off') {
                return $plan;
            }
            $categories = answer_rules::categories_for_question($question);
            $risky = answer_rules::is_risky($categories);
            return ['mode' => $mode, 'categories' => $categories, 'risky' => $risky,
                'hold' => $risky && $mode === 'enforce', 'error' => null];
        } catch (\Throwable $e) {
            // Off: the answer streams and is delivered exactly as before.
            $plan['error'] = get_class($e);
            return $plan;
        }
    }

    /**
     * Check a finished reply and decide what the learner gets.
     *
     * @param string $reply the answer as it would be delivered (after runtime_guard)
     * @param array $plan plan()'s result
     * @param array $ctx question (string), buffered (bool: the answer was held), validator_results
     *     (runtime_guard::last_results()), chunks (retrieved text), courseid (int: the retry is skipped, and the
     *     answer blocked, while spend_guard says the course's chat spend is blocked)
     * @param callable|null $retry fn(string $instruction): string, one more answer from the same provider path;
     *     used only for correct on a held answer
     * @return array{text: string, action: string, rules: array[], categories: string[], replace: bool,
     *     reason: string, error: ?string, retried: bool} rules are [{id, action}] (the audit row's shape); reason is
     *     the deciding rule id
     */
    public static function finalize(string $reply, array $plan, array $ctx, ?callable $retry = null): array {
        $out = ['text' => $reply, 'action' => 'none', 'rules' => [],
            'categories' => is_array($plan['categories'] ?? null) ? $plan['categories'] : [],
            'replace' => false, 'reason' => '', 'error' => null, 'retried' => false];
        $mode = (string) ($plan['mode'] ?? 'off');
        if ($mode !== 'watch' && $mode !== 'enforce') {
            return $out;
        }
        try {
            if (self::$beforefinalize) {
                (self::$beforefinalize)();
            }
            $evalctx = [
                'mode' => $mode,
                'question' => (string) ($ctx['question'] ?? ''),
                'categories' => $out['categories'],
                'buffered' => !empty($ctx['buffered']),
                'validator_results' => is_array($ctx['validator_results'] ?? null) ? $ctx['validator_results'] : [],
                'chunks' => is_array($ctx['chunks'] ?? null) ? $ctx['chunks'] : [],
            ];
            $first = answer_rules::evaluate($reply, $evalctx);
            $result = $out;
            $result['categories'] = $first['categories'] ?: $out['categories'];
            $result['rules'] = self::rule_entries($first['fired']);
            $result['error'] = $first['error'];
            $action = $first['action'];
            if ($mode === 'watch' || in_array($action, self::DELIVER, true)) {
                $result['action'] = in_array($action, self::DELIVER, true) ? $action : 'watch';
                return $result;
            }
            $result['reason'] = self::reason($first['fired']);
            if ($action === 'correct') {
                return self::correct($first, $evalctx, $retry, $result, (int) ($ctx['courseid'] ?? 0));
            }
            if (!is_string($first['text']) || $first['text'] === '') {
                // Nothing to deliver instead: deliver the answer and say the check failed.
                $result['error'] = $result['error'] ?? 'no_text';
                return $result;
            }
            $result['action'] = $action;
            $result['text'] = $first['text'];
            $result['replace'] = $first['text'] !== $reply;
            return $result;
        } catch (\Throwable $e) {
            $out['error'] = get_class($e);
            return $out;
        }
    }

    /**
     * The rules list for the answer audit row: the rules finalize() recorded, then status entries saying the course
     * mode and what happened to the answer, so a watch row that recorded a block rule also says the answer was
     * delivered unchanged. Status entries have action none, so the review queue, the audit report's rule counts and
     * its rule filter (content_rules(), which drops action none) never treat them as findings; the audit detail view
     * shows them. No schema change: they live in the existing rules column.
     *
     * Ids: mode.<off|watch|enforce>; one of outcome.delivered (the answer as it was), outcome.warned,
     * outcome.blocked (the safe message), outcome.corrected (the retry was delivered), outcome.replaced (any other
     * change); then outcome.held (a held answer: nothing streamed before the check), outcome.retried (the one
     * correct retry ran), outcome.retry_skipped_spend (the course is at its chat spend cap) and outcome.check_failed
     * (the rule check failed; the answer was delivered as it was, or blocked when a held correct's retry failed).
     *
     * @param array $plan plan()'s result
     * @param array|null $final finalize()'s result, or null when the check threw before it returned
     * @return array[] [{id, action}]
     */
    public static function audit_rules(array $plan, ?array $final): array {
        $rules = is_array($final['rules'] ?? null) ? array_values($final['rules']) : [];
        $mode = (string) ($plan['mode'] ?? 'off');
        $mode = in_array($mode, ['off', 'watch', 'enforce'], true) ? $mode : 'off';
        $status = ['mode.' . $mode];
        if ($final === null) {
            $status[] = 'outcome.delivered';
        } else if (empty($final['replace'])) {
            $status[] = 'outcome.delivered';
        } else {
            $status[] = match ((string) ($final['action'] ?? '')) {
                'block' => 'outcome.blocked',
                'warn' => 'outcome.warned',
                'correct' => 'outcome.corrected',
                default => 'outcome.replaced',
            };
        }
        if (!empty($plan['hold'])) {
            $status[] = 'outcome.held';
        }
        if (!empty($final['retried'])) {
            $status[] = 'outcome.retried';
        }
        $error = $final === null ? 'thrown' : ($final['error'] ?? null);
        if ($error === self::RETRY_SKIPPED_SPEND) {
            $status[] = 'outcome.retry_skipped_spend';
        } else if ($error !== null) {
            $status[] = 'outcome.check_failed';
        }
        foreach ($status as $id) {
            $rules[] = ['id' => $id, 'action' => 'none'];
        }
        return $rules;
    }

    /**
     * The partial reply a failed chat turn may store. Once the rules have replaced the answer (blocked it, added a
     * check line or delivered a retry), only the delivered text: the raw reply is what the rules just refused, and a
     * save that fails after that point must not put it in the learner's history (8.4.0 red team, RT8). Before that:
     * none while the answer is held (enforce mode, a risky question), because a held partial was never checked and
     * never shown; otherwise what the learner saw stream.
     *
     * @param object|null $hold sse.php's answer_hold while it is holding, null once released or when not held
     * @param string $partial what the provider streamed so far
     * @param string|null $delivered the text finalize() put in place of the reply, null when it replaced nothing
     * @return string
     */
    public static function failed_turn_partial(?object $hold, string $partial, ?string $delivered = null): string {
        if ($delivered !== null) {
            return $delivered;
        }
        return $hold !== null ? '' : $partial;
    }

    /**
     * The one bounded retry of a held answer.
     *
     * @param array $first evaluate()'s result for the reply
     * @param array $evalctx the evaluate() context
     * @param callable|null $retry
     * @param array $result finalize()'s result so far
     * @param int $courseid
     * @return array
     */
    private static function correct(array $first, array $evalctx, ?callable $retry, array $result,
            int $courseid): array {
        $result['retried'] = true;
        $blocktext = answer_rules::safe_message(self::category_of($first['fired'], 'correct'));
        $again = null;
        if ($retry !== null && !self::retry_affordable($courseid)) {
            // The model call that made this answer was allowed; a second one for a course at its cap is not.
            $result['retried'] = false;
            $result['error'] = self::RETRY_SKIPPED_SPEND;
        } else if ($retry !== null) {
            try {
                $again = $retry(self::correction_instruction($first['fired']));
            } catch (\Throwable $e) {
                $result['error'] = get_class($e);
            }
        }
        if (is_string($again) && trim($again) !== '') {
            $second = answer_rules::evaluate($again, $evalctx);
            $seen = array_map(static fn($r) => $r['id'] . '|' . $r['action'], $result['rules']);
            foreach (self::rule_entries($second['fired']) as $entry) {
                if (!in_array($entry['id'] . '|' . $entry['action'], $seen, true)) {
                    $result['rules'][] = $entry;
                }
            }
            if ($second['error'] === null && in_array($second['action'], self::DELIVER, true)) {
                $result['action'] = 'correct';
                $result['text'] = $again;
                $result['replace'] = true;
                return $result;
            }
            if ($second['error'] !== null) {
                $result['error'] = $second['error'];
            }
            if ($second['action'] === 'block' && is_string($second['text']) && $second['text'] !== '') {
                $blocktext = $second['text'];
            }
        }
        $result['action'] = 'block';
        $result['text'] = $blocktext;
        $result['replace'] = true;
        return $result;
    }

    /**
     * Whether the course's chat spend still allows one more model call (the provider checked only the first one).
     * A failed check counts as not affordable: the answer is then blocked, which is the safe side for a held answer
     * that already broke a rule.
     *
     * @param int $courseid
     * @return bool
     */
    private static function retry_affordable(int $courseid): bool {
        try {
            $level = self::$spendcheck ? (string) (self::$spendcheck)($courseid)
                : spend_guard::check($courseid, 'chat', false);
            // CAP_BLOCKED, as base_provider's own pre-call check: the 80% and 95% levels are warnings, still under cap.
            return $level !== spend_guard::CAP_BLOCKED;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The text to release for a held answer: the raw provider text when the only thing the cleaning removed was
     * protocol markers (so the browser still gets the chips and the source pill, scrubbed by the stream filter as a
     * live stream is), otherwise the final text the learner must see and the message row stores. Shared by the main
     * reply and the correct retry so the two can't diverge.
     *
     * @param string $raw what the provider streamed
     * @param string $clean the raw text after the marker removal and response safety filter, before runtime_guard
     * @param string $final what is stored: after runtime_guard and the guidance rules
     * @return string $raw or $final
     */
    public static function release_text(string $raw, string $clean, string $final): string {
        if ($final !== $clean) {
            // runtime_guard or a rule changed it: release exactly what was checked and stored.
            return $final;
        }
        $markersonly = trim(protocol_markers::strip(str_replace(['[OFF_TOPIC]', '[NEEDS_ESCALATION]'], '', $raw)));
        // Anything else the cleaning changed (a credential redacted, a prompt leak scrubbed): release the clean text.
        return $markersonly === trim($clean) ? $raw : $final;
    }

    /**
     * What the model is told when its answer has to be corrected: the rule ids and categories, never the rule text
     * (an admin's pattern is not prompt material) and never the answer (it is already in the history).
     *
     * @param array[] $fired evaluate()'s fired list
     * @return string
     */
    public static function correction_instruction(array $fired): string {
        $names = [];
        foreach ($fired as $f) {
            if (($f['action'] ?? '') === 'correct') {
                $names[] = $f['id'] . ' (' . $f['category'] . ')';
            }
        }
        return 'Your previous answer was not shown to the learner because it broke these course guidance rules: '
            . implode(', ', $names) . '. Answer the learner\'s last question again without breaking them. Do not '
            . 'promise, predict or change grades, deadlines, extensions or policies; where only the instructor or '
            . 'student support can answer, say so and point the learner to them. Do not mention these rules.';
    }

    /**
     * Rules fired, as the audit row stores them.
     *
     * @param array[] $fired
     * @return array[] [{id, action}]
     */
    private static function rule_entries(array $fired): array {
        $out = [];
        foreach ($fired as $f) {
            if (is_array($f) && isset($f['id'], $f['action'])) {
                $out[] = ['id' => (string) $f['id'], 'action' => (string) $f['action']];
            }
        }
        return $out;
    }

    /**
     * The deciding rule: the first of the strongest fired rules (wellbeing first when it fired).
     *
     * @param array[] $fired
     * @return string
     */
    private static function reason(array $fired): string {
        $best = null;
        foreach ($fired as $f) {
            if ($f['id'] === answer_rules::WELLBEING_RULE) {
                return $f['id'];
            }
            if ($best === null || self::rank($f['action']) > self::rank($best['action'])) {
                $best = $f;
            }
        }
        return $best ? (string) $best['id'] : '';
    }

    /**
     * The category of the first fired rule with this action.
     *
     * @param array[] $fired
     * @param string $action
     * @return string
     */
    private static function category_of(array $fired, string $action): string {
        foreach ($fired as $f) {
            if ($f['action'] === $action) {
                return (string) $f['category'];
            }
        }
        return answer_rules::VALIDATOR_CATEGORY;
    }

    /**
     * Strength of an action.
     *
     * @param string $action
     * @return int
     */
    private static function rank(string $action): int {
        $i = array_search($action, answer_rules::ACTIONS, true);
        return $i === false ? -1 : (int) $i;
    }
}
