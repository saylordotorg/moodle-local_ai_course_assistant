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
 * OpenAI Whisper transcription proxy endpoint.
 * Accepts POST multipart { audio (file), sesskey, courseid, lang }.
 * Returns JSON { text: "transcribed text" }.
 *
 * Used as a fallback for browsers without Web Speech API (e.g. iOS Chrome).
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once('../../config.php');

require_login();

// PHP throws the whole body away when it exceeds post_max_size, and its default
// (8 MB) is well under MAX_AUDIO_BYTES (25 MB). Detect that here, before
// require_sesskey(), because the sesskey was discarded with everything else:
// without this the learner's long recording comes back as an invalid-sesskey
// exception at HTTP 200 and the server log records a CSRF failure that did not
// happen. See security::oversized_post_was_discarded().
if (\local_ai_course_assistant\security::oversized_post_was_discarded($_SERVER, $_POST, $_FILES)) {
    // Same headers as every other response from this endpoint. This branch used
    // to answer before send_security_headers() ran, making it the one reply
    // without nosniff, CSP and X-Frame-Options.
    \local_ai_course_assistant\security::send_security_headers();
    http_response_code(413);
    header('Content-Type: application/json');
    echo json_encode(['error' => get_string(
        'voice:error_toolarge',
        'local_ai_course_assistant',
        \local_ai_course_assistant\security::max_audio_mb_display()
    )]);
    exit;
}

require_sesskey();

\local_ai_course_assistant\security::send_security_headers();
header('Content-Type: application/json');

$courseid = optional_param('courseid', 0, PARAM_INT);
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

// Rate limit: 20 STT requests per 60 seconds per user. Whisper is a per-minute
// spend vector; without this cap an authenticated learner can upload clips in a
// tight loop and rack up cost.
if (\local_ai_course_assistant\rate_limiter::is_rate_limited($USER->id, 'stt', 20, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => get_string('chat:error_ratelimit', 'local_ai_course_assistant')]);
    exit;
}

// Require the uploaded audio file. A size-class upload error means the file WAS
// provided and was too big for upload_max_filesize (PHP default 2 MB) or for a
// form MAX_FILE_SIZE; it leaves tmp_name empty exactly as an absent file does,
// so it has to be separated out or an oversized clip is reported as a missing one.
// is_array guard first: a field posted as audio[] makes ['error'] an
// ARRAY, and (int) on a non-empty array is 1, which is exactly
// UPLOAD_ERR_INI_SIZE. Without this, a malformed upload with no size
// problem is answered "that recording is too large".
$rawerror = $_FILES['audio']['error'] ?? UPLOAD_ERR_NO_FILE;
$uploaderror = is_array($rawerror) ? UPLOAD_ERR_NO_FILE : (int) $rawerror;
if (\local_ai_course_assistant\security::upload_error_is_size($uploaderror)) {
    http_response_code(413);
    echo json_encode(['error' => get_string(
        'voice:error_toolarge',
        'local_ai_course_assistant',
        \local_ai_course_assistant\security::max_audio_mb_display()
    )]);
    exit;
}
if (empty($_FILES['audio']['tmp_name']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['error' => get_string('voice:error_noaudio', 'local_ai_course_assistant')]);
    exit;
}

