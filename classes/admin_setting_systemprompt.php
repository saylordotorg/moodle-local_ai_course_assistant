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
 * The system prompt textarea, which loads its reset button's script on demand.
 *
 * CONTRIB-10574 #273. The "reset to template" button used to carry an inline
 * onclick. Its replacement, amd/src/reset_prompt.js, has to be requested from
 * PHP, and settings.php is the wrong place to do that: it runs whenever the
 * admin tree is built, on far more pages than the one showing this field.
 * output_html() runs only when the field is actually rendered, and a
 * requirement added during the page body is still printed with the footer, so
 * this is where core's own settings (the colour picker, for one) attach their
 * scripts too.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_systemprompt extends \admin_setting_configtextarea {

    /**
     * Render the textarea and ask for the reset button's module.
     *
     * @param mixed $data Current value.
     * @param string $query Admin search query.
     * @return string HTML.
     */
    public function output_html($data, $query = '') {
        global $PAGE;
        $PAGE->requires->js_call_amd('local_ai_course_assistant/reset_prompt', 'init');
        return parent::output_html($data, $query);
    }
}
