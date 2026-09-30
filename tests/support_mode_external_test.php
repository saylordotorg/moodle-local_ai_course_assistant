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
 * The drawer's web services, called by a support learner who is not enrolled.
 *
 * This file exists because the first implementation of support mode was broken
 * in exactly the case it was written for, and every unit test passed anyway.
 *
 * external_api::validate_context() ends in
 * `require_login($course, false, $cm, false, true)`. The final argument makes it
 * throw rather than redirect, and for a course context require_login() enforces
 * ENROLMENT. So validating the support course's context rejected every learner
 * who was not enrolled in it -- which is the entire intended audience -- and it
 * did so before the function body ran, so support_mode::require_use() was never
 * reached. The widget rendered on the dashboard and then failed to boot, because
 * get_config and get_history are the drawer's first two calls.
 *
 * The support_mode unit tests missed it because they exercise require_use()
 * directly. Nothing called an external function as an unenrolled support user.
 * These tests do.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\support_mode::validation_context
 */
final class support_mode_external_test extends \advanced_testcase {

    /** @var \stdClass The designated support course. */
    private $supportcourse;

    /** @var \stdClass A learner enrolled in nothing at all. */
    private $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $gen = $this->getDataGenerator();
        $this->supportcourse = $gen->create_course();
        $this->user = $gen->create_user();

        set_config('enabled', 1, 'local_ai_course_assistant');
        set_config('support_enabled', 1, 'local_ai_course_assistant');
        set_config('support_courseid', $this->supportcourse->id, 'local_ai_course_assistant');
        support_mode::reset_cache();

