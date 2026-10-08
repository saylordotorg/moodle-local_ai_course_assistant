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

namespace local_ai_course_assistant\event;

/**
 * SOLA switched a role to another model (v7.8.0), automatically or at an admin's request.
 *
 * @property-read array $other {
 *      - string role
 *      - string from
 *      - string to
 *      - string mode
 * }
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_switched extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_ai_course_assistant_model_switch';
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:model_switched', 'local_ai_course_assistant');
    }

    /**
     * Description for the logs.
     *
     * @return string
     */
    public function get_description() {
        $o = $this->other;
        return "SOLA switched the '" . s($o['role'] ?? '') . "' role from '" . s($o['from'] ?? '') . "' to '"
            . s($o['to'] ?? '') . "' (" . s($o['mode'] ?? '') . ').';
    }

    /**
     * Where an admin reviews switches.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/ai_course_assistant/model_upgrades.php');
    }

    /**
     * Validate the custom data.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['role', 'from', 'to', 'mode'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }

    /**
     * Mapping for backup and restore: none, this is site configuration.
     *
     * @return bool
     */
    public static function get_objectid_mapping() {
        return \core\event\base::NOT_MAPPED;
    }

    /**
     * Mapping for the other field: nothing in it refers to a restorable object.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
