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

use local_ai_course_assistant\external\clear_history;
use local_ai_course_assistant\external\rate_message;
use local_ai_course_assistant\external\save_avatar_preference;
use local_ai_course_assistant\external\dismiss_intro;
use local_ai_course_assistant\external\get_history;
use local_ai_course_assistant\external\record_objective_attempt;

/**
 * External service contract tests (v5.3.20).
 *
 * The chat client (amd/src/repository.js) calls these services on
 * every turn and on every state change. A regression in capability
 * checks, parameter validation, or the return-shape contract surfaces
 * as silent failures or hard exceptions in the browser. These tests
 * gate each service against its declared execute_parameters /
 * execute_returns contract on every push.
 *
 * Coverage: the load-bearing handful (chat clear / message rate /
 * avatar save / intro dismiss / history fetch / mastery attempt). The
 * remaining 30+ services are not yet covered; future releases can
 * extend this file as bugs surface.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class external_services_test extends \advanced_testcase {

    /**
     * Common: enrolled student + setUser. The three-line setup pattern.
     *
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function enrolled_student(): array {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        return [$course, $user];
    }

    // ───────────────────────────────────────────────────────────
    // clear_history
    // ───────────────────────────────────────────────────────────

    public function test_clear_history_happy_path_returns_success_true(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled_student();

        // Seed a conversation + message so there is something to clear.
        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);
        conversation_manager::add_message($conv->id, $user->id, $course->id, 'user', 'hi');

        $result = clear_history::execute((int)$course->id);

        $this->assertSame(['success' => true], $result);
        // External API spec compliance: actually run the result through
        // execute_returns so PARAM_BOOL coercion + missing-key detection
        // both fire.
        $clean = \core_external\external_api::clean_returnvalue(
            clear_history::execute_returns(), $result);
        $this->assertSame(['success' => true], $clean);
    }

    public function test_clear_history_unenrolled_user_throws(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        // Deliberately NOT enrolled. The external API stack rejects via
        // require_login_exception (moodle_exception subclass) before
        // the require_capability check fires.
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        clear_history::execute((int)$course->id);
    }

    public function test_clear_history_unknown_courseid_throws(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        // courseid 999999 doesn't exist; context_course::instance throws
        // dml_missing_record_exception. -1 would do the same. Either is
        // an acceptable contract — the service must NOT silently no-op.
        $this->expectException(\moodle_exception::class);
        clear_history::execute(999999);
    }

    // ───────────────────────────────────────────────────────────
    // rate_message
    // ───────────────────────────────────────────────────────────

    public function test_rate_message_thumbs_up_records_rating(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $user] = $this->enrolled_student();

        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);
        conversation_manager::add_message($conv->id, $user->id, $course->id, 'assistant', 'reply');
        $msgs = conversation_manager::get_messages($conv->id);
        $msg = end($msgs);

        $result = rate_message::execute((int)$msg->id, 1);

        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $row = $DB->get_record('local_ai_course_assistant_msg_ratings',
            ['messageid' => $msg->id, 'userid' => $user->id]);
        $this->assertNotFalse($row);
        $this->assertEquals(1, (int)$row->rating);
    }

    public function test_rate_message_invalid_rating_value_throws(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled_student();
        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);
        conversation_manager::add_message($conv->id, $user->id, $course->id, 'assistant', 'reply');
        $msgs = conversation_manager::get_messages($conv->id);
        $msg = end($msgs);

        // The contract is rating in {-1, 0, 1}; anything else should be
        // rejected by validate_parameters or the handler.
        $this->expectException(\moodle_exception::class);
        rate_message::execute((int)$msg->id, 99);
    }

    // ───────────────────────────────────────────────────────────
    // save_avatar_preference
    // ───────────────────────────────────────────────────────────

    public function test_save_avatar_preference_stores_user_pref(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled_student();

        $result = save_avatar_preference::execute('avatar_07');

        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertEquals('avatar_07',
            get_user_preferences('local_ai_course_assistant_avatar', '', $user->id));
    }

    public function test_save_avatar_preference_rejects_unknown_id(): void {
        $this->resetAfterTest();
        $this->enrolled_student();
        $this->expectException(\invalid_parameter_exception::class);
        save_avatar_preference::execute('not-a-real-avatar');
    }

    // ───────────────────────────────────────────────────────────
    // dismiss_intro
    // ───────────────────────────────────────────────────────────

    public function test_dismiss_intro_marks_user_pref(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled_student();

        $result = dismiss_intro::execute();

        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        // The exact pref key is implementation detail, but SOMETHING
        // must change after the call. Look for any user preference
        // beginning with the local_ai_course_assistant prefix.
        $prefs = get_user_preferences(null, null, $user->id);
        $found = false;
        foreach ($prefs as $k => $v) {
            if (strpos($k, 'local_ai_course_assistant') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'dismiss_intro must record at least one user preference.');
    }

    // ───────────────────────────────────────────────────────────
    // get_history
    // ───────────────────────────────────────────────────────────

    public function test_get_history_returns_messages_in_order(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled_student();
        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);
        conversation_manager::add_message($conv->id, $user->id, $course->id, 'user', 'q1');
        conversation_manager::add_message($conv->id, $user->id, $course->id, 'assistant', 'a1');

        $result = get_history::execute((int)$course->id);

        $this->assertArrayHasKey('messages', $result);
        $messages = $result['messages'];
        $this->assertGreaterThanOrEqual(2, count($messages));
        // First-in / last-in ordering: the user message must precede the
        // assistant message.
        $userseen = false;
        foreach ($messages as $m) {
            if ($m['role'] === 'user' && $m['message'] === 'q1') {
                $userseen = true;
            }
            if ($m['role'] === 'assistant' && $m['message'] === 'a1') {
                $this->assertTrue($userseen,
                    'User message must appear before the assistant reply in get_history.');
            }
        }
    }

    public function test_get_history_empty_for_fresh_user(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled_student();

        $result = get_history::execute((int)$course->id);

        $this->assertArrayHasKey('messages', $result);
        $this->assertSame([], $result['messages'],
            'Fresh user with no chat history must get an empty messages array, not null or missing key.');
    }

    // ───────────────────────────────────────────────────────────
    // record_objective_attempt
    // ───────────────────────────────────────────────────────────

    public function test_record_objective_attempt_short_circuits_when_mastery_disabled(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled_student();

        // Mastery disabled by default for the new course.
        $result = record_objective_attempt::execute((int)$course->id, 1, true);

        $this->assertArrayHasKey('recorded', $result);
        $this->assertFalse($result['recorded'],
            'When mastery is disabled for the course, the call must short-circuit '
            . 'and return recorded=false rather than throwing.');
        $this->assertEquals('not_started', $result['status']);
    }

    public function test_record_objective_attempt_writes_row_when_mastery_enabled(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $user] = $this->enrolled_student();

        // Plant an objective in the (correct) table name + enable mastery.
        $objid = $DB->insert_record('local_ai_course_assistant_objs', (object)[
            'courseid' => $course->id,
            'shortname' => 'obj1',
            'description' => 'Test objective',
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        set_config('mastery_enabled_course_' . $course->id, '1', 'local_ai_course_assistant');

        $result = record_objective_attempt::execute((int)$course->id, (int)$objid, true);

        $this->assertArrayHasKey('recorded', $result);
        $this->assertTrue($result['recorded']);
        $row = $DB->get_record('local_ai_course_assistant_obj_att',
            ['userid' => $user->id, 'objectiveid' => $objid]);
        $this->assertNotFalse($row);
        $this->assertEquals(1, (int)$row->iscorrect);
    }

    // ───────────────────────────────────────────────────────────
    // Cross-service: every service's execute_returns must round-trip
    // its own happy-path result through clean_returnvalue without throwing.
    // ───────────────────────────────────────────────────────────

    public function test_clear_history_clean_returnvalue_round_trip(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled_student();
        conversation_manager::get_or_create_conversation($user->id, $course->id);

        $result = clear_history::execute((int)$course->id);
        $clean = \core_external\external_api::clean_returnvalue(
            clear_history::execute_returns(), $result);
        $this->assertEquals($result, $clean,
            'clear_history return must round-trip through its declared execute_returns.');
    }

    public function test_save_avatar_preference_clean_returnvalue_round_trip(): void {
        $this->resetAfterTest();
        $this->enrolled_student();

        $result = save_avatar_preference::execute('avatar_01');
        $clean = \core_external\external_api::clean_returnvalue(
            save_avatar_preference::execute_returns(), $result);
        $this->assertEquals($result, $clean);
    }
}
