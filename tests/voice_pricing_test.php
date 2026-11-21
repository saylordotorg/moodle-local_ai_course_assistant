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

defined('MOODLE_INTERNAL') || die();

/**
 * Voice model pricing (v8.1.1): official audio rates, the audio prefix rule,
 * the dated 2027 Gemini TTS step and conservative estimates for usage records
 * that don't separate text from audio.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\token_cost_manager
 * @covers     \local_ai_course_assistant\model_registry
 * @covers     \local_ai_course_assistant\price_source
 */
final class voice_pricing_test extends \advanced_testcase {
    /** @var int 2026-12-31 23:59:59 UTC, the last second at the 2026 price. */
    private const LAST_2026 = 1798761599;

    /** @var int 2027-01-01 00:00:00 UTC, the first second at the 2027 price. */
    private const FIRST_2027 = 1798761600;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        token_cost_manager::set_clock_for_testing(self::LAST_2026 - 30 * DAYSECS);
        model_registry::reset_cache();
    }

    protected function tearDown(): void {
        token_cost_manager::set_clock_for_testing(null);
        model_registry::reset_cache();
        parent::tearDown();
    }

    /**
     * Assert a model's resolved rates exactly.
     *
     * @param string $model
     * @param float $input
     * @param float $output
     * @return void
     */
    private function assert_rates(string $model, float $input, float $output): void {
        $rates = model_registry::rate_for($model);
        $this->assertNotNull($rates, "$model must be priced");
        $this->assertEqualsWithDelta($input, $rates['input'], 1e-9, "$model input");
        $this->assertEqualsWithDelta($output, $rates['output'], 1e-9, "$model output");
    }

    // ------------------------------------------------------------------
    // Each new rate, exactly.
    // ------------------------------------------------------------------

    public function test_gemini_tts_rates_before_2027(): void {
        $this->assert_rates('gemini-3.8-flash-tts', 0.50, 9.00);
        $this->assert_rates('gemini-3.8-flash-lite-tts', 0.50, 6.00);
    }

    public function test_gemini_live_rates_are_the_audio_rates(): void {
        foreach (['gemini-3.8-live', 'gemini-3.8-live-extended-thinking', 'gemini-3.1-flash-live-preview'] as $model) {
            $this->assert_rates($model, 3.00, 12.00);
        }
    }

    public function test_openai_realtime_rates_are_the_audio_rates(): void {
        $this->assert_rates('gpt-realtime-2.1-mini', 10.00, 20.00);
        $this->assert_rates('gpt-realtime-mini', 10.00, 20.00);
        $this->assert_rates('gpt-realtime-mini-2025-12-15', 10.00, 20.00);
        foreach (['gpt-realtime-2.1', 'gpt-realtime-2', 'gpt-realtime-1.5', 'gpt-realtime'] as $model) {
            $this->assert_rates($model, 32.00, 64.00);
        }
    }

    public function test_openai_tts_rates(): void {
        // Per-character prices at the repo's 4 characters per token convention.
        $this->assert_rates('tts-1', 60.00, 0.00);
        $this->assert_rates('tts-1-hd', 120.00, 0.00);
        $this->assert_rates('gpt-4o-mini-tts', 0.60, 12.00);
        $this->assert_rates('gpt-4o-mini-tts-2025-12-15', 0.60, 12.00);
    }

    public function test_openai_transcription_rates_on_the_per_minute_convention(): void {
        // STT rows carry 1000 tokens per minute, so $X/min is $X * 1000 per 1M.
        $this->assert_rates('gpt-transcribe', 4.50, 0.00);
        $this->assert_rates('gpt-live-transcribe', 17.00, 0.00);
        $this->assert_rates('gpt-4o-mini-transcribe', 3.00, 0.00);
        $this->assert_rates('gpt-4o-transcribe', 6.00, 0.00);
        $this->assert_rates('whisper-1', 6.00, 0.00);
        // One minute of gpt-transcribe is $0.0045.
        $this->assertEqualsWithDelta(0.0045, token_cost_manager::estimate_cost('gpt-transcribe', 1000, 0), 1e-12);
    }

    public function test_gemini_tts_prices_text_and_audio_from_their_own_columns(): void {
        // tts.php writes Google's promptTokenCount (text) and candidatesTokenCount (audio).
        $this->assertEqualsWithDelta(0.50 + 9.00,
            token_cost_manager::estimate_cost('gemini-3.8-flash-tts', 1_000_000, 1_000_000), 1e-9);
        // Ten seconds of audio (250 tokens) for 40 text tokens.
        $this->assertEqualsWithDelta((40 * 0.50 + 250 * 9.00) / 1e6,
            token_cost_manager::estimate_cost('gemini-3.8-flash-tts', 40, 250), 1e-15);
    }

    // ------------------------------------------------------------------
    // The prefix collision: audio never takes a text family's price.
    // ------------------------------------------------------------------

    public function test_audio_models_never_match_a_text_family_prefix(): void {
        // The 8.1.0 known bug: this matched the gemini-2.5-flash chat card (0.30/2.50).
        $this->assertNull(model_registry::rate_for('gemini-2.5-flash-preview-tts'));
        $this->assertNull(model_registry::rate_for('gemini-2.5-flash-native-audio-preview-12-2025'));
        $this->assertNull(model_registry::rate_for('gemini-2.5-flash-lite-preview-tts'));
        // Matched gpt-4o (2.50/10.00) before.
        $this->assertNull(model_registry::rate_for('gpt-4o-audio-preview'));
        $this->assertNull(token_cost_manager::estimate_cost('gemini-2.5-flash-preview-tts', 100, 100));
        // Matched gpt-4o-mini (0.15/0.60) before; now its own audio key.
        $this->assert_rates('gpt-4o-mini-tts', 0.60, 12.00);
        // The chat models themselves are untouched.
        $this->assert_rates('gemini-2.5-flash', 0.30, 2.50);
        $this->assert_rates('gemini-2.5-flash-preview-09-2026', 0.30, 2.50);
        $this->assert_rates('gpt-4o-mini', 0.15, 0.60);
        // A namespace key still prices self-hosted speech at zero.
        $this->assert_rates('selfhosted-whisper-1', 0.00, 0.00);
    }

    public function test_a_text_family_table_row_cannot_price_an_audio_model(): void {
        model_registry::upsert(['modelkey' => 'gemini-2.6', 'input_rate' => 0.2, 'output_rate' => 1.0], 'manual', 2);
        $this->assertNotNull(model_registry::rate_for('gemini-2.6-flash'));
        $this->assertNull(model_registry::rate_for('gemini-2.6-flash-tts'));
        // An audio row an admin types prices it.
        model_registry::upsert(['modelkey' => 'gemini-2.6-flash-tts', 'input_rate' => 0.5, 'output_rate' => 8.0],
            'manual', 2);
        $this->assert_rates('gemini-2.6-flash-tts', 0.5, 8.0);
    }

    public function test_audio_classification_uses_name_segments(): void {
        foreach (['tts-1', 'gpt-4o-mini-tts', 'gpt-realtime', 'gemini-3.8-live', 'whisper-1', 'grok-stt',
                'gpt-4o-transcribe', 'gpt-4o-audio-preview', 'batch/gpt-4o-mini-tts'] as $model) {
            $this->assertTrue(token_cost_manager::is_audio_model($model), $model);
        }
        foreach (['gemini-2.5-flash', 'gpt-4o-mini', 'olive-7b', 'deliver-1', 'claude-sonnet-5'] as $model) {
            $this->assertFalse(token_cost_manager::is_audio_model($model), $model);
        }
    }

    public function test_upstream_feed_rows_cannot_price_audio_but_a_manual_row_can(): void {
        // An upstream row with LiteLLM's TEXT price for gpt-realtime is ignored.
        model_registry::upsert(['modelkey' => 'gpt-realtime', 'input_rate' => 4.0, 'output_rate' => 16.0], 'upstream');
        $this->assert_rates('gpt-realtime', 32.00, 64.00);
        // The same row typed by an admin wins.
        model_registry::upsert(['modelkey' => 'gpt-realtime', 'input_rate' => 30.0, 'output_rate' => 60.0], 'manual', 2);
        $this->assert_rates('gpt-realtime', 30.0, 60.0);
        // Upstream text rows are still honoured.
        model_registry::upsert(['modelkey' => 'brand-new-chat', 'input_rate' => 1.0, 'output_rate' => 2.0], 'upstream');
        $this->assert_rates('brand-new-chat', 1.0, 2.0);
    }

    public function test_litellm_parse_skips_audio_models(): void {
        $body = json_encode([
            'gpt-realtime' => ['mode' => 'chat', 'input_cost_per_token' => 4e-6, 'output_cost_per_token' => 1.6e-5,
                'input_cost_per_audio_token' => 3.2e-5],
            'gemini/gemini-2.5-flash-preview-tts' => ['mode' => 'chat', 'input_cost_per_token' => 5e-7,
                'output_cost_per_token' => 1e-5],
            'gpt-4o-mini' => ['mode' => 'chat', 'input_cost_per_token' => 1.5e-7, 'output_cost_per_token' => 6e-7],
        ]);
        $result = price_source::parse('litellm', $body);
        $this->assertTrue($result['ok']);
        $this->assertSame(['gpt-4o-mini'], array_keys($result['prices']));
    }

    public function test_drift_compare_does_not_count_an_audio_model_as_covered_by_a_chat_card(): void {
        $rates = model_registry::effective_rates();
        $this->assertNull(price_source::longest_prefix('gemini-2.5-flash-preview-tts', array_keys($rates)));
        $this->assertSame('gemini-2.5-flash',
            price_source::longest_prefix('gemini-2.5-flash-preview-09-2026', array_keys($rates)));
        $found = price_source::compare(['gemini-2.5-flash-preview-tts' => ['input' => 0.5, 'output' => 10.0]],
            $rates, 1.0);
        $this->assertSame(0, $found['covered']);
        $this->assertSame('gemini-2.5-flash-preview-tts', $found['new'][0]['modelkey']);
    }

    // ------------------------------------------------------------------
    // The 2027 step.
    // ------------------------------------------------------------------

    public function test_gemini_tts_steps_up_on_2027_01_01_utc(): void {
        token_cost_manager::set_clock_for_testing(self::LAST_2026);
        $this->assert_rates('gemini-3.8-flash-tts', 0.50, 9.00);
        $this->assert_rates('gemini-3.8-flash-lite-tts', 0.50, 6.00);

        // Same request, clock moved: the cached map must be rebuilt.
        token_cost_manager::set_clock_for_testing(self::FIRST_2027);
        $this->assert_rates('gemini-3.8-flash-tts', 1.00, 18.00);
        $this->assert_rates('gemini-3.8-flash-lite-tts', 1.00, 12.00);
        $this->assertEqualsWithDelta(1.00 + 18.00,
            token_cost_manager::estimate_cost('gemini-3.8-flash-tts', 1_000_000, 1_000_000), 1e-9);

        // Models without a step are unchanged.
        $this->assert_rates('gemini-3.8-live', 3.00, 12.00);
        $this->assert_rates('gemini-2.5-flash', 0.30, 2.50);
    }

    public function test_an_admin_row_is_not_stepped(): void {
        model_registry::upsert(['modelkey' => 'gemini-3.8-flash-tts', 'input_rate' => 0.4, 'output_rate' => 8.0],
            'manual', 2);
        token_cost_manager::set_clock_for_testing(self::FIRST_2027);
        $this->assert_rates('gemini-3.8-flash-tts', 0.4, 8.0);
    }

    public function test_baseline_takes_an_explicit_time(): void {
        $before = token_cost_manager::baseline_rate_cards(self::LAST_2026);
        $after = token_cost_manager::baseline_rate_cards(self::FIRST_2027);
        $this->assertEqualsWithDelta(9.00, $before['gemini-3.8-flash-tts']['output'], 1e-9);
        $this->assertEqualsWithDelta(18.00, $after['gemini-3.8-flash-tts']['output'], 1e-9);
        $this->assertNotSame(token_cost_manager::rate_schedule_signature(self::LAST_2026),
            token_cost_manager::rate_schedule_signature(self::FIRST_2027));
    }

    // ------------------------------------------------------------------
    // Usage records that don't separate text from audio.
    // ------------------------------------------------------------------

    public function test_tts_record_without_audio_count_adds_a_conservative_audio_estimate(): void {
        // tts.php for OpenAI: ceil(chars / 4) prompt tokens, 0 output.
        $cost = token_cost_manager::estimate_cost('gpt-4o-mini-tts', 1000, 0);
        $expected = (1000 * 0.60 + 1000 * token_cost_manager::AUDIO_TOKENS_PER_TEXT_TOKEN * 12.00) / 1e6;
        $this->assertEqualsWithDelta($expected, $cost, 1e-12);
        $this->assertGreaterThan(1000 * 0.60 / 1e6, $cost, 'never the text price alone');
        $this->assertSame(10, token_cost_manager::AUDIO_TOKENS_PER_TEXT_TOKEN);

        // Gemini with no candidatesTokenCount.
        $this->assertEqualsWithDelta((100 * 0.50 + 1000 * 9.00) / 1e6,
            token_cost_manager::estimate_cost('gemini-3.8-flash-tts', 100, 0), 1e-12);

        // A record that HAS an audio count is priced as recorded.
        $this->assertEqualsWithDelta((1000 * 0.60 + 500 * 12.00) / 1e6,
            token_cost_manager::estimate_cost('gpt-4o-mini-tts', 1000, 500), 1e-12);

        // tts-1 is priced per character on input; nothing is added.
        $this->assertEqualsWithDelta(1000 * 60.00 / 1e6, token_cost_manager::estimate_cost('tts-1', 1000, 0), 1e-12);
    }

    public function test_prompt_only_realtime_record_is_priced_at_the_dearer_rate(): void {
        // sse.php's realtime logger: duration x 50 as prompt tokens, model gpt-4o-realtime.
        $this->assertEqualsWithDelta(6000 * 20.00 / 1e6,
            token_cost_manager::estimate_cost('gpt-4o-realtime', 6000, 0), 1e-12);
        $this->assertEqualsWithDelta(1000 * 12.00 / 1e6,
            token_cost_manager::estimate_cost('gemini-3.8-live', 1000, 0), 1e-12);
        // Separated records use each side's own rate.
        $this->assertEqualsWithDelta((1000 * 10.00 + 500 * 20.00) / 1e6,
            token_cost_manager::estimate_cost('gpt-realtime-mini', 1000, 500), 1e-12);
        // Transcription is input only and is not inflated.
        $this->assertEqualsWithDelta(1000 * 17.00 / 1e6,
            token_cost_manager::estimate_cost('gpt-live-transcribe', 1000, 0), 1e-12);
        // Text models are untouched.
        $this->assertEqualsWithDelta(1000 * 0.15 / 1e6, token_cost_manager::estimate_cost('gpt-4o-mini', 1000, 0), 1e-12);
    }

    // ------------------------------------------------------------------
    // Unknown models stay unpriced, never free; the banner follows.
    // ------------------------------------------------------------------

    public function test_unknown_voice_models_stay_unpriced(): void {
        foreach (['gpt-live-1', 'gpt-live-1.1', 'grok-tts', 'gemini-9-flash-tts', 'brand-new-voice-model'] as $model) {
            $this->assertNull(model_registry::rate_for($model), $model);
            $this->assertNull(token_cost_manager::estimate_cost($model, 1000, 1000), "$model is unknown, not free");
        }
    }

    public function test_unpriced_banner_drops_priced_voice_models_and_keeps_the_rest(): void {
        $this->log_voice('gemini-3.8-flash-tts', 'gemini', 'gemini_tts', 40, 250);
        $this->log_voice('gpt-4o-mini-tts', 'openai', 'openai_tts', 40, 0);
        $this->log_voice('gpt-realtime-mini', 'openai', 'voice', 500, 0);
        $this->log_voice('gemini-2.5-flash-preview-tts', 'gemini', 'gemini_tts', 40, 250);
        $this->log_voice('grok-tts', 'xai', 'xai_tts', 40, 0);

        $names = array_column(model_registry::unpriced_models(), 'model_name');
        $this->assertNotContains('gemini-3.8-flash-tts', $names);
        $this->assertNotContains('gpt-4o-mini-tts', $names);
        $this->assertNotContains('gpt-realtime-mini', $names);
        $this->assertContains('gemini-2.5-flash-preview-tts', $names, 'was hidden behind the chat price in 8.1.0');
        $this->assertContains('grok-tts', $names);
    }

    /**
     * Write one voice usage row the way tts.php and the realtime logger do.
     *
     * @param string $model
     * @param string $provider
     * @param string $type interaction_type
     * @param int $prompt
     * @param int $completion
     * @return void
     */
    private function log_voice(string $model, string $provider, string $type, int $prompt, int $completion): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid'    => 1,
            'userid'            => 2,
            'courseid'          => SITEID,
            'role'              => 'system',
            'message'           => '[TTS]',
            'prompt_tokens'     => $prompt,
            'completion_tokens' => $completion,
            'model_name'        => $model,
            'provider'          => $provider,
            'interaction_type'  => $type,
            'timecreated'       => time(),
        ]);
    }
}
