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
 * that check, so this puts it back: a non-empty value must be an http:// or https:// address with a host and
 * no whitespace. An empty value is left to the parent, so it behaves exactly as an encrypted password field
 * does (it clears the setting). A refused value is never saved and never repeated: the error names the field
 * only, because what was typed may be a pasted key or token.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_webhook_url extends admin_setting_secret_field {

    /**
     * Save the URL, or say the field is wrong without saying what was typed.
     *
     * @param mixed $data the submitted value
     * @return string '' on success, else an error that names the field
     */
    public function write_setting($data) {
        $typed = is_string($data) ? trim($data) : $data;
        if ($typed !== '' && (!is_string($typed) || !preg_match('~^https?://[^\s/?#]\S*$~iD', $typed))) {
            return get_string('providerkeys:err_webhook_url', 'local_ai_course_assistant', $this->visiblename);
        }
        return parent::write_setting($data);
    }
}
