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
 * An incoming-webhook URL field: stored encrypted like a key, and checked on save like a URL.
 *
 * These settings were plain URL fields (PARAM_URL) before 7.9.0. Becoming encrypted password fields dropped
 * that check, so this puts it back: a non-empty value must be an http:// or https:// address with a host
 * (and an optional port), no user name or password, and no whitespace or control characters. The scheme is
 * saved in lower case, because the send-time address check (security::is_safe_provider_url) compares it as
 * written and would refuse HTTPS://. An empty value is left to the parent, so it behaves exactly as an
 * encrypted password field does (it clears the setting). A refused value is never saved and never repeated:
 * the error names the field only, because what was typed may be a pasted key or token.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_webhook_url extends admin_setting_secret_field {

    /**
     * http or https, then a host (a name or a bracketed address, never empty, no "user@") with an optional
     * port, then an optional path, query or fragment. No character from 0x00 to 0x20 or 0x7f anywhere, and
     * no back slash, at sign, colon or slash in a host name.
     */
    private const PATTERN = '~^https?://(?:[^\x00-\x20\x7f/\\\\?#@:\[\]]+|\[[0-9a-f:.]+\])'
        . '(?::\d{1,5})?(?:[/?#][^\x00-\x20\x7f]*)?$~iD';

    /**
     * Whatever is saved here is a URL, so say only that, and never show a tail: a value saved before the
     * save-time check existed may have no scheme at all, and the last four characters of any of it are part
     * of the token.
     *
     * @param string $plain the saved value, decrypted ('' when unset or undecryptable)
     * @return string '' when there is nothing to say
     */
    protected function saved_hint(string $plain): string {
        return $plain === '' ? '' : get_string('providerkeys:saved_url_hint', 'local_ai_course_assistant');
    }

    /**
     * Save the URL, or say the field is wrong without saying what was typed.
     *
     * @param mixed $data the submitted value
     * @return string '' on success, else an error that names the field
     */
    public function write_setting($data) {
        $typed = is_string($data) ? trim($data) : $data;
        if ($typed === '') {
            return parent::write_setting($data);
        }
        if (!is_string($typed) || !preg_match(self::PATTERN, $typed)) {
            return get_string('providerkeys:err_webhook_url', 'local_ai_course_assistant', $this->visiblename);
        }
        $lowerscheme = static fn(array $m): string => strtolower($m[0]);
        return parent::write_setting(preg_replace_callback('~^https?~i', $lowerscheme, $typed));
    }
}
