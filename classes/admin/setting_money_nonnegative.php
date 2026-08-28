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

namespace local_ai_course_assistant\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * A text setting holding a non-negative amount of money.
 *
 * PARAM_FLOAT is the obvious choice for a currency field and is wrong twice
 * over. admin_setting_configtext::validate() accepts a value only when it
 * survives a round trip through clean_param unchanged as a string, so "25.50"
 * cleans to 25.5, fails "25.50" === "25.5", and a plainly valid dollar amount is
 * rejected. Meanwhile "-5" round-trips perfectly and is stored, giving a
 * negative spend floor that no comparison can ever satisfy.
 *
 * This accepts an optional-decimal amount of zero or more and nothing else.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_money_nonnegative extends \admin_setting_configtext {

    /**
     * @param string $name
     * @param string $visiblename
     * @param string $description
     * @param string $defaultsetting
     * @param int $size
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $size = 10) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_RAW, $size);
    }

    /**
     * Accept zero or more, with up to two decimal places, and nothing else.
     *
     * @param string $data
     * @return true|string true when valid, else the error to display.
     */
    public function validate($data) {
        $data = (string) $data;
        if ($data === '') {
            return get_string('validateerror', 'admin');
        }
        // Not trimmed before matching: validate() trimming while write_setting()
        // does not meant " 25 " passed validation and was then stored with its
        // spaces intact, so the admin saw padded input round-trip into the field.
        // Rejecting it is simpler than overriding write_setting to normalise.
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $data)) {
            return get_string(
                'settings:money_nonnegative_invalid',
                'local_ai_course_assistant'
            );
        }
        return true;
    }
}
