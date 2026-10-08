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
 * A provider taught SOLA a capability fact about one of its models (v7.8.0).
 *
 * Fired when a request was rejected with a 400 that named a parameter, the
 * request healer recognised it, and the fix was stored for later calls.
 *
 * @property-read array $other {
 *      - string provider
 *      - string model
 *      - string field
 *      - string value
 * }
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_capability_learned extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_ai_course_assistant_model_caps';
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:model_capability_learned', 'local_ai_course_assistant');
    }

    /**
     * Description for the logs.
     *
     * @return string
     */
    public function get_description() {
        $o = $this->other;
        return "SOLA learned that model '" . s($o['model'] ?? '') . "' on provider '" . s($o['provider'] ?? '')
            . "' needs " . s($o['field'] ?? '') . " = '" . s($o['value'] ?? '') . "'.";
    }

    /**
     * Where an admin reviews learned facts.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/ai_course_assistant/model_registry.php');
    }

    /**
     * Validate the custom data.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['provider', 'model', 'field', 'value'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }

    /**
     * Mapping for backup and restore: none, this is a site-level fact.
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
