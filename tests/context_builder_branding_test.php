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

/**
 * Tests that the v6.8.0 brand-token system reaches the generated system
 * prompt: every [[token]] resolves from the four admin settings and no raw
 * token ever leaks into what the LLM receives.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\context_builder
 */
final class context_builder_branding_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    public function test_system_prompt_resolves_brand_tokens_and_never_leaks(): void {
        $course = $this->getDataGenerator()->create_course();

        // Rebrand to a distinctive name no default text would contain.
        set_config('short_name', 'ZBRANDX', 'local_ai_course_assistant');
        set_config('display_name', 'Zeta Brand Tutor', 'local_ai_course_assistant');
        set_config('institution_name', 'Zeta University', 'local_ai_course_assistant');

        $prompt = context_builder::build_system_prompt(
            (int) $course->id, (int) get_admin()->id, 'en', [], 0, '', '');

        $this->assertStringContainsString('ZBRANDX', $prompt,
            'The configured short name must appear in the prompt.');
        $this->assertStringContainsString('Zeta University', $prompt);
        $this->assertDoesNotMatchRegularExpression('/\[\[(tutorname|tutorshort|uniname|unishort)\]\]/',
            $prompt, 'No brand token may leak into the system prompt.');
        // The literal default brand must not survive a rebrand.
        $this->assertStringNotContainsString('You are SOLA', $prompt);
    }

    public function test_system_prompt_defaults_to_sola_branding(): void {
        $course = $this->getDataGenerator()->create_course();
        // With no branding config, fallbacks apply -> the prompt is SOLA-branded.
        $prompt = context_builder::build_system_prompt(
            (int) $course->id, (int) get_admin()->id, 'en', [], 0, '', '');

        $this->assertStringContainsString('SOLA', $prompt);
        $this->assertDoesNotMatchRegularExpression('/\[\[(tutorname|tutorshort|uniname|unishort)\]\]/',
            $prompt);
    }
}
