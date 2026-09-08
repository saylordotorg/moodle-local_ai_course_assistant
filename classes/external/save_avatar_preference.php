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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_ai_course_assistant\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function to save the user's preferred avatar.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_avatar_preference extends external_api {
    /** @var string[] Valid avatar IDs */
    private static $allowed = [
        'avatar_01', 'avatar_02', 'avatar_03', 'avatar_04', 'avatar_05', 'avatar_06',
        'avatar_07', 'avatar_08', 'avatar_09', 'avatar_10',
        'avatar_12', 'avatar_13', 'avatar_14', 'avatar_15', 'avatar_16', 'avatar_17',
    ];

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            // PARAM_TEXT, not PARAM_ALPHANUMEXT: custom avatar keys are
            // 'custom:<contenthash>' and ALPHANUMEXT strips the colon, so
            // validate_parameters threw before the allowlist ever ran --
            // admin-uploaded avatars appeared as picker tiles and could never
            // be selected, with the rejection swallowed by an empty JS catch.
            'avatar' => new external_value(PARAM_TEXT, 'Avatar identifier'),
        ]);
    }

    public static function execute(string $avatar): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['avatar' => $avatar]);
        // This only ever writes the calling user's own preference, so validate
        // and authorise against that user's context. moodle/user:editownprofile
        // is held by authenticated users on their own context but not by guests.
        $context = \context_user::instance($USER->id);
        self::validate_context($context);
        require_capability('moodle/user:editownprofile', $context);

        $choice = $params['avatar'];
        $iscustom = str_starts_with($choice, 'custom:');
        if ($iscustom) {
            // Allowlist against what actually exists in the admin-uploaded
            // filearea -- the same enumeration the picker itself is built from.
            global $CFG;
            require_once($CFG->dirroot . '/local/ai_course_assistant/lib.php');
            $validcustom = array_column(local_ai_course_assistant_get_custom_avatars(), 'key');
            if (!in_array($choice, $validcustom, true)) {
                throw new \invalid_parameter_exception('Invalid avatar: ' . $choice);
            }
        } else if (!in_array($choice, self::$allowed, true)) {
            throw new \invalid_parameter_exception('Invalid avatar: ' . $choice);
        }

        set_user_preference('local_ai_course_assistant_avatar', $params['avatar']);

        return ['success' => true];
    }

    public static function execute_returns(): external_single_structure {
        // v5.3.20: matches the actual return shape of execute() which is
        // ['success' => bool]. Previously declared as a scalar PARAM_BOOL,
        // which clean_returnvalue would reject ("Scalar type expected,
        // array or object received."). Caught by external_services_test.
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success flag'),
        ]);
    }
}
