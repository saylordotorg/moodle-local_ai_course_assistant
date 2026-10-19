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
        set_config(
            'policy_bundle_pubkey',
            base64_encode(sodium_crypto_sign_publickey($keypair)),
            'local_ai_course_assistant'
        );
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
        $this->assertSame(
            'derive|prove that',
            get_config('local_ai_course_assistant', 'premium_escalation_triggers')
        );
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
                'rerank_candidates' => 25, // Allowed.
                'apikey' => 'sk-evil', // NOT allowed.
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
        foreach (
            ['ssrf_trusted_endpoints', 'policy_bundle_url', 'policy_bundle_pubkey',
                'stt_selfhosted_url', 'rerank_apikey', 'spend_notify_emails'] as $forbidden
        ) {
            $this->assertNotContains(
                $forbidden,
                policy_bundle::ALLOWED_KEYS,
                "{$forbidden} must never be settable by a bundle"
            );
        }
    }

    public function test_every_allowlisted_key_is_a_registered_setting(): void {
        // failover_timeout_voice sat on this list for four minor versions while
        // nothing read it and settings.php had since stopped being its only
        // home. An allowlisted key that no longer exists is worse than a missing
        // one: policy_bundle_tool --sign accepts it, the bundle applies it, the
        // audit row records a change, and nothing anywhere consumes the value.
        global $CFG;
        $src = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/settings.php');
        $this->assertNotFalse($src, 'settings.php must be readable for this guard to mean anything.');

        preg_match_all("/'local_ai_course_assistant\/([a-z0-9_]+)'/", $src, $m);
        $registered = array_flip($m[1]);
        $this->assertNotEmpty(
            $registered,
            'Parsed zero settings from settings.php — the guard has broken, not passed.'
        );

        $orphans = [];
        foreach (policy_bundle::ALLOWED_KEYS as $key) {
            if (!isset($registered[$key])) {
                $orphans[] = $key;
            }
        }
        $this->assertSame(
            [],
            $orphans,
            'Allowlisted but not registered in settings.php: ' . implode(', ', $orphans)
        );
    }

    // 7.12.0: prompt_budget_auto_tune is retired. A bundle already signed with it must keep working.

    public function test_the_retired_list_names_the_key_and_the_allowlist_does_not(): void {
        $this->assertSame(['prompt_budget_auto_tune'], policy_bundle::RETIRED_KEYS);
        $this->assertNotContains('prompt_budget_auto_tune', policy_bundle::ALLOWED_KEYS);
        $this->assertSame(
            [],
            array_values(array_intersect(policy_bundle::RETIRED_KEYS, policy_bundle::ALLOWED_KEYS)),
            'A key is either settable or retired, never both.'
        );
    }

    public function test_a_retired_key_is_not_a_registered_setting(): void {
        // Retired means nothing reads it. If settings.php ever registers one again, it is a setting and belongs on
        // ALLOWED_KEYS (or nowhere), not on a list whose whole point is that the key does nothing.
        global $CFG;
        $src = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/settings.php');
        $this->assertNotFalse($src);
        preg_match_all("/'local_ai_course_assistant\/([a-z0-9_]+)'/", $src, $m);
        $this->assertNotEmpty($m[1], 'Parsed zero settings from settings.php: the guard has broken, not passed.');
        $this->assertSame([], array_values(array_intersect(policy_bundle::RETIRED_KEYS, $m[1])));
    }

    public function test_a_bundle_with_a_retired_key_applies_its_other_keys_and_never_writes_the_retired_one(): void {
        global $DB;
        // A database installed before 7.12.0 stores the setting's default.
        unset_config('prompt_budget_auto_tune', 'local_ai_course_assistant');
        $result = policy_bundle::process_envelope($this->envelope([
            'version' => 2,
            'settings' => ['rerank_candidates' => 25, 'prompt_budget_auto_tune' => 1],
        ]));
        $this->assertSame('applied', $result['status'], $result['detail']);
        $this->assertSame('25', get_config('local_ai_course_assistant', 'rerank_candidates'));
        $this->assertFalse(get_config('local_ai_course_assistant', 'prompt_budget_auto_tune'), 'a retired key is never written');
        $this->assertSame('2', get_config('local_ai_course_assistant', 'policy_bundle_applied_version'));
        $this->assertStringContainsString('1 setting(s) changed', $result['detail']);

        $rows = $DB->get_records('local_ai_course_assistant_audit', ['action' => 'policy_bundle_applied'], 'id DESC', '*', 0, 1);
        $details = (string) reset($rows)->details;
        $this->assertStringContainsString('rerank_candidates', $details);
        $this->assertStringNotContainsString('prompt_budget_auto_tune', $details, 'a retired key is not reported as changed');
    }

    public function test_a_retired_key_is_not_one_of_the_keys_the_bundle_manages(): void {
        policy_bundle::process_envelope($this->envelope([
            'version' => 2,
            'settings' => ['rerank_candidates' => 25, 'prompt_budget_auto_tune' => 1],
        ]));
        $this->assertSame(['rerank_candidates'], policy_bundle::managed_keys());
        $this->assertSame(['rerank_candidates'], autoupgrade\switcher::managed_keys());
    }

    public function test_a_retired_key_never_overwrites_a_value_the_site_still_holds(): void {
        // A site that has not run the 7.12.0 upgrade step yet (the sync task can run before it) still stores the flag.
        set_config('prompt_budget_auto_tune', '1', 'local_ai_course_assistant');
        $result = policy_bundle::process_envelope($this->envelope([
            'version' => 2,
            'settings' => ['prompt_budget_auto_tune' => 0, 'rerank_candidates' => 25],
        ]));
        $this->assertSame('applied', $result['status'], $result['detail']);
        $this->assertSame('1', get_config('local_ai_course_assistant', 'prompt_budget_auto_tune'));
    }

    public function test_a_bundle_that_carries_only_a_retired_key_is_accepted_and_changes_nothing(): void {
        unset_config('prompt_budget_auto_tune', 'local_ai_course_assistant');
        $result = policy_bundle::process_envelope($this->envelope([
            'version' => 4,
            'settings' => ['prompt_budget_auto_tune' => 0],
        ]));
        $this->assertSame('applied', $result['status'], $result['detail']);
        $this->assertStringContainsString('0 setting(s) changed', $result['detail']);
        $this->assertSame('4', get_config('local_ai_course_assistant', 'policy_bundle_applied_version'));
        $this->assertFalse(get_config('local_ai_course_assistant', 'prompt_budget_auto_tune'));
        $this->assertSame([], policy_bundle::managed_keys());
    }

    public function test_a_retired_key_does_not_excuse_an_unknown_one(): void {
        set_config('rerank_candidates', '42', 'local_ai_course_assistant');
        $result = policy_bundle::process_envelope($this->envelope([
            'version' => 2,
            'settings' => ['prompt_budget_auto_tune' => 1, 'rerank_candidates' => 25, 'apikey' => 'sk-evil'],
        ]));
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('apikey', $result['detail']);
        $this->assertSame('42', get_config('local_ai_course_assistant', 'rerank_candidates'), 'rejected in full');
    }

    public function test_a_retired_key_still_has_to_carry_a_plain_value(): void {
        $result = policy_bundle::process_envelope($this->envelope([
            'version' => 2,
            'settings' => ['prompt_budget_auto_tune' => ['an', 'array'], 'rerank_candidates' => 25],
        ]));
        $this->assertSame('error', $result['status']);
        $this->assertNotSame('25', get_config('local_ai_course_assistant', 'rerank_candidates'));
    }

    public function test_verify_output_labels_a_retired_key_and_nothing_else(): void {
        $this->assertSame(
            "prompt_budget_auto_tune = 1  (retired, ignored)",
            policy_bundle::describe_setting('prompt_budget_auto_tune', 1)
        );
        $this->assertSame('rerank_candidates = 25', policy_bundle::describe_setting('rerank_candidates', 25));
        $this->assertSame("temperature = '0.4'", policy_bundle::describe_setting('temperature', '0.4'));
        $tool = file_get_contents(__DIR__ . '/../admin/cli/policy_bundle_tool.php');
        $this->assertStringContainsString('policy_bundle::describe_setting(', $tool, '--verify prints through the helper');
    }

    public function test_the_model_switcher_still_may_not_write_a_retired_key(): void {
        $blocked = autoupgrade\switcher::blocker('chat', ['prompt_budget_auto_tune' => '1']);
        $this->assertSame(
            get_string('autoupgrade:block_allowlist', 'local_ai_course_assistant', 'prompt_budget_auto_tune'),
            $blocked
        );
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
        $this->assertStringContainsString(
            'skipped',
            (string) get_config('local_ai_course_assistant', 'policy_bundle_last_result')
        );
    }

    public function test_unchanged_values_do_not_count_as_changes(): void {
        set_config('rerank_candidates', '30', 'local_ai_course_assistant');
        $result = policy_bundle::process_envelope($this->envelope([
            'version' => 1, 'settings' => ['rerank_candidates' => 30],
        ]));
        $this->assertSame('applied', $result['status']);
        $this->assertStringContainsString('0 setting(s) changed', $result['detail']);
    }

    // Fix wave 3, F3.5: the bundle is the second writer of the settings that decide which row's key apikey mirrors.

    /**
     * Two providers with a key each, apikey mirroring the first.
     *
     * @param string $provider the site provider
     * @param string $apikey what apikey holds now (plain; stored concealed)
     */
    private function two_providers(string $provider, string $apikey): void {
        set_config('provider', $provider, 'local_ai_course_assistant');
        set_config('comparison_providers', "openai|sk-proj-BUNDLE-OPENAI-1111-AAAA|gpt-4o-mini\n"
            . "gemini|AIzaBUNDLE-GEMINI-2222-BBBB|gemini-2.5-flash", 'local_ai_course_assistant');
        set_config('apikey', secrets::conceal($apikey), 'local_ai_course_assistant');
    }

    public function test_a_bundle_that_changes_the_provider_re_mirrors_the_default_key(): void {
        $this->two_providers('openai', 'sk-proj-BUNDLE-OPENAI-1111-AAAA');
        $result = policy_bundle::process_envelope($this->envelope([
            'version' => 1, 'settings' => ['provider' => 'gemini'],
        ]));
        $this->assertSame('applied', $result['status']);
        $this->assertSame('gemini', get_config('local_ai_course_assistant', 'provider'));
        $this->assertSame('AIzaBUNDLE-GEMINI-2222-BBBB', secrets::get('apikey'));
        $this->assertTrue(secrets::is_encrypted(get_config('local_ai_course_assistant', 'apikey')));
    }

    public function test_a_bundle_that_changes_the_model_re_mirrors_when_the_provider_follows_the_model(): void {
        $this->two_providers('auto', 'sk-proj-BUNDLE-OPENAI-1111-AAAA');
        set_config('model', 'gpt-4o-mini', 'local_ai_course_assistant');
        $this->assertSame('openai', provider_keys::site_provider());
        $result = policy_bundle::process_envelope($this->envelope([
            'version' => 1, 'settings' => ['model' => 'gemini-2.5-flash'],
        ]));
        $this->assertSame('applied', $result['status']);
        $this->assertSame('gemini', provider_keys::site_provider());
        $this->assertSame('AIzaBUNDLE-GEMINI-2222-BBBB', secrets::get('apikey'));
    }

    public function test_a_bundle_that_changes_nothing_the_mirror_reads_leaves_apikey_alone(): void {
        $this->two_providers('gemini', 'AIzaSTALE-GEMINI-KEY-OLD-0000');
        $result = policy_bundle::process_envelope($this->envelope([
            'version' => 1, 'settings' => ['rerank_candidates' => 25, 'temperature' => '0.4'],
        ]));
        $this->assertSame('applied', $result['status']);
        $this->assertSame('AIzaSTALE-GEMINI-KEY-OLD-0000', secrets::get('apikey'), 'no provider, model or URL written: no sync');
    }

    public function test_a_bundle_whose_provider_is_already_that_value_writes_nothing_and_syncs_nothing(): void {
        $this->two_providers('gemini', 'AIzaSTALE-GEMINI-KEY-OLD-0000');
        $result = policy_bundle::process_envelope($this->envelope([
            'version' => 1, 'settings' => ['provider' => 'gemini'],
        ]));
        $this->assertSame('applied', $result['status']);
        $this->assertStringContainsString('0 setting(s) changed', $result['detail']);
        $this->assertSame('AIzaSTALE-GEMINI-KEY-OLD-0000', secrets::get('apikey'));
    }

    public function test_a_bundle_that_is_not_newer_is_not_applied_and_syncs_nothing(): void {
        $this->two_providers('openai', 'sk-proj-BUNDLE-OPENAI-1111-AAAA');
        policy_bundle::process_envelope($this->envelope(['version' => 5, 'settings' => ['rerank_candidates' => 25]]));
        $result = policy_bundle::process_envelope($this->envelope([
            'version' => 4, 'settings' => ['provider' => 'gemini'],
        ]));
        $this->assertSame('skipped', $result['status']);
        $this->assertSame('openai', get_config('local_ai_course_assistant', 'provider'));
        $this->assertSame('sk-proj-BUNDLE-OPENAI-1111-AAAA', secrets::get('apikey'));
    }

    public function test_a_bundle_raises_no_notice_when_it_re_mirrors(): void {
        $this->two_providers('openai', 'sk-proj-BUNDLE-OPENAI-1111-AAAA');
        policy_bundle::process_envelope($this->envelope(['version' => 1, 'settings' => ['provider' => 'gemini']]));
        $this->assertSame([], \core\notification::fetch());
    }

    /** F3 round 1: a sync that throws (core encryption has no usable key) must not undo or abort an applied bundle. */
    public function test_a_bundle_still_applies_and_is_audited_when_the_mirror_cannot_be_written(): void {
        global $CFG, $DB;
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('comparison_providers', "openai|sk-proj-BUNDLE-OPENAI-1111-AAAA|gpt-4o-mini\n"
            . "gemini|AIzaBUNDLE-GEMINI-2222-BBBB|gemini-2.5-flash", 'local_ai_course_assistant');
        set_config('apikey', 'sk-proj-BUNDLE-OPENAI-1111-AAAA', 'local_ai_course_assistant');
        $audits = $DB->count_records('local_ai_course_assistant_audit', ['action' => 'policy_bundle_applied']);
        $CFG->secretdataroot = $CFG->dataroot . '/no-such-secret-dir-for-the-bundle-test';
        $CFG->nokeygeneration = true;
        $result = policy_bundle::process_envelope($this->envelope([
            'version' => 7, 'settings' => ['provider' => 'gemini', 'rerank_candidates' => 25],
        ]));
        $this->assertSame('applied', $result['status'], $result['detail']);
        $this->assertSame('gemini', get_config('local_ai_course_assistant', 'provider'));
        $this->assertSame('25', get_config('local_ai_course_assistant', 'rerank_candidates'));
        $this->assertSame('7', get_config('local_ai_course_assistant', 'policy_bundle_applied_version'));
        $this->assertStringStartsWith('applied', (string) get_config('local_ai_course_assistant', 'policy_bundle_last_result'));
        $this->assertSame($audits + 1, $DB->count_records('local_ai_course_assistant_audit', ['action' => 'policy_bundle_applied']));
        $this->assertSame('sk-proj-BUNDLE-OPENAI-1111-AAAA', get_config('local_ai_course_assistant', 'apikey'), 'not re-mirrored');
        $messages = $this->getDebuggingMessages();
        $this->assertCount(1, $messages);
        $this->assertStringNotContainsString('sk-proj', $messages[0]->message);
        $this->assertStringNotContainsString('AIza', $messages[0]->message);
        $this->resetDebugging();
    }
}
