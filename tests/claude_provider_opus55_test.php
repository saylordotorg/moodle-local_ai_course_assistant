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
 * The two request shapes Claude Opus 5.5 rejects outright.
 *
 * Both return HTTP 400 for the whole request, and the SSE write path swallows
 * the error body, so each one surfaced to a learner as the generic "Sorry,
 * something went wrong" with nothing in the log naming the parameter. Neither
 * is a degradation: the call simply does not happen.
 *
 *  - `temperature`. The deny-list at model_supports_temperature() was already
 *    right, but the thinking branch set temperature = 1 BEFORE consulting it,
 *    on the old rule that extended thinking requires temperature exactly 1.
 *    That rule belongs to the models that still accept the parameter at all.
 *  - `tool_choice` of type `tool`. Opus 5.5, Sonnet 5.5, Fable 5.1 and Mythos
 *    5.1 removed forced tool choice. Structured output is the only caller, so
 *    every structured-output call on those models failed.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\claude_provider
 */
final class claude_provider_opus55_test extends \advanced_testcase {

    /**
     * Build a request body for a given model and options.
     *
     * build_body() is private by design; the wire shape is the contract.
     *
     * @param string $model Model identifier.
     * @param array $options Request options passed through to build_body().
     * @return array The decoded request body.
     */
    private function body(string $model, array $options): array {
        $this->resetAfterTest();
        $provider = new claude_provider(['apikey' => 'test-key', 'model' => $model]);
        $r = new \ReflectionClass(claude_provider::class);
        $m = $r->getMethod('build_body');
        $m->setAccessible(true);
        $json = $m->invoke($provider, 'You are a tutor.',
            [['role' => 'user', 'content' => 'hello']], false, $options);
        return json_decode($json, true);
    }

    /**
     * A thinking request to Opus 5.5 must not carry temperature at all.
     */
    public function test_thinking_sends_no_temperature_on_opus_5_5(): void {
        $body = $this->body('claude-opus-5-5', ['thinking' => true]);

        $this->assertSame(['type' => 'adaptive'], $body['thinking']);
        $this->assertArrayNotHasKey('temperature', $body,
            'Opus 5.5 removed temperature; sending it 400s the whole request');
    }

    /**
     * The same is true for the rest of the generation that removed it.
     */
    public function test_thinking_sends_no_temperature_on_other_reasoning_models(): void {
        foreach (['claude-opus-5', 'claude-opus-4-8', 'claude-opus-4-7', 'claude-sonnet-5'] as $model) {
            $body = $this->body($model, ['thinking' => true]);
            $this->assertArrayNotHasKey('temperature', $body, "temperature leaked for {$model}");
        }
    }

    /**
     * Models that still accept sampling keep the temperature = 1 thinking rule.
     *
     * The fix must not strip it where it is still required, or thinking breaks
     * on the older models instead.
     */
    public function test_thinking_still_pins_temperature_where_supported(): void {
        $body = $this->body('claude-sonnet-4-6', ['thinking' => true]);

        $this->assertSame(1, $body['temperature']);
    }

    /**
     * Structured output on Opus 5.5 uses auto, never a forced choice.
     */
    public function test_structured_output_is_not_forced_on_opus_5_5(): void {
        $body = $this->body('claude-opus-5-5', [
            'response_schema' => ['name' => 'grade', 'schema' => ['type' => 'object']],
        ]);

        $this->assertSame(['type' => 'auto'], $body['tool_choice']);
    }

    /**
     * Strict mode is never sent, because this provider cannot satisfy it.
     *
     * The first version of this fix set strict: true, and this test asserted
     * it. Strict compiles the schema through the structured-outputs pipeline,
     * which rejects array minItems above 1, any maxItems, numeric and length
     * bounds, and any object without additionalProperties: false. The SDKs
     * strip those keywords; raw HTTP does not. Measured live against
     * claude-sonnet-5-5: the real objectives schema below returned HTTP 400.
     *
     * The earlier tests only ever sent ['type' => 'object'], which strict mode
     * would ALSO reject for lacking additionalProperties: false, and still
     * passed, because nothing here talks to the API. That is why this one uses
     * a schema a real caller actually sends.
     */
    public function test_strict_mode_is_never_sent_with_a_real_caller_schema(): void {
        $objectives = [
            'name' => 'learning_objectives',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'objectives' => [
                        'type' => 'array',
                        'minItems' => 6,
                        'maxItems' => 12,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                            ],
                            'required' => ['title', 'description'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['objectives'],
                'additionalProperties' => false,
            ],
        ];

        foreach (\local_ai_course_assistant\provider\claude_provider::FORCED_TOOL_CHOICE_DENY_PREFIXES as $model) {
            $body = $this->body($model, ['response_schema' => $objectives]);

            $this->assertArrayNotHasKey('strict', $body['tools'][0],
                "strict sent for {$model}; strict mode 400s on minItems 6 / maxItems 12");
            $this->assertSame(['type' => 'auto'], $body['tool_choice']);
            // The caller's bounds still travel as guidance; they are what kept
            // the live run inside 6..12 without strict enforcing them.
            $this->assertSame(6, $body['tools'][0]['input_schema']['properties']['objectives']['minItems']);
        }
    }

    /**
     * The steering instruction is its own system block, not an edit to the
     * cached one.
     *
     * The first block carries cache_control, so appending to its text would
     * invalidate the cached prefix on every call whose schema name differs.
     */
    public function test_the_steering_instruction_does_not_disturb_the_cached_block(): void {
        $plain = $this->body('claude-opus-5-5', []);
        $structured = $this->body('claude-opus-5-5', [
            'response_schema' => ['name' => 'grade', 'schema' => ['type' => 'object']],
        ]);

        $this->assertSame($plain['system'][0], $structured['system'][0],
            'the cached system block must be byte-identical');
        $this->assertCount(2, $structured['system']);
        $this->assertArrayNotHasKey('cache_control', $structured['system'][1]);
        $this->assertStringContainsString('grade', $structured['system'][1]['text']);
    }

    /**
     * Models that still accept a forced tool choice keep getting one.
     */
    public function test_structured_output_stays_forced_where_supported(): void {
        foreach (['claude-opus-5', 'claude-sonnet-5', 'claude-sonnet-4-6'] as $model) {
            $body = $this->body($model, [
                'response_schema' => ['name' => 'grade', 'schema' => ['type' => 'object']],
            ]);
            $this->assertSame(['type' => 'tool', 'name' => 'grade'], $body['tool_choice'],
                "forced tool choice was dropped for {$model}, which still accepts it");
            $this->assertCount(1, $body['system'], "an extra system block leaked for {$model}");
        }
    }

    /**
     * The deny list must not catch the shorter ids it sits next to.
     *
     * 'claude-opus-5-5' is not a prefix of 'claude-opus-5', which is what makes
     * prefix matching safe in this direction; the temperature list next door
     * had to be an allow list for the opposite reason.
     */
    public function test_the_deny_list_does_not_overreach(): void {
        $r = new \ReflectionClass(claude_provider::class);
        $m = $r->getMethod('model_supports_forced_tool_choice');
        $m->setAccessible(true);

        foreach (['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-fable-5-1', 'claude-mythos-5-1'] as $denied) {
            $this->assertFalse($m->invoke(null, $denied), "{$denied} should be denied");
        }
        foreach (['claude-opus-5', 'claude-sonnet-5', 'claude-opus-4-8', 'claude-haiku-4-5', ''] as $allowed) {
            $this->assertTrue($m->invoke(null, $allowed), "{$allowed} should be allowed");
        }
    }
}
