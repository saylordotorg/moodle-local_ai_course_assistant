<?php
// This file is part of Moodle - http://moodle.org/

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
            'avatar' => new external_value(PARAM_ALPHANUMEXT, 'Avatar identifier'),
        ]);
    }

    public static function execute(string $avatar): array {
        $params = self::validate_parameters(self::execute_parameters(), ['avatar' => $avatar]);
        self::validate_context(\context_system::instance());

        if (!in_array($params['avatar'], self::$allowed, true)) {
            throw new \invalid_parameter_exception('Invalid avatar: ' . $params['avatar']);
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
