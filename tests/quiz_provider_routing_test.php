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

defined('MOODLE_INTERNAL') || die();

/**
 * Quiz-coach provider routing (v6.9.5).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\generate_quiz
 */
final class quiz_provider_routing_test extends \advanced_testcase {

    /**
     * Invoke the private resolver.
     *
     * @return string Resolved model name.
     */
    private function resolved_model(): string {
        $m = new \ReflectionMethod(\local_ai_course_assistant\external\generate_quiz::class, 'resolve_quiz_provider');
        $m->setAccessible(true);
        $provider = $m->invoke(null, 0);
        // `model` is a protected property on base_provider with no public
        // accessor; read it reflectively for the assertion.
        $prop = new \ReflectionProperty($provider, 'model');
        $prop->setAccessible(true);
        return (string) $prop->getValue($provider);
    }

    /**
     * With no override set, quiz stays on the chat provider — the behaviour
     * every existing site relies on.
     */
    public function test_defaults_to_chat_provider(): void {
        $this->resetAfterTest();
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('model', 'gpt-4o-mini', 'local_ai_course_assistant');
        set_config('quiz_provider', '', 'local_ai_course_assistant');
        set_config('quiz_model', '', 'local_ai_course_assistant');

        $this->assertSame('gpt-4o-mini', $this->resolved_model());
    }

    /**
     * A half-configured override (provider only, or model only) must NOT
     * take effect — it falls back to the chat provider rather than routing
     * to a provider with no model.
     */
    public function test_partial_override_is_ignored(): void {
        $this->resetAfterTest();
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('model', 'gpt-4o-mini', 'local_ai_course_assistant');

        set_config('quiz_provider', 'claude', 'local_ai_course_assistant');
        set_config('quiz_model', '', 'local_ai_course_assistant');
        $this->assertSame('gpt-4o-mini', $this->resolved_model());

        set_config('quiz_provider', '', 'local_ai_course_assistant');
        set_config('quiz_model', 'claude-haiku-4-5', 'local_ai_course_assistant');
        $this->assertSame('gpt-4o-mini', $this->resolved_model());
    }

    /**
     * An unresolvable provider degrades to the chat tier rather than
     * throwing — quiz generation must not hard-fail on a bad setting.
     */
    public function test_unresolvable_override_falls_back_without_throwing(): void {
        $this->resetAfterTest();
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('model', 'gpt-4o-mini', 'local_ai_course_assistant');
        set_config('quiz_provider', 'nosuchprovider', 'local_ai_course_assistant');
        set_config('quiz_model', 'nosuchmodel', 'local_ai_course_assistant');

        $this->assertSame('gpt-4o-mini', $this->resolved_model());
        // The fallback is deliberately noisy in developer mode so a broken
        // override is diagnosable rather than silently ignored.
        $this->assertDebuggingCalled();
    }
}
