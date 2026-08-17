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
 * The active-learners indicator is off by default (v6.9.7) and the endpoint
 * must not answer when it is off.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\get_active_learners
 */
final class active_learners_gate_test extends \advanced_testcase {

    /** @var \stdClass Course the learners share. */
    private $course;
    /** @var \stdClass The calling user. */
    private $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->user->id, $this->course->id);
        $this->setUser($this->user);
    }

    /**
     * Write msgs rows for $n distinct other learners inside the active window,
     * so a working endpoint would report exactly $n.
     *
     * @param int $n How many other learners to make active.
     * @return void
     */
    private function seed_active_learners(int $n): void {
        global $DB;
        for ($i = 0; $i < $n; $i++) {
            $other = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($other->id, $this->course->id);
            $DB->insert_record('local_ai_course_assistant_msgs', (object) [
                'courseid' => $this->course->id,
                'userid' => $other->id,
                // conversationid is an int column, not a string handle.
                'conversationid' => $i + 1,
                'role' => 'user',
                'message' => 'hello',
                'timecreated' => time() - 60,
            ]);
        }
    }

    public function test_disabled_by_default(): void {
        // No set_config call at all: this asserts the SHIPPED default, which is
        // the point of the change. If someone flips the default in settings.php
        // this test fails rather than silently re-enabling the poll everywhere.
        $this->seed_active_learners(5);

        $result = \local_ai_course_assistant\external\get_active_learners::execute($this->course->id);

        $this->assertSame(0, (int) $result['count'],
            'The endpoint must not report a count while the feature is off.');
        $this->assertSame('disabled', $result['scope'],
            'Scope should report disabled so the client can tell "off" from "nobody here".');
    }

    public function test_returns_count_once_enabled(): void {
        set_config('active_learners_enabled', 1, 'local_ai_course_assistant');
        set_config('active_learners_scope', 'course', 'local_ai_course_assistant');
        $this->seed_active_learners(3);

        $result = \local_ai_course_assistant\external\get_active_learners::execute($this->course->id);

        $this->assertSame(3, (int) $result['count']);
        $this->assertSame('course', $result['scope']);
    }

    public function test_explicit_zero_is_still_disabled(): void {
        // '0' and 0 and '' must all read as off — get_config returns strings.
        set_config('active_learners_enabled', 0, 'local_ai_course_assistant');
        $this->seed_active_learners(4);

        $result = \local_ai_course_assistant\external\get_active_learners::execute($this->course->id);

        $this->assertSame(0, (int) $result['count']);
        $this->assertSame('disabled', $result['scope']);
    }

    public function test_caller_still_needs_the_use_capability_when_enabled(): void {
        // The gate must not become a substitute for the capability check. Enrol
        // the user first so we test the CAPABILITY specifically -- an unenrolled
        // user is stopped earlier by validate_context() with a require_login
        // exception, which would pass this test without proving anything about
        // the capability.
        set_config('active_learners_enabled', 1, 'local_ai_course_assistant');
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->course->id);
        $roleid = $this->getDataGenerator()->create_role();
        role_assign($roleid, $other->id, \context_course::instance($this->course->id));
        assign_capability('local/ai_course_assistant:use', CAP_PROHIBIT, $roleid,
            \context_course::instance($this->course->id), true);
        $this->setUser($other);

        $this->expectException(\required_capability_exception::class);
        \local_ai_course_assistant\external\get_active_learners::execute($this->course->id);
    }
}
