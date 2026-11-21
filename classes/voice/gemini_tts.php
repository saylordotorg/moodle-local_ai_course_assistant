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

namespace local_ai_course_assistant\voice;

defined('MOODLE_INTERNAL') || die();

/**
 * Gemini text to speech: the request tts.php sends and how the audio is read back. No network here.
 *
 * Request: POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent with the key in
 * the x-goog-api-key header (never in the URL, where it would land in proxy and server logs), and a body asking for
 * AUDIO with one prebuilt voice. Response: base64 PCM (s16le, 24 kHz, mono) in
 * candidates[0].content.parts[0].inlineData, which wav::from_pcm16() turns into something a browser plays.
 *
 * The shape follows Google's Gemini 2.5 TTS documentation. It wasn't checked against the 3.8 models, which
 * nobody here has called yet; see the release notes for the live check to run.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gemini_tts {
    /** @var string API root; the model and method are appended. */
    public const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * The generateContent URL for a model, or '' for an id that isn't a plain model name.
     *
     * The id goes into the path, so anything beyond letters, digits, dots and dashes is refused rather than encoded.
     *
     * @param string $model
     * @return string
     */
    public static function endpoint(string $model): string {
        if (!preg_match('~^[a-z0-9][a-z0-9.\-]{0,99}$~', $model)) {
            return '';
        }
        return self::BASE_URL . $model . ':generateContent';
    }

    /**
     * HTTP headers. The key travels only here.
     *
     * @param string $apikey
     * @return string[]
     */
    public static function headers(string $apikey): array {
        return ['x-goog-api-key: ' . $apikey, 'Content-Type: application/json'];
    }

    /**
     * The request body.
     *
     * @param string $text What to say.
     * @param string $voice A prebuilt voice name such as Kore.
     * @return array
     */
    public static function build_body(string $text, string $voice): array {
        return [
            'contents' => [
                ['parts' => [['text' => $text]]],
            ],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => [
                        'prebuiltVoiceConfig' => ['voiceName' => $voice],
                    ],
                ],
            ],
        ];
    }

    /**
     * The voice to ask for: the learner's choice when it's a Gemini voice (any case), else the row's voice.
     *
     * The browser remembers an OpenAI voice name (shimmer, nova) from before, and Gemini answers an unknown voice
     * with a 400, so a foreign name must not reach it.
     *
     * @param string $requested From the browser; may be anything.
     * @param string $rowvoice The configured voice for the row.
     * @param string[] $known Known Gemini voices.
     * @return string
     */
    public static function pick_voice(string $requested, string $rowvoice, array $known): string {
        foreach ($known as $name) {
            if ($requested !== '' && strcasecmp($requested, $name) === 0) {
                return $name;
            }
        }
        return $rowvoice;
    }

    /**
     * The audio and token counts out of a generateContent response, or null when it carries no audio.
     *
     * @param string $body Raw response body.
     * @return array|null ['wav' => bytes, 'mime' => wav::MIME, 'prompt_tokens' => int, 'output_tokens' => int]
     */
    public static function parse_response(string $body): ?array {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        $pcm = null;
        $mime = '';
        foreach ((array) ($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            $inline = $part['inlineData'] ?? ($part['inline_data'] ?? null);
            if (is_array($inline) && isset($inline['data']) && is_string($inline['data'])) {
                $decoded = base64_decode($inline['data'], true);
                if ($decoded !== false && $decoded !== '') {
                    $pcm = $decoded;
                    $mime = (string) ($inline['mimeType'] ?? ($inline['mime_type'] ?? ''));
                    break;
                }
            }
        }
        if ($pcm === null) {
            return null;
        }
        // A container the provider already wrapped is passed through as is.
        if (str_starts_with($pcm, 'RIFF') && substr($pcm, 8, 4) === 'WAVE') {
            $wav = $pcm;
        } else {
            $wav = wav::from_pcm16($pcm, wav::rate_from_mime($mime), 1);
        }
        $usage = (array) ($data['usageMetadata'] ?? []);
        return [
            'wav' => $wav,
            'mime' => wav::MIME,
            'prompt_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
        ];
    }
}
