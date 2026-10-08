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
 * The reasoning level reaches Claude 5.5 models (v7.8.2).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\claude_provider
 */
final class claude_reasoning_level_test extends \advanced_testcase {
    /**
     * The request body a model would be sent.
     *
     * @param string $model
     * @param array $options
     * @return array
     */
    private function body(string $model, array $options = []): array {
        $p = new claude_provider(['apikey' => 'k-test', 'model' => $model]);
        $m = new \ReflectionMethod($p, 'build_body');
        $m->setAccessible(true);
        return json_decode($m->invoke($p, 'sys', [['role' => 'user', 'content' => 'hi']], false,
            $options + ['max_tokens' => 1024]), true);
    }

    public function test_off_sends_between_tools_on_sonnet_5_5(): void {
        $b = $this->body('claude-sonnet-5-5', ['reasoning' => 'off']);
        $this->assertSame(['type' => 'between_tools'], $b['thinking']);
        $this->assertArrayNotHasKey('output_config', $b);
        $this->assertSame(1024, $b['max_tokens'], 'No thinking, no headroom.');
    }

    public function test_levels_become_output_config_effort_with_headroom(): void {
        foreach (['low', 'medium', 'high'] as $level) {
            $b = $this->body('claude-sonnet-5-5', ['reasoning' => $level]);
            $this->assertSame(['effort' => $level], $b['output_config']);
            $this->assertArrayNotHasKey('thinking', $b);
            $this->assertSame(1024 + model_capabilities::headroom(1024), $b['max_tokens']);
        }
    }

    public function test_the_site_setting_is_the_default_level(): void {
        $this->resetAfterTest();
        set_config('reasoning_effort', 'medium', 'local_ai_course_assistant');
        $this->assertSame(['effort' => 'medium'], $this->body('claude-sonnet-5-5')['output_config']);
        set_config('reasoning_effort', 'off', 'local_ai_course_assistant');
        $this->assertSame(['type' => 'between_tools'], $this->body('claude-sonnet-5-5')['thinking']);
    }

    public function test_opus_5_5_takes_effort_but_never_between_tools(): void {
        $b = $this->body('claude-opus-5-5', ['reasoning' => 'off']);
        $this->assertArrayNotHasKey('thinking', $b);
        $this->assertSame(['effort' => 'low'], $b['output_config']);
    }

    public function test_other_claude_models_are_sent_exactly_what_they_were(): void {
        foreach (['claude-haiku-4-5', 'claude-sonnet-5', 'claude-sonnet-4-6'] as $model) {
            $b = $this->body($model, ['reasoning' => 'off']);
            $this->assertArrayNotHasKey('thinking', $b, $model);
            $this->assertArrayNotHasKey('output_config', $b, $model);
            $this->assertSame(1024, $b['max_tokens'], $model);
        }
    }

    public function test_an_explicit_thinking_request_is_left_alone(): void {
        $b = $this->body('claude-sonnet-5-5', ['thinking' => true, 'reasoning' => 'off']);
        $this->assertSame(['type' => 'adaptive'], $b['thinking']);
        $this->assertArrayNotHasKey('output_config', $b);
    }

    public function test_headroom_never_passes_the_output_limit(): void {
        $this->resetAfterTest();
        model_capabilities::learn('claude', 'claude-sonnet-5-5', 'max_output_tokens', '1500', 'test');
        $this->assertLessThanOrEqual(1500, $this->body('claude-sonnet-5-5', ['reasoning' => 'low'])['max_tokens']);
    }

    public function test_a_model_taught_that_thinking_is_refused_is_sent_neither_field(): void {
        $this->resetAfterTest();
        model_capabilities::learn('claude', 'claude-sonnet-5-5', 'reasoning', model_capabilities::REASONING_NONE, 'test');
        $b = $this->body('claude-sonnet-5-5', ['reasoning' => 'off']);
        $this->assertArrayNotHasKey('thinking', $b);
        $this->assertArrayNotHasKey('output_config', $b);
    }

    public function test_the_healer_learns_that_effort_is_refused(): void {
        $profile = model_capabilities::profile('claude', 'claude-sonnet-5-5');
        $fix = request_healer::diagnose(
            400,
            json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'output_config.effort is not supported for this model']]),
            ['output_config' => ['effort' => 'low']],
            $profile
        );
        $this->assertSame('claude_effort', $fix['field'] ?? null);
        $this->assertSame('0', $fix['value'] ?? null);
        $healed = model_capabilities::apply_fact($profile, 'claude_effort', '0');
        $this->assertFalse($healed['claude_effort']);
    }
}
