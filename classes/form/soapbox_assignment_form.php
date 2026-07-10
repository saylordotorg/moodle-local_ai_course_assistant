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

namespace local_ai_course_assistant\form;

use local_ai_course_assistant\soapbox_config;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Create/edit form for a Soapbox video/audio presentation assignment (v6.8.12).
 *
 * Labels are literal English here; full 46-language i18n is a later Phase 1
 * item. Values are re-clamped server-side by soapbox_assignment_manager, so
 * this form's ranges are guidance, not the security boundary.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_assignment_form extends \moodleform {

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('text', 'name', 'Assignment name', ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', 'This field is required', 'required', null, 'client');

        $mform->addElement('editor', 'intro', 'Instructions', null, ['maxfiles' => 0]);
        $mform->setType('intro', PARAM_RAW);

        $mform->addElement('select', 'mode', 'Recording type', [
            'video' => 'Video',
            'audio' => 'Audio only',
        ]);
        $mform->setDefault('mode', 'video');

        $mform->addElement('select', 'ptype', 'Presentation type', [
            'informative' => 'Informative (explain or teach)',
            'persuasive'  => 'Persuasive (convince)',
        ]);
        $mform->setDefault('ptype', 'informative');

        $maxsec = soapbox_config::max_seconds();
        $mform->addElement('text', 'min_seconds', 'Minimum length (seconds)', ['size' => 8]);
        $mform->setType('min_seconds', PARAM_INT);
        $mform->setDefault('min_seconds', 300);
        $mform->addElement('text', 'max_seconds', 'Maximum length (seconds)', ['size' => 8]);
        $mform->setType('max_seconds', PARAM_INT);
        $mform->setDefault('max_seconds', min(420, $maxsec));
        $mform->addElement('static', 'seccap', '',
            'Site maximum length: ' . $maxsec . ' seconds.');

        $mform->addElement('text', 'max_attempts', 'Attempts allowed (0 = unlimited)', ['size' => 6]);
        $mform->setType('max_attempts', PARAM_INT);
        $mform->setDefault('max_attempts', 0);

        $maxrec = soapbox_config::max_recordings();
        $mform->addElement('text', 'stored_attempts', 'Recordings kept per student', ['size' => 6]);
        $mform->setType('stored_attempts', PARAM_INT);
        $mform->setDefault('stored_attempts', 2);
        $mform->addElement('static', 'reccap', '',
            'Site maximum recordings kept per student: ' . $maxrec . '.');

        $mform->addElement('advcheckbox', 'slides_enabled',
            'Slides', 'Let students upload a PDF deck and advance slides while recording');
        $mform->setDefault('slides_enabled', 0);

        $mform->addElement('advcheckbox', 'visible', 'Visible to students');
        $mform->setDefault('visible', 1);

        $this->add_action_buttons();
    }

    /**
     * Server-side validation (client rules are advisory).
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (trim((string) $data['name']) === '') {
            $errors['name'] = 'This field is required';
        }
        if ((int) $data['min_seconds'] < 1) {
            $errors['min_seconds'] = 'Enter at least 1 second';
        }
        if ((int) $data['min_seconds'] > (int) $data['max_seconds']) {
            $errors['min_seconds'] = 'Minimum length cannot exceed the maximum';
        }
        return $errors;
    }
}
