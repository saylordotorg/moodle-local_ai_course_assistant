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
 * apikey mirrors the default provider's row key, so the places that read apikey need no change.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider_keys
 */
final class provider_keys_sync_test extends \advanced_testcase {

    private function cfg(string $name, string $value): void {
        set_config($name, $value, 'local_ai_course_assistant');
    }

    public function test_sync_copies_the_default_providers_row_key_into_apikey(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-OLD-KEY-OLD-KEY-1111'));
        $this->cfg('comparison_providers', "openai|sk-proj-NEW-KEY-NEW-KEY-2222|gpt-4o-mini");
        $this->assertTrue(provider_keys::sync_default_key());
        $this->assertSame('sk-proj-NEW-KEY-NEW-KEY-2222', secrets::get('apikey'));
        $this->assertTrue(secrets::is_encrypted(get_config('local_ai_course_assistant', 'apikey')));
        $this->assertFalse(provider_keys::sync_default_key(), 'second run changes nothing');
    }

    public function test_switching_the_default_picks_the_new_providers_key_not_the_old_one(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('comparison_providers', "gemini|AIzaGEMINI-KEY-0000-1234|g\nopenai|sk-proj-OPENAI-KEY-5555-6789|o");
        provider_keys::sync_default_key();
        $this->assertSame('AIzaGEMINI-KEY-0000-1234', secrets::get('apikey'));
        $this->cfg('provider', 'openai');
        provider_keys::sync_default_key();
        $this->assertSame('sk-proj-OPENAI-KEY-5555-6789', secrets::get('apikey'));
    }

    public function test_no_keyed_row_for_the_default_leaves_apikey_alone(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-KEEP-THIS-KEY-3333'));
        $this->cfg('comparison_providers', "gemini|AIzaGEMINI-KEY-0000-1234|g");
        $this->assertFalse(provider_keys::sync_default_key());
        $this->assertSame('sk-proj-KEEP-THIS-KEY-3333', secrets::get('apikey'));
    }

    /** Review focus 4. */
    public function test_auto_with_no_legacy_key_never_starts_using_a_direct_provider(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'auto');
        $this->cfg('model', 'gemini-3.1-flash-lite');
        $this->cfg('comparison_providers', "gemini|AIzaGEMINI-KEY-0000-1234|g");
        $this->assertSame('', provider_keys::site_provider());
        $this->assertFalse(provider_keys::sync_default_key());
        $this->assertSame('', secrets::get('apikey'));
    }

    public function test_auto_with_a_key_follows_the_model_name(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'auto');
        $this->cfg('model', 'gemini-3.1-flash-lite');
        $this->cfg('apikey', secrets::conceal('AIzaOLD-GEMINI-0000-9999'));
        $this->cfg('comparison_providers', "gemini|AIzaNEW-GEMINI-0000-8888|g");
        $this->assertSame('gemini', provider_keys::site_provider());
        $this->assertTrue(provider_keys::sync_default_key());
        $this->assertSame('AIzaNEW-GEMINI-0000-8888', secrets::get('apikey'));
    }

    public function test_a_keyless_default_provider_is_never_synced(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'ollama');
        $this->cfg('comparison_providers', "ollama||llama3");
        $this->assertFalse(provider_keys::sync_default_key());
    }

    public function test_a_key_forced_in_config_php_is_never_written(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->forced_plugin_settings['local_ai_course_assistant']['apikey'] = 'sk-proj-FORCED-KEY-4444-0000';
        $this->cfg('provider', 'openai');
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-KEY-ROW-KEY-7777|o");
        $this->assertFalse(provider_keys::sync_default_key());
        unset($CFG->forced_plugin_settings['local_ai_course_assistant']['apikey']);
    }

    public function test_the_save_callback_warns_when_the_new_default_has_no_row_and_the_key_is_another_vendors(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('AIzaGEMINI-LEFTOVER-0000-1234'));
        $this->cfg('comparison_providers', '');
        provider_keys::after_settings_saved();
        $notices = \core\notification::fetch();
        $this->assertCount(1, $notices);
        $text = $notices[0]->get_message();
        $this->assertStringContainsString('gemini', $text);
        $this->assertStringContainsString('openai', $text);
        $this->assertStringNotContainsString('AIzaGEMINI', $text);
    }

    public function test_key_from_setting_returns_the_chosen_rows_key_or_nothing(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "openai|sk-proj-EMBED-ROW-KEY-1212-3434|o");
        $this->assertSame('', provider_keys::key_from_setting('embed_key_from'));
        $this->cfg('embed_key_from', 'own');
        $this->assertSame('', provider_keys::key_from_setting('embed_key_from'));
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame('sk-proj-EMBED-ROW-KEY-1212-3434', provider_keys::key_from_setting('embed_key_from'));
        $this->cfg('embed_key_from', 'gemini');
        $this->assertSame('', provider_keys::key_from_setting('embed_key_from'));
    }
}
