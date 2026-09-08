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
 * The 'ratelimit' cache ttl must be at least as long as the longest rate-limit
 * window in the codebase.
 *
 * rate_limiter stores an entire sliding window as ONE cache entry
 * (window_start + count) and restarts the window whenever that entry is missing
 * (classes/rate_limiter.php::count_unsynchronised). So if the cache evicts the
 * entry before the window closes, the count silently resets and the limit
 * enforces a shorter window than it claims.
 *
 * This shipped: db/caches.php had ttl=120 while soapbox_stt asks for 12 requests
 * per 600s, so it was really enforcing 12 per 120s -- five times the intended
 * allowance, with nothing logged and nothing visibly wrong.
 *
 * A rate limit that silently does not hold is worse than no rate limit, because
 * it reads as protection. This test fails the moment someone adds a window
 * longer than the ttl.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rate_limit_ttl_test extends \basic_testcase {
    /**
     * Plugin root.
     *
     * @return string
     */
    private function plugin_root(): string {
        global $CFG;
        return $CFG->dirroot . '/local/ai_course_assistant';
    }

    /**
     * The declared ttl of the 'ratelimit' cache definition.
     *
     * @return int
     */
    private function ratelimit_ttl(): int {
        $definitions = [];
        include($this->plugin_root() . '/db/caches.php');
        $this->assertArrayHasKey('ratelimit', $definitions, 'ratelimit cache definition is missing');
        $this->assertArrayHasKey('ttl', $definitions['ratelimit'], 'ratelimit cache has no ttl');
        return (int) $definitions['ratelimit']['ttl'];
    }

    /**
     * Every window argument passed to a rate_limiter entry point.
     *
     * Matches the trailing integer pair of is_rate_limited()/is_ip_rate_limited()
     * calls: the window is the last numeric argument.
     *
     * @return array<int, array{window: int, file: string}>
     */
    private function windows_in_use(): array {
        $found = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->plugin_root(), \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iter as $f) {
            $path = $f->getPathname();
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            if (preg_match('#/(\.git|node_modules|vendor|tests/|\.drafts/)#', $path)) {
                continue;
            }
            $body = file_get_contents($path);
            // is_rate_limited($who, 'bucket', MAX, WINDOW) and
            // is_ip_rate_limited('bucket', MAX, WINDOW).
            if (preg_match_all('/is_(?:ip_)?rate_limited\s*\([^;]*?,\s*(\d+)\s*,\s*(\d+)\s*\)/s', $body, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $found[] = [
                        'window' => (int) $hit[2],
                        'file' => str_replace($this->plugin_root() . '/', '', $path),
                    ];
                }
            }
        }
        return $found;
    }

    public function test_ttl_covers_every_rate_limit_window(): void {
        $ttl = $this->ratelimit_ttl();
        $windows = $this->windows_in_use();

        // Guard against a silently-empty scan reporting success.
        $this->assertNotEmpty($windows, 'Scan found no rate-limit call sites; the regex or the paths are wrong');

        $toolong = [];
        foreach ($windows as $w) {
            if ($w['window'] > $ttl) {
                $toolong[] = "{$w['file']}: window {$w['window']}s > ttl {$ttl}s";
            }
        }

        $this->assertSame([], $toolong, "Rate-limit windows longer than the 'ratelimit' cache ttl will silently "
            . "reset mid-window and enforce a shorter limit than they declare. Raise the ttl in db/caches.php:\n  - "
            . implode("\n  - ", $toolong));
    }
}
