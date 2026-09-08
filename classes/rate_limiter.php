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

/**
 * Rate limiter for API endpoints.
 *
 * Uses Moodle's cache API for efficient distributed rate limiting.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rate_limiter {
    /** @var \cache Cache instance */
    private static $cache = null;

    /**
     * Get cache instance.
     *
     * @return \cache
     */
    private static function get_cache(): \cache {
        if (self::$cache === null) {
            self::$cache = \cache::make('local_ai_course_assistant', 'ratelimit');
        }
        return self::$cache;
    }


    /**
     * Atomically count one request against a window and report whether the
     * limit is now exceeded.
     *
     * v7.0.5: the counting used to be a bare read-modify-write on a cache key
     * with no lock, so N parallel requests all read the same count and all wrote
     * count+1. `curl ... &` in a loop walked straight through every limit in the
     * plugin. Serialising on the key closes that.
     *
     * Contention is itself the signal: these keys are per user (or per IP) and
     * per endpoint, so two requests racing for the same lock means that same
     * caller already has one in flight. A normal user never contends, so failing
     * closed on lock timeout costs legitimate traffic nothing and denies exactly
     * the burst this exists to stop.
     *
     * If the lock factory itself is unavailable — a broken or unusual Moodle
     * install rather than an attack — fall back to the unsynchronised count
     * rather than taking the site's AI features offline, and say so in the
     * developer log.
     *
     * @param string $key Cache key, already namespaced by caller identity.
     * @param int $maxrequests
     * @param int $windowseconds
     * @return bool True if the limit is exceeded.
     */
    private static function bump(string $key, int $maxrequests, int $windowseconds): bool {
        try {
            $factory = \core\lock\lock_config::get_lock_factory('local_ai_course_assistant_ratelimit');
        } catch (\Throwable $e) {
            debugging(
                'SOLA rate limiter could not obtain a lock factory; counting without synchronisation: '
                    . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return self::count_unsynchronised($key, $maxrequests, $windowseconds);
        }

        $lock = false;
        try {
            $lock = $factory->get_lock($key, 2);
        } catch (\Throwable $e) {
            debugging('SOLA rate limiter lock error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        if ($lock === false) {
            // Another request for this same key holds the lock. Treat as over
            // the limit rather than letting the race through.
            return true;
        }

        try {
            return self::count_unsynchronised($key, $maxrequests, $windowseconds);
        } finally {
            $lock->release();
        }
    }

    /**
     * The sliding-window count itself. Only ever called with the key's lock
     * held, except on the documented lock-unavailable fallback.
     *
     * @param string $key
     * @param int $maxrequests
     * @param int $windowseconds
     * @return bool
     */
    private static function count_unsynchronised(string $key, int $maxrequests, int $windowseconds): bool {
        $cache = self::get_cache();
        $now = time();
        $data = $cache->get($key);
        if (!$data || !isset($data['window_start'], $data['count'])
                || ($now - (int) $data['window_start']) >= $windowseconds) {
            $data = ['count' => 0, 'window_start' => $now];
        }
        $data['count'] = ((int) $data['count']) + 1;
        $cache->set($key, $data);
        return $data['count'] > $maxrequests;
    }

    /**
     * Check if a request should be rate limited.
     *
     * Uses sliding window algorithm with per-user and per-IP limits.
     *
     * @param int $userid User ID
     * @param string $endpoint Endpoint identifier
     * @param int $maxrequests Maximum requests per window
     * @param int $windowseconds Window size in seconds (default 60)
     * @return bool True if rate limit exceeded
     */
    public static function is_rate_limited(
        int $userid,
        string $endpoint,
        int $maxrequests = 20,
        int $windowseconds = 60
    ): bool {
        return self::bump("user_{$userid}_{$endpoint}", $maxrequests, $windowseconds);
    }

    /**
     * Get IP-based rate limit check (additional security layer).
     *
     * @param string $endpoint Endpoint identifier
     * @param int $maxrequests Maximum requests per window
     * @param int $windowseconds Window size in seconds
     * @return bool True if rate limit exceeded
     */
    public static function is_ip_rate_limited(
        string $endpoint,
        int $maxrequests = 100,
        int $windowseconds = 60
    ): bool {
        // Use the hardened client IP helper: only honors X-Forwarded-For when
        // $CFG->reverseproxy is true. Without this tightening a caller could
        // bypass IP rate limits by spoofing XFF on a non-proxied deployment.
        $ip = \local_ai_course_assistant\security::client_ip();
        return self::bump('ip_' . md5($ip) . "_{$endpoint}", $maxrequests, $windowseconds);
    }

    /**
     * Reset rate limit for a user (admin function).
     *
     * @param int $userid
     * @param string $endpoint
     */
    public static function reset_user_limit(int $userid, string $endpoint): void {
        $cache = self::get_cache();
        $key = "user_{$userid}_{$endpoint}";
        $cache->delete($key);
    }
}
