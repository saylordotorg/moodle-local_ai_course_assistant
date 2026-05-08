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
 * Tests for streak_tracker (v5.3.0 / regression coverage v5.3.8).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\streak_tracker
 */
final class streak_tracker_test extends \advanced_testcase {

    public function test_record_first_activity_creates_row_with_streak_one(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $milestone = streak_tracker::record_activity($user->id, $course->id);

        $this->assertNull($milestone, 'First-day activity should not cross any milestone.');
        $row = streak_tracker::get($user->id, $course->id);
        $this->assertNotNull($row);
        $this->assertEquals(1, $row->current_streak_days);
        $this->assertEquals(1, $row->longest_streak_days);
        $this->assertEquals(streak_tracker::today_str(), $row->last_active_date);
    }

    public function test_record_same_day_idempotent(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        streak_tracker::record_activity($user->id, $course->id);
        $milestone = streak_tracker::record_activity($user->id, $course->id);
        $this->assertNull($milestone);

        $row = streak_tracker::get($user->id, $course->id);
        $this->assertEquals(1, $row->current_streak_days,
            'Two activity calls on the same day must not double-count.');
    }

    public function test_date_diff_days_handles_consecutive_and_gap(): void {
        $this->assertEquals(1, streak_tracker::date_diff_days('2026-05-07', '2026-05-08'));
        $this->assertEquals(0, streak_tracker::date_diff_days('2026-05-07', '2026-05-07'));
        $this->assertEquals(7, streak_tracker::date_diff_days('2026-05-01', '2026-05-08'));
        $this->assertEquals(9999, streak_tracker::date_diff_days('', '2026-05-08'));
    }

    public function test_mark_sent_records_kind_and_time(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        streak_tracker::record_activity($user->id, $course->id);

        streak_tracker::mark_sent($user->id, $course->id, 'streak7');

        $row = streak_tracker::get($user->id, $course->id);
        $this->assertEquals('streak7', $row->last_milestone_kind);
        $this->assertGreaterThan(0, (int)$row->last_milestone_at);
    }

    /**
     * Crossing the streak7 threshold via simulated DB rows. We can't
     * naturally march 7 days forward in a unit test, so this asserts the
     * detection logic by populating the row to streak=7 manually then
     * calling the helper through a one-day forward record_activity().
     */
    public function test_streak7_milestone_detected_when_threshold_crossed(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $now = time();
        $yesterday = userdate($now - 86400, '%Y-%m-%d', \core_date::get_server_timezone());

        // Seed: learner had a 6-day streak ending yesterday, no milestone yet.
        $DB->insert_record('local_ai_course_assistant_streak', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'current_streak_days' => 6, 'longest_streak_days' => 6,
            'last_active_date' => $yesterday,
            'last_milestone_kind' => '',
            'last_milestone_at' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        // Today's activity should bump to 7 AND signal the milestone.
        $milestone = streak_tracker::record_activity($user->id, $course->id);

        $this->assertEquals('streak7', $milestone);
        $row = streak_tracker::get($user->id, $course->id);
        $this->assertEquals(7, $row->current_streak_days);
    }
}
