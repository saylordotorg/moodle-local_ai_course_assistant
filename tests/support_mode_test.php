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
 * Support mode: the assistant on pages that are not a course.
 *
 * The test this file exists for is test_a_live_attempt_elsewhere_locks_the_
 * support_surface(). Support mode's first implementation passed the course id it
 * was rendering against into quiz_lock, and quiz_lock adds `AND cm.course = ?`
 * under its default scope. The support course holds no quizzes, so the exam lock
 * matched nothing and never fired: a learner could open an exam in one tab, open
 * the dashboard in another, and get an unlocked assistant. Everything else here
 * is scaffolding around keeping that one property true.
 *
 * The second theme is that support mode must be invisible when it is off, which
 * is the state every existing site is in. Several tests assert the unchanged
 * behaviour rather than the new behaviour for that reason.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\support_mode
 */
final class support_mode_test extends \advanced_testcase {

    /** @var \stdClass The designated support course. */
    private $supportcourse;

    /** @var \stdClass An ordinary course the learner is enrolled in. */
    private $realcourse;

    /** @var \stdClass The learner. */
    private $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $gen = $this->getDataGenerator();
        $this->supportcourse = $gen->create_course(['fullname' => 'Student Support']);
        $this->realcourse = $gen->create_course();
        $this->user = $gen->create_user();
        $gen->enrol_user($this->user->id, $this->realcourse->id);
        support_mode::reset_cache();
    }

    /**
     * Switch support mode on, pointed at the support course.
     *
     * @return void
     */
    private function enable_support(): void {
        set_config('support_enabled', 1, 'local_ai_course_assistant');
        set_config('support_courseid', $this->supportcourse->id, 'local_ai_course_assistant');
        support_mode::reset_cache();
    }

    // ---------------------------------------------------------------- course id

    /**
     * With nothing configured, support mode resolves to nothing and is off.
     */
    public function test_unconfigured_support_mode_is_off(): void {
        $this->assertSame(0, support_mode::course_id());
        $this->assertFalse(support_mode::is_enabled());
    }

    /**
     * A valid, visible course id resolves and switches the feature on.
     */
    public function test_a_visible_course_resolves(): void {
        $this->enable_support();
        $this->assertSame((int) $this->supportcourse->id, support_mode::course_id());
        $this->assertTrue(support_mode::is_enabled());
    }

    /**
     * SITEID is refused however it is entered.
     *
     * Accepting it would reintroduce every collision the designated-course design
     * exists to avoid -- the shared Learning Radar conversation row above all --
     * through the back door of an administrator typing 1 into the box.
     */
    public function test_siteid_is_refused(): void {
        set_config('support_enabled', 1, 'local_ai_course_assistant');
        set_config('support_courseid', SITEID, 'local_ai_course_assistant');
        support_mode::reset_cache();

        $this->assertSame(0, support_mode::course_id());
        $this->assertFalse(support_mode::is_enabled());
    }

    /**
     * A hidden course is refused, because its content is hidden from learners.
     *
     * Surfacing it through the assistant would route around that, which is the
     * same reason supplemental_sources filters on visible = 1.
     */
    public function test_a_hidden_course_is_refused(): void {
        global $DB;
        $this->enable_support();
        $DB->set_field('course', 'visible', 0, ['id' => $this->supportcourse->id]);
        support_mode::reset_cache();

        $this->assertSame(0, support_mode::course_id());
        $this->assertFalse(support_mode::is_enabled());
    }

    /**
     * A deleted course degrades to "off", it does not break every page.
     */
    public function test_a_missing_course_degrades_to_off(): void {
        set_config('support_enabled', 1, 'local_ai_course_assistant');
        set_config('support_courseid', 999999, 'local_ai_course_assistant');
        support_mode::reset_cache();

        $this->assertSame(0, support_mode::course_id());
        $this->assertFalse(support_mode::is_enabled());
    }

    /**
     * The checkbox alone is not enough, and neither is the course alone.
     */
    public function test_both_halves_are_required(): void {
        set_config('support_enabled', 1, 'local_ai_course_assistant');
        set_config('support_courseid', '', 'local_ai_course_assistant');
        support_mode::reset_cache();
        $this->assertFalse(support_mode::is_enabled(), 'checkbox without a course');

        set_config('support_enabled', 0, 'local_ai_course_assistant');
        set_config('support_courseid', $this->supportcourse->id, 'local_ai_course_assistant');
        support_mode::reset_cache();
        $this->assertFalse(support_mode::is_enabled(), 'course without the checkbox');
    }

    // ------------------------------------------------------------- support turn

    /**
     * Only the designated course id is a support turn.
     */
    public function test_is_support_turn_recognises_only_the_support_course(): void {
        $this->enable_support();

        $this->assertTrue(support_mode::is_support_turn((int) $this->supportcourse->id));
        $this->assertFalse(support_mode::is_support_turn((int) $this->realcourse->id));
        $this->assertFalse(support_mode::is_support_turn(0));
        $this->assertFalse(support_mode::is_support_turn(SITEID));
    }

    /**
     * With the feature off, nothing is a support turn.
     */
    public function test_nothing_is_a_support_turn_when_disabled(): void {
        $this->assertFalse(support_mode::is_support_turn((int) $this->supportcourse->id));
    }

    // ------------------------------------------------- the integrity-scope fix

    /**
     * integrity_scope() is the identity function in course mode.
     *
     * This is what makes the change safe to ship: every existing quiz_lock call
     * site now routes through it, so if it ever returned anything but its
     * argument for an ordinary course it would silently undo the v7.2.5 per-course
     * scoping that stopped one forgotten attempt disabling the assistant
     * everywhere.
     */
    public function test_integrity_scope_is_identity_in_course_mode(): void {
        $this->enable_support();

        $this->assertSame((int) $this->realcourse->id, support_mode::integrity_scope((int) $this->realcourse->id));
        $this->assertSame(42, support_mode::integrity_scope(42));
        $this->assertSame(0, support_mode::integrity_scope(0));
    }

    /**
     * On a support turn it drops to 0, which is quiz_lock's site-wide branch.
     */
    public function test_integrity_scope_falls_back_to_site_wide_on_a_support_turn(): void {
        $this->enable_support();

        $this->assertSame(0, support_mode::integrity_scope((int) $this->supportcourse->id));
    }

    /**
     * With support mode off, the support course is scoped like any other course.
     */
    public function test_integrity_scope_is_untouched_when_support_mode_is_off(): void {
        $this->assertSame(
            (int) $this->supportcourse->id,
            support_mode::integrity_scope((int) $this->supportcourse->id)
        );
    }

    /**
     * END TO END: a live attempt in another course locks the support surface.
     *
     * The defect this prevents, stated as a sequence: a learner starts an exam in
     * their real course, opens the dashboard in a second tab, and asks the
     * assistant for help. If the support surface scopes the lock to the support
     * course -- which holds no quizzes -- the lock finds nothing and the learner
     * gets an unlocked assistant during a graded attempt.
     *
     * Asserted through quiz_lock itself rather than through integrity_scope()'s
     * return value, so the test still fails if the two stop being wired together.
     */
    public function test_a_live_attempt_elsewhere_locks_the_support_surface(): void {
        global $DB;
        $this->enable_support();
        set_config('quiz_lock_enabled', 1, 'local_ai_course_assistant');
        set_config('quiz_lock_scope', quiz_lock::SCOPE_COURSE, 'local_ai_course_assistant');

        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $this->realcourse->id,
            'timelimit' => 0,
        ]);
        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $quiz->id,
            'userid' => $this->user->id,
            'attempt' => 1,
            'uniqueid' => $DB->count_records('quiz_attempts') + 1000,
            'layout' => '1,0',
            'state' => 'inprogress',
            'preview' => 0,
            'timestart' => time() - 60,
            'timemodified' => time(),
            'sumgrades' => null,
        ]);

        // The naive implementation: scope to the course the widget renders
        // against. The support course has no quizzes, so this finds nothing.
        $this->assertFalse(
            quiz_lock::is_locked_for((int) $this->user->id, (int) $this->supportcourse->id),
            'precondition: scoping to the support course finds no attempt -- this is the bypass'
        );

        // What the code actually does.
        $this->assertTrue(
            quiz_lock::is_locked_for(
                (int) $this->user->id,
                support_mode::integrity_scope((int) $this->supportcourse->id)
            ),
            'a live attempt in another course must lock the support surface'
        );
    }

    /**
     * The v7.2.5 property still holds: an attempt does not lock unrelated courses.
     *
     * Guards the other direction of the same change. integrity_scope() must not
     * quietly widen ordinary course turns back to site-wide.
     */
    public function test_an_attempt_still_does_not_lock_an_unrelated_course(): void {
        global $DB;
        $this->enable_support();
        set_config('quiz_lock_enabled', 1, 'local_ai_course_assistant');
        set_config('quiz_lock_scope', quiz_lock::SCOPE_COURSE, 'local_ai_course_assistant');

        $other = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $this->realcourse->id,
            'timelimit' => 0,
        ]);
        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $quiz->id,
            'userid' => $this->user->id,
            'attempt' => 1,
            'uniqueid' => $DB->count_records('quiz_attempts') + 2000,
            'layout' => '1,0',
            'state' => 'inprogress',
            'preview' => 0,
            'timestart' => time() - 60,
            'timemodified' => time(),
            'sumgrades' => null,
        ]);

        $this->assertFalse(
            quiz_lock::is_locked_for(
                (int) $this->user->id,
                support_mode::integrity_scope((int) $other->id)
            ),
            'v7.2.5: an attempt in one course must not lock an unrelated course'
        );
    }

    // --------------------------------------------------------------- capability

    /**
     * A guest cannot use support mode, capability or not.
     */
    public function test_a_guest_cannot_use_support_mode(): void {
        $this->enable_support();
        $this->setGuestUser();

        $this->assertFalse(support_mode::can_use());
    }

    /**
     * A logged-out visitor cannot use support mode.
     *
     * Phase 1 is the authenticated case only: an unauthenticated streaming LLM
     * endpoint needs per-IP rate limiting and an anonymous identity, and neither
     * exists yet.
     */
    public function test_a_logged_out_visitor_cannot_use_support_mode(): void {
        $this->enable_support();
        $this->setUser(null);

        $this->assertFalse(support_mode::can_use());
    }

    /**
     * An ordinary authenticated learner can, by default.
     *
     * The capability ships with 'user' => CAP_ALLOW at system context, which is
     * the whole point: the learner who needs support is the one not enrolled in
     * anything that would grant them the per-course capability.
     */
    public function test_an_authenticated_learner_can_use_support_mode(): void {
        $this->enable_support();
        $this->setUser($this->user);

        $this->assertTrue(support_mode::can_use());
    }

    /**
     * The capability is a real, separate capability at system context.
     *
     * Pinned because the tempting shortcut -- adding 'user' => CAP_ALLOW to the
     * existing :use capability -- would grant it in every course by archetype
     * inheritance and defeat the per-course opt-out.
     */
    public function test_the_capability_is_separate_and_system_scoped(): void {
        $caps = get_all_capabilities();

        $this->assertArrayHasKey(support_mode::CAPABILITY, $caps, 'capability must be installed');
        $this->assertSame(
            CONTEXT_SYSTEM,
            (int) $caps[support_mode::CAPABILITY]['contextlevel'],
            'must be system-scoped, not course-scoped'
        );
        $this->assertNotSame(
            'local/ai_course_assistant:use',
            support_mode::CAPABILITY,
            'must not be the per-course capability'
        );
    }

    /**
     * Revoking the capability turns support mode off for that user.
     */
    public function test_revoking_the_capability_denies_support_mode(): void {
        $this->enable_support();
        $this->setUser($this->user);
        $this->assertTrue(support_mode::can_use(), 'precondition');

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            support_mode::CAPABILITY,
            CAP_PROHIBIT,
            $roleid,
            \context_system::instance()->id,
            true
        );
        role_assign($roleid, $this->user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse(support_mode::can_use());
    }

    // ------------------------------------------------------------- access gate

    /**
     * require_use() lets a support turn through without the course capability.
     */
    public function test_require_use_admits_a_support_turn(): void {
        $this->enable_support();
        $this->setUser($this->user);
        $context = \context_course::instance($this->supportcourse->id);

        $this->assertFalse(
            has_capability('local/ai_course_assistant:use', $context),
            'precondition: the learner is not enrolled in the support course'
        );

        support_mode::require_use((int) $this->supportcourse->id, $context);
        $this->assertTrue(support_mode::can_use_in((int) $this->supportcourse->id, $context));
    }

    /**
     * require_use() still refuses an ordinary course the learner cannot use.
     *
     * The support capability must not become a skeleton key for every course.
     */
    public function test_require_use_still_refuses_an_unrelated_course(): void {
        $this->enable_support();
        $this->setUser($this->user);
        $stranger = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($stranger->id);

        $this->assertFalse(support_mode::can_use_in((int) $stranger->id, $context));

        $this->expectException(\required_capability_exception::class);
        support_mode::require_use((int) $stranger->id, $context);
    }

    /**
     * With support mode off, require_use() is exactly require_capability().
     */
    public function test_require_use_is_unchanged_when_support_mode_is_off(): void {
        $this->setUser($this->user);
        $context = \context_course::instance($this->supportcourse->id);

        $this->expectException(\required_capability_exception::class);
        support_mode::require_use((int) $this->supportcourse->id, $context);
    }
}
