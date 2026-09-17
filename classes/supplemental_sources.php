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

/**
 * Courses whose already-indexed content is retrievable from other courses.
 *
 * The problem this solves: a learner in MBA603 asks "how do exams work?" and the
 * answer lives in the Student Resource Center, which is a different course.
 * Retrieval scopes to one course plus the site FAQ, so that content was
 * unreachable however well it was indexed.
 *
 * Deliberately NOT a new indexing pipeline. A supplemental course is an ordinary
 * Moodle course that content_indexer has already chunked and embedded, so this
 * only widens which chunks retrieval may score. Referencing the orientation
 * course from ninety others therefore costs nothing extra to embed and stays in
 * step automatically when that course is reindexed.
 *
 * Ids are per site, not shared: Learn and Degrees are separate Moodle instances,
 * so the same orientation course has a different id on each and there is no
 * global constant that could be right for both.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class supplemental_sources {

    /**
     * Hard cap on how many supplemental courses one course may pull in.
     *
     * Every supplemental course's chunks are loaded and scored on every
     * retrieval, so this bounds both the memory and the dilution. Five is well
     * past the intended use (an orientation course, maybe a policy course) and
     * short of anything that would double a course's index.
     */
    public const MAX_COURSES = 5;

    /** @var array<int, int[]> Per-request resolution cache, keyed by course id. */
    private static array $resolved = [];

    /**
     * Course ids whose chunks this course may also retrieve.
     *
     * A per-course value REPLACES the site-wide list rather than adding to it.
     * Additive would make "this course must not see the orientation material"
     * unexpressible, and opting one course out is the likelier need.
     *
     * @param int $courseid The course being viewed.
     * @return int[] Course ids, never including $courseid itself.
     */
    public static function course_ids(int $courseid): array {
        $percourse = get_config('local_ai_course_assistant', 'supplemental_courses_course_' . $courseid);
        $raw = ($percourse === false || $percourse === null || trim((string) $percourse) === '')
            ? (string) get_config('local_ai_course_assistant', 'supplemental_courses')
            : (string) $percourse;

        return self::parse($raw, $courseid);
    }

    /**
     * Parse a comma or space separated id list into validated course ids.
     *
     * Everything that is not a positive integer is dropped rather than
     * rejected: this is admin-entered free text, and one stray character
     * should not silently disable the whole list.
     *
     * @param string $raw
     * @param int $exclude Course to drop (its own chunks are already in scope).
     * @return int[]
     */
    public static function parse(string $raw, int $exclude = 0): array {
        if (trim($raw) === '') {
            return [];
        }
        $ids = [];
        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            $id = (int) $part;
            // SITEID is excluded because site-course chunks reach every course
            // through the FAQ clause already; listing it would double-score them.
            if ($id > 0 && $id !== $exclude && $id != SITEID && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            if (count($ids) >= self::MAX_COURSES) {
                break;
            }
        }
        return $ids;
    }

    /**
     * The same list, filtered to courses that exist and are visible.
     *
     * Visibility is checked because a hidden course is hidden from learners, and
     * surfacing its content through the assistant would route around that. On
     * Learn the obvious orientation candidate is currently hidden, so this is a
     * live case rather than a theoretical one.
     *
     * @param int $courseid
     * @return int[]
     */
    public static function usable_course_ids(int $courseid): array {
        global $DB;

        // Called twice per retrieval (once to build the cache key, once for the
        // query). Both want the same answer in the same request, and the second
        // call should not pay for another round trip.
        if (isset(self::$resolved[$courseid])) {
            return self::$resolved[$courseid];
        }

        $ids = self::course_ids($courseid);
        if (empty($ids)) {
            self::$resolved[$courseid] = [];
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'sup');
        $found = $DB->get_fieldset_select('course', 'id', "id {$insql} AND visible = 1", $params);

        // Preserve the administrator's order so the cap trims predictably.
        self::$resolved[$courseid] = array_values(
            array_filter($ids, static fn(int $id): bool => in_array($id, $found))
        );
        return self::$resolved[$courseid];
    }

    /**
     * Discard the per-request resolution.
     *
     * Only needed by tests and by anything that changes the setting mid-request.
     */
    public static function reset_cache(): void {
        self::$resolved = [];
    }
}
