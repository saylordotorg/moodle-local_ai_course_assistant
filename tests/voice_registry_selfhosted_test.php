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
 * Tests for the v6.2.0 selfhosted Whisper STT provider in voice_registry.
 *
 * Selfhosted STT (whisper-server / speaches / whisper.cpp, all speaking the
 * OpenAI /v1/audio/transcriptions protocol) is the default STT path whenever
 * a server URL is configured; an explicit paid label in voice_active_stt
 * still wins.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\voice_registry
 */
final class voice_registry_selfhosted_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_no_url_no_rows_resolves_null(): void {
        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_STT));
    }

    public function test_url_configured_is_default_stt(): void {
        set_config('stt_selfhosted_url', 'http://10.0.0.5:8000', 'local_ai_course_assistant');
        $cfg = voice_registry::resolve(voice_registry::CAPABILITY_STT);
        $this->assertNotNull($cfg);
        $this->assertSame('selfhosted', $cfg['provider']);
        $this->assertSame('http://10.0.0.5:8000/v1/audio/transcriptions', $cfg['endpoint']);
        $this->assertSame('whisper-1', $cfg['model']);
        $this->assertSame('', $cfg['apikey']);
    }

    public function test_custom_model_and_key_are_passed_through(): void {
        set_config('stt_selfhosted_url', 'http://10.0.0.5:8000', 'local_ai_course_assistant');
        set_config('stt_selfhosted_model', 'Systran/faster-whisper-small', 'local_ai_course_assistant');
        set_config('stt_selfhosted_apikey', 'sk-local-123', 'local_ai_course_assistant');
        $cfg = voice_registry::resolve(voice_registry::CAPABILITY_STT);
        $this->assertSame('Systran/faster-whisper-small', $cfg['model']);
        $this->assertSame('sk-local-123', $cfg['apikey']);
    }

    public function test_explicit_paid_label_overrides_selfhosted(): void {
        set_config('stt_selfhosted_url', 'http://10.0.0.5:8000', 'local_ai_course_assistant');
        set_config('voice_providers', "openai|sk-test|Hosted OpenAI||", 'local_ai_course_assistant');
        set_config('voice_active_stt', 'Hosted OpenAI', 'local_ai_course_assistant');
        $cfg = voice_registry::resolve(voice_registry::CAPABILITY_STT);
        $this->assertSame('openai', $cfg['provider']);
        $this->assertSame('https://api.openai.com/v1/audio/transcriptions', $cfg['endpoint']);
    }

    public function test_explicit_selfhosted_label_wins_over_rows(): void {
        set_config('stt_selfhosted_url', 'http://10.0.0.5:8000', 'local_ai_course_assistant');
        set_config('voice_providers', "openai|sk-test|Hosted OpenAI||", 'local_ai_course_assistant');
        set_config('voice_active_stt', voice_registry::SELFHOSTED_LABEL, 'local_ai_course_assistant');
        $cfg = voice_registry::resolve(voice_registry::CAPABILITY_STT);
        $this->assertSame('selfhosted', $cfg['provider']);
    }

    public function test_blank_label_with_url_prefers_selfhosted_over_rows(): void {
        set_config('stt_selfhosted_url', 'http://10.0.0.5:8000', 'local_ai_course_assistant');
        set_config('voice_providers', "openai|sk-test|Hosted OpenAI||", 'local_ai_course_assistant');
        $cfg = voice_registry::resolve(voice_registry::CAPABILITY_STT);
        $this->assertSame('selfhosted', $cfg['provider']);
    }

    public function test_selfhosted_label_without_url_falls_back_to_rows(): void {
        set_config('voice_providers', "openai|sk-test|Hosted OpenAI||", 'local_ai_course_assistant');
        set_config('voice_active_stt', voice_registry::SELFHOSTED_LABEL, 'local_ai_course_assistant');
        $cfg = voice_registry::resolve(voice_registry::CAPABILITY_STT);
        $this->assertNotNull($cfg);
        $this->assertSame('openai', $cfg['provider']);
    }

    public function test_tts_and_realtime_never_resolve_selfhosted(): void {
        set_config('stt_selfhosted_url', 'http://10.0.0.5:8000', 'local_ai_course_assistant');
        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_TTS));
        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_REALTIME));
    }

    public function test_selfhosted_url_enables_any_voice(): void {
        set_config('stt_selfhosted_url', 'http://10.0.0.5:8000', 'local_ai_course_assistant');
        $this->assertTrue(voice_registry::any_voice_enabled());
    }

    public function test_endpoint_normalization_variants(): void {
        $this->assertSame(
            'http://h:8000/v1/audio/transcriptions',
            voice_registry::selfhosted_stt_endpoint('http://h:8000')
        );
        $this->assertSame(
            'http://h:8000/v1/audio/transcriptions',
            voice_registry::selfhosted_stt_endpoint('http://h:8000/')
        );
        $this->assertSame(
            'http://h:8000/v1/audio/transcriptions',
            voice_registry::selfhosted_stt_endpoint('http://h:8000/v1')
        );
        $this->assertSame(
            'http://h:8000/v1/audio/transcriptions',
            voice_registry::selfhosted_stt_endpoint('http://h:8000/v1/audio/transcriptions')
        );
        $this->assertSame(
            'https://stt.example.org/v2/audio/transcriptions',
            voice_registry::selfhosted_stt_endpoint('https://stt.example.org/v2/')
        );
    }
}
