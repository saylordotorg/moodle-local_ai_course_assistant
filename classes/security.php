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

defined('MOODLE_INTERNAL') || die();

/**
 * Security helpers: SSRF URL validation, response headers, XFF hardening,
 * and a small allowlist for audio MIME types on the transcribe endpoint.
 *
 * Centralized here so the provider drivers, the AJAX endpoints, and the
 * admin pages all share the same posture and the CSP connect-src list is
 * maintained in a single place.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class security {

    /** @var string[] Audio MIME types accepted by the transcribe endpoint. */
    public const AUDIO_MIME_ALLOWLIST = [
        'audio/webm', 'audio/ogg', 'audio/oga', 'audio/mp4', 'audio/mpeg',
        'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/flac', 'audio/aac',
    ];

    /** @var int Maximum audio upload size in bytes (25 MB). */
    public const MAX_AUDIO_BYTES = 25 * 1024 * 1024;

    /**
     * Return true only if the URL is a safe https endpoint not pointing at a
     * loopback, link local, private, or reserved address. Used on every
     * admin-configured provider URL before a curl_init fires, to stop a
     * compromised admin account from aiming a provider at 127.0.0.1 or
     * 169.254.169.254 (cloud metadata).
     *
     * @param string $url
     * @return bool
     */
    public static function is_safe_provider_url(string $url): bool {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = $parts['host'] ?? '';
        if ($host === '' || $host === 'localhost' || $host === '0.0.0.0') {
            return false;
        }
        // Resolve to an IP, block if it lands in a private or reserved range.
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            // DNS resolution failed; reject by default.
            return false;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        return true;
    }

    /**
     * Emit hardened response headers on SOLA endpoints. Moodle default
     * rendering already sets Content-Type; this adds:
     *  - Content-Security-Policy with an explicit AI provider allowlist.
     *  - X-Content-Type-Options: nosniff
     *  - X-Frame-Options: SAMEORIGIN
     *  - Referrer-Policy: same-origin
     *
     * Call from every SOLA entry point that renders learner-affecting HTML
     * or streams AI output.
     */
    public static function send_security_headers(): void {
        if (headers_sent()) {
            return;
        }
        $connect = [
            "'self'",
            'https://api.openai.com',
            'https://api.anthropic.com',
            'https://api.x.ai',
            'https://api.mistral.ai',
            'https://api.deepseek.com',
            'https://generativelanguage.googleapis.com',
            'https://api.minimax.chat',
            'https://openrouter.ai',
            'wss://api.openai.com',
            'wss://api.x.ai',
        ];
        $csp = "default-src 'self'; "
             . "script-src 'self' 'unsafe-inline'; "
             . "style-src 'self' 'unsafe-inline'; "
             . "img-src 'self' data: blob:; "
             . "media-src 'self' blob:; "
             . "font-src 'self' data:; "
             . 'connect-src ' . implode(' ', $connect) . '; '
             . "frame-ancestors 'self';";
        header('Content-Security-Policy: ' . $csp);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
    }

    /**
     * Tighter client IP derivation for the rate limiter. Only honors
     * X-Forwarded-For when `$CFG->reverseproxy` is explicitly enabled;
     * otherwise falls back to `$_SERVER['REMOTE_ADDR']`. Prevents an
     * attacker from bypassing IP rate limits by spoofing the header.
     *
     * @return string
     */
    public static function client_ip(): string {
        global $CFG;
        if (!empty($CFG->reverseproxy)) {
            return getremoteaddr();
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    /**
     * Normalize RAG chunks on ingest to strip prompt injection markers that
     * would otherwise influence the system prompt when the chunk is later
     * retrieved. Targets common role delimiters and section markers in the
     * jailbreak test corpus. Returns the sanitized text plus a count of
     * neutralized patterns for indexing visibility.
     *
     * @param string $text
     * @return array{text:string,neutralized:int}
     */
    public static function sanitize_rag_chunk(string $text): array {
        $neutralized = 0;
        $patterns = [
            '/##\s*(system|instructions|rules|security)\b/i',
            '/###\s*(system|instructions|rules|security)\b/i',
            '/\[\s*(system|instruction|assistant|user)\s*\]/i',
            '/<\/?\s*(system|instruction|assistant)\s*>/i',
            '/ignore\s+(all\s+)?(previous|prior)\s+instructions/i',
            '/forget\s+your\s+(system\s+)?(prompt|instructions)/i',
        ];
        foreach ($patterns as $re) {
            $text = preg_replace_callback($re, function ($m) use (&$neutralized) {
                $neutralized++;
                return '[redacted]';
            }, $text);
        }
        return ['text' => $text, 'neutralized' => $neutralized];
    }
}
