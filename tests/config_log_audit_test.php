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
}
