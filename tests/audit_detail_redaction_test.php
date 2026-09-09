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
 * v7.4.2: provider debuginfo is redacted before it is persisted.
 *
 * sse.php records $e->debuginfo into the audit table ungated, so that a
 * production site running with debug OFF can still diagnose a chat failure.
 * That is the right trade for observability, but debuginfo is raw vendor
 * output: base_provider throws "HTTP {code}: {body}" with the unparsed error
 * body, embeds $curl->error, and embeds the full endpoint URL when the SSRF
 * validator rejects one.
 *
 * The audit table is declared in privacy/provider.php, displayed by
 * audit_log.php and included in the learner's own data export, so a credential
 * written into it is a credential disclosed. Truncating to 500 characters is
 * not a control: the front of an error string is exactly where the key is.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\security::redact_secrets
 */
final class audit_detail_redaction_test extends \basic_testcase {

    /**
     * The SSRF rejection path puts a configured base URL in debuginfo, and an
     * admin may have embedded credentials in it.
     */
    public function test_url_userinfo_is_removed(): void {
        $raw = 'Provider endpoint rejected by SSRF validator: https://svc:s3cr3t-p4ssw0rd@proxy.internal/v1';
        $out = security::redact_secrets($raw);

        $this->assertStringNotContainsString('s3cr3t-p4ssw0rd', $out);
        $this->assertStringNotContainsString('svc:', $out);
        // Still diagnosable: the host is what an admin needs to see.
        $this->assertStringContainsString('proxy.internal', $out);
    }

    /**
     * Vendor error bodies routinely echo the Authorization header back.
     */
    public function test_bearer_and_vendor_keys_are_removed(): void {
        $cases = [
            'HTTP 401: {"error":{"message":"Incorrect API key provided: sk-proj-AbCdEf123456789xyz"}}'
                => 'sk-proj-AbCdEf123456789xyz',
            'HTTP 400: header Authorization: Bearer eyJhbGciOiJIUzI1NiJ9abcdef'
                => 'eyJhbGciOiJIUzI1NiJ9abcdef',
            'HTTP 403: API key not valid. key=AIzaSyD-ABCdefGHIjklMNOpqrs'
                => 'AIzaSyD-ABCdefGHIjklMNOpqrs',
            'GET https://generativelanguage.googleapis.com/v1/models?key=AIzaSyLEAKED12345678'
                => 'AIzaSyLEAKED12345678',
        ];
        foreach ($cases as $raw => $secret) {
            $out = security::redact_secrets($raw);
            $this->assertStringNotContainsString($secret, $out,
                'a credential survived redaction and would be written to the audit table: ' . $raw);
        }
    }

    /**
     * Redaction must not destroy the diagnostic value the audit row exists for.
     * The 2026-08 incident was diagnosed from exactly this kind of string.
     */
    public function test_ordinary_provider_errors_survive_intact(): void {
        $raw = 'HTTP 429: {"error":{"type":"rate_limit_error","message":"Your organization has '
             . 'exceeded its monthly spend limit"}}';
        $out = security::redact_secrets($raw);

        $this->assertStringContainsString('429', $out);
        $this->assertStringContainsString('rate_limit_error', $out);
        $this->assertStringContainsString('exceeded its monthly spend limit', $out);
    }

    /**
     * Both sse.php audit writers redact. The moodle_exception handler is the
     * one provider failures actually take; the Throwable handler has written
     * raw debuginfo since the 2026-08 remediation. Neither may be the
     * unredacted one.
     */
    public function test_both_sse_audit_writers_redact(): void {
        $src = file_get_contents(__DIR__ . '/../sse.php');

        // Every place a 'detail' key is built from debuginfo must pass through
        // the redactor before core_text::substr bounds it.
        $count = preg_match_all("/'detail'\\]?\\s*=\\s*\\\\core_text::substr\\(\\s*\\\\local_ai_course_assistant\\\\security::redact_secrets/", $src);
        $totaldetail = preg_match_all("/\\['detail'\\]\\s*=/", $src);

        $this->assertSame($totaldetail, $count,
            'an sse.php handler writes provider debuginfo into the audit table without redacting it');
        $this->assertGreaterThanOrEqual(2, $count, 'expected both sse.php audit writers to be covered');
    }
}
