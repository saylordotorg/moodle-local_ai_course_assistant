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

use local_ai_course_assistant\provider\gemini_provider;
use local_ai_course_assistant\provider\openai_provider;

/**
 * v7.7.6: Gemini thinking gets its own budget instead of eating the answer's.
 *
 * Gemini 2.5 Flash, the production chat model, thinks before it answers, and
 * on Google's OpenAI-compatible endpoint the thinking is paid out of the same
 * max_tokens as the answer. With SOLA's default of 1,024, a live reproduction
 * ended 'length' on 5 of 9 runs, the worst after 979 tokens of thinking and 41
 * of answer. The fix sends Google's documented thinking budget and raises
 * max_tokens by the same amount, for thinking models only.
 *
 * What is pinned here is the request body, because that is the whole fix and
 * every part of it is a way to break a provider: the budget must be present
 * and max_tokens raised for 2.5 and 3 models; it must NOT be sent to 2.0 or
 * 1.5 (which reject it), to Flash-Lite (where it would switch thinking on), or
 * to any non-Gemini provider; and it must never ride alongside
 * reasoning_effort, which Google rejects with a 400.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\gemini_provider
 * @covers     \local_ai_course_assistant\provider\openai_compatible_provider::build_body
 */
final class gemini_thinking_budget_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * The decoded body a provider would send.
     *
     * @param object $provider
     * @param array $options
     * @param bool $stream
     * @return array
     */
    private function body(object $provider, array $options, bool $stream = true): array {
        $m = new \ReflectionMethod($provider, 'build_body');
        $m->setAccessible(true);
        $json = $m->invoke($provider, 'system', [['role' => 'user', 'content' => 'q']], $stream, $options);
        return json_decode($json, true);
    }

    /**
     * A Gemini provider on the given model.
     *
     * @param string $model
     * @return gemini_provider
     */
    private function gemini(string $model): gemini_provider {
        return new gemini_provider(['apikey' => 'x', 'model' => $model]);
    }

    /**
     * The production case: chat on gemini-2.5-flash with the default 1,024.
     *
     * The answer keeps all 1,024 and thinking gets 2,048 of its own, in the
     * field shape verified live against Google's endpoint (a literal top-level
     * extra_body key; the same object as a top-level "google" key is a 400).
     */
    public function test_chat_on_gemini_flash_gets_a_thinking_budget_on_top_of_the_answer(): void {
        foreach ([true, false] as $stream) {
            $body = $this->body($this->gemini('gemini-2.5-flash'), ['max_tokens' => 1024], $stream);

            $this->assertSame(
                ['google' => ['thinking_config' => ['thinking_budget' => 2048]]],
                $body['extra_body'] ?? null,
                'Thinking has no budget of its own, so it spends the answer\'s max_tokens.'
            );
            $this->assertSame(
                1024 + 2048,
                $body['max_tokens'],
                'max_tokens was not raised by the budget, so thinking still eats the answer.'
            );
            $this->assertArrayNotHasKey(
                'reasoning_effort',
                $body,
                'Google rejects reasoning_effort together with thinking_config.'
            );
        }
    }

    /**
     * Small calls get a budget scaled to their answer, never below the floor.
     */
    public function test_small_calls_get_a_scaled_budget(): void {
        $classifier = $this->body($this->gemini('gemini-2.5-flash'), ['max_tokens' => 200]);
        $this->assertSame(512, $classifier['extra_body']['google']['thinking_config']['thinking_budget']);
        $this->assertSame(712, $classifier['max_tokens']);

        $profile = $this->body($this->gemini('gemini-2.5-flash'), ['max_tokens' => 512]);
        $this->assertSame(1024, $profile['extra_body']['google']['thinking_config']['thinking_budget']);
        $this->assertSame(1536, $profile['max_tokens']);

        $big = $this->body($this->gemini('gemini-2.5-flash'), ['max_tokens' => 8192]);
        $this->assertSame(2048, $big['extra_body']['google']['thinking_config']['thinking_budget']);
        $this->assertSame(8192 + 2048, $big['max_tokens']);
    }

    /**
     * Every thinking Gemini model gets the budget, with or without the "models/" prefix.
     */
    public function test_every_thinking_gemini_model_gets_the_budget(): void {
        $models = ['gemini-2.5-pro', 'models/gemini-2.5-flash', 'gemini-3.5-flash', 'gemini-3-flash-preview',
            'gemini-flash-latest', 'gemini-pro-latest'];
        foreach ($models as $model) {
            $body = $this->body($this->gemini($model), ['max_tokens' => 1024]);
            $this->assertArrayHasKey('extra_body', $body, $model . ' thinks but got no budget.');
            $this->assertSame(3072, $body['max_tokens'], $model);
            $this->assertGreaterThanOrEqual(
                128,
                $body['extra_body']['google']['thinking_config']['thinking_budget'],
                'gemini-2.5-pro rejects a thinking budget under 128.'
            );
        }
    }

    /**
     * Models that do not think by default are sent exactly what they were before.
     */
    public function test_non_thinking_gemini_models_are_left_alone(): void {
        foreach (['gemini-2.0-flash', 'gemini-1.5-pro', 'gemini-2.5-flash-lite', 'gemini-3.5-flash-lite',
                'gemini-flash-lite-latest'] as $model) {
            $body = $this->body($this->gemini($model), ['max_tokens' => 1024]);
            $this->assertArrayNotHasKey(
                'extra_body',
                $body,
                $model . ' would reject the field or have thinking switched on by it.'
            );
            $this->assertSame(1024, $body['max_tokens'], $model);
        }
    }

    /**
     * The raised max_tokens never passes the model's output limit.
     *
     * The admin setting is unbounded; a value near the limit that worked before
     * must not become a 400 because the budget was added on top.
     */
    public function test_raised_max_tokens_stays_within_the_output_limit(): void {
        $body = $this->body($this->gemini('gemini-2.5-flash'), ['max_tokens' => 65000]);
        $this->assertSame(65536, $body['max_tokens']);
        $this->assertSame(2048, $body['extra_body']['google']['thinking_config']['thinking_budget']);
    }

    /**
     * A call that sets no max_tokens has no budget to protect, so nothing is added.
     */
    public function test_a_call_without_max_tokens_is_unchanged(): void {
        $body = $this->body($this->gemini('gemini-2.5-flash'), []);
        $this->assertArrayNotHasKey('extra_body', $body);
        $this->assertArrayNotHasKey('max_tokens', $body);
    }

    /**
     * No other OpenAI-compatible provider is touched.
     */
    public function test_non_gemini_providers_are_unchanged(): void {
        $providers = [
            new openai_provider(['apikey' => 'x', 'model' => 'gpt-4o-mini']),
            new provider\xai_provider(['apikey' => 'x', 'model' => 'grok-4.3']),
            new provider\deepseek_provider(['apikey' => 'x', 'model' => 'deepseek-chat']),
            // A Gemini model id routed through a different vendor's endpoint is that vendor's call.
            new provider\openrouter_provider(['apikey' => 'x', 'model' => 'google/gemini-2.5-flash']),
        ];
        foreach ($providers as $p) {
            $body = $this->body($p, ['max_tokens' => 1024]);
            $this->assertArrayNotHasKey('extra_body', $body, get_class($p));
            $this->assertSame(1024, $body['max_tokens'], get_class($p));
        }
    }

    /**
     * A body that already chose reasoning_effort, or its own extra_body, is not given a second thinking control.
     */
    public function test_never_sent_alongside_reasoning_effort(): void {
        $p = $this->gemini('gemini-2.5-flash');
        $m = new \ReflectionMethod($p, 'adjust_body');
        $m->setAccessible(true);

        $witheffort = $m->invoke($p, ['model' => 'gemini-2.5-flash', 'max_tokens' => 1024, 'reasoning_effort' => 'low'], []);
        $this->assertArrayNotHasKey('extra_body', $witheffort);
        $this->assertSame(1024, $witheffort['max_tokens']);

        $own = ['google' => ['thinking_config' => ['thinking_budget' => 64]]];
        $withextra = $m->invoke($p, ['model' => 'gemini-2.5-flash', 'max_tokens' => 1024, 'extra_body' => $own], []);
        $this->assertSame($own, $withextra['extra_body']);
        $this->assertSame(1024, $withextra['max_tokens']);
    }
}
