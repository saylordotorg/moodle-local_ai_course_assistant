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

use local_ai_course_assistant\voice_registry;

/**
 * The text to speech request tts.php sends, per provider, and the audio read out of the answer (8.1.0).
 *
 * Pure: no network, no config. tts.php does the sending, the guards and the spend record. The OpenAI and xAI
 * bodies are byte for byte what tts.php built before 8.1.0, except that the OpenAI model now comes from the row or
 * the catalog instead of a literal (the catalog default is the same tts-1).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tts_request {
    /**
     * Build one request.
     *
     * @param array $cfg voice_registry::resolve() output: provider, apikey, endpoint.
     * @param string $model The model to call, already resolved through the catalog.
     * @param string $text
     * @param string $voice
     * @param string $lang Two-letter language, used by xAI only.
     * @return array{url: string, headers: string[], body: string, timeout: int}
     */
    public static function build(array $cfg, string $model, string $text, string $voice, string $lang): array {
        $provider = (string) ($cfg['provider'] ?? '');
        if ($provider === 'gemini') {
            return [
                'url' => gemini_tts::endpoint($model),
                'headers' => gemini_tts::headers((string) $cfg['apikey']),
                'body' => json_encode(gemini_tts::build_body($text, $voice)),
                // Gemini returns the whole clip at once and long passages take a while.
                'timeout' => 60,
            ];
        }
        if ($provider === 'xai') {
            $body = json_encode([
                'text'     => $text,
                'voice_id' => $voice,
                'language' => $lang !== '' ? $lang : 'en',
            ]);
        } else {
            $body = json_encode([
                'model'  => $model,
                'voice'  => $voice,
                'input'  => $text,
                'format' => 'mp3',
            ]);
        }
        return [
            'url' => (string) ($cfg['endpoint'] ?? ''),
            'headers' => ['Authorization: Bearer ' . $cfg['apikey'], 'Content-Type: application/json'],
            'body' => $body,
            'timeout' => 30,
        ];
    }

    /**
     * The voice to send: the browser's choice, except that Gemini only gets one of its own voices.
     *
     * @param string $provider
     * @param string $requested From the browser (PARAM_ALPHA).
     * @param string $rowvoice The configured voice.
     * @return string
     */
    public static function voice(string $provider, string $requested, string $rowvoice): string {
        if ($provider === 'gemini') {
            return gemini_tts::pick_voice($requested, $rowvoice, voice_registry::voices_for('gemini'));
        }
        return $requested !== '' ? $requested : $rowvoice;
    }

    /**
     * The audio in a 200 answer, or null when there is none.
     *
     * OpenAI and xAI send audio bytes; a body starting with '{' is a JSON refusal, never audio. Gemini sends JSON
     * with base64 PCM, which is wrapped as WAV.
     *
     * @param string $provider
     * @param string|false $response
     * @return array|null ['audio' => bytes, 'type' => MIME, 'prompt_tokens' => ?int, 'output_tokens' => ?int]
     *         (token counts are null when the provider doesn't report them)
     */
    public static function extract_audio(string $provider, $response): ?array {
        if ($response === false || $response === '') {
            return null;
        }
        if ($provider === 'gemini') {
            $parsed = gemini_tts::parse_response((string) $response);
            if ($parsed === null) {
                return null;
            }
            return [
                'audio' => $parsed['wav'],
                'type' => $parsed['mime'],
                'prompt_tokens' => $parsed['prompt_tokens'] > 0 ? $parsed['prompt_tokens'] : null,
                'output_tokens' => $parsed['output_tokens'] > 0 ? $parsed['output_tokens'] : null,
            ];
        }
        if ($response[0] === '{') {
            return null;
        }
        return ['audio' => (string) $response, 'type' => 'audio/mpeg', 'prompt_tokens' => null, 'output_tokens' => null];
    }
}
