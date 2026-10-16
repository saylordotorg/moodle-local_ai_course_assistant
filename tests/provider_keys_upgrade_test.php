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
 * The 7.9.0 upgrade changes where keys live, never which key a call uses.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider_keys
 */
final class provider_keys_upgrade_test extends \advanced_testcase {

    /** @var string A gateway base URL used by the gateway tests. */
    private const GATEWAY = 'https://gateway.example.test/v1';

    /**
     * A test that pretends to be a web request must not leave that on for the next one, however it ends.
     */
    protected function tearDown(): void {
        settings_save_report::assume_web(null);
        parent::tearDown();
    }

    private function cfg(string $name, string $value): void {
        set_config($name, $value, 'local_ai_course_assistant');
    }

    private function stored(): string {
        return (string) get_config('local_ai_course_assistant', 'comparison_providers');
    }

    /**
     * The value in the database, bypassing get_config() (which returns a value forced in config.php).
     *
     * @param string $name
     * @return string '' when the row is absent
     */
    private function dbvalue(string $name): string {
        global $DB;
        return (string) ($DB->get_field('config_plugins', 'value',
            ['plugin' => 'local_ai_course_assistant', 'name' => $name]) ?: '');
    }

    /**
     * The one-time notice as stored: structured data, never English text.
     *
     * @return array|null null when there is none
     */
    private function notice(): ?array {
        $raw = get_config('local_ai_course_assistant', 'provider_keys_notice');
        return $raw === false ? null : json_decode((string) $raw, true);
    }

    /**
     * The displaced-key map as stored (provider => concealed value).
     *
     * @return array|null null when the setting is absent
     */
    private function displaced(): ?array {
        $raw = get_config('local_ai_course_assistant', 'provider_keys_displaced');
        return $raw === false ? null : json_decode((string) $raw, true);
    }

    /**
     * The audit rows the upgrade wrote for a displaced key.
     *
     * @return \stdClass[]
     */
    private function displaced_audit_rows(): array {
        global $DB;
        return array_values($DB->get_records('local_ai_course_assistant_audit', ['action' => 'provider_key_displaced'],
            'id ASC'));
    }

    /**
     * The stored table, one entry per line.
     *
     * @return string[]
     */
    private function lines(): array {
        return explode("\n", $this->stored());
    }

    /**
     * Rows for a provider with their keys revealed, in stored order.
     *
     * @param string $provider
     * @return array[]
     */
    private function rows_of(string $provider): array {
        return array_values(array_filter(provider_keys::rows(true), fn($r) => $r['provider'] === $provider));
    }

    public function test_plain_rows_become_ciphertext_and_keep_their_keys(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-DEFAULT-KEY-1111-AAAA'));
        $this->cfg('comparison_providers', "openai|sk-proj-DEFAULT-KEY-1111-AAAA|gpt-4o-mini\n"
            . "claude|sk-ant-CLAUDE-ROW-2222-BBBB|claude-haiku-4-5");
        $summary = provider_keys::upgrade();
        $this->assertSame(1, $summary['encrypted']);
        $this->assertStringNotContainsString('sk-ant-CLAUDE', $this->stored());
        $this->assertSame('sk-ant-CLAUDE-ROW-2222-BBBB', provider_keys::key_for('claude'));
        $this->assertSame('sk-proj-DEFAULT-KEY-1111-AAAA', provider_keys::key_for('openai'));
        $this->assertSame('sk-proj-DEFAULT-KEY-1111-AAAA', secrets::get('apikey'));
        $this->assertSame('', $summary['row_added']);
        $this->assertSame('', $summary['conflict']);
    }

    public function test_a_default_provider_with_no_row_gets_one_from_apikey(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('apikey', secrets::conceal('AIzaDEFAULT-GEMINI-3333-CCCC'));
        $this->cfg('comparison_providers', '');
        $summary = provider_keys::upgrade();
        $this->assertSame('gemini', $summary['row_added']);
        $this->assertSame('AIzaDEFAULT-GEMINI-3333-CCCC', provider_keys::key_for('gemini'));
        $this->assertSame('AIzaDEFAULT-GEMINI-3333-CCCC', secrets::get('apikey'));
        $this->assertStringNotContainsString('AIzaDEFAULT', $this->stored());
    }