// Enforce the configured max size (max_audio_mb, default 25 MB, capped by
// PHP's own post_max_size and upload_max_filesize) and an audio MIME
// allowlist before the file ever
// hits the upstream transcription API. Uses finfo so a spoofed Content-Type
// header cannot smuggle a non-audio payload through.
$tmp = $_FILES['audio']['tmp_name'];
$size = filesize($tmp) ?: 0;
if ($size <= 0 || $size > \local_ai_course_assistant\security::max_audio_bytes()) {
    http_response_code(413);
    echo json_encode(['error' => get_string(
        'voice:error_toolarge',
        'local_ai_course_assistant',
        \local_ai_course_assistant\security::max_audio_mb_display()
    )]);
    exit;
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$sniffed = $finfo ? finfo_file($finfo, $tmp) : '';
if ($finfo) {
    finfo_close($finfo);
}
// MediaRecorder audio containers sniff as video/* or octet-stream (not audio/*),
// so a strict audio/*-only check 415'd every real browser recording. Accept the
// container types real recordings produce; see security::is_allowed_audio_upload.
$declaredtype = !empty($_FILES['audio']['type']) ? (string) $_FILES['audio']['type'] : '';
if (!\local_ai_course_assistant\security::is_allowed_audio_upload((string) $sniffed, $declaredtype)) {
    http_response_code(415);
    echo json_encode(['error' => get_string('voice:error_format', 'local_ai_course_assistant')]);
    exit;
}

// Resolve active STT provider via the voice_providers registry.
$cfg = \local_ai_course_assistant\voice_registry::resolve(
    \local_ai_course_assistant\voice_registry::CAPABILITY_STT
);
if ($cfg === null) {
    http_response_code(503);
    echo json_encode(['error' => get_string('voice:error_noprovider', 'local_ai_course_assistant')]);
    exit;
}

// Optional ISO 639-1 language hint (e.g. 'en', 'es').
$lang = optional_param('lang', '', PARAM_ALPHA);

$tmpfile  = $_FILES['audio']['tmp_name'];
$mimetype = !empty($_FILES['audio']['type']) ? $_FILES['audio']['type'] : 'audio/webm';

// Map MIME type to a file extension Whisper will recognise.
$ext_map = [
    'audio/webm'  => 'webm',
    'audio/ogg'   => 'ogg',
    'audio/mp4'   => 'mp4',
    'audio/mpeg'  => 'mp3',
    'audio/wav'   => 'wav',
    'audio/x-wav' => 'wav',
];
$ext      = $ext_map[$mimetype] ?? 'webm';
$filename = 'audio.' . $ext;

if ($cfg['provider'] === 'xai') {
    // xAI STT accepts a multipart upload with the audio file.
    $post = ['file' => new CURLFile($tmpfile, $mimetype, $filename)];
    if (!empty($lang)) {
        $post['language'] = $lang;
    }
    $model = 'grok-stt';
} else {
    // OpenAI protocol: hosted OpenAI (whisper-1) or an OpenAI compatible
    // selfhosted server (faster-whisper / whisper-server). The model name
    // comes from the registry so selfhosted servers can name the Whisper
    // model they have loaded.
    $model = !empty($cfg['model']) ? $cfg['model'] : 'whisper-1';
    // v7.4.4: self-hosted Whisper is FREE, but pricing keys off model_name, not
    // interaction_type -- and 'whisper-1' (or any name a self-hosted server
    // reports) matches the 'whisper' rate-card prefix, so free transcription was
    // being billed at the hosted rate. The comment further down claimed the
    // opposite of what the code did. Prefixing puts the model outside the
    // 'whisper' rate prefix while keeping it a DISTINCT name, so the provenance
    // survives on the row rather than vanishing the way a null model_name would.
    //
    // The prefix resolves to an explicit $0.00 entry in token_cost_manager's
    // rate card. That is deliberate and NOT the same as leaving it unpriced:
    // 'selfhosted_stt' is in analytics::spend_rows_predicate(), so these rows
    // are billable-set rows, and a billable-set row whose model resolves to no
    // rate is precisely what model_registry::unpriced_models() reports as a
    // defect -- which raised a DAILY price-drift alert naming a model that is
    // free by design.
    if (($cfg['provider'] ?? '') === \local_ai_course_assistant\voice_registry::SELFHOSTED_LABEL) {
        $model = 'selfhosted-' . $model;
    }
    $post = [
        'file'  => new CURLFile($tmpfile, $mimetype, $filename),
        'model' => $model,
    ];
    if (!empty($lang)) {
        $post['language'] = $lang;
    }
}

if (!\local_ai_course_assistant\security::is_safe_provider_url($cfg['endpoint'])) {
    // The endpoint failed SSRF validation, which is an administrator's
    // misconfiguration, not anything the learner did or can retry past. The
    // diagnostic, including the URL that was rejected, goes to the server error
    // log rather than the response: it names an internal host an administrator
    // chose, and echoing it back tells whoever is on the other end of this
    // request what is reachable from inside the network. The learner gets the
    // actionable half, which is that transcription is not set up.
    //
    // log_operational_failure(), not debugging(): debugging() writes nothing
    // unless $CFG->debug is DEVELOPER, so on a production site this line would
    // be discarded and the claim above would be false.
    \local_ai_course_assistant\security::log_operational_failure(
        'STT endpoint failed SSRF validation: '
            . \local_ai_course_assistant\security::loggable_endpoint($cfg['endpoint'])
    );
    http_response_code(502);
    echo json_encode(['error' => get_string('voice:error_noprovider', 'local_ai_course_assistant')]);
    exit;
}
// Selfhosted servers are usually keyless behind a trusted network; only
// send an Authorization header when a key is actually configured.
$headers = [];
if (!empty($cfg['apikey'])) {
    $headers[] = 'Authorization: Bearer ' . $cfg['apikey'];
}
require_once($CFG->libdir . '/filelib.php'); // For \curl.
$curl = new \curl();
$curl->setopt(array_merge([
    'CURLOPT_RETURNTRANSFER' => true,
    'CURLOPT_TIMEOUT'        => 30,
    'CURLOPT_HTTPHEADER'     => $headers,
    // Pin to the validated IP, closing the DNS-rebinding window.
], \local_ai_course_assistant\security::resolve_pin_options($cfg['endpoint'])));
// Multipart upload: $post carries a CURLFile plus the model/language fields.
$response = $curl->post($cfg['endpoint'], $post);
$httpcode = (int) ($curl->get_info()['http_code'] ?? 0);

if ($httpcode !== 200) {
    // The upstream status is a diagnostic. A learner reading "Transcription API
    // error 401" learns only that something is broken, and it discloses which
    // upstream failure mode a caller triggered. It goes to the server error log
    // with the host, so an administrator can tell a bad key from a rate limit
    // from an outage, and the learner is told the thing they can act on.
    //
    // log_operational_failure(), not debugging(): debugging() writes nothing
    // unless $CFG->debug is DEVELOPER, which no production site sets.
    \local_ai_course_assistant\security::log_operational_failure(
        'STT provider returned HTTP ' . $httpcode . ' from '
            . \local_ai_course_assistant\security::loggable_endpoint($cfg['endpoint'])
    );
    http_response_code(502);
    echo json_encode(['error' => get_string('voice:error_unavailable', 'local_ai_course_assistant')]);
    exit;
}

$data = json_decode($response, true);
if (!isset($data['text'])) {
    http_response_code(502);
    echo json_encode(['error' => get_string('voice:error_badresponse', 'local_ai_course_assistant')]);
    exit;
}

// Log Whisper transcription usage: approximate tokens from audio file size.
// Hosted Whisper charges per minute (~$0.006/min). Rough estimate: 1MB ≈ 1 min
// audio. Selfhosted servers cost $0, which is enforced above by recording the
// model under the 'selfhosted-' prefix, whose rate-card entry is an explicit
// $0.00 -- NOT by the interaction_type, which pricing never looks at. This
// comment previously said otherwise and free transcription was billed at the
// hosted rate. The entry has to EXIST: a model with no rate at all is what
// model_registry::unpriced_models() reports as a defect, so "free" written as
// an absent rate raises a daily price-drift alert instead of costing nothing.
$filesizebytes = filesize($tmpfile) ?: 0;
$approxminutes = max(0.1, $filesizebytes / 1_000_000);
$approxtokens = (int) ceil($approxminutes * 1000); // Arbitrary unit for rate card matching.
try {
    $conv = $DB->get_record('local_ai_course_assistant_convs', [
        'userid' => $USER->id, 'courseid' => $courseid > 0 ? $courseid : SITEID,
    ]);
    if ($conv) {
        \local_ai_course_assistant\conversation_manager::add_message(
            $conv->id,
            $USER->id,
            $courseid > 0 ? $courseid : SITEID,
            'system',
            '[STT Transcription]',
            0,
            // F81: the capability suffix belongs in interaction_type (arg 11),
            // where spend_guard's voice bucket and token_analytics' categories
            // read it -- not welded onto the provider name.
            $cfg['provider'],
            $approxtokens,
            0,
            $model,
            \local_ai_course_assistant\voice_registry::interaction_type($cfg['provider'], 'stt')
        );
    }
} catch (\Throwable $e) {
    // Non-critical.
}

echo json_encode(['text' => $data['text']]);
