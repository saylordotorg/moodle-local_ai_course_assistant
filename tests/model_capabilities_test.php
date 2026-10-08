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

/**
 * v7.8.0 capability profiles: request shapes come from data, not name checks.
 *
 * Two kinds of test live here. REGRESSION tests pin the exact body today's
 * production models were sent before v7.8.0 (gpt-4o-mini, Gemini 2.5 Flash,
 * Claude Sonnet 5 and Haiku 4.5), so moving the decisions into profiles cannot
 * quietly change a working request. NEW-MODEL tests pin the bodies that failed
 * on 2026-10-07: gpt-6 got max_tokens and a 400, GPT-5 and GPT-6 got a
 * temperature they reject, and gpt-5-mini had no reasoning budget.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\model_capabilities
 * @covers     \local_ai_course_assistant\provider\openai_compatible_provider::apply_reasoning
 */
final class model_capabilities_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        model_capabilities::reset_cache();
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
     * An OpenAI provider on a model.
     *
     * @param string $model
     * @return openai_provider
     */
    private function openai(string $model): openai_provider {
        return new openai_provider(['apikey' => 'x', 'model' => $model, 'temperature' => '0.4']);
    }

    /**
     * REGRESSION: the production failover, gpt-4o-mini, is sent what it always was.
     */
    public function test_gpt_4o_mini_body_is_unchanged(): void {
        $body = $this->body($this->openai('gpt-4o-mini'), ['max_tokens' => 1024]);
        $this->assertSame(1024, $body['max_tokens']);
        $this->assertArrayNotHasKey('max_completion_tokens', $body);
        $this->assertEqualsWithDelta(0.4, $body['temperature'], 0.0001);
        $this->assertArrayNotHasKey('reasoning_effort', $body);
        $this->assertArrayNotHasKey('extra_body', $body);
    }

    /**
     * REGRESSION: the production chat model, gemini-2.5-flash, keeps v7.7.6's budget.
     */
    public function test_gemini_flash_body_is_unchanged(): void {
        $body = $this->body(new gemini_provider(['apikey' => 'x', 'model' => 'gemini-2.5-flash']), ['max_tokens' => 1024]);
        $this->assertSame(3072, $body['max_tokens']);
        $this->assertSame(['google' => ['thinking_config' => ['thinking_budget' => 2048]]], $body['extra_body']);
        $this->assertArrayNotHasKey('reasoning_effort', $body);
        $this->assertArrayHasKey('temperature', $body);
    }

    /**
     * REGRESSION: Claude temperature still follows the allow-list setting.
     */
    public function test_claude_temperature_rules_are_unchanged(): void {
        $this->assertSame(model_capabilities::TEMP_OMIT, model_capabilities::profile('claude', 'claude-sonnet-5')['temperature']);
        $this->assertSame(model_capabilities::TEMP_OMIT, model_capabilities::profile('claude', 'claude-opus-5-5')['temperature']);
        $this->assertSame(model_capabilities::TEMP_ANY, model_capabilities::profile('claude', 'claude-haiku-4-5')['temperature']);
        $this->assertSame(
            model_capabilities::TEMP_ANY,
            model_capabilities::profile('claude', 'claude-sonnet-4-20250514')['temperature']
        );
        $this->assertFalse(model_capabilities::profile('claude', 'claude-opus-5-5')['forced_tool_choice']);
        $this->assertTrue(model_capabilities::profile('claude', 'claude-opus-5')['forced_tool_choice']);
        $this->assertSame(
            claude_provider::FORCED_TOOL_CHOICE_DENY_PREFIXES,
            model_capabilities::CLAUDE_FORCED_TOOL_CHOICE_DENY
        );
    }

    /**
     * The 2026-10-07 failure: gpt-6 was sent max_tokens and a temperature, both 400s.
     */
    public function test_gpt_6_gets_max_completion_tokens_no_temperature_and_low_effort(): void {
        foreach ([true, false] as $stream) {
            $body = $this->body($this->openai('gpt-6-luna'), ['max_tokens' => 1024], $stream);
            $this->assertArrayNotHasKey('max_tokens', $body, 'gpt-6 rejects max_tokens with HTTP 400.');
            $this->assertSame(
                1024 + 2048,
                $body['max_completion_tokens'],
                'The answer keeps its 1,024 and reasoning gets 2,048 on top.'
            );
            $this->assertArrayNotHasKey('temperature', $body, 'gpt-6 accepts only its default temperature.');
            $this->assertSame('low', $body['reasoning_effort']);
        }
    }

    /**
     * gpt-5-mini thought through its whole budget: it now gets headroom and low effort.
     */
    public function test_gpt_5_mini_gets_reasoning_headroom(): void {
        $body = $this->body($this->openai('gpt-5-mini'), ['max_tokens' => 1024]);
        $this->assertSame(3072, $body['max_completion_tokens']);
        $this->assertSame('low', $body['reasoning_effort']);
        $this->assertArrayNotHasKey('temperature', $body);

        $small = $this->body($this->openai('gpt-5-mini'), ['max_tokens' => 200]);
        $this->assertSame(200 + 512, $small['max_completion_tokens'], 'Headroom has a 512-token floor.');
    }

    /**
     * Effort is sent even when the caller sets no max_tokens.
     */
    public function test_effort_is_sent_without_max_tokens(): void {
        $body = $this->body($this->openai('gpt-5-mini'), []);
        $this->assertSame('low', $body['reasoning_effort']);
        $this->assertArrayNotHasKey('max_completion_tokens', $body);
        $this->assertArrayNotHasKey('max_tokens', $body);
    }

    /**
     * The reasoning setting maps onto values each model accepts.
     */
    public function test_reasoning_setting_maps_to_accepted_values(): void {
        set_config('reasoning_effort', 'off', 'local_ai_course_assistant');
        $this->assertSame('minimal', $this->body($this->openai('gpt-5-mini'), [])['reasoning_effort']);
        $this->assertSame('none', $this->body($this->openai('gpt-5.1'), [])['reasoning_effort']);
        $this->assertSame('none', $this->body($this->openai('gpt-6-luna'), [])['reasoning_effort']);
        $this->assertSame(
            'low',
            $this->body($this->openai('o3-mini'), [])['reasoning_effort'],
            'With no none or minimal value the lowest accepted value is used.'
        );
        // An effort of 'none' means no reasoning, so no headroom is added either.
        $this->assertSame(1024, $this->body($this->openai('gpt-5.1'), ['max_tokens' => 1024])['max_completion_tokens']);

        set_config('reasoning_effort', 'high', 'local_ai_course_assistant');
        $this->assertSame('high', $this->body($this->openai('o3-mini'), [])['reasoning_effort']);

        // A per-call option wins over the site setting.
        $this->assertSame('medium', $this->body($this->openai('gpt-5-mini'), ['reasoning' => 'medium'])['reasoning_effort']);

        // An unknown setting value falls back to the shipped default.
        set_config('reasoning_effort', 'bogus', 'local_ai_course_assistant');
        $this->assertSame('low', model_capabilities::site_level());
    }

    /**
     * Gemini 'off' switches thinking off where the model allows it, and only there.
     */
    public function test_gemini_off_sends_a_zero_budget_where_allowed(): void {
        set_config('reasoning_effort', 'off', 'local_ai_course_assistant');
        $flash = $this->body(new gemini_provider(['apikey' => 'x', 'model' => 'gemini-2.5-flash']), ['max_tokens' => 1024]);
        $this->assertSame(0, $flash['extra_body']['google']['thinking_config']['thinking_budget']);
        $this->assertSame(1024, $flash['max_tokens'], 'No headroom when thinking is off.');

        $pro = $this->body(new gemini_provider(['apikey' => 'x', 'model' => 'gemini-2.5-pro']), ['max_tokens' => 1024]);
        $this->assertSame(
            2048,
            $pro['extra_body']['google']['thinking_config']['thinking_budget'],
            '2.5 Pro cannot turn thinking off, so it keeps its normal budget.'
        );
    }

    /**
     * Reasoning models found by prefix at a name boundary, nothing more.
     */
    public function test_prefixes_match_only_at_a_name_boundary(): void {
        foreach (['gpt-5', 'gpt-5-mini', 'gpt-5.1', 'gpt-6', 'gpt-6-luna', 'o1', 'o3-mini', 'o4-mini-2025-04-16'] as $model) {
            $this->assertSame(
                model_capabilities::TOKENS_MAX_COMPLETION,
                model_capabilities::profile('openai', $model)['token_param'],
                $model
            );
        }
        foreach (['gpt-50', 'o10', 'gpt-4o', 'gpt-4.1-mini', 'omni-moderation'] as $model) {
            $this->assertSame(
                model_capabilities::TOKENS_MAX,
                model_capabilities::profile('openai', $model)['token_param'],
                $model
            );
        }
    }

    /**
     * A provider the rules do not know is sent exactly what it was before.
     */
    public function test_unknown_families_get_todays_shape(): void {
        foreach (
            [
            new provider\xai_provider(['apikey' => 'x', 'model' => 'grok-4.3', 'temperature' => '0.4']),
            new provider\deepseek_provider(['apikey' => 'x', 'model' => 'deepseek-chat', 'temperature' => '0.4']),
            new provider\openrouter_provider(['apikey' => 'x', 'model' => 'google/gemini-2.5-flash', 'temperature' => '0.4']),
            ] as $p
        ) {
            $body = $this->body($p, ['max_tokens' => 1024]);
            $this->assertSame(1024, $body['max_tokens'], get_class($p));
            $this->assertArrayHasKey('temperature', $body, get_class($p));
            $this->assertArrayNotHasKey('reasoning_effort', $body, get_class($p));
            $this->assertArrayNotHasKey('extra_body', $body, get_class($p));
        }
    }

    /**
     * A learned fact outranks the rules, and a malformed one is ignored.
     */
    public function test_learned_facts_override_rules_and_bad_rows_are_ignored(): void {
        global $DB;
        $this->assertTrue(model_capabilities::learn('openai', 'gpt-4o-mini', 'temperature', 'omit', 'x'));
        $this->assertArrayNotHasKey('temperature', $this->body($this->openai('gpt-4o-mini'), []));

        $DB->insert_record(model_capabilities::TABLE, (object) [
            'provider' => 'openai', 'modelkey' => 'gpt-4o', 'field' => 'token_param', 'value' => 'evil_param',
            'evidence' => '', 'hits' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        model_capabilities::reset_cache();
        $this->assertSame(model_capabilities::TOKENS_MAX, model_capabilities::profile('openai', 'gpt-4o')['token_param']);
        $this->assertFalse(model_capabilities::learn('openai', 'gpt-4o', 'not_a_field', 'x'));
    }

    /**
     * Thinking billed outside completion_tokens is one fact, read by pricing too.
     */
    public function test_billing_fact_is_shared_with_pricing(): void {
        $this->assertTrue(model_capabilities::profile('gemini', 'gemini-2.5-flash')['billed_outside_completion']);
        $this->assertFalse(model_capabilities::profile('openai', 'gpt-5-mini')['billed_outside_completion']);
        $this->assertTrue(token_cost_manager::reasoning_billed_as_extra_output('gemini-2.5-flash'));
        $this->assertFalse(token_cost_manager::reasoning_billed_as_extra_output('gpt-5-mini'));
    }

    /**
     * A subclass from outside the provider namespace keeps its vendor's profile.
     *
     * Test doubles and probes subclass a provider. Before v7.8.0 an anonymous
     * one reported "claude_provider@anonymous..." as its provider id, and the
     * profile is chosen by provider id, so it got the generic shape.
     */
    public function test_a_subclass_keeps_its_vendors_profile(): void {
        $double = new class(['apikey' => 'x', 'model' => 'claude-opus-5-5']) extends claude_provider {
        };
        $this->assertSame('claude', $double->provider_id());
        $body = $this->body($double, ['response_schema' => ['name' => 'g', 'schema' => ['type' => 'object']]]);
        $this->assertSame(['type' => 'auto'], $body['tool_choice'],
            'The double was given another vendor\'s request shape.');
    }

    /**
     * The output limit caps the total, so headroom never produces a 400.
     */
    public function test_output_limit_caps_the_total(): void {
        $body = $this->body($this->openai('gpt-4o-mini'), ['max_tokens' => 50000]);
        $this->assertSame(16384, $body['max_tokens']);
    }
}
