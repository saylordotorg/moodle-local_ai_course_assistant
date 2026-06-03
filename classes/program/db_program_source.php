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

namespace local_ai_course_assistant\program;

defined('MOODLE_INTERNAL') || die();

/**
 * Live adapter over the Moodle Programs plugin (v5.8.0).
 *
 * Detects the table prefix once: production uses `enrol_programs_*`, dev uses
 * `tool_muprog_*`. The schemas are identical. Every public method is
 * \Throwable-guarded so a schema difference or a missing plugin degrades to an
 * empty/false result rather than an error — the feature stays silent.
 *
 * Not exercised by PHPUnit (no program tables in the CI/test DB); verified
 * read-only against the live tables on dev.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class db_program_source implements program_source_interface {

    /** @var string|null Detected prefix without trailing underscore, e.g. 'enrol_programs'. */
    private $prefix = null;

    /** @var bool */
    private $detected = false;

    /**
     * Detect the installed Programs plugin table prefix once.
     */
    private function detect(): void {
        global $DB;
        if ($this->detected) {
            return;
        }
        $this->detected = true;
        $dbman = $DB->get_manager();
        foreach (['enrol_programs', 'tool_muprog'] as $p) {
            try {
                if ($dbman->table_exists($p . '_program')) {
                    $this->prefix = $p;
                    return;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    public function is_available(): bool {
        $this->detect();
        return $this->prefix !== null;
    }

    public function get_user_programs(int $userid): array {
        $this->detect();
        if ($this->prefix === null) {
            return [];
        }
        global $DB;
        try {
            $p = $this->prefix;
            $sql = "SELECT pr.id, pr.fullname
                      FROM {{$p}_allocation} a
                      JOIN {{$p}_program} pr ON pr.id = a.programid
                     WHERE a.userid = :uid AND a.archived = 0 AND pr.archived = 0";
            $rows = $DB->get_records_sql($sql, ['uid' => $userid]);
            return array_map(
                static fn($r) => ['programid' => (int) $r->id, 'name' => (string) $r->fullname],
                array_values($rows)
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function get_programs_for_course(int $courseid): array {
        $this->detect();
        if ($this->prefix === null) {
            return [];
        }
        global $DB;
        try {
            $p = $this->prefix;
            $sql = "SELECT DISTINCT pr.id, pr.fullname
                      FROM {{$p}_item} i
                      JOIN {{$p}_program} pr ON pr.id = i.programid
                     WHERE i.courseid = :cid AND pr.archived = 0";
            $rows = $DB->get_records_sql($sql, ['cid' => $courseid]);
            return array_map(
                static fn($r) => ['programid' => (int) $r->id, 'name' => (string) $r->fullname],
                array_values($rows)
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function get_program_courses(int $programid): array {
        $this->detect();
        if ($this->prefix === null) {
            return [];
        }
        global $DB;
        try {
            $p = $this->prefix;
            $items = $DB->get_records_select(
                "{$p}_item",
                'programid = :pid AND courseid IS NOT NULL',
                ['pid' => $programid],
                'id ASC',
                'id, courseid, fullname, previtemid, topitem, sequencejson'
            );
            // Mark items that sit inside a sequence-enforced (allinorder) set.
            $allitems = $DB->get_records("{$p}_item", ['programid' => $programid], '', 'id, sequencejson');
            $orderedset = [];
            foreach ($allitems as $it) {
                $j = json_decode((string) $it->sequencejson, true);
                if (is_array($j) && ($j['type'] ?? '') === 'allinorder') {
                    foreach (($j['children'] ?? []) as $childid) {
                        $orderedset[(int) $childid] = true;
                    }
                }
            }
            $out = [];
            $pos = 0;
            foreach ($items as $it) {
                $course = $DB->get_record('course', ['id' => (int) $it->courseid], 'id, fullname, visible');
                if (!$course) {
                    continue;
                }
                $pos++;
                $out[] = [
                    'itemid' => (int) $it->id,
                    'courseid' => (int) $it->courseid,
                    'coursename' => (string) $course->fullname,
                    'visible' => (bool) $course->visible,
                    'ordered' => !empty($orderedset[(int) $it->id]),
                    'position' => $pos,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function get_prerequisites(int $programid): array {
        $this->detect();
        if ($this->prefix === null) {
            return [];
        }
        global $DB;
        try {
            $p = $this->prefix;
            $sql = "SELECT pre.id, pre.itemid, pre.prerequisiteitemid
                      FROM {{$p}_prerequisite} pre
                      JOIN {{$p}_item} i ON i.id = pre.itemid
                     WHERE i.programid = :pid";
            $rows = $DB->get_records_sql($sql, ['pid' => $programid]);
            $out = [];
            foreach ($rows as $r) {
                $out[(int) $r->itemid][] = (int) $r->prerequisiteitemid;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function is_item_completed(int $userid, int $programid, int $itemid): bool {
        $this->detect();
        if ($this->prefix === null) {
            return false;
        }
        global $DB;
        try {
            $p = $this->prefix;
            $sql = "SELECT c.id
                      FROM {{$p}_completion} c
                      JOIN {{$p}_allocation} a ON a.id = c.allocationid
                     WHERE a.userid = :uid AND a.programid = :pid AND c.itemid = :iid
                       AND c.timecompleted > 0";
            return $DB->record_exists_sql($sql, ['uid' => $userid, 'pid' => $programid, 'iid' => $itemid]);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
