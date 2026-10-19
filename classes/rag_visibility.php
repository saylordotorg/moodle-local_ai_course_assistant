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
 * Keep only the retrieved chunks the requesting user may see (#341).
 *
 * The index is built by whoever runs the indexer, and the scheduled task runs as
 * an administrator, so the chunk table holds text from hidden activities, hidden
 * sections and activities behind availability restrictions. Retrieval scores by
 * course, so without this a learner's prompt could carry a staged answer key.
 * The index is left as it is (staff ground on it too); the decision is made per
 * request, for the user asking, on the final top-k only.
 *
 * Rules, in order:
 *  1. A site FAQ chunk (site course, modtype faq, no cmid) is kept: it is one
 *     admin setting shown to everyone, not activity content.
 *  2. A chunk is dropped unless it comes from the course being chatted in or
 *     from one of that course's supplemental courses (the administrator shared
 *     those on purpose; supplemental_sources already drops hidden courses).
 *  3. Every chunk of the chatted course is dropped when the user can't access
 *     that course (can_access_course(), or the support course for a holder of
 *     the support capability). A supplemental course counts as accessible: that
 *     is the sharing the setting exists for.
 *  4. A chunk with no cmid (course summary and section text) is then kept.
 *  5. A chunk with a cmid is kept only when that cm is in the chunk's course and
 *     cm_info::uservisible is true for this user, which covers hidden activities,
 *     hidden sections, availability (dates, groups, grades) and the
 *     viewhiddenactivities capability that keeps staff seeing drafts.
 *
 * Survivors keep their order and fields; nothing is refilled, so a turn may get
 * fewer than top-k passages. Modinfo is built once per course per call and never
 * kept across calls, so no user's visibility can reach another user.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rag_visibility {
    /**
     * Filter retrieved chunks to what one user may see.
     *
     * @param array $chunks Retrieved rows, each with 'courseid', 'cmid' and 'modtype' (as hydrate_content() returns).
     * @param int $courseid The course the user is chatting in (already authorised by the caller).
     * @param int $userid The user the answer is for. 0 or less keeps only site FAQ chunks.
     * @return array The kept chunks as a list, in their original order.
     */
    public static function filter(array $chunks, int $courseid, int $userid): array {
        if (empty($chunks)) {
            return [];
        }
        $supplemental = null;
        $access = [];
        $modinfos = [];
        $out = [];
        foreach ($chunks as $chunk) {
            $chunkcourse = (int) ($chunk['courseid'] ?? 0);
            $cmid = (int) ($chunk['cmid'] ?? 0);
            if (
                $chunkcourse === (int) SITEID && $cmid <= 0
                    && (string) ($chunk['modtype'] ?? '') === faq_manager::MODTYPE
            ) {
                $out[] = $chunk;
                continue;
            }
            if ($userid <= 0 || $chunkcourse <= 0) {
                continue;
            }
            if ($chunkcourse !== $courseid) {
                $supplemental = $supplemental ?? supplemental_sources::usable_course_ids($courseid);
                if (!in_array($chunkcourse, $supplemental, true)) {
                    continue;
                }
            }
            if (!array_key_exists($chunkcourse, $access)) {
                $access[$chunkcourse] = ($chunkcourse !== $courseid) || self::can_read_course($courseid, $userid);
            }
            if (!$access[$chunkcourse]) {
                continue;
            }
            if ($cmid <= 0) {
                $out[] = $chunk;
                continue;
            }
            if (!array_key_exists($chunkcourse, $modinfos)) {
                $modinfos[$chunkcourse] = self::modinfo($chunkcourse, $userid);
            }
            $cms = $modinfos[$chunkcourse] !== null ? $modinfos[$chunkcourse]->get_cms() : [];
            if (isset($cms[$cmid]) && $cms[$cmid]->uservisible) {
                $out[] = $chunk;
            }
        }
        return $out;
    }

    /**
     * Whether a user may read the course-level content of the course they chat in.
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    private static function can_read_course(int $courseid, int $userid): bool {
        // The support course answers holders of the system-level support capability who
        // are not enrolled in it; support_mode::require_use() let them in for that reason.
        if (
            support_mode::is_support_turn($courseid)
                && has_capability(support_mode::CAPABILITY, \context_system::instance(), $userid)
        ) {
            return true;
        }
        try {
            return can_access_course(get_course($courseid), $userid);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Course module info for one user, or null when the course can't be read.
     *
     * @param int $courseid
     * @param int $userid
     * @return \course_modinfo|null
     */
    private static function modinfo(int $courseid, int $userid) {
        try {
            return get_fast_modinfo($courseid, $userid);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
