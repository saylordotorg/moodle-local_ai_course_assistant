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

namespace local_ai_course_assistant\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function to get an ephemeral token for OpenAI Realtime voice mode.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_realtime_token extends external_api {
    /**
     * Test-only override for the OpenAI Realtime curl call (v5.4.0).
     *
     * Set to ['body' => string, 'http_code' => int] to short-circuit the
     * upstream network call in PHPUnit. Production paths must never set
     * this — the static is always null at boot and is only mutated from
     * within the test suite. Reset to null in test tearDown.
     *
     * @var array{body:string,http_code:int}|null
     */
    public static ?array $test_http_response = null;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            // v5.3.5: pageid + pagetitle so the realtime session is grounded
            // in the same page-content / course-content context the chat
            // endpoint already uses. Optional for backward compatibility.
            'pageid' => new external_value(PARAM_INT, 'Course module id of the current page', VALUE_DEFAULT, 0),
            'pagetitle' => new external_value(PARAM_TEXT, 'Title of the current page or activity', VALUE_DEFAULT, ''),
            'lang' => new external_value(PARAM_ALPHA, 'Learner language preference (ISO 639-1)', VALUE_DEFAULT, ''),
            // v7.0.5: the voice-mode augmentation moved server-side. It used to
            // be assembled in the browser and pushed with session.update, which
            // REPLACES session.instructions -- so it silently overwrote the
            // grounded prompt attached at mint time. The client now sends the
            // mode and its inputs, and the server assembles the whole thing.
            'mode' => new external_value(PARAM_ALPHA, 'Voice mode: conversation|ell', VALUE_DEFAULT, 'conversation'),
            'topic' => new external_value(PARAM_TEXT, 'Chosen speaking topic, if any', VALUE_DEFAULT, ''),
            'phrase' => new external_value(PARAM_TEXT, 'Pronunciation phrase for ELL mode, if any', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Get an ephemeral OpenAI Realtime session token.
     *
     * @param int $courseid
     * @param int $pageid Course module id of the current page (optional).
     * @param string $pagetitle Title of the current page (optional).
     * @param string $lang Learner language preference (optional).
     * @param string $mode Voice mode: 'conversation' or 'ell'. Selects the
     *      augmentation block appended to the grounded prompt server-side.
     * @param string $topic Chosen speaking topic, if the learner picked one.
     * @param string $phrase Pronunciation phrase for ELL mode, if any.
     * @return array
     */
    public static function execute(int $courseid, int $pageid = 0, string $pagetitle = '', string $lang = '',
            string $mode = 'conversation', string $topic = '', string $phrase = ''): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'pageid' => $pageid,
            'pagetitle' => $pagetitle,
            'lang' => $lang,
            'mode' => $mode,
            'topic' => $topic,
            'phrase' => $phrase,
        ]);

        $coursecontext = \context_course::instance($params['courseid']);
        self::validate_context($coursecontext);
        require_capability('local/ai_course_assistant:use', $coursecontext);

        // F80: throttle the mint. Each token opens a paid realtime session and
        // nothing here wrote a usage row, so this was the one paid endpoint
        // with neither a request limit nor spend accounting. Six per five
        // minutes is ample for genuine reconnects; a mint loop is not.
        global $USER;
        if (\local_ai_course_assistant\rate_limiter::is_rate_limited($USER->id, 'realtime_mint', 6, 300)) {
            throw new \moodle_exception('soapbox:rate_limited', 'local_ai_course_assistant');
        }

        // v7.0.5 security fix: enforce the voice kill switch HERE, not only in
        // the UI. Until now `realtime_enabled` was read in exactly one
        // non-language file — hook_callbacks, which decides whether to draw the
        // button — so switching voice off in admin settings hid the control but
        // left this endpoint fully live. Any learner holding :use could POST to
        // it directly and mint a real OpenAI Realtime client_secret, at roughly
        // $18/hour for a held session. An admin turning a feature off must
        // actually turn it off.
        //
        // Checked after the capability check so an unauthorised caller still
        // gets the authorisation error rather than learning the feature state.
        if (!get_config('local_ai_course_assistant', 'realtime_enabled')) {
            throw new \moodle_exception(
                'error',
                'local_ai_course_assistant',
                '',
                'Voice mode is disabled on this site.'
            );
        }

        // The emergency kill switch is enforced inside voice_registry::resolve(),
        // which returns null when it is engaged; the $cfg === null branch below
        // turns that into a refusal.

        // v5.3.5: build the same system prompt the chat endpoint uses, then
        // append a small voice-mode tail (no SOLA_NEXT markers, prefer
        // shorter spoken responses, no markdown). The realtime session will
        // pick this up on the first session.update from the client.
        try {
            $systemprompt = \local_ai_course_assistant\context_builder::build_system_prompt(
                (int)$params['courseid'],
                (int)$USER->id,
                (string)$params['lang'],
                [],
                (int)$params['pageid'],
                (string)$params['pagetitle'],
                ''
            );
            $hascurrentpage = ((int)$params['pageid'] > 0 && (string)$params['pagetitle'] !== '');
            $pagetitleq = (string)$params['pagetitle'];
            $voicetail = "\n\n## Voice mode (overrides any conflicting style above)\n"
                . "You are speaking, not writing. Keep replies short — usually one or two "
                . "sentences. Plain spoken language only: no markdown, no bracketed tags "
                . "or markers in the SPOKEN portion of your reply. Pause naturally between "
                . "ideas so the learner can interject. When the learner starts speaking, "
                . "stop your current sentence and listen.\n\n"
                . "Chip suggestions in voice mode: at the very END of each reply, after the "
                . "spoken portion, append the SOLA_NEXT block exactly as in chat: "
                . "[SOLA_NEXT]<chip 1>||<chip 2>||<chip 3>||<chip 4>[/SOLA_NEXT]. The audio "
                . "stream skips the brackets and pipes naturally — they exist only so the "
                . "on-screen UI can render four clickable follow-up chips below the "
                . "transcript. Each chip is a short (3-8 word) actionable prompt specific "
                . "to what was just discussed (e.g. \"Quiz me on this\", \"Give me an "
                . "example\", \"Show me a real case\", \"What's next?\"). NEVER emit literal "
                . "placeholder text like \"chip 1\" or \"suggestion 1\" — those are shapes "
                . "to be replaced.\n\n"
                . "Anchor the conversation in the course content. If the \"## Current Page "
                . "Content\" section is present, treat that page as the default topic from "
                . "the very first turn. Do NOT open with generic icebreakers like asking "
                . "about the learner's favourite hobby, weekend, or unrelated personal "
                . "topics. The learner is here to study, not chat.\n\n"
                . "Begin every reply with substance, not affirmation filler. Do NOT start "
                . "replies with \"Great!\", \"Absolutely!\", \"Sure!\", \"Of course!\", \"Wonderful!\", "
                . "\"Awesome!\", \"Perfect!\", or any other one-word affirmation. Open directly "
                . "with the answer or with a short relevant question.";
            if ($hascurrentpage) {
                $voicetail .= "\n\nThe learner is currently on the page titled \"" . $pagetitleq
                    . "\". If they have not chosen a different topic, your first spoken turn "
                    . "should briefly summarise that page (one sentence) and ask which part "
                    . "they want to discuss.";
            }
            // v7.0.5 security fix: keep these two apart.
            //
            // $systemprompt carries the jailbreak defences, the course
            // configuration and the learner's personalisation — exactly what
            // sse.php runs an output scrubber to keep away from students on the
            // chat path. This endpoint used to hand the whole thing to the
            // browser as PARAM_RAW, so a learner who wanted the prompt could skip
            // the jailbreak and just read the JSON response.
            //
            // The full text now goes to OpenAI server-side at mint time, and only
            // $voicetail — generic spoken-style guidance with no course or learner
            // data in it — is returned to the client.
            $voicetail .= self::mode_block(
                (string) $params['mode'],
                (string) $params['topic'],
                (string) $params['phrase'],
                $pagetitleq
            );
            $fullinstructions = $systemprompt . $voicetail;
            // OpenAI Realtime rejects session.instructions that contain a
            // reserved special token and fails the whole session with
            // "instructions contain a reserved special token". The chat
            // jailbreak-defense line cites one such token (<|im_start|>) as an
            // example of text to ignore; the chat APIs tolerate it, but Realtime
            // validates strictly. Neutralize any <|...|> token so voice mode can
            // start. The surrounding instruction keeps its meaning.
            $fullinstructions = preg_replace('/<\|[a-zA-Z0-9_\-]+\|>/', '[special token]', $fullinstructions);
            $clientinstructions = preg_replace('/<\|[a-zA-Z0-9_\-]+\|>/', '[special token]', $voicetail);
        } catch (\Throwable $e) {
            // Fail CLOSED. The old handler blanked the instructions and minted
            // anyway, so a prompt-build failure produced a live voice session
            // running on provider defaults -- no persona, no fencing, none of
            // the security section's jailbreak defenses -- for its whole life.
            // sse.php fails closed on the same builder; voice must too.
            debugging('realtime instructions build failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            throw new \moodle_exception('error:realtime_unavailable', 'local_ai_course_assistant');
        }

        // Resolve active Realtime provider via the voice_providers registry.
        $cfg = \local_ai_course_assistant\voice_registry::resolve(
            \local_ai_course_assistant\voice_registry::CAPABILITY_REALTIME
        );
        if ($cfg === null) {
            throw new \moodle_exception(
                'error',
                'local_ai_course_assistant',
                '',
                'No voice provider configured for Realtime.'
            );
        }

        // xAI Realtime: the master API key must never leave the server, so
        // we mint a short-lived HS256 JWT scoped to this learner + course +
        // voice + a fresh nonce and return the proxy URL with the token
        // embedded. The standalone `services/xai_rt_proxy/` daemon validates
        // the token and opens the upstream WebSocket to api.x.ai itself.
        if ($cfg['provider'] === 'xai') {
            $proxyurl = get_config('local_ai_course_assistant', 'xai_proxy_url');
            $jwtsecret = get_config('local_ai_course_assistant', 'xai_proxy_jwt_secret');
            if (empty($proxyurl) || empty($jwtsecret)) {
                throw new \moodle_exception(
                    'error',
                    'local_ai_course_assistant',
                    '',
                    'xAI Realtime proxy is not configured. Set xai_proxy_url and xai_proxy_jwt_secret in SOLA admin settings, or switch voice to OpenAI.'
                );
            }
            // SSRF check: the proxy URL is wss://; the validator wants https://
            // for the host-and-IP-range check, so map for validation only.
            $proxyurlforcheck = preg_replace('/^wss:\/\//i', 'https://', $proxyurl);
            if (!\local_ai_course_assistant\security::is_safe_provider_url($proxyurlforcheck)) {
                throw new \moodle_exception(
                    'error',
                    'local_ai_course_assistant',
                    '',
                    'xAI Realtime proxy URL failed SSRF validation.'
                );
            }
            $now = time();
            $header = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
            $payload = self::b64url(json_encode([
                'sub'      => (int)$USER->id,
                'courseid' => (int)$params['courseid'],
                'provider' => 'xai',
                'voice'    => $cfg['voice'],
                'iat'      => $now,
                'nbf'      => $now,
                'exp'      => $now + 60,
                'nonce'    => bin2hex(random_bytes(12)),
            ]));
            $sig = self::b64url(hash_hmac('sha256', $header . '.' . $payload, $jwtsecret, true));
            $jwt = $header . '.' . $payload . '.' . $sig;
            $sep = (strpos($proxyurl, '?') === false) ? '?' : '&';
            return [
                // Empty token: the proxy URL carries the auth. The client must
                // not pass a subprotocol bearer for the xAI-via-proxy path.
                'token'    => '',
                'voice'    => $cfg['voice'],
                'provider' => 'xai',
                'endpoint' => $proxyurl . $sep . 'token=' . rawurlencode($jwt),
                // Voice-style tail only; see the security note above.
                'instructions' => $clientinstructions,
            ];
        }

        // OpenAI: mint an ephemeral client secret so the key never reaches the browser.
        [$response, $httpcode] = self::call_openai_realtime((string) $cfg['apikey'], $fullinstructions);

        if ($httpcode !== 200) {
            $errdata = json_decode($response, true);
            $errmsg = isset($errdata['error']['message'])
                ? $errdata['error']['message']
                : 'API error ' . $httpcode . ': ' . substr($response, 0, 300);
            throw new \moodle_exception('error', 'local_ai_course_assistant', '', $errmsg);
        }

        $data = json_decode($response, true);
        $token = $data['client_secret']['value'] ?? ($data['value'] ?? '');
        if (empty($token)) {
            throw new \moodle_exception(
                'error',
                'local_ai_course_assistant',
                '',
                'Unexpected response from OpenAI Realtime API: ' . substr($response, 0, 200)
            );
        }

        return [
            'token'    => $token,
            'voice'    => $cfg['voice'],
            'provider' => 'openai',
            'endpoint' => $cfg['endpoint'],
            // Voice-style tail only; the grounded prompt went to OpenAI at mint time.
            'instructions' => $clientinstructions,
        ];
    }

    /**
     * base64url encoder used for the JWT header/payload/signature.
     *
     * @param string $data
     * @return string
     */
    private static function b64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * The voice-mode augmentation, moved out of the browser in v7.0.5.
     *
     * chat.js used to build this and hand it to Realtime.connect(), which pushed
     * it with session.update. That call REPLACES session.instructions rather than
     * merging, so it overwrote the grounded prompt attached at mint time — every
     * OpenAI voice session ran with no course content, no learner personalisation
     * and no jailbreak defences, while the surviving text still referred to a
     * "## Current Page Content" section that was no longer in the session.
     *
     * Text kept byte-for-byte as it was in the client so voice behaviour does not
     * change; only where it is assembled does.
     *
     * @param string $mode conversation|ell
     * @param string $topic Chosen speaking topic, if any.
     * @param string $phrase Pronunciation phrase, if any.
     * @param string $pagetitle Current page title, used as a topic fallback.
     * @return string
     */
    private static function mode_block(string $mode, string $topic, string $phrase, string $pagetitle): string {
        if (strtolower($mode) === 'ell') {
            $out = "\n\n" . 'You are SOLA in a real-time voice conversation. Keep responses brief (2-3 sentences). '
                . '[ELL Coaching Mode] You are an English conversation and pronunciation coach. '
                . 'Wait for the user to speak first, then respond. '
                . 'While having natural conversation: gently correct grammar errors by modeling the correct form '
                . '("You might say: \'...\'"), offer pronunciation tips when speech sounds unclear. '
                . 'For pronunciation practice, speak a target phrase clearly, then listen to the learner repeat it '
                . 'and give specific encouraging feedback. '
                . 'Do NOT include any markup, tags, or bracketed instructions in your responses. '
                . 'Keep your output as natural spoken language only.';
            if ($phrase !== '') {
                $out .= "\n\n" . 'The student wants to practice pronouncing: "' . $phrase
                    . '". Begin by welcoming the student, briefly explain the exercise, say this phrase clearly, '
                    . 'then ask them to repeat it.';
            } else {
                $out .= "\n\n" . 'Begin by welcoming the student, briefly explain the exercise, then invite them '
                    . 'to try a word or short phrase from the current page.';
            }
            $out .= ' Base your pronunciation feedback and examples on the current page and nearby course '
                . 'content when possible.';
            return $out;
        }

        // Default: ordinary voice conversation.
        if ($topic === '' && $pagetitle !== '') {
            // Same fallback the client applied: anchor on the page the learner is
            // reading rather than let the model improvise an icebreaker.
            $topic = $pagetitle;
        }
        $out = "\n\n## Voice Conversation\n"
            . 'Keep every turn grounded in the course content above. '
            . 'Respond naturally to follow-up questions and let the learner interrupt or redirect. '
            . 'For your first spoken turn, give a one-sentence welcome and one specific question '
            . 'about the topic below. Do NOT explain how the speaking practice works, do NOT ask '
            . 'about hobbies or unrelated personal topics, and do NOT begin with affirmation '
            . 'fillers like "Great!" or "Absolutely!".';
        if ($topic !== '') {
            $out .= ' Topic for this session: "' . $topic . '".';
        } else {
            $out .= ' If no specific topic is set, ask the learner which course topic they want to talk through.';
        }
        return $out;
    }

    /**
     * Hit the OpenAI Realtime ephemeral-token endpoint.
     *
     * Returns [body, http_code]. Tests can short-circuit the upstream
     * network call by populating self::$test_http_response — when that
     * static is set, the curl call is skipped entirely.
     *
     * @param string $apikey OpenAI API key (Bearer).
     * @param string $instructions Grounded system prompt attached to the session
     *      at mint time so it never has to travel via the browser. Empty sends
     *      the bare `{}` body the endpoint used before v7.0.5.
     * @return array{0:string,1:int} [response body, HTTP status]
     */
    private static function call_openai_realtime(string $apikey, string $instructions = ''): array {
        if (self::$test_http_response !== null) {
            return [
                (string) (self::$test_http_response['body'] ?? ''),
                (int) (self::$test_http_response['http_code'] ?? 0),
            ];
        }
        // The grounded system prompt is attached to the session here rather than
        // being handed to the browser to send via session.update. Verified
        // against the live API 2026-08-23: /v1/realtime/client_secrets returns
        // 200 with a usable client_secret both with and without this body.
        $payload = [];
        if ($instructions !== '') {
            $payload['session'] = [
                'type'         => 'realtime',
                'instructions' => $instructions,
            ];
        }
        $body = $payload === [] ? '{}' : json_encode($payload);
        global $CFG;
        require_once($CFG->libdir . '/filelib.php'); // For \curl.
        $endpoint = 'https://api.openai.com/v1/realtime/client_secrets';
        $curl = new \curl();
        $curl->setopt(array_merge([
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT'        => 5,
            'CURLOPT_HTTPHEADER'     => [
                'Authorization: Bearer ' . $apikey,
                'Content-Type: application/json',
            ],
            // Pin to the validated IP, closing the DNS-rebinding window.
        ], \local_ai_course_assistant\security::resolve_pin_options($endpoint)));
        $response = (string) $curl->post($endpoint, $body);
        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
        return [$response, $httpcode];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'token'    => new external_value(PARAM_RAW, 'Ephemeral session token (or raw API key for providers without ephemeral support)'),
            'voice'    => new external_value(PARAM_ALPHANUMEXT, 'Voice identifier'),
            'provider' => new external_value(PARAM_ALPHANUMEXT, 'Provider id (openai|xai)'),
            // v5.1.3: was PARAM_URL, which Moodle\'s webservice layer rejects
            // for wss:// schemes — both providers return wss:// endpoints,
            // and the xAI proxy form additionally carries a JWT in its query
            // string. PARAM_URL validation failure surfaced as the generic
            // "Invalid response value detected" error to the learner the
            // moment voice mode was opened. Server-generated value, not
            // user input, so PARAM_RAW is the right type here.
            'endpoint' => new external_value(PARAM_RAW, 'WebSocket endpoint URL (wss://)'),
            // v5.3.5: full system prompt for the realtime session, including
            // course content, current page text, and a voice-mode tail. The
            // client passes this to the WebSocket via the first session.update.
            'instructions' => new external_value(PARAM_RAW, 'System instructions for the realtime session', VALUE_DEFAULT, ''),
        ]);
    }
}
