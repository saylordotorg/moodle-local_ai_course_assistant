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

use local_ai_course_assistant\provider\claude_provider;
use local_ai_course_assistant\provider\gemini_provider;
use local_ai_course_assistant\provider\openai_provider;
use local_ai_course_assistant\provider\provider_http_exception;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_http.php');

/**
 * v7.8.0 self-healing requests, end to end through the real provider classes.
 *
 * The HTTP layer is replaced by a scripted fake (each test subclasses a real
 * provider and overrides only http_post / http_post_stream), so the body
 * building, the healer, the learned-fact store and the retry are all the
 * production code. What is pinned:
 *
 *  - a 400 naming a parameter is fixed, retried ONCE, and remembered, so the
 *    NEXT call is built right the first time with no 400 at all;
 *  - the retry happens on streaming calls only while nothing has reached the
 *    learner;
 *  - an unrecognised 400 is not retried, and a call that fails again after its
 *    one fix surfaces the second error instead of looping;
 *  - the same holds for Claude, Gemini and the OpenAI-compatible family.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\base_provider::heal_request
 * @covers     \local_ai_course_assistant\provider\openai_compatible_provider
 * @covers     \local_ai_course_assistant\provider\claude_provider
 */
final class provider_self_heal_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        model_capabilities::reset_cache();
    }

    /**
     * An OpenAI-shaped success body.
     *
     * @param string $text
     * @return string
     */
    private static function ok(string $text = 'Hello'): string {
        return json_encode(['model' => 'm', 'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]]);
    }

    /**
     * A provider whose HTTP layer replays a script.
     *
     * Each script entry is either a string (a 200 body for http_post, or the
     * raw SSE bytes for http_post_stream) or [status, body] for an error.
     *
     * @param string $class Provider class to extend.
     * @param string $providerid What provider_id() reports.
     * @param string $model
     * @param array $script
     * @return object
     */
    private function fake(string $class, string $providerid, string $model, array $script): object {
        $overrides = ['apikey' => 'x', 'model' => $model, 'temperature' => '0.4'];
        $fake = match ($class) {
            openai_provider::class => new class ($overrides) extends openai_provider {
                use fake_http;
            },
            gemini_provider::class => new class ($overrides) extends gemini_provider {
                use fake_http;
            },
            claude_provider::class => new class ($overrides) extends claude_provider {
                use fake_http;
            },
            provider\together_provider::class => new class ($overrides) extends provider\together_provider {
                use fake_http;
            },
        };
        $fake->script = $script;
        $fake->fakeid = $providerid;
        return $fake;
    }

    /**
     * The 2026-10-07 gpt-6 failure heals in one call and stays healed.
     */
    public function test_openai_token_param_heals_and_is_remembered(): void {
        global $DB;
        // A model the rules do not describe, so the first request uses max_tokens.
        $error = json_encode(['error' => ['message' => "Unsupported parameter: 'max_tokens' is not supported with this "
            . "model. Use 'max_completion_tokens' instead.", 'param' => 'max_tokens']]);
        $p = $this->fake(openai_provider::class, 'openai', 'gpt-99-nova', [[400, $error], self::ok('Fine')]);

        $sink = $this->redirectEvents();
        $this->assertSame('Fine', $p->chat_completion('sys', [['role' => 'user', 'content' => 'q']], ['max_tokens' => 100]));
        $this->assertCount(2, $p->sent, 'Exactly one retry.');
        $this->assertArrayHasKey('max_tokens', $p->sent[0]);
        $this->assertArrayHasKey('max_completion_tokens', $p->sent[1]);
        $this->assertArrayNotHasKey('max_tokens', $p->sent[1]);
        $this->assertSame('token_param', $p->get_last_heal()['field']);

        $row = $DB->get_record(model_capabilities::TABLE, ['provider' => 'openai', 'modelkey' => 'gpt-99-nova']);
        $this->assertSame('max_completion_tokens', $row->value);
        $events = array_filter($sink->get_events(), function ($e) {
            return $e instanceof event\model_capability_learned;
        });
        $this->assertCount(1, $events);

        // A fresh instance, as the next learner request would build: right first time.
        $next = $this->fake(openai_provider::class, 'openai', 'gpt-99-nova', [self::ok('Again')]);
        $this->assertSame('Again', $next->chat_completion('sys', [['role' => 'user', 'content' => 'q']], ['max_tokens' => 100]));
        $this->assertCount(1, $next->sent);
        $this->assertArrayHasKey('max_completion_tokens', $next->sent[0]);
    }

    /**
     * Streaming heals before the first chunk.
     */
    public function test_streaming_heals_before_any_output(): void {
        $error = json_encode(['error' => ['message' => "Unsupported value: 'temperature' does not support 0.4 with this "
            . "model. Only the default (1) value is supported.", 'param' => 'temperature']]);
        $sse = "data: " . json_encode(['choices' => [['delta' => ['content' => 'Hi']]]]) . "\n"
            . "data: " . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]]) . "\n"
            . "data: [DONE]\n";
        $p = $this->fake(openai_provider::class, 'openai', 'some-new-model', [[400, $error], $sse]);
        $out = '';
        $p->chat_completion_stream('sys', [['role' => 'user', 'content' => 'q']], function ($c) use (&$out) {
            $out .= $c;
        }, []);
        $this->assertSame('Hi', $out, 'The learner sees the answer once.');
        $this->assertCount(2, $p->sent);
        $this->assertArrayHasKey('temperature', $p->sent[0]);
        $this->assertArrayNotHasKey('temperature', $p->sent[1]);
        $this->assertSame('stop', $p->get_last_finish_reason());
    }

    /**
     * Once a chunk has reached the learner, a later error is never retried.
     */
    public function test_streaming_never_retries_after_output(): void {
        $error = json_encode(['error' => ['message' => "Unsupported value: 'temperature' does not support 0.4."]]);
        $p = $this->fake(openai_provider::class, 'openai', 'some-new-model', [['partial', 400, $error], 'unused']);
        $out = '';
        try {
            $p->chat_completion_stream('sys', [['role' => 'user', 'content' => 'q']], function ($c) use (&$out) {
                $out .= $c;
            }, []);
            $this->fail('The error should have surfaced.');
        } catch (provider_http_exception $e) {
            $this->assertSame(400, $e->status);
        }
        $this->assertSame('Par', $out);
        $this->assertCount(1, $p->sent, 'No retry after output was forwarded.');
    }

    /**
     * An unrecognised 400 is surfaced at once, with no retry and nothing learned.
     */
    public function test_unrecognised_400_is_not_retried(): void {
        global $DB;
        $error = json_encode(['error' => ['message' => "Invalid 'messages[0].content': string too long."]]);
        $p = $this->fake(openai_provider::class, 'openai', 'gpt-4o-mini', [[400, $error], self::ok()]);
        try {
            $p->chat_completion('sys', [['role' => 'user', 'content' => 'q']], []);
            $this->fail('The error should have surfaced.');
        } catch (provider_http_exception $e) {
            $this->assertStringContainsString('string too long', $e->debuginfo);
        }
        $this->assertCount(1, $p->sent);
        $this->assertSame(0, $DB->count_records(model_capabilities::TABLE));
    }

    /**
     * A call that fails again after its one fix does not loop.
     */
    public function test_second_failure_is_not_retried_again(): void {
        $tokens = json_encode(['error' => ['message' => "Unsupported parameter: 'max_tokens' is not supported with "
            . "this model. Use 'max_completion_tokens' instead.", 'param' => 'max_tokens']]);
        $temp = json_encode(['error' => ['message' => "Unsupported value: 'temperature' does not support 0.4 with "
            . "this model.", 'param' => 'temperature']]);
        $p = $this->fake(openai_provider::class, 'openai', 'gpt-99-nova', [[400, $tokens], [400, $temp], self::ok()]);
        try {
            $p->chat_completion('sys', [['role' => 'user', 'content' => 'q']], ['max_tokens' => 10]);
            $this->fail('The second error should have surfaced.');
        } catch (provider_http_exception $e) {
            $this->assertStringContainsString('temperature', $e->debuginfo);
        }
        $this->assertCount(2, $p->sent, 'One retry, never a second.');
        global $DB;
        $this->assertSame(
            0,
            $DB->count_records(model_capabilities::TABLE),
            'A fix whose retry failed is not remembered: the 400 may not have been about the model.'
        );
    }

    /**
     * Claude: a model that stopped accepting temperature heals the same way.
     */
    public function test_claude_temperature_heals(): void {
        set_config('claude_temperature_allow_prefixes', 'claude-test-', 'local_ai_course_assistant');
        $error = json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error',
            'message' => 'temperature is deprecated for this model.']]);
        $ok = json_encode(['model' => 'claude-test-1', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Done']], 'usage' => ['input_tokens' => 3, 'output_tokens' => 1]]);
        $p = $this->fake(claude_provider::class, 'claude', 'claude-test-1', [[400, $error], $ok]);
        $this->assertSame('Done', $p->chat_completion('sys', [['role' => 'user', 'content' => 'q']], []));
        $this->assertArrayHasKey('temperature', $p->sent[0]);
        $this->assertArrayNotHasKey('temperature', $p->sent[1]);
        $this->assertSame(
            model_capabilities::TEMP_OMIT,
            model_capabilities::profile('claude', 'claude-test-1')['temperature']
        );
    }

    /**
     * Claude streaming heals before output, like the OpenAI path.
     */
    public function test_claude_streaming_heals(): void {
        $error = json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error',
            'message' => 'max_tokens: 100000 > 64000, which is the maximum allowed number of output tokens.']]);
        $sse = "data: " . json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Ok']])
            . "\ndata: " . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]) . "\n";
        $p = $this->fake(claude_provider::class, 'claude', 'claude-test-2', [[400, $error], $sse]);
        $out = '';
        $p->chat_completion_stream('sys', [['role' => 'user', 'content' => 'q']], function ($c) use (&$out) {
            $out .= $c;
        }, ['max_tokens' => 100000]);
        $this->assertSame('Ok', $out);
        $this->assertSame(64000, $p->sent[1]['max_tokens']);
    }

    /**
     * Gemini: a model that cannot turn thinking off learns that and keeps thinking.
     */
    public function test_gemini_thinking_off_heals(): void {
        set_config('reasoning_effort', 'off', 'local_ai_course_assistant');
        $error = json_encode([['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT',
            'message' => 'Budget 0 is invalid. This model only works in thinking mode.']]]);
        $p = $this->fake(gemini_provider::class, 'gemini', 'gemini-3.9-flash', [[400, $error], self::ok('Thought')]);
        $this->assertSame('Thought', $p->chat_completion('sys', [['role' => 'user', 'content' => 'q']], ['max_tokens' => 1024]));
        $this->assertSame(0, $p->sent[0]['extra_body']['google']['thinking_config']['thinking_budget']);
        $this->assertSame(2048, $p->sent[1]['extra_body']['google']['thinking_config']['thinking_budget']);
    }

    /**
     * The OpenAI-compatible family heals too (Together, OpenRouter, custom).
     */
    public function test_openai_compatible_family_heals(): void {
        $error = json_encode(['error' => ['message' => 'reasoning_effort is not supported for this model']]);
        $p = $this->fake(provider\together_provider::class, 'together', 'openai/gpt-oss-120b', [[400, $error], self::ok()]);
        $p->chat_completion('sys', [['role' => 'user', 'content' => 'q']], ['max_tokens' => 1024]);
        $this->assertSame('low', $p->sent[0]['reasoning_effort']);
        $this->assertArrayNotHasKey('reasoning_effort', $p->sent[1]);
        $this->assertSame(
            model_capabilities::REASONING_NONE,
            model_capabilities::profile('together', 'openai/gpt-oss-120b')['reasoning']
        );
    }

    /**
     * The HTTP error still reads as it always did, and never carries a key.
     */
    public function test_http_exception_is_the_old_exception_with_a_redacted_body(): void {
        $p = new openai_provider(['apikey' => 'x', 'model' => 'gpt-4o-mini']);
        $m = new \ReflectionMethod($p, 'check_http_error');
        $m->setAccessible(true);
        try {
            $m->invoke($p, 400, '{"error":{"message":"bad key sk-abcdefghijklmnopqrstuvwxyz123"}}');
            $this->fail('Expected an exception.');
        } catch (provider_http_exception $e) {
            $this->assertInstanceOf(\moodle_exception::class, $e);
            $this->assertSame('chat:error', $e->errorcode);
            $this->assertSame(400, $e->status);
            $this->assertStringStartsWith('HTTP 400: ', $e->debuginfo);
            $this->assertStringNotContainsString('sk-abcdefghijklmnop', $e->debuginfo);
        }
    }
}
