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
 * Tests for branding (v5.3.22).
 *
 * branding resolves the institution / display / short / contact
 * strings used across ~20 admin pages, every email template, and the
 * chat drawer header. A regression in the fallback chain (admin
 * setting → lang default) would render generic placeholders in
 * admin-customised installs.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\branding
 */
final class branding_test extends \advanced_testcase {

    public function test_short_name_returns_admin_setting_when_set(): void {
        $this->resetAfterTest();
        set_config('short_name', 'CHAT', 'local_ai_course_assistant');

        $this->assertEquals('CHAT', branding::short_name());
    }

    public function test_short_name_falls_back_when_admin_setting_blank(): void {
        $this->resetAfterTest();
        set_config('short_name', '', 'local_ai_course_assistant');

        $result = branding::short_name();

        $this->assertNotEmpty($result, 'short_name must always return a non-empty string.');
    }

    public function test_display_name_returns_admin_setting_when_set(): void {
        $this->resetAfterTest();
        set_config('display_name', 'My Custom Assistant', 'local_ai_course_assistant');

        $this->assertEquals('My Custom Assistant', branding::display_name());
    }

    public function test_display_name_falls_back_when_admin_setting_blank(): void {
        $this->resetAfterTest();
        set_config('display_name', '', 'local_ai_course_assistant');

        $this->assertNotEmpty(branding::display_name());
    }

    public function test_institution_name_returns_admin_setting(): void {
        $this->resetAfterTest();
        set_config('institution_name', 'Acme University', 'local_ai_course_assistant');

        $this->assertEquals('Acme University', branding::institution_name());
    }

    public function test_institution_short_name_returns_admin_setting(): void {
        $this->resetAfterTest();
        set_config('institution_short_name', 'Acme', 'local_ai_course_assistant');

        $this->assertEquals('Acme', branding::institution_short_name());
    }

    public function test_contact_email_returns_admin_setting_when_valid(): void {
        $this->resetAfterTest();
        set_config('contact_email', 'support@example.com', 'local_ai_course_assistant');

        $this->assertEquals('support@example.com', branding::contact_email());
    }

    public function test_dpo_email_returns_admin_setting_when_valid(): void {
        $this->resetAfterTest();
        set_config('dpo_email', 'dpo@example.com', 'local_ai_course_assistant');

        $this->assertEquals('dpo@example.com', branding::dpo_email());
    }

    public function test_filename_slug_is_safe_for_filenames(): void {
        // filename_slug() is used to build downloadable filenames in
        // settings_user.php. Must not contain spaces, slashes, or
        // characters that would corrupt a Content-Disposition header.
        $this->resetAfterTest();
        set_config('display_name', 'My Tutor with spaces / & special chars',
            'local_ai_course_assistant');

        $slug = branding::filename_slug();

        $this->assertNotEmpty($slug);
        $this->assertDoesNotMatchRegularExpression('#[\s/\\\\\'"]#', $slug,
            'filename_slug must not contain spaces, slashes, or quotes.');
    }

    public function test_privacy_external_url_returns_setting_when_url(): void {
        $this->resetAfterTest();
        set_config('privacy_external_url', 'https://example.com/privacy',
            'local_ai_course_assistant');

        $this->assertEquals('https://example.com/privacy',
            branding::privacy_external_url());
    }

    public function test_all_methods_return_strings_under_blank_config(): void {
        // Defensive smoke: clear EVERY branding-related admin setting and
        // confirm every method still returns a non-null string. Regression
        // protection against any future branding field with a missing
        // null-safe fallback.
        $this->resetAfterTest();
        foreach (['short_name', 'display_name', 'institution_name',
                'institution_short_name', 'contact_email', 'dpo_email',
                'privacy_external_url'] as $key) {
            set_config($key, '', 'local_ai_course_assistant');
        }

        $this->assertIsString(branding::short_name());
        $this->assertIsString(branding::display_name());
        $this->assertIsString(branding::institution_name());
        $this->assertIsString(branding::institution_short_name());
        $this->assertIsString(branding::contact_email());
        $this->assertIsString(branding::dpo_email());
        $this->assertIsString(branding::privacy_external_url());
        $this->assertIsString(branding::filename_slug());
    }

