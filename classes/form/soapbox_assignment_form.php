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
 * Labels come from lang strings as of the CONTRIB-10574 string extraction, in
 * all 46 locales. They were briefly English-only, which turned
 * lang_completeness_test::test_translation_parity_has_not_regressed red: that
 * gate exists precisely to stop "the translations follow later", because later
 * does not arrive and the plugin keeps claiming 46/46. Values are re-clamped
 * server-side by soapbox_assignment_manager, so this form's ranges are
 * guidance, not the security boundary.
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

        $mform->addElement(
            'text',
            'name',
            get_string('soapbox:assign_name', 'local_ai_course_assistant'),
            ['size' => 60]
        );
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule(
            'name',
            get_string('soapbox:assign_required', 'local_ai_course_assistant'),
            'required',
            null,
            'client'
        );

        $mform->addElement(
            'editor',
            'intro',
            get_string('soapbox:assign_intro', 'local_ai_course_assistant'),
            null,
            ['maxfiles' => 0]
        );
        $mform->setType('intro', PARAM_RAW);

        $mform->addElement(
            'select',
            'mode',
            get_string('soapbox:assign_recordtype', 'local_ai_course_assistant'),
            [
                'video' => get_string('soapbox:assign_mode_video', 'local_ai_course_assistant'),
                'audio' => get_string('soapbox:assign_mode_audio', 'local_ai_course_assistant'),
            ]
        );
        $mform->setDefault('mode', 'video');

        $mform->addElement(
            'select',
            'ptype',
            get_string('soapbox:mode_label', 'local_ai_course_assistant'),
            [
                'informative' => get_string('soapbox:mode_informative', 'local_ai_course_assistant'),
                'persuasive'  => get_string('soapbox:mode_persuasive', 'local_ai_course_assistant'),
            ]
        );
        $mform->setDefault('ptype', 'informative');

        $maxsec = soapbox_config::max_seconds();
        $mform->addElement(
            'text',
            'min_seconds',
            get_string('soapbox:assign_min_seconds', 'local_ai_course_assistant'),
            ['size' => 8]
        );
        $mform->setType('min_seconds', PARAM_INT);
        $mform->setDefault('min_seconds', 300);
        $mform->addElement(
            'text',
            'max_seconds',
            get_string('soapbox:assign_max_seconds', 'local_ai_course_assistant'),
            ['size' => 8]
        );
        $mform->setType('max_seconds', PARAM_INT);
        $mform->setDefault('max_seconds', min(420, $maxsec));
        $mform->addElement(
            'static',
            'seccap',
            '',
            get_string('soapbox:assign_seccap', 'local_ai_course_assistant', $maxsec)
        );

        $mform->addElement(
            'text',
            'max_attempts',
            get_string('soapbox:assign_max_attempts', 'local_ai_course_assistant'),
            ['size' => 6]
        );
        $mform->setType('max_attempts', PARAM_INT);
        $mform->setDefault('max_attempts', 0);

        $maxrec = soapbox_config::max_recordings();
        $mform->addElement(
            'text',
            'stored_attempts',
            get_string('soapbox:assign_stored_attempts', 'local_ai_course_assistant'),
            ['size' => 6]
        );
        $mform->setType('stored_attempts', PARAM_INT);
        $mform->setDefault('stored_attempts', 2);
        $mform->addElement(
            'static',
            'reccap',
            '',
            get_string('soapbox:assign_reccap', 'local_ai_course_assistant', $maxrec)
        );

        $mform->addElement(
            'advcheckbox',
            'slides_enabled',
            get_string('soapbox:assign_slides', 'local_ai_course_assistant'),
            get_string('soapbox:assign_slides_help', 'local_ai_course_assistant')
        );
        $mform->setDefault('slides_enabled', 0);

        // v6.8.31: optional slide-vision pass. Only meaningful with slides on, and
        // additionally gated on the site soapbox_slide_vision toggle at run time.
        $mform->addElement(
            'advcheckbox',
            'slide_vision',
            get_string('soapbox:assign_slide_vision', 'local_ai_course_assistant'),
            get_string('soapbox:assign_slide_vision_help', 'local_ai_course_assistant')
        );
        $mform->setDefault('slide_vision', 0);
        $mform->disabledIf('slide_vision', 'slides_enabled', 'notchecked');

        $mform->addElement(
            'advcheckbox',
            'visible',
            get_string('soapbox:assign_visible', 'local_ai_course_assistant')
        );
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
            $errors['name'] = get_string('soapbox:assign_required', 'local_ai_course_assistant');
        }
        if ((int) $data['min_seconds'] < 1) {
            $errors['min_seconds'] = get_string('soapbox:assign_err_min_seconds', 'local_ai_course_assistant');
        }
        if ((int) $data['min_seconds'] > (int) $data['max_seconds']) {
            $errors['min_seconds'] = get_string('soapbox:assign_err_minmax', 'local_ai_course_assistant');
        }
        return $errors;
    }
}
