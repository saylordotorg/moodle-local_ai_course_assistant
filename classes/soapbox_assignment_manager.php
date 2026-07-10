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

use context_course;

defined('MOODLE_INTERNAL') || die();

/**
 * CRUD for Soapbox video/audio presentation assignments and their topics
 * (v6.8.12). All writes require the course-level :manage capability and route
 * instructor-supplied values through soapbox_config::clamp_assignment so the
 * editor and the upload validator enforce identical bounds.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_assignment_manager {

    /** @var string Assignments table. */
    const T_ASSIGN = 'local_ai_course_assistant_sbx_assign';

    /** @var string Topics table. */
    const T_TOPIC = 'local_ai_course_assistant_sbx_topic';

    /** @var string Recordings table. */
    const T_REC = 'local_ai_course_assistant_sbx_rec';

    /**
     * Fetch one assignment.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public static function get_assignment(int $id): ?\stdClass {
        global $DB;
        $rec = $DB->get_record(self::T_ASSIGN, ['id' => $id]);
        return $rec ?: null;
    }

    /**
     * All assignments in a course, name-ordered.
     *
     * @param int $courseid
     * @param bool $includehidden Include assignments with visible = 0.
     * @return \stdClass[]
     */
    public static function get_course_assignments(int $courseid, bool $includehidden = true): array {
        global $DB;
        $select = 'courseid = :courseid';
        if (!$includehidden) {
            $select .= ' AND visible = 1';
        }
        return array_values($DB->get_records_select(
            self::T_ASSIGN, $select, ['courseid' => $courseid], 'name ASC'));
    }

    /**
     * Require the course-level manage capability.
     *
     * @param int $courseid
     */
    private static function require_manage(int $courseid): void {
        require_capability('local/ai_course_assistant:manage', context_course::instance($courseid));
    }

    /**
     * Create an assignment. Clamps to admin caps; requires :manage.
     *
     * @param int $courseid
     * @param array $data
     * @return int New assignment id.
     */
    public static function create_assignment(int $courseid, array $data): int {
        global $DB, $USER;
        self::require_manage($courseid);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \moodle_exception('required');
        }

        $clamped = soapbox_config::clamp_assignment($data);
        $now = time();
        $rec = (object) [
            'courseid'        => $courseid,
            'name'            => $name,
            'intro'           => (string) ($data['intro'] ?? ''),
            'introformat'     => (int) ($data['introformat'] ?? FORMAT_HTML),
            'ptype'           => $clamped['ptype'],
            'mode'            => $clamped['mode'],
            'min_seconds'     => $clamped['min_seconds'],
            'max_seconds'     => $clamped['max_seconds'],
            'max_attempts'    => $clamped['max_attempts'],
            'stored_attempts' => $clamped['stored_attempts'],
            'rubricid'        => !empty($data['rubricid']) ? (int) $data['rubricid'] : null,
            'speaking_level'  => isset($data['speaking_level']) ? (string) $data['speaking_level'] : null,
            'visible'         => isset($data['visible']) ? (int) (bool) $data['visible'] : 1,
            'usermodified'    => (int) $USER->id,
            'timecreated'     => $now,
            'timemodified'    => $now,
        ];
        return (int) $DB->insert_record(self::T_ASSIGN, $rec);
    }

    /**
     * Update an assignment. Missing keys keep their existing value; provided
     * keys are re-clamped. Requires :manage.
     *
     * @param int $id
     * @param array $data
     */
    public static function update_assignment(int $id, array $data): void {
        global $DB, $USER;
        $existing = self::get_assignment($id);
        if (!$existing) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        self::require_manage((int) $existing->courseid);

        // Clamp against the merged view so an untouched min/max/stored is still valid.
        $clamped = soapbox_config::clamp_assignment(array_merge((array) $existing, $data));
        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : $existing->name;
        if ($name === '') {
            $name = $existing->name;
        }
        $rec = (object) [
            'id'              => $id,
            'name'            => $name,
            'intro'           => array_key_exists('intro', $data) ? (string) $data['intro'] : $existing->intro,
            'introformat'     => (int) ($data['introformat'] ?? $existing->introformat),
            'ptype'           => $clamped['ptype'],
            'mode'            => $clamped['mode'],
            'min_seconds'     => $clamped['min_seconds'],
            'max_seconds'     => $clamped['max_seconds'],
            'max_attempts'    => $clamped['max_attempts'],
            'stored_attempts' => $clamped['stored_attempts'],
            'rubricid'        => array_key_exists('rubricid', $data)
                ? (!empty($data['rubricid']) ? (int) $data['rubricid'] : null) : $existing->rubricid,
            'speaking_level'  => array_key_exists('speaking_level', $data)
                ? (string) $data['speaking_level'] : $existing->speaking_level,
            'visible'         => array_key_exists('visible', $data)
                ? (int) (bool) $data['visible'] : $existing->visible,
            'usermodified'    => (int) $USER->id,
            'timemodified'    => time(),
        ];
        $DB->update_record(self::T_ASSIGN, $rec);
    }

    /**
     * Delete an assignment and its topics and recording rows. Requires :manage.
     *
     * Storage objects for any recordings are removed by the retention cleanup /
     * storage layer (later Phase 1 PR); this drops the DB rows only.
     *
     * @param int $id
     */
    public static function delete_assignment(int $id): void {
        global $DB;
        $existing = self::get_assignment($id);
        if (!$existing) {
            return;
        }
        self::require_manage((int) $existing->courseid);
        $DB->delete_records(self::T_REC, ['assignid' => $id]);
        $DB->delete_records(self::T_TOPIC, ['assignid' => $id]);
        $DB->delete_records(self::T_ASSIGN, ['id' => $id]);
    }

    /**
     * Topics for an assignment, in display order.
     *
     * @param int $assignid
     * @return \stdClass[]
     */
    public static function get_topics(int $assignid): array {
        global $DB;
        return array_values($DB->get_records(
            self::T_TOPIC, ['assignid' => $assignid], 'sortorder ASC, id ASC'));
    }

    /**
     * Create or update a topic (id in $data updates). Requires :manage on the
     * owning assignment's course.
     *
     * @param int $assignid
     * @param array $data
     * @return int Topic id.
     */
    public static function save_topic(int $assignid, array $data): int {
        global $DB;
        $assign = self::get_assignment($assignid);
        if (!$assign) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        self::require_manage((int) $assign->courseid);

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new \moodle_exception('required');
        }
        $now = time();
        if (!empty($data['id'])) {
            $rec = (object) [
                'id'                 => (int) $data['id'],
                'title'              => $title,
                'instructions'       => (string) ($data['instructions'] ?? ''),
                'instructionsformat' => (int) ($data['instructionsformat'] ?? FORMAT_HTML),
                'pdf_itemid'         => (int) ($data['pdf_itemid'] ?? 0),
                'sortorder'          => (int) ($data['sortorder'] ?? 0),
                'timemodified'       => $now,
            ];
            $DB->update_record(self::T_TOPIC, $rec);
            return (int) $data['id'];
        }
        $rec = (object) [
            'assignid'           => $assignid,
            'title'              => $title,
            'instructions'       => (string) ($data['instructions'] ?? ''),
            'instructionsformat' => (int) ($data['instructionsformat'] ?? FORMAT_HTML),
            'pdf_itemid'         => (int) ($data['pdf_itemid'] ?? 0),
            'sortorder'          => (int) ($data['sortorder'] ?? 0),
            'timecreated'        => $now,
            'timemodified'       => $now,
        ];
        return (int) $DB->insert_record(self::T_TOPIC, $rec);
    }

    /**
     * Delete a topic. Requires :manage on the owning assignment's course.
     *
     * @param int $id
     */
    public static function delete_topic(int $id): void {
        global $DB;
        $topic = $DB->get_record(self::T_TOPIC, ['id' => $id]);
        if (!$topic) {
            return;
        }
        $assign = self::get_assignment((int) $topic->assignid);
        if ($assign) {
            self::require_manage((int) $assign->courseid);
        }
        $DB->delete_records(self::T_TOPIC, ['id' => $id]);
    }
}
