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

namespace local_ai_course_assistant\task;

use local_ai_course_assistant\outreach_sender;
use local_ai_course_assistant\streak_tracker;

/**
 * Tests for the milestone_check scheduled task (v5.3.18).
 *
 * Exercises the path from "streak row crosses threshold" → "outreach
 * sender called" → "audit log row written". Uses dry-run mode so no
 * real email leaves the test environment.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\task\milestone_check
 */
final class milestone_check_test extends \advanced_testcase {

    /**
     * Helper: enable everything that needs to be on, set dry-run so no
     * real email goes out, return the (course, user) pair.
     *
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function setup_milestones_enabled(): array {
        set_config('outreach_master_enabled', '1', 'local_ai_course_assistant');
        set_config('milestones_feature_enabled', '1', 'local_ai_course_assistant');
        set_config('outreach_dryrun', '1', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        set_user_preferences(['sola_outreach_milestones' => '1'], $user->id);
        return [$course, $user];
    }

    public function test_disabled_feature_short_circuits(): void {
        $this->resetAfterTest();
        global $DB;
        set_config('milestones_feature_enabled', '0', 'local_ai_course_assistant');
        // Seed a streak row at exactly 7 days that would otherwise fire.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $now = time();
        $DB->insert_record('local_ai_course_assistant_streak', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'current_streak_days' => 7, 'longest_streak_days' => 7,
            'last_active_date' => date('Y-m-d', $now),
            'last_milestone_kind' => '', 'last_milestone_at' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $task = new milestone_check();
        ob_start();
        $task->execute();
        ob_end_clean();

        // Audit log must remain empty when the feature flag is off.
        $this->assertEquals(0,
            $DB->count_records('local_ai_course_assistant_outreach_log', ['userid' => $user->id]));
    }

    public function test_streak7_milestone_fires_through_outreach_sender(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $user] = $this->setup_milestones_enabled();
        $now = time();

        $DB->insert_record('local_ai_course_assistant_streak', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'current_streak_days' => 7, 'longest_streak_days' => 7,
            'last_active_date' => date('Y-m-d', $now),
            'last_milestone_kind' => '', 'last_milestone_at' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $task = new milestone_check();
        ob_start();
        $task->execute();
        ob_end_clean();

        $log = $DB->get_records('local_ai_course_assistant_outreach_log',
            ['userid' => $user->id]);
        $this->assertCount(1, $log,
            'milestone_check must dispatch one streak7 outreach for a 7-day streak.');
        $row = reset($log);
        $this->assertEquals(outreach_sender::CH_STREAK7, $row->channel);
        $this->assertStringStartsWith('dryrun_', $row->message_id,
            'outreach_dryrun must produce a dryrun-prefixed message id.');

        // streak_tracker must record that the milestone fired so it does
        // not re-fire on the next cron tick.
        $streak = streak_tracker::get($user->id, $course->id);
        $this->assertEquals('streak7', $streak->last_milestone_kind);
    }

    public function test_already_sent_streak_does_not_refire(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $user] = $this->setup_milestones_enabled();
        $now = time();

        // Streak row at 7 days BUT last_milestone_kind already set.
        $DB->insert_record('local_ai_course_assistant_streak', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'current_streak_days' => 7, 'longest_streak_days' => 7,
            'last_active_date' => date('Y-m-d', $now),
            'last_milestone_kind' => 'streak7',
            'last_milestone_at' => $now - 86400,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $task = new milestone_check();
        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertEquals(0,
            $DB->count_records('local_ai_course_assistant_outreach_log', ['userid' => $user->id]),
            'Already-fired streak7 must not re-fire.');
    }

    public function test_completion_milestone_fires(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $user] = $this->setup_milestones_enabled();
        $now = time();

        // Seed a streak row plus a course_completions row.
        $DB->insert_record('local_ai_course_assistant_streak', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'current_streak_days' => 1, 'longest_streak_days' => 1,
            'last_active_date' => date('Y-m-d', $now),
            'last_milestone_kind' => '', 'last_milestone_at' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('course_completions', (object)[
            'userid' => $user->id, 'course' => $course->id,
            'timeenrolled' => $now - 86400 * 30,
            'timestarted' => $now - 86400 * 30,
            'timecompleted' => $now,
            'reaggregate' => 0,
        ]);

        $task = new milestone_check();
        ob_start();
        $task->execute();
        ob_end_clean();

        $row = $DB->get_record('local_ai_course_assistant_outreach_log',
            ['userid' => $user->id]);
        $this->assertNotFalse($row);
        $this->assertEquals(outreach_sender::CH_COMPLETION, $row->channel);
    }
}
