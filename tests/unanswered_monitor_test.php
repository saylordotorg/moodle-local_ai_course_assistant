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
 * The unanswered-question monitor must fire on the shape of the August 2026
 * outage — many questions, no answers — and stay quiet otherwise.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\unanswered_monitor
 */
final class unanswered_monitor_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Write $asked user rows and $answered assistant rows into a course.
     *
     * @param int $courseid
     * @param int $asked
     * @param int $answered
     * @param int $agesecs How long ago the rows were written.
     * @return void
     */
    private function seed(int $courseid, int $asked, int $answered, int $agesecs = 600): void {
        global $DB;
        $rows = [];
        for ($i = 0; $i < $asked; $i++) {
            $rows[] = (object) ['courseid' => $courseid, 'userid' => 100 + $i, 'conversationid' => $i + 1,
                'role' => 'user', 'message' => 'q', 'timecreated' => time() - $agesecs];
        }
        for ($i = 0; $i < $answered; $i++) {
            $rows[] = (object) ['courseid' => $courseid, 'userid' => 100 + $i, 'conversationid' => $i + 1,
                'role' => 'assistant', 'message' => 'a', 'timecreated' => time() - $agesecs];
        }
        foreach ($rows as $r) {
            $DB->insert_record('local_ai_course_assistant_msgs', $r);
        }
    }

    public function test_fires_on_the_august_outage_shape(): void {
        // CS101: 176 asked, 0 answered over nine days. The monitor exists
        // because nothing caught this.
        $course = $this->getDataGenerator()->create_course(['shortname' => 'CS101']);
        $this->seed($course->id, 20, 0);

        $eval = unanswered_monitor::evaluate();

        $this->assertSame('unanswered', $eval['status']);
        $this->assertCount(1, $eval['failing']);
        // Moodle's generator hands back a string id; the monitor casts to int.
        $this->assertSame((int) $course->id, $eval['failing'][0]['courseid']);
        $this->assertSame(0.0, $eval['failing'][0]['rate']);
    }

    public function test_healthy_course_does_not_alert(): void {
        $course = $this->getDataGenerator()->create_course(['shortname' => 'OK101']);
        $this->seed($course->id, 20, 20);

        $eval = unanswered_monitor::evaluate();

        $this->assertSame('ok', $eval['status']);
        $this->assertSame([], $eval['failing']);
    }

    public function test_low_traffic_course_is_not_judged(): void {
        // Two learners closing the tab mid-answer must not read as an outage.
        $course = $this->getDataGenerator()->create_course(['shortname' => 'QUIET101']);
        $this->seed($course->id, 2, 0);

        $eval = unanswered_monitor::evaluate();

        $this->assertSame('ok', $eval['status'],
            'Below min_questions a course must not be judged at all.');
    }

    public function test_partial_failure_is_caught(): void {
        // Half the calls failing is still an outage worth knowing about; a
        // threshold near zero would miss one provider of several being down.
        $course = $this->getDataGenerator()->create_course(['shortname' => 'HALF101']);
        $this->seed($course->id, 20, 6);

        $eval = unanswered_monitor::evaluate();

        $this->assertSame('unanswered', $eval['status']);
        $this->assertSame(0.3, $eval['failing'][0]['rate']);
    }

    public function test_old_traffic_falls_outside_the_window(): void {
        // A failure that has already been fixed must stop alerting.
        $course = $this->getDataGenerator()->create_course(['shortname' => 'OLD101']);
        $this->seed($course->id, 20, 0, 60 * 60 * 24 * 3);

        $eval = unanswered_monitor::evaluate();

        $this->assertSame('ok', $eval['status']);
    }

    public function test_worst_course_is_listed_first(): void {
        $bad = $this->getDataGenerator()->create_course(['shortname' => 'BAD101']);
        $mid = $this->getDataGenerator()->create_course(['shortname' => 'MID101']);
        $this->seed($mid->id, 20, 8);
        $this->seed($bad->id, 20, 0);

        $eval = unanswered_monitor::evaluate();

        $this->assertCount(2, $eval['failing']);
        $this->assertSame((int) $bad->id, $eval['failing'][0]['courseid'],
            'The email leads with the worst course, so triage starts there.');
    }

    public function test_zero_rate_setting_disables_the_rate_test(): void {
        set_config('unanswered_min_answer_rate', 0, 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course(['shortname' => 'CS101']);
        $this->seed($course->id, 20, 0);

        $eval = unanswered_monitor::evaluate();

        // A configured 0 must be honoured, not treated as "unset" and replaced
        // by the default -- the ?: trap this codebase has hit before.
        $this->assertSame(0.0, $eval['min_rate']);
        $this->assertSame('ok', $eval['status']);
    }

    public function test_alert_is_sent_once_per_day(): void {
        $course = $this->getDataGenerator()->create_course(['shortname' => 'CS101']);
        $this->seed($course->id, 20, 0);
        set_config('spend_notify_emails', 'ops@example.com', 'local_ai_course_assistant');

        $sink = $this->redirectEmails();
        $eval = unanswered_monitor::evaluate();

        $this->assertTrue(unanswered_monitor::maybe_send_alert($eval));
        $this->assertFalse(unanswered_monitor::maybe_send_alert($eval),
            'A second run the same day must not re-send; cron runs often.');

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('CS101', $messages[0]->subject);
        $sink->close();
    }
}
