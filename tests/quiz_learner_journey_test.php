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

use local_ai_course_assistant\external\generate_quiz;
use local_ai_course_assistant\external\record_quiz_open;
use local_ai_course_assistant\provider\stub_provider;

/**
 * The practice-quiz flow, driven end to end as the learner it was written for.
 *
 * WHY THIS FILE EXISTS. Four defects reached a release on one day. Three were
 * capability failures: a learner-facing panel read its data through an API
 * needing a capability no student holds, so the feature was invisible to
 * learners and worked perfectly for everybody who checked it. Every test
 * passed, because the tests asserted refusals ("a learner cannot read someone
 * else's data") or shapes ("the declared structure matches") and not one of
 * them drove the feature with real data AS a student.
 *
 * The quiz flow already has ~50 tests and they share that blind spot: every
 * one of them would pass unchanged if it ran as a teacher or an administrator.
 * So each test here asserts FIRST that the learner holds none of the staff
 * capabilities that would make the run meaningless -- analytics, manage,
 * viewhiddenactivities, grade:viewall, quiz:preview -- and holds
 * local/ai_course_assistant:use, so a red test reads as "the learner was
 * refused" rather than "the learner was never entitled".
 *
 * Beyond that it pins five things the existing quiz tests genuinely do not
 * reach, because they assert the collaborators directly instead of entering
 * the external function: the two course-scoped lock call sites inside
 * generate_quiz (an attempt in an unrelated course must not kill this course's
 * quiz button, and must not turn a retryable provider error into a
 * non-retryable refusal), the surface string the refusal is audited under, the
 * contents of the assembled guided prompt (this learner's chat, this course's
 * grades, never a foreign module's content), the null-vs-zero shape of the
 * spend row generate_quiz itself writes, and the per-learner-ness of the
 * quiz_open rate bucket.
 *
 * No network and no provider: the flow runs on the plugin's own stub provider
 * seam, so this is CI-safe and cannot be disabled by an expiring key.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\generate_quiz
 * @covers     \local_ai_course_assistant\external\record_quiz_open
 */
final class quiz_learner_journey_test extends \advanced_testcase {
    /**
     * Route every provider call to the in-process stub and clear its call log.
     *
     * @return void
     */
    private function use_the_stub_provider(): void {
        stub_provider::reset();
        set_config('provider', 'stub', 'local_ai_course_assistant');
        set_config('apikey', 'stub-key', 'local_ai_course_assistant');
    }

    /**
     * Put a live, non-preview quiz attempt in front of a learner.
     *
     * Raw insert because mod_quiz ships no generator for attempts; the column
     * list is the one tests/quiz_lock_enforcement_test.php uses, with every
     * NOT NULL column given a value so the pgsql CI matrix behaves like MySQL.
     *
     * @param \stdClass $user Learner sitting the quiz.
     * @param \stdClass $course Course holding the quiz.
     * @return void
     */
    private function seed_live_attempt(\stdClass $user, \stdClass $course): void {
        global $DB;

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
        // Set explicitly even though course scope is the default, so this file
        // still means what it says if the default is ever flipped.
        set_config('quiz_lock_scope', quiz_lock::SCOPE_COURSE, 'local_ai_course_assistant');
    }

