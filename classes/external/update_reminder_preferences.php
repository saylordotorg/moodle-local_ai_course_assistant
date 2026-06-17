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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_course_assistant\reminder_manager;

/**
 * Subscribe or update reminder preferences for the current user.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_reminder_preferences extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'channel' => new external_value(PARAM_ALPHA, 'Channel: email or whatsapp'),
            'destination' => new external_value(PARAM_RAW, 'Email address or phone number'),
            'country_code' => new external_value(PARAM_ALPHA, 'ISO country code', VALUE_DEFAULT, ''),
            'frequency' => new external_value(PARAM_ALPHANUMEXT, 'Frequency: daily, every_other_day, weekly', VALUE_DEFAULT, 'daily'),
            'enabled' => new external_value(PARAM_BOOL, 'Enable or disable', VALUE_DEFAULT, true),
        ]);
    }

    /**
     * Subscribe, update, or disable reminder preferences for the current user.
     *
     * @param int $courseid The course ID.
     * @param string $channel Reminder channel: 'email' or 'whatsapp'.
     * @param string $destination Email address or phone number.
     * @param string $country_code ISO country code (for WhatsApp).
     * @param string $frequency Reminder frequency: 'daily', 'every_other_day', or 'weekly'.
     * @param bool $enabled Whether to enable or disable the reminder.
     * @return array Success flag.
     */
    public static function execute(
        int $courseid,
        string $channel,
        string $destination,
        string $country_code = '',
        string $frequency = 'daily',
        bool $enabled = true
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'channel' => $channel,
            'destination' => $destination,
            'country_code' => $country_code,
            'frequency' => $frequency,
            'enabled' => $enabled,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);

        if ($params['enabled']) {
            // Default destination to the user's Moodle email for email channel.
            $destination = $params['destination'];
            if (empty($destination) && $params['channel'] === 'email') {
                $destination = $USER->email;
            }
            reminder_manager::subscribe(
                $USER->id,
                $params['courseid'],
                $params['channel'],
                $destination,
                $params['country_code'],
                $params['frequency']
            );

            // Save contact info to the Moodle user profile. We deliberately do
            // NOT mutate the global $USER object in-session: the database row is
            // the source of truth and is re-read on the next request.
            if ($params['channel'] === 'whatsapp' && !empty($destination)) {
                $DB->set_field('user', 'phone1', $destination, ['id' => $USER->id]);
            }
        } else {
            // Disable existing reminder.
            global $DB;
            $existing = $DB->get_record('local_ai_course_assistant_reminders', [
                'userid' => $USER->id,
                'courseid' => $params['courseid'],
                'channel' => $params['channel'],
            ]);
            if ($existing) {
                $existing->enabled = 0;
                $existing->timemodified = time();
                $DB->update_record('local_ai_course_assistant_reminders', $existing);
            }
        }

        return ['success' => true];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success flag'),
        ]);
    }
}
