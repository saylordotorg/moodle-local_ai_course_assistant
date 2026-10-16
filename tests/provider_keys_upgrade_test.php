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
        $this->assertStringContainsString('EEEE', $summary['conflict']);
        $this->assertStringContainsString('DDDD', $summary['conflict']);
        $this->assertStringNotContainsString('STALE', $summary['conflict']);
        $this->assertStringNotContainsString('PRIMARY', $summary['conflict']);
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
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-PRIMARY-KEY-3030-LLLL'));
        $this->cfg('comparison_providers', "openai|sk-proj-OLDROW-KEY-4040-MMMM|m");
        provider_keys::upgrade();
        provider_keys::flush_notice();
        $notices = \core\notification::fetch();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('MMMM', $notices[0]->get_message());
        $this->assertStringNotContainsString('OLDROW', $notices[0]->get_message());
        provider_keys::flush_notice();
        $this->assertCount(0, \core\notification::fetch(), 'shown once');
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
        $this->assertStringContainsString('3333', $summary['conflict']);
        $this->assertStringContainsString('2222', $summary['conflict']);
        $this->assertStringNotContainsString('9999-0000', $summary['conflict']);
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
        $this->assertStringContainsString('AAAA', $summary['conflict']);
        $this->assertStringContainsString('BBBB', $summary['conflict']);
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

        $this->assertStringContainsString('2222', $summary['conflict']);
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
     * R16. A crash between the two writes must not lose the notice, so the notice goes first. The two
     * set_config() calls can't be observed from outside, so this reads the order from the method's source.
     */
    public function test_the_notice_is_saved_before_the_table_is_rewritten(): void {
        $method = new \ReflectionMethod(provider_keys::class, 'upgrade');
        $lines = file($method->getFileName());
        $source = implode('', array_slice($lines, $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1));
        $notice = strpos($source, "set_config('provider_keys_notice'");
        $table = strrpos($source, 'set_config(self::SETTING');
        $this->assertNotFalse($notice);
        $this->assertNotFalse($table);
        $this->assertLessThan($table, $notice, 'the notice is written before the last table write');
    }
}
