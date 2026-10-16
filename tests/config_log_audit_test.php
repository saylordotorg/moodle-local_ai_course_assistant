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
 * Credentials left in the clear in config_log must be findable.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\config_log_audit
 */
final class config_log_audit_test extends \advanced_testcase {

    /**
     * Insert a config_log row directly.
     *
     * @param string $plugin
     * @param string $name
     * @param string $value
     * @param string $oldvalue The value this row replaced, as core records it.
     * @return int
     */
    private function log_row(string $plugin, string $name, string $value, string $oldvalue = ''): int {
        global $DB;
        return (int) $DB->insert_record('config_log', (object) [
            'userid' => 2,
            'timemodified' => time(),
            'plugin' => $plugin,
            'name' => $name,
            'value' => $value,
            'oldvalue' => $oldvalue,
        ]);
    }

    /**
     * A key written in the clear is found; a masked or blanked one is not.
     */
    public function test_finds_only_values_actually_in_the_clear(): void {
        $this->resetAfterTest(true);

        $clear = $this->log_row('local_saylorcode', 'jobeapikey', str_repeat('a', 64));
        $this->log_row('local_ai_course_assistant', 'apikey', '********');
        $this->log_row('local_ai_course_assistant', 'embed_apikey', '');

        $ids = array_column(config_log_audit::find_exposed(), 'id');
        $this->assertContains($clear, $ids);
        $this->assertCount(1, $ids);
    }

    /**
     * The finding covers every plugin, not just this one.
     *
     * The August 2026 dev audit turned up exposed keys in four plugins. A check
     * scoped to this plugin's own settings would have reported all clear.
     */
    public function test_audit_is_site_wide(): void {
        $this->resetAfterTest(true);

        $this->log_row('block_openai_chat', 'apikey', str_repeat('b', 164));
        $this->log_row('local_corolair', 'apikey', str_repeat('c', 19));
        $this->log_row('local_ai_course_assistant', 'xai_proxy_jwt_secret', str_repeat('d', 64));

        $plugins = array_column(config_log_audit::find_exposed(), 'plugin');
        sort($plugins);
        $this->assertSame(
            ['block_openai_chat', 'local_ai_course_assistant', 'local_corolair'],
            $plugins
        );
    }

    /**
     * A secret value must never come back from the audit.
     */
    public function test_reported_rows_carry_no_value(): void {
        $this->resetAfterTest(true);

        $secret = str_repeat('e', 40);
        $this->log_row('local_saylorcode', 'jobeapikey', $secret);

        $found = config_log_audit::find_exposed();
        $this->assertCount(1, $found);
        $this->assertArrayNotHasKey('value', $found[0]);
        $this->assertStringNotContainsString($secret, json_encode($found[0]));
        $this->assertSame(40, $found[0]['length']);
    }

    /**
     * A published key is not a secret and must not be masked.
     */
    public function test_public_key_is_not_treated_as_a_secret(): void {
        $this->assertFalse(config_log_audit::is_secret_name('policy_bundle_pubkey'));
        $this->assertFalse(config_log_audit::is_secret_name('max_tokens'));
        $this->assertFalse(config_log_audit::is_secret_name('backend_context_tokens'));
        $this->assertTrue(config_log_audit::is_secret_name('jobeapikey'));
        $this->assertTrue(config_log_audit::is_secret_name('xai_proxy_jwt_secret'));
        $this->assertTrue(config_log_audit::is_secret_name('zendesk_token'));
    }

    /**
     * Redaction removes the value and keeps the row.
     */
    public function test_a_rotated_key_is_still_exposed_in_oldvalue(): void {
        $this->resetAfterTest(true);

        // The shape a rotation leaves behind: the new key in value, the key it
        // replaced in oldvalue. /report/configlog renders both. A scan of value
        // alone calls this site clean while the retired key is still on screen.
        $id = $this->log_row('local_ai_course_assistant', 'apikey',
            str_repeat('n', 53), str_repeat('o', 53));

        $found = config_log_audit::find_exposed();
        $columns = array_column($found, 'column');
        sort($columns);
        $this->assertSame(['oldvalue', 'value'], $columns);
        $this->assertSame([$id, $id], array_column($found, 'id'));
    }

    /**
     * A cleared key leaves nothing in value and the whole key in oldvalue.
     *
     * This is the case that made the value-only scan actively misleading: the
     * row it needs to find is the one whose value column is empty.
     */
    public function test_a_cleared_key_is_found_in_oldvalue_alone(): void {
        $this->resetAfterTest(true);

        $this->log_row('local_saylorcode', 'jobeapikey', '', str_repeat('p', 64));

        $found = config_log_audit::find_exposed();
        $this->assertCount(1, $found);
        $this->assertSame('oldvalue', $found[0]['column']);
        $this->assertSame(64, $found[0]['length']);
    }

