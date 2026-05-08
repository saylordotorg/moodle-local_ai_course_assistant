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
}
