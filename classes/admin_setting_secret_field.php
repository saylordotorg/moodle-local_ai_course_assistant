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
 * Moodle's encrypted password field plus a masked "Saved key ends in ab12." hint.
 *
 * The parent already renders only a set or unset flag, never the value. This adds the last four characters
 * so a saved key can be matched to the one in a provider console. The hint comes from
 * provider_keys::last4(), which shows nothing for a key under 12 characters, and is never built from the
 * stored (encrypted) string. An unset field, or a key that won't decrypt on this site, gets no hint.
 *
 * A saved value that is a URL (an incoming webhook, whose token sits in the path) gets no last four, because
 * the tail of a URL is part of its token: the field says only that a URL is saved.
 *
 * Saving is the parent's: a typed value is encrypted, a blank one clears the setting. The config log is not:
 * Moodle's encrypted password field adds nothing to it, so every key's full ciphertext was written into
 * mdl_config_log (never purged, readable by anyone who can reach the report). This field logs '********' for
 * a value that is set, as Moodle's masked password type does, and leaves an empty value empty so a clear
 * still shows as one.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_secret_field extends \admin_setting_encryptedpassword {

    /**
     * The field, with the masked hint added to its description while it renders.
     *
     * @param mixed $data the setting's current value (the stored ciphertext, or '')
     * @param string $query the admin search text
     * @return string
     */
    public function output_html($data, $query = '') {
        $original = $this->description;
        $hint = $this->saved_hint(secrets::get($this->name));
        if ($hint !== '') {
            $this->description .= ' ' . $hint;
        }
        try {
            return parent::output_html($data, $query);
        } finally {
            $this->description = $original;
        }
    }

    /**
     * Admin search matches this setting by its name, visible name and description only. Moodle's own test also
     * reads the stored value, which would let the search box answer "does the saved key contain these
     * letters?" for a value the page never shows.
     *
     * @param string $query the search text
     * @return bool
     */
    public function is_related($query) {
        $query = \core_text::strtolower((string) $query);
        foreach ([$this->name, $this->visiblename, $this->description] as $text) {
            if (strpos(\core_text::strtolower((string) $text), $query) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Log that the setting changed, never what it changed from or to.
     *
     * @param string $name setting name
     * @param mixed $oldvalue the stored value before (ciphertext), or null when there was none
     * @param mixed $value the stored value after (ciphertext), or '' when cleared
     * @return void
     */
    protected function add_to_config_log($name, $oldvalue, $value) {
        $mask = static fn($v) => ($v === null || $v === '') ? $v : '********';
        parent::add_to_config_log($name, $mask($oldvalue), $mask($value));
    }

    /**
     * The sentence that says what is saved, built from the revealed value and never containing it.
     *
     * @param string $plain the saved value, decrypted ('' when unset or undecryptable)
     * @return string '' when there is nothing to say
     */
    protected function saved_hint(string $plain): string {
        if (preg_match('~^https?://~i', ltrim($plain))) {
            return get_string('providerkeys:saved_url_hint', 'local_ai_course_assistant');
        }
        $last4 = provider_keys::last4($plain);
        return $last4 === '' ? '' : get_string('providerkeys:last4_hint', 'local_ai_course_assistant', s($last4));
    }
}
