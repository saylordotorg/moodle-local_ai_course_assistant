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

/**
 * Text to speech proxy endpoint (OpenAI, xAI, and Gemini since 8.1.0).
 * Accepts POST {text, sesskey, courseid}, returns JSON {audio: base64, type: 'audio/mpeg' or 'audio/wav'}.
 *
 * The model is resolved through voice_model_catalog at call time, so a model the provider has shut down is never
 * sent. If the provider still answers that a configured model doesn't exist, the call is retried once on the
 * catalog's successor, and admins are told.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once('../../config.php');

require_login();
require_sesskey();

\local_ai_course_assistant\security::send_security_headers();
header('Content-Type: application/json');

$text = required_param('text', PARAM_TEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);

// Verify capability.
if ($courseid > 0) {
    $context = context_course::instance($courseid);
} else {
    $context = context_system::instance();
}
// v7.5.7: voice in and out are part of an ordinary chat turn, not a course
// feature, and both buttons are on screen in support mode -- so a support
// learner, who is not enrolled in the support course and holds no role in it,
// must pass here. require_use() falls back to the per-course capability for
// every other request.
\local_ai_course_assistant\support_mode::require_use((int) $courseid, $context);

// Rate limit: 30 TTS requests per 60 seconds per user. TTS is a per-character
// spend vector; without this cap an authenticated learner can burn an unbounded
// amount of OpenAI, xAI or Gemini credit.
if (\local_ai_course_assistant\rate_limiter::is_rate_limited($USER->id, 'tts', 30, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => get_string('chat:error_ratelimit', 'local_ai_course_assistant')]);
    exit;
}

// Resolve active TTS provider via the voice_providers registry. This is also
// where the spend cap (failover or pause) and the emergency kill switch apply.
$cfg = \local_ai_course_assistant\voice_registry::resolve(
    \local_ai_course_assistant\voice_registry::CAPABILITY_TTS
);
if ($cfg === null) {
    http_response_code(503);
    echo json_encode(['error' => get_string('error_no_tts_key', 'local_ai_course_assistant')]);
    exit;
}

$provider = (string) $cfg['provider'];
$voice = \local_ai_course_assistant\voice\tts_request::voice(
    $provider,
    optional_param('voice', '', PARAM_ALPHA),
    (string) $cfg['voice']
);
$lang = optional_param('lang', 'en', PARAM_ALPHA) ?: 'en';
// The catalog-resolved model (8.1.0). Before 8.1.0 this was the literal tts-1 / grok-tts, which are the catalog
// defaults, so a row without a model behaves as before.
$model = (string) ($cfg['model'] ?? '');

// Truncate text to avoid excessive API costs.
$text = mb_substr(trim($text), 0, 4096);

/**
 * Send one text to speech request.
 *
 * @param array $request tts_request::build() output.
 * @return array{0: int, 1: string|false} HTTP status (0 when refused before sending) and body.
 */
function local_ai_course_assistant_tts_send(array $request): array {
    global $CFG;
    if ($request['url'] === '' || !\local_ai_course_assistant\security::is_safe_provider_url($request['url'])) {
        return [0, false];
    }
    require_once($CFG->libdir . '/filelib.php'); // For \curl.
    $curl = new \curl();
    $curl->setopt(array_merge([
        'CURLOPT_RETURNTRANSFER' => true,
        'CURLOPT_TIMEOUT'        => $request['timeout'],
        'CURLOPT_HTTPHEADER'     => $request['headers'],
        // Pin to the validated IP, closing the DNS-rebinding window.
    ], \local_ai_course_assistant\security::resolve_pin_options($request['url'])));
    $response = $curl->post($request['url'], $request['body']);
    return [(int) ($curl->get_info()['http_code'] ?? 0), $response];
}

