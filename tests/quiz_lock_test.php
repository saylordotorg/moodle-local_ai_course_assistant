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
 * v7.1.0 quiz lock: no AI while a learner is sitting a Moodle quiz.
 *
 * The lock has two failure modes and they pull in opposite directions. Letting a
 * learner through during an exam is the one everybody thinks of. Locking a
 * learner who is NOT sitting an exam is the one that would actually bite: on
 * learn.saylor.org ~88,900 attempts sit in state 'inprogress' with 95% of them
 * over a month old, because self-paced courses mostly set no time limit and
 * nothing transitions an abandoned attempt. A naive check would have locked the
 * assistant permanently for 71,381 learners. Both directions are tested.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\quiz_lock
 */
final class quiz_lock_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->user->id, $this->course->id);
    }

    /**
     * Create a quiz and an attempt row in a given state, started $agoseconds ago.
     *
     * @param int $timelimit  Quiz time limit in seconds (0 = open ended).
     * @param int $agoseconds How long ago the attempt started.
     * @param string $state   Attempt state.
     * @param int $preview    1 for a teacher preview attempt.
     * @return \stdClass The quiz module record (has ->cmid).
     */
    private function make_attempt(
        int $timelimit,
        int $agoseconds,
        string $state = 'inprogress',
        int $preview = 0
    ): \stdClass {
        global $DB;
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $this->course->id,
            'timelimit' => $timelimit,
        ]);
        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $quiz->id,
            'userid' => $this->user->id,
            'attempt' => 1,
            'uniqueid' => $DB->count_records('quiz_attempts') + 1000,
            'layout' => '1,0',
            'state' => $state,
            'preview' => $preview,
            'timestart' => time() - $agoseconds,
            'timefinish' => 0,
            'timemodified' => time(),
            'sumgrades' => null,
        ]);
        return $quiz;
    }

    public function test_no_attempt_means_no_lock(): void {
        $this->assertFalse(quiz_lock::is_locked_for((int) $this->user->id));
    }

    public function test_a_live_attempt_locks(): void {
        $this->make_attempt(3600, 120);
        $this->assertTrue(quiz_lock::is_locked_for((int) $this->user->id),
            'a learner two minutes into a timed exam must be locked');
    }

    public function test_an_open_ended_attempt_locks_inside_the_window(): void {
        $this->make_attempt(0, 600);
        $this->assertTrue(quiz_lock::is_locked_for((int) $this->user->id),
            'no time limit, started ten minutes ago -- still sitting it');
    }

    /**
     * THE important one. An abandoned attempt on a self-paced course stays
     * 'inprogress' forever; it must not lock the learner out of the assistant.
     */
    public function test_a_stale_open_ended_attempt_does_not_lock(): void {
        $this->make_attempt(0, 40 * 86400);
        $this->assertFalse(quiz_lock::is_locked_for((int) $this->user->id),
            'an attempt abandoned 40 days ago is not someone sitting an exam');
    }

    public function test_an_attempt_past_its_time_limit_does_not_lock(): void {
        // 30-minute quiz, started three hours ago: the attempt is dead even
        // though nothing has transitioned its state.
        $this->make_attempt(1800, 10800);
        $this->assertFalse(quiz_lock::is_locked_for((int) $this->user->id),
            'past its own time limit plus grace, the attempt stops counting');
    }

    /**
     * A teacher previewing a quiz writes a real attempt row (preview=1,
     * state='inprogress') that survives navigating away. Without a preview
     * filter, that teacher is locked out of the assistant in EVERY course for
     * the whole window, with nothing on screen explaining why. Core's
     * quiz_get_user_attempts() filters preview rows for the same reason.
     *
     * The first version of this fixture omitted the column entirely, so every
     * attempt defaulted to preview=0 and the gap was invisible to the suite.
     */
    public function test_a_teacher_preview_does_not_lock(): void {
        $this->make_attempt(0, 120, 'inprogress', 1);
        $this->assertFalse(quiz_lock::is_locked_for((int) $this->user->id),
            'a preview attempt is not a learner sitting an exam');
    }

    public function test_a_real_attempt_still_locks_alongside_a_preview(): void {
        $this->make_attempt(0, 120, 'inprogress', 1);
        $this->make_attempt(0, 120, 'inprogress', 0);
        $this->assertTrue(quiz_lock::is_locked_for((int) $this->user->id),
            'the preview filter must not swallow genuine attempts');
    }

    /**
     * The blocked message carries a [[tutorshort]] branding token. Nothing
     * resolves brand tokens on the way out of an exception or an SSE token, so
     * the call sites must use branding::str; a bare get_string ships the literal
     * token to the learner in all 46 languages.
     */
    public function test_the_blocked_message_has_no_unresolved_branding_token(): void {
        $resolved = \local_ai_course_assistant\branding::str('quizlock:blocked');
        $this->assertStringNotContainsString('[[', $resolved,
            'brand tokens must be resolved before the learner sees this');
        $this->assertNotEmpty($resolved);
    }

    public function test_a_finished_attempt_does_not_lock(): void {
        $this->make_attempt(3600, 120, 'finished');
        $this->assertFalse(quiz_lock::is_locked_for((int) $this->user->id));
    }

    public function test_abandoned_state_does_not_lock(): void {
        $this->make_attempt(3600, 120, 'abandoned');
        $this->assertFalse(quiz_lock::is_locked_for((int) $this->user->id));
    }

    public function test_a_teacher_can_exempt_one_quiz(): void {
        $quiz = $this->make_attempt(3600, 120);
        $this->assertTrue(quiz_lock::is_locked_for((int) $this->user->id));

        quiz_config_manager::save((int) $quiz->cmid, (int) $this->course->id, 'full');
        $this->assertFalse(quiz_lock::is_locked_for((int) $this->user->id),
            'setting a quiz to full help must opt it out of the lock');
    }

    public function test_coach_level_still_locks_the_provider_path(): void {
        // 'coach' changes how the assistant answers, not whether it is reachable
        // during an attempt. Only an explicit 'full' exempts.
        $quiz = $this->make_attempt(3600, 120);
        quiz_config_manager::save((int) $quiz->cmid, (int) $this->course->id, 'coach');
        $this->assertTrue(quiz_lock::is_locked_for((int) $this->user->id));
    }

    public function test_the_lock_can_be_switched_off_site_wide(): void {
        $this->make_attempt(3600, 120);
        set_config('quiz_lock_enabled', 0, 'local_ai_course_assistant');
        $this->assertFalse(quiz_lock::is_locked_for((int) $this->user->id));
    }

    public function test_it_defaults_to_on(): void {
        // No stored value at all: an integrity control that ships off protects
        // nobody, and this was requested as the default for every quiz.
        unset_config('quiz_lock_enabled', 'local_ai_course_assistant');
        $this->assertTrue(quiz_lock::is_enabled());
    }

    public function test_the_window_is_configurable(): void {
        $this->make_attempt(0, 7200);                       // two hours ago
        $this->assertTrue(quiz_lock::is_locked_for((int) $this->user->id),
            'inside the 180-minute default');

        set_config('quiz_lock_window_minutes', 60, 'local_ai_course_assistant');
        $this->assertFalse(quiz_lock::is_locked_for((int) $this->user->id),
            'outside a 60-minute window');
    }

    public function test_one_learners_attempt_does_not_lock_another(): void {
        $this->make_attempt(3600, 120);
        $other = $this->getDataGenerator()->create_user();
        $this->assertTrue(quiz_lock::is_locked_for((int) $this->user->id));
        $this->assertFalse(quiz_lock::is_locked_for((int) $other->id));
    }

    /**
     * Any Moodle quiz, not just graded ones -- the v7.1.0 policy change.
     */
    public function test_default_level_is_hidden_for_an_ungraded_quiz(): void {
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $this->course->id,
            'grade' => 0,
        ]);
        $this->assertEquals('hidden',
            quiz_config_manager::get_assistance_level((int) $quiz->cmid),
            'an ungraded practice quiz still locks by default');
    }

    public function test_a_non_quiz_module_is_never_locked(): void {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->assertEquals('full',
            quiz_config_manager::get_assistance_level((int) $page->cmid),
            'the default must not lock pages, books or anything else');
    }
}
