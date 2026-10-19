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

    /** @var string Error code (and lang key): the assignment holds recordings PresenterAI's importer owns. */
    const ERR_HELD = 'soapbox:assignment_held';

    /** @var string Error code (and lang key): the PresenterAI check could not be made, so nothing was deleted. */
    const ERR_HELD_UNCHECKED = 'soapbox:assignment_held_unchecked';

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
            self::T_ASSIGN,
            $select,
            ['courseid' => $courseid],
            'name ASC'
        ));
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
            'slides_enabled'  => $clamped['slides_enabled'],
            'slide_vision'    => $clamped['slide_vision'],
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
            throw new \moodle_exception('soapbox:assignment_notfound', 'local_ai_course_assistant');
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
            'slides_enabled'  => $clamped['slides_enabled'],
            'slide_vision'    => $clamped['slide_vision'],
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
     * The stored objects go first, through drop_recording_objects(). The
     * previous version of this docblock said the retention cleanup task would
     * remove them. It could not: that task finds expired objects by walking the
     * very sbx_rec rows this method deletes, so dropping the rows first left
     * every recording stranded in the bucket with nothing pointing at it, and
     * only the bucket lifecycle rule as a backstop. The "later Phase 1 PR" it
     * deferred to never landed.
     *
     * It refuses, deleting nothing, while any of the assignment's recordings is
     * held by PresenterAI's importer (presenterai_interlock): the importer
     * re-points its own attempts at the same stored objects, so deleting them here
     * would leave every migrated attempt pointing at nothing. It refuses just the
     * same when the check cannot be made, because not knowing is not permission.
     * Course deletion and privacy erasure do NOT go through this method and are
     * not held back: removing a course or a person's data still removes the media.
     *
     * @param int $id
     * @throws \moodle_exception soapbox:assignment_held when a recording is held,
     *         soapbox:assignment_held_unchecked when the lookup failed.
     */
    public static function delete_assignment(int $id): void {
        global $DB;
        $existing = self::get_assignment($id);
        if (!$existing) {
            return;
        }
        self::require_manage((int) $existing->courseid);
        self::refuse_while_held($id, (int) $existing->courseid);
        self::drop_recording_objects([$id]);
        $DB->delete_records(self::T_REC, ['assignid' => $id]);
        $DB->delete_records(self::T_TOPIC, ['assignid' => $id]);
        $DB->delete_records(self::T_ASSIGN, ['id' => $id]);
    }

    /**
     * Refuse to go on when PresenterAI's importer holds any recording of this assignment.
     *
     * Fails closed: if the lookup throws, the deletion is refused with a different
     * message and one audit row names the exception class (never its message,
     * which can carry SQL).
     *
     * @param int $id The assignment.
     * @param int $courseid Its course, for the audit row.
     * @return void
     * @throws \moodle_exception
     */
    private static function refuse_while_held(int $id, int $courseid): void {
        global $DB, $USER;

        $recids = $DB->get_fieldset_select(self::T_REC, 'id', 'assignid = :aid', ['aid' => $id]);
        if (empty($recids)) {
            return;
        }
        try {
            $held = static::held_recordings(array_map('intval', $recids));
        } catch (\Throwable $e) {
            audit_logger::log('soapbox_delete_interlock_failed', (int) $USER->id, $courseid, [
                'assignid' => $id,
                'error_class' => get_class($e),
            ]);
            throw new \moodle_exception(self::ERR_HELD_UNCHECKED, 'local_ai_course_assistant');
        }
        if (!empty($held)) {
            throw new \moodle_exception(self::ERR_HELD, 'local_ai_course_assistant');
        }
    }

    /**
     * Which of these recordings PresenterAI's importer holds.
     *
     * Protected so a test can make the lookup fail; production asks the interlock.
     *
     * @param array $recids Soapbox recording ids.
     * @return array The held ids.
     */
    protected static function held_recordings(array $recids): array {
        return presenterai_interlock::held_ids($recids);
    }

    /**
     * Delete the stored media for every recording on these assignments.
     *
     * Call this BEFORE deleting the sbx_rec rows. Nothing comes back for an
     * object once its row is gone: soapbox_cleanup finds expired media by
     * walking sbx_rec, so a row deleted without its object strands a learner's
     * video, their slide deck and the still frames of their face in the bucket
     * permanently.
     *
     * Every deleter of sbx_rec rows calls this. There are three, and until
     * v7.5.2 only the privacy erasure path did it: deleting an assignment, and
     * deleting a whole course, both left the media behind, and the course path
     * is the one an administrator actually uses.
     *
     * Best-effort by design. A storage failure must not stop the deletion an
     * administrator asked for, and the bucket lifecycle rule on the prefix is
     * the backstop, so a failed object delete is logged rather than thrown.
     *
     * @param int[] $assignids Assignment ids whose recordings are being removed.
     * @return void
     */
    public static function drop_recording_objects(array $assignids): void {
        global $DB;

        $assignids = array_values(array_filter(array_map('intval', $assignids)));
        if (empty($assignids) || !\local_ai_course_assistant\soapbox_storage::is_configured()) {
            return;
        }
        try {
            [$insql, $inparams] = $DB->get_in_or_equal($assignids, SQL_PARAMS_NAMED, 'aid');
            $recs = $DB->get_records_select(
                self::T_REC,
                "assignid {$insql}",
                $inparams,
                '',
                'id, storage_key, deck_key, frames_key'
            );
            if (empty($recs)) {
                return;
            }
            $storage = new \local_ai_course_assistant\soapbox_storage();
            foreach ($recs as $rec) {
                foreach ([$rec->storage_key, $rec->deck_key, $rec->frames_key] as $objkey) {
                    if (empty($objkey)) {
                        continue;
                    }
                    try {
                        $storage->delete_object($objkey);
                    } catch (\Throwable $e) {
                        debugging(
                            'SOLA: could not delete Soapbox object ' . $objkey . ': ' . $e->getMessage(),
                            DEBUG_DEVELOPER
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            debugging(
                'SOLA: could not enumerate Soapbox recordings for deletion: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
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
            self::T_TOPIC,
            ['assignid' => $assignid],
            'sortorder ASC, id ASC'
        ));
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
            throw new \moodle_exception('soapbox:assignment_notfound', 'local_ai_course_assistant');
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