$request = \local_ai_course_assistant\voice\tts_request::build($cfg, $model, $text, $voice, $lang);
if ($request['url'] === '' || !\local_ai_course_assistant\security::is_safe_provider_url($request['url'])) {
    http_response_code(502);
    echo json_encode(['error' => 'TTS endpoint failed SSRF validation']);
    exit;
}
[$httpcode, $response] = local_ai_course_assistant_tts_send($request);

// 8.1.0: the provider says the model doesn't exist (shut down early, renamed, or mistyped). Retry ONCE on the
// catalog's successor; never on a model outside the catalog. xAI's body carries no model, so this is for the
// providers that name one.
if ($httpcode !== 200 && $provider !== 'xai' && $model !== ''
        && \local_ai_course_assistant\voice_model_catalog::is_model_not_found($httpcode, (string) $response)) {
    $catalogprovider = \local_ai_course_assistant\voice_model_catalog::has_provider($provider) ? $provider : 'openai';
    $fallback = \local_ai_course_assistant\voice_model_catalog::fallback_after_not_found(
        $catalogprovider,
        \local_ai_course_assistant\voice_model_catalog::TTS,
        $model
    );
    if ($fallback !== null) {
        $retrycfg = $cfg;
        if ($provider === 'gemini') {
            $retrycfg['endpoint'] = \local_ai_course_assistant\voice_registry::endpoint_for('gemini', 'tts', $fallback);
        }
        $retry = \local_ai_course_assistant\voice\tts_request::build($retrycfg, $fallback, $text, $voice, $lang);
        [$httpcode, $response] = local_ai_course_assistant_tts_send($retry);
        $worked = $httpcode === 200
            && \local_ai_course_assistant\voice\tts_request::extract_audio($provider, $response) !== null;
        \local_ai_course_assistant\voice_model_watch::record_fallback(
            $provider,
            \local_ai_course_assistant\voice_model_catalog::TTS,
            $model,
            $fallback,
            $worked,
            (int) $USER->id
        );
        $model = $fallback;
    }
}

if ($httpcode !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'TTS API error ' . $httpcode]);
    exit;
}

// Some providers/proxies return HTTP 200 with a JSON error or empty body (e.g.
// a moderation refusal). Returning that as base64 "audio" makes the client
// decode garbage and silently fall back to the browser voice with no
// diagnostic. extract_audio() rejects a non-audio 200 (mp3 never starts with
// '{'; a Gemini answer must carry inline audio data).
$audio = \local_ai_course_assistant\voice\tts_request::extract_audio($provider, $response);
if ($audio === null) {
    http_response_code(502);
    echo json_encode(['error' => 'TTS provider returned no audio']);
    exit;
}

// Log TTS cost. Gemini reports its own token counts; otherwise approximate from
// the character count (~4 chars/token), as before.
$charcount = mb_strlen($text);
$prompttokens = $audio['prompt_tokens'] ?? (int) ceil($charcount / 4);
$outputtokens = $audio['output_tokens'] ?? 0;
try {
    $conv = $DB->get_record('local_ai_course_assistant_convs', [
        'userid' => $USER->id,
        'courseid' => $courseid > 0 ? $courseid : SITEID,
    ]);
    if ($conv) {
        \local_ai_course_assistant\conversation_manager::add_message(
            $conv->id,
            $USER->id,
            $courseid > 0 ? $courseid : SITEID,
            'system',
            '[TTS]',
            0,
            // F81: the capability suffix belongs in interaction_type (arg 11),
            // where spend_guard's voice bucket and token_analytics' categories
            // read it -- not welded onto the provider name.
            $provider,
            $prompttokens,
            $outputtokens,
            $model,
            \local_ai_course_assistant\voice_registry::interaction_type($provider, 'tts')
        );
    }
} catch (\Throwable $e) {
    unset($e);
    // Non-critical — don't block audio delivery.
}

echo json_encode([
    'audio' => base64_encode($audio['audio']),
    'type'  => $audio['type'],
]);
