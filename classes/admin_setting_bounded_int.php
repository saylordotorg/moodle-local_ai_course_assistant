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

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * An integer setting that refuses a value outside a fixed range.
 *
 * Issue #295. PARAM_INT accepts any integer, so an admin could save 20000 retry
 * attempts, see 20000 in the field afterwards, and have the code clamp to 10
 * every time it ran: the page would state one value and the code would enforce
 * another. admin_setting_audio_mb settled this already for one setting, on the
 * reasoning that rejecting is better than clamping silently, because an admin
 * who typed 20000 wanted 20000 and the useful answer is the range. This is the
 * same thing for any range.
 *
 * Validation is only the admin-side half. A value can still arrive already out
 * of range (an earlier save, forced_plugin_settings, an upgrade, CLI
 * set_config), none of which run validate(), so the code that reads the value
 * must clamp as well; see retry_policy.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_bounded_int extends \admin_setting_configtext {

    /** @var int Smallest accepted value. */
    private $min;

    /** @var int Largest accepted value. */
    private $max;

    /**
     * Constructor.
     *
     * @param string $name Setting name.
     * @param string $visiblename Localised label.
     * @param string $description Localised description.
     * @param string $defaultsetting Default value.
     * @param int $min Smallest accepted value.
     * @param int $max Largest accepted value.
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, int $min, int $max) {
        $this->min = $min;
        $this->max = $max;
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_INT);
    }

    /**
     * Refuse anything outside [min, max].
     *
     * @param string $data Submitted value.
     * @return true|string True when valid, else the message to show.
     */
    public function validate($data) {
        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }
        $value = (int) $data;
        if ($value < $this->min || $value > $this->max) {
            return get_string('settings:int_range', 'local_ai_course_assistant',
                (object) ['min' => $this->min, 'max' => $this->max]);
        }
        return true;
    }
}
