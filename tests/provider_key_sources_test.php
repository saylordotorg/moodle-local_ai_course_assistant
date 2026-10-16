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
     * The help says what a voice key turns on, and the chip it names is the one the code gates on the same key
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
        foreach (['settings:realtime_apikey_desc', 'settings:voice_providers_heading_desc'] as $key) {
            $text = get_string($key, 'local_ai_course_assistant');
            $this->assertStringContainsString('speak button', $text, $key);
            $this->assertStringContainsString('speech-to-text', $text, $key);
            $this->assertStringContainsString('even when Voice Mode is off', $text, $key);
            foreach ($chips as $chip) {
                $this->assertStringContainsString($chip, $text, $key);
            }
        }
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
        foreach (\admin_get_root(true, true)->locate('local_ai_course_assistant')->children as $page) {
            if (!$page instanceof \admin_settingpage) {
                continue;
            }
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
}
