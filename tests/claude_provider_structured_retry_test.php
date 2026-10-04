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
 * Issue #298: the structured-output retry on models without forced tool choice.
 *
 * Opus 5.5, Sonnet 5.5, Fable 5.1 and Mythos 5.1 reject a forced tool_choice,
 * so structured output on them goes out with 'auto', which does not guarantee
 * a call. A prose answer used to fall through to the text branch, the caller's
 * json_decode failed, and the quiz or score was lost. The provider now retries
 * once.
 *
 * The retry itself is two lines. What these tests pin is the two ways it goes
 * wrong if written naively:
 *
 *  - Billing. Callers bill from get_last_token_usage(). A retry that overwrote
 *    usage would drop the first request's tokens from the bill on every retried
 *    call, and nothing would look wrong.
 *  - Making things worse. The retry is best-effort: if it is refused, comes back
 *    empty or throws, the caller gets exactly what the first attempt would have
 *    returned without it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\claude_provider
 */
final class claude_provider_structured_retry_test extends \advanced_testcase {

    /** @var array A minimal structured-output request. */
    private const OPTIONS = ['response_schema' => ['name' => 'grade', 'schema' => ['type' => 'object']]];

    /**
     * A provider whose HTTP layer replays canned responses in order.
     *
     * Each queue entry is a decoded response array, or a \Throwable to throw.
     *
     * @param string $model
     * @param array $queue
     * @return claude_provider
     */
    private function provider(string $model, array $queue): claude_provider {
        $this->resetAfterTest();
        $p = new class(['apikey' => 'test-key', 'model' => $model]) extends claude_provider {
            /** @var array */
            public $queue = [];
            /** @var int */
            public $calls = 0;
            protected function http_post(string $url, array $headers, string $body): string {
                $this->calls++;
                $next = array_shift($this->queue);
                if ($next instanceof \Throwable) {
                    throw $next;
                }
                return json_encode($next);
            }
        };
        $p->queue = $queue;
        return $p;
    }

    /**
     * A prose-only response, i.e. the model answered instead of calling the tool.
     *
     * @param int $in
     * @param int $out
     * @return array
     */
    private static function prose(int $in, int $out): array {
        return [
            'model' => 'claude-sonnet-5-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Here is my grade: a solid B.']],
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
        ];
    }

    /**
     * A response that called the tool.
     *
     * @param int $in
     * @param int $out
     * @return array
     */
    private static function tool(int $in, int $out): array {
        return [
            'model' => 'claude-sonnet-5-5',
            'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'name' => 'grade', 'input' => ['score' => 4]]],
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
        ];
    }

    /**
     * A miss is retried, and the retry's tool call is what comes back.
     */
    public function test_a_prose_answer_is_retried_and_the_tool_call_returned(): void {
        $p = $this->provider('claude-sonnet-5-5', [self::prose(100, 20), self::tool(100, 30)]);

        $out = $p->chat_completion('sys', [['role' => 'user', 'content' => 'grade this']], self::OPTIONS);

        $this->assertSame(2, $p->calls);
        $this->assertSame(['score' => 4], json_decode($out, true));
    }

    /**
     * The bill covers both requests, not just the one that succeeded.
     *
     * This is the reason the retry was not a two-line change.
     */
    public function test_usage_is_the_sum_of_both_attempts(): void {
        $p = $this->provider('claude-sonnet-5-5', [self::prose(100, 20), self::tool(110, 30)]);

        $p->chat_completion('sys', [['role' => 'user', 'content' => 'grade this']], self::OPTIONS);
        $usage = $p->get_last_token_usage();

        $this->assertSame(210, $usage['prompt_tokens'], 'the first request\'s input tokens were dropped');
        $this->assertSame(50, $usage['completion_tokens'], 'the first request\'s output tokens were dropped');
    }

    /**
     * A first-time tool call is not retried.
     */
    public function test_a_tool_call_on_the_first_attempt_is_not_retried(): void {
        $p = $this->provider('claude-sonnet-5-5', [self::tool(100, 30)]);

        $out = $p->chat_completion('sys', [['role' => 'user', 'content' => 'grade this']], self::OPTIONS);

        $this->assertSame(1, $p->calls);
        $this->assertSame(['score' => 4], json_decode($out, true));
        $this->assertSame(100, $p->get_last_token_usage()['prompt_tokens']);
    }

    /**
     * It retries once, not until it succeeds.
     *
     * Two misses return the first answer's text, as the code did before the
     * retry existed, and both requests are billed.
     */
    public function test_two_misses_stop_after_one_retry(): void {
        $p = $this->provider('claude-sonnet-5-5', [self::prose(100, 20), self::prose(100, 25), self::tool(1, 1)]);

        $out = $p->chat_completion('sys', [['role' => 'user', 'content' => 'grade this']], self::OPTIONS);

        $this->assertSame(2, $p->calls);
        $this->assertSame('Here is my grade: a solid B.', $out);
        $this->assertSame(45, $p->get_last_token_usage()['completion_tokens']);
    }

    /**
     * A failed retry never makes the call worse than no retry at all.
     */
    public function test_a_retry_that_throws_falls_back_to_the_first_answer(): void {
        $p = $this->provider('claude-sonnet-5-5',
            [self::prose(100, 20), new \moodle_exception('chat:error', 'local_ai_course_assistant')]);

        $out = $p->chat_completion('sys', [['role' => 'user', 'content' => 'grade this']], self::OPTIONS);

        $this->assertSame('Here is my grade: a solid B.', $out);
        $this->assertSame(100, $p->get_last_token_usage()['prompt_tokens']);
        $this->assertDebuggingCalled();
    }

    /**
     * A refused retry falls back too, rather than turning a usable first
     * answer into a refusal message.
     */
    public function test_a_refused_retry_falls_back_to_the_first_answer(): void {
        $refusal = ['stop_reason' => 'refusal', 'content' => [], 'usage' => ['input_tokens' => 100, 'output_tokens' => 0]];
        $p = $this->provider('claude-sonnet-5-5', [self::prose(100, 20), $refusal]);

        $out = $p->chat_completion('sys', [['role' => 'user', 'content' => 'grade this']], self::OPTIONS);

        $this->assertSame('Here is my grade: a solid B.', $out);
    }

    /**
     * Models that accept a forced tool choice are never retried.
     *
     * The forced choice already guarantees a call, so a prose answer there is
     * a different problem and retrying would only double the cost.
     */
    public function test_models_with_forced_tool_choice_are_not_retried(): void {
        $p = $this->provider('claude-opus-5', [self::prose(100, 20), self::tool(100, 30)]);

        $p->chat_completion('sys', [['role' => 'user', 'content' => 'grade this']], self::OPTIONS);

        $this->assertSame(1, $p->calls);
    }

    /**
     * Ordinary chat is never retried, however it answers.
     */
    public function test_plain_chat_is_not_retried(): void {
        $p = $this->provider('claude-sonnet-5-5', [self::prose(100, 20), self::prose(100, 20)]);

        $p->chat_completion('sys', [['role' => 'user', 'content' => 'hello']], []);

        $this->assertSame(1, $p->calls);
    }
}