        $this->setUser($this->user);
    }

    protected function tearDown(): void {
        // The resolved-course memo is static. It survives resetAfterTest, and
        // only is_enabled()'s short-circuit on support_enabled keeps it from
        // leaking today. That is one refactor away from being wrong.
        support_mode::reset_cache();
        parent::tearDown();
    }

    /**
     * The precondition that makes this whole file necessary.
     */
    public function test_the_support_learner_is_genuinely_not_enrolled(): void {
        $context = \context_course::instance($this->supportcourse->id);

        $this->assertFalse(
            is_enrolled($context, $this->user),
            'the learner must not be enrolled, or these tests prove nothing'
        );
        $this->assertTrue(support_mode::can_use(), 'but they must hold :usesupport');
    }

    /**
     * validate_context() on the course would throw for this user.
     *
     * Asserted directly against Moodle rather than inferred, because the whole
     * fix rests on it. If a future Moodle stops enforcing enrolment here, this
     * test fails and tells us the workaround is no longer needed.
     */
    public function test_validating_the_course_context_would_reject_the_support_learner(): void {
        $this->expectException(\require_login_exception::class);
        \core_external\external_api::validate_context(
            \context_course::instance($this->supportcourse->id)
        );
    }

    /**
     * On a support turn, the context to validate is the system context.
     */
    public function test_a_support_turn_validates_at_system_context(): void {
        $coursecontext = \context_course::instance($this->supportcourse->id);
        $chosen = support_mode::validation_context((int) $this->supportcourse->id, $coursecontext);

        $this->assertSame(CONTEXT_SYSTEM, $chosen->contextlevel);
    }

    /**
     * An ordinary course turn still validates the course context.
     *
     * The other direction of the same change: this must not become a way to skip
     * enrolment checks on ordinary courses.
     */
    public function test_an_ordinary_course_turn_still_validates_the_course(): void {
        $other = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($other->id);
        $chosen = support_mode::validation_context((int) $other->id, $coursecontext);

        $this->assertSame(CONTEXT_COURSE, $chosen->contextlevel);
        $this->assertSame($coursecontext->id, $chosen->id);
    }

    /**
     * With support mode off, the support course validates like any course.
     */
    public function test_support_mode_off_validates_the_course_context(): void {
        set_config('support_enabled', 0, 'local_ai_course_assistant');
        support_mode::reset_cache();
        $coursecontext = \context_course::instance($this->supportcourse->id);

        $chosen = support_mode::validation_context((int) $this->supportcourse->id, $coursecontext);
        $this->assertSame(CONTEXT_COURSE, $chosen->contextlevel);
    }

    /**
     * A guest gets the course context, and therefore the enrolment check.
     *
     * can_use() refuses guests, so validation_context() must not hand them the
     * system-context shortcut.
     */
    public function test_a_guest_does_not_get_the_system_context_shortcut(): void {
        $this->setGuestUser();
        $coursecontext = \context_course::instance($this->supportcourse->id);

        $chosen = support_mode::validation_context((int) $this->supportcourse->id, $coursecontext);
        $this->assertSame(CONTEXT_COURSE, $chosen->contextlevel);
    }

    // ------------------------------------------------- the endpoints themselves

    /**
     * get_config is the drawer's first call. It must work for a support learner.
     */
    public function test_get_config_works_for_an_unenrolled_support_learner(): void {
        $result = \local_ai_course_assistant\external\get_config::execute((int) $this->supportcourse->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('enabled', $result);
        $this->assertTrue((bool) $result['enabled']);
    }

    /**
     * get_history is the drawer's second call.
     */
    public function test_get_history_works_for_an_unenrolled_support_learner(): void {
        $result = \local_ai_course_assistant\external\get_history::execute((int) $this->supportcourse->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('messages', $result);
    }

    /**
     * clear_history is reachable from the drawer header.
     */
    public function test_clear_history_works_for_an_unenrolled_support_learner(): void {
        $result = \local_ai_course_assistant\external\clear_history::execute((int) $this->supportcourse->id);

        $this->assertIsArray($result);
    }

    /**
     * An unrelated course is still refused for a learner with no business there.
     *
     * The support capability must not have become a skeleton key: this is the
     * assertion that would fail if validation_context() ever returned the system
     * context for anything but the designated support course.
     */
    public function test_an_unrelated_course_is_still_refused(): void {
        $stranger = $this->getDataGenerator()->create_course();

        $this->expectException(\require_login_exception::class);
        \local_ai_course_assistant\external\get_config::execute((int) $stranger->id);
    }

    /**
     * With support mode off, the support course is refused like any other.
     *
     * Confirms an existing site sees no change in these endpoints until an
     * administrator switches the feature on.
     */
    public function test_with_support_mode_off_the_endpoint_refuses(): void {
        set_config('support_enabled', 0, 'local_ai_course_assistant');
        support_mode::reset_cache();

        $this->expectException(\require_login_exception::class);
        \local_ai_course_assistant\external\get_config::execute((int) $this->supportcourse->id);
    }

    /**
     * submit_feedback: the thumbs control under a support reply.
     */
    public function test_submit_feedback_works_for_an_unenrolled_support_learner(): void {
        $result = \local_ai_course_assistant\external\submit_feedback::execute(
            (int) $this->supportcourse->id,
            5,
            'Support mode answered my question.',
            'Firefox',
            'macOS',
            'desktop',
            '1920x1080',
            'phpunit',
            '/my/'
        );

        $this->assertIsArray($result);
    }

    /**
     * rate_message: worth pinning separately because it is the one endpoint that
     * takes its course from the STORED MESSAGE rather than from a parameter, so
     * it exercises a different path into validation_context().
     */
    public function test_rate_message_works_for_an_unenrolled_support_learner(): void {
        global $DB;

        $conv = conversation_manager::get_or_create_conversation(
            (int) $this->user->id,
            (int) $this->supportcourse->id
        );
        $messageid = $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => $conv->id,
            'userid'         => (int) $this->user->id,
            'courseid'       => (int) $this->supportcourse->id,
            'role'           => 'assistant',
            'message'        => 'A support answer.',
            'timecreated'    => time(),
        ]);

        $result = \local_ai_course_assistant\external\rate_message::execute((int) $messageid, 1);

        $this->assertIsArray($result);
    }
}