    /**
     * Redaction clears both columns of a rotation row.
     */
    public function test_redaction_covers_both_columns(): void {
        global $DB;
        $this->resetAfterTest(true);

        $id = $this->log_row('local_ai_course_assistant', 'apikey',
            str_repeat('n', 53), str_repeat('o', 53));

        $this->assertSame(2, config_log_audit::redact());
        $this->assertSame([], config_log_audit::find_exposed());

        $row = $DB->get_record('config_log', ['id' => $id]);
        $this->assertSame(config_log_audit::REDACTED, $row->value);
        $this->assertSame(config_log_audit::REDACTED, $row->oldvalue);
    }

    public function test_redaction_preserves_the_audit_trail(): void {
        global $DB;
        $this->resetAfterTest(true);

        $id = $this->log_row('local_saylorcode', 'jobeapikey', str_repeat('f', 64));

        $this->assertSame(1, config_log_audit::redact());
        $this->assertSame([], config_log_audit::find_exposed());

        $row = $DB->get_record('config_log', ['id' => $id]);
        $this->assertNotFalse($row, 'the row must survive redaction');
        $this->assertSame(config_log_audit::REDACTED, $row->value);
        $this->assertSame('jobeapikey', $row->name);
        $this->assertSame('local_saylorcode', $row->plugin);
    }

    /**
     * Redaction can be limited to named rows.
     */
    public function test_redaction_can_target_specific_rows(): void {
        $this->resetAfterTest(true);

        $one = $this->log_row('local_saylorcode', 'jobeapikey', str_repeat('g', 64));
        $two = $this->log_row('block_openai_chat', 'apikey', str_repeat('h', 64));

        $this->assertSame(1, config_log_audit::redact([$one]));

        $remaining = array_column(config_log_audit::find_exposed(), 'id');
        $this->assertSame([$two], $remaining);
    }

    /**
     * Setting names the audit has no other way to know are secrets: they hold an incoming-webhook URL or an
     * access key id, and no "key", "token" or "secret" word the name fragments look for.
     *
     * @return string[]
     */
    private function names_without_a_secret_word(): array {
        return ['anomaly_digest_slack_webhook', 'anomaly_digest_teams_webhook', 'soapbox_storage_key', 'spend_export_key'];
    }

    /**
     * A sentinel value for a secret setting: a URL for a webhook (whose token is in the path), else a key.
     *
     * @param string $name
     * @return string
     */
    private function sentinel_for(string $name): string {
        return (str_contains($name, 'webhook') && !str_contains($name, 'secret'))
            ? 'https://hooks.example/T000/B000/SENTINEL-AUDIT-' . $name
            : 'SENTINEL-AUDIT-' . $name . '-0123456789';
    }

    /**
     * Every setting in secrets::names() is a secret name, including the ones no name fragment matches, and
     * the public-key and token-count names stay exempt.
     */
    public function test_every_listed_secret_setting_is_a_secret_name(): void {
        foreach (secrets::names() as $name) {
            $this->assertTrue(config_log_audit::is_secret_name($name), "$name is a listed secret");
        }
        foreach ($this->names_without_a_secret_word() as $name) {
            $this->assertTrue(secrets::is_secret($name), "$name is listed in secrets::names()");
            $this->assertTrue(config_log_audit::is_secret_name($name), "$name has no secret word and is still a secret");
        }
        foreach (['policy_bundle_pubkey', 'token_analytics_link', 'backend_context_tokens', 'max_tokens', 'maxtokens',
                'tokenexpiry'] as $name) {
            $this->assertFalse(secrets::is_secret($name), "$name is not a listed secret");
            $this->assertFalse(config_log_audit::is_secret_name($name), "$name is exempt");
        }
        // Names that are neither listed nor look like a secret stay out, so the list didn't turn into "everything".
        foreach (['model', 'provider', 'comparison_providers', 'voice_providers', 'enabled'] as $name) {
            $this->assertFalse(config_log_audit::is_secret_name($name), "$name is not a secret name");
        }
    }

