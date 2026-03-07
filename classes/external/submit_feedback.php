<?php
// This file is part of Moodle - http://moodle.org/

namespace local_ai_course_assistant\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function to submit user feedback about SOLA.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_feedback extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'    => new external_value(PARAM_INT, 'Course ID'),
            'rating'      => new external_value(PARAM_INT, 'Rating 1-5'),
            'comment'     => new external_value(PARAM_TEXT, 'Feedback comment', VALUE_DEFAULT, ''),
            'browser'     => new external_value(PARAM_TEXT, 'Browser name and version', VALUE_DEFAULT, ''),
            'os'          => new external_value(PARAM_TEXT, 'Operating system', VALUE_DEFAULT, ''),
            'device'      => new external_value(PARAM_TEXT, 'Device type (desktop/mobile/tablet)', VALUE_DEFAULT, ''),
            'screen_size' => new external_value(PARAM_TEXT, 'Screen resolution', VALUE_DEFAULT, ''),
            'user_agent'  => new external_value(PARAM_TEXT, 'Full user agent string', VALUE_DEFAULT, ''),
            'page_url'    => new external_value(PARAM_TEXT, 'Current page URL', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Submit feedback.
     *
     * @param int    $courseid
     * @param int    $rating
     * @param string $comment
     * @param string $browser
     * @param string $os
     * @param string $device
     * @param string $screen_size
     * @param string $user_agent
     * @param string $page_url
     * @return array
     */
    public static function execute(
        int $courseid, int $rating, string $comment,
        string $browser, string $os, string $device,
        string $screen_size, string $user_agent, string $page_url
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'    => $courseid,
            'rating'      => $rating,
            'comment'     => $comment,
            'browser'     => $browser,
            'os'          => $os,
            'device'      => $device,
            'screen_size' => $screen_size,
            'user_agent'  => $user_agent,
            'page_url'    => $page_url,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);

        // Clamp rating to 1-5.
        $rating = max(1, min(5, $params['rating']));

        $record = (object) [
            'userid'      => $USER->id,
            'courseid'    => $params['courseid'],
            'rating'      => $rating,
            'comment'     => substr(trim($params['comment']), 0, 2000),
            'browser'     => substr($params['browser'], 0, 100),
            'os'          => substr($params['os'], 0, 100),
            'device'      => substr($params['device'], 0, 50),
            'screen_size' => substr($params['screen_size'], 0, 20),
            'user_agent'  => substr($params['user_agent'], 0, 500),
            'page_url'    => substr($params['page_url'], 0, 500),
            'timecreated' => time(),
        ];

        $DB->insert_record('local_ai_course_assistant_feedback', $record);

        return ['success' => true];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether feedback was saved'),
        ]);
    }
}
