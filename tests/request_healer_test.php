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

use local_ai_course_assistant\provider\request_healer;

/**
 * v7.8.0: the request healer reads a vendor's 400 and names one fix.
 *
 * Each case is a real error body shape from the vendor named, with the request
 * that provoked it. The negative cases matter as much: a 400 that names nothing
 * recognisable, or a parameter the request did not carry, must produce no fix,
 * because a guessed retry turns a clear error into a confusing one.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\request_healer
 */
final class request_healer_test extends \advanced_testcase {
    /**
     * An OpenAI-style error body.
     *
     * @param string $message
     * @param string|null $param
     * @return string
     */
    private function openai_error(string $message, ?string $param = null): string {
        return json_encode(['error' => ['message' => $message, 'type' => 'invalid_request_error',
            'param' => $param, 'code' => 'unsupported_parameter']]);
    }

    /**
     * Diagnose with a profile for the given provider and model.
     *
     * @param string $body
     * @param array $sent
     * @param string $provider
     * @param string $model
     * @param int $status
     * @return array|null
     */
    private function diagnose(
        string $body,
        array $sent,
        string $provider = 'openai',
        string $model = 'gpt-9',
        int $status = 400
    ): ?array {
        $this->resetAfterTest();
        return request_healer::diagnose($status, $body, $sent, model_capabilities::profile($provider, $model));
    }

    public function test_openai_max_tokens_to_max_completion_tokens(): void {
        $fix = $this->diagnose($this->openai_error("Unsupported parameter: 'max_tokens' is not supported with this "
            . "model. Use 'max_completion_tokens' instead.", 'max_tokens'), ['max_tokens' => 1024]);
        $this->assertSame(['token_param', 'max_completion_tokens'], [$fix['field'], $fix['value']]);
    }

    public function test_max_completion_tokens_back_to_max_tokens(): void {
        $fix = $this->diagnose(
            $this->openai_error('Unrecognized request argument supplied: max_completion_tokens'),
            ['max_completion_tokens' => 1024],
            'together',
            'some/model'
        );
        $this->assertSame(['token_param', 'max_tokens'], [$fix['field'], $fix['value']]);
    }

    public function test_openai_temperature_only_default(): void {
        $fix = $this->diagnose($this->openai_error("Unsupported value: 'temperature' does not support 0.4 with this "
            . "model. Only the default (1) value is supported.", 'temperature'), ['temperature' => 0.4]);
        $this->assertSame(['temperature', 'omit'], [$fix['field'], $fix['value']]);
    }

