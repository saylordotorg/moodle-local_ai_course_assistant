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
use local_ai_course_assistant\anonymizer;

/**
 * Resolve a Learning Radar citation token to a single (pseudonymized) message
 * row. Replaces the former radar_cite.php AJAX_SCRIPT endpoint. Site-admin only.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_radar_citation extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Message id to resolve'),
        ]);
    }

    /**
     * @param int $id
     * @return array
     */
    public static function execute(int $id): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $row = $DB->get_record_sql(
            "SELECT m.id, m.role, m.message, m.timecreated, m.userid, m.courseid,
                    m.provider, m.model_name, c.fullname AS coursename
               FROM {local_ai_course_assistant_msgs} m
               JOIN {course} c ON c.id = m.courseid
              WHERE m.id = :id",
            ['id' => $params['id']]
        );

        if (!$row) {
            return ['ok' => false, 'id' => 0, 'role' => '', 'who' => '',
                'course' => '', 'date' => '', 'message' => ''];
        }

        $who = $row->role === 'user'
            ? anonymizer::name((int) $row->userid)
            : ('SOLA' . ($row->provider
                ? " ({$row->provider}" . ($row->model_name ? "/{$row->model_name}" : '') . ')'
                : ''));

        return [
            'ok' => true,
            'id' => (int) $row->id,
            'role' => (string) $row->role,
            'who' => $who,
            'course' => (string) $row->coursename,
            'date' => userdate($row->timecreated, '%Y-%m-%d %H:%M'),
            'message' => (string) $row->message,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether the message was found'),
            'id' => new external_value(PARAM_INT, 'Message id'),
            'role' => new external_value(PARAM_ALPHA, 'Message role'),
            'who' => new external_value(PARAM_TEXT, 'Pseudonymized author label'),
            'course' => new external_value(PARAM_TEXT, 'Course full name'),
            'date' => new external_value(PARAM_TEXT, 'Formatted date'),
            'message' => new external_value(PARAM_RAW, 'Message text'),
        ]);
    }
}
