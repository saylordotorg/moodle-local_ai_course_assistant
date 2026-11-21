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

use local_ai_course_assistant\voice\gemini_tts;
use local_ai_course_assistant\voice\tts_request;
use local_ai_course_assistant\voice\wav;

/**
 * Gemini text to speech (8.1.0): the WAV wrapper, the request, and reading the audio back. No network.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\voice\wav
 * @covers     \local_ai_course_assistant\voice\gemini_tts
 * @covers     \local_ai_course_assistant\voice\tts_request
 */
final class voice_gemini_tts_test extends \advanced_testcase {

    public function test_the_wav_header_is_the_canonical_44_bytes(): void {
        $pcm = pack('v*', 0, 1000, -1000 & 0xFFFF, 32767);   // Four mono samples, eight bytes.
        $wav = wav::from_pcm16($pcm, 24000, 1);
        $this->assertSame(44 + 8, strlen($wav));
        $h = unpack('a4riff/Vsize/a4wave/a4fmt/Vfmtsize/vformat/vchannels/Vrate/Vbyterate/vblock/vbits/a4data/Vdatasize',
            substr($wav, 0, 44));
        $this->assertSame([
            'riff' => 'RIFF', 'size' => 36 + 8, 'wave' => 'WAVE', 'fmt' => 'fmt ', 'fmtsize' => 16, 'format' => 1,
            'channels' => 1, 'rate' => 24000, 'byterate' => 48000, 'block' => 2, 'bits' => 16, 'data' => 'data',
            'datasize' => 8,
        ], $h);
        $this->assertSame($pcm, substr($wav, 44), 'samples follow the header unchanged');
        // The exact header bytes for this case, so a field order slip can't pass.
        $this->assertSame('52494646' . '2c000000' . '57415645' . '666d7420' . '10000000' . '0100' . '0100'
            . 'c05d0000' . '80bb0000' . '0200' . '1000' . '64617461' . '08000000', bin2hex(substr($wav, 0, 44)));
    }

    public function test_a_half_sample_is_dropped_and_stereo_counts_both_channels(): void {
        $wav = wav::from_pcm16("\x01\x02\x03", 24000, 1);
        $this->assertSame(46, strlen($wav));
        $this->assertSame(2, unpack('V', substr($wav, 40, 4))[1]);
        $this->assertSame(38, unpack('V', substr($wav, 4, 4))[1]);

        $stereo = wav::from_pcm16(str_repeat("\x00", 10), 44100, 2);   // Two whole frames, two stray bytes.
        $this->assertSame(8, unpack('V', substr($stereo, 40, 4))[1]);
        $this->assertSame(44100 * 4, unpack('V', substr($stereo, 28, 4))[1]);
        $this->assertSame(4, unpack('v', substr($stereo, 32, 2))[1]);

        $empty = wav::from_pcm16('', 24000, 1);
        $this->assertSame(44, strlen($empty));
        $this->assertSame(0, unpack('V', substr($empty, 40, 4))[1]);
    }

    public function test_out_of_range_formats_are_refused(): void {
        $this->expectException(\coding_exception::class);
        wav::from_pcm16('', 0, 1);
    }

    public function test_the_rate_is_read_from_the_mime_type(): void {
        $this->assertSame(24000, wav::rate_from_mime('audio/L16;codec=pcm;rate=24000'));
        $this->assertSame(16000, wav::rate_from_mime('audio/L16; rate=16000'));
        $this->assertSame(24000, wav::rate_from_mime('audio/L16'));
        $this->assertSame(22050, wav::rate_from_mime('', 22050));
    }

