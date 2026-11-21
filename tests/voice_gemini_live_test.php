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

use local_ai_course_assistant\voice\gemini_live;
use local_ai_course_assistant\voice\gemini_live_token;

/**
 * Gemini Live (8.2.0), the parts with no network: the catalog entries, the token request, the registry, the caps.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\voice\gemini_live_token
 * @covers     \local_ai_course_assistant\voice\gemini_live
 * @covers     \local_ai_course_assistant\voice_registry
 */
final class voice_gemini_live_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    private function rows(string ...$lines): void {
        set_config('voice_providers', implode("\n", $lines), 'local_ai_course_assistant');
    }

    // ---------- the catalog ----------

    public function test_the_catalog_lists_the_live_models_with_the_preview_shutdown(): void {
        $this->assertSame('gemini-3.8-live', voice_model_catalog::default_model('gemini', 'realtime'));
        $this->assertTrue(voice_model_catalog::is_vetted('gemini', 'realtime', 'gemini-3.8-live'));
        $old = voice_model_catalog::entry('gemini', 'realtime', 'gemini-2.5-flash-native-audio-preview-12-2025');
        $this->assertSame(voice_model_catalog::DEPRECATED, $old['status']);
        $this->assertSame('2027-03-31', $old['shutdown']);
        $this->assertSame('gemini-3.8-live', $old['replaced_by']);
    }

    public function test_a_deprecated_live_model_is_called_until_its_shutdown_then_replaced(): void {
        set_config('gemini_live_model', 'gemini-2.5-flash-native-audio-preview-12-2025', 'local_ai_course_assistant');
        $before = gmmktime(23, 59, 59, 3, 30, 2027);
        $after = gmmktime(0, 0, 0, 3, 31, 2027);
        $this->assertSame('gemini-2.5-flash-native-audio-preview-12-2025',
            voice_registry::model_for('gemini', 'realtime', '', $before));
        $this->assertSame('gemini-3.8-live', voice_registry::model_for('gemini', 'realtime', '', $after));
        // Blank uses the catalog default; the row's TTS model column never reaches Live.
        set_config('gemini_live_model', '', 'local_ai_course_assistant');
        $row = ['tts_model' => 'gemini-3.8-flash-tts', 'realtime_model' => ''];
        $this->assertSame('', voice_registry::row_model($row, 'realtime'));
        $this->assertSame('gemini-3.8-live',
            voice_registry::model_for('gemini', 'realtime', voice_registry::row_model($row, 'realtime')));
        // And OpenAI Realtime is untouched by the Gemini setting.
        set_config('gemini_live_model', 'gemini-3.8-live', 'local_ai_course_assistant');
        $this->assertSame('gpt-realtime-2.1-mini', voice_registry::model_for('openai', 'realtime', ''));
    }

    // ---------- the registry ----------

    public function test_a_gemini_row_serves_realtime_only_while_gemini_live_is_on(): void {
        $this->rows('gemini|AIzaLIVE-ROW-1111|google|Puck|');
        set_config('voice_active_realtime', 'google', 'local_ai_course_assistant');

        $this->assertFalse(voice_registry::supports('gemini', 'realtime'), 'off by default');
        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_REALTIME));

        set_config('gemini_live_enabled', 1, 'local_ai_course_assistant');
        $this->assertTrue(voice_registry::supports('gemini', 'realtime'));
        $this->assertFalse(voice_registry::supports('gemini', 'stt'), 'Live never makes Gemini an STT provider');
        $cfg = voice_registry::resolve(voice_registry::CAPABILITY_REALTIME);
        $this->assertSame('gemini', $cfg['provider']);
        $this->assertSame('AIzaLIVE-ROW-1111', $cfg['apikey']);
        $this->assertSame('Puck', $cfg['voice']);
        $this->assertSame('gemini-3.8-live', $cfg['model']);
        $this->assertSame(gemini_live_token::WS_URL, $cfg['endpoint']);
        $this->assertStringNotContainsString('AIza', $cfg['endpoint']);
        $this->assertSame('gemini_live', voice_registry::interaction_type('gemini', 'live'));
    }

    public function test_an_openai_row_still_wins_realtime_when_it_is_the_chosen_label(): void {
        set_config('gemini_live_enabled', 1, 'local_ai_course_assistant');
        $this->rows('gemini|AIzaLIVE-ROW-2222|google', 'openai|sk-proj-OA-2222|oa');
        set_config('voice_active_realtime', 'oa', 'local_ai_course_assistant');
        $this->assertSame('openai', voice_registry::resolve(voice_registry::CAPABILITY_REALTIME)['provider']);
    }

    public function test_the_emergency_switch_stops_gemini_live_too(): void {
        set_config('gemini_live_enabled', 1, 'local_ai_course_assistant');
        $this->rows('gemini|AIzaLIVE-ROW-3333|google');
        set_config('voice_active_realtime', 'google', 'local_ai_course_assistant');
        emergency_control::disable([emergency_control::FLAG_VOICE], 'test', 'phpunit');
        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_REALTIME));
        $this->assertDebuggingCalled();
    }

    // ---------- the token request (pure) ----------

    public function test_the_token_request_is_single_use_short_lived_and_locked(): void {
        $now = gmmktime(10, 0, 0, 11, 21, 2026);
        $req = gemini_live_token::build_request('gemini-3.8-live', 'GROUNDED PROMPT', 'Kore', 600, $now);

        $this->assertSame(1, $req['uses']);
        $this->assertSame('2026-11-21T10:01:00Z', $req['newSessionExpireTime'], 'one minute to start the session');
        $this->assertSame('2026-11-21T10:10:30Z', $req['expireTime'], 'dies 30 s after the session cap');
        $setup = $req['bidiGenerateContentSetup']['setup'];
        $this->assertSame('models/gemini-3.8-live', $setup['model']);
        $this->assertSame(['AUDIO'], $setup['generationConfig']['responseModalities']);
        $this->assertSame('Kore', $setup['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName']);
        $this->assertSame('GROUNDED PROMPT', $setup['systemInstruction']['parts'][0]['text']);
        foreach (['model', 'systemInstruction', 'generationConfig.responseModalities'] as $locked) {
            $this->assertContains($locked, explode(',', $req['fieldMask']));
        }
        // Transcription switched on with empty OBJECTS: [] would be a JSON array, which Google refuses.
        $json = json_encode($req);
        $this->assertStringContainsString('"inputAudioTranscription":{}', $json);
        $this->assertStringContainsString('"outputAudioTranscription":{}', $json);
    }

    public function test_the_token_request_refuses_a_model_that_is_not_a_plain_id(): void {
        $this->expectException(\coding_exception::class);
        gemini_live_token::build_request('../gemini?x=1', 'p', 'Kore', 600, time());
    }

    public function test_the_key_travels_only_in_a_header(): void {
        $this->assertSame(['x-goog-api-key: AIzaKEY', 'Content-Type: application/json'],
            gemini_live_token::headers('AIzaKEY'));
        $this->assertStringNotContainsString('key=', gemini_live_token::TOKEN_URL);
        $this->assertStringStartsWith('wss://generativelanguage.googleapis.com/', gemini_live_token::WS_URL);
    }

    public function test_the_token_is_read_from_name_and_anything_else_is_refused(): void {
        $this->assertSame('auth_tokens/abc123DEF-_.xyz',
            gemini_live_token::parse_response('{"name":"auth_tokens/abc123DEF-_.xyz"}'));
        $this->assertSame('', gemini_live_token::parse_response('{"name":"auth_tokens/a b&c=d1234"}'));
        $this->assertSame('', gemini_live_token::parse_response('{"value":"auth_tokens/abc123456"}'));
        $this->assertSame('', gemini_live_token::parse_response('not json'));
    }

    public function test_an_error_message_never_carries_a_key_or_a_token(): void {
        $msg = gemini_live_token::error_message(400,
            '{"error":{"message":"API key AIzaSyABCDEFGHIJKLMNOP not valid; auth_tokens/secret123 bad"}}');
        $this->assertStringNotContainsString('AIzaSy', $msg);
        $this->assertStringNotContainsString('secret123', $msg);
        $this->assertStringContainsString('[redacted]', $msg);
        $this->assertSame('Gemini Live token request failed: HTTP 503', gemini_live_token::error_message(503, ''));
    }

    // ---------- the caps ----------

    public function test_the_cap_settings_default_safely_and_are_clamped(): void {
        $this->assertSame(600, gemini_live::max_session_seconds());
        $this->assertSame(90, gemini_live::idle_seconds());
        $this->assertSame(1800, gemini_live::daily_seconds());
        set_config('gemini_live_max_session_minutes', 500, 'local_ai_course_assistant');
        set_config('gemini_live_idle_seconds', 1, 'local_ai_course_assistant');
        set_config('gemini_live_daily_minutes', 'abc', 'local_ai_course_assistant');
        $this->assertSame(1800, gemini_live::max_session_seconds(), 'clamped to 30 minutes');
        $this->assertSame(15, gemini_live::idle_seconds());
        $this->assertSame(1800, gemini_live::daily_seconds(), 'junk reads as the default');
        $this->assertFalse(gemini_live::enabled(), 'off by default');
    }

    public function test_the_session_allowance_is_cut_to_what_is_left_of_the_day(): void {
        $this->assertSame(600, gemini_live::session_allowance(600, 1800, 0));
        $this->assertSame(300, gemini_live::session_allowance(600, 1800, 1500));
        $this->assertSame(0, gemini_live::session_allowance(600, 1800, 1750), 'under a minute left: refuse');
        $this->assertSame(0, gemini_live::session_allowance(600, 1800, 9999));
        $this->assertSame(600, gemini_live::session_allowance(600, 0, 99999), '0 = no daily cap');
    }

    public function test_reported_usage_is_clamped_to_what_the_server_can_believe(): void {
        $this->assertSame([300, 1000, 2000], gemini_live::clamp_usage(300, 1000, 2000, 600, 400));
        $this->assertSame([600, 0, 0], gemini_live::clamp_usage(99999, -5, -1, 600, 10000), 'never past the cap');
        $this->assertSame([65, 5000000, 5000000], gemini_live::clamp_usage(600, PHP_INT_MAX, 9e9, 600, 60),
            'never longer than the time since the mint (plus 5 s)');
    }

    public function test_unreported_sessions_count_their_full_cap(): void {
        $this->assertSame(312, gemini_live::counted_seconds('[Gemini Live session: 312s]'));
        $this->assertSame(600, gemini_live::counted_seconds('[Gemini Live session: open, cap 600s]'));
        $this->assertSame(1800, gemini_live::counted_seconds('something else'), 'unknown rows count the largest cap');
    }

    public function test_a_session_row_is_opened_once_and_closed_once_by_its_owner(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $id = gemini_live::open_session((int) $user->id, (int) $course->id, 'gemini-3.8-live', 600);
        $this->assertSame(600, gemini_live::seconds_used_today((int) $user->id), 'open rows count their cap');
        $this->assertSame(0, gemini_live::seconds_used_today((int) $other->id));

        $this->assertFalse(gemini_live::close_session($id, (int) $other->id, 10, 1, 1), 'another learner can\'t');
        $later = time() + 200;
        $this->assertTrue(gemini_live::close_session($id, (int) $user->id, 180, 4000, 6000, $later));
        $this->assertFalse(gemini_live::close_session($id, (int) $user->id, 1, 1, 1, $later), 'only once');

        global $DB;
        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $id]);
        $this->assertSame('[Gemini Live session: 180s]', $row->message);
        $this->assertSame('gemini_live', $row->interaction_type);
        $this->assertSame('gemini-3.8-live', $row->model_name);
        $this->assertSame('gemini', $row->provider);
        $this->assertEquals(4000, $row->prompt_tokens);
        $this->assertEquals(6000, $row->completion_tokens);
        $this->assertEquals(10000, $row->tokens_used);
        $this->assertSame(180, gemini_live::seconds_used_today((int) $user->id, $later));
    }

    public function test_the_session_row_is_voice_spend(): void {
        $sql = spend_guard::capability_sql('voice');
        $this->assertStringContainsString("'gemini_live'", $sql);
        $this->assertStringContainsString("'gemini_live'", analytics::spend_rows_predicate('m'));
    }
}
