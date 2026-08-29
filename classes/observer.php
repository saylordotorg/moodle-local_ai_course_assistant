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
 * Event observers.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Course-scoped tables, cleared when the course goes.
     *
     * Kept as an explicit list rather than a pattern match so adding a table
     * forces a decision about deletion, and so a table belonging to another
     * plugin can never be caught by a wildcard.
     */
    private const COURSE_TABLES = [
        'local_ai_course_assistant_convs',
        'local_ai_course_assistant_msgs',
        'local_ai_course_assistant_course_cfg',
        'local_ai_course_assistant_quiz_cfg',
        'local_ai_course_assistant_objs',
        'local_ai_course_assistant_rubrics',
        'local_ai_course_assistant_sbx_assign',
        'local_ai_course_assistant_plans',
        'local_ai_course_assistant_reminders',
        'local_ai_course_assistant_learner_goals',
        'local_ai_course_assistant_streak',
        'local_ai_course_assistant_practice_scores',
        'local_ai_course_assistant_flashcards',
        'local_ai_course_assistant_surveys',
        'local_ai_course_assistant_survey_resp',
        'local_ai_course_assistant_ut_tasks',
        'local_ai_course_assistant_ut_resp',
        'local_ai_course_assistant_profiles',
        'local_ai_course_assistant_learner_memory',
        'local_ai_course_assistant_struggle_signal',
        'local_ai_course_assistant_avatar_sess',
        'local_ai_course_assistant_chunks',
        'local_ai_course_assistant_keywords',
        'local_ai_course_assistant_feedback',
        'local_ai_course_assistant_msg_ratings',
        'local_ai_course_assistant_obj_att',
        'local_ai_course_assistant_review_res',
        'local_ai_course_assistant_outreach_log',
    ];

    /**
     * Child tables with no courseid of their own, keyed to a parent that is.
     *
     * Deleting the parent without these leaves rows that point at nothing --
     * and course ids are reused, so an orphan can resurface attached to an
     * unrelated course. sbx_rec is the one that matters most: it holds speech
     * transcripts and storage keys, which is the same retention argument the
     * class docblock makes for conversations.
     *
     * Ordered child-before-parent, and each pair names the parent's own table
     * so the id lookup happens while the parent rows still exist.
     *
     * @var array<string, array{0: string, 1: string}> child table => [parent
     *      table, child column holding the parent id].
     */
    private const CHILD_TABLES = [
        'local_ai_course_assistant_obj_links' => ['local_ai_course_assistant_objs', 'objectiveida'],
        'local_ai_course_assistant_sbx_rec' => ['local_ai_course_assistant_sbx_assign', 'assignid'],
        'local_ai_course_assistant_sbx_topic' => ['local_ai_course_assistant_sbx_assign', 'assignid'],
    ];

    /**
     * Clear this plugin's data when a course is deleted.
     *
     * Nothing did this. Deleting a course left every row above orphaned, and
     * every "<setting>_course_<id>" row in config_plugins with it, permanently
     * and invisibly -- including learner conversations and messages, which is a
     * retention problem as much as a tidiness one. Course ids are reused, so an
     * orphaned row can also resurface attached to an unrelated course.
     *
     * Deliberately not touched: the audit log, which is a record of what
     * happened on this site and should outlive the course it happened in, and
     * email_optout, which is a site-wide contact preference.
     *
     * @param \core\event\course_deleted $event
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;

        $courseid = (int) $event->objectid;
        if ($courseid <= 0) {
            return;
        }

        // Children first: their parent ids have to still be resolvable.
        foreach (self::CHILD_TABLES as $child => [$parent, $column]) {
            try {
                if (!$DB->get_manager()->table_exists(new \xmldb_table($child))
                    || !$DB->get_manager()->table_exists(new \xmldb_table($parent))) {
                    continue;
                }
                $parentids = $DB->get_fieldset_select($parent, 'id', 'courseid = :courseid',
                    ['courseid' => $courseid]);
                if (empty($parentids)) {
                    continue;
                }
                [$insql, $inparams] = $DB->get_in_or_equal($parentids, SQL_PARAMS_NAMED, 'pid');
                $DB->delete_records_select($child, "{$column} {$insql}", $inparams);

                // obj_links joins two objectives; a link is dead if either end
                // belonged to this course, so sweep the second column too.
                if ($child === 'local_ai_course_assistant_obj_links') {
                    [$insql2, $inparams2] = $DB->get_in_or_equal($parentids, SQL_PARAMS_NAMED, 'pidb');
                    $DB->delete_records_select($child, "objectiveidb {$insql2}", $inparams2);
                }
            } catch (\Throwable $e) {
                debugging(
                    "SOLA: could not clear {$child} for deleted course {$courseid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        foreach (self::COURSE_TABLES as $table) {
            try {
                if ($DB->get_manager()->table_exists(new \xmldb_table($table))) {
                    $DB->delete_records($table, ['courseid' => $courseid]);
                }
            } catch (\Throwable $e) {
                // One unexpected table must not abort the course deletion the
                // administrator actually asked for.
                debugging(
                    "SOLA: could not clear {$table} for deleted course {$courseid}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        // Per-course overrides live in config_plugins keyed by course id.
        foreach ($DB->get_records_select(
            'config_plugins',
            'plugin = :plugin AND ' . $DB->sql_like('name', ':pattern'),
            [
                'plugin' => 'local_ai_course_assistant',
                'pattern' => '%' . $DB->sql_like_escape('_course_' . $courseid),
            ],
            '',
            'id, name'
        ) as $row) {
            // Belt and braces: only a name whose trailing id is exactly this
            // course, so _course_5 never matches while deleting course 15.
            if (preg_match('/_course_' . $courseid . '$/', $row->name)) {
                unset_config($row->name, 'local_ai_course_assistant');
            }
        }
    }
}
