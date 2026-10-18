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

use local_ai_course_assistant\embedding_provider\base_embedding_provider;
use local_ai_course_assistant\embedding_provider\openai_embedding_provider;
use local_ai_course_assistant\embedding_provider\voyage_reranker;
use local_ai_course_assistant\tests\settings_tree;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/settings_tree.php');

/**
 * Embedding, Rerank and Voice can use a provider row's key, and nothing else turns voice on.
 *
 * Every key in here is a sentinel; nothing prints one.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class provider_key_sources_test extends \advanced_testcase {

    private function cfg(string $name, string $value): void {
        set_config($name, $value, 'local_ai_course_assistant');
    }

    public function test_embedding_uses_the_chosen_rows_key_and_falls_back_to_its_own(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-FOR-EMBED-1111-AAAA|m");
        $this->cfg('embed_apikey', secrets::conceal('sk-proj-OWN-EMBED-KEY-2222-BBBB'));
        $this->assertSame('sk-proj-OWN-EMBED-KEY-2222-BBBB', (new openai_embedding_provider())->resolved_key());
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame('sk-proj-ROW-FOR-EMBED-1111-AAAA', (new openai_embedding_provider())->resolved_key());
    }

    public function test_embedding_falls_back_to_its_own_key_when_the_chosen_row_is_gone_or_has_no_key(): void {
        $this->resetAfterTest();
        $this->cfg('embed_apikey', secrets::conceal('sk-proj-OWN-EMBED-KEY-2222-BBBB'));
        $this->cfg('embed_key_from', 'openai');
        $this->cfg('comparison_providers', '');
        $this->assertSame('sk-proj-OWN-EMBED-KEY-2222-BBBB', (new openai_embedding_provider())->resolved_key(), 'no row');
        $this->cfg('comparison_providers', "openai||m");
        $this->assertSame('sk-proj-OWN-EMBED-KEY-2222-BBBB', (new openai_embedding_provider())->resolved_key(), 'keyless row');
        $this->cfg('embed_key_from', 'own');
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-FOR-EMBED-1111-AAAA|m");
        $this->assertSame('sk-proj-OWN-EMBED-KEY-2222-BBBB', (new openai_embedding_provider())->resolved_key(), 'own chosen');
    }

    /** A migration builds the target provider with its own key; the chosen row must not replace it. */
    public function test_a_migration_override_of_the_embedding_key_beats_the_chosen_row(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-FOR-EMBED-1111-AAAA|m");
        $this->cfg('embed_apikey', secrets::conceal('sk-proj-OWN-EMBED-KEY-2222-BBBB'));
        $this->cfg('embed_key_from', 'openai');
        $migrating = base_embedding_provider::create_from_config(['embed_apikey' => 'sk-proj-MIGRATION-TARGET-9999-ZZZZ']);
        $this->assertSame('sk-proj-MIGRATION-TARGET-9999-ZZZZ', $migrating->resolved_key());
        // A migration with no key of its own leaves the live choice in charge, and the override is gone after.
        $nokey = base_embedding_provider::create_from_config(['embed_model' => 'x']);
        $this->assertSame('sk-proj-ROW-FOR-EMBED-1111-AAAA', $nokey->resolved_key());
        $this->assertSame('sk-proj-ROW-FOR-EMBED-1111-AAAA', base_embedding_provider::create_from_config()->resolved_key());
    }

    /** The RAG admin card asks the same question the provider does. */
    public function test_the_embedding_status_key_is_the_one_the_provider_will_use(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-FOR-EMBED-1111-AAAA|m");
        $this->assertSame('', base_embedding_provider::resolve_apikey(), 'nothing set anywhere');
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame('sk-proj-ROW-FOR-EMBED-1111-AAAA', base_embedding_provider::resolve_apikey());
        $this->assertSame(base_embedding_provider::resolve_apikey(), (new openai_embedding_provider())->resolved_key());
    }

    public function test_rerank_uses_its_row_then_its_own_then_the_embedding_key(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "voyage|pa-VOYAGE-ROW-KEY-3333-CCCC|rerank-2.5");
        $this->cfg('embed_apikey', secrets::conceal('pa-EMBED-KEY-4444-DDDD'));
        $this->assertSame('pa-EMBED-KEY-4444-DDDD', (new voyage_reranker())->resolved_key(), 'the embedding key');
        $this->cfg('rerank_apikey', secrets::conceal('pa-OWN-RERANK-KEY-5555-EEEE'));
        $this->assertSame('pa-OWN-RERANK-KEY-5555-EEEE', (new voyage_reranker())->resolved_key(), 'its own beats embedding');
        $this->cfg('rerank_key_from', 'voyage');
        $this->assertSame('pa-VOYAGE-ROW-KEY-3333-CCCC', (new voyage_reranker())->resolved_key(), 'a chosen row beats its own');
        $this->cfg('rerank_key_from', 'claude');
        $this->assertSame('pa-OWN-RERANK-KEY-5555-EEEE', (new voyage_reranker())->resolved_key(), 'a missing row changes nothing');
    }

    /** "The Embedding key" is whichever key Embedding really uses, so a site that moved it into a row keeps reranking. */
    public function test_the_rerank_fallback_is_the_key_embedding_resolves_to(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "voyage|pa-VOYAGE-ROW-KEY-3333-CCCC|rerank-2.5");
        $this->cfg('embed_key_from', 'voyage');
        $reranker = new voyage_reranker();
        $this->assertSame('pa-VOYAGE-ROW-KEY-3333-CCCC', $reranker->resolved_key());
        $this->assertTrue($reranker->is_configured());
    }

    public function test_voice_uses_the_chosen_row_for_the_realtime_key_only_when_asked(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('comparison_providers', "openai|sk-proj-FAILOVER-ONLY-5555-EEEE|m");
        $this->assertFalse(voice_registry::is_configured(), 'an OpenAI failover row must not turn voice on');
        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_TTS));
        $this->assertFalse(voice_registry::any_voice_enabled());
        $this->cfg('realtime_key_from', 'openai');
        $this->assertTrue(voice_registry::is_configured());
        $this->assertSame('sk-proj-FAILOVER-ONLY-5555-EEEE', voice_registry::resolve(voice_registry::CAPABILITY_TTS)['apikey']);
        $this->assertSame('openai', voice_registry::resolve(voice_registry::CAPABILITY_TTS)['provider']);
    }

    public function test_voice_does_not_turn_on_from_a_choice_with_no_usable_row(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('realtime_key_from', 'openai');
        $this->assertFalse(voice_registry::is_configured(), 'the row is not there');
        $this->cfg('comparison_providers', "openai||m");
        $this->assertFalse(voice_registry::is_configured(), 'the row has no key');
        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_TTS));
    }

    /** The voice endpoints are OpenAI's, so only an OpenAI row can be the voice key; any other vendor's key stays put. */
    public function test_voice_never_sends_another_vendors_row_key_to_openai(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('comparison_providers', "claude|sk-ant-NOT-A-VOICE-KEY-7777-GGGG|m\nxai|xai-NOT-OPENAI-8888-HHHH|m");
        foreach (['claude', 'xai', 'gemini'] as $from) {
            $this->cfg('realtime_key_from', $from);
            $this->assertFalse(voice_registry::is_configured(), "a {$from} row is not a voice key");
            $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_TTS));
        }
    }

    public function test_voice_keeps_its_own_pasted_key_unless_a_row_is_chosen(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('realtime_apikey', secrets::conceal('sk-proj-OWN-VOICE-KEY-1010-IIII'));
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-VOICE-KEY-2020-JJJJ|m");
        $this->assertTrue(voice_registry::is_configured());
        $this->assertSame('sk-proj-OWN-VOICE-KEY-1010-IIII', voice_registry::resolve(voice_registry::CAPABILITY_TTS)['apikey']);
        $this->cfg('realtime_key_from', 'openai');
        $this->assertSame('sk-proj-ROW-VOICE-KEY-2020-JJJJ', voice_registry::resolve(voice_registry::CAPABILITY_TTS)['apikey']);
        $this->cfg('realtime_key_from', 'own');
        $this->assertSame('sk-proj-OWN-VOICE-KEY-1010-IIII', voice_registry::resolve(voice_registry::CAPABILITY_TTS)['apikey']);
    }

    /**
     * The help says what a voice key turns on, and the chips it names are the ones the code gates on the same key
     * (starter_manager shows a 'tts' starter only when voice_registry::is_configured()).
     */
    public function test_the_voice_help_names_what_a_key_turns_on(): void {
        $this->resetAfterTest();
        $chips = [];
        foreach (starter_manager::get_global_starters() as $starter) {
            if (($starter['conditional'] ?? '') === 'tts') {
                $chips[] = (string) $starter['name'];
            }
        }
        $this->assertNotEmpty($chips, 'a starter gated on TTS exists');
        $keys = ['settings:realtime_apikey_desc', 'settings:realtime_key_from_desc', 'settings:voice_providers_heading_desc'];
        foreach ($keys as $key) {
            $text = get_string($key, 'local_ai_course_assistant');
            $this->assertStringContainsString('speak button', $text, $key);
            $this->assertStringContainsString('speech-to-text', $text, $key);
            foreach ($chips as $chip) {
                $this->assertStringContainsString($chip, $text, $key);
            }
        }
    }

    /**
     * realtime_key_from and realtime_apikey sit in the Voice Mode dependency list, so they are hidden while Voice Mode
     * is off. Their help must not say they can be used then; it says the effects outlast Voice Mode instead.
     */
    public function test_the_help_of_the_hidden_voice_fields_never_says_they_work_with_voice_mode_off(): void {
        $this->resetAfterTest();
        foreach (['settings:realtime_apikey_desc', 'settings:realtime_key_from_desc'] as $key) {
            $text = get_string($key, 'local_ai_course_assistant');
            $this->assertStringNotContainsString('even when Voice Mode is off', $text, $key);
            $this->assertStringNotContainsString('Voice Mode is off', $text, $key);
            $this->assertStringContainsString('stay on if Voice Mode is later switched off', $text, $key);
        }
        // The Voice providers table is not hidden with Voice Mode, so its help may say so.
        $this->assertStringContainsString('even when Voice Mode is off',
            get_string('settings:voice_providers_heading_desc', 'local_ai_course_assistant'));
    }

    public function test_the_choices_list_own_plus_every_keyed_provider(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "openai|sk-proj-CHOICE-ONE-6666-FFFF|m\nollama||x");
        $choices = provider_keys::from_choices();
        $this->assertSame(['own', 'openai'], array_keys($choices));
    }

    /**
     * The three selects are on the settings tree, default to "own", and the voice one offers only an OpenAI row.
     */
    public function test_the_three_selects_are_on_the_settings_tree(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->cfg('comparison_providers', "openai|sk-proj-TREE-ROW-ONE-3030-KKKK|m\nclaude|sk-ant-TREE-ROW-TWO-4040-LLLL|m");

        $found = [];
        foreach (settings_tree::pages(\admin_get_root(true, true)->locate('local_ai_course_assistant')) as $page) {
            foreach ($page->settings as $setting) {
                if (in_array($setting->name, ['embed_key_from', 'rerank_key_from', 'realtime_key_from'], true)) {
                    $found[$setting->name] = $setting;
                }
            }
        }
        $this->assertSame(['embed_key_from', 'rerank_key_from', 'realtime_key_from'], array_keys($found), 'on the tree, once each');
        foreach ($found as $name => $setting) {
            $this->assertInstanceOf(\admin_setting_configselect::class, $setting, $name);
            $this->assertSame('own', $setting->get_defaultsetting(), $name);
            $setting->load_choices();
            $this->assertSame('own', array_key_first($setting->choices), $name);
        }
        $this->assertSame(['own', 'openai', 'claude'], array_keys($found['embed_key_from']->choices));
        $this->assertSame(['own', 'openai', 'claude'], array_keys($found['rerank_key_from']->choices));
        $this->assertSame(['own', 'openai'], array_keys($found['realtime_key_from']->choices), 'voice endpoints are OpenAI only');
    }

    // Fix round 1.

    /**
     * An embedding row for a vendor the key itself identifies as another one is skipped, because the key would go
     * to the wrong vendor's endpoint. The pasted key is used instead, silently.
     */
    public function test_embedding_skips_a_chosen_row_that_belongs_to_another_vendor(): void {
        $this->resetAfterTest();
        $this->cfg('embed_apikey', secrets::conceal('sk-proj-OWN-EMBED-KEY-2222-BBBB'));
        $this->cfg('comparison_providers', "claude|sk-ant-ROW-OTHER-VENDOR-1111-AAAA|m\nopenai|sk-proj-ROW-FOR-EMBED-3333-CCCC|m");
        $this->cfg('embed_key_from', 'claude');
        $this->assertSame('sk-proj-OWN-EMBED-KEY-2222-BBBB', (new openai_embedding_provider())->resolved_key(),
            'a Claude key is not an OpenAI embedding key');
        $this->assertSame('sk-proj-OWN-EMBED-KEY-2222-BBBB', base_embedding_provider::resolve_apikey());
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame('sk-proj-ROW-FOR-EMBED-3333-CCCC', (new openai_embedding_provider())->resolved_key(),
            'a row of the right vendor still wins');
    }

    /** The admin picks the openai row, then switches Embedding to Voyage: the OpenAI key must not follow. */
    public function test_a_stale_choice_never_sends_the_openai_row_key_to_voyage(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-FOR-EMBED-3333-CCCC|m");
        $this->cfg('embed_key_from', 'openai');
        $this->cfg('embed_provider', 'openai');
        $this->assertSame('sk-proj-ROW-FOR-EMBED-3333-CCCC', base_embedding_provider::create_from_config()->resolved_key());
        $this->cfg('embed_provider', 'voyage');
        $this->assertSame('', base_embedding_provider::create_from_config()->resolved_key(), 'no pasted key: no key');
        $this->assertSame('', base_embedding_provider::resolve_apikey());
        $this->cfg('embed_apikey', secrets::conceal('pa-OWN-VOYAGE-KEY-4444-DDDD'));
        $this->assertSame('pa-OWN-VOYAGE-KEY-4444-DDDD', base_embedding_provider::create_from_config()->resolved_key());
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-FOR-EMBED-3333-CCCC|m\nvoyage|pa-ROW-VOYAGE-5555-EEEE|m");
        $this->cfg('embed_key_from', 'voyage');
        $this->assertSame('pa-ROW-VOYAGE-5555-EEEE', base_embedding_provider::create_from_config()->resolved_key(),
            'the matching vendor row wins');
    }

    public function test_the_embedding_vendor_check_lets_an_unidentifiable_key_and_a_base_url_through(): void {
        $this->resetAfterTest();
        $this->cfg('embed_apikey', secrets::conceal('pa-OWN-VOYAGE-KEY-4444-DDDD'));
        $this->cfg('embed_provider', 'voyage');
        // A bare sk- is shared by many vendors, so it identifies nobody and is never rejected.
        $this->cfg('comparison_providers', "openai|sk-BARE-PREFIX-NO-OWNER-6666-FFFF|m");
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame('sk-BARE-PREFIX-NO-OWNER-6666-FFFF', base_embedding_provider::resolve_apikey());
        // A key with no known prefix at all.
        $this->cfg('comparison_providers', "openai|plain-looking-key-no-prefix-7777|m");
        $this->assertSame('plain-looking-key-no-prefix-7777', base_embedding_provider::resolve_apikey());
        // A gateway takes any key, so a vendor-looking key is used when a base URL is set.
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-FOR-EMBED-3333-CCCC|m");
        $this->assertSame('pa-OWN-VOYAGE-KEY-4444-DDDD', base_embedding_provider::resolve_apikey(), 'no base URL: skipped');
        $this->cfg('embed_apibaseurl', 'https://gateway.example/v1');
        $this->assertSame('sk-proj-ROW-FOR-EMBED-3333-CCCC', base_embedding_provider::resolve_apikey(), 'base URL: unchecked');
    }

    /** A migration builds the target with its own key; the vendor check judges the TARGET provider, not the live one. */
    public function test_a_migration_to_voyage_does_not_take_the_openai_row_key(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-FOR-EMBED-3333-CCCC|m");
        $this->cfg('embed_key_from', 'openai');
        $keyless = base_embedding_provider::create_from_config(['embed_provider' => 'voyage']);
        $this->assertSame('', $keyless->resolved_key(), 'the OpenAI row key is not offered to a Voyage target');
    }

    public function test_embedding_falls_back_to_its_own_key_when_the_chosen_row_cannot_be_decrypted(): void {
        $this->resetAfterTest();
        $this->cfg('embed_apikey', secrets::conceal('sk-proj-OWN-EMBED-KEY-2222-BBBB'));
        $this->cfg('comparison_providers', "openai|sodium:AAAAnotarealciphertextAAAAAAAAAAAAAAAAAAAAAAAA|m");
        $this->cfg('embed_key_from', 'openai');
        $resolved = (new openai_embedding_provider())->resolved_key();
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertSame('sk-proj-OWN-EMBED-KEY-2222-BBBB', $resolved);
    }

    /** Rerank sends its key to Voyage, so a row the key identifies as another vendor is skipped, onward to the next source. */
    public function test_rerank_skips_a_chosen_row_that_belongs_to_another_vendor(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-FOR-RERANK-8888-GGGG|m\nvoyage|pa-VOYAGE-ROW-KEY-3333-CCCC|m");
        $this->cfg('embed_apikey', secrets::conceal('pa-EMBED-KEY-4444-DDDD'));
        $this->cfg('rerank_key_from', 'openai');
        $this->assertSame('pa-EMBED-KEY-4444-DDDD', (new voyage_reranker())->resolved_key(), 'onward to the embedding key');
        $this->cfg('rerank_apikey', secrets::conceal('pa-OWN-RERANK-KEY-5555-EEEE'));
        $this->assertSame('pa-OWN-RERANK-KEY-5555-EEEE', (new voyage_reranker())->resolved_key(), 'onward to its own key');
        $this->cfg('rerank_key_from', 'voyage');
        $this->assertSame('pa-VOYAGE-ROW-KEY-3333-CCCC', (new voyage_reranker())->resolved_key(), 'the voyage row wins');
    }

    public function test_the_rerank_vendor_check_lets_an_unidentifiable_key_and_a_base_url_through(): void {
        $this->resetAfterTest();
        $this->cfg('embed_apikey', secrets::conceal('pa-EMBED-KEY-4444-DDDD'));
        $this->cfg('rerank_key_from', 'openai');
        $this->cfg('comparison_providers', "openai|sk-BARE-PREFIX-NO-OWNER-6666-FFFF|m");
        $this->assertSame('sk-BARE-PREFIX-NO-OWNER-6666-FFFF', (new voyage_reranker())->resolved_key(), 'bare sk- never rejected');
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-FOR-RERANK-8888-GGGG|m");
        $this->assertSame('pa-EMBED-KEY-4444-DDDD', (new voyage_reranker())->resolved_key());
        $this->cfg('rerank_apibaseurl', 'https://rerank-gateway.example/v1');
        $this->assertSame('sk-proj-ROW-FOR-RERANK-8888-GGGG', (new voyage_reranker())->resolved_key(), 'own base URL: unchecked');
        $this->cfg('rerank_apibaseurl', '');
        $this->assertSame('pa-EMBED-KEY-4444-DDDD', (new voyage_reranker())->resolved_key());
        // With no rerank URL the reranker uses the embedding URL, so that one counts too.
        $this->cfg('embed_apibaseurl', 'https://gateway.example/v1');
        $this->assertSame('sk-proj-ROW-FOR-RERANK-8888-GGGG', (new voyage_reranker())->resolved_key(), 'embed base URL: unchecked');
    }

    public function test_rerank_falls_back_when_the_chosen_row_is_gone_keyless_or_undecryptable(): void {
        $this->resetAfterTest();
        $this->cfg('rerank_apikey', secrets::conceal('pa-OWN-RERANK-KEY-5555-EEEE'));
        $this->cfg('rerank_key_from', 'voyage');
        $this->assertSame('pa-OWN-RERANK-KEY-5555-EEEE', (new voyage_reranker())->resolved_key(), 'row gone');
        $this->cfg('comparison_providers', "voyage||rerank-2.5");
        $this->assertSame('pa-OWN-RERANK-KEY-5555-EEEE', (new voyage_reranker())->resolved_key(), 'row has no key');
        $this->cfg('comparison_providers', "voyage|sodium:AAAAnotarealciphertextAAAAAAAAAAAAAAAAAAAAAAAA|rerank-2.5");
        $resolved = (new voyage_reranker())->resolved_key();
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertSame('pa-OWN-RERANK-KEY-5555-EEEE', $resolved, 'row cannot be decrypted');
    }

    /** A row with its own base URL (Azure OpenAI, a gateway) is a different destination from api.openai.com. */
    public function test_voice_does_not_use_an_openai_row_that_has_its_own_base_url(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('comparison_providers', "openai|sk-proj-GATEWAY-ROW-9090-HHHH|m||https://example.openai.azure.com");
        $this->cfg('realtime_key_from', 'openai');
        $this->assertFalse(voice_registry::is_configured(), 'a gateway key is not an api.openai.com key');
        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_TTS));
        $this->cfg('realtime_apikey', secrets::conceal('sk-proj-OWN-VOICE-KEY-1010-IIII'));
        $this->assertSame('sk-proj-OWN-VOICE-KEY-1010-IIII', voice_registry::resolve(voice_registry::CAPABILITY_TTS)['apikey']);
        $this->cfg('comparison_providers', "openai|sk-proj-PLAIN-ROW-9191-JJJJ|m");
        $this->assertSame('sk-proj-PLAIN-ROW-9191-JJJJ', voice_registry::resolve(voice_registry::CAPABILITY_TTS)['apikey']);
    }

    public function test_voice_falls_back_to_its_own_key_when_the_chosen_row_cannot_be_decrypted(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('realtime_apikey', secrets::conceal('sk-proj-OWN-VOICE-KEY-1010-IIII'));
        $this->cfg('comparison_providers', "openai|sodium:AAAAnotarealciphertextAAAAAAAAAAAAAAAAAAAAAAAA|m");
        $this->cfg('realtime_key_from', 'openai');
        $key = voice_registry::legacy_realtime_key();
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertSame('sk-proj-OWN-VOICE-KEY-1010-IIII', $key);
    }

    /** The optimizer labels the voice provider from the same key voice resolves. */
    public function test_the_optimizer_labels_voice_openai_when_a_row_is_chosen(): void {
        $this->resetAfterTest();
        $label = new \ReflectionMethod(llm_optimizer::class, 'active_provider_for');
        $label->setAccessible(true);
        $this->cfg('comparison_providers', "openai|sk-proj-LABEL-ROW-1212-KKKK|m");
        $this->assertSame('(legacy)', $label->invoke(null, 'voice'), 'a failover row alone');
        $this->cfg('realtime_key_from', 'openai');
        $this->assertSame('openai', $label->invoke(null, 'voice'), 'a chosen row');
        $this->cfg('voice_active_realtime', 'xai-main');
        $this->assertSame('xai-main', $label->invoke(null, 'voice'), 'a configured label still wins');
    }

    /** The benchmark CLI writes each arm's key into embed_apikey, so a chosen row would override it. */
    public function test_chosen_row_sources_names_the_settings_that_point_at_a_row(): void {
        $this->resetAfterTest();
        $both = ['embed_key_from', 'rerank_key_from'];
        $this->assertSame([], provider_keys::chosen_row_sources($both), 'nothing set');
        $this->cfg('embed_key_from', 'own');
        $this->cfg('rerank_key_from', '');
        $this->assertSame([], provider_keys::chosen_row_sources($both), 'own and empty both mean own');
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame(['embed_key_from'], provider_keys::chosen_row_sources($both));
        $this->cfg('rerank_key_from', ' Voyage ');
        $this->assertSame(['embed_key_from', 'rerank_key_from'], provider_keys::chosen_row_sources($both));
    }

    public function test_the_benchmark_cli_refuses_to_run_before_it_writes_any_config(): void {
        // Code only: the script's comments talk about set_config() and the guard at length.
        $code = '';
        foreach (token_get_all((string) file_get_contents(__DIR__ . '/../admin/cli/run_rag_fixture_benchmark.php')) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $guard = strpos($code, "provider_keys::chosen_row_sources(['embed_key_from', 'rerank_key_from'])");
        $this->assertNotFalse($guard, 'the script asks which key sources point at a row');
        $this->assertSame(1, preg_match('/\bset_config\(/', $code, $first, PREG_OFFSET_CAPTURE), 'the script does write config');
        $this->assertLessThan($first[0][1], $guard, 'the guard comes before the first set_config()');
        $help = strpos($code, "\$arg === '--help'");
        $this->assertNotFalse($help);
        $this->assertGreaterThan($help, $guard, '--help still works on a site that uses rows');
        $block = substr($code, $guard, 700);
        $this->assertStringContainsString('if ($chosenrows) {', $block, 'the refusal is conditional on a chosen row');
        $this->assertStringContainsString('cli_error(', $block);
        $this->assertStringContainsString('providerkeys:use_own_key', $block, 'it names the choice the operator must pick');
    }

    // Fix round 2.

    /**
     * Rerank falls onward to the key Embedding uses. When that key is a CHOSEN ROW's, it is vendor-checked against
     * Voyage like Rerank's own choice, so an OpenAI row chosen for Embedding never reaches Voyage that way.
     */
    public function test_rerank_does_not_inherit_an_openai_row_chosen_for_embedding(): void {
        $this->resetAfterTest();
        $this->cfg('embed_provider', 'openai');
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-FOR-EMBED-3333-CCCC|m");
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame('sk-proj-ROW-FOR-EMBED-3333-CCCC', base_embedding_provider::resolve_apikey(), 'Embedding does use it');
        $reranker = new voyage_reranker();
        $this->assertSame('', $reranker->resolved_key(), 'but Voyage does not get it');
        $this->assertFalse($reranker->is_configured());
        // Its own key and its own row still come first, as before.
        $this->cfg('rerank_apikey', secrets::conceal('pa-OWN-RERANK-KEY-5555-EEEE'));
        $this->assertSame('pa-OWN-RERANK-KEY-5555-EEEE', (new voyage_reranker())->resolved_key());
    }

    /** "Same key for the same call" for a site that configures nothing new: a pasted embedding key falls through as always. */
    public function test_rerank_still_inherits_a_pasted_embedding_key_whatever_its_prefix(): void {
        $this->resetAfterTest();
        $this->cfg('embed_provider', 'openai');
        $this->cfg('embed_apikey', secrets::conceal('sk-proj-PASTED-EMBED-KEY-3333-CCCC'));
        $this->assertSame('sk-proj-PASTED-EMBED-KEY-3333-CCCC', (new voyage_reranker())->resolved_key());
        // A row exists for the same vendor but is not chosen: still the pasted key.
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-FOR-EMBED-4444-DDDD|m");
        $this->cfg('embed_key_from', 'own');
        $this->assertSame('sk-proj-PASTED-EMBED-KEY-3333-CCCC', (new voyage_reranker())->resolved_key());
        // A chosen row that Embedding itself skips (another vendor's) leaves the pasted key, and that falls through.
        $this->cfg('embed_provider', 'voyage');
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame('sk-proj-PASTED-EMBED-KEY-3333-CCCC', base_embedding_provider::resolve_apikey());
        $this->assertSame('sk-proj-PASTED-EMBED-KEY-3333-CCCC', (new voyage_reranker())->resolved_key());
    }

    public function test_rerank_inherits_a_voyage_row_chosen_for_embedding_and_a_base_url_lifts_the_check(): void {
        $this->resetAfterTest();
        $this->cfg('embed_provider', 'voyage');
        $this->cfg('comparison_providers', "voyage|pa-VOYAGE-ROW-KEY-3333-CCCC|voyage-3.5");
        $this->cfg('embed_key_from', 'voyage');
        $this->assertSame('pa-VOYAGE-ROW-KEY-3333-CCCC', (new voyage_reranker())->resolved_key(), 'a voyage row falls through');
        // A gateway takes any key: with a base URL the OpenAI row key is inherited, as the ruling says.
        $this->cfg('embed_provider', 'openai');
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-FOR-EMBED-4444-DDDD|m");
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame('', (new voyage_reranker())->resolved_key(), 'no base URL: skipped');
        $this->cfg('embed_apibaseurl', 'https://gateway.example/v1');
        $this->assertSame('sk-proj-ROW-FOR-EMBED-4444-DDDD', (new voyage_reranker())->resolved_key(), 'base URL set: unchecked');
    }

    // Fix wave 3.

    /**
     * F3.2: a row with its own base URL (Azure OpenAI, a gateway) holds a credential for THAT destination. Embedding
     * and Rerank send to their own base URL, so a chosen row is used only when the URLs are the same.
     */
    public function test_embedding_skips_a_chosen_row_whose_base_url_differs_from_its_own(): void {
        $this->resetAfterTest();
        $this->cfg('embed_provider', 'openai');
        $this->cfg('embed_apikey', secrets::conceal('sk-proj-OWN-EMBED-KEY-2222-BBBB'));
        $this->cfg('comparison_providers', "openai|sk-proj-AZURE-ROW-KEY-1111-AAAA|m||https://example.openai.azure.com");
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame('sk-proj-OWN-EMBED-KEY-2222-BBBB', (new openai_embedding_provider())->resolved_key(),
            'Embedding goes to api.openai.com, so the Azure row key stays home');
        $this->assertSame('sk-proj-OWN-EMBED-KEY-2222-BBBB', base_embedding_provider::resolve_apikey(), 'the status card agrees');
        $this->cfg('embed_apibaseurl', 'https://other.example.test/v1');
        $this->assertSame('sk-proj-OWN-EMBED-KEY-2222-BBBB', (new openai_embedding_provider())->resolved_key(),
            'a different base URL is a different destination');
        $this->cfg('embed_apibaseurl', ' https://example.openai.azure.com ');
        $this->assertSame('sk-proj-AZURE-ROW-KEY-1111-AAAA', (new openai_embedding_provider())->resolved_key(),
            'the same destination (compared trimmed)');
        $this->assertSame('sk-proj-AZURE-ROW-KEY-1111-AAAA', base_embedding_provider::resolve_apikey());
    }

    public function test_embedding_with_a_gateway_row_and_no_pasted_key_has_no_key_rather_than_the_wrong_one(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "openai|sk-proj-AZURE-ROW-KEY-1111-AAAA|m||https://example.openai.azure.com");
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame('', (new openai_embedding_provider())->resolved_key());
    }

    public function test_a_chosen_row_with_no_base_url_is_used_whatever_the_embedding_base_url(): void {
        $this->resetAfterTest();
        $this->cfg('embed_apikey', secrets::conceal('sk-proj-OWN-EMBED-KEY-2222-BBBB'));
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-FOR-EMBED-3333-CCCC|m");
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame('sk-proj-ROW-FOR-EMBED-3333-CCCC', (new openai_embedding_provider())->resolved_key());
        $this->cfg('embed_apibaseurl', 'https://gateway.example/v1');
        $this->assertSame('sk-proj-ROW-FOR-EMBED-3333-CCCC', (new openai_embedding_provider())->resolved_key(),
            'as before: an empty row base URL still works');
    }

    public function test_rerank_skips_a_chosen_row_whose_base_url_differs_from_the_rerank_url(): void {
        $this->resetAfterTest();
        $this->cfg('rerank_apikey', secrets::conceal('pa-OWN-RERANK-KEY-5555-EEEE'));
        $this->cfg('comparison_providers', "voyage|pa-GATEWAY-ROW-KEY-6666-FFFF|rerank-2.5||https://voyage-gw.example.test/v1");
        $this->cfg('rerank_key_from', 'voyage');
        $this->assertSame('pa-OWN-RERANK-KEY-5555-EEEE', (new voyage_reranker())->resolved_key(),
            'the reranker goes to api.voyageai.com, so the gateway row key stays home');
        $this->cfg('rerank_apibaseurl', 'https://elsewhere.example.test/v1');
        $this->assertSame('pa-OWN-RERANK-KEY-5555-EEEE', (new voyage_reranker())->resolved_key(), 'another destination');
        $this->cfg('rerank_apibaseurl', ' https://voyage-gw.example.test/v1 ');
        $this->assertSame('pa-GATEWAY-ROW-KEY-6666-FFFF', (new voyage_reranker())->resolved_key(), 'the same destination');
        // With no rerank URL the reranker uses the embedding URL, which counts as its target.
        $this->cfg('rerank_apibaseurl', '');
        $this->cfg('embed_apibaseurl', 'https://voyage-gw.example.test/v1');
        $this->assertSame('pa-GATEWAY-ROW-KEY-6666-FFFF', (new voyage_reranker())->resolved_key(), 'the embedding URL is the target');
    }

    public function test_a_chosen_rerank_row_with_no_base_url_is_used_as_before(): void {
        $this->resetAfterTest();
        $this->cfg('rerank_apikey', secrets::conceal('pa-OWN-RERANK-KEY-5555-EEEE'));
        $this->cfg('comparison_providers', "voyage|pa-VOYAGE-ROW-KEY-3333-CCCC|rerank-2.5");
        $this->cfg('rerank_key_from', 'voyage');
        $this->assertSame('pa-VOYAGE-ROW-KEY-3333-CCCC', (new voyage_reranker())->resolved_key());
        $this->cfg('rerank_apibaseurl', 'https://rerank-gateway.example/v1');
        $this->assertSame('pa-VOYAGE-ROW-KEY-3333-CCCC', (new voyage_reranker())->resolved_key());
    }

    /**
     * Rerank falls onward to the key Embedding took from a chosen row. That row's base URL was right for Embedding's
     * destination; Rerank applies the same rule against its own.
     */
    public function test_rerank_does_not_inherit_an_embedding_row_that_is_for_another_base_url(): void {
        $this->resetAfterTest();
        $this->cfg('embed_provider', 'openai');
        $this->cfg('embed_apibaseurl', 'https://example.openai.azure.com');
        $this->cfg('comparison_providers', "openai|sk-proj-AZURE-ROW-KEY-1111-AAAA|m||https://example.openai.azure.com");
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame('sk-proj-AZURE-ROW-KEY-1111-AAAA', base_embedding_provider::resolve_apikey(), 'Embedding uses it');
        $this->assertSame('sk-proj-AZURE-ROW-KEY-1111-AAAA', (new voyage_reranker())->resolved_key(),
            'no rerank URL: the embedding URL is the same destination');
        $this->cfg('rerank_apibaseurl', 'https://api.voyage-gateway.example.test/v1');
        $reranker = new voyage_reranker();
        $this->assertSame('', $reranker->resolved_key(), 'a different Rerank destination does not get the Azure key');
        $this->assertFalse($reranker->is_configured());
        $this->cfg('rerank_apikey', secrets::conceal('pa-OWN-RERANK-KEY-5555-EEEE'));
        $this->assertSame('pa-OWN-RERANK-KEY-5555-EEEE', (new voyage_reranker())->resolved_key(), 'its own key still comes first');
    }

    public function test_rerank_still_inherits_an_embedding_row_with_no_base_url_under_a_rerank_gateway(): void {
        $this->resetAfterTest();
        $this->cfg('embed_provider', 'voyage');
        $this->cfg('comparison_providers', "voyage|pa-VOYAGE-ROW-KEY-3333-CCCC|voyage-3.5");
        $this->cfg('embed_key_from', 'voyage');
        $this->cfg('rerank_apibaseurl', 'https://rerank-gateway.example/v1');
        $this->assertSame('pa-VOYAGE-ROW-KEY-3333-CCCC', (new voyage_reranker())->resolved_key(), 'an empty row URL works as today');
    }

    public function test_key_from_setting_applies_the_base_url_rule_to_every_caller(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "openai|sk-proj-AZURE-ROW-KEY-1111-AAAA|m||https://example.openai.azure.com\n"
            . "claude|sk-ant-PLAIN-ROW-KEY-7777-GGGG|m");
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame('', provider_keys::key_from_setting('embed_key_from'), 'no target URL given');
        $this->assertSame('', provider_keys::key_from_setting('embed_key_from', 'openai', 'https://other.example.test'));
        $this->assertSame('sk-proj-AZURE-ROW-KEY-1111-AAAA',
            provider_keys::key_from_setting('embed_key_from', 'openai', ' https://example.openai.azure.com '));
        $this->assertSame('sk-proj-AZURE-ROW-KEY-1111-AAAA',
            provider_keys::key_from_setting('embed_key_from', '', 'https://example.openai.azure.com'));
        $this->cfg('embed_key_from', 'claude');
        $this->assertSame('sk-ant-PLAIN-ROW-KEY-7777-GGGG', provider_keys::key_from_setting('embed_key_from'),
            'a row with no base URL is used as before');
    }

    /**
     * F3.4: the Voice key is the first keyed OpenAI row for api.openai.com, which means the first PLAIN one. An
     * Azure row listed first must not hide a plain row listed later.
     */
    public function test_voice_uses_the_first_plain_openai_row_when_a_gateway_row_is_listed_first(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('comparison_providers', "openai|sk-proj-GATEWAY-ROW-9090-HHHH|m||https://example.openai.azure.com\n"
            . "openai|sk-proj-PLAIN-ROW-9191-JJJJ|m\nopenai|sk-proj-LATER-PLAIN-9292-KKKK|m");
        $this->cfg('realtime_key_from', 'openai');
        $this->assertSame('sk-proj-PLAIN-ROW-9191-JJJJ', voice_registry::legacy_realtime_key());
        $this->assertTrue(voice_registry::is_configured());
        $this->assertSame('sk-proj-PLAIN-ROW-9191-JJJJ', voice_registry::resolve(voice_registry::CAPABILITY_TTS)['apikey']);
    }

    public function test_voice_skips_a_keyless_or_unreadable_plain_row_for_a_later_plain_one(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('comparison_providers', "openai||m\n"
            . "openai|sodium:AAAAnotarealciphertextAAAAAAAAAAAAAAAAAAAAAAAA|m\n"
            . "openai|sk-proj-PLAIN-ROW-9191-JJJJ|m");
        $this->cfg('realtime_key_from', 'openai');
        $key = voice_registry::legacy_realtime_key();
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertSame('sk-proj-PLAIN-ROW-9191-JJJJ', $key);
    }

    public function test_voice_falls_back_to_its_own_key_when_every_keyed_openai_row_has_a_base_url(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('realtime_apikey', secrets::conceal('sk-proj-OWN-VOICE-KEY-1010-IIII'));
        $this->cfg('comparison_providers', "openai|sk-proj-GATEWAY-ROW-9090-HHHH|m||https://example.openai.azure.com\n"
            . "openai|sk-proj-GATEWAY-TWO-9393-LLLL|m||https://gw.example.test/v1");
        $this->cfg('realtime_key_from', 'openai');
        $this->assertSame('sk-proj-OWN-VOICE-KEY-1010-IIII', voice_registry::legacy_realtime_key());
    }
}
