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

namespace local_ai_course_assistant\external;

use local_ai_course_assistant\radar_schedule_manager;

/**
 * Tests for the external services that replaced the radar_schedule.php and
 * talking_avatar_session.php AJAX_SCRIPT endpoints (CONTRIB-10574 #275).
 *
 * Two properties matter more than the happy path and are what this file is
 * mostly about: a user without the capability is refused, and a user cannot
 * act on another user's data.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\get_radar_schedule
 * @covers     \local_ai_course_assistant\external\save_radar_schedule
 * @covers     \local_ai_course_assistant\external\delete_radar_schedule
 * @covers     \local_ai_course_assistant\external\toggle_radar_schedule
 * @covers     \local_ai_course_assistant\external\start_avatar_session
 */
final class external_radar_schedule_avatar_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * A stored schedule, created straight through the manager.
     *
     * @param array $overrides Column overrides.
     * @return int Schedule id.
     */
    private function make_schedule(array $overrides = []): int {
        return radar_schedule_manager::save($overrides + [
            'name' => 'Weekly confusion scan',
            'query' => 'Which units confuse learners most?',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'frequency' => 'weekly',
            'recipient_email' => 'reports@example.org',
            'format' => 'text',
            'filterprovider' => 'gemini',
            'enabled' => 1,
        ], 2);
    }

    // ───────────────────────────────────────────────────────────
    // Capability: a non-admin is refused by every radar service.
    // ───────────────────────────────────────────────────────────

    public function test_get_radar_schedule_refuses_a_user_without_siteconfig(): void {
        $id = $this->make_schedule();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        get_radar_schedule::execute($id);
    }

    public function test_save_radar_schedule_refuses_a_user_without_siteconfig(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        save_radar_schedule::execute(0, 'Mine', 'Who is struggling?');
    }

    public function test_delete_radar_schedule_refuses_a_user_without_siteconfig(): void {
        global $DB;
        $id = $this->make_schedule();
        $this->setUser($this->getDataGenerator()->create_user());
        try {
            delete_radar_schedule::execute($id);
            $this->fail('A user without moodle/site:config must not delete a schedule.');
        } catch (\required_capability_exception $e) {
            $this->assertTrue($DB->record_exists('local_ai_course_assistant_radar_sched', ['id' => $id]));
        }
    }

    public function test_toggle_radar_schedule_refuses_a_user_without_siteconfig(): void {
        global $DB;
        $id = $this->make_schedule();
        $this->setUser($this->getDataGenerator()->create_user());
        try {
            toggle_radar_schedule::execute($id, false);
            $this->fail('A user without moodle/site:config must not pause a schedule.');
        } catch (\required_capability_exception $e) {
            $this->assertEquals(
                1,
                (int) $DB->get_field('local_ai_course_assistant_radar_sched', 'enabled', ['id' => $id])
            );
        }
    }

    /**
     * A teacher is not an administrator. The radar reports on every learner on
     * the site, so course-level power must not reach it.
     */
    public function test_a_teacher_cannot_read_a_schedule(): void {
        $id = $this->make_schedule();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $this->expectException(\required_capability_exception::class);
        get_radar_schedule::execute($id);
    }

    // ───────────────────────────────────────────────────────────
    // Behaviour, admin side.
    // ───────────────────────────────────────────────────────────

    public function test_get_radar_schedule_round_trips_its_declared_return(): void {
        $this->setAdminUser();
        $id = $this->make_schedule();
        $result = get_radar_schedule::execute($id);
        $clean = \core_external\external_api::clean_returnvalue(
            get_radar_schedule::execute_returns(),
            $result
        );
        $this->assertEquals($result, $clean);
        $this->assertSame('Weekly confusion scan', $result['name']);
        $this->assertSame('Which units confuse learners most?', $result['query']);
        // Never run, no explicit window: both must survive as null, not 0.
        $this->assertNull($result['range_days']);
        $this->assertNull($result['last_run']);
    }

    public function test_get_radar_schedule_throws_for_a_missing_id(): void {
        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        get_radar_schedule::execute(999999);
    }

    public function test_save_radar_schedule_creates_and_updates(): void {
        global $DB;
        $this->setAdminUser();

        $created = save_radar_schedule::execute(0, 'Nightly', 'What went wrong today?', 'openai', '', 'daily');
        $this->assertGreaterThan(0, $created['id']);
        $row = $DB->get_record('local_ai_course_assistant_radar_sched', ['id' => $created['id']]);
        $this->assertSame('daily', $row->frequency);

        $updated = save_radar_schedule::execute($created['id'], 'Nightly v2', 'What went wrong today?');
        $this->assertSame($created['id'], $updated['id']);
        $this->assertSame(
            'Nightly v2',
            $DB->get_field('local_ai_course_assistant_radar_sched', 'name', ['id' => $created['id']])
        );
    }

    /**
     * The modal has no filterprovider input, so an edit omits the key. Omitted
     * must keep the stored filter; an explicit empty string must clear it.
     */
    public function test_save_radar_schedule_keeps_an_omitted_filterprovider(): void {
        global $DB;
        $this->setAdminUser();
        $id = $this->make_schedule();

        save_radar_schedule::execute($id, 'Weekly confusion scan', 'Which units confuse learners most?');
        $this->assertSame(
            'gemini',
            $DB->get_field('local_ai_course_assistant_radar_sched', 'filterprovider', ['id' => $id])
        );

        save_radar_schedule::execute(
            $id, 'Weekly confusion scan', 'Which units confuse learners most?',
            '', '', 'weekly', '', '', '', 'text', '', ''
        );
        $this->assertSame(
            '',
            $DB->get_field('local_ai_course_assistant_radar_sched', 'filterprovider', ['id' => $id])
        );
    }

    /**
     * A blank range means "site default" (NULL), which is not the same as 0.
     */
    public function test_save_radar_schedule_keeps_a_blank_range_as_null(): void {
        global $DB;
        $this->setAdminUser();
        $created = save_radar_schedule::execute(0, 'Blank range', 'Anything?');
        $this->assertNull(
            $DB->get_field('local_ai_course_assistant_radar_sched', 'range_days', ['id' => $created['id']])
        );
    }

    public function test_save_radar_schedule_rejects_an_empty_name_or_query(): void {
        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        save_radar_schedule::execute(0, '', 'Who is struggling?');
    }

    public function test_toggle_and_delete_round_trip(): void {
        global $DB;
        $this->setAdminUser();
        $id = $this->make_schedule();

        $toggled = toggle_radar_schedule::execute($id, false);
        $this->assertTrue($toggled['status']);
        $this->assertEquals(
            $toggled,
            \core_external\external_api::clean_returnvalue(toggle_radar_schedule::execute_returns(), $toggled)
        );
        $this->assertEquals(
            0,
            (int) $DB->get_field('local_ai_course_assistant_radar_sched', 'enabled', ['id' => $id])
        );
        // The rest of the row must survive a toggle, range_days included.
        $this->assertNull($DB->get_field('local_ai_course_assistant_radar_sched', 'range_days', ['id' => $id]));
        $this->assertSame(
            'gemini',
            $DB->get_field('local_ai_course_assistant_radar_sched', 'filterprovider', ['id' => $id])
        );

        $deleted = delete_radar_schedule::execute($id);
        $this->assertTrue($deleted['status']);
        $this->assertEquals(
            $deleted,
            \core_external\external_api::clean_returnvalue(delete_radar_schedule::execute_returns(), $deleted)
        );
        $this->assertFalse($DB->record_exists('local_ai_course_assistant_radar_sched', ['id' => $id]));
    }

    /**
     * All five services are declared in db/services.php and every declared
     * class can describe itself. A class renamed without touching the
     * declaration, or a malformed structure, fails the browser call with a
     * 500 that no other test here would see.
     */
    public function test_every_new_service_is_declared_and_describable(): void {
        $functions = [];
        require(__DIR__ . '/../db/services.php');

        $expected = [
            'local_ai_course_assistant_get_radar_schedule' => 'moodle/site:config',
            'local_ai_course_assistant_save_radar_schedule' => 'moodle/site:config',
            'local_ai_course_assistant_delete_radar_schedule' => 'moodle/site:config',
            'local_ai_course_assistant_toggle_radar_schedule' => 'moodle/site:config',
            'local_ai_course_assistant_start_avatar_session' => 'local/ai_course_assistant:use',
        ];

        foreach ($expected as $name => $capability) {
            $this->assertArrayHasKey($name, $functions, $name . ' is missing from db/services.php');
            $this->assertTrue($functions[$name]['ajax'], $name . ' must be callable from core/ajax');
            $this->assertSame($capability, $functions[$name]['capabilities']);

            $class = $functions[$name]['classname'];
            $this->assertTrue(class_exists($class), $class . ' does not exist');
            $this->assertInstanceOf(
                \core_external\external_function_parameters::class,
                $class::execute_parameters()
            );
            $this->assertInstanceOf(
                \core_external\external_single_structure::class,
                $class::execute_returns()
            );
        }
    }

    public function test_toggle_radar_schedule_throws_for_a_missing_id(): void {
        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        toggle_radar_schedule::execute(999999, true);
    }

    // ───────────────────────────────────────────────────────────
    // start_avatar_session
    // ───────────────────────────────────────────────────────────

    /**
     * An enrolled student with the talking-avatar flag on.
     *
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function avatar_student(): array {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        set_config('talking_avatar_enabled', 1, 'local_ai_course_assistant');
        $this->setUser($user);
        return [$course, $user];
    }

    /**
     * An open session row for a given user.
     *
     * @param int $userid
     * @param int $courseid
     * @return void
     */
    private function open_avatar_row(int $userid, int $courseid): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_avatar_sess', (object) [
            'userid' => $userid,
            'courseid' => $courseid,
            'provider' => 'tavus',
            'persona_id' => '',
            'upstream_session_id' => 'sess-' . $userid,
            'started_at' => time(),
            'ended_at' => null,
            'duration_sec' => 0,
            'est_cost_usd' => 0,
            'source' => 'open',
        ]);
    }

    public function test_start_avatar_session_refuses_a_user_without_the_capability(): void {
        $course = $this->getDataGenerator()->create_course();
        set_config('talking_avatar_enabled', 1, 'local_ai_course_assistant');
        // Not enrolled, so neither require_login nor the :use capability holds.
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\moodle_exception::class);
        start_avatar_session::execute((int) $course->id);
    }

    public function test_start_avatar_session_reports_the_feature_being_off(): void {
        [$course] = $this->avatar_student();
        set_config('talking_avatar_enabled', 0, 'local_ai_course_assistant');
        $result = start_avatar_session::execute((int) $course->id);
        $this->assertFalse($result['ok']);
        $this->assertSame('disabled', $result['reason']);
        $this->assertEquals(
            $result,
            \core_external\external_api::clean_returnvalue(start_avatar_session::execute_returns(), $result)
        );
    }

    /**
     * The one-open-session guard counts the CALLER's rows. Two of this
     * learner's own sessions lock them out.
     */
    public function test_start_avatar_session_refuses_a_second_session_for_the_same_user(): void {
        [$course, $user] = $this->avatar_student();
        $this->open_avatar_row((int) $user->id, (int) $course->id);
        $this->open_avatar_row((int) $user->id, (int) $course->id);

        $result = start_avatar_session::execute((int) $course->id);
        $this->assertFalse($result['ok']);
        $this->assertSame('session_open', $result['reason']);
    }

    /**
     * A user cannot act on, or be acted on through, another user's rows. Two
     * open sessions belonging to someone else must not lock this learner out,
     * and the refusal that does come back must be about provider config, not
     * about a session the caller does not own.
     */
    public function test_another_users_open_sessions_do_not_block_this_learner(): void {
        [$course, $user] = $this->avatar_student();
        $other = $this->getDataGenerator()->create_user();
        $this->open_avatar_row((int) $other->id, (int) $course->id);
        $this->open_avatar_row((int) $other->id, (int) $course->id);
        $this->setUser($user);

        $result = start_avatar_session::execute((int) $course->id);
        $this->assertFalse($result['ok']);
        $this->assertSame(
            'unconfigured',
            $result['reason'],
            'The open-session guard must count only the calling user\'s own sessions.'
        );
    }

    /**
     * There is no userid parameter, so a session can only ever be opened for
     * the caller. The contract is the guard, so assert the contract.
     */
    public function test_start_avatar_session_takes_no_userid_parameter(): void {
        $keys = array_keys(start_avatar_session::execute_parameters()->keys);
        $this->assertSame(['courseid', 'lang', 'greeting'], $keys);
    }
}
