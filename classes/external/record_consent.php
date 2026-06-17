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

namespace local_ai_course_assistant\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_course_assistant\audit_logger;

/**
 * Record that the current learner has accepted the first-run privacy notice.
 *
 * Replaces the former consent.php AJAX_SCRIPT endpoint. Writes the
 * aica_sola_consent_given user preference and an audit-log row. Self-service:
 * only the calling (non-guest) user's own consent is recorded.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_consent extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Record consent for the current user.
     *
     * @return array
     */
    public static function execute(): array {
        global $USER;
        // Self-service: validate and authorise against the caller's own user
        // context. moodle/user:editownprofile is held by authenticated users on
        // their own context but not by guests.
        $context = \context_user::instance($USER->id);
        self::validate_context($context);
        require_capability('moodle/user:editownprofile', $context);

        set_user_preference('aica_sola_consent_given', time());
        audit_logger::log('consent_given', (int) $USER->id, 0, []);

        return ['success' => true];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether consent was recorded'),
        ]);
    }
}
