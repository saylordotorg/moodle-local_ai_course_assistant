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
 * The maximum recording size setting, which refuses a value it cannot honour.
 *
 * PARAM_INT accepts any integer, so without this an admin could save 500, see
 * 500 in the field afterwards, and have security::configured_audio_mb() clamp
 * to 200 every time the endpoint ran. The page would state one limit and the
 * code would enforce another, which is the exact class of defect this release
 * was written to remove from the learner-facing messages. It should not be
 * reintroduced on the admin side.
 *
 * Rejecting is better than clamping silently: an admin who typed 500 wanted
 * 500, and the useful answer tells them the range rather than quietly storing
 * something else.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_audio_mb extends \admin_setting_configtext {

    /**
     * Refuse anything outside [MIN_AUDIO_MB, MAX_AUDIO_MB].
     *
     * @param string $data Submitted value.
     * @return true|string True when valid, else the message to show.
     */
    public function validate($data) {
        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }

        // No empty-string branch: admin_setting_configtext::validate() already
        // refuses an empty value for PARAM_INT, and it runs first. A branch here
        // would be dead code implying a fallback that does not exist. Verified
        // by calling validate('') directly, which is refused by the parent.
        $mb = (int) $data;
        if ($mb < security::MIN_AUDIO_MB || $mb > security::MAX_AUDIO_MB) {
            return get_string(
                'settings:max_audio_mb_range',
                'local_ai_course_assistant',
                (object) [
                    'min' => security::MIN_AUDIO_MB,
                    'max' => security::MAX_AUDIO_MB,
                ]
            );
        }

        return true;
    }
}
