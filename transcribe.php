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
require_sesskey();

\local_ai_course_assistant\security::send_security_headers();
header('Content-Type: application/json');

$courseid = optional_param('courseid', 0, PARAM_INT);
if ($courseid > 0) {
    $context = context_course::instance($courseid);
} else {
    $context = context_system::instance();
}
require_capability('local/ai_course_assistant:use', $context);

// Rate limit: 20 STT requests per 60 seconds per user. Whisper is a per-minute
// spend vector; without this cap an authenticated learner can upload clips in a
// tight loop and rack up cost.
if (\local_ai_course_assistant\rate_limiter::is_rate_limited($USER->id, 'stt', 20, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => get_string('chat:error_ratelimit', 'local_ai_course_assistant')]);
    exit;
}

// Require the uploaded audio file.
if (empty($_FILES['audio']['tmp_name']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No audio file provided.']);
    exit;
}

// Enforce a 25 MB max size and an audio MIME allowlist before the file ever
// hits the upstream transcription API. Uses finfo so a spoofed Content-Type
// header cannot smuggle a non-audio payload through.
$tmp = $_FILES['audio']['tmp_name'];
$size = filesize($tmp) ?: 0;
if ($size <= 0 || $size > \local_ai_course_assistant\security::MAX_AUDIO_BYTES) {
    http_response_code(413);
    echo json_encode(['error' => 'Audio file too large.']);
    exit;
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$sniffed = $finfo ? finfo_file($finfo, $tmp) : '';
if ($finfo) { finfo_close($finfo); }
// MediaRecorder audio containers sniff as video/* or octet-stream (not audio/*),
// so a strict audio/*-only check 415'd every real browser recording. Accept the
// container types real recordings produce; see security::is_allowed_audio_upload.
$declaredtype = !empty($_FILES['audio']['type']) ? (string) $_FILES['audio']['type'] : '';
if (!\local_ai_course_assistant\security::is_allowed_audio_upload((string) $sniffed, $declaredtype)) {
    http_response_code(415);
    echo json_encode(['error' => 'Unsupported audio format.']);
    exit;
}

// Resolve active STT provider via the voice_providers registry.
$cfg = \local_ai_course_assistant\voice_registry::resolve(
    \local_ai_course_assistant\voice_registry::CAPABILITY_STT);
if ($cfg === null) {
    http_response_code(503);
    echo json_encode(['error' => 'No voice provider configured for transcription.']);
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
    $post = [
        'file'  => new CURLFile($tmpfile, $mimetype, $filename),
        'model' => $model,
    ];
    if (!empty($lang)) {
        $post['language'] = $lang;
    }
}

if (!\local_ai_course_assistant\security::is_safe_provider_url($cfg['endpoint'])) {
    http_response_code(502);
    echo json_encode(['error' => 'STT endpoint failed SSRF validation']);
    exit;
}
// Selfhosted servers are usually keyless behind a trusted network; only
// send an Authorization header when a key is actually configured.
$headers = [];
if (!empty($cfg['apikey'])) {
    $headers[] = 'Authorization: Bearer ' . $cfg['apikey'];
}
$ch = curl_init($cfg['endpoint']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 30,
]);
$response = curl_exec($ch);
$httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpcode !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'Transcription API error ' . $httpcode]);
    exit;
}

$data = json_decode($response, true);
if (!isset($data['text'])) {
    http_response_code(502);
    echo json_encode(['error' => 'Invalid transcription response.']);
    exit;
}

// Log Whisper transcription usage: approximate tokens from audio file size.
// Hosted Whisper charges per minute (~$0.006/min). Rough estimate: 1MB ≈ 1 min
// audio. Selfhosted servers cost $0 — the rate card simply has no entry for
// 'selfhosted_stt', so the row records usage telemetry at zero cost.
$filesizebytes = filesize($tmpfile) ?: 0;
$approxminutes = max(0.1, $filesizebytes / 1_000_000);
$approxtokens = (int) ceil($approxminutes * 1000); // Arbitrary unit for rate card matching.
try {
    $conv = $DB->get_record('local_ai_course_assistant_convs', [
        'userid' => $USER->id, 'courseid' => $courseid > 0 ? $courseid : SITEID,
    ]);
    if ($conv) {
        \local_ai_course_assistant\conversation_manager::add_message(
            $conv->id, $USER->id, $courseid > 0 ? $courseid : SITEID,
            'system', '[STT Transcription]',
            0, $cfg['provider'] . '_stt', $approxtokens, 0, $model
        );
    }
} catch (\Throwable $e) {
    // Non-critical.
}

echo json_encode(['text' => $data['text']]);
