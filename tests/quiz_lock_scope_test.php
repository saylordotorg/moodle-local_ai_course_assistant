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
 * The quiz lock reaches only the course holding the attempt.
 *
 * Reported from dev against 7.2.4: the assistant refused in a course that
 * contains no quizzes at all. The cause was not a stale attempt state, which is
 * what the report assumed -- the query has always filtered on
 * state = 'inprogress' -- but scope. One genuinely open attempt in an unrelated
 * sandbox course disabled the assistant everywhere.
 *
 * Against production numbers that is not a corner case: ~88,900 attempts sit in
 * 'inprogress' on learn.saylor.org, 95% of them over a month old, because
 * nothing transitions an abandoned attempt out of that state. Site-wide scope
 * means each of those learners loses the assistant in every course, forever,
 * with nothing on screen to explain it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\quiz_lock
 */
final class quiz_lock_scope_test extends \advanced_testcase {

    /** @var \stdClass Course holding the quiz. */
    private $quizcourse;

    /** @var \stdClass A course with no quizzes at all. */
    private $othercourse;

    /** @var \stdClass The learner. */
    private $user;

    /**
     * Two courses and one learner enrolled in both.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $gen = $this->getDataGenerator();
        $this->quizcourse = $gen->create_course();
        $this->othercourse = $gen->create_course();
        $this->user = $gen->create_user();
        $gen->enrol_user($this->user->id, $this->quizcourse->id);
        $gen->enrol_user($this->user->id, $this->othercourse->id);
        set_config('quiz_lock_enabled', 1, 'local_ai_course_assistant');
        set_config('quiz_lock_scope', quiz_lock::SCOPE_COURSE, 'local_ai_course_assistant');
    }

    /**
     * Insert an attempt on a new quiz in the quiz course.
     *
     * @param string $state Attempt state to store.
     * @param int $agoseconds How long ago the attempt started.
     * @return void
     */
    private function make_attempt(string $state = 'inprogress', int $agoseconds = 60): void {
        global $DB;
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $this->quizcourse->id,
            'timelimit' => 0,
        ]);
        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $quiz->id,
            'userid' => $this->user->id,
            'attempt' => 1,
            'uniqueid' => $DB->count_records('quiz_attempts') + 1000,
            'layout' => '1,0',
            'state' => $state,
            'preview' => 0,
            'timestart' => time() - $agoseconds,
            'timemodified' => time(),
            'sumgrades' => null,
        ]);
    }

    /**
     * An open attempt locks its own course and nothing else.
     */
    public function test_an_open_attempt_locks_only_its_own_course(): void {
        $this->make_attempt('inprogress');

        $this->assertTrue(
            quiz_lock::is_locked_for((int) $this->user->id, (int) $this->quizcourse->id),
            'The course holding the open attempt must be locked.'
        );
        $this->assertFalse(
            quiz_lock::is_locked_for((int) $this->user->id, (int) $this->othercourse->id),
            'A course with no quizzes must not be locked by an attempt elsewhere.'
        );
    }

    /**
     * A submitted attempt lifts the lock immediately.
     *
     * The report asked specifically for this assertion. It already held -- the
     * query has always required state = 'inprogress', and there is no cache in
     * front of it, so the lock lifts on the learner's very next request -- but
     * nothing pinned it, which is why it could be suspected.
     */
    public function test_a_finished_attempt_does_not_lock(): void {
        $this->make_attempt('finished');

        $this->assertFalse(
            quiz_lock::is_locked_for((int) $this->user->id, (int) $this->quizcourse->id),
            'A submitted attempt must not lock the course it was taken in.'
        );
        $this->assertFalse(
            quiz_lock::is_locked_for((int) $this->user->id, 0),
            'A submitted attempt must not lock site-wide either.'
        );
    }

    /**
     * An abandoned attempt behaves like a finished one.
     */
    public function test_an_abandoned_attempt_does_not_lock(): void {
        $this->make_attempt('abandoned');

        $this->assertFalse(
            quiz_lock::is_locked_for((int) $this->user->id, (int) $this->quizcourse->id)
        );
    }

    /**
     * Submitting mid-session lifts the lock without waiting for the window.
     */
    public function test_submitting_lifts_the_lock_on_the_next_request(): void {
        global $DB;
        $this->make_attempt('inprogress');
        $this->assertTrue(
            quiz_lock::is_locked_for((int) $this->user->id, (int) $this->quizcourse->id)
        );

        // What core does on submission.
        $DB->set_field('quiz_attempts', 'state', 'finished', ['userid' => $this->user->id]);

        $this->assertFalse(
            quiz_lock::is_locked_for((int) $this->user->id, (int) $this->quizcourse->id),
            'The lock must lift on submission, not when the freshness window expires.'
        );
    }

    /**
     * Site scope is still available for a site that wants it.
     */
    public function test_site_scope_still_locks_everywhere(): void {
        set_config('quiz_lock_scope', quiz_lock::SCOPE_SITE, 'local_ai_course_assistant');
        $this->make_attempt('inprogress');

        $this->assertTrue(
            quiz_lock::is_locked_for((int) $this->user->id, (int) $this->othercourse->id),
            'Under site scope an attempt anywhere locks everywhere.'
        );
    }

    /**
     * With no course context the check stays conservative.
     *
     * A surface that cannot say which course it belongs to gets the site-wide
     * test rather than no test, so scoping cannot become a way around the lock.
     */
    public function test_no_course_context_falls_back_to_site_wide(): void {
        $this->make_attempt('inprogress');

        $this->assertTrue(
            quiz_lock::is_locked_for((int) $this->user->id, 0),
            'Course id 0 must not silently disable the lock.'
        );
    }

    /**
     * The row identifies the course, so a caller can say which quiz.
     */
    public function test_active_attempt_reports_its_course(): void {
        $this->make_attempt('inprogress');

        $row = quiz_lock::active_attempt((int) $this->user->id, (int) $this->quizcourse->id);
        $this->assertNotNull($row);
        $this->assertSame((int) $this->quizcourse->id, (int) $row->courseid);
    }
}