    /**
     * Before 7.9.0 these settings logged their value (a URL, an access key id) or their ciphertext, and the
     * audit could not see the rows because the name has no secret-looking word.
     */
    public function test_rows_for_every_listed_secret_setting_are_found_in_either_column(): void {
        $this->resetAfterTest(true);
        $expected = [];
        foreach (secrets::names() as $name) {
            $expected[$this->log_row('local_ai_course_assistant', $name, $this->sentinel_for($name))] = $name;
        }
        // A rotation row: a masked new value and the retired value in oldvalue.
        $rotated = $this->log_row('local_ai_course_assistant', 'spend_export_key', '********', 'sodium:' . str_repeat('Q', 40));
        // And rows that must stay out: masked or empty.
        $masked = $this->log_row('local_ai_course_assistant', 'soapbox_storage_key', '********');
        $empty = $this->log_row('local_ai_course_assistant', 'anomaly_digest_slack_webhook', '', '********');

        $found = config_log_audit::find_exposed();
        $ids = array_column($found, 'id');
        foreach ($expected as $id => $name) {
            $this->assertContains($id, $ids, "a row for $name is found");
        }
        $this->assertContains($rotated, $ids, 'a retired ciphertext in oldvalue is found');
        $this->assertNotContains($masked, $ids);
        $this->assertNotContains($empty, $ids);
        foreach ($found as $record) {
            $this->assertArrayNotHasKey('value', $record);
            $this->assertArrayNotHasKey('oldvalue', $record);
        }
        $this->assertStringNotContainsString('SENTINEL-AUDIT', json_encode($found), 'no value is in what is reported');
    }

    /**
     * The key column is the second column of both tables.
     */
    public function test_a_table_row_with_a_key_in_the_clear_or_encrypted_is_found(): void {
        $this->resetAfterTest(true);
        $plain = $this->log_row('local_ai_course_assistant', 'comparison_providers',
            "openai|SENTINEL-TABLE-PLAIN-KEY-0001|gpt-4o-mini\nollama||llama3");
        $cipher = $this->log_row('local_ai_course_assistant', 'voice_providers',
            'openai|sodium:' . str_repeat('Z', 48) . '|main|shimmer|nova');
        $retired = $this->log_row('local_ai_course_assistant', 'comparison_providers', "openai|********|gpt-4o-mini",
            "openai|SENTINEL-TABLE-RETIRED-KEY-0002|gpt-4o-mini");
        $found = config_log_audit::find_exposed();
        $byid = [];
        foreach ($found as $record) {
            $byid[$record['id']][] = $record['column'];
        }
        $this->assertSame(['value'], $byid[$plain] ?? null);
        $this->assertSame(['value'], $byid[$cipher] ?? null);
        $this->assertSame(['oldvalue'], $byid[$retired] ?? null, 'a retired key in the old value is found');
        $this->assertStringNotContainsString('SENTINEL', json_encode($found));
        $this->assertStringNotContainsString('sodium', json_encode($found));
        foreach ($found as $record) {
            $this->assertSame('local_ai_course_assistant', $record['plugin']);
            $this->assertArrayNotHasKey('value', $record);
        }
    }

    public function test_a_table_row_the_editor_has_already_masked_is_not_found(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest(true);
        // What the 7.9.0 editor writes to the log for these two tables, made by the editor's own redaction, so
        // a change to that format shows up here.
        $provider = admin_setting_provider_keys::redact_keys("openai|sodium:" . str_repeat('A', 40) . "|m|0.5|https://gw.example\n"
            . "claude|SENTINEL-NOT-FOUND-KEY-0003|m\nollama||llama3");
        $voice = admin_setting_voice_providers::redact_keys('openai|sodium:' . str_repeat('B', 40) . '|main|shimmer|nova');
        $this->assertStringContainsString('********', $provider);
        $this->assertStringNotContainsString('SENTINEL', $provider);
        $ids = [
            $this->log_row('local_ai_course_assistant', 'comparison_providers', $provider, $provider),
            $this->log_row('local_ai_course_assistant', 'voice_providers', $voice, $voice),
            // A key column of asterisks of any length, a keyless row, blank lines, a comment, an empty value.
            $this->log_row('local_ai_course_assistant', 'comparison_providers', "openai|****|m\n\n  \nollama||x\n# openai|SENTINEL-COMMENT-KEY-0004|m", ''),
            $this->log_row('local_ai_course_assistant', 'voice_providers', '', ''),
        ];
        $found = array_column(config_log_audit::find_exposed(), 'id');
        foreach ($ids as $id) {
            $this->assertNotContains($id, $found);
        }
    }

    public function test_one_unmasked_line_among_masked_ones_is_enough(): void {
        $this->resetAfterTest(true);
        $id = $this->log_row('local_ai_course_assistant', 'comparison_providers',
            "openai|********|m\r\nollama||x\r\nclaude|SENTINEL-ONE-LINE-KEY-0005|m\r\n");
        $this->assertContains($id, array_column(config_log_audit::find_exposed(), 'id'));
    }

