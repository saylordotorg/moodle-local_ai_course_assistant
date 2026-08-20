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

namespace local_ai_course_assistant\talking_avatar;

use local_ai_course_assistant\security;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared HTTP + config plumbing for the four talking-avatar drivers.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_provider implements provider_interface {
    /**
     * Read a per-provider config value from `<provider_key>_<key>`.
     *
     * Until v7.0.0 this also fell back to `talking_avatar_<key>`, documented as
     * a mid-cycle upgrade path for the v4.8.1 placeholder settings. That branch
     * was unreachable: every caller passes a bare suffix ('api_key', 'base_url',
     * 'persona_id'), so it looked up `talking_avatar_api_key`, whereas the
     * settings actually registered were `talking_avatar_provider_api_key` and
     * `talking_avatar_provider_url`. Those two settings have been removed, and
     * the fallback with them.
     *
     * @param string $key Config suffix (e.g. 'api_key', 'persona_id', 'base_url').
     * @param string $default Fallback when the config row is not set.
     * @return string
     */
    protected function cfg(string $key, string $default = ''): string {
        $value = (string) (get_config('local_ai_course_assistant', $this->get_key() . '_' . $key) ?: '');
        return $value !== '' ? $value : $default;
    }

    /**
     * Issue a JSON POST to a provider endpoint with SSRF + status-code checks.
     *
     * @param string $url Full upstream URL.
     * @param array $headers Header lines (e.g. 'Authorization: Bearer …').
     * @param array $payload JSON-encodable body.
     * @return array Decoded JSON response.
     * @throws \moodle_exception On unsafe URL, transport error, or non-2xx status.
     */
    protected function http_post_json(string $url, array $headers, array $payload): array {
        if (!security::is_safe_provider_url($url)) {
            throw new \moodle_exception(
                'chat:error',
                'local_ai_course_assistant',
                '',
                null,
                $this->get_key() . ': provider URL rejected by SSRF allowlist (' . $url . ')'
            );
        }
        global $CFG;
        require_once($CFG->dirroot . '/lib/filelib.php');
        $curl = new \curl();
        $curl->setHeader(array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers));
        // Pin to the validated IP, closing the DNS-rebinding window.
        $resp = $curl->post($url, json_encode($payload), security::resolve_pin_options($url));
        $code = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($code < 200 || $code >= 300) {
            throw new \moodle_exception(
                'chat:error',
                'local_ai_course_assistant',
                '',
                null,
                $this->get_key() . ': HTTP ' . $code . ' from ' . $url . ' — ' . substr((string) $resp, 0, 400)
            );
        }
        $decoded = json_decode((string) $resp, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception(
                'chat:error',
                'local_ai_course_assistant',
                '',
                null,
                $this->get_key() . ': non-JSON response from ' . $url
            );
        }
        return $decoded;
    }
}