    public function test_a_conflicting_row_takes_the_key_chat_was_using(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-PRIMARY-KEY-4444-DDDD'));
        $this->cfg('comparison_providers', "openai|sk-proj-STALE-ROW-KEY-5555-EEEE|gpt-4o-mini");
        $summary = provider_keys::upgrade();
        $this->assertSame('sk-proj-PRIMARY-KEY-4444-DDDD', provider_keys::key_for('openai'));
        $this->assertSame('sk-proj-PRIMARY-KEY-4444-DDDD', secrets::get('apikey'));
        $this->assertSame('openai', $summary['conflict'], 'the summary names the provider only');
        $this->assertSame(['provider' => 'openai', 'rowlast4' => 'EEEE', 'defaultlast4' => 'DDDD'], $this->notice(),
            'the notice is structured data with the two last-four values');
        $raw = (string) get_config('local_ai_course_assistant', 'provider_keys_notice');
        $this->assertStringNotContainsString('STALE', $raw);
        $this->assertStringNotContainsString('PRIMARY', $raw);
    }

    /** Review focus 4. */
    public function test_auto_with_no_key_is_left_exactly_as_it_was(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'auto');
        $this->cfg('model', 'gemini-3.1-flash-lite');
        $this->cfg('comparison_providers', "gemini|AIzaROW-ONLY-GEMINI-6666-FFFF|g");
        $summary = provider_keys::upgrade();
        $this->assertSame('', $summary['row_added']);
        $this->assertSame('', secrets::get('apikey'));
        $this->assertSame('AIzaROW-ONLY-GEMINI-6666-FFFF', provider_keys::key_for('gemini'));
    }

    public function test_auto_with_a_key_and_a_model_adds_the_inferred_providers_row(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'auto');
        $this->cfg('model', 'gemini-3.1-flash-lite');
        $this->cfg('apikey', secrets::conceal('AIzaAUTO-GEMINI-KEY-7777-GGGG'));
        $summary = provider_keys::upgrade();
        $this->assertSame('gemini', $summary['row_added']);
        $this->assertSame('AIzaAUTO-GEMINI-KEY-7777-GGGG', provider_keys::key_for('gemini'));
    }

    /** Review finding M5: auto with an Anthropic key and no model resolves openai; the key must not be filed there. */
    public function test_a_key_that_belongs_to_another_vendor_is_not_filed_under_the_default_provider(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'auto');
        $this->cfg('model', '');
        $this->cfg('apikey', secrets::conceal('sk-ant-ANTHROPIC-KEY-1357-AAAA'));
        $this->cfg('comparison_providers', '');
        $this->assertSame('openai', provider_keys::site_provider(), 'auto with no model name resolves openai');
        $summary = provider_keys::upgrade();
        $this->assertSame('', $summary['row_added']);
        $this->assertSame('', $summary['conflict']);
        $this->assertSame('', $this->stored(), 'no openai row holding an Anthropic key');
        $this->assertNull(provider_keys::row_for('openai'));
        $this->assertFalse(get_config('local_ai_course_assistant', 'provider_keys_notice'), 'and no notice');
        $this->assertSame('sk-ant-ANTHROPIC-KEY-1357-AAAA', secrets::get('apikey'), 'apikey itself is untouched');
    }

    public function test_an_explicit_default_provider_with_another_vendors_key_gets_no_row_either(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('apikey', secrets::conceal('sk-ant-ANTHROPIC-KEY-1357-AAAA'));
        $summary = provider_keys::upgrade();
        $this->assertSame('', $summary['row_added']);
        $this->assertSame('', $this->stored());
    }

    public function test_a_wrong_vendor_key_does_not_overwrite_an_existing_keyed_row_either(): void {
        // Same mis-filing by the other door: the conflict rule would swap an Anthropic key into the openai row.
        $this->resetAfterTest();
        $this->cfg('provider', 'auto');
        $this->cfg('apikey', secrets::conceal('sk-ant-ANTHROPIC-KEY-1357-AAAA'));
        $this->cfg('comparison_providers', 'openai|sk-proj-REAL-OPENAI-KEY-2468-BBBB|gpt-4o-mini');
        $summary = provider_keys::upgrade();
        $this->assertSame('', $summary['conflict']);
        $this->assertSame('', $summary['row_added']);
        $this->assertSame('sk-proj-REAL-OPENAI-KEY-2468-BBBB', provider_keys::key_for('openai'));
        $this->assertFalse(get_config('local_ai_course_assistant', 'provider_keys_notice'));
    }

    public function test_a_gateway_site_may_file_any_shaped_key_under_its_provider(): void {
        // A gateway takes whatever key it is given, so the prefix check does not apply (the base URL is passed).
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apibaseurl', self::GATEWAY);
        $this->cfg('apikey', secrets::conceal('sk-ant-GATEWAY-TOKEN-1357-AAAA'));
        $summary = provider_keys::upgrade();
        $this->assertSame('openai', $summary['row_added']);
        $this->assertSame('sk-ant-GATEWAY-TOKEN-1357-AAAA', provider_keys::key_for('openai'));
    }

    public function test_a_keyless_default_provider_adds_no_row(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'ollama');
        $this->cfg('apikey', secrets::conceal('not-a-real-key-0000'));
        $summary = provider_keys::upgrade();
        $this->assertSame('', $summary['row_added']);
    }

    public function test_a_table_forced_in_config_php_is_untouched(): void {
        global $CFG;
        $this->resetAfterTest();
        $plain = 'openai|sk-proj-DB-PLAIN-ROW-0000-1111|m';
        $this->cfg('comparison_providers', $plain);
        $CFG->forced_plugin_settings['local_ai_course_assistant']['comparison_providers'] =
            'openai|sk-proj-FORCED-ROW-8888-HHHH|m';
        try {
            $this->cfg('provider', 'openai');
            $this->cfg('apikey', secrets::conceal('sk-proj-ANOTHER-KEY-9999-IIII'));
            $summary = provider_keys::upgrade();
            $this->assertSame(0, $summary['encrypted']);
            $this->assertSame('', $summary['row_added']);
            $this->assertSame($plain, $this->dbvalue('comparison_providers'), 'the database value is not rewritten');
        } finally {
            unset($CFG->forced_plugin_settings['local_ai_course_assistant']['comparison_providers']);
        }
    }

    /** A key forced in config.php is kept out of the database on purpose; the upgrade must not copy it in. */
    public function test_a_forced_apikey_is_never_copied_into_a_new_row(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->forced_plugin_settings['local_ai_course_assistant']['apikey'] = 'sk-proj-FORCED-SITE-KEY-1212-3434';
        try {
            $this->cfg('provider', 'openai');
            $this->cfg('comparison_providers', '');
            $summary = provider_keys::upgrade();
            $this->assertSame('', $summary['row_added']);
            $this->assertSame('', $summary['conflict']);
            $this->assertSame('', $this->stored(), 'no entry for the provider');
            $this->assertSame('', $this->dbvalue('comparison_providers'));
            $this->assertSame('', $this->dbvalue('apikey'), 'the forced key is not written either');
        } finally {
            unset($CFG->forced_plugin_settings['local_ai_course_assistant']['apikey']);
        }
    }

    public function test_a_forced_apikey_never_overwrites_an_existing_row_and_sets_no_notice(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->forced_plugin_settings['local_ai_course_assistant']['apikey'] = 'sk-proj-FORCED-SITE-KEY-1212-3434';
        try {
            $this->cfg('provider', 'openai');
            $this->cfg('comparison_providers', 'openai|sk-proj-ROW-KEEP-KEY-5555-6666|m');
            $summary = provider_keys::upgrade();
            $this->assertSame('', $summary['conflict']);
            $this->assertSame('', $summary['row_added']);
            $this->assertSame('sk-proj-ROW-KEEP-KEY-5555-6666', provider_keys::key_for('openai'));
            $this->assertFalse(get_config('local_ai_course_assistant', 'provider_keys_notice'));
            $this->assertCount(1, $this->rows_of('openai'));
        } finally {
            unset($CFG->forced_plugin_settings['local_ai_course_assistant']['apikey']);
        }
    }

    public function test_running_it_twice_changes_nothing(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-TWICE-KEY-1010-JJJJ'));
        $this->cfg('comparison_providers', "claude|sk-ant-TWICE-ROW-2020-KKKK|c");
        provider_keys::upgrade();
        $first = $this->stored();
        $again = provider_keys::upgrade();
        $this->assertSame($first, $this->stored(), 'stored table is byte-identical on a second run');
        $this->assertSame(0, $again['encrypted']);
        $this->assertSame('', $again['row_added']);
        $this->assertSame('', $again['conflict']);
    }

    public function test_a_conflict_leaves_a_one_time_notice_that_names_no_key(): void {
        $this->resetAfterTest();
        settings_save_report::assume_web(true);
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-PRIMARY-KEY-3030-LLLL'));
        $this->cfg('comparison_providers', "openai|sk-proj-OLDROW-KEY-4040-MMMM|m");
        provider_keys::upgrade();
        provider_keys::flush_notice();
        $notices = \core\notification::fetch();
        $this->assertCount(1, $notices);
        $message = $notices[0]->get_message();
        $this->assertStringContainsString('openai', $message);
        $this->assertStringContainsString('MMMM', $message, 'the old row key\'s last four');
        $this->assertStringContainsString('LLLL', $message, 'the default key\'s last four');
        $this->assertStringNotContainsString('OLDROW', $message);
        $this->assertStringNotContainsString('PRIMARY', $message);
        $this->assertStringNotContainsString('[[', $message, 'brand tokens are resolved');
        provider_keys::flush_notice();
        $this->assertCount(0, \core\notification::fetch(), 'shown once');
        $this->assertNull($this->notice(), 'and forgotten');
    }

    /**
     * F2.3: admin/cli/upgrade.php runs admin_apply_default_settings(), which includes settings.php (and so
     * flush_notice()) right after the upgrade step wrote the notice. A CLI request has no admin to show it
     * to, and what it queued would die with its session, so the notice has to stay for the next web page.
     */
    public function test_a_cli_request_keeps_the_notice_for_the_next_admin_page(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-PRIMARY-KEY-3030-LLLL'));
        $this->cfg('comparison_providers', "openai|sk-proj-OLDROW-KEY-4040-MMMM|m");
        provider_keys::upgrade();
        $raw = get_config('local_ai_course_assistant', 'provider_keys_notice');
        $this->assertNotFalse($raw, 'the upgrade left a notice');
        $this->assertTrue(CLI_SCRIPT, 'PHPUnit is a CLI request, as the upgrade script is');

        provider_keys::flush_notice();
        $this->assertCount(0, \core\notification::fetch(), 'nothing is queued into a CLI session');
        $this->assertSame($raw, get_config('local_ai_course_assistant', 'provider_keys_notice'), 'and the notice is still there');

        settings_save_report::assume_web(true);
        provider_keys::flush_notice();
        $this->assertCount(1, \core\notification::fetch(), 'the next web page shows it');
        $this->assertFalse(get_config('local_ai_course_assistant', 'provider_keys_notice'), 'once');
    }

    /** F2.3: a web request that is part of the upgrade itself (the admin pages run while it is under way) waits too. */
    public function test_a_web_request_during_an_upgrade_keeps_the_notice(): void {
        global $CFG;
        $this->resetAfterTest();
        settings_save_report::assume_web(true);
        $notice = json_encode(['provider' => 'openai', 'rowlast4' => 'MMMM', 'defaultlast4' => 'LLLL']);
        $this->cfg('provider_keys_notice', $notice);
        $CFG->upgraderunning = time() + 300;
        provider_keys::flush_notice();
        $this->assertCount(0, \core\notification::fetch(), 'nothing is shown while the upgrade runs');
        $this->assertSame($notice, get_config('local_ai_course_assistant', 'provider_keys_notice'), 'and it is kept');

        unset($CFG->upgraderunning);
        provider_keys::flush_notice();
        $this->assertCount(1, \core\notification::fetch(), 'it shows once the upgrade is over');
        $this->assertFalse(get_config('local_ai_course_assistant', 'provider_keys_notice'));
    }

    /** The notice is ONE translatable string: the product name comes from the branding tokens, not from code. */
    public function test_the_notice_uses_the_configured_product_name(): void {
        $this->resetAfterTest();
        settings_save_report::assume_web(true);
        $this->cfg('short_name', 'ACMEBOT');
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-PRIMARY-KEY-3030-LLLL'));
        $this->cfg('comparison_providers', "openai|sk-proj-OLDROW-KEY-4040-MMMM|m");
        provider_keys::upgrade();
        provider_keys::flush_notice();
        $notices = \core\notification::fetch();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('ACMEBOT', $notices[0]->get_message());
        $this->assertStringNotContainsString('SOLA', $notices[0]->get_message());
    }

    /**
     * A site that ran an earlier build holds the old value: a finished English sentence. It, and anything else
     * that is not the structured shape, shows nothing, is forgotten and breaks nothing.
     *
     * @dataProvider unusable_notices_provider
     * @param string $value
     */
    public function test_an_old_or_malformed_notice_shows_nothing_and_is_forgotten(string $value): void {
        $this->resetAfterTest();
        settings_save_report::assume_web(true);
        $this->cfg('provider_keys_notice', $value);
        provider_keys::flush_notice();
        $this->assertCount(0, \core\notification::fetch());
        $this->assertFalse(get_config('local_ai_course_assistant', 'provider_keys_notice'));
    }

    /**
     * @return array[]
     */
    public static function unusable_notices_provider(): array {
        return [
            'old english sentence' => ['openai: the row ended in MMMM and the default key ends in LLLL'],
            'not json' => ['{"provider":'],
            'json scalar' => ['5'],
            'json list' => ['["openai","MMMM","LLLL"]'],
            'missing a field' => ['{"provider":"openai","rowlast4":"MMMM"}'],
            'a field of the wrong type' => ['{"provider":"openai","rowlast4":["x"],"defaultlast4":"LLLL"}'],
            'empty provider' => ['{"provider":"","rowlast4":"MMMM","defaultlast4":"LLLL"}'],
        ];
    }

    /** R15: a gateway key must never be copied into a row with no base URL, where a failover would send it to the vendor. */
    public function test_a_gateway_site_gets_its_own_row_with_the_gateway_base_url(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apibaseurl', ' ' . self::GATEWAY . ' ');
        $this->cfg('apikey', secrets::conceal('gateway-key-GATEWAY-0000-1111'));
        $this->cfg('comparison_providers', "openai|sk-proj-REAL-VENDOR-KEY-9999-0000|gpt-4o-mini");
        $summary = provider_keys::upgrade();

        $this->assertSame('openai', $summary['row_added']);
        $this->assertSame('', $summary['conflict'], 'a row for another destination is not a conflict');
        $rows = $this->rows_of('openai');
        $this->assertCount(2, $rows);
        $this->assertSame('sk-proj-REAL-VENDOR-KEY-9999-0000', $rows[0]['key'], 'the vendor row is untouched');
        $this->assertSame('', $rows[0]['baseurl']);
        $this->assertSame('gateway-key-GATEWAY-0000-1111', $rows[1]['key']);
        $this->assertSame(self::GATEWAY, $rows[1]['baseurl'], 'the new row carries the trimmed site base URL');
        $this->assertSame('gateway-key-GATEWAY-0000-1111', secrets::get('apikey'));
        $this->assertStringNotContainsString('GATEWAY-0000', $this->stored());
        $this->assertStringNotContainsString('REAL-VENDOR', $this->stored());
    }

    public function test_a_gateway_site_mirror_keeps_the_gateway_key_after_the_upgrade(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apibaseurl', self::GATEWAY);
        $this->cfg('apikey', secrets::conceal('gateway-key-GATEWAY-0000-1111'));
        $this->cfg('comparison_providers', "openai|sk-proj-REAL-VENDOR-KEY-9999-0000|gpt-4o-mini");
        provider_keys::upgrade();
        $this->assertFalse(provider_keys::sync_default_key(), 'apikey already equals the gateway row');
        $this->assertSame('gateway-key-GATEWAY-0000-1111', secrets::get('apikey'));
    }

    public function test_a_gateway_row_with_the_same_key_and_base_url_adds_nothing(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apibaseurl', self::GATEWAY);
        $this->cfg('apikey', secrets::conceal('gateway-key-GATEWAY-0000-1111'));
        $this->cfg('comparison_providers', 'openai|gateway-key-GATEWAY-0000-1111|m||' . self::GATEWAY);
        $summary = provider_keys::upgrade();
        $this->assertSame('', $summary['row_added']);
        $this->assertSame('', $summary['conflict']);
        $this->assertCount(1, $this->rows_of('openai'));
    }

    public function test_the_conflict_rule_applies_only_to_the_row_with_the_same_base_url(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apibaseurl', self::GATEWAY);
        $this->cfg('apikey', secrets::conceal('gateway-key-CURRENT-0000-2222'));
        $this->cfg('comparison_providers', "openai|sk-proj-REAL-VENDOR-KEY-9999-0000|gpt-4o-mini\n"
            . 'openai|gateway-key-OLDONE-0000-3333|m||' . self::GATEWAY);
        $summary = provider_keys::upgrade();

        $this->assertSame('', $summary['row_added']);
        $this->assertSame(['provider' => 'openai', 'rowlast4' => '3333', 'defaultlast4' => '2222'], $this->notice(),
            'the gateway row\'s last four and the default\'s, not the vendor row\'s 0000');
        $rows = $this->rows_of('openai');
        $this->assertCount(2, $rows);
        $this->assertSame('sk-proj-REAL-VENDOR-KEY-9999-0000', $rows[0]['key'], 'the vendor row is left completely alone');
        $this->assertSame('gateway-key-CURRENT-0000-2222', $rows[1]['key'], 'the gateway row takes the key chat was using');
    }

    public function test_with_no_gateway_a_row_that_has_a_base_url_is_not_the_default_row(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-SITE-KEY-KEEP-3333-4444'));
        $this->cfg('comparison_providers', "openai|sk-proj-OTHER-GW-KEY-5555-6666|m||https://other.example.test/v1");
        $summary = provider_keys::upgrade();
        $this->assertSame('openai', $summary['row_added']);
        $this->assertSame('', $summary['conflict']);
        $rows = $this->rows_of('openai');
        $this->assertSame('sk-proj-OTHER-GW-KEY-5555-6666', $rows[0]['key']);
        $this->assertSame('https://other.example.test/v1', $rows[0]['baseurl']);
        $this->assertSame('sk-proj-SITE-KEY-KEEP-3333-4444', $rows[1]['key']);
        $this->assertSame('', $rows[1]['baseurl']);
    }

    public function test_running_it_twice_on_a_gateway_site_changes_nothing(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apibaseurl', self::GATEWAY);
        $this->cfg('apikey', secrets::conceal('gateway-key-GATEWAY-0000-1111'));
        $this->cfg('comparison_providers', "openai|sk-proj-REAL-VENDOR-KEY-9999-0000|gpt-4o-mini");
        provider_keys::upgrade();
        $first = $this->stored();
        $again = provider_keys::upgrade();
        $this->assertSame($first, $this->stored());
        $this->assertSame('', $again['row_added']);
        $this->assertSame('', $again['conflict']);
    }

    /** The upgrade edits the stored text; it never rebuilds a line it was not asked to change. */
    public function test_appending_a_row_leaves_every_existing_line_as_it_was(): void {
        $this->resetAfterTest();
        $comment = '# openai|sk-parked-comment-key|m';
        $this->cfg('provider', 'gemini');
        $this->cfg('apikey', secrets::conceal('AIzaDEFAULT-GEMINI-3333-CCCC'));
        $this->cfg('comparison_providers', "openai\n" . $comment
            . "\nClaude|sk-ant-MIXED-CASE-KEY-1111-AAAA|m|0.3|https://c.example|extra");
        $summary = provider_keys::upgrade();

        $this->assertSame('gemini', $summary['row_added']);
        $lines = $this->lines();
        $this->assertCount(4, $lines);
        $this->assertSame('openai', $lines[0], 'a one-column line stays a one-column line');
        $this->assertSame($comment, $lines[1], 'a comment line stays, byte for byte');
        $claude = explode('|', $lines[2]);
        $this->assertSame(['Claude', 'm', '0.3', 'https://c.example', 'extra'],
            array_merge([$claude[0]], array_slice($claude, 2)), 'label case and every other column are kept');
        $this->assertTrue(secrets::is_encrypted($claude[1]));
        $this->assertSame('sk-ant-MIXED-CASE-KEY-1111-AAAA', secrets::reveal($claude[1]));
        $added = explode('|', $lines[3]);
        $this->assertSame('gemini', $added[0]);
        $this->assertSame('AIzaDEFAULT-GEMINI-3333-CCCC', secrets::reveal($added[1]));
        $this->assertSame('', $added[2]);
        $this->assertNull(provider_keys::row_for('openai'), 'the one-column line did not become a row');
        $first = $this->stored();
        provider_keys::upgrade();
        $this->assertSame($first, $this->stored(), 'a second run leaves the string byte-identical');
    }

    public function test_resolving_a_conflict_changes_only_the_key_field_of_that_line(): void {
        $this->resetAfterTest();
        $comment = '# openai|sk-parked-comment-key|m';
        $this->cfg('provider', 'claude');
        $this->cfg('apibaseurl', 'https://c.example');
        $this->cfg('apikey', secrets::conceal('sk-ant-CURRENT-KEY-2222-BBBB'));
        $this->cfg('comparison_providers', "openai\n" . $comment
            . "\nClaude|sk-ant-MIXED-CASE-KEY-1111-AAAA|m|0.3|https://c.example|extra");
        $summary = provider_keys::upgrade();

        $this->assertSame('', $summary['row_added']);
        $this->assertSame('claude', $summary['conflict']);
        $this->assertSame(['provider' => 'claude', 'rowlast4' => 'AAAA', 'defaultlast4' => 'BBBB'], $this->notice());
        $lines = $this->lines();
        $this->assertCount(3, $lines);
        $this->assertSame('openai', $lines[0]);
        $this->assertSame($comment, $lines[1]);
        $claude = explode('|', $lines[2]);
        $this->assertSame(['Claude', 'm', '0.3', 'https://c.example', 'extra'],
            array_merge([$claude[0]], array_slice($claude, 2)), 'only the key field was swapped');
        $this->assertSame('sk-ant-CURRENT-KEY-2222-BBBB', secrets::reveal($claude[1]));
        $this->assertNull(provider_keys::row_for('openai'));
        $first = $this->stored();
        $again = provider_keys::upgrade();
        $this->assertSame($first, $this->stored(), 'a second run leaves the string byte-identical');
        $this->assertSame('', $again['conflict']);
    }

    /** The line it swaps is the one stored_rows() calls that row, however many non-rows come before it. */
    public function test_a_conflict_swap_finds_its_row_past_blank_comment_and_providerless_lines(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-CURRENT-KEY-0000-3333'));
        $this->cfg('comparison_providers', "# a comment\n\n|sk-orphan-KEY-0000-1111|m\n"
            . "openai|sk-proj-OLDKEY-KEY-0000-2222|m");
        $summary = provider_keys::upgrade();

        $this->assertSame('openai', $summary['conflict']);
        $this->assertSame('2222', $this->notice()['rowlast4']);
        $lines = $this->lines();
        $this->assertCount(4, $lines);
        $this->assertSame('# a comment', $lines[0]);
        $this->assertSame('', $lines[1]);
        $orphan = explode('|', $lines[2]);
        $this->assertSame('', $orphan[0]);
        $this->assertSame('sk-orphan-KEY-0000-1111', secrets::reveal($orphan[1]), 'the providerless line keeps its key');
        $this->assertSame('m', $orphan[2]);
        $this->assertSame('sk-proj-CURRENT-KEY-0000-3333', secrets::reveal(explode('|', $lines[3])[1]));
        $this->assertSame('sk-proj-CURRENT-KEY-0000-3333', provider_keys::key_for('openai'));
    }

    public function test_a_table_that_ends_in_a_newline_gets_the_new_row_without_a_blank_line(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('apikey', secrets::conceal('AIzaDEFAULT-GEMINI-3333-CCCC'));
        $this->cfg('comparison_providers', "claude|" . secrets::conceal('sk-ant-CLAUDE-ROW-2222-BBBB') . "|c\n");
        provider_keys::upgrade();
        $lines = $this->lines();
        $this->assertCount(2, $lines, 'the existing trailing newline is the separator');
        $this->assertStringStartsWith('claude|', $lines[0]);
        $this->assertStringStartsWith('gemini|', $lines[1]);
    }

    /**
     * R16 and R21. A crash between steps must stay re-runnable, so the order is: notice, displaced blob, audit
     * row, then the table write. The set_config() calls can't be observed from outside, so this reads the order
     * from the method's source.
     */
    public function test_the_conflict_steps_run_notice_then_displaced_then_audit_then_table(): void {
        $method = new \ReflectionMethod(provider_keys::class, 'upgrade');
        $lines = file($method->getFileName());
        $source = implode('', array_slice($lines, $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1));
        $notice = strpos($source, "set_config('provider_keys_notice'");
        $displaced = strpos($source, 'self::keep_displaced(');
        $audit = strpos($source, "audit_logger::log('provider_key_displaced'");
        $table = strrpos($source, 'set_config(self::SETTING');
        foreach (['notice' => $notice, 'displaced' => $displaced, 'audit' => $audit, 'table' => $table] as $name => $at) {
            $this->assertNotFalse($at, "the $name step is in upgrade()");
        }
        $this->assertLessThan($displaced, $notice, 'the notice is written before the displaced blob');
        $this->assertLessThan($audit, $displaced, 'the displaced blob is stored before the audit row');
        $this->assertLessThan($table, $audit, 'the audit row is written before the table is rewritten');
    }

    public function test_a_displaced_key_is_kept_concealed_and_decrypts_to_the_old_key(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-PRIMARY-KEY-4444-DDDD'));
        $this->cfg('comparison_providers', 'openai|sk-proj-STALE-ROW-KEY-5555-EEEE|gpt-4o-mini');
        provider_keys::upgrade();

        $map = $this->displaced();
        $this->assertSame(['openai'], array_keys($map));
        $this->assertTrue(secrets::is_encrypted($map['openai']), 'concealed, not plain text');
        $this->assertSame('sk-proj-STALE-ROW-KEY-5555-EEEE', secrets::reveal($map['openai']));
        $raw = (string) get_config('local_ai_course_assistant', 'provider_keys_displaced');
        $this->assertStringNotContainsString('STALE', $raw);
        $this->assertStringNotContainsString('5555', $raw);
        $this->assertSame('sk-proj-PRIMARY-KEY-4444-DDDD', provider_keys::key_for('openai'), 'the row still takes the default key');
    }

    public function test_the_audit_row_carries_the_provider_and_the_two_last_four_values_only(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-PRIMARY-KEY-4444-DDDD'));
        $this->cfg('comparison_providers', 'openai|sk-proj-STALE-ROW-KEY-5555-EEEE|gpt-4o-mini');
        provider_keys::upgrade();

        $rows = $this->displaced_audit_rows();
        $this->assertCount(1, $rows);
        $this->assertSame(0, (int) $rows[0]->userid);
        $this->assertSame(0, (int) $rows[0]->courseid);
        $this->assertSame(['provider' => 'openai', 'row_last4' => 'EEEE', 'default_last4' => 'DDDD'],
            json_decode($rows[0]->details, true));
        foreach (['STALE', 'PRIMARY', 'sk-proj', '5555', '4444', 'sodium:'] as $needle) {
            $this->assertStringNotContainsString($needle, $rows[0]->details);
        }
    }

    public function test_running_the_upgrade_again_adds_no_second_audit_row_and_changes_no_blob(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-PRIMARY-KEY-4444-DDDD'));
        $this->cfg('comparison_providers', 'openai|sk-proj-STALE-ROW-KEY-5555-EEEE|gpt-4o-mini');
        provider_keys::upgrade();
        $blob = (string) get_config('local_ai_course_assistant', 'provider_keys_displaced');
        provider_keys::upgrade();
        $this->assertCount(1, $this->displaced_audit_rows());
        $this->assertSame($blob, (string) get_config('local_ai_course_assistant', 'provider_keys_displaced'));
    }

    /** An entry already there is the older, original credential: a re-run after a crash must not replace it. */
    public function test_an_existing_displaced_entry_is_never_clobbered(): void {
        $this->resetAfterTest();
        $first = secrets::conceal('sk-proj-FIRST-DISPLACED-0000-1111');
        $other = secrets::conceal('sk-ant-OTHER-DISPLACED-0000-2222');
        $this->cfg('provider_keys_displaced', json_encode(['openai' => $first, 'claude' => $other]));
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-PRIMARY-KEY-4444-DDDD'));
        $this->cfg('comparison_providers', 'openai|sk-proj-STALE-ROW-KEY-5555-EEEE|gpt-4o-mini');
        provider_keys::upgrade();

        $map = $this->displaced();
        $this->assertSame($first, $map['openai'], 'the existing entry stays byte for byte');
        $this->assertSame($other, $map['claude'], 'and so does every other provider\'s');
        $this->assertSame('sk-proj-PRIMARY-KEY-4444-DDDD', provider_keys::key_for('openai'), 'the row is still resolved');
    }

    public function test_a_new_displaced_entry_is_added_beside_the_others(): void {
        $this->resetAfterTest();
        $other = secrets::conceal('sk-ant-OTHER-DISPLACED-0000-2222');
        $this->cfg('provider_keys_displaced', json_encode(['claude' => $other]));
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-PRIMARY-KEY-4444-DDDD'));
        $this->cfg('comparison_providers', 'openai|sk-proj-STALE-ROW-KEY-5555-EEEE|gpt-4o-mini');
        provider_keys::upgrade();

        $map = $this->displaced();
        $this->assertSame(['claude', 'openai'], array_keys($map));
        $this->assertSame($other, $map['claude']);
        $this->assertSame('sk-proj-STALE-ROW-KEY-5555-EEEE', secrets::reveal($map['openai']));
    }

    public function test_a_damaged_displaced_value_is_replaced_not_fatal(): void {
        $this->resetAfterTest();
        $this->cfg('provider_keys_displaced', 'not json at all');
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-PRIMARY-KEY-4444-DDDD'));
        $this->cfg('comparison_providers', 'openai|sk-proj-STALE-ROW-KEY-5555-EEEE|gpt-4o-mini');
        provider_keys::upgrade();
        $this->assertSame('sk-proj-STALE-ROW-KEY-5555-EEEE', secrets::reveal($this->displaced()['openai']));
    }

    public function test_no_conflict_means_no_displaced_blob_and_no_audit_row(): void {
        $this->resetAfterTest();
        // A matching row, a missing row, and a row for another provider: none displaces anything.
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-PRIMARY-KEY-4444-DDDD'));
        $this->cfg('comparison_providers', "openai|sk-proj-PRIMARY-KEY-4444-DDDD|m\nclaude|sk-ant-CLAUDE-ROW-2222-BBBB|c");
        provider_keys::upgrade();
        $this->cfg('comparison_providers', 'claude|sk-ant-CLAUDE-ROW-2222-BBBB|c');
        provider_keys::upgrade();
        $this->assertNull($this->displaced());
        $this->assertCount(0, $this->displaced_audit_rows());
    }
}
