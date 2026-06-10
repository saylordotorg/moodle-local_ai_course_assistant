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
 * Unit tests for security::build_security_headers (v6.2.x).
 *
 * Guards the fix where the strict CSP broke Moodle's own YUI/requireJS on full
 * Moodle pages (flashcards, essay feedback, sandbox, instructor dashboard).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\security::build_security_headers
 */
final class security_headers_test extends \advanced_testcase {

    public function test_raw_endpoint_sends_strict_csp(): void {
        $h = security::build_security_headers(false);
        $this->assertArrayHasKey('Content-Security-Policy', $h);
        $csp = $h['Content-Security-Policy'];
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        // The raw CSP must NOT permit eval (defence-in-depth for data endpoints).
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_full_moodle_page_omits_csp(): void {
        $h = security::build_security_headers(true);
        // A full Moodle page must NOT receive the plugin CSP — it breaks YUI
        // (needs unsafe-eval) and blocks the MathJax CDN, crippling page JS.
        $this->assertArrayNotHasKey('Content-Security-Policy', $h);
    }

    public function test_hardening_headers_present_in_both_modes(): void {
        foreach ([true, false] as $fullpage) {
            $h = security::build_security_headers($fullpage);
            $this->assertSame('nosniff', $h['X-Content-Type-Options']);
            $this->assertSame('SAMEORIGIN', $h['X-Frame-Options']);
            $this->assertSame('same-origin', $h['Referrer-Policy']);
        }
    }
}
