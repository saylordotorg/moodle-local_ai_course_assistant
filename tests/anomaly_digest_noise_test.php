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
 * The anomaly digest stops crying wolf on small numbers and stops repeating itself.
 *
 * Seen on Learn and Degrees, Oct 2026: "Negative ratings up 300% (4 vs 1 prior)"
 * nearly every day. 4 vs 1 is three thumbs-down, and the 7-day windows slide a day
 * at a time, so one small bump stayed over the line and re-sent.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\task\run_anomaly_digest
 */
final class anomaly_digest_noise_test extends \advanced_testcase {

    /**
     * Call a private method of the task.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private function call(string $method, array $args = []) {
        $task = new \local_ai_course_assistant\task\run_anomaly_digest();
        $ref = new \ReflectionMethod($task, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($ref->isStatic() ? null : $task, $args);
    }

    /**
     * Seed negative ratings.
     *
     * @param int $count
     * @param int $secondsago
     * @return void
     */
    private function rate(int $count, int $secondsago): void {
        global $DB;
        static $next = 1;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        for ($i = 0; $i < $count; $i++) {
            $DB->insert_record('local_ai_course_assistant_msg_ratings', (object) [
                'messageid' => $next++, 'userid' => $user->id, 'courseid' => $course->id,
                'rating' => -1, 'is_hallucination' => 0, 'comment' => "bad answer $i",
                'rated_excerpt' => 'x', 'timecreated' => time() - $secondsago,
            ]);
        }
    }

    private const SQL = "SELECT COUNT(id) FROM {local_ai_course_assistant_msg_ratings} "
        . "WHERE rating = -1 AND timecreated >= ? AND timecreated < ?";

    public function test_four_against_one_no_longer_alerts(): void {
        $this->resetAfterTest(true);
        $this->rate(4, 3 * 86400);
        $this->rate(1, 10 * 86400);
        $this->assertNull($this->call('compare_metric_change', [self::SQL, 7 * 86400, 50, 10, 5]),
            '+300% on 3 extra ratings is noise');
    }

    public function test_the_old_behaviour_is_still_there_without_minimums(): void {
        $this->resetAfterTest(true);
        $this->rate(4, 3 * 86400);
        $this->rate(1, 10 * 86400);
        $res = $this->call('compare_metric_change', [self::SQL, 7 * 86400, 50]);
        $this->assertSame(300, $res['pct']);
    }

    public function test_a_real_jump_still_alerts(): void {
        $this->resetAfterTest(true);
        $this->rate(20, 3 * 86400);
        $this->rate(8, 10 * 86400);
        $res = $this->call('compare_metric_change', [self::SQL, 7 * 86400, 50, 10, 5]);
        $this->assertNotNull($res);
        $this->assertSame(20, $res['recent']);
    }

    public function test_a_large_count_with_a_tiny_rise_does_not_alert(): void {
        $this->resetAfterTest(true);
        $this->rate(11, 3 * 86400);
        $this->rate(8, 10 * 86400);
        $this->assertNull($this->call('compare_metric_change', [self::SQL, 7 * 86400, 20, 10, 5]),
            '11 vs 8 clears the percentage and the count floor but is only 3 more');
    }

    public function test_from_nothing_needs_the_count_floor_too(): void {
        $this->resetAfterTest(true);
        $this->rate(6, 3 * 86400);
        $this->assertNull($this->call('compare_metric_change', [self::SQL, 7 * 86400, 50, 10, 5]));
        $this->rate(6, 2 * 86400);
        $this->assertNotNull($this->call('compare_metric_change', [self::SQL, 7 * 86400, 50, 10, 5]));
    }

    public function test_an_alert_is_quiet_inside_the_cooldown_unless_it_grows(): void {
        $this->resetAfterTest(true);
        $this->assertFalse($this->call('in_cooldown', ['negative_ratings', 15]), 'nothing sent yet');
        $this->call('remember_sent', ['negative_ratings', 15]);
        $this->assertTrue($this->call('in_cooldown', ['negative_ratings', 15]));
        $this->assertTrue($this->call('in_cooldown', ['negative_ratings', 12]));
        $this->assertFalse($this->call('in_cooldown', ['negative_ratings', 16]), 'worse again: tell me');
    }

    public function test_the_cooldown_ends(): void {
        $this->resetAfterTest(true);
        set_config('anomaly_digest_last_token_spend',
            json_encode(['t' => time() - 4 * DAYSECS, 'v' => 100]), 'local_ai_course_assistant');
        $this->assertFalse($this->call('in_cooldown', ['token_spend', 100]));
    }

    public function test_detail_names_courses_and_the_email_gets_the_comments_but_slack_does_not(): void {
        $this->resetAfterTest(true);
        $this->rate(3, 86400);
        $detail = $this->call('negative_rating_detail');
        $this->assertNotEmpty($detail['courses']);
        $this->assertCount(3, $detail['comments']);

        $alerts = ['negative_ratings' => ['line' => 'Negative ratings up.', 'value' => 3, 'detail' => $detail]];
        $email = $this->call('build_body', [$alerts, true]);
        $slack = $this->call('build_body', [$alerts, false]);
        $this->assertStringContainsString('bad answer', $email);
        $this->assertStringNotContainsString('bad answer', $slack,
            'learners\' own words must not go to a webhook');
        $this->assertStringContainsString('Most thumbs-down, by course', $slack);
    }
}