    public function test_the_endpoint_puts_the_model_in_the_path_and_never_the_key(): void {
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash-tts:generateContent',
            gemini_tts::endpoint('gemini-3.8-flash-tts'));
        foreach (['', 'Gemini-3.8', '../v1/files', 'gemini?key=x', 'gemini:streamGenerateContent', "a\nb", 'a/b'] as $bad) {
            $this->assertSame('', gemini_tts::endpoint($bad), $bad);
        }
        $headers = gemini_tts::headers('AIzaTESTKEY');
        $this->assertContains('x-goog-api-key: AIzaTESTKEY', $headers);
        $this->assertContains('Content-Type: application/json', $headers);
    }

    public function test_the_request_body(): void {
        $this->assertSame(
            '{"contents":[{"parts":[{"text":"Hello there"}]}],"generationConfig":{"responseModalities":["AUDIO"],'
            . '"speechConfig":{"voiceConfig":{"prebuiltVoiceConfig":{"voiceName":"Kore"}}}}}',
            json_encode(gemini_tts::build_body('Hello there', 'Kore'))
        );
    }

    public function test_only_a_gemini_voice_reaches_gemini(): void {
        $known = voice_registry::voices_for('gemini');
        $this->assertSame('Puck', gemini_tts::pick_voice('puck', 'Kore', $known), 'case-insensitive, canonical case');
        $this->assertSame('Kore', gemini_tts::pick_voice('shimmer', 'Kore', $known), 'an OpenAI voice is replaced');
        $this->assertSame('Charon', gemini_tts::pick_voice('', 'Charon', $known));
        $this->assertSame('nova', tts_request::voice('openai', 'nova', 'shimmer'), 'other providers unchanged');
        $this->assertSame('shimmer', tts_request::voice('openai', '', 'shimmer'));
    }

    public function test_the_audio_is_read_and_wrapped(): void {
        $pcm = str_repeat(pack('v', 1234), 50);
        $body = json_encode([
            'candidates' => [['content' => ['parts' => [
                ['inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => base64_encode($pcm)]],
            ]]]],
            'usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 412, 'totalTokenCount' => 419],
        ]);
        $parsed = gemini_tts::parse_response($body);
        $this->assertSame('audio/wav', $parsed['mime']);
        $this->assertSame(wav::from_pcm16($pcm, 24000), $parsed['wav']);
        $this->assertSame(7, $parsed['prompt_tokens']);
        $this->assertSame(412, $parsed['output_tokens']);

        $audio = tts_request::extract_audio('gemini', $body);
        $this->assertSame('audio/wav', $audio['type']);
        $this->assertSame(substr($audio['audio'], 0, 4), 'RIFF');
        $this->assertSame(412, $audio['output_tokens']);
    }

    public function test_snake_case_a_later_part_and_a_wrapped_wav_are_handled(): void {
        $wav = wav::from_pcm16(str_repeat("\x00\x01", 4), 16000);
        $body = json_encode(['candidates' => [['content' => ['parts' => [
            ['text' => 'ignored'],
            ['inline_data' => ['mime_type' => 'audio/wav', 'data' => base64_encode($wav)]],
        ]]]]]);
        $parsed = gemini_tts::parse_response($body);
        $this->assertSame($wav, $parsed['wav'], 'a RIFF payload is not wrapped twice');
        $this->assertSame(0, $parsed['output_tokens']);
        $this->assertNull(tts_request::extract_audio('gemini', $body)['output_tokens'], 'no count reported is null, not 0');
    }

    public function test_an_answer_without_audio_is_not_audio(): void {
        foreach ([
            '',
            'not json',
            '{"candidates":[{"finishReason":"SAFETY"}]}',
            '{"candidates":[{"content":{"parts":[{"text":"I cannot"}]}}]}',
            '{"candidates":[{"content":{"parts":[{"inlineData":{"data":"!!!not base64!!!"}}]}}]}',
            '{"error":{"code":400}}',
        ] as $body) {
            $this->assertNull(tts_request::extract_audio('gemini', $body), $body);
        }
        $this->assertNull(tts_request::extract_audio('gemini', false));
        // The mp3 providers keep the old guard.
        $this->assertNull(tts_request::extract_audio('openai', '{"error":"moderation"}'));
        $this->assertNull(tts_request::extract_audio('xai', ''));
        $this->assertSame(['audio' => "\xFF\xFBmp3", 'type' => 'audio/mpeg', 'prompt_tokens' => null, 'output_tokens' => null],
            tts_request::extract_audio('openai', "\xFF\xFBmp3"));
    }

    /** OpenAI and xAI bodies are what tts.php sent before 8.1.0; only the OpenAI model is no longer a literal. */
    public function test_openai_and_xai_requests_are_unchanged(): void {
        $openai = tts_request::build(['provider' => 'openai', 'apikey' => 'sk-test', 'endpoint' => 'https://api.openai.com/v1/audio/speech'],
            'tts-1', 'Hi', 'nova', 'fr');
        $this->assertSame('https://api.openai.com/v1/audio/speech', $openai['url']);
        $this->assertSame('{"model":"tts-1","voice":"nova","input":"Hi","format":"mp3"}', $openai['body']);
        $this->assertSame(['Authorization: Bearer sk-test', 'Content-Type: application/json'], $openai['headers']);
        $this->assertSame(30, $openai['timeout']);

        $xai = tts_request::build(['provider' => 'xai', 'apikey' => 'xai-test', 'endpoint' => 'https://api.x.ai/v1/tts'],
            'grok-tts', 'Hi', 'eve', '');
        $this->assertSame('{"text":"Hi","voice_id":"eve","language":"en"}', $xai['body']);
        $this->assertSame('https://api.x.ai/v1/tts', $xai['url']);
    }

    public function test_the_gemini_request_never_carries_the_key_in_the_url(): void {
        $req = tts_request::build(['provider' => 'gemini', 'apikey' => 'AIzaSECRET', 'endpoint' => 'ignored'],
            'gemini-3.8-flash-lite-tts', 'Hello', 'Kore', 'en');
        $this->assertSame(gemini_tts::BASE_URL . 'gemini-3.8-flash-lite-tts:generateContent', $req['url']);
        $this->assertStringNotContainsString('AIzaSECRET', $req['url']);
        $this->assertStringNotContainsString('AIzaSECRET', $req['body']);
        $this->assertContains('x-goog-api-key: AIzaSECRET', $req['headers']);
        $this->assertSame(json_encode(gemini_tts::build_body('Hello', 'Kore')), $req['body']);
        $this->assertSame('', tts_request::build(['provider' => 'gemini', 'apikey' => 'k'], 'Bad/Model', 'x', 'Kore', '')['url']);
    }
}