    /**
     * Only this plugin's two tables are read as tables: another plugin's setting of the same name is not ours
     * to parse, or to overwrite.
     */
    public function test_a_table_setting_of_another_plugin_is_left_alone(): void {
        $this->resetAfterTest(true);
        $this->log_row('local_other', 'comparison_providers', 'openai|SENTINEL-OTHER-PLUGIN-KEY-0006|m');
        $this->log_row('local_ai_course_assistant', 'some_other_table', 'openai|SENTINEL-OTHER-SETTING-KEY-0007|m');
        $this->assertSame([], config_log_audit::find_exposed());
    }

    /**
     * Redacting a table row blanks the key column of each line and nothing else, so the history still says
     * which provider and model changed.
     */
    public function test_redacting_a_table_row_masks_only_the_key_column(): void {
        global $DB;
        $this->resetAfterTest(true);
        $value = "openai|SENTINEL-REDACT-KEY-0008|gpt-4o-mini|0.5|https://gw.example/v1\r\n"
            . "claude|sodium:" . str_repeat('C', 40) . "|claude-haiku-4-5\r\n"
            . "ollama||llama3|0.2|http://localhost:11434\r\n"
            . "gemini|SENTINEL-REDACT-TWO-COLUMNS-0013\r\n"
            . "# openai|SENTINEL-REDACT-COMMENT-0009|kept\r\n"
            . "xai|********|grok";
        $old = "openai|SENTINEL-REDACT-OLD-KEY-0010|gpt-4o-mini";
        $id = $this->log_row('local_ai_course_assistant', 'comparison_providers', $value, $old);
        $other = $this->log_row('local_saylorcode', 'jobeapikey', str_repeat('f', 64));

        $this->assertSame(2, config_log_audit::redact([$id]), 'both columns of the table row, and not the other row');

        $row = $DB->get_record('config_log', ['id' => $id]);
        $this->assertSame("openai|********|gpt-4o-mini|0.5|https://gw.example/v1\r\n"
            . "claude|********|claude-haiku-4-5\r\n"
            . "ollama||llama3|0.2|http://localhost:11434\r\n"
            . "gemini|********\r\n"
            . "# openai|SENTINEL-REDACT-COMMENT-0009|kept\r\n"
            . "xai|********|grok", $row->value);
        $this->assertSame('openai|********|gpt-4o-mini', $row->oldvalue);
        $this->assertSame(str_repeat('f', 64), $DB->get_field('config_log', 'value', ['id' => $other]), 'ids limit the redaction');
        $this->assertSame('comparison_providers', $row->name);
        $this->assertSame([$other], array_column(config_log_audit::find_exposed(), 'id'));
    }

    /**
     * A table row redacts only the column that is exposed; a masked old value is left as it is, and a value that
     * is not a table (every other setting) is still overwritten whole.
     */
    public function test_redacting_everything_handles_tables_and_other_settings_each_their_own_way(): void {
        global $DB;
        $this->resetAfterTest(true);
        $table = $this->log_row('local_ai_course_assistant', 'voice_providers', 'openai|SENTINEL-ALL-VOICE-KEY-0011|main|shimmer|nova',
            'openai|********|main|shimmer|nova');
        $hook = $this->log_row('local_ai_course_assistant', 'anomaly_digest_slack_webhook',
            'https://hooks.example/T000/B000/SENTINEL-ALL-HOOK-0012');
        $plain = $this->log_row('local_saylorcode', 'jobeapikey', str_repeat('g', 64));

        $this->assertSame(3, config_log_audit::redact());
        $this->assertSame([], config_log_audit::find_exposed());
        $this->assertSame('openai|********|main|shimmer|nova', $DB->get_field('config_log', 'value', ['id' => $table]));
        $this->assertSame('openai|********|main|shimmer|nova', $DB->get_field('config_log', 'oldvalue', ['id' => $table]));
        $this->assertSame(config_log_audit::REDACTED, $DB->get_field('config_log', 'value', ['id' => $hook]));
        $this->assertSame(config_log_audit::REDACTED, $DB->get_field('config_log', 'value', ['id' => $plain]));
    }

    /**
     * The CLI help says what it now covers. The text lives in the class so a test can read it; the script
     * must print it.
     */
    public function test_the_cli_help_mentions_the_two_tables_and_the_script_prints_it(): void {
        $help = config_log_audit::help();
        $this->assertStringContainsString('comparison_providers', $help);
        $this->assertStringContainsString('voice_providers', $help);
        $this->assertStringContainsString('key column', $help);
        $this->assertStringContainsString('--redact', $help);
        $this->assertStringContainsString('--ids', $help);
        $this->assertStringContainsString('No key value is printed', $help);
        $script = (string) file_get_contents(__DIR__ . '/../admin/cli/audit_config_log_secrets.php');
        $this->assertStringContainsString('config_log_audit::help()', $script, 'the script prints the help from the class');
    }
}
