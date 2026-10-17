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

    /**
     * Read a protected string property a built provider ended up holding.
     *
     * @param object $provider
     * @param string $name property name
     * @return string
     */
    private function prop_of($provider, string $name): string {
        $ref = new \ReflectionObject($provider);
        while ($ref && !$ref->hasProperty($name)) {
            $ref = $ref->getParentClass();
        }
        if (!$ref) {
            return '';
        }
        $p = $ref->getProperty($name);
        $p->setAccessible(true);
        return (string) $p->getValue($provider);
    }

    /**
     * Give the test course its own provider, key and base URL.
     *
     * @param string $provider
     * @param string $key plain key; course_config_manager conceals it
     * @param string $baseurl
     * @return void
     */
    private function course_with(string $provider, string $key, string $baseurl = ''): void {
        \local_ai_course_assistant\course_config_manager::save($this->course->id, [
            'enabled' => 1, 'provider' => $provider, 'apikey' => $key, 'apibaseurl' => $baseurl,
        ]);
    }

    /**
     * The 7.9.0 upgrade adds a row for the default provider that mirrors the site key. Before 7.9.0 a course
     * with its own key for that provider kept it (and its own gateway) when no row existed, so the mirror
     * must not take the course's credential over (ruling R20).
     */
    public function test_a_mirror_row_does_not_shadow_a_courses_own_key_or_gateway(): void {
        set_config('provider', 'claude', 'local_ai_course_assistant');
        set_config('apikey', 'sk-ant-SITE-KEY-1111', 'local_ai_course_assistant');
        set_config('comparison_providers', "claude|sk-ant-SITE-KEY-1111|claude-haiku-4-5|0.3",
            'local_ai_course_assistant');
        $this->course_with('claude', 'sk-ant-COURSE-KEY-2222', 'https://course-gw.example.test/v1');

        $provider = base_provider::create_for_comparison('claude', 'claude-haiku-4-5', $this->course->id);

        $this->assertSame('sk-ant-COURSE-KEY-2222', $this->key_of($provider), 'the course key is kept');
        $this->assertSame('https://course-gw.example.test/v1', $this->prop_of($provider, 'baseurl'),
            'and so is the course gateway, so the site key never goes there');
    }

    public function test_a_mirror_row_with_a_site_gateway_does_not_replace_a_courses_own_gateway(): void {
        set_config('provider', 'claude', 'local_ai_course_assistant');
        set_config('apibaseurl', 'https://site-gw.example.test/v1', 'local_ai_course_assistant');
        set_config('apikey', 'sk-ant-SITE-KEY-1111', 'local_ai_course_assistant');
        // What the upgrade writes for a gateway site: the mirror row carries the site's base URL.
        set_config('comparison_providers', "claude|sk-ant-SITE-KEY-1111|m||https://site-gw.example.test/v1",
            'local_ai_course_assistant');
        $this->course_with('claude', 'sk-ant-COURSE-KEY-2222', 'https://course-gw.example.test/v1');

        $provider = base_provider::create_for_comparison('claude', 'claude-haiku-4-5', $this->course->id);

        $this->assertSame('sk-ant-COURSE-KEY-2222', $this->key_of($provider));
        $this->assertSame('https://course-gw.example.test/v1', $this->prop_of($provider, 'baseurl'));
    }

    public function test_a_mirror_row_still_applies_its_temperature_when_the_course_key_stays(): void {
        set_config('provider', 'claude', 'local_ai_course_assistant');
        set_config('apikey', 'sk-ant-SITE-KEY-1111', 'local_ai_course_assistant');
        set_config('comparison_providers', "claude|sk-ant-SITE-KEY-1111|m|0.2", 'local_ai_course_assistant');
        $this->course_with('claude', 'sk-ant-COURSE-KEY-2222');

        $provider = base_provider::create_for_comparison('claude', 'claude-haiku-4-5', $this->course->id);

        $this->assertSame('sk-ant-COURSE-KEY-2222', $this->key_of($provider));
        $this->assertEqualsWithDelta(0.2, (float) $this->prop_of($provider, 'temperature'), 0.0001);
    }

    public function test_a_mirror_row_with_no_course_key_gives_the_site_key_as_before(): void {
        set_config('provider', 'claude', 'local_ai_course_assistant');
        set_config('apikey', 'sk-ant-SITE-KEY-1111', 'local_ai_course_assistant');
        set_config('comparison_providers', "claude|sk-ant-SITE-KEY-1111|claude-haiku-4-5", 'local_ai_course_assistant');

        $provider = base_provider::create_for_comparison('claude', 'claude-haiku-4-5', $this->course->id);

        $this->assertSame('sk-ant-SITE-KEY-1111', $this->key_of($provider));
    }

    public function test_a_row_that_shares_the_site_key_but_has_its_own_gateway_still_routes_there(): void {
        // No course key, so the course has nothing to protect: the row's base URL applies exactly as before.
        set_config('provider', 'claude', 'local_ai_course_assistant');
        set_config('apikey', 'sk-ant-SITE-KEY-1111', 'local_ai_course_assistant');
        set_config('comparison_providers', "claude|sk-ant-SITE-KEY-1111|m||https://row-gw.example.test",
            'local_ai_course_assistant');

        $provider = base_provider::create_for_comparison('claude', 'claude-haiku-4-5', $this->course->id);

        $this->assertSame('sk-ant-SITE-KEY-1111', $this->key_of($provider));
        $this->assertSame('https://row-gw.example.test', $this->prop_of($provider, 'baseurl'));
    }

    public function test_a_row_with_its_own_credential_still_beats_a_courses_key(): void {
        // A deliberate row for the same vendor with a DIFFERENT key (a premium-tier key, say) wins exactly as
        // it did before 7.9.0.
        set_config('provider', 'claude', 'local_ai_course_assistant');
        set_config('apikey', 'sk-ant-SITE-KEY-1111', 'local_ai_course_assistant');
        set_config('comparison_providers', "claude|sk-ant-PREMIUM-ROW-3333|claude-sonnet-5||https://row-gw.example.test",
            'local_ai_course_assistant');
        $this->course_with('claude', 'sk-ant-COURSE-KEY-2222', 'https://course-gw.example.test/v1');

        $provider = base_provider::create_for_comparison('claude', 'claude-sonnet-5', $this->course->id);

        $this->assertSame('sk-ant-PREMIUM-ROW-3333', $this->key_of($provider));
        $this->assertSame('https://row-gw.example.test', $this->prop_of($provider, 'baseurl'));
    }

    public function test_a_course_on_another_provider_is_unchanged_by_the_mirror_rule(): void {
        // The course holds a Claude key but the call asks for OpenAI, whose row mirrors the site key. The
        // course key belongs to another vendor, so the row serves as it always has.
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('apikey', 'sk-proj-SITE-KEY-1111', 'local_ai_course_assistant');
        set_config('comparison_providers', "openai|sk-proj-SITE-KEY-1111|gpt-4o-mini", 'local_ai_course_assistant');
        $this->course_with('claude', 'sk-ant-COURSE-KEY-2222');

        $provider = base_provider::create_for_comparison('openai', 'gpt-4o-mini', $this->course->id);

        $this->assertSame('sk-proj-SITE-KEY-1111', $this->key_of($provider));
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

    public function test_a_row_whose_key_cannot_be_decrypted_never_gets_the_inherited_key(): void {
        // The row exists and says it has a key, but the ciphertext can't be read (a database restored onto
        // another site). It must count as no row at all: using it would build an OpenAI provider and send
        // it the Anthropic key the course inherits.
        set_config('provider', 'claude', 'local_ai_course_assistant');
        set_config('apikey', 'sk-ant-SITE-KEY', 'local_ai_course_assistant');
        set_config('comparison_providers',
            "openai|sodium:AAAAnotarealciphertextAAAAAAAAAAAAAAAAAAAAAAAA|gpt-4o-mini",
            'local_ai_course_assistant');

        try {
            base_provider::create_for_comparison('openai', 'gpt-4o-mini', $this->course->id);
            $this->fail('Expected a refusal rather than sending an Anthropic key to OpenAI.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('openai', $e->debuginfo);
            $this->assertStringContainsString('Comparison providers', $e->debuginfo);
        }
        // secrets::reveal() raises one developer notice for the ciphertext it can't read.
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
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
