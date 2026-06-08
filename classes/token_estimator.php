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
 * Language-aware token estimation for prompt budgeting (v5.10.0).
 *
 * SOLA budgets the system prompt in characters. On a small self-hosted
 * backend with a hard max_model_len, that character budget must be clamped
 * so the prompt (plus reserved output and history) fits the token window.
 * This class converts between characters and an estimated token count using
 * conservative (low) chars-per-token divisors, so estimates round high and
 * the assembled prompt never overflows the window, even in token-dense
 * languages such as Hungarian.
 *
 * The divisors are deliberately approximate: a real per-model tokenizer is
 * out of scope (self-hosted Llama-family models use a different tokenizer
 * from the hosted providers). Operators who want extra margin set
 * backend_context_tokens below their true window.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class token_estimator {

    /** Conservative fallback divisor for unlisted languages. */
    private const FALLBACK_CPT = 3.0;

    /** Reserve for the pending user message, in tokens. */
    private const USER_HEADROOM_TOKENS = 512;

    /** Per-language chars-per-token, keyed by Moodle lang code (or prefix). */
    private const CPT = [
        'en' => 4.0,
        'es' => 3.8, 'fr' => 3.8, 'de' => 3.8, 'it' => 3.8, 'pt' => 3.8,
        'nl' => 3.8, 'sv' => 3.8, 'da' => 3.8, 'nb' => 3.8, 'ro' => 3.8,
        'hu' => 2.8, 'fi' => 2.8, 'tr' => 2.8, 'pl' => 2.8, 'cs' => 2.8, 'sk' => 2.8,
        'ar' => 3.0, 'he' => 3.0, 'ru' => 3.0, 'uk' => 3.0, 'bg' => 3.0,
        'zh_cn' => 1.5, 'ja' => 1.5, 'ko' => 1.5, 'th' => 1.8,
    ];

    /**
     * Conservative chars-per-token divisor for a language.
     *
     * @param string $lang Moodle language code (e.g. 'en', 'pt_br', 'zh_cn')
     */
    public static function chars_per_token(string $lang): float {
        $key = strtolower($lang);
        if (isset(self::CPT[$key])) {
            return self::CPT[$key];
        }
        // Try the bare prefix (e.g. 'pt_br' -> 'pt').
        $prefix = preg_replace('/[_-].*$/', '', $key);
        return self::CPT[$prefix] ?? self::FALLBACK_CPT;
    }

    /**
     * Estimate the token count of a string in the given language.
     *
     * @param string $text the text to measure
     * @param string $lang learner language code
     */
    public static function estimate_tokens(string $text, string $lang): int {
        if ($text === '') {
            return 0;
        }
        return (int) ceil(strlen($text) / self::chars_per_token($lang));
    }

    /**
     * Character budget available for the system prompt given a backend token
     * window, after reserving output, history, and pending-user-message space.
     *
     * Returns 0 (not a negative value) when nothing is left for the prompt;
     * callers floor it to a safe minimum.
     *
     * @param int $windowtokens backend max_model_len
     * @param int $outputtokens reserved output tokens (max_tokens; pass a floor if unlimited)
     * @param int $historytokens estimated conversation-history tokens this turn
     * @param string $lang learner language code
     */
    public static function budget_chars_for_window(
        int $windowtokens, int $outputtokens, int $historytokens, string $lang
    ): int {
        $sysprompttokens = $windowtokens - $outputtokens - $historytokens - self::USER_HEADROOM_TOKENS;
        if ($sysprompttokens <= 0) {
            return 0;
        }
        return (int) floor($sysprompttokens * self::chars_per_token($lang));
    }
}
