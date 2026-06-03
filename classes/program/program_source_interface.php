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
 * What forward learning-path awareness needs from a Moodle Programs plugin.
 *
 * Saylor runs the Programs plugin under the `enrol_programs_*` tables on
 * production and the older `tool_muprog_*` tables on dev; the schemas are
 * identical. This interface hides which naming (if any) is installed so the
 * forward learning-path logic can be unit-tested against a stub with no
 * program tables present, and so a single adapter handles the prefix variance.
 *
 * Implementations return empty/false results (never throw) when the program
 * plugin is absent or a row is malformed, so the feature degrades to silence.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface program_source_interface {

    /**
     * Whether a supported program plugin is installed on this site.
     *
     * @return bool
     */
    public function is_available(): bool;

    /**
     * Non-archived programs the user is currently allocated to.
     *
     * @param int $userid
     * @return array<int, array{programid: int, name: string}>
     */
    public function get_user_programs(int $userid): array;

    /**
     * Non-archived programs that contain the given course, regardless of who
     * is allocated. Used only as a fallback when the learner has no allocation.
     *
     * @param int $courseid
     * @return array<int, array{programid: int, name: string}>
     */
    public function get_programs_for_course(int $courseid): array;

    /**
     * The course-bearing items of a program, in resolved order.
     *
     * Each entry: itemid, courseid, coursename, visible (course visibility),
     * ordered (true when the item sits in a sequence-enforced set), and
     * position (1-based order among ALL course items in the program).
     *
     * @param int $programid
     * @return array<int, array{itemid: int, courseid: int, coursename: string,
     *                          visible: bool, ordered: bool, position: int}>
     */
    public function get_program_courses(int $programid): array;

    /**
     * Prerequisite edges within a program: itemid => list of prerequisite itemids.
     *
     * An entry `J => [I]` means program item J requires program item I — i.e.
     * I builds toward J.
     *
     * @param int $programid
     * @return array<int, int[]>
     */
    public function get_prerequisites(int $programid): array;

    /**
     * Has the user completed the given program item?
     *
     * @param int $userid
     * @param int $programid
     * @param int $itemid
     * @return bool
     */
    public function is_item_completed(int $userid, int $programid, int $itemid): bool;
}
