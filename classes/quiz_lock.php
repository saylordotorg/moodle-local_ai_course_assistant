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
 * Server-side check for "this learner is sitting a Moodle quiz right now".
 *
 * The pre-7.1.0 lock keyed off the course-module id the browser reported, which
 * made it an interface affordance rather than a control: omit the parameter, or
 * simply open the course home page in a second tab, and the assistant was fully
 * available while an exam was open in the first. This asks the database instead,
 * so it holds regardless of which page the request claims to come from.
 *
 * Freshness matters more than it looks. On learn.saylor.org there are ~88,900
 * attempts in state 'inprogress', 95% of them started over a month ago: Saylor's
 * courses are self-paced, 1,672 of 3,150 quizzes set neither a time limit nor a
 * close date, and nothing ever transitions an abandoned attempt out of
 * 'inprogress'. Treating any in-progress attempt as "sitting an exam" would lock
 * the assistant permanently for 71,381 learners. Only ~66 attempts are less than
 * an hour old, which is the population this is actually about, so every attempt
 * is bounded by its own time limit where it has one and by a configured window
 * where it does not.
 *
 * Scope, changed in v7.2.5, for the same reason. The lock was site-wide: one
 * open attempt anywhere disabled the assistant in every course. On dev that
 * showed up as SOLA being dead in a course containing no quizzes at all,
 * because of a forgotten attempt in an unrelated sandbox course; against the
 * production numbers above it would do that to tens of thousands of learners
 * who have an abandoned attempt somewhere in their history and no idea it
 * exists. The integrity case for site-wide is thin -- a learner determined to
 * cheat has a second browser, and the assistant's course context and retrieval
 * are course-scoped anyway -- so the default is now the course holding the
 * attempt. A site that wants the old behaviour sets quiz_lock_scope to 'site'.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_lock {

    /** Fallback window (seconds) for quizzes with no time limit of their own. */
    const DEFAULT_WINDOW_SECONDS = 10800;

    /** Grace added to a quiz's own time limit before an attempt stops counting. */
    const GRACE_SECONDS = 900;

    /** Lock only the course holding the attempt. The default. */
    const SCOPE_COURSE = 'course';

    /** Lock every course, as v7.1.0 to v7.2.4 did. */
    const SCOPE_SITE = 'site';

    /**
     * Is the lock switched on for this site?
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $raw = get_config('local_ai_course_assistant', 'quiz_lock_enabled');
        // Default on: an integrity control that ships off protects nobody, and
        // this one was requested as the default behaviour for all quizzes.
        return ($raw === false || $raw === '') ? true : (bool) $raw;
    }

    /**
     * How far the lock reaches: the attempt's own course, or the whole site.
     *
     * @return string One of SCOPE_COURSE or SCOPE_SITE.
     */
    public static function scope(): string {
        $raw = (string) get_config('local_ai_course_assistant', 'quiz_lock_scope');
        return $raw === self::SCOPE_SITE ? self::SCOPE_SITE : self::SCOPE_COURSE;
    }

    /**
     * Configured fallback window, in seconds.
     *
     * @return int
     */
    public static function window_seconds(): int {
        $mins = (int) get_config('local_ai_course_assistant', 'quiz_lock_window_minutes');
        if ($mins <= 0) {
            return self::DEFAULT_WINDOW_SECONDS;
        }
        return $mins * 60;
    }

    /**
     * The quiz this learner is currently sitting, if any.
     *
     * A quiz counts when the learner has an in-progress attempt that is still
     * plausibly live, and the quiz has not been explicitly opted out by a
     * teacher setting its assistance level to 'full'.
     *
     * @param int $userid
     * @param int $courseid Course the request is being made from. Under the
     *        default course scope only an attempt in this course locks. Pass 0
     *        when the surface has no course context, which falls back to the
     *        site-wide test rather than to no test at all.
     * @return \stdClass|null Row with quizid, quizname, cmid, courseid and
     *         timestart, or null.
     */
    public static function active_attempt(int $userid, int $courseid = 0): ?\stdClass {
        global $DB;

        if ($userid <= 0 || !self::is_enabled()) {
            return null;
        }

        $now = time();
        // All arithmetic involving a placeholder is done here, not in SQL.
        // Postgres types placeholders independently, so "$4 - $5" is
        // `unknown - unknown` and it refuses with "operator is not unique";
        // MySQL infers happily, which is why this passed locally and failed on
        // the pgsql matrix. Every placeholder below is now compared against a
        // column expression, so its type is always inferrable.
        $params = [
            'userid'        => $userid,
            'nowminusgrace' => $now - self::GRACE_SECONDS,
            'cutoff'        => $now - self::window_seconds(),
        ];

        // Course scope needs a course to scope to. With none, the caller has no
        // course context to protect, so fall back to the site-wide test: the
        // conservative direction for an integrity control.
        $coursewhere = '';
        if ($courseid > 0 && self::scope() === self::SCOPE_COURSE) {
            $coursewhere = ' AND cm.course = :courseid';
            $params['courseid'] = $courseid;
        }

        // An attempt counts while it is inside its own time limit (plus grace),
        // or, for the open-ended majority, inside the configured window.
        $sql = "SELECT qa.id, qa.timestart, q.id AS quizid, q.name AS quizname,
                       cm.id AS cmid, cm.course AS courseid
                  FROM {quiz_attempts} qa
                  JOIN {quiz} q ON q.id = qa.quiz
                  JOIN {course_modules} cm ON cm.instance = q.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
             LEFT JOIN {local_ai_course_assistant_quiz_cfg} cfg ON cfg.cmid = cm.id
                 WHERE qa.userid = :userid
                   -- Only an attempt the learner still has open. A submitted or
                   -- abandoned attempt moves to 'finished' or 'abandoned' and
                   -- stops matching here the moment it does; there is no cache
                   -- in front of this query, so submitting lifts the lock on the
                   -- learner's very next request.
                   AND qa.state = 'inprogress'
                   -- Teacher previews write a real attempt row with preview=1
                   -- and it survives navigating away, so without this a teacher
                   -- who previewed an untimed quiz is locked out of the
                   -- assistant in every course for the whole window. Core's
                   -- quiz_get_user_attempts() filters the same way.
                   AND qa.preview = 0
                   AND (
                         (q.timelimit > 0 AND qa.timestart + q.timelimit >= :nowminusgrace)
                      OR (q.timelimit = 0 AND qa.timestart >= :cutoff)
                       )
                   AND (cfg.assistance_level IS NULL OR cfg.assistance_level <> 'full')
                       {$coursewhere}
              ORDER BY qa.timestart DESC";

        $rows = $DB->get_records_sql($sql, $params, 0, 1);
        return $rows ? reset($rows) : null;
    }

    /**
     * Convenience wrapper.
     *
     * @param int $userid
     * @param int $courseid Course the request comes from; see active_attempt().
     * @return bool
     */
    public static function is_locked_for(int $userid, int $courseid = 0): bool {
        return self::active_attempt($userid, $courseid) !== null;
    }
}
