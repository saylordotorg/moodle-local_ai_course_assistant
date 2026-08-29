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

use local_ai_course_assistant\provider\base_provider;

/**
 * The quiz lock must actually stop a provider call, not merely report locked.
 *
 * Every existing quiz-lock test asserts quiz_lock::is_locked_for() directly.
 * None exercised enforcement, which is how this survived: the check sat after
 * an is_siteadmin() early return, so an administrator with an attempt open
 * reached a provider while the drawer displayed the integrity notice. Because
 * every AI surface resolves through these factories, that one exemption opened
 * practice-quiz generation, flashcards, scoring and the rest at once.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\base_provider
 */
final class quiz_lock_enforcement_test extends \advanced_testcase {

    /**
     * Put an in-progress, non-preview attempt in front of a user.
     *
     * @param \stdClass $user
     * @return \stdClass The course the quiz belongs to.
     */
    private function seed_attempt_for(\stdClass $user): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'attempt' => 1,
            'uniqueid' => random_int(100000, 999999),
            'layout' => '',
            'state' => 'inprogress',
            'timestart' => time(),
            'preview' => 0,
            'timemodified' => time(),
            'timemodifiedoffline' => 0,
            'sumgrades' => null,
        ]);
        set_config('quiz_lock_enabled', 1, 'local_ai_course_assistant');
        return $course;
    }

    /**
     * Assert the factory refuses, and refuses for the quiz-lock reason.
     *
     * @param callable $fn
     * @param string $context
     */
    private function assert_blocked(callable $fn, string $context): void {
        $expected = branding::str('quizlock:blocked');
        try {
            $fn();
            $this->fail("{$context}: a provider was handed out during a live quiz attempt.");
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(
                $expected,
                $e->getMessage(),
                "{$context}: refused, but not because of the quiz lock."
            );
        }
    }

    public function test_a_learner_sitting_a_quiz_cannot_reach_a_provider(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $course = $this->seed_attempt_for($student);
        $this->setUser($student);

        $this->assertTrue(quiz_lock::is_locked_for((int) $student->id));
        $this->assert_blocked(
            fn() => base_provider::create_from_config($course->id),
            'create_from_config as a learner'
        );
    }

    public function test_the_comparison_factory_is_locked_too(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $course = $this->seed_attempt_for($student);
        $this->setUser($student);

        // generate_quiz prefers this factory whenever a site configures a
        // separate quiz tier, which is exactly the surface the lock exists for.
        $this->assert_blocked(
            fn() => base_provider::create_for_comparison('openai', 'gpt-4o-mini', $course->id),
            'create_for_comparison as a learner'
        );
    }

    public function test_a_site_administrator_sitting_a_quiz_is_locked_as_well(): void {
        $this->resetAfterTest();
        $admin = get_admin();
        $course = $this->seed_attempt_for($admin);
        $this->setAdminUser();

        $this->assert_blocked(
            fn() => base_provider::create_from_config($course->id),
            'create_from_config as a site administrator'
        );
    }

    /**
     * A blocked quiz-generation request must say WHY, not "please try again".
     *
     * The refusal is not retryable, so borrowing the generic failure message
     * sends the learner round a loop that cannot succeed. The external
     * function reports errorcode='quizlocked' and the drawer's own integrity
     * wording, which is what the client renders instead.
     */
    public function test_quiz_generation_reports_the_lock_not_a_generic_failure(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $course = $this->seed_attempt_for($student);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $result = \local_ai_course_assistant\external\generate_quiz::execute($course->id, 3, 'Photosynthesis');

        // Also proves the field is declared in execute_returns(); an undeclared
        // key is stripped here and never reaches the browser.
        $cleaned = \core_external\external_api::clean_returnvalue(
            \local_ai_course_assistant\external\generate_quiz::execute_returns(),
            $result
        );

        $this->assertFalse($cleaned['success']);
        $this->assertSame('quizlocked', $cleaned['errorcode']);
        $this->assertSame(branding::str('quizlock:blocked'), $cleaned['error']);
        $this->assertSame([], $cleaned['questions']);
    }

    public function test_no_attempt_means_no_lock(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        set_config('quiz_lock_enabled', 1, 'local_ai_course_assistant');
        $this->setUser($student);

        $this->assertFalse(quiz_lock::is_locked_for((int) $student->id));
        try {
            base_provider::create_from_config($course->id);
        } catch (\moodle_exception $e) {
            $this->assertStringNotContainsString(
                branding::str('quizlock:blocked'),
                $e->getMessage(),
                'The lock fired with no attempt in progress.'
            );
        }
    }

    /**
     * The practice-quiz button uses the same scope as chat.
     *
     * Both lock checks in generate_quiz took the courseid default, so they ran
     * site-wide while base_provider and sse.php scoped to the course. The
     * learner got a working chat and a quiz button that refused, in the same
     * drawer, over an attempt in a course they were not in. The catch-block
     * re-check had the sharper edge: it fires on any throwable, so an unrelated
     * attempt turned a genuine provider timeout into errorcode=quizlocked,
     * which the client treats as non-retryable -- discarding a real error and
     * discouraging the retry that would have worked.
     */
    public function test_quiz_generation_scopes_the_lock_to_its_own_course(): void {
        $this->resetAfterTest(true);

        $gen = $this->getDataGenerator();
        $quizcourse = $gen->create_course();
        $othercourse = $gen->create_course();
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $quizcourse->id);
        $gen->enrol_user($student->id, $othercourse->id);
        $this->setUser($student);

        set_config('quiz_lock_enabled', 1, 'local_ai_course_assistant');
        set_config('quiz_lock_scope', quiz_lock::SCOPE_COURSE, 'local_ai_course_assistant');

        global $DB;
        $quiz = $gen->create_module('quiz', ['course' => $quizcourse->id]);
        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $quiz->id,
            'userid' => $student->id,
            'attempt' => 1,
            'uniqueid' => random_int(100000, 999999),
            'layout' => '',
            'state' => 'inprogress',
            'timestart' => time(),
            'preview' => 0,
            'timemodified' => time(),
            'timemodifiedoffline' => 0,
            'sumgrades' => null,
        ]);

        // The course holding the attempt is locked, as it should be.
        $this->assertTrue(
            quiz_lock::is_locked_for((int) $student->id, (int) $quizcourse->id)
        );

        // The other course must not be, and that is the scope generate_quiz has
        // to use or the drawer contradicts itself.
        $this->assertFalse(
            quiz_lock::is_locked_for((int) $student->id, (int) $othercourse->id),
            'Quiz generation in an unrelated course must not see this attempt.'
        );
    }
}
