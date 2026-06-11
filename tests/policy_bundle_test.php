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
 * Tests for the v6.4.0 signed policy bundle (Ed25519 verification, settings
 * allowlist, monotonic version, audit logging). All tests go through the
 * network-free seam policy_bundle::process_envelope().
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\policy_bundle
 */
final class policy_bundle_test extends \advanced_testcase {

    /** @var string Base64 Ed25519 secret key for this test run. */
    private string $secret;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $keypair = sodium_crypto_sign_keypair();
        $this->secret = base64_encode(sodium_crypto_sign_secretkey($keypair));
        set_config('policy_bundle_pubkey',
            base64_encode(sodium_crypto_sign_publickey($keypair)), 'local_ai_course_assistant');
        set_config('policy_bundle_enabled', '1', 'local_ai_course_assistant');
    }

    /**
     * Build a signed envelope around a payload array.
     *
     * @param array $payload Payload to sign.
     * @param string|null $secretb64 Override signing key (defaults to the test key).
     * @return string Envelope JSON.
     */
    private function envelope(array $payload, ?string $secretb64 = null): string {
        $secret = base64_decode($secretb64 ?? $this->secret);
        $bytes = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig = sodium_crypto_sign_detached($bytes, $secret);
        return json_encode([
            'format'    => policy_bundle::FORMAT,
            'payload'   => base64_encode($bytes),
            'signature' => base64_encode($sig),
        ]);
    }

    public function test_valid_bundle_applies_settings_and_version(): void {
        $json = $this->envelope([
            'version' => 3,
            'issued_at' => '2026-06-11T00:00:00Z',
            'settings' => [
                'premium_escalation_triggers' => 'derive|prove that',
                'rerank_candidates' => 30,
                'cost_anomaly_enabled' => true,
            ],
        ]);
        $result = policy_bundle::process_envelope($json);
        $this->assertSame('applied', $result['status']);
        $this->assertSame('derive|prove that',
            get_config('local_ai_course_assistant', 'premium_escalation_triggers'));
        $this->assertSame('30', get_config('local_ai_course_assistant', 'rerank_candidates'));
        $this->assertSame('1', get_config('local_ai_course_assistant', 'cost_anomaly_enabled'));
        $this->assertSame('3', get_config('local_ai_course_assistant', 'policy_bundle_applied_version'));
    }

    public function test_application_writes_audit_row(): void {
        global $DB;
        $before = $DB->count_records('local_ai_course_assistant_audit');
        policy_bundle::process_envelope($this->envelope([
            'version' => 1, 'settings' => ['rerank_candidates' => 25],
        ]));
        $this->assertSame($before + 1, $DB->count_records('local_ai_course_assistant_audit'));
        $rows = $DB->get_records('local_ai_course_assistant_audit', null, 'id DESC', '*', 0, 1);
        $row = reset($rows);
        $this->assertSame('policy_bundle_applied', $row->action);
        $this->assertStringContainsString('rerank_candidates', $row->details);
    }

    public function test_bad_signature_rejected(): void {
        set_config('rerank_candidates', '42', 'local_ai_course_assistant');
        $other = sodium_crypto_sign_keypair();
        $json = $this->envelope(
            ['version' => 1, 'settings' => ['rerank_candidates' => 25]],
            base64_encode(sodium_crypto_sign_secretkey($other))
        );
        $result = policy_bundle::process_envelope($json);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('signature', $result['detail']);
        $this->assertSame('42', get_config('local_ai_course_assistant', 'rerank_candidates'));
    }

    public function test_tampered_payload_rejected(): void {
        set_config('rerank_candidates', '42', 'local_ai_course_assistant');
        $json = $this->envelope(['version' => 1, 'settings' => ['rerank_candidates' => 25]]);
        $envelope = json_decode($json, true);
        $payload = json_decode(base64_decode($envelope['payload']), true);
        $payload['settings']['rerank_candidates'] = 999;
        $envelope['payload'] = base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $result = policy_bundle::process_envelope(json_encode($envelope));
        $this->assertSame('error', $result['status']);
        $this->assertSame('42', get_config('local_ai_course_assistant', 'rerank_candidates'));
    }

    public function test_disallowed_key_rejects_whole_bundle(): void {
        set_config('rerank_candidates', '42', 'local_ai_course_assistant');
        $json = $this->envelope([
            'version' => 1,
            'settings' => [
                'rerank_candidates' => 25,          // Allowed.
                'apikey' => 'sk-evil',              // NOT allowed.
            ],
        ]);
        $result = policy_bundle::process_envelope($json);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('allowlist', $result['detail']);
        // Fail closed: the allowed key must not have been applied either.
        $this->assertSame('42', get_config('local_ai_course_assistant', 'rerank_candidates'));
        $this->assertNotSame('sk-evil', (string) get_config('local_ai_course_assistant', 'apikey'));
    }

    public function test_ssrf_and_url_settings_not_on_allowlist(): void {
        foreach (['ssrf_trusted_endpoints', 'policy_bundle_url', 'policy_bundle_pubkey',
                'stt_selfhosted_url', 'rerank_apikey', 'spend_notify_emails'] as $forbidden) {
            $this->assertNotContains($forbidden, policy_bundle::ALLOWED_KEYS,
                "{$forbidden} must never be settable by a bundle");
        }
    }

    public function test_older_or_equal_version_skipped(): void {
        policy_bundle::process_envelope($this->envelope([
            'version' => 5, 'settings' => ['rerank_candidates' => 30],
        ]));
        $result = policy_bundle::process_envelope($this->envelope([
            'version' => 5, 'settings' => ['rerank_candidates' => 99],
        ]));
        $this->assertSame('skipped', $result['status']);
        $this->assertSame('30', get_config('local_ai_course_assistant', 'rerank_candidates'));

        $result = policy_bundle::process_envelope($this->envelope([
            'version' => 4, 'settings' => ['rerank_candidates' => 99],
        ]));
        $this->assertSame('skipped', $result['status']);
        $this->assertSame('30', get_config('local_ai_course_assistant', 'rerank_candidates'));
    }

    public function test_wrong_format_rejected(): void {
        $json = $this->envelope(['version' => 1, 'settings' => ['rerank_candidates' => 25]]);
        $envelope = json_decode($json, true);
        $envelope['format'] = 'something-else';
        $result = policy_bundle::process_envelope(json_encode($envelope));
        $this->assertSame('error', $result['status']);
    }

    public function test_non_scalar_value_rejected(): void {
        $json = $this->envelope([
            'version' => 1,
            'settings' => ['premium_escalation_triggers' => ['an', 'array']],
        ]);
        $result = policy_bundle::process_envelope($json);
        $this->assertSame('error', $result['status']);
    }

    public function test_missing_version_rejected(): void {
        $json = $this->envelope(['settings' => ['rerank_candidates' => 25]]);
        $result = policy_bundle::process_envelope($json);
        $this->assertSame('error', $result['status']);
    }

    public function test_oversized_envelope_rejected(): void {
        $json = $this->envelope([
            'version' => 1,
            'comment' => str_repeat('x', policy_bundle::MAX_BUNDLE_BYTES),
            'settings' => ['rerank_candidates' => 25],
        ]);
        $result = policy_bundle::process_envelope($json);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('bytes', $result['detail']);
    }

    public function test_sync_disabled_skips(): void {
        set_config('policy_bundle_enabled', '0', 'local_ai_course_assistant');
        $result = policy_bundle::sync();
        $this->assertSame('skipped', $result['status']);
        $this->assertStringContainsString('disabled', $result['detail']);
    }

    public function test_sync_records_status_config(): void {
        set_config('policy_bundle_enabled', '0', 'local_ai_course_assistant');
        policy_bundle::sync();
        $this->assertNotEmpty(get_config('local_ai_course_assistant', 'policy_bundle_last_sync'));
        $this->assertStringContainsString('skipped',
            (string) get_config('local_ai_course_assistant', 'policy_bundle_last_result'));
    }

    public function test_unchanged_values_do_not_count_as_changes(): void {
        set_config('rerank_candidates', '30', 'local_ai_course_assistant');
        $result = policy_bundle::process_envelope($this->envelope([
            'version' => 1, 'settings' => ['rerank_candidates' => 30],
        ]));
        $this->assertSame('applied', $result['status']);
        $this->assertStringContainsString('0 setting(s) changed', $result['detail']);
    }
}
