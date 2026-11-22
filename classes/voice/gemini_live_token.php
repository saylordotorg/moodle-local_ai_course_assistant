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
 * Gemini Live ephemeral tokens: the one place that knows the HTTP shape of Google's auth_tokens call.
 *
 * The browser must never see the site's Gemini key, so get_realtime_token mints a short-lived, single-use token
 * here and hands only that to the browser, which opens the Live WebSocket with it.
 *
 * Request: POST https://generativelanguage.googleapis.com/v1alpha/auth_tokens with the key in the x-goog-api-key
 * header and a body that says how often and until when the token can be used, and which session setup it is locked
 * to (bidiGenerateContentSetup plus a fieldMask naming the locked fields): the model, the system instruction, AUDIO
 * as the only response modality, the voice and both transcriptions. A setup the browser sends can't change a locked
 * field. Response: the token is the "name" field ("auth_tokens/..."). The browser connects to
 * BidiGenerateContentConstrained with ?access_token=<token>.
 *
 * UNVERIFIED: this shape was written from memory of Google's ephemeral token documentation and the google-genai SDKs
 * (auth_tokens.create with live_connect_constraints, lock_additional_fields), not checked against a live call. In
 * particular the exact fieldMask paths, whether "model" belongs in the mask, and whether the setup sits under
 * bidiGenerateContentSetup.setup are best guesses. Everything that could be wrong is in this class, so a live check
 * that fails is fixed here and in its test, nowhere else.
 *
 * Pure functions apart from call(), which tests short-circuit with $test_http_response.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gemini_live_token {
    /** @var string The auth_tokens endpoint (v1alpha: ephemeral tokens are an alpha API). */
    public const TOKEN_URL = 'https://generativelanguage.googleapis.com/v1alpha/auth_tokens';

    /** @var string The Live WebSocket for a constrained (ephemeral token) session. The token goes in the query. */
    public const WS_URL = 'wss://generativelanguage.googleapis.com/ws/'
        . 'google.ai.generativelanguage.v1alpha.GenerativeService.BidiGenerateContentConstrained';

    /** @var int Seconds the token can be used to START a session. Short: the browser connects right away. */
    public const NEW_SESSION_WINDOW = 60;

    /** @var int Seconds added to the session cap for the token's hard expiry (connect time and the goodbye). */
    public const EXPIRY_GRACE = 30;

    /** @var string[] Fields of the setup the token locks, as fieldMask paths. */
    public const LOCKED_FIELDS = [
        'model',
        'systemInstruction',
        'generationConfig.responseModalities',
        'generationConfig.speechConfig',
        'inputAudioTranscription',
        'outputAudioTranscription',
    ];

    /**
     * Test-only override for the upstream call: ['body' => string, 'http_code' => int]. Null in production.
     *
     * @var array{body:string,http_code:int}|null
     */
    public static ?array $test_http_response = null;

    /** @var array|null The last request body call() was given, kept for tests only. */
    public static ?array $last_request = null;

    /**
     * An RFC 3339 UTC timestamp as Google's API wants it.
     *
     * @param int $time
     * @return string
     */
    public static function rfc3339(int $time): string {
        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    /**
     * The token request body. No network, no config: everything comes in as arguments.
     *
     * @param string $model A plain model id (gemini-3.8-live); refused when it isn't one.
     * @param string $instructions The full grounded system instruction, already cleaned.
     * @param string $voice A prebuilt voice name.
     * @param int $maxsessionseconds The session cap; the token dies EXPIRY_GRACE seconds after it.
     * @param int $now Unix time.
     * @return array
     * @throws \coding_exception for a model id that isn't plain, or a non-positive cap
     */
    public static function build_request(string $model, string $instructions, string $voice, int $maxsessionseconds,
            int $now): array {
        if (!preg_match('~^[a-z0-9][a-z0-9.\-]{0,99}$~', $model)) {
            throw new \coding_exception('Gemini Live model id is not a plain model name');
        }
        if ($maxsessionseconds < 1) {
            throw new \coding_exception('Gemini Live session cap must be positive');
        }
        $setup = [
            'model' => 'models/' . $model,
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => [
                        'prebuiltVoiceConfig' => ['voiceName' => $voice],
                    ],
                ],
            ],
            'systemInstruction' => [
                'parts' => [['text' => $instructions]],
            ],
            // Empty objects turn transcription on; json_encode must write {} not [].
            'inputAudioTranscription' => new \stdClass(),
            'outputAudioTranscription' => new \stdClass(),
        ];
        return [
            'uses' => 1,
            'expireTime' => self::rfc3339($now + $maxsessionseconds + self::EXPIRY_GRACE),
            'newSessionExpireTime' => self::rfc3339($now + self::NEW_SESSION_WINDOW),
            'bidiGenerateContentSetup' => ['setup' => $setup],
            'fieldMask' => implode(',', self::LOCKED_FIELDS),
        ];
    }

    /**
     * HTTP headers. The site key travels only here, never in a URL.
     *
     * @param string $apikey
     * @return string[]
     */
    public static function headers(string $apikey): array {
        return ['x-goog-api-key: ' . $apikey, 'Content-Type: application/json'];
    }

    /**
     * The token out of an auth_tokens response, or '' when there is none.
     *
     * Only an "auth_tokens/..." name made of URL-safe characters is accepted, because it goes into the WebSocket
     * URL's query string.
     *
     * @param string $body
     * @return string
     */
    public static function parse_response(string $body): string {
        $data = json_decode($body, true);
        $name = is_array($data) ? (string) ($data['name'] ?? '') : '';
        if (!preg_match('~^auth_tokens/[A-Za-z0-9_\-.]{8,512}$~', $name)) {
            return '';
        }
        return $name;
    }

    /**
     * Google's error message out of a failed response, cut short, with nothing that looks like a key.
     *
     * @param int $status
     * @param string $body
     * @return string
     */
    public static function error_message(int $status, string $body): string {
        $data = json_decode($body, true);
        $msg = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
        if ($msg === '') {
            $msg = 'HTTP ' . $status;
        }
        $msg = preg_replace('~AIza[0-9A-Za-z_\-]{10,}|auth_tokens/[A-Za-z0-9_\-.]+~', '[redacted]', $msg);
        return 'Gemini Live token request failed: ' . \core_text::substr($msg, 0, 200);
    }

    /**
     * POST the token request. Returns [body, http status].
     *
     * @param string $apikey
     * @param array $request From build_request().
     * @return array{0:string,1:int}
     */
    public static function call(string $apikey, array $request): array {
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            self::$last_request = $request;
        }
        if (self::$test_http_response !== null) {
            return [
                (string) (self::$test_http_response['body'] ?? ''),
                (int) (self::$test_http_response['http_code'] ?? 0),
            ];
        }
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt(array_merge([
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => 8,
            'CURLOPT_HTTPHEADER' => self::headers($apikey),
        ], \local_ai_course_assistant\security::resolve_pin_options(self::TOKEN_URL)));
        $response = (string) $curl->post(self::TOKEN_URL, json_encode($request));
        $status = (int) ($curl->get_info()['http_code'] ?? 0);
        return [$response, $status];
    }
}
