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

defined('MOODLE_INTERNAL') || die();

/**
 * Per-quiz SOLA assistance level manager (v5.2.0).
 *
 * Lets a course teacher choose, per quiz activity, whether SOLA appears in
 * full-help mode, coach mode (Socratic hints, no direct answers), or is
 * hidden entirely. The "default" setting falls back to the legacy
 * grade-based heuristic: graded quizzes (grade > 0) hide the widget,
 * ungraded formative quizzes get full help.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_config_manager {

    /** Table name. */
    const TABLE = 'local_ai_course_assistant_quiz_cfg';

    /** Valid level keys. */
    const LEVELS = ['default', 'full', 'coach', 'hidden'];

    /**
     * Fetch the raw config row for a quiz cmid (or null).
     */
    public static function get(int $cmid): ?\stdClass {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['cmid' => $cmid]);
        return $row ?: null;
    }

    /**
     * Resolve the effective assistance level for a quiz.
     *
     * Returns one of 'full' | 'coach' | 'hidden' (never 'default'); the
     * default falls back to grade-based behaviour: summative
     * (grade > 0) → hidden, formative → full.
     *
     * @param int $cmid     course_modules.id of the quiz
     * @param ?float $grade Optional pre-loaded quiz.grade. If null, looks it up.
     */
    public static function get_assistance_level(int $cmid, ?float $grade = null): string {
        global $DB;
        $row = self::get($cmid);
        $level = $row ? $row->assistance_level : 'default';

        if (!in_array($level, self::LEVELS, true)) {
            $level = 'default';
        }

        if ($level !== 'default') {
            return $level;
        }

        // Fall back to grade-based heuristic.
        if ($grade === null) {
            $cm = $DB->get_record('course_modules', ['id' => $cmid], 'instance, module', IGNORE_MISSING);
            if (!$cm) {
                return 'full';
            }
            $modname = $DB->get_field('modules', 'name', ['id' => $cm->module]);
            if ($modname !== 'quiz') {
                return 'full';
            }
            $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'grade', IGNORE_MISSING);
            $grade = $quiz ? (float)$quiz->grade : 0.0;
        }

        return ($grade > 0) ? 'hidden' : 'full';
    }

    /**
     * Insert or update the per-quiz assistance level.
     *
     * Saving 'default' deletes the row so the row absence == fall back to
     * the grade-based heuristic.
     */
    public static function save(int $cmid, int $courseid, string $level): void {
        global $DB;
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'default';
        }
        $now = time();
        $existing = self::get($cmid);

        if ($level === 'default') {
            if ($existing) {
                $DB->delete_records(self::TABLE, ['cmid' => $cmid]);
            }
            return;
        }

        if ($existing) {
            $existing->assistance_level = $level;
            $existing->timemodified = $now;
            $DB->update_record(self::TABLE, $existing);
            return;
        }

        $row = (object)[
            'cmid' => $cmid,
            'courseid' => $courseid,
            'assistance_level' => $level,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record(self::TABLE, $row);
    }

    /**
     * List every quiz in a course with its current assistance level.
     *
     * Returns an array of objects: cmid, instanceid, name, grade,
     * stored_level (raw db value or 'default'), effective_level
     * (full|coach|hidden after fallback).
     *
     * @return array<int, \stdClass>
     */
    public static function list_for_course(int $courseid): array {
        global $DB;

        $sql = "SELECT cm.id AS cmid, q.id AS instanceid, q.name, q.grade
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quiz} q ON q.id = cm.instance
                 WHERE cm.course = :courseid
              ORDER BY cm.section, cm.id";
        $rows = $DB->get_records_sql($sql, ['modname' => 'quiz', 'courseid' => $courseid]);

        if (!$rows) {
            return [];
        }

        $cmids = array_keys($rows);
        list($insql, $inparams) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
        $cfgrows = $DB->get_records_select(self::TABLE, "cmid {$insql}", $inparams);
        $bycm = [];
        foreach ($cfgrows as $cfg) {
            $bycm[$cfg->cmid] = $cfg->assistance_level;
        }

        $out = [];
        foreach ($rows as $r) {
            $stored = $bycm[$r->cmid] ?? 'default';
            $effective = self::get_assistance_level((int)$r->cmid, (float)$r->grade);
            $r->stored_level = $stored;
            $r->effective_level = $effective;
            $out[] = $r;
        }
        return $out;
    }
}
