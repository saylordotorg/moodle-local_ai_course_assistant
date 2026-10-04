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

    /** @var string The template the reset button restores. */
    private $resetvalue;

    /** @var string The reset button's label. */
    private $resetlabel;

    /**
     * Constructor.
     *
     * @param string $name Setting name.
     * @param string $visiblename Localised label.
     * @param string $description Localised description. Plain text: see output_html().
     * @param string $defaultsetting Stored default.
     * @param string $resetvalue The template the reset button restores.
     * @param string $resetlabel The reset button's label.
     */
    public function __construct($name, $visiblename, $description, $defaultsetting,
            string $resetvalue = '', string $resetlabel = '') {
        $this->resetvalue = $resetvalue;
        $this->resetlabel = $resetlabel;
        parent::__construct($name, $visiblename, $description, $defaultsetting);
    }

    /**
     * Render the textarea, the reset button after it, and ask for its module.
     *
     * The button is rendered HERE rather than carried in the description,
     * because format_admin_setting() runs the description through
     * markdown_to_html(). The default prompt is full of "## " headings, "- "
     * bullets and blank lines; inside a description they were rewritten into
     * <h2>, <ul> and <p> INSIDE the data-default attribute, and Reset saved
     * that HTML as the prompt, in every locale. The element HTML is not
     * Markdown-processed, so the button goes in right after the textarea.
     *
     * Not passed to the module as a js_call_amd argument either: the template
     * is far longer than the 1,024 characters core warns about at DEVELOPER
     * debug, which is the limit behind issue #292.
     *
     * @param mixed $data Current value.
     * @param string $query Admin search query.
     * @return string HTML.
     */
    public function output_html($data, $query = '') {
        global $PAGE;
        $html = parent::output_html($data, $query);
        if ($this->resetvalue === '') {
            return $html;
        }

        $PAGE->requires->js_call_amd('local_ai_course_assistant/reset_prompt', 'init');
        $button = \html_writer::tag('button', s($this->resetlabel), [
            'type' => 'button',
            'class' => 'btn btn-sm btn-outline-secondary mt-1',
            'data-action' => 'sola-reset-prompt',
            'data-target' => $this->get_id(),
            'data-default' => $this->resetvalue,
        ]);

        $pos = strpos($html, '</textarea>');
        if ($pos === false) {
            return $html . $button;
        }
        $pos += strlen('</textarea>');
        return substr($html, 0, $pos) . '<div>' . $button . '</div>' . substr($html, $pos);
    }
}
