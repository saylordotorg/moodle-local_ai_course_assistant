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

    /** The row has a key on purpose: only the KEYLESS guard stops it being copied. */
    public function test_a_keyless_default_provider_is_never_synced(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'ollama');
        $this->cfg('apikey', secrets::conceal('site-key-KEEP-ME-0001-2222'));
        $this->cfg('comparison_providers', "ollama|some-proxy-key-1234|llama3");
        $this->assertFalse(provider_keys::sync_default_key());
        $this->assertSame('site-key-KEEP-ME-0001-2222', secrets::get('apikey'));
    }

    public function test_a_gateway_site_is_never_given_a_vendor_key_from_a_row_without_a_base_url(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apibaseurl', 'https://gateway.example.test/v1');
        $this->cfg('apikey', secrets::conceal('gateway-key-GATEWAY-0000-1111'));
        $this->cfg('comparison_providers', "openai|sk-proj-REAL-VENDOR-KEY-9999-0000|gpt-4o-mini");
        $this->assertFalse(provider_keys::sync_default_key());
        $this->assertSame('gateway-key-GATEWAY-0000-1111', secrets::get('apikey'));
    }

    public function test_auto_with_a_gateway_never_takes_the_key_of_a_row_without_a_base_url(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'auto');
        $this->cfg('model', 'gpt-4o-mini');
        $this->cfg('apibaseurl', 'https://gateway.example.test/v1');
        $this->cfg('apikey', secrets::conceal('gateway-key-GATEWAY-0000-1111'));
        $this->cfg('comparison_providers', "openai|sk-proj-REAL-VENDOR-KEY-9999-0000|gpt-4o-mini");
        $this->assertSame('openai', provider_keys::site_provider());
        $this->assertFalse(provider_keys::sync_default_key());
        $this->assertSame('gateway-key-GATEWAY-0000-1111', secrets::get('apikey'));
    }

    public function test_a_row_with_the_same_base_url_as_the_site_is_synced(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'custom');
        $this->cfg('apibaseurl', ' https://gateway.example.test/v1 ');
        $this->cfg('apikey', secrets::conceal('old-gateway-key-AAAA-1111'));
        $this->cfg('comparison_providers', "custom|new-gateway-key-BBBB-2222|m||https://gateway.example.test/v1");
        $this->assertTrue(provider_keys::sync_default_key());
        $this->assertSame('new-gateway-key-BBBB-2222', secrets::get('apikey'));
    }

    public function test_a_row_with_a_base_url_is_not_used_when_the_site_has_none(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-SITE-KEY-KEEP-3333-4444'));
        $this->cfg('comparison_providers', "openai|sk-proj-OTHER-GW-KEY-5555-6666|m||https://other.example.test/v1");
        $this->assertFalse(provider_keys::sync_default_key());
        $this->assertSame('sk-proj-SITE-KEY-KEEP-3333-4444', secrets::get('apikey'));
    }

    /** R15: the first keyed row for the provider is not always the row for this destination. */
    public function test_a_keyed_vendor_row_listed_before_the_gateway_row_does_not_block_mirroring(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apibaseurl', 'https://gateway.example.test/v1');
        $this->cfg('apikey', secrets::conceal('old-gateway-key-AAAA-1111'));
        $this->cfg('comparison_providers', "openai|sk-proj-REAL-VENDOR-KEY-9999-0000|gpt-4o-mini\n"
            . "openai|new-gateway-key-BBBB-2222|m||https://gateway.example.test/v1");
        $this->assertTrue(provider_keys::sync_default_key());
        $this->assertSame('new-gateway-key-BBBB-2222', secrets::get('apikey'));
        $this->assertFalse(provider_keys::sync_default_key(), 'second run changes nothing');
    }

    public function test_a_row_that_cannot_be_decrypted_does_not_block_a_later_row_with_a_good_key(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-OLD-KEY-OLD-KEY-1111'));
        $this->cfg('comparison_providers', "openai|sodium:AAAAnotarealciphertextAAAAAAAAAAAAAAAAAAAAAAAA|o\n"
            . "openai|sk-proj-GOOD-ROW-KEY-GOOD-2222|o");
        $this->assertTrue(provider_keys::sync_default_key());
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertSame('sk-proj-GOOD-ROW-KEY-GOOD-2222', secrets::get('apikey'));
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

    public function test_the_default_warning_names_the_providers_when_the_key_is_another_vendors(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('AIzaGEMINI-LEFTOVER-0000-1234'));
        $this->cfg('comparison_providers', '');
        $warnings = provider_keys::default_provider_warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('gemini', $warnings[0]);
        $this->assertStringContainsString('openai', $warnings[0]);
        $this->assertStringNotContainsString('AIzaGEMINI', $warnings[0]);
        $this->assertSame([], \core\notification::fetch(), 'building the warnings raises nothing');
    }

    public function test_the_save_callback_syncs_but_never_raises_a_notice(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('AIzaGEMINI-LEFTOVER-0000-1234'));
        $this->cfg('comparison_providers', '');
        provider_keys::after_settings_saved();
        $this->assertSame([], \core\notification::fetch(), 'a mid-save callback must not warn');

        $this->cfg('comparison_providers', "openai|sk-proj-SYNCED-KEY-1111-2222|o");
        provider_keys::after_settings_saved('comparison_providers');
        $this->assertSame('sk-proj-SYNCED-KEY-1111-2222', secrets::get('apikey'));
        $this->assertSame([], \core\notification::fetch());
    }

    public function test_no_warning_when_the_default_provider_has_a_keyed_row(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-KEY-ROW-KEY-1111|o");
        $this->assertSame([], provider_keys::default_provider_warnings());
    }

    public function test_no_warning_when_the_saved_key_belongs_to_the_default_provider(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-LEGACY-KEY-2222-3333'));
        $this->cfg('comparison_providers', '');
        $this->assertSame([], provider_keys::default_provider_warnings());
    }

    public function test_no_warning_for_a_keyless_default_provider(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'ollama');
        $this->cfg('comparison_providers', '');
        $this->assertSame([], provider_keys::default_provider_warnings());
    }

    public function test_the_no_key_warning_when_nothing_is_saved_anywhere(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('comparison_providers', '');
        $warnings = provider_keys::default_provider_warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('openai', $warnings[0]);
        $this->assertStringContainsString('no key is saved for it anywhere', $warnings[0]);
    }

    public function test_a_custom_provider_with_a_base_url_and_no_key_is_not_warned_about(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'custom');
        $this->cfg('apibaseurl', 'https://selfhosted.example.test/v1');
        $this->cfg('comparison_providers', '');
        $this->assertSame([], provider_keys::default_provider_warnings());
    }

    public function test_a_custom_provider_without_a_base_url_or_key_is_warned_about(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'custom');
        $this->cfg('comparison_providers', '');
        $this->assertCount(1, provider_keys::default_provider_warnings());
    }

    public function test_a_row_whose_key_cannot_be_decrypted_counts_as_no_key(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('comparison_providers', "openai|sodium:AAAAnotarealciphertextAAAAAAAAAAAAAAAAAAAAAAAA|o");
        $warnings = provider_keys::default_provider_warnings();
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('no key is saved for it anywhere', $warnings[0]);
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

    public function test_from_choices_lists_own_first_then_only_providers_with_a_key(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "openai|sk-proj-CHOICE-KEY-1111-2222|m\nollama||x");
        $choices = provider_keys::from_choices();
        $this->assertSame(['own', 'openai'], array_keys($choices));
        $this->assertSame('own', array_key_first($choices));
    }
}
