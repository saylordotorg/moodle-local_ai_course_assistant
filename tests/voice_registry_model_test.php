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
 * Voice rows with a TTS model column, and Gemini as a text to speech provider (8.1.0).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\voice_registry
 * @covers     \local_ai_course_assistant\admin_setting_voice_providers
 */
final class voice_registry_model_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    private function rows(string ...$lines): void {
        set_config('voice_providers', implode("\n", $lines), 'local_ai_course_assistant');
    }

    private function key(string $plain): string {
        return secrets::conceal($plain);
    }

    public function test_a_row_without_a_model_reads_exactly_as_before(): void {
        $this->rows('openai|' . $this->key('sk-proj-OLD-ROW-1111') . '|main|shimmer|nova');
        $row = voice_registry::parse_rows()[0];
        $this->assertSame(['openai', 'sk-proj-OLD-ROW-1111', 'main', 'shimmer', 'nova', ''],
            [$row['provider'], $row['apikey'], $row['label'], $row['realtime_voice'], $row['tts_voice'], $row['tts_model']]);

        $tts = voice_registry::resolve(voice_registry::CAPABILITY_TTS);
        $this->assertSame('https://api.openai.com/v1/audio/speech', $tts['endpoint']);
        $this->assertSame('tts-1', $tts['model'], 'the catalog default is the old literal');
        $this->assertSame('nova', $tts['voice']);
        $rt = voice_registry::resolve(voice_registry::CAPABILITY_REALTIME);
        $this->assertSame('wss://api.openai.com/v1/realtime?model=gpt-realtime-mini', $rt['endpoint'], 'unchanged URL');
        $this->assertSame('gpt-realtime-mini', $rt['model']);
        $this->assertSame('https://api.openai.com/v1/audio/transcriptions',
            voice_registry::resolve(voice_registry::CAPABILITY_STT)['endpoint']);
    }

    public function test_a_row_with_a_model_carries_it(): void {
        $this->rows('openai|' . $this->key('sk-proj-HD-2222') . '|hd||nova|tts-1-hd');
        $this->assertSame('tts-1-hd', voice_registry::parse_rows()[0]['tts_model']);
        $this->assertSame('tts-1-hd', voice_registry::resolve(voice_registry::CAPABILITY_TTS)['model']);
        // The TTS model never leaks into Realtime.
        $this->assertSame('gpt-realtime-mini', voice_registry::resolve(voice_registry::CAPABILITY_REALTIME)['model']);
    }

    public function test_a_gemini_row_serves_text_to_speech_on_the_catalog_model(): void {
        $this->rows('gemini|' . $this->key('AIzaGEMINI-3333') . '|google||');
        $tts = voice_registry::resolve(voice_registry::CAPABILITY_TTS);
        $this->assertSame('gemini', $tts['provider']);
        $this->assertSame('AIzaGEMINI-3333', $tts['apikey']);
        $this->assertSame('Kore', $tts['voice']);
        $this->assertSame('gemini-3.8-flash-tts', $tts['model']);
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash-tts:generateContent',
            $tts['endpoint']);
        $this->assertStringNotContainsString('AIza', $tts['endpoint']);
        $this->assertSame('gemini_tts', voice_registry::interaction_type('gemini', 'tts'));
        $this->assertSame('voice', voice_registry::interaction_type('gemini', 'stt'), 'no Gemini STT type exists');
    }

    public function test_a_retired_model_in_a_row_is_resolved_at_call_time(): void {
        $this->rows('gemini|' . $this->key('AIzaGEMINI-4444') . '|google||Puck|gemini-2.5-flash-preview-tts');
        $before = gmmktime(12, 0, 0, 12, 17, 2026);
        $after = gmmktime(0, 0, 0, 12, 18, 2026);
        $this->assertSame('gemini-2.5-flash-preview-tts',
            voice_registry::model_for('gemini', 'tts', 'gemini-2.5-flash-preview-tts', $before));
        $this->assertSame('gemini-3.8-flash-tts',
            voice_registry::model_for('gemini', 'tts', 'gemini-2.5-flash-preview-tts', $after));
        // resolve() uses the real clock; whichever side of the date the suite runs on, the endpoint names the model.
        $tts = voice_registry::resolve(voice_registry::CAPABILITY_TTS);
        $this->assertSame(voice_registry::model_for('gemini', 'tts', 'gemini-2.5-flash-preview-tts'), $tts['model']);
        $this->assertStringContainsString('/models/' . $tts['model'] . ':generateContent', $tts['endpoint']);
    }

    public function test_a_gemini_row_is_never_used_for_realtime_or_stt(): void {
        $this->rows(
            'gemini|' . $this->key('AIzaGEMINI-5555') . '|google',
            'openai|' . $this->key('sk-proj-OPENAI-5555') . '|oa'
        );
        // The first-row fallback skips Gemini.
        $this->assertSame('openai', voice_registry::resolve(voice_registry::CAPABILITY_REALTIME)['provider']);
        $this->assertSame('openai', voice_registry::resolve(voice_registry::CAPABILITY_STT)['provider']);
        $this->assertSame('gemini', voice_registry::resolve(voice_registry::CAPABILITY_TTS)['provider']);
        // So does an explicit choice of the Gemini label.
        set_config('voice_active_realtime', 'google', 'local_ai_course_assistant');
        set_config('voice_active_stt', 'google', 'local_ai_course_assistant');
        $this->assertSame('openai', voice_registry::resolve(voice_registry::CAPABILITY_REALTIME)['provider']);
        $this->assertSame('openai', voice_registry::resolve(voice_registry::CAPABILITY_STT)['provider']);
    }

    public function test_only_a_gemini_row_means_no_realtime_and_the_legacy_key_does_not_take_over(): void {
        $this->rows('gemini|' . $this->key('AIzaGEMINI-6666') . '|google');
        set_config('realtime_apikey', 'sk-proj-LEGACY-6666', 'local_ai_course_assistant');
        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_REALTIME));
        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_STT));
        $this->assertSame('gemini', voice_registry::resolve(voice_registry::CAPABILITY_TTS)['provider']);
        $this->assertTrue(voice_registry::is_configured(), 'the speak button shows');
        $this->assertTrue(voice_registry::any_voice_enabled());
    }

    public function test_the_legacy_single_key_path_gets_catalog_models(): void {
        set_config('realtime_apikey', 'sk-proj-LEGACY-7777', 'local_ai_course_assistant');
        $tts = voice_registry::resolve(voice_registry::CAPABILITY_TTS);
        $this->assertSame('openai', $tts['provider']);
        $this->assertSame('tts-1', $tts['model']);
        $this->assertSame('wss://api.openai.com/v1/realtime?model=gpt-realtime-mini',
            voice_registry::resolve(voice_registry::CAPABILITY_REALTIME)['endpoint']);
    }

    public function test_capabilities_and_gemini_voices(): void {
        $this->assertTrue(voice_registry::supports('gemini', 'tts'));
        $this->assertFalse(voice_registry::supports('gemini', 'realtime'));
        $this->assertFalse(voice_registry::supports('GEMINI', 'stt'));
        $this->assertTrue(voice_registry::supports('xai', 'stt'));
        $this->assertTrue(voice_registry::supports('something-old', 'realtime'), 'unknown ids keep the old OpenAI behaviour');
        $this->assertSame(['Kore', 'Charon', 'Puck', 'Zephyr', 'Aoede', 'Sulafat', 'Achird', 'Iapetus', 'Schedar', 'Erinome'],
            voice_registry::voices_for('gemini'));
        $this->assertContains(voice_registry::default_voice_for('gemini'), voice_registry::voices_for('gemini'));
        $this->assertSame('', voice_registry::endpoint_for('gemini', 'realtime'));
        $this->assertSame('', voice_registry::endpoint_for('gemini', 'stt'));
    }

    private function setting(): admin_setting_voice_providers {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        return new admin_setting_voice_providers('local_ai_course_assistant/voice_providers', 'Voice', 'desc');
    }

    private function post(string $p, string $key, string $label = '', string $tts = '', string $model = ''): array {
        return ['provider' => $p, 'newkey' => $key, 'keepid' => '', 'label' => $label, 'realtime_voice' => '',
            'tts_voice' => $tts, 'tts_model' => $model];
    }

    public function test_the_editor_saves_a_gemini_row_with_a_model_and_leaves_model_less_rows_at_five_columns(): void {
        $this->assertSame('', $this->setting()->write_setting(['rows' => [
            $this->post('gemini', 'AIzaEDITOR-8888', 'google', 'Puck', 'gemini-3.8-flash-lite-tts'),
            $this->post('openai', 'sk-proj-EDITOR-8888', 'oa', 'nova'),
        ]]));
        $lines = explode("\n", (string) get_config('local_ai_course_assistant', 'voice_providers'));
        $this->assertCount(6, explode('|', $lines[0]));
        $this->assertStringEndsWith('|google||Puck|gemini-3.8-flash-lite-tts', $lines[0]);
        $this->assertCount(5, explode('|', $lines[1]), 'no model, no sixth column');
        $this->assertStringNotContainsString('AIzaEDITOR', $lines[0]);
        $rows = voice_registry::parse_rows();
        $this->assertSame('gemini-3.8-flash-lite-tts', $rows[0]['tts_model']);
        $this->assertSame('AIzaEDITOR-8888', $rows[0]['apikey']);
        $this->assertStringContainsString('aria-label="TTS model"', $this->setting()->output_html(''));
    }

    public function test_the_editor_refuses_a_model_that_is_not_a_plain_id(): void {
        foreach (['gemini-3.8?key=x', '../files', 'tts 1', 'models/../x'] as $bad) {
            $err = $this->setting()->write_setting(['rows' => [$this->post('gemini', 'AIzaBAD-9999', 'g', '', $bad)]]);
            $this->assertNotSame('', $err, $bad);
            $this->assertStringNotContainsString('AIzaBAD', $err);
        }
        $this->assertSame('', (string) get_config('local_ai_course_assistant', 'voice_providers'), 'nothing saved');
        $this->assertSame('', $this->setting()->write_setting(['rows' => [
            $this->post('gemini', 'AIzaGOOD-9999', 'g', '', 'models/gemini-3.8-flash-tts'),
        ]]));
    }
}
