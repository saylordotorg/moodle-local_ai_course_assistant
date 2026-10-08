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
 * Essay-feedback provider routing (v7.8.2): same rules as the quiz pair.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\score_essay
 */
final class essay_provider_routing_test extends \advanced_testcase {
    /**
     * Invoke the private resolver and read the model it built.
     *
     * @return string
     */
    private function resolved_model(): string {
        $m = new \ReflectionMethod(\local_ai_course_assistant\external\score_essay::class, 'resolve_essay_provider');
        $m->setAccessible(true);
        $provider = $m->invoke(null, 0);
        $prop = new \ReflectionProperty($provider, 'model');
        $prop->setAccessible(true);
        return (string) $prop->getValue($provider);
    }

    public function test_defaults_to_the_chat_model(): void {
        $this->resetAfterTest();
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('model', 'gpt-4o-mini', 'local_ai_course_assistant');
        $this->assertSame('gpt-4o-mini', $this->resolved_model());
    }

    public function test_partial_override_is_ignored(): void {
        $this->resetAfterTest();
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('model', 'gpt-4o-mini', 'local_ai_course_assistant');
        set_config('essay_provider', 'claude', 'local_ai_course_assistant');
        $this->assertSame('gpt-4o-mini', $this->resolved_model());
        set_config('essay_provider', '', 'local_ai_course_assistant');
        set_config('essay_model', 'claude-haiku-4-5', 'local_ai_course_assistant');
        $this->assertSame('gpt-4o-mini', $this->resolved_model());
    }

    public function test_unresolvable_override_falls_back_without_throwing(): void {
        $this->resetAfterTest();
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('model', 'gpt-4o-mini', 'local_ai_course_assistant');
        set_config('essay_provider', 'nosuchprovider', 'local_ai_course_assistant');
        set_config('essay_model', 'nosuchmodel', 'local_ai_course_assistant');
        $this->assertSame('gpt-4o-mini', $this->resolved_model());
        $this->assertDebuggingCalled();
    }

    public function test_the_essay_role_is_registered_with_the_recommender_and_roles(): void {
        $spec = \local_ai_course_assistant\autoupgrade\roles::spec('essay');
        $this->assertSame('essay_model', $spec['modelkey']);
        $this->assertFalse($spec['auto'], 'an essay model is never switched automatically');
    }
}
