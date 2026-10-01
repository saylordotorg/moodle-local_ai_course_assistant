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
 * Fetches and caches remote SOLA configuration from a GitHub-hosted JSON file.
 *
 * Allows Saylor to push prompt/config updates without a plugin release.
 * Local admin settings always take priority over remote values.
 * Falls back to empty array (hardcoded defaults) on any failure.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote_config_manager {
    /**
     * No default URL. Remote configuration is OFF unless an administrator sets one.
     *
     * This constant used to hold the Saylor repository's raw GitHub URL, and
     * get() fell back to it whenever the setting was empty. The effect was that
     * every installation of this plugin, on any site, fetched a file from a
     * branch of one organisation's repository once an hour and applied its
     * `system_prompt`, `instruction_blocks` and `model_default` to the assistant
     * its learners talk to. Clearing the setting did not switch it off, because
     * empty fell back to the same URL.
     *
     * That put learner-facing behaviour, including the house-style block that
     * carries the self-harm and crisis guidance, and the choice of which model
     * is billed, under the control of whoever could write to that file, with no
     * plugin release, no code review and no administrator upgrade in between.
     *
     * Remote configuration is now opt-in: the setting ships empty, empty means
     * disabled, and there is nothing to fall back to. Prompt defaults live in
     * released code and language strings, so a behaviour change goes through the
     * same administrator-controlled upgrade path as any other code change.
     *
     * Reported as an approval blocker under the Moodle security guidelines
     * (CONTRIB-10574, issue #267), and the same principle as the self-updater
     * removed after the previous review (issue #204).
     */
    const DEFAULT_URL = '';

    /** Cache TTL in seconds (1 hour). */
    const CACHE_TTL = 3600;

    /**
     * Return remote config as decoded array. Returns [] on any failure.
     *
     * @return array
     */
    public static function get(): array {
        $cache = \cache::make('local_ai_course_assistant', 'remoteconfig');
        $cached = $cache->get('config');
        if ($cached !== false) {
            return $cached;
        }

        // Empty means disabled. There is deliberately no fallback: an
        // administrator who clears this setting has switched remote
        // configuration off, and must be able to rely on that.
        $url = trim((string) get_config('local_ai_course_assistant', 'remoteconfigurl'));
        if ($url === '') {
            $cache->set('config', []);
            return [];
        }

        // Defence in depth: HTTPS, allowlist, no private/reserved IPs.
        if (!$url || !security::is_safe_provider_url($url)) {
            $cache->set('config', []);
            return [];
        }

        global $CFG;
        require_once($CFG->libdir . '/filelib.php'); // For \curl.
        $curl = new \curl();
        $curl->setopt(array_merge([
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_MAXREDIRS'      => 3,
            'CURLOPT_TIMEOUT'        => 10,
            'CURLOPT_USERAGENT'      => 'SOLA-Moodle-Plugin/1.0',
            // Pin to the validated IP, closing the DNS-rebinding window.
        ], security::resolve_pin_options($url)));
        $body = $curl->get($url);
        $code = (int) ($curl->get_info()['http_code'] ?? 0);

        if ($code !== 200 || !$body) {
            $cache->set('config', []);
            return [];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            $cache->set('config', []);
            return [];
        }

        $cache->set('config', $data);
        return $data;
    }

    /**
     * Get a single key from remote config with a fallback value.
     *
     * @param string $key     Top-level key in the remote config JSON.
     * @param mixed  $fallback Value to return if key is absent or fetch failed.
     * @return mixed
     */
    public static function get_value(string $key, $fallback = null) {
        $config = self::get();
        return $config[$key] ?? $fallback;
    }

    /**
     * Invalidate the remote config cache (e.g. after saving settings).
     *
     * @return void
     */
    public static function invalidate(): void {
        \cache::make('local_ai_course_assistant', 'remoteconfig')->delete('config');
    }
}
