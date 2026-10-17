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

    /** A table forced in config.php keeps its keys out of the database on purpose; the mirror must not copy one in. */
    public function test_a_table_forced_in_config_php_is_never_mirrored_into_apikey(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-DB-APIKEY-STAY-1111'));
        $CFG->forced_plugin_settings['local_ai_course_assistant']['comparison_providers'] =
            'openai|sk-proj-FORCED-TABLE-KEY-2222|o';
        try {
            $this->assertSame('sk-proj-FORCED-TABLE-KEY-2222', provider_keys::key_for('openai'), 'the forced row is live');
            $this->assertFalse(provider_keys::sync_default_key());
            $this->assertSame('sk-proj-DB-APIKEY-STAY-1111', secrets::get('apikey'), 'apikey is left as it was');
            $this->assertTrue(secrets::is_encrypted(get_config('local_ai_course_assistant', 'apikey')));
        } finally {
            unset($CFG->forced_plugin_settings['local_ai_course_assistant']['comparison_providers']);
        }
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

    /** The display-name check for the primary model lives in the pure function, not in the save callback (R13). */
    public function test_the_default_warnings_flag_a_display_name_primary_model(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('model', 'GPT-4o-mini');
        $this->cfg('comparison_providers', "openai|sk-proj-MODEL-WARN-0000-ABCD|m");
        $warnings = provider_keys::default_provider_warnings();
        $this->assertCount(1, $warnings, 'the key is fine; only the model is wrong');
        $this->assertStringContainsString('gpt-4o-mini', $warnings[0]);
        $this->assertStringNotContainsString('MODEL-WARN', $warnings[0]);
        $this->assertSame([], \core\notification::fetch(), 'building the warnings raises nothing');
    }

    public function test_the_save_callback_raises_nothing_for_a_display_name_primary_model(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('model', 'GPT-4o-mini');
        $this->cfg('comparison_providers', "openai|sk-proj-MODEL-WARN-0000-ABCD|m");
        provider_keys::after_settings_saved();
        $this->assertSame([], \core\notification::fetch(), 'a mid-save callback must not warn');
    }

    /** The primary model and a failover row are judged by the same function, so they say the same thing. */
    public function test_the_primary_model_and_a_failover_row_get_the_same_sentence(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('model', 'GPT-4o-mini');
        $this->cfg('comparison_providers', "openai|sk-proj-MODEL-WARN-0000-ABCD|GPT-4o-mini");
        $this->cfg('spend_failover_chain', 'chat:openai');
        $rowproblems = array_values(array_filter(failover_verifier::static_problems(),
            static fn($p) => str_contains($p, 'GPT-4o-mini')));
        $this->assertCount(1, $rowproblems);
        $this->assertSame([s($rowproblems[0])], provider_keys::default_provider_warnings());
    }

    public function test_a_primary_model_that_is_not_a_display_name_adds_no_warning(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('comparison_providers', "openai|sk-proj-MODEL-OK-0000-ABCD|m");
        $this->cfg('model', 'gpt-4o-mini');
        $this->assertSame([], provider_keys::default_provider_warnings(), 'a lowercase id');
        $this->cfg('model', '');
        $this->assertSame([], provider_keys::default_provider_warnings(), 'no model set');
        $this->cfg('model', 'GPT-4o-mini');
        $this->cfg('apibaseurl', 'https://gateway.example/v1');
        $this->assertSame([], provider_keys::default_provider_warnings(), 'a gateway takes any spelling');
        $this->cfg('apibaseurl', '');
        $this->cfg('provider', 'openrouter');
        $this->cfg('comparison_providers', "openrouter|sk-or-MODEL-OK-0000-ABCD|m");
        $this->cfg('model', 'Meta Llama');
        $this->assertSame([], provider_keys::default_provider_warnings(), 'openrouter model ids are not lowercase');
    }

    public function test_a_fine_tuned_primary_model_adds_no_warning(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('model', 'ft:gpt-4o-mini-2024-07-18:Acme-University::AbC123xYz');
        $this->cfg('comparison_providers', "openai|sk-proj-MODEL-OK-0000-ABCD|m");
        $this->assertSame([], provider_keys::default_provider_warnings());
    }

    public function test_the_model_warning_follows_the_key_warning_and_neither_changes_the_other(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('model', 'GPT-4o-mini');
        $this->cfg('comparison_providers', '');
        $warnings = provider_keys::default_provider_warnings();
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('no key is saved for it anywhere', $warnings[0]);
        $this->assertStringContainsString('gpt-4o-mini', $warnings[1]);

        $this->cfg('apikey', secrets::conceal('AIzaGEMINI-LEFTOVER-0000-1234'));
        $warnings = provider_keys::default_provider_warnings();
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('gemini', $warnings[0]);
        $this->assertStringContainsString('gpt-4o-mini', $warnings[1]);
    }

    public function test_a_site_with_no_default_provider_to_check_gets_no_model_warning(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'auto');
        $this->cfg('model', 'GPT-4o-mini');
        $this->cfg('comparison_providers', '');
        $this->assertSame('', provider_keys::site_provider());
        $this->assertSame([], provider_keys::default_provider_warnings(), 'core_ai with no key: nothing is sent to a vendor');
    }

    public function test_the_model_warning_escapes_what_the_admin_typed(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('model', 'GPT<script>x');
        $this->cfg('comparison_providers', "openai|sk-proj-MODEL-WARN-0000-ABCD|m");
        $warnings = provider_keys::default_provider_warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringNotContainsString('<script>', $warnings[0]);
        $this->assertStringContainsString('&lt;script&gt;', $warnings[0]);
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

    // Fix wave 3, F3.1: the default provider's keyed row sits under another base URL.

    /**
     * The admin moves to a gateway by editing the openai row (new base URL, new key) and saves. The row's URL
     * differs from the site's API Base URL, so the mirror copies nothing and chat keeps using the old key. The
     * page must say so instead of showing "Saved" and nothing else.
     */
    public function test_a_keyed_default_row_under_another_base_url_is_warned_about(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-LEGACY-KEY-AAAA-1111'));
        $this->cfg('comparison_providers', "openai|sk-proj-NEW-GATEWAY-KEY-BBBB-2222|m||https://gw.example.test/v1");
        $this->assertFalse(provider_keys::sync_default_key(), 'the mirror copies nothing');
        $this->assertSame('sk-proj-LEGACY-KEY-AAAA-1111', secrets::get('apikey'), 'so chat still uses the old key');
        $warnings = provider_keys::default_provider_warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('openai', $warnings[0]);
        $this->assertStringContainsString('has its own base URL', $warnings[0]);
        $this->assertStringContainsString('API Base URL', $warnings[0]);
        $this->assertStringContainsString('still uses the previous default key', $warnings[0]);
        $this->assertStringNotContainsString('sk-proj', $warnings[0]);
        $this->assertStringNotContainsString('gw.example.test', $warnings[0], 'names the provider, never a URL');
        $this->assertStringNotContainsString("\u{2014}", $warnings[0]);
        $this->assertSame([], \core\notification::fetch(), 'building the warnings raises nothing');
    }

    public function test_the_warning_also_fires_when_there_is_no_saved_default_key_at_all(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('comparison_providers', "openai|sk-proj-NEW-GATEWAY-KEY-BBBB-2222|m||https://gw.example.test/v1");
        $this->assertFalse(provider_keys::sync_default_key());
        $warnings = provider_keys::default_provider_warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('has its own base URL', $warnings[0]);
        $this->assertStringNotContainsString('no key is saved for it anywhere', $warnings[0], 'a keyed row does exist');
    }

    public function test_no_base_url_warning_when_the_row_and_the_site_use_the_same_url(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apibaseurl', ' https://gw.example.test/v1 ');
        $this->cfg('apikey', secrets::conceal('gateway-key-OLD-AAAA-1111'));
        $this->cfg('comparison_providers', "openai|gateway-key-NEW-BBBB-2222|m||https://gw.example.test/v1");
        $this->assertSame([], provider_keys::default_provider_warnings(), 'a gateway site, row under the same URL');
        $this->assertTrue(provider_keys::sync_default_key(), 'and that row is the one the mirror uses');
    }

    public function test_no_base_url_warning_when_neither_the_row_nor_the_site_has_one(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-LEGACY-KEY-AAAA-1111'));
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-KEY-ROW-KEY-3333|m");
        $this->assertSame([], provider_keys::default_provider_warnings());
    }

    /** A matching row anywhere in the table is the mirror's row, so a row for another URL listed first changes nothing. */
    public function test_no_base_url_warning_when_a_later_row_matches_the_site_url(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-LEGACY-KEY-AAAA-1111'));
        $this->cfg('comparison_providers', "openai|sk-proj-AZURE-ROW-KEY-4444|m||https://azure.example.test\n"
            . "openai|sk-proj-PLAIN-ROW-KEY-5555|m");
        $this->assertSame([], provider_keys::default_provider_warnings());
        $this->assertTrue(provider_keys::sync_default_key());
        $this->assertSame('sk-proj-PLAIN-ROW-KEY-5555', secrets::get('apikey'));
    }

    /** The row names a gateway of its own and the site names another: both are base URLs, and they differ. */
    public function test_a_gateway_site_whose_row_names_another_gateway_is_warned_about(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apibaseurl', 'https://gw.example.test/v1');
        $this->cfg('apikey', secrets::conceal('gateway-key-OLD-AAAA-1111'));
        $this->cfg('comparison_providers', "openai|gateway-key-NEW-BBBB-2222|m||https://gw2.example.test/v1");
        $this->assertFalse(provider_keys::sync_default_key());
        $warnings = provider_keys::default_provider_warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('has its own base URL', $warnings[0]);
    }

    /**
     * A plain vendor row on a gateway site has no base URL of its own, so "the row has its own base URL" would be
     * untrue; the mirror still skips it (a vendor key is never copied into a gateway site), and no sentence is raised.
     */
    public function test_a_plain_vendor_row_on_a_gateway_site_raises_no_base_url_sentence(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apibaseurl', 'https://gw.example.test/v1');
        $this->cfg('apikey', secrets::conceal('gateway-key-OLD-AAAA-1111'));
        $this->cfg('comparison_providers', "openai|sk-proj-REAL-VENDOR-KEY-9999-0000|m");
        $this->assertFalse(provider_keys::sync_default_key());
        $this->assertSame('gateway-key-OLD-AAAA-1111', secrets::get('apikey'));
        $this->assertSame([], provider_keys::default_provider_warnings());
    }

    /**
     * A key or a table forced in config.php switches the mirror off by design (sync_default_key() returns early), so
     * "make the two match, or clear the row's base URL" would be a false alarm there, and clearing the URL would break
     * a legitimate same-vendor failover row.
     */
    public function test_the_base_url_warning_is_off_when_apikey_is_forced_in_config_php(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('comparison_providers', "openai|sk-proj-NEW-GATEWAY-KEY-BBBB-2222|m||https://gw.example.test/v1");
        $this->assertCount(1, provider_keys::default_provider_warnings(), 'unforced: the sentence is raised');
        $CFG->forced_plugin_settings['local_ai_course_assistant']['apikey'] = 'sk-proj-FORCED-KEY-4444-0000';
        try {
            $this->assertFalse(provider_keys::sync_default_key(), 'the mirror is off');
            $this->assertSame([], provider_keys::default_provider_warnings());
        } finally {
            unset($CFG->forced_plugin_settings['local_ai_course_assistant']['apikey']);
        }
        $this->assertCount(1, provider_keys::default_provider_warnings(), 'unforced again: the sentence is back');
    }

    public function test_the_base_url_warning_is_off_when_the_table_is_forced_in_config_php(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-LEGACY-KEY-AAAA-1111'));
        $row = 'openai|sk-proj-FORCED-TABLE-KEY-2222|m||https://gw.example.test/v1';
        $this->cfg('comparison_providers', $row);
        $this->assertCount(1, provider_keys::default_provider_warnings(), 'unforced: the sentence is raised');
        $CFG->forced_plugin_settings['local_ai_course_assistant']['comparison_providers'] = $row;
        try {
            $this->assertFalse(provider_keys::sync_default_key(), 'the mirror is off');
            $this->assertSame([], provider_keys::default_provider_warnings());
        } finally {
            unset($CFG->forced_plugin_settings['local_ai_course_assistant']['comparison_providers']);
        }
        $this->assertCount(1, provider_keys::default_provider_warnings(), 'unforced again: the sentence is back');
    }

    /** Forced config only silences the new sentence; the other two key warnings are as before. */
    public function test_a_forced_apikey_does_not_silence_the_other_key_warnings(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('comparison_providers', '');
        $CFG->forced_plugin_settings['local_ai_course_assistant']['apikey'] = 'AIzaGEMINI-FORCED-0000-1234';
        try {
            $warnings = provider_keys::default_provider_warnings();
            $this->assertCount(1, $warnings);
            $this->assertStringContainsString('gemini', $warnings[0], 'the vendor-mismatch sentence still fires');
        } finally {
            unset($CFG->forced_plugin_settings['local_ai_course_assistant']['apikey']);
        }
    }

    public function test_no_base_url_warning_for_a_keyless_provider_or_auto_without_a_key(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'ollama');
        $this->cfg('comparison_providers', "ollama|some-proxy-key-1234|llama3||http://localhost:11434");
        $this->assertSame([], provider_keys::default_provider_warnings(), 'a keyless provider');
        $this->cfg('provider', 'auto');
        $this->cfg('model', 'gpt-4o-mini');
        $this->cfg('comparison_providers', "openai|sk-proj-NEW-GATEWAY-KEY-BBBB-2222|m||https://gw.example.test/v1");
        $this->assertSame('', provider_keys::site_provider());
        $this->assertSame([], provider_keys::default_provider_warnings(), 'auto with no saved key is core_ai');
    }

    /** A key that cannot be read is not a keyed row for this purpose: the existing no-key sentence stands. */
    public function test_an_unreadable_row_under_another_base_url_gets_the_no_key_sentence(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('comparison_providers',
            "openai|sodium:AAAAnotarealciphertextAAAAAAAAAAAAAAAAAAAAAAAA|m||https://gw.example.test/v1");
        $warnings = provider_keys::default_provider_warnings();
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('no key is saved for it anywhere', $warnings[0]);
    }

    public function test_the_base_url_warning_is_raised_once_through_the_save_report(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-LEGACY-KEY-AAAA-1111'));
        $this->cfg('comparison_providers', "openai|sk-proj-NEW-GATEWAY-KEY-BBBB-2222|m||https://gw.example.test/v1");
        $this->assertSame(1, settings_save_report::emit_default_provider_warnings(['comparison_providers' => ['', '']]));
        $notes = \core\notification::fetch();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('has its own base URL', $notes[0]->get_message());
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
