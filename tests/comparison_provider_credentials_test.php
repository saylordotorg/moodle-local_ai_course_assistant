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

use local_ai_course_assistant\provider\base_provider;

/**
 * create_for_comparison() must never hand one vendor's API key to another.
 *
 * Regression cover for 2026-08-18: CS101 was configured with a per-course
 * Anthropic provider and key. An admin whose LLM picker was set to gemini --
 * a provider with no Comparison providers row on that site -- had the course's
 * Anthropic key passed to a Gemini client, and saw a bare "HTTP 400:" with no
 * message, because Google's error body is not in the shape SOLA parses.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\base_provider::create_for_comparison
 */
final class comparison_provider_credentials_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Read the apikey a built provider ended up holding.
     *
     * @param object $provider
     * @return string
     */
    private function key_of($provider): string {
        $ref = new \ReflectionObject($provider);
        while ($ref && !$ref->hasProperty('apikey')) {
            $ref = $ref->getParentClass();
        }
        if (!$ref) {
            return '';
        }
        $p = $ref->getProperty('apikey');
        $p->setAccessible(true);
        return (string) $p->getValue($provider);
    }

    public function test_does_not_lend_one_vendors_key_to_another(): void {
        // Site + course are on Anthropic. gemini has no comparison row, so it
        // must NOT receive the Anthropic key. This is the 2026-08-18 bug.
        set_config('provider', 'claude', 'local_ai_course_assistant');
        set_config('apikey', 'sk-ant-SITE-KEY', 'local_ai_course_assistant');
        set_config('comparison_providers', "openai|sk-openai-key|GPT-4o-mini|\n",
            'local_ai_course_assistant');

        // Refusing is the only way to stop it: base_provider's constructor
        // treats an empty apikey override as "unset" and falls back to the
        // site key, so the credential cannot simply be blanked.
        try {
            base_provider::create_for_comparison('gemini', 'gemini-2.5-flash', $this->course->id);
            $this->fail('Expected a refusal rather than sending an Anthropic key to Gemini.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('gemini', $e->debuginfo);
            $this->assertStringContainsString('Comparison providers', $e->debuginfo,
                'The message must name the missing configuration so an operator can act on it.');
        }
    }

    public function test_keyless_providers_are_not_broken_by_the_guard(): void {
        // Several providers legitimately need no SOLA key -- a stub in tests, a
        // local ollama, coreai via Moodle's AI subsystem. An earlier version of
        // this guard threw here, which would have broken all of them.
        set_config('provider', 'claude', 'local_ai_course_assistant');
        set_config('apikey', 'sk-ant-SITE-KEY', 'local_ai_course_assistant');
        set_config('comparison_providers', '', 'local_ai_course_assistant');

        $provider = base_provider::create_for_comparison('stub', 'stub', $this->course->id);

        $this->assertNotNull($provider, 'A keyless provider must still be constructible.');
    }

    public function test_uses_the_comparison_row_key_when_one_exists(): void {
        set_config('provider', 'claude', 'local_ai_course_assistant');
        set_config('apikey', 'sk-ant-SITE-KEY', 'local_ai_course_assistant');
        set_config('comparison_providers', "openai|sk-openai-ROW-KEY|GPT-4o-mini|\n",
            'local_ai_course_assistant');

        $provider = base_provider::create_for_comparison('openai', 'gpt-4o-mini', $this->course->id);

        $this->assertSame('sk-openai-ROW-KEY', $this->key_of($provider),
            'The row key must win; this is the normal comparison path.');
    }

    public function test_falls_back_to_the_site_key_for_the_site_provider(): void {
        // Requesting the site's own provider with no row is legitimate -- the
        // site key belongs to it, so use that rather than refusing.
        set_config('provider', 'gemini', 'local_ai_course_assistant');
        set_config('apikey', 'gemini-SITE-KEY', 'local_ai_course_assistant');
        set_config('comparison_providers', '', 'local_ai_course_assistant');

        $provider = base_provider::create_for_comparison('gemini', 'gemini-2.5-flash', $this->course->id);

        $this->assertSame('gemini-SITE-KEY', $this->key_of($provider));
    }

    public function test_same_provider_keeps_the_inherited_key(): void {
        // Asking for the provider the course already uses must not change
        // anything -- no row needed, no refusal.
        set_config('provider', 'claude', 'local_ai_course_assistant');
        set_config('apikey', 'sk-ant-SITE-KEY', 'local_ai_course_assistant');
        set_config('comparison_providers', '', 'local_ai_course_assistant');

        $provider = base_provider::create_for_comparison('claude', 'claude-haiku-4-5', $this->course->id);

        $this->assertSame('sk-ant-SITE-KEY', $this->key_of($provider));
    }
}
