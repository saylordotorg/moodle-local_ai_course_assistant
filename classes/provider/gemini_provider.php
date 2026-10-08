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

namespace local_ai_course_assistant\provider;

use local_ai_course_assistant\model_capabilities;

/**
 * Google Gemini provider via the OpenAI-compatible Gemini endpoint.
 *
 * v7.7.6: thinking gets its own budget. Gemini 2.5 and 3 models think before
 * they answer, and on this endpoint the thinking is paid out of the same
 * max_tokens as the answer. With SOLA's default max_tokens of 1024, a live
 * reproduction on a ~3,700-token tutor prompt ended 'length' on 5 of 9 runs;
 * the worst spent 979 tokens thinking and 41 answering, so the learner got a
 * sentence fragment. A call that sets max_tokens therefore now sends a
 * thinking budget and adds it on top, so the requested amount stays available
 * for the answer. Thinking stays ON: the chat model was benchmarked with it.
 *
 * v7.8.0: the budget is built by openai_compatible_provider::apply_reasoning()
 * from the capability profile, which is also how OpenAI reasoning models now get
 * the same headroom. This class keeps its constants and helpers as the public
 * statement of the Gemini numbers, and reports thinking as billed outside
 * completion_tokens through the profile's Gemini family default.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gemini_provider extends openai_compatible_provider {
    /**
     * Ceiling on the thinking budget, in tokens.
     *
     * Measured, not guessed: on the reproduction prompt with no token limit at
     * all, gemini-2.5-flash thought 145 to 1,647 tokens (median about 750)
     * across three questions, the longest on a compare-and-judge question.
     * 2,048 covers the largest observed with room to spare, so a chat answer
     * is not starved of the thinking it was benchmarked with. Thinking is
     * billed only as used, so the ceiling costs nothing on calls that think
     * less; what it buys is that thinking can no longer eat the answer.
     */
    public const THINKING_BUDGET_MAX = model_capabilities::HEADROOM_MAX;

    /**
     * Floor on the thinking budget, in tokens.
     *
     * Small calls (the mastery classifier asks for 200 tokens, profile
     * generation 512) get a budget scaled to twice the answer they asked for,
     * but never below this. Before v7.7.6 such a call could think at most its
     * whole max_tokens and then return nothing, so this is still more room
     * than they had, without letting a 200-token label think for 2,048.
     * 512 also clears gemini-2.5-pro's minimum budget of 128.
     */
    public const THINKING_BUDGET_MIN = model_capabilities::HEADROOM_MIN;

    /**
     * Largest max_tokens Gemini 2.5 and 3 models accept (their output limit).
     *
     * The admin max_tokens setting is unbounded, so requested + budget is
     * capped here: a value that worked before this change must not start
     * failing with a 400 because the budget pushed it over.
     */
    public const OUTPUT_TOKEN_LIMIT = model_capabilities::GEMINI_OUTPUT_LIMIT;

    protected function get_default_model(): string {
        return 'gemini-2.5-flash';
    }

    protected function get_default_base_url(): string {
        return 'https://generativelanguage.googleapis.com/v1beta/openai';
    }

    /**
     * Does this model think by default, so a thinking budget applies?
     *
     * v7.8.0: answered by the capability profile rather than a regex in this
     * class. The rules are unchanged: gemini-2.5-* and gemini-3* chat models
     * and the flash/pro -latest aliases think; 2.0 and 1.5 do not and reject
     * the field; Flash-Lite, TTS, image, audio, live, transcribe and embedding
     * variants are left alone (a budget would switch thinking ON on
     * Flash-Lite). A learned fact can now correct a model the rules get wrong.
     *
     * @param string $model Model id, with or without the "models/" prefix.
     * @return bool
     */
    public static function model_thinks(string $model): bool {
        $profile = model_capabilities::profile('gemini', $model);
        return $profile['reasoning'] === model_capabilities::REASONING_GEMINI && !empty($profile['thinks']);
    }

    /**
     * Thinking budget for a call that asked for $requested answer tokens, or
     * null when the model gets no budget.
     *
     * Twice the requested answer, clamped to
     * [THINKING_BUDGET_MIN, THINKING_BUDGET_MAX]: the default 1,024-token chat
     * answer gets the full 2,048. The same rule now gives OpenAI reasoning
     * models their headroom; see model_capabilities::headroom().
     *
     * @param string $model
     * @param int $requested max_tokens the caller asked for.
     * @return int|null
     */
    public static function thinking_budget(string $model, int $requested): ?int {
        if ($requested <= 0 || !self::model_thinks($model)) {
            return null;
        }
        return model_capabilities::headroom($requested);
    }
}