    /**
     * Give a learner a final grade on a named activity in a course.
     *
     * build_grade_summary() skips items whose finalgrade is null, so an
     * ungraded item would make the scoping tests pass for the wrong reason.
     *
     * @param \stdClass $user Learner to grade.
     * @param \stdClass $course Course holding the activity.
     * @param string $name Activity name, used as the grade item name.
     * @param float $grade Final grade to award.
     * @return void
     */
    private function seed_graded_activity(\stdClass $user, \stdClass $course, string $name, float $grade): void {
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => $name,
        ]);
        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assign->id,
            'courseid' => $course->id,
        ]);
        $this->assertNotFalse($item, 'The assign did not produce a grade item to grade.');
        $item->update_final_grade($user->id, $grade);
    }

    /**
     * The learner running this test must not be able to see everybody's work.
     *
     * Without these five assertions the whole file would pass while running as
     * somebody who can read hidden activities and every learner's grades,
     * which is exactly how three defects hid on release day.
     *
     * @param int $courseid Course whose context the capabilities are read in.
     * @return void
     */
    private function assert_holds_no_staff_capability(int $courseid): void {
        $context = \context_course::instance($courseid);

        $this->assertFalse(
            has_capability('local/ai_course_assistant:viewanalytics', $context),
            'A learner must not hold the analytics capability, or this test proves nothing.'
        );
        $this->assertFalse(
            has_capability('local/ai_course_assistant:manage', $context),
            'A learner must not hold the plugin manage capability, or this test proves nothing.'
        );
        $this->assertFalse(
            has_capability('moodle/course:viewhiddenactivities', $context),
            'A learner must not be able to read hidden activities, or the foreign-module '
                . 'guarantee is being checked as somebody allowed to read them.'
        );
        $this->assertFalse(
            has_capability('moodle/grade:viewall', $context),
            'A learner must not be able to read everybody\'s grades, or the grade-scoping '
                . 'guarantee is being checked as somebody who can see them all anyway.'
        );
        $this->assertFalse(
            has_capability('mod/quiz:preview', $context),
            'A learner must not be a previewer: the lock ignores preview attempts, so a '
                . 'previewer would never meet the lock this file is about.'
        );
    }

    /**
     * The person driving this test is an entitled learner, and only that.
     *
     * @param int $courseid Course whose context the capabilities are read in.
     * @return void
     */
    private function assert_this_is_an_entitled_learner(int $courseid): void {
        $this->assert_holds_no_staff_capability($courseid);
        $this->assertTrue(
            has_capability('local/ai_course_assistant:use', \context_course::instance($courseid)),
            'The learner must hold the assistant capability, or a red test below would only '
                . 'mean they were never entitled to the feature in the first place.'
        );
    }

    /**
     * Prohibit the assistant capability for one user in one course.
     *
     * A dedicated role rather than an edit to 'student', so the second learner
     * in the same course keeps working and the two cases stay independent.
     *
     * @param \stdClass $user User to prohibit.
     * @param \stdClass $course Course to prohibit them in.
     * @return void
     */
    private function prohibit_the_assistant_for(\stdClass $user, \stdClass $course): void {
        $context = \context_course::instance($course->id);
        $roleid = $this->getDataGenerator()->create_role();
        role_assign($roleid, $user->id, $context);
        assign_capability('local/ai_course_assistant:use', CAP_PROHIBIT, $roleid, $context, true);
    }

    /**
     * A quiz JSON payload the stub can return, shaped like the real thing.
     *
     * @param array $questions Question rows to encode.
     * @param string $topic Topic name to encode.
     * @return string JSON body.
     */
    private function quiz_payload(array $questions, string $topic = 'Stub topic'): string {
        return json_encode(['topic' => $topic, 'questions' => $questions]);
    }

    /**
     * Sitting a quiz in one course must not close the quiz button in another.
     *
     * The learner is studying course A with a half-finished attempt forgotten
     * in course B. If this breaks they press Quiz Me in course A and are told
     * to finish an exam they are not sitting, with no way to tell which course
     * it means -- the production incident v7.2.5 exists to prevent.
     *
     * @return void
     */
    public function test_an_attempt_in_another_course_does_not_block_this_courses_quiz(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $coursea->id, 'student');
        $this->getDataGenerator()->enrol_user($learner->id, $courseb->id, 'student');
        $this->seed_live_attempt($learner, $courseb);
        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $coursea->id);

        // The attempt is real: it does lock the course it belongs to.
        $this->assertTrue(quiz_lock::is_locked_for((int) $learner->id, (int) $courseb->id));

        $result = generate_quiz::execute((int) $coursea->id);

        $this->assertTrue($result['success'], 'The learner was refused a quiz in a course they are not sitting.');
        $this->assertSame('', $result['errorcode']);
        $this->assertCount(3, $result['questions']);
    }

    /**
     * A provider outage stays a retryable outage, whatever else the learner has open.
     *
     * If this breaks, a learner with an abandoned attempt anywhere is shown the
     * integrity notice whenever the model is down -- a message the client
     * treats as final, so it discards the real error and never retries.
     *
     * @return void
     */
    public function test_a_provider_failure_is_not_reported_as_the_integrity_lock(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $coursea->id, 'student');
        $this->getDataGenerator()->enrol_user($learner->id, $courseb->id, 'student');
        $this->seed_live_attempt($learner, $courseb);
        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $coursea->id);

        // phpcs:ignore moodle.NamingConventions.ValidVariableName.VariableNameUnderscore
        stub_provider::$throw_next = new \moodle_exception('stub-upstream-timeout');

        $result = generate_quiz::execute((int) $coursea->id);

        $this->assertFalse($result['success']);
        $this->assertSame(
            '',
            $result['errorcode'],
            'A provider timeout must stay retryable; "quizlocked" tells the client to give up.'
        );
        $this->assertStringContainsString('stub-upstream-timeout', $result['error']);
    }

    /**
     * A refused quiz is auditable as a refused quiz.
     *
     * An integrity review asks "who was turned away, in which course, from
     * what". If the surface is wrong the refusal is filed under chat and the
     * quiz button's refusals cannot be counted at all.
     *
     * @return void
     */
    public function test_a_refused_quiz_is_audited_as_the_quiz_surface(): void {
        global $DB;

        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id, 'student');
        $this->seed_live_attempt($learner, $course);
        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $result = generate_quiz::execute((int) $course->id);

        $this->assertSame('quizlocked', $result['errorcode']);
        $this->assertSame([], stub_provider::$calls, 'A refused quiz must not reach the provider.');

        $rows = $DB->get_records('local_ai_course_assistant_audit', ['action' => 'quiz_lock_refused']);
        $this->assertCount(1, $rows, 'One refusal must produce exactly one audit row.');
        $row = reset($rows);
        $this->assertSame((int) $learner->id, (int) $row->userid);
        $this->assertSame((int) $course->id, (int) $row->courseid);
        $details = json_decode($row->details, true);
        $this->assertSame('quiz', $details['surface'], 'The quiz button filed its refusal under another surface.');
    }

    /**
     * A learner the site has switched the assistant off for gets nothing at all.
     *
     * Not an empty quiz, not a silent success: a refusal. The learner is
     * enrolled, so this isolates the capability gate from the login gate, and
     * proves the model is never called on their behalf.
     *
     * @return void
     */
    public function test_a_learner_without_the_use_capability_is_refused_the_quiz(): void {
        global $DB;

        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id, 'student');
        $this->prohibit_the_assistant_for($learner, $course);
        $this->setUser($learner);

        $this->assert_holds_no_staff_capability((int) $course->id);
        $this->assertFalse(
            has_capability('local/ai_course_assistant:use', \context_course::instance($course->id)),
            'The prohibition did not take effect, so this test would prove nothing.'
        );

        try {
            generate_quiz::execute((int) $course->id);
            $this->fail('A learner without the assistant capability was served a quiz.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(
                get_capability_string('local/ai_course_assistant:use'),
                $e->a,
                'Refused, but for some capability other than the assistant one.'
            );
        }

        $this->assertSame([], stub_provider::$calls, 'A refused learner must not be billed for a provider call.');
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_msgs', ['courseid' => $course->id]));
    }

    /**
     * The telemetry endpoint is shut to the same learner the quiz is shut to.
     *
     * A write the read gate would refuse must not be reachable, or the panel's
     * usage numbers include people the panel never opened for.
     *
     * @return void
     */
    public function test_a_learner_without_the_use_capability_cannot_record_a_panel_open(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id, 'student');
        $this->prohibit_the_assistant_for($learner, $course);
        $this->setUser($learner);

        $this->assert_holds_no_staff_capability((int) $course->id);
        $this->assertFalse(has_capability('local/ai_course_assistant:use', \context_course::instance($course->id)));

        try {
            record_quiz_open::execute((int) $course->id, 0);
            $this->fail('A learner without the assistant capability recorded a panel open.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(
                get_capability_string('local/ai_course_assistant:use'),
                $e->a,
                'Refused, but for some capability other than the assistant one.'
            );
        }

        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_msgs', [
            'courseid' => $course->id,
            'interaction_type' => 'quiz_open',
        ]));
    }

    /**
     * The quiz a learner is offered is built from their own questions only.
     *
     * Two students share a course. If this breaks, one learner's quiz is aimed
     * at what the other has been struggling with, and the other learner's
     * questions have been handed to a third party and to the model.
     *
     * @return void
     */
    public function test_a_guided_quiz_is_built_from_this_learners_chat_only(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'student');

        $otherconv = conversation_manager::get_or_create_conversation((int) $other->id, (int) $course->id);
        conversation_manager::add_message(
            (int) $otherconv->id,
            (int) $other->id,
            (int) $course->id,
            'user',
            'CANARY-OTHER-LEARNER what is amortisation?'
        );
        $ownconv = conversation_manager::get_or_create_conversation((int) $learner->id, (int) $course->id);
        conversation_manager::add_message(
            (int) $ownconv->id,
            (int) $learner->id,
            (int) $course->id,
            'user',
            'CANARY-OWN what is a balance sheet?'
        );

        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $result = generate_quiz::execute((int) $course->id);
        $this->assertTrue($result['success']);

        $this->assertCount(1, stub_provider::$calls);
        $prompt = stub_provider::$calls[0]['systemprompt'];
        $this->assertStringContainsString(
            'CANARY-OWN',
            $prompt,
            'The learner\'s own questions must reach their guided quiz, or the feature is dead.'
        );
        $this->assertStringNotContainsString(
            'CANARY-OTHER-LEARNER',
            $prompt,
            'Another learner\'s questions were put in this learner\'s quiz prompt.'
        );
    }

    /**
     * The quiz a learner is offered is built from this course's grades only.
     *
     * If this breaks, the quiz for a finance course is aimed at how the learner
     * did in an unrelated course, and the names of activities in courses the
     * teacher of this course never saw are sent to the model.
     *
     * @return void
     */
    public function test_a_guided_quiz_is_built_from_this_courses_grades_only(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $coursea->id, 'student');
        $this->getDataGenerator()->enrol_user($learner->id, $courseb->id, 'student');
        $this->seed_graded_activity($learner, $coursea, 'OWNCOURSE-ASSIGN', 88);
        $this->seed_graded_activity($learner, $courseb, 'LEAKCANARY-ASSIGN', 77);

        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $coursea->id);

        $result = generate_quiz::execute((int) $coursea->id);
        $this->assertTrue($result['success']);

        $prompt = stub_provider::$calls[0]['systemprompt'];
        // Positive control: without it, a grade summary that failed outright
        // would satisfy the negative assertion below and prove nothing.
        $this->assertStringContainsString(
            'OWNCOURSE-ASSIGN',
            $prompt,
            'This course\'s own grades must reach the guided prompt.'
        );
        $this->assertStringNotContainsString(
            'LEAKCANARY-ASSIGN',
            $prompt,
            'A grade from an unrelated course was used to build this course\'s quiz.'
        );
    }

    /**
     * A page id from another course cannot smuggle that page into the quiz.
     *
     * The browser supplies the cmid. If this breaks, anyone who can edit a URL
     * gets a quiz generated from material in a course the request was never
     * authorised for, and that material is sent to the model.
     *
     * @return void
     */
    public function test_a_module_from_another_course_never_reaches_the_quiz_prompt(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $coursea->id, 'student');
        $this->getDataGenerator()->enrol_user($learner->id, $courseb->id, 'student');

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $courseb->id,
            'content' => '<p>FOREIGN-SECRET the unreleased marking scheme for the final assessment, '
                . 'with worked answers to every question.</p>',
            'contentformat' => FORMAT_HTML,
        ]);

        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $coursea->id);

        $result = generate_quiz::execute((int) $coursea->id, 3, '', (int) $page->cmid);

        $this->assertTrue($result['success'], 'A foreign cmid must fall back to course topics, not fail.');
        $prompt = stub_provider::$calls[0]['systemprompt'];
        $this->assertStringNotContainsString(
            'FOREIGN-SECRET',
            $prompt,
            'Content from a course this request was not authorised for reached the quiz prompt.'
        );
        $this->assertStringContainsString(
            'Course topics:',
            $prompt,
            'The fallback branch must be the one that ran, or this proves nothing about the guard.'
        );
    }

    /**
     * An objective the model invented is dropped; a real one is kept.
     *
     * The learner sees their progress move on objectives they actually
     * practised. A made-up id is written into the mastery table as an attempt
     * against a row that means nothing, so their Progress tab fills with
     * answers to questions nobody set.
     *
     * @return void
     */
    public function test_an_invented_objective_is_dropped_and_a_real_one_survives(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id, 'student');
        objective_manager::set_enabled_for_course((int) $course->id, true);
        $realid = objective_manager::create((int) $course->id, 'Read a balance sheet');

        stub_provider::program_response('quiz', $this->quiz_payload([
            [
                'id' => 1,
                'question' => 'Which side of the balance sheet carries the liabilities?',
                'choices' => ['A) Left', 'B) Right', 'C) Neither', 'D) Both'],
                'correct' => 'B',
                'explanation' => 'Liabilities sit on the right.',
                'objectiveid' => $realid,
            ],
            [
                'id' => 2,
                'question' => 'What does a trial balance prove?',
                'choices' => ['A) Accuracy', 'B) Arithmetic', 'C) Solvency', 'D) Liquidity'],
                'correct' => 'B',
                'explanation' => 'It proves the arithmetic only.',
                'objectiveid' => 999999,
            ],
        ]));

        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $result = generate_quiz::execute((int) $course->id);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['questions']);
        $this->assertSame($realid, $result['questions'][0]['objectiveid'], 'A real objective tag was thrown away.');
        $this->assertSame(
            0,
            $result['questions'][1]['objectiveid'],
            'An objective id this course does not have was carried through to mastery recording.'
        );
    }

    /**
     * What nobody measured is stored as null, never as a zero.
     *
     * The learner is on the course home page and the provider reported no
     * cache and no thinking tokens. A zero there is a measurement nobody made,
     * and it prices as "we checked, it was nothing" in every cost report the
     * institution sees.
     *
     * @return void
     */
    public function test_the_spend_row_a_quiz_writes_keeps_nulls_where_nothing_was_measured(): void {
        global $DB;

        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id, 'student');
        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $result = generate_quiz::execute((int) $course->id);
        $this->assertTrue($result['success']);

        $rows = $DB->get_records('local_ai_course_assistant_msgs', [
            'userid' => $learner->id,
            'interaction_type' => 'quiz',
        ]);
        $this->assertCount(1, $rows, 'One quiz generation must write exactly one spend row.');
        $row = reset($rows);

        $this->assertSame((int) $course->id, (int) $row->courseid);
        $this->assertSame('system', $row->role, 'A spend row must stay out of the learner\'s history.');
        $this->assertSame(100, (int) $row->prompt_tokens);
        $this->assertSame(50, (int) $row->completion_tokens);
        $this->assertNull($row->cached_tokens, 'The provider reported no cache; a 0 claims it reported one.');
        $this->assertNull($row->reasoning_tokens, 'The provider reported no thinking tokens; a 0 claims it did.');
        $this->assertNull($row->cmid, 'The learner was on the course home page, not an activity.');
    }

    /**
     * One learner pressing Quiz Me all afternoon cannot lock out everybody else.
     *
     * The ceiling is per learner. Shared, the second learner in the course
     * presses the panel open for the first time and is silently dropped.
     *
     * @return void
     */
    public function test_the_quiz_open_ceiling_is_per_learner(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $heavy = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($heavy->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($second->id, $course->id, 'student');

        $this->setUser($heavy);
        $this->assert_this_is_an_entitled_learner((int) $course->id);
        for ($i = 0; $i < 65; $i++) {
            record_quiz_open::execute((int) $course->id, 0);
        }

        $this->setUser($second);
        $this->assert_this_is_an_entitled_learner((int) $course->id);
        $result = record_quiz_open::execute((int) $course->id, 0);

        $this->assertTrue(
            $result['recorded'],
            'The second learner\'s first press was refused because of somebody else\'s presses.'
        );
        $this->assertSame(1, $DB->count_records('local_ai_course_assistant_msgs', [
            'userid' => $second->id,
            'interaction_type' => 'quiz_open',
        ]));
    }

    /**
     * Everything the browser is handed survives the declared return structure.
     *
     * These are ajax endpoints and the throw lands after the provider has been
     * billed, so a shape that only holds for the canned ASCII payload means the
     * first learner with a real, objective-tagged, accented quiz pays for it and
     * sees an error. Checked on all three things the flow can hand back: a
     * populated quiz, the integrity refusal, and the panel-open acknowledgement.
     *
     * @return void
     */
    public function test_what_the_browser_receives_survives_the_declared_structure(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id, 'student');
        objective_manager::set_enabled_for_course((int) $course->id, true);
        $realid = objective_manager::create((int) $course->id, 'Bilanzen lesen');

        $topic = 'Bilanzprüfung und Überblick';
        $explanation = 'It\'s the lender\'s claim & the firm\'s obligation.';
        stub_provider::program_response('quiz', $this->quiz_payload([
            [
                'id' => 7,
                'question' => 'Wo stehen die Verbindlichkeiten?',
                'choices' => ['A) Links', 'B) Rechts', 'C) Nirgends', 'D) Überall'],
                'correct' => 'B',
                'explanation' => $explanation,
                'objectiveid' => $realid,
            ],
        ], $topic));

        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        // Case (a): a populated, objective-tagged, non-ASCII quiz.
        $result = generate_quiz::execute((int) $course->id);
        $this->assertTrue($result['success']);
        $clean = \core_external\external_api::clean_returnvalue(generate_quiz::execute_returns(), $result);

        $this->assertSame($topic, $clean['topic'], 'The topic was rewritten on its way to the browser.');
        $this->assertSame(7, $clean['questions'][0]['id']);
        $this->assertSame($result['questions'][0]['question'], $clean['questions'][0]['question']);
        $this->assertSame($result['questions'][0]['choices'], $clean['questions'][0]['choices']);
        $this->assertSame('B', $clean['questions'][0]['correct']);
        $this->assertSame($explanation, $clean['questions'][0]['explanation']);
        $this->assertSame($realid, $clean['questions'][0]['objectiveid']);

        // Case (b): the integrity refusal, which the client reads for its errorcode.
        $this->seed_live_attempt($learner, $course);
        $locked = generate_quiz::execute((int) $course->id);
        $cleanlocked = \core_external\external_api::clean_returnvalue(generate_quiz::execute_returns(), $locked);
        $this->assertSame('quizlocked', $cleanlocked['errorcode']);
        $this->assertFalse($cleanlocked['success']);
        $this->assertSame($locked['error'], $cleanlocked['error']);
        $this->assertSame([], $cleanlocked['questions']);

        // Case (c): the panel-open acknowledgement, which has never been round-tripped.
        $open = record_quiz_open::execute((int) $course->id, 0);
        $cleanopen = \core_external\external_api::clean_returnvalue(record_quiz_open::execute_returns(), $open);
        $this->assertSame(['recorded' => true], $cleanopen);
    }
}