    // ------------------------------------------------------------------
    // Brand-token substitution (v6.8.0): lang strings, the system prompt,
    // emails, and admin copy embed [[tutorname]]/[[tutorshort]]/[[uniname]]/
    // [[unishort]] tokens that resolve from the four admin settings, so the
    // whole product rebrands without code or string-file edits.
    // ------------------------------------------------------------------

    public function test_token_map_has_the_four_brand_tokens(): void {
        $this->resetAfterTest();
        $map = branding::token_map();
        $this->assertEqualsCanonicalizing(
            ['tutorname', 'tutorshort', 'uniname', 'unishort'], array_keys($map));
    }

    public function test_apply_resolves_every_token_from_config(): void {
        $this->resetAfterTest();
        set_config('display_name', 'Acme Study Buddy', 'local_ai_course_assistant');
        set_config('short_name', 'ASB', 'local_ai_course_assistant');
        set_config('institution_name', 'Acme University', 'local_ai_course_assistant');
        set_config('institution_short_name', 'Acme', 'local_ai_course_assistant');

        $out = branding::apply('[[tutorshort]] by [[tutorname]] at [[uniname]] ([[unishort]])');

        $this->assertEquals('ASB by Acme Study Buddy at Acme University (Acme)', $out);
        $this->assertStringNotContainsString('[[', $out);
    }

    public function test_apply_uses_defaults_when_unconfigured(): void {
        $this->resetAfterTest();
        $this->assertEquals('SOLA', branding::apply('[[tutorshort]]'));
        $this->assertEquals('Saylor Online Learning Assistant', branding::apply('[[tutorname]]'));
        $this->assertEquals('Saylor University', branding::apply('[[uniname]]'));
        $this->assertEquals('Saylor', branding::apply('[[unishort]]'));
    }

    public function test_apply_is_noop_and_null_safe(): void {
        $this->resetAfterTest();
        $this->assertEquals('', branding::apply(null));
        $this->assertEquals('', branding::apply(''));
        $this->assertEquals('no tokens here', branding::apply('no tokens here'));
        // An unknown token is left untouched (only the four are resolved).
        $this->assertEquals('[[unknown]]', branding::apply('[[unknown]]'));
    }

    public function test_apply_handles_repeated_tokens(): void {
        $this->resetAfterTest();
        set_config('short_name', 'ASB', 'local_ai_course_assistant');
        $this->assertEquals('ASB and ASB', branding::apply('[[tutorshort]] and [[tutorshort]]'));
    }

    public function test_str_resolves_a_tokenized_lang_string(): void {
        $this->resetAfterTest();
        set_config('short_name', 'ASB', 'local_ai_course_assistant');
        // chat:open is "Open [[tutorshort]]" in the tokenized lang file.
        $this->assertEquals('Open ASB', branding::str('chat:open'));
    }

    public function test_token_map_json_is_valid_json(): void {
        $this->resetAfterTest();
        $decoded = json_decode(branding::token_map_json(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('tutorshort', $decoded);
    }

    public function test_no_english_lang_string_leaks_a_token_after_apply(): void {
        // The strong guarantee: every brand token that appears in any English
        // lang string is one that apply() knows how to resolve. If a future
        // string introduces an unknown [[token]], apply() leaves it and this
        // test fails — catching the leak before it reaches a user.
        $this->resetAfterTest();
        $string = [];
        include(__DIR__ . '/../lang/en/local_ai_course_assistant.php');
        $leaks = [];
        foreach ($string as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            $resolved = branding::apply($value);
            if (strpos($resolved, '[[') !== false
                    && preg_match('/\[\[(tutorname|tutorshort|uniname|unishort)\]\]/', $resolved)) {
                $leaks[] = $key;
            }
        }
        $this->assertSame([], $leaks,
            'These lang keys still hold an unresolved brand token after apply(): '
            . implode(', ', $leaks));
    }
}
