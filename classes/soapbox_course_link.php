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
 * Place a Soapbox assignment on the course page as a core url activity (v7.4.3).
 *
 * Until now Soapbox was reachable only from the course secondary navigation, so
 * it sat in the "More" menu rather than in a section next to the work it belongs
 * with. Teachers asked for it on the page itself.
 *
 * This does it by creating a core `mod_url` activity pointing at
 * soapbox_present.php, rather than by inventing a placement mechanism:
 *
 *  - A local plugin cannot render into a course section. Moodle 4.5's only
 *    output hooks append to the footer or below the whole main region, so a
 *    self-rendered card could never sit between activities, could not be moved,
 *    and would carry no availability or completion.
 *  - A url activity is a first-class module, so it travels through backup,
 *    restore, import and course duplication with no extra code, and the teacher
 *    moves, hides and restricts it with the controls they already know.
 *  - No new plugin component, and no cmid column on the Soapbox tables: the
 *    link is derived by matching {url}.externalurl, which is self-healing if a
 *    teacher deletes the activity and cannot go stale the way a stored id does.
 *
 * The stored URL is ROOT-RELATIVE on purpose. Local plugins cannot register a
 * backup link encoder (encode_content_links() exists only on the course,
 * activity and block tasks), so an absolute URL would carry the origin site's
 * wwwroot into every cross-site restore. url_fix_submitted_url() preserves a
 * leading slash and url_get_full_url() accepts it, so only the assignment id
 * needs remapping on restore.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_course_link {

    /** @var string Path of the learner-facing presentation page. */
    public const PATH = '/local/ai_course_assistant/soapbox_present.php';

    /**
     * The root-relative URL stored in the url activity.
     *
     * @param int $assignid Soapbox assignment id.
     * @return string
     */
    public static function present_url(int $assignid): string {
        return self::PATH . '?id=' . $assignid;
    }

    /**
     * Extract the Soapbox assignment id from a url activity's externalurl.
     *
     * Accepts both the root-relative form written now and any absolute form an
     * earlier hand-made link may carry, so a teacher who pasted the full URL by
     * hand before this existed is still recognised.
     *
     * @param string $externalurl
     * @return int|null The assignment id, or null if this is not a Soapbox link.
     */
    public static function assign_id_from_url(string $externalurl): ?int {
        $quoted = preg_quote(self::PATH, '#');
        if (preg_match('#' . $quoted . '\?id=(\d+)(?:&|$)#', $externalurl, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * All Soapbox assignment ids already placed on a course page, keyed by assign id.
     *
     * One query for the whole course. The nav callback runs on every course page
     * load, so resolving this per assignment would add a query per assignment to
     * every page view.
     *
     * @param int $courseid
     * @return array [assignid => cmid]
     */
    public static function placed_in_course(int $courseid): array {
        global $DB;

        // cm.id leads the SELECT so the result is keyed by something unique --
        // keying by a repeatable column silently collapses rows.
        $sql = "SELECT cm.id AS cmid, u.externalurl
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'url'
                  JOIN {url} u ON u.id = cm.instance
                 WHERE cm.course = :courseid";
        $rows = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        $out = [];
        foreach ($rows as $row) {
            $assignid = self::assign_id_from_url((string) $row->externalurl);
            if ($assignid !== null) {
                $out[$assignid] = (int) $row->cmid;
            }
        }
        return $out;
    }

    /**
     * The course-module id for one placed assignment, or null.
     *
     * @param int $courseid
     * @param int $assignid
     * @return int|null
     */
    public static function find_module(int $courseid, int $assignid): ?int {
        $placed = self::placed_in_course($courseid);
        return $placed[$assignid] ?? null;
    }

    /**
     * Whether the current user may place an activity in this course.
     *
     * Checked separately so callers can HIDE the control rather than let
     * create_module()'s own required_capability_exception surface: a manager can
     * hold local/ai_course_assistant:manage without moodle/course:manageactivities.
     *
     * @param int $courseid
     * @return bool
     */
    public static function can_place(int $courseid): bool {
        return has_capability(
            'moodle/course:manageactivities',
            \context_course::instance($courseid)
        );
    }

    /**
     * Create the url activity for a Soapbox assignment.
     *
     * @param int $assignid Soapbox assignment id.
     * @param int $sectionnum Course section number to place it in.
     * @return int The new course-module id.
     * @throws \moodle_exception If the assignment is missing or already placed.
     */
    public static function add_to_course_page(int $assignid, int $sectionnum): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        $assign = soapbox_assignment_manager::get_assignment($assignid);
        if (!$assign) {
            throw new \moodle_exception('soapbox:assignment_notfound', 'local_ai_course_assistant');
        }
        $courseid = (int) $assign->courseid;

        require_capability('local/ai_course_assistant:manage', \context_course::instance($courseid));
        require_capability('moodle/course:manageactivities', \context_course::instance($courseid));

        if (self::find_module($courseid, $assignid) !== null) {
            throw new \moodle_exception('soapbox:already_on_course_page', 'local_ai_course_assistant');
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'url';
        $moduleinfo->course = $courseid;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->visible = 1;
        $moduleinfo->name = $assign->name;
        $moduleinfo->externalurl = self::present_url($assignid);
        $moduleinfo->introeditor = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];

        // DISPLAY_OPEN, explicitly, for two reasons.
        //
        // Not EMBED: lib/resourcelib.php renders an <iframe> with no
        // allow="camera; microphone", so Permissions Policy would block
        // getUserMedia and recording would fail silently inside the frame.
        //
        // Not AUTO: url_get_final_display_type() recognises an internal link only
        // via strpos($externalurl, $CFG->wwwroot) === 0, which a root-relative URL
        // never satisfies, so AUTO would fall through to the download/mimetype
        // heuristics instead of opening the page.
        $moduleinfo->display = RESOURCELIB_DISPLAY_OPEN;
        $moduleinfo->printintro = 0;

        $created = create_module($moduleinfo);
        return (int) $created->coursemodule;
    }
}
