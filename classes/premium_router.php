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

defined('MOODLE_INTERNAL') || die();

/**
 * Premium escalation router (A.10 follow-on, shipped v5.12.0).
 *
 * Decides per-turn whether the learner's chat call should be routed to the
 * premium tier (Claude Opus 4.8 by default) instead of the workhorse chat
 * provider (Gemini 2.5 Flash by default). Two trigger paths:
 *
 *  1. Message-content regex match — admin-configured list (default ships
 *     with multi-step STEM markers: "derive", "prove that", "step by step",
 *     LaTeX math `$...$`, fenced code blocks, big-O, integrals, etc.).
 *  2. Course allowlist — admin-configured list of course shortnames or
 *     idnumbers where every turn auto-escalates regardless of regex.
 *
 * The router is OFF by default. When enabled, ~5% of typical-course turns
 * get the premium tier; STEM-tagged courses escalate at a higher rate per
 * the course allowlist. Cost at 100k Saylor MAU with 5% escalation: ~$700/mo.
 *
 * Falls back to the workhorse provider on any error so a misconfigured
 * premium row cannot break the chat path.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class premium_router {

    /**
     * Default trigger regex set (one per line). Curated from the A.10
     * bake-off's hard_math / hard_cs / hard_science categories.
     * Admins can override via the premium_escalation_triggers setting.
     *
     * Each line is a PCRE regex (without the wrapping delimiters or
     * modifiers — the evaluator adds `~i` to make case-insensitive
     * matching consistent across admin entries).
     */
    public const DEFAULT_TRIGGERS = <<<'TXT'
\bderive\b
\bprove\s+that\b
\bproof\s+by\s+induction\b
\bstep[\s-]*by[\s-]*step\b
\bfrom\s+first\s+principles\b
\bbig[\s-]*[oO]\b|\bO\s*\(\s*\w
\b(integrate|integral|derivative|differentiate|differential\s+equation)\b
\b(optimi[sz]ation|stoichiometr|thermodynamic|free\s+body|electromagnetic)\b
\b(complexity\s+proof|recurrence\s+relation|amortiz)\b
\\\\(int|sum|frac|sqrt|partial|nabla|infty)\b
\$[^\$\n]{2,}?\$
```
TXT;

    /**
     * Decide whether to escalate this turn to the premium tier.
     *
     * @param string $usermessage The student's latest message text.
     * @param int $courseid The Moodle course id for context.
     * @return array {
     *     escalate: bool,
     *     reason: string,   // 'disabled' | 'course_tag' | 'trigger:<regex>' | 'none'
     *     provider: ?string, // resolved premium provider id or null
     *     model: ?string,    // resolved premium model or null
     * }
     */
    public static function decide(string $usermessage, int $courseid): array {
        $enabled = (bool) get_config('local_ai_course_assistant', 'premium_escalation_enabled');
        if (!$enabled) {
            return ['escalate' => false, 'reason' => 'disabled', 'provider' => null, 'model' => null];
        }

        $provider = trim((string) get_config('local_ai_course_assistant', 'premium_escalation_provider'));
        $model = trim((string) get_config('local_ai_course_assistant', 'premium_escalation_model'));
        if ($provider === '' || $model === '') {
            return ['escalate' => false, 'reason' => 'unconfigured', 'provider' => null, 'model' => null];
        }

        // Course-allowlist fast path: every turn in a tagged course escalates.
        if ($courseid > 0 && self::course_matches_allowlist($courseid)) {
            return ['escalate' => true, 'reason' => 'course_tag', 'provider' => $provider, 'model' => $model];
        }

        // Regex trigger evaluation.
        $matched = self::first_matching_trigger($usermessage);
        if ($matched !== null) {
            return ['escalate' => true, 'reason' => 'trigger:' . $matched, 'provider' => $provider, 'model' => $model];
        }

        return ['escalate' => false, 'reason' => 'none', 'provider' => null, 'model' => null];
    }

    /**
     * Return the first regex (from the admin-configured trigger list or
     * the default set if blank) that matches the message, or null if none.
     *
     * @param string $message
     * @return string|null The raw regex line (pre-delimiter) that matched, or null.
     */
    public static function first_matching_trigger(string $message): ?string {
        if ($message === '') {
            return null;
        }
        $raw = (string) get_config('local_ai_course_assistant', 'premium_escalation_triggers');
        if (trim($raw) === '') {
            $raw = self::DEFAULT_TRIGGERS;
        }
        foreach (preg_split("/\r?\n/", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            // Wrap with `~` delimiters + case-insensitive flag. Suppress
            // warnings from malformed admin regex; treat as non-match.
            $ok = @preg_match('~' . $line . '~i', $message);
            if ($ok === 1) {
                return $line;
            }
        }
        return null;
    }

    /**
     * Whether this course is in the admin-configured premium allowlist.
     * Lines in the allowlist match against the course `shortname` OR
     * `idnumber` (case-insensitive, prefix match — so "MATH" matches
     * MATH121, MATH205, etc.).
     *
     * @param int $courseid
     * @return bool
     */
    public static function course_matches_allowlist(int $courseid): bool {
        $raw = trim((string) get_config('local_ai_course_assistant', 'premium_escalation_course_tags'));
        if ($raw === '') {
            return false;
        }
        $tags = [];
        foreach (preg_split("/\r?\n/", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $tags[] = strtolower($line);
        }
        if (empty($tags)) {
            return false;
        }
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid], 'shortname,idnumber', IGNORE_MISSING);
        if (!$course) {
            return false;
        }
        $candidates = array_filter([
            strtolower((string) ($course->shortname ?? '')),
            strtolower((string) ($course->idnumber ?? '')),
        ]);
        foreach ($tags as $tag) {
            foreach ($candidates as $c) {
                if ($c === $tag || str_starts_with($c, $tag)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Log a premium-escalation routing decision to the messages table so
     * Learning Radar / Redash can monitor the escalation rate per course.
     * Best-effort; failures do not block the chat path.
     *
     * @param int $conversationid
     * @param int $userid
     * @param int $courseid
     * @param array $decision Output of self::decide().
     */
    public static function log_decision(int $conversationid, int $userid, int $courseid, array $decision): void {
        if (empty($decision['escalate'])) {
            return;
        }
        global $DB;
        try {
            $record = new \stdClass();
            $record->conversationid = $conversationid;
            $record->userid = $userid;
            $record->courseid = $courseid;
            $record->role = 'system';
            $record->message = '[PremiumRouter] ' . $decision['reason'];
            $record->tokens_used = 0;
            $record->prompt_tokens = 0;
            $record->completion_tokens = 0;
            $record->model_name = (string) ($decision['model'] ?? '');
            $record->provider = 'premium_router';
            $record->interaction_type = 'premium_route';
            $record->timecreated = time();
            $DB->insert_record('local_ai_course_assistant_msgs', $record);
        } catch (\Throwable $e) {
            // Non-critical; don't break chat path on telemetry failure.
        }
    }
}
