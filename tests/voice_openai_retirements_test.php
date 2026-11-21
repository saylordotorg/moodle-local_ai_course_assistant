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
 * OpenAI's text to speech and speech to text retirements (8.3.0), before and after each shutdown date.
 *
 * No network: every assertion is about what would be sent, read from voice_registry and the catalog.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\voice_registry
 * @covers     \local_ai_course_assistant\voice_model_catalog
 * @covers     \local_ai_course_assistant\voice_model_watch
 * @covers     \local_ai_course_assistant\admin_setting_voice_providers
 */
final class voice_openai_retirements_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        set_config('spend_notify_emails', 'voice-admin@example.com', 'local_ai_course_assistant');
    }

    /** 2027-01-05 23:59:59 UTC, the last second OpenAI text to speech may be called. */
    private static function tts_before(): int {
        return gmmktime(23, 59, 59, 1, 5, 2027);
    }

    /** 2027-01-06 00:00:00 UTC, OpenAI text to speech is shut down. */
    private static function tts_at(): int {
        return gmmktime(0, 0, 0, 1, 6, 2027);
    }

    /** 2027-02-25 23:59:59 UTC, the last second whisper-1 may be called. */
    private static function stt_before(): int {
        return gmmktime(23, 59, 59, 2, 25, 2027);
    }

    /** 2027-02-26 00:00:00 UTC, whisper-1 and the 4o transcribe models are shut down. */
    private static function stt_at(): int {
        return gmmktime(0, 0, 0, 2, 26, 2027);
    }

    private function rows(string ...$lines): void {
        set_config('voice_providers', implode("\n", $lines), 'local_ai_course_assistant');
    }

    private function key(string $plain): string {
        return secrets::conceal($plain);
    }

    private function text(string $body): string {
        return trim(preg_replace('~\s+~', ' ', quoted_printable_decode($body)));
    }

    public function test_the_dates_are_the_ones_they_claim(): void {
        $this->assertSame('2027-01-06 00:00:00', gmdate('Y-m-d H:i:s', self::tts_at()));
        $this->assertSame('2027-02-26 00:00:00', gmdate('Y-m-d H:i:s', self::stt_at()));
        $this->assertSame(1, self::tts_at() - self::tts_before());
        $this->assertSame(1, self::stt_at() - self::stt_before());
    }

    public function test_the_catalog_lists_the_openai_retirements(): void {
        foreach (['tts-1', 'tts-1-hd', 'gpt-4o-mini-tts'] as $model) {
            $entry = voice_model_catalog::entry('openai', 'tts', $model);
            $this->assertSame(voice_model_catalog::DEPRECATED, $entry['status'], $model);
            $this->assertSame('2027-01-06', $entry['shutdown'], $model);
            $this->assertNull($entry['replaced_by'], "{$model}: gpt-realtime-2.1-mini is not a drop-in for speech");
            $this->assertTrue(voice_model_catalog::has_no_successor('openai', 'tts', $model), $model);
            $this->assertNull(voice_model_catalog::successor('openai', 'tts', $model, self::tts_at()), $model);
            $this->assertNull(voice_model_catalog::fallback_after_not_found('openai', 'tts', $model, self::tts_at()),
                "{$model}: a 404 is never retried on another OpenAI speech model");
        }
        $this->assertSame('tts-1', voice_model_catalog::default_model('openai', 'tts'), 'unchanged before the date');
        foreach (['whisper-1', 'gpt-4o-transcribe', 'gpt-4o-mini-transcribe', 'gpt-4o-transcribe-diarize'] as $model) {
            $entry = voice_model_catalog::entry('openai', 'stt', $model);
            $this->assertSame('2027-02-26', $entry['shutdown'], $model);
            $this->assertSame('gpt-transcribe', $entry['replaced_by'], $model);
        }
        $snapshot = voice_model_catalog::entry('openai', 'stt', 'gpt-4o-mini-transcribe-2025-03-20');
        $this->assertSame('2027-01-20', $snapshot['shutdown']);
        $this->assertSame('gpt-4o-mini-transcribe-2025-12-15', $snapshot['replaced_by']);
        $this->assertTrue(voice_model_catalog::is_vetted('openai', 'stt', 'gpt-transcribe'));
        $this->assertSame('whisper-1', voice_model_catalog::default_model('openai', 'stt'), 'unchanged before the date');
    }

    public function test_before_the_tts_shutdown_an_openai_row_speaks_as_before(): void {
        $this->rows(
            'openai|' . $this->key('sk-proj-OA-TTS-1111') . '|oa',
            'gemini|' . $this->key('AIzaGEMINI-TTS-1111') . '|google||Kore|gemini-3.8-flash-lite-tts'
        );
        set_config('voice_active_tts', 'oa', 'local_ai_course_assistant');
        $tts = voice_registry::resolve(voice_registry::CAPABILITY_TTS, self::tts_before());
        $this->assertSame('openai', $tts['provider']);
        $this->assertSame('tts-1', $tts['model']);
        $this->assertSame('https://api.openai.com/v1/audio/speech', $tts['endpoint']);
        $this->assertNull(voice_registry::last_retired());
    }

    public function test_after_the_tts_shutdown_the_openai_row_gives_way_to_a_gemini_row(): void {
        $this->rows(
            'openai|' . $this->key('sk-proj-OA-TTS-2222') . '|oa||nova|tts-1-hd',
            'gemini|' . $this->key('AIzaGEMINI-TTS-2222') . '|google||Kore|gemini-3.8-flash-lite-tts'
        );
        set_config('voice_active_tts', 'oa', 'local_ai_course_assistant');
        $tts = voice_registry::resolve(voice_registry::CAPABILITY_TTS, self::tts_at());
        $this->assertDebuggingCalled('SOLA voice: openai shut down tts-1-hd on 2027-01-06, so tts uses the row "google" '
            . 'instead.');
        $this->assertSame('gemini', $tts['provider'], 'the retired model is never called');
        $this->assertSame('AIzaGEMINI-TTS-2222', $tts['apikey']);
        $this->assertSame('gemini-3.8-flash-lite-tts', $tts['model']);
        $this->assertStringContainsString('/models/gemini-3.8-flash-lite-tts:generateContent', $tts['endpoint']);
        $this->assertSame('Kore', $tts['voice']);
        $this->assertNull(voice_registry::last_retired(), 'a row served, so nothing is reported as stopped');
        $this->assertNull(voice_registry::retired_tts_message());
        // The OpenAI row still serves Realtime and STT, whose models have successors.
        $this->assertSame('openai', voice_registry::resolve(voice_registry::CAPABILITY_REALTIME, self::tts_at())['provider']);
        $this->assertSame('openai', voice_registry::resolve(voice_registry::CAPABILITY_STT, self::tts_at())['provider']);
    }

    public function test_a_gemini_row_listed_first_is_unaffected_and_a_frozen_clock_is_honoured(): void {
        $this->rows(
            'openai|' . $this->key('sk-proj-OA-TTS-3333') . '|oa',
            'gemini|' . $this->key('AIzaGEMINI-TTS-3333') . '|google'
        );
        // No $now: the registry reads Moodle's clock.
        $this->mock_clock_with_frozen(self::tts_at());
        $this->assertSame('gemini', voice_registry::resolve(voice_registry::CAPABILITY_TTS)['provider']);
        $this->assertDebuggingCalled();
        $this->mock_clock_with_frozen(self::tts_before());
        $this->assertSame('openai', voice_registry::resolve(voice_registry::CAPABILITY_TTS)['provider']);
    }

    public function test_after_the_tts_shutdown_with_no_other_row_tts_stops_with_a_clear_error(): void {
        $this->rows('openai|' . $this->key('sk-proj-OA-TTS-4444') . '|oa');
        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_TTS, self::tts_at()));
        $this->assertSame(['provider' => 'openai', 'capability' => 'tts', 'model' => 'tts-1', 'shutdown' => '2027-01-06'],
            voice_registry::last_retired());
        $message = voice_registry::retired_tts_message();
        $this->assertStringContainsString('tts-1', $message);
        $this->assertStringContainsString('2027-01-06', $message);
        $this->assertStringContainsString('Voice providers', $message, 'names the setting');
        $this->assertStringContainsString('Active TTS provider', $message);
        $this->assertStringContainsString('Gemini', $message);
        $this->assertStringNotContainsString('[[', $message, 'brand tokens resolved');
        $this->assertStringNotContainsString('sk-proj', $message);
        // The legacy single-key fallback must not quietly take over either.
        set_config('realtime_apikey', 'sk-proj-LEGACY-4444', 'local_ai_course_assistant');
        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_TTS, self::tts_at()));
        // Realtime keeps working on the same row.
        $this->assertSame('openai', voice_registry::resolve(voice_registry::CAPABILITY_REALTIME, self::tts_at())['provider']);
        // A later resolve that succeeds clears the report.
        $this->assertNotNull(voice_registry::resolve(voice_registry::CAPABILITY_TTS, self::tts_before()));
        $this->assertNull(voice_registry::last_retired());
    }

    public function test_the_legacy_single_key_path_stops_after_the_tts_shutdown(): void {
        set_config('realtime_apikey', 'sk-proj-LEGACY-5555', 'local_ai_course_assistant');
        $this->assertSame('tts-1', voice_registry::resolve(voice_registry::CAPABILITY_TTS, self::tts_before())['model']);
        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_TTS, self::tts_at()));
        $this->assertSame('tts-1', voice_registry::last_retired()['model']);
        $this->assertNotNull(voice_registry::retired_tts_message());
    }

    public function test_tts_php_reports_the_retirement_before_any_request_is_built(): void {
        global $CFG;
        $src = (string) file_get_contents($CFG->dirroot . '/local/ai_course_assistant/tts.php');
        $resolve = strpos($src, 'voice_registry::resolve(');
        $message = strpos($src, 'voice_registry::retired_tts_message()');
        $build = strpos($src, '$request = \local_ai_course_assistant\voice\tts_request::build(');
        $this->assertNotFalse($resolve);
        $this->assertNotFalse($message, 'tts.php tells the admin which setting to change');
        $this->assertNotFalse($build);
        $this->assertTrue($resolve < $message && $message < $build);
        $this->assertDoesNotMatchRegularExpression("~'tts-1~", $src, 'no hard-coded OpenAI speech model');
    }

    public function test_stt_on_api_openai_com_moves_to_gpt_transcribe_on_the_shutdown_date(): void {
        $this->rows('openai|' . $this->key('sk-proj-OA-STT-6666') . '|oa');
        $before = voice_registry::resolve(voice_registry::CAPABILITY_STT, self::stt_before());
        $this->assertSame('https://api.openai.com/v1/audio/transcriptions', $before['endpoint']);
        $this->assertSame('whisper-1', $before['model']);
        $this->assertSame('whisper-1', voice_registry::stt_model($before, self::stt_before()), 'identical before the date');

        $after = voice_registry::resolve(voice_registry::CAPABILITY_STT, self::stt_at());
        $this->assertSame('https://api.openai.com/v1/audio/transcriptions', $after['endpoint'], 'same endpoint');
        $this->assertSame('gpt-transcribe', $after['model']);
        $this->assertSame('gpt-transcribe', voice_registry::stt_model($before, self::stt_at()),
            'the call-time lookup moves even a cfg resolved earlier');

        // A row's own STT model is followed the same way; the dated snapshot has an earlier date.
        $this->rows('openai|' . $this->key('sk-proj-OA-STT-6666') . '|oa|||||gpt-4o-mini-transcribe-2025-03-20');
        $this->assertSame('gpt-4o-mini-transcribe-2025-03-20', voice_registry::parse_rows()[0]['stt_model']);
        $this->assertSame('gpt-4o-mini-transcribe-2025-03-20',
            voice_registry::resolve(voice_registry::CAPABILITY_STT, gmmktime(0, 0, 0, 1, 19, 2027))['model']);
        $this->assertSame('gpt-4o-mini-transcribe-2025-12-15',
            voice_registry::resolve(voice_registry::CAPABILITY_STT, gmmktime(0, 0, 0, 1, 20, 2027))['model']);
        // The legacy single key is api.openai.com too.
        $this->rows();
        set_config('realtime_apikey', 'sk-proj-LEGACY-6666', 'local_ai_course_assistant');
        $legacy = voice_registry::resolve(voice_registry::CAPABILITY_STT, self::stt_at());
        $this->assertSame('gpt-transcribe', voice_registry::stt_model($legacy, self::stt_at()));
        // xAI keeps its own model name.
        $this->assertSame('grok-stt', voice_registry::stt_model(['provider' => 'xai', 'model' => '',
            'endpoint' => 'https://api.x.ai/v1/stt'], self::stt_at()));
    }

    /** The self-hosted trap: whisper-1 is the server's own name for its model, and is never remapped. */
    public function test_a_self_hosted_or_custom_server_keeps_its_whisper_1_after_the_shutdown(): void {
        $this->rows('openai|' . $this->key('sk-proj-OA-STT-7777') . '|oa');
        foreach (['', 'whisper-1'] as $configured) {
            set_config('stt_selfhosted_url', 'http://whisper.internal:8000', 'local_ai_course_assistant');
            set_config('stt_selfhosted_model', $configured, 'local_ai_course_assistant');
            $cfg = voice_registry::resolve(voice_registry::CAPABILITY_STT, self::stt_at());
            $this->assertSame(voice_registry::SELFHOSTED_LABEL, $cfg['provider']);
            $this->assertSame('whisper-1', $cfg['model'], "configured '{$configured}'");
            $this->assertSame('whisper-1', voice_registry::stt_model($cfg, self::stt_at()), "configured '{$configured}'");
        }
        // Even a self-hosted URL that points at api.openai.com is the admin's own choice of name.
        set_config('stt_selfhosted_url', 'https://api.openai.com/v1', 'local_ai_course_assistant');
        set_config('stt_selfhosted_model', 'whisper-1', 'local_ai_course_assistant');
        $cfg = voice_registry::resolve(voice_registry::CAPABILITY_STT, self::stt_at());
        $this->assertSame('whisper-1', voice_registry::stt_model($cfg, self::stt_at()));
        // Mixed case and a server-specific name pass through untouched too.
        $this->assertSame('Systran/faster-whisper-small', voice_registry::stt_model(['provider' => 'selfhosted',
            'model' => 'Systran/faster-whisper-small', 'endpoint' => 'http://whisper.internal:8000/v1/audio/transcriptions'],
            self::stt_at()));
        // Any endpoint that isn't exactly https://api.openai.com is a custom base URL: no remapping.
        foreach (['https://gateway.example.com/v1/audio/transcriptions', 'http://api.openai.com/v1/audio/transcriptions',
                'https://api.openai.com:8443/v1/audio/transcriptions', 'https://api.openai.com.example.net/v1/x',
                'https://user@api.openai.com/v1/audio/transcriptions', ''] as $endpoint) {
            $this->assertFalse(voice_registry::is_openai_hosted($endpoint), $endpoint);
            $this->assertSame('whisper-1', voice_registry::stt_model(['provider' => 'openai', 'model' => 'whisper-1',
                'endpoint' => $endpoint], self::stt_at()), $endpoint);
        }
        $this->assertTrue(voice_registry::is_openai_hosted('https://API.openai.com/v1/audio/transcriptions'));
    }

    public function test_the_transcription_endpoints_ask_the_registry_for_the_model(): void {
        global $CFG;
        foreach (['transcribe.php', 'soapbox_transcribe.php', 'classes/soapbox_scorer.php'] as $file) {
            $src = (string) file_get_contents($CFG->dirroot . '/local/ai_course_assistant/' . $file);
            $this->assertStringContainsString('voice_registry::stt_model($cfg)', $src, $file);
            $this->assertStringNotContainsString(": 'whisper-1'", $src, "{$file} hard-codes no model");
        }
    }

    public function test_the_stt_model_column_round_trips_and_is_validated(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $setting = new admin_setting_voice_providers('local_ai_course_assistant/voice_providers', 'Voice', 'desc');
        $row = ['provider' => 'openai', 'newkey' => 'sk-proj-STT-COL-8888-AAAA', 'keepid' => '', 'label' => 'oa',
            'realtime_voice' => '', 'tts_voice' => '', 'tts_model' => '', 'realtime_model' => '',
            'stt_model' => 'gpt-transcribe'];
        $this->assertSame('', $setting->write_setting(['rows' => [$row]]));
        $this->assertSame('gpt-transcribe', voice_registry::parse_rows()[0]['stt_model']);
        $this->assertSame('gpt-transcribe', voice_registry::resolve(voice_registry::CAPABILITY_STT, self::stt_before())['model']);
        $this->assertSame('tts-1', voice_registry::resolve(voice_registry::CAPABILITY_TTS, self::tts_before())['model'],
            'the STT model never crosses into text to speech');
        $this->assertCount(8, explode('|', (string) get_config('local_ai_course_assistant', 'voice_providers')));

        $row['stt_model'] = 'gpt transcribe|x';
        $err = $setting->write_setting(['rows' => [$row]]);
        $this->assertStringContainsString('STT model', $err);
        $this->assertSame('gpt-transcribe', voice_registry::parse_rows()[0]['stt_model'], 'nothing saved');
        // A row without the column reads as before.
        $this->rows('openai|' . $this->key('sk-proj-OLD-8888') . '|old|shimmer|nova');
        $this->assertSame('', voice_registry::parse_rows()[0]['stt_model']);
    }

    public function test_the_watch_warns_45_days_ahead_and_recommends_a_gemini_row(): void {
        $inuse = voice_model_watch::models_in_use([['provider' => 'openai', 'apikey' => 'x', 'label' => 'oa',
            'realtime_voice' => '', 'tts_voice' => '', 'tts_model' => '']]);
        $this->assertContains(['openai', 'tts', 'tts-1'], $inuse);
        $this->assertContains(['openai', 'stt', 'whisper-1'], $inuse);

        // 46 days before: nothing yet. 45 days before: the warning.
        $this->assertSame([], voice_model_watch::check($inuse, [], gmmktime(0, 0, 0, 11, 21, 2026))['warnings']);
        $at = gmmktime(0, 0, 0, 11, 22, 2026);
        $result = voice_model_watch::check($inuse, [], $at);
        $this->assertCount(1, $result['warnings']);
        $warning = $result['warnings'][0];
        $this->assertSame(['openai', 'tts', 'tts-1', 45], [$warning['provider'], $warning['capability'],
            $warning['model'], $warning['days_left']]);
        $this->assertTrue($warning['no_successor']);

        $sink = $this->redirectEmails();
        $this->assertSame(1, voice_model_watch::notify_new($result, $at));
        $body = $this->text($sink->get_messages()[0]->body);
        $this->assertStringContainsString('tts-1 on 2027-01-06 (in 45 days)', $body);
        $this->assertStringContainsString('no replacement', $body);
        $this->assertStringContainsString('Add a Gemini row under Voice providers', $body);
        $this->assertStringContainsString('gemini-3.8-flash-lite-tts', $body);
        $this->assertStringNotContainsString('calls go to tts-1', $body, 'never claims a successor');
        $this->assertStringNotContainsString('[[', $body);
        $sink->clear();

        // After the date, once more, as retired.
        $after = voice_model_watch::check($inuse, [], self::tts_at());
        $this->assertSame(1, voice_model_watch::notify_new($after, self::tts_at()));
        $body = $this->text($sink->get_messages()[0]->body);
        $this->assertStringContainsString('shut down the text to speech model tts-1 on 2027-01-06', $body);
        $this->assertStringContainsString('gemini-3.8-flash-lite-tts', $body);
        $sink->clear();

        // whisper-1 is warned 45 days before 2027-02-26, naming its successor.
        $stt = voice_model_watch::check($inuse, [], gmmktime(0, 0, 0, 1, 12, 2027));
        $models = array_column($stt['warnings'], 'resolves_to', 'model');
        $this->assertSame('gpt-transcribe', $models['whisper-1']);
        $this->assertSame(1, voice_model_watch::notify_new($stt, gmmktime(0, 0, 0, 1, 12, 2027)),
            'only the new STT finding; the TTS one was sent already');
        $this->assertStringContainsString('From then on, calls go to gpt-transcribe',
            $this->text($sink->get_messages()[0]->body));
        $sink->close();
    }

    public function test_the_watch_leaves_a_self_hosted_stt_server_alone(): void {
        set_config('stt_selfhosted_url', 'http://whisper.internal:8000', 'local_ai_course_assistant');
        $row = ['provider' => 'openai', 'apikey' => 'x', 'label' => 'oa', 'realtime_voice' => '', 'tts_voice' => '',
            'tts_model' => ''];
        $inuse = voice_model_watch::models_in_use([$row]);
        $this->assertNotContains(['openai', 'stt', 'whisper-1'], $inuse, 'the self-hosted server takes every transcription');
        $this->assertContains(['openai', 'tts', 'tts-1'], $inuse);
        // Choosing the OpenAI row for STT over the server brings it back.
        set_config('voice_active_stt', 'oa', 'local_ai_course_assistant');
        $this->assertContains(['openai', 'stt', 'whisper-1'], voice_model_watch::models_in_use([$row]));
    }
}