    public function test_anthropic_temperature_deprecated(): void {
        $body = json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error',
            'message' => 'temperature is deprecated for this model.']]);
        $fix = $this->diagnose($body, ['temperature' => 0.7, 'max_tokens' => 1024], 'claude', 'claude-x-9');
        $this->assertSame(['temperature', 'omit'], [$fix['field'], $fix['value']]);
    }

    public function test_reasoning_effort_value_list_is_learned(): void {
        $fix = $this->diagnose(
            $this->openai_error("Unsupported value: 'reasoning_effort' does not support 'minimal' "
            . "with this model. Supported values are: 'low', 'medium', and 'high'.", 'reasoning_effort'),
            ['reasoning_effort' => 'minimal']
        );
        $this->assertSame(['reasoning_efforts', 'low,medium,high'], [$fix['field'], $fix['value']]);
    }

    public function test_reasoning_effort_not_supported_at_all(): void {
        $fix = $this->diagnose(
            $this->openai_error('Unrecognized request argument supplied: reasoning_effort'),
            ['reasoning_effort' => 'low']
        );
        $this->assertSame(['reasoning', 'none'], [$fix['field'], $fix['value']]);
    }

    public function test_output_limit_is_learned(): void {
        $fix = $this->diagnose($this->openai_error('max_tokens is too large: 50000. This model supports at most '
            . '16384 completion tokens, whereas you provided 50000.', 'max_tokens'), ['max_tokens' => 50000]);
        $this->assertSame(['max_output_tokens', '16384'], [$fix['field'], $fix['value']]);

        $anthropic = json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error',
            'message' => 'max_tokens: 100000 > 64000, which is the maximum allowed number of output tokens for x']]);
        $fix = $this->diagnose($anthropic, ['max_tokens' => 100000], 'claude', 'claude-x-9');
        $this->assertSame(['max_output_tokens', '64000'], [$fix['field'], $fix['value']]);
    }

    public function test_gemini_thinking_budget_zero_refused(): void {
        $body = json_encode([['error' => ['code' => 400,
            'message' => 'Budget 0 is invalid. This model only works in thinking mode.', 'status' => 'INVALID_ARGUMENT']]]);
        $sent = ['extra_body' => ['google' => ['thinking_config' => ['thinking_budget' => 0]]]];
        $fix = $this->diagnose($body, $sent, 'gemini', 'gemini-9-pro');
        $this->assertSame(['thinking_off', 'forbidden'], [$fix['field'], $fix['value']]);
    }

    public function test_gemini_thinking_config_unknown(): void {
        $body = json_encode([['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT',
            'message' => 'Invalid JSON payload received. Unknown name "extra_body": Cannot find field.']]]);
        $sent = ['max_tokens' => 3072, 'extra_body' => ['google' => ['thinking_config' => ['thinking_budget' => 2048]]]];
        $fix = $this->diagnose($body, $sent, 'gemini', 'gemini-2.5-flash');
        $this->assertSame(['reasoning', 'none'], [$fix['field'], $fix['value']]);
    }

    public function test_claude_forced_tool_choice_refused(): void {
        $body = json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error',
            'message' => 'tool_choice: type "tool" and "any" are not supported for this model.']]);
        $fix = $this->diagnose($body, ['tool_choice' => ['type' => 'tool', 'name' => 'x']], 'claude', 'claude-x-9');
        $this->assertSame(['forced_tool_choice', '0'], [$fix['field'], $fix['value']]);
    }

    public function test_vllm_extra_inputs(): void {
        $body = json_encode(['detail' => [['type' => 'extra_forbidden', 'loc' => ['body', 'reasoning_effort'],
            'msg' => 'Extra inputs are not permitted']]]);
        $fix = $this->diagnose($body, ['reasoning_effort' => 'low'], 'custom', 'my-model', 422);
        $this->assertSame(['reasoning', 'none'], [$fix['field'], $fix['value']]);
    }

    /**
     * Unrecognised rejections are left alone: no fix, so no retry.
     */
    public function test_unrecognised_400s_produce_no_fix(): void {
        $this->assertNull($this->diagnose(
            $this->openai_error('This model is overloaded, try again.'),
            ['max_tokens' => 10, 'temperature' => 0.4]
        ));
        $this->assertNull($this->diagnose(
            $this->openai_error("Invalid 'messages[0].content': string too long."),
            ['max_tokens' => 10]
        ));
        $this->assertNull($this->diagnose('', ['max_tokens' => 10]));
        $this->assertNull($this->diagnose('<html>Bad Request</html>', ['max_tokens' => 10]));
    }

    /**
     * A message naming a parameter the request did not carry is not acted on.
     */
    public function test_parameter_must_have_been_sent(): void {
        $this->assertNull($this->diagnose(
            $this->openai_error("Unsupported value: 'temperature' does not support 0.4"),
            ['max_tokens' => 10]
        ));
        $this->assertNull($this->diagnose(
            $this->openai_error('Unrecognized request argument supplied: reasoning_effort'),
            ['max_tokens' => 10]
        ));
    }

    /**
     * Only 400 and 422 are healed; auth, rate and server errors never are.
     */
    public function test_other_statuses_are_never_healed(): void {
        $body = $this->openai_error("Unsupported parameter: 'max_tokens' is not supported with this model.", 'max_tokens');
        foreach ([401, 403, 404, 429, 500, 503] as $status) {
            $this->assertNull($this->diagnose($body, ['max_tokens' => 10], 'openai', 'gpt-9', $status), (string) $status);
        }
    }
}
