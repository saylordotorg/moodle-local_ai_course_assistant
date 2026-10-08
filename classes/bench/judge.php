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

namespace local_ai_course_assistant\bench;

use local_ai_course_assistant\task\run_model_benchmark;

/**
 * The rubric judge, shared by the CLI harness and the adhoc tasks (v7.8.0).
 *
 * Two problems from the 2026-10-07 benchmark are fixed here.
 *
 * A CUT-OFF ANSWER CONFUSED THE JUDGE. Given a tutor response that stopped
 * mid-sentence, the judge continued the student's answer instead of grading it,
 * returned prose, and the row went unjudged (1 or 2 per arm for four models).
 * A truncated answer is now labelled as one, inside explicit delimiters, with
 * an instruction to grade what is there and not continue it.
 *
 * PARSING WAS ALL OR NOTHING. A reply that was valid JSON wrapped in a sentence,
 * or a fence with trailing text, was discarded. The parser now finds the JSON
 * object carrying the three scores wherever it sits, validates each score as an
 * integer 1 to 5, and when the reply holds no such object asks once more for
 * the JSON alone.
 *
 * The judge's system prompt is unchanged (run_model_benchmark::JUDGE_PROMPT),
 * and a complete answer is sent exactly as before, so scores of complete
 * answers stay comparable with earlier runs.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class judge {
    /** @var string[] The three rubric dimensions. */
    public const DIMENSIONS = ['socratic', 'accuracy', 'tone'];

    /** @var string Follow-up sent once when the first reply carried no scores. */
    public const RETRY_PROMPT = 'Your reply did not contain the scores. Reply with ONLY the JSON object '
        . '{"socratic": N, "accuracy": N, "tone": N, "notes": "one sentence"}, with each N an integer 1 to 5, '
        . 'and nothing else.';

    /**
     * The user message for one judgement.
     *
     * A complete answer gets the historical message byte for byte. A truncated
     * one is fenced and labelled so the judge grades it rather than finishing it.
     *
     * @param string $prompt The student prompt.
     * @param string $response The tutor response.
     * @param bool $truncated Whether the response hit its output limit.
     * @return string
     */
    public static function user_message(string $prompt, string $response, bool $truncated = false): string {
        if (!$truncated) {
            return "STUDENT PROMPT:\n" . $prompt . "\n\nTUTOR RESPONSE:\n" . $response;
        }
        return "STUDENT PROMPT:\n" . $prompt . "\n\n"
            . "TUTOR RESPONSE (it was CUT OFF at the length limit; grade what is there, do not continue it, "
            . "and treat the missing ending as incomplete):\n"
            . "<<<RESPONSE\n" . $response . "\nRESPONSE>>>\n\n"
            . 'Reply with the JSON object only.';
    }

    /**
     * Pull the three scores out of a judge reply, or null.
     *
     * Accepts the object bare, in a code fence, or surrounded by prose. Each
     * score must be a number from 1 to 5 (a numeric string is accepted);
     * anything else is no score rather than a guessed one.
     *
     * @param string $reply
     * @return array{socratic: int|float, accuracy: int|float, tone: int|float, notes: string}|null
     */
    public static function parse(string $reply): ?array {
        $reply = trim($reply);
        if ($reply === '') {
            return null;
        }
        foreach (self::json_objects($reply) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (!is_array($decoded)) {
                continue;
            }
            $scores = [];
            foreach (self::DIMENSIONS as $dim) {
                $value = $decoded[$dim] ?? null;
                if (is_string($value) && is_numeric(trim($value))) {
                    $value = trim($value) + 0;
                }
                if ((!is_int($value) && !is_float($value)) || $value < 1 || $value > 5) {
                    continue 2;
                }
                $scores[$dim] = (is_float($value) && floor($value) == $value) ? (int) $value : $value;
            }
            $scores['notes'] = is_string($decoded['notes'] ?? null) ? (string) $decoded['notes'] : '';
            return $scores;
        }
        return null;
    }

    /**
     * Judge one response, with one follow-up when the reply carried no scores.
     *
     * @param \local_ai_course_assistant\provider\provider_interface $judge
     * @param string $prompt
     * @param string $response
     * @param bool $truncated
     * @param callable|null $onusage Called with the usage array after each judge call.
     * @return array{scores: ?array, error: string, retried: bool}
     */
    public static function score(
        $judge,
        string $prompt,
        string $response,
        bool $truncated = false,
        ?callable $onusage = null
    ): array {
        if (trim($response) === '') {
            return ['scores' => null, 'error' => 'empty response', 'retried' => false];
        }
        $messages = [['role' => 'user', 'content' => self::user_message($prompt, $response, $truncated)]];
        try {
            $reply = (string) $judge->chat_completion(run_model_benchmark::JUDGE_PROMPT, $messages, ['temperature' => 0.0]);
            if ($onusage !== null) {
                $onusage($judge->get_last_token_usage());
            }
        } catch (\Throwable $e) {
            return ['scores' => null, 'error' => \core_text::substr($e->getMessage(), 0, 200), 'retried' => false];
        }
        $scores = self::parse($reply);
        if ($scores !== null) {
            return ['scores' => $scores, 'error' => '', 'retried' => false];
        }

        $messages[] = ['role' => 'assistant', 'content' => $reply];
        $messages[] = ['role' => 'user', 'content' => self::RETRY_PROMPT];
        try {
            $again = (string) $judge->chat_completion(run_model_benchmark::JUDGE_PROMPT, $messages, ['temperature' => 0.0]);
            if ($onusage !== null) {
                $onusage($judge->get_last_token_usage());
            }
        } catch (\Throwable $e) {
            return ['scores' => null, 'error' => \core_text::substr($e->getMessage(), 0, 200), 'retried' => true];
        }
        $scores = self::parse($again);
        if ($scores !== null) {
            return ['scores' => $scores, 'error' => '', 'retried' => true];
        }
        return [
            'scores' => null,
            'error' => 'judge returned non-JSON: ' . \core_text::substr(trim($again), 0, 80),
            'retried' => true,
        ];
    }

    /**
     * Every balanced {...} substring, outermost first, in order of appearance.
     *
     * Quotes are tracked so a brace inside a string (a note that says "use {x}")
     * does not end the object early.
     *
     * @param string $text
     * @return string[]
     */
    private static function json_objects(string $text): array {
        $out = [];
        $len = strlen($text);
        for ($start = 0; $start < $len; $start++) {
            if ($text[$start] !== '{') {
                continue;
            }
            $depth = 0;
            $instring = false;
            $escaped = false;
            for ($i = $start; $i < $len; $i++) {
                $c = $text[$i];
                if ($instring) {
                    if ($escaped) {
                        $escaped = false;
                    } else if ($c === '\\') {
                        $escaped = true;
                    } else if ($c === '"') {
                        $instring = false;
                    }
                    continue;
                }
                if ($c === '"') {
                    $instring = true;
                } else if ($c === '{') {
                    $depth++;
                } else if ($c === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $out[] = substr($text, $start, $i - $start + 1);
                        $start = $i;
                        break;
                    }
                }
            }
        }
        return $out;
    }
}
