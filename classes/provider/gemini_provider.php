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
    public const THINKING_BUDGET_MAX = 2048;

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
    public const THINKING_BUDGET_MIN = 512;

    /**
     * Largest max_tokens Gemini 2.5 and 3 models accept (their output limit).
     *
     * The admin max_tokens setting is unbounded, so requested + budget is
     * capped here: a value that worked before this change must not start
     * failing with a 400 because the budget pushed it over.
     */
    public const OUTPUT_TOKEN_LIMIT = 65536;

    protected function get_default_model(): string {
        return 'gemini-2.5-flash';
    }

    protected function get_default_base_url(): string {
        return 'https://generativelanguage.googleapis.com/v1beta/openai';
    }

    /**
     * Gemini's compatibility endpoint reports thinking only implicitly: its
     * completion_tokens leaves it out and total_tokens includes it. Google
     * bills it at the output rate either way.
     *
     * @return bool
     */
    protected function completion_excludes_reasoning(): bool {
        return true;
    }

    /**
     * Does this model think by default, so a thinking budget applies?
     *
     * gemini-2.5-* and gemini-3* chat models do, as do the -latest aliases
     * that point at them. 2.0 and 1.5 do not think and
     * reject the field. Flash-Lite models are left alone: thinking is off by
     * default on them, and sending a budget would switch it ON, changing both
     * their answers and their cost. Non-chat variants (TTS, image, audio, live)
     * never come through this class, but are excluded by name in case one is
     * configured by mistake.
     *
     * @param string $model Model id, with or without the "models/" prefix.
     * @return bool
     */
    public static function model_thinks(string $model): bool {
        $model = strtolower(trim($model));
        if (str_starts_with($model, 'models/')) {
            $model = substr($model, strlen('models/'));
        }
        // gemini-flash-latest and gemini-pro-latest are Google's moving
        // aliases, and both resolve to thinking models.
        if (!preg_match('/^gemini-(2\.5|3|flash-latest|pro-latest)/', $model)) {
            return false;
        }
        return !preg_match('/(lite|tts|image|audio|live|transcribe|embedding)/', $model);
    }

    /**
     * Thinking budget for a call that asked for $requested answer tokens, or
     * null when the model gets no budget.
     *
     * Twice the requested answer, clamped to
     * [THINKING_BUDGET_MIN, THINKING_BUDGET_MAX]: the default 1,024-token chat
     * answer gets the full 2,048.
     *
     * @param string $model
     * @param int $requested max_tokens the caller asked for.
     * @return int|null
     */
    public static function thinking_budget(string $model, int $requested): ?int {
        if ($requested <= 0 || !self::model_thinks($model)) {
            return null;
        }
        return max(self::THINKING_BUDGET_MIN, min(self::THINKING_BUDGET_MAX, 2 * $requested));
    }

    /**
     * Give thinking its own budget on top of the caller's max_tokens.
     *
     * The field is Google's documented compatibility extension, sent as a
     * literal top-level `extra_body` key (verified live: the same object sent
     * as a top-level `google` key is rejected with "Unknown name google").
     * Google rejects a request carrying both reasoning_effort and
     * thinking_config, so nothing is added when a caller already set either.
     * A call without max_tokens is left alone: the model's own output limit
     * (65,536) is far above anything thinking uses, so it cannot be starved.
     *
     * @param array $body
     * @param array $options
     * @return array
     */
    protected function adjust_body(array $body, array $options): array {
        if (!isset($body['max_tokens']) || isset($body['reasoning_effort']) || isset($body['extra_body'])) {
            return $body;
        }
        $requested = (int) $body['max_tokens'];
        $budget = self::thinking_budget($this->model, $requested);
        if ($budget === null) {
            return $body;
        }
        $body['max_tokens'] = min($requested + $budget, self::OUTPUT_TOKEN_LIMIT);
        $body['extra_body'] = ['google' => ['thinking_config' => ['thinking_budget' => $budget]]];
        return $body;
    }
}
