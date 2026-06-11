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
 * Unit tests for the voice STT/TTS fixes (v6.2.x):
 *  - security::is_allowed_audio_upload (STT MediaRecorder container types).
 *  - voice_registry::is_configured (TTS button gating includes the registry).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\security::is_allowed_audio_upload
 */
final class voice_audio_test extends \advanced_testcase {

    public function test_direct_audio_types_accepted(): void {
        $this->assertTrue(security::is_allowed_audio_upload('audio/webm', 'audio/webm'));
        $this->assertTrue(security::is_allowed_audio_upload('audio/wav', ''));
        // Case-insensitive.
        $this->assertTrue(security::is_allowed_audio_upload('AUDIO/MPEG', ''));
    }

    public function test_mediarecorder_container_types_accepted(): void {
        // What finfo actually emits for audio-only MediaRecorder recordings.
        $this->assertTrue(security::is_allowed_audio_upload('video/webm', 'audio/webm'));
        $this->assertTrue(security::is_allowed_audio_upload('video/mp4', 'audio/mp4'));
        $this->assertTrue(security::is_allowed_audio_upload('application/ogg', 'audio/ogg'));
    }

    public function test_generic_sniff_trusts_declared_audio_type(): void {
        // octet-stream / text/plain only pass when the browser said it's audio.
        $this->assertTrue(security::is_allowed_audio_upload('application/octet-stream', 'audio/ogg'));
        $this->assertTrue(security::is_allowed_audio_upload('text/plain', 'audio/webm'));
        $this->assertTrue(security::is_allowed_audio_upload('', 'audio/mp4'));
    }

    public function test_generic_sniff_without_audio_declared_rejected(): void {
        $this->assertFalse(security::is_allowed_audio_upload('application/octet-stream', ''));
        $this->assertFalse(security::is_allowed_audio_upload('application/octet-stream', 'application/pdf'));
    }

    public function test_clearly_non_audio_rejected(): void {
        $this->assertFalse(security::is_allowed_audio_upload('image/png', 'image/png'));
        $this->assertFalse(security::is_allowed_audio_upload('application/pdf', 'audio/webm'));
    }

    public function test_is_configured_false_when_nothing_set(): void {
        $this->resetAfterTest(true);
        set_config('voice_providers', '', 'local_ai_course_assistant');
        set_config('realtime_apikey', '', 'local_ai_course_assistant');
        set_config('provider', 'anthropic', 'local_ai_course_assistant');
        set_config('apikey', 'sk-anthropic', 'local_ai_course_assistant');
        // Non-OpenAI chat provider with a key but no voice config: not configured.
        $this->assertFalse(voice_registry::is_configured());
    }

    public function test_is_configured_true_with_registry_row(): void {
        $this->resetAfterTest(true);
        set_config('realtime_apikey', '', 'local_ai_course_assistant');
        set_config('provider', 'anthropic', 'local_ai_course_assistant');
        // A voice_providers row must count even when chat runs on a non-OpenAI
        // provider — this is the regression the TTS fix addresses.
        set_config('voice_providers', 'openai|sk-voice|OpenAI Voice', 'local_ai_course_assistant');
        $this->assertTrue(voice_registry::is_configured());
    }

    public function test_is_configured_true_with_legacy_keys(): void {
        $this->resetAfterTest(true);
        set_config('voice_providers', '', 'local_ai_course_assistant');
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('apikey', 'sk-openai', 'local_ai_course_assistant');
        set_config('realtime_apikey', '', 'local_ai_course_assistant');
        $this->assertTrue(voice_registry::is_configured());
    }
}
