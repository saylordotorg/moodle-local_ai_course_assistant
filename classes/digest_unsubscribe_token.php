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
 * v4.1 / F2 — HMAC token for one-click email unsubscribe.
 *
 * Token format (URL-safe base64):
 *   payload = "{userid}.{courseid}.{expiry_epoch}"
 *   token   = base64url(payload) + "." + base64url(hmac_sha256(payload, $CFG->siteidentifier))
 *
 * Replay isn't a security risk for an unsubscribe action — worst case the
 * preference is set to '0' twice. We still embed an expiry (default 60 days
 * from email send) so an old leaked link can't reactivate later.
 *
 * The signing key is `$CFG->siteidentifier` — site-unique, already used by
 * core for similar single-purpose links, and rotated only on a full re-install.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class digest_unsubscribe_token {

    /** @var int Default token lifetime in seconds (60 days). */
    private const DEFAULT_TTL = 60 * 24 * 60 * 60;

    /**
     * Build a token for a (userid, courseid) pair. Default expiry 60 days.
     *
     * @param int $userid
     * @param int $courseid
     * @param int|null $ttl Lifetime in seconds; null uses the default.
     * @return string URL-safe token, no padding.
     */
    public static function mint(int $userid, int $courseid, ?int $ttl = null): string {
        $expiry = time() + ($ttl ?? self::DEFAULT_TTL);
        $payload = $userid . '.' . $courseid . '.' . $expiry;
        $sig = self::sign($payload);
        return self::b64url_encode($payload) . '.' . self::b64url_encode($sig);
    }

    /**
     * Validate a token and return [userid, courseid] on success or null on
     * any failure: malformed, bad signature, or expired.
     *
     * @param string $token
     * @return array{0:int,1:int}|null
     */
    public static function verify(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        $payload = self::b64url_decode($parts[0]);
        $sig     = self::b64url_decode($parts[1]);
        if ($payload === false || $sig === false) {
            return null;
        }
        $expected = self::sign($payload);
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $segments = explode('.', $payload);
        if (count($segments) !== 3) {
            return null;
        }
        [$userid, $courseid, $expiry] = $segments;
        if (!ctype_digit($userid) || !ctype_digit($courseid) || !ctype_digit($expiry)) {
            return null;
        }
        if ((int) $expiry < time()) {
            return null;
        }
        return [(int) $userid, (int) $courseid];
    }

    /**
     * Build the unsubscribe URL the email body and List-Unsubscribe header
     * should point at. Same URL works for both the link click (renders a
     * confirmation page) and the RFC 8058 List-Unsubscribe-Post (silent
     * unsubscribe via mail-client button).
     *
     * @param int $userid
     * @param int $courseid
     * @return string
     */
    public static function url(int $userid, int $courseid): string {
        return (new \moodle_url('/local/ai_course_assistant/digest_unsubscribe.php', [
            'token' => self::mint($userid, $courseid),
        ]))->out(false);
    }

    /**
     * Internal: HMAC-SHA256 with the site identifier as the key.
     *
     * @param string $payload
     * @return string Raw bytes (NOT hex).
     */
    private static function sign(string $payload): string {
        global $CFG;
        $key = (string) ($CFG->siteidentifier ?? '');
        if ($key === '') {
            // Fallback — should never happen, but never sign with empty key.
            $key = 'aica-digest-unsubscribe-fallback';
        }
        return hash_hmac('sha256', $payload, $key, true);
    }

    /**
     * URL-safe base64 encode without padding.
     *
     * @param string $bytes
     * @return string
     */
    private static function b64url_encode(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * URL-safe base64 decode. Returns false on malformed input.
     *
     * @param string $s
     * @return string|false
     */
    private static function b64url_decode(string $s) {
        $padded = strtr($s, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($padded, true);
    }
}
