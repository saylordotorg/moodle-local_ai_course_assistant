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
 * Tests for the DNS-rebinding pin (security::resolve_pin_options), which closes
 * the time-of-check/time-of-use window between is_safe_provider_url() and the
 * outbound connection by forcing curl to the validated IP.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\security::resolve_pin_options
 */
final class ssrf_pin_test extends \advanced_testcase {

    public function test_literal_ip_is_not_pinned(): void {
        $this->resetAfterTest();
        // A literal IP cannot be rebound, so no pin is emitted.
        $this->assertSame([], security::resolve_pin_options('https://203.0.113.10/v1/chat'));
    }

    public function test_proxy_disables_pin(): void {
        global $CFG;
        $this->resetAfterTest();
        // Under a Moodle proxy the proxy resolves the host; no client pin applies.
        $CFG->proxyhost = 'proxy.example.org';
        $this->assertSame([], security::resolve_pin_options('https://api.example.com/v1/chat'));
    }

    public function test_trusted_host_is_not_pinned(): void {
        $this->resetAfterTest();
        // An admin-allowlisted self-hosted endpoint may legitimately resolve to
        // a private address; it is trusted, so neither pinned nor rejected.
        set_config('ssrf_trusted_endpoints', "http://ollama.internal:11434",
            'local_ai_course_assistant');
        $this->assertSame([], security::resolve_pin_options('http://ollama.internal:11434/api/generate'));
    }

    public function test_host_resolving_to_loopback_is_rejected(): void {
        $this->resetAfterTest();
        // localhost resolves to 127.0.0.1 (reserved) via the local hosts file,
        // with no network DNS: the rebind guard must refuse to connect.
        $this->expectException(\moodle_exception::class);
        security::resolve_pin_options('https://localhost/v1/chat');
    }

    public function test_resolve_pin_options_shape_for_public_host(): void {
        $this->resetAfterTest();
        // example.com is an IANA-reserved documentation domain that resolves to
        // a stable public address. Assert the option shape, not a specific IP.
        $opts = security::resolve_pin_options('https://example.com/v1/chat');
        $this->assertArrayHasKey('CURLOPT_RESOLVE', $opts);
        $this->assertCount(1, $opts['CURLOPT_RESOLVE']);
        $this->assertMatchesRegularExpression(
            '/^example\.com:443:\d{1,3}(\.\d{1,3}){3}$/', $opts['CURLOPT_RESOLVE'][0]);
    }
}
