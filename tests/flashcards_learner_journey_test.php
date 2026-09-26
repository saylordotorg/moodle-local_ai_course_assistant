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

use local_ai_course_assistant\external\generate_flashcards;
use local_ai_course_assistant\external\review_flashcard;
use local_ai_course_assistant\provider\stub_provider;

/**
 * The flashcard flow -- generate, own, review, re-schedule -- driven as the learner.
 *
 * WHY THIS FILE EXISTS. Three capability defects reached a release on one day.
 * Each one re-gated a learner-facing feature behind a capability no student
 * holds, so the feature was invisible to learners and worked perfectly for
 * every teacher and administrator who checked it. Not one test went red,
 * because across 200+ test files exactly one asserted that a learner LACKS a
 * staff capability: every other test would pass unchanged running as staff.
 *
 * So each test below asserts FIRST that the learner holds none of :manage,
 * :viewanalytics, moodle/course:manageactivities, moodle/course:update,
 * moodle/grade:viewall or moodle/course:viewhiddenactivities, and DOES hold
 * local/ai_course_assistant:use. A red test then reads "the learner was
 * refused", not "the learner was never entitled".
 *
 * Beyond the capability class this file closes four gaps the existing
 * flashcard tests genuinely do not reach:
 *
 *  - classes/external/review_flashcard.php had ZERO tests. Nothing in tests/
 *    so much as named it, so its ownership filter, its card-derived context,
 *    its capability gate and its quality allowlist were all unexercised.
 *  - No flashcard test anywhere put a second learner in the picture, so
 *    nothing pinned that one learner cannot move another's card (defended
 *    twice, at the endpoint and in the manager -- both are pinned here, the
 *    endpoint with a cross-course victim so the two defences are separable)
 *    and nothing pinned that a deck excludes other learners' and other
 *    courses' cards.
 *  - The plugin's repeat null-vs-zero regression. The nullable columns on this
 *    table are cmid and objectiveid, and the one existing test on that line
 *    asserts assertEquals(0, (int)$row->objectiveid) -- an int cast that reads
 *    NULL and 0 as the same value, so the most-cited null-safety test on the
 *    feature cannot see the defect it looks like it covers. Here it is
 *    assertNull on the raw column.
 *  - The v4.0/M5 mastery boost, which halves the next interval for a card
 *    tagged to an unmastered objective, had no test at all: deleting it today
 *    leaves every other suite green.
 *
 * No network and no provider key: generation runs on the plugin's own stub
 * provider seam, and the whole review/scheduling half is pure DB.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\generate_flashcards
 * @covers     \local_ai_course_assistant\external\review_flashcard
 * @covers     \local_ai_course_assistant\flashcard_manager
 */
final class flashcards_learner_journey_test extends \advanced_testcase {
    /** @var string Page body long enough to clear get_module_content()'s 30-char floor. */
    private const PAGE_BODY = 'Photosynthesis turns light into sugar. ';

    /**
     * Route every provider call to the in-process stub and switch flashcards on.
     *
     * The feature ships OFF, so without the flag every test here would pass by
     * taking the 'flashcards_disabled' early return -- green for the wrong reason.
     *
     * @return void
     */
    private function use_the_stub_provider(): void {
        stub_provider::reset();
        set_config('provider', 'stub', 'local_ai_course_assistant');
        set_config('apikey', 'stub-key', 'local_ai_course_assistant');
        set_config('flashcards_enabled', 1, 'local_ai_course_assistant');
    }

    /**
     * The learner running this test must not be able to see everybody's work.
     *
     * Without these assertions the whole file would pass while running as
     * somebody who can manage the course and read every learner's grades,
     * which is exactly how three defects hid on release day.
     *
     * @param int $courseid Course whose context the capabilities are read in.
     * @return void
     */
    private function assert_holds_no_staff_capability(int $courseid): void {
        $context = \context_course::instance($courseid);

        $this->assertFalse(
            has_capability('local/ai_course_assistant:manage', $context),
            'A learner must not hold the plugin manage capability, or this test proves nothing.'
        );
        $this->assertFalse(
            has_capability('local/ai_course_assistant:viewanalytics', $context),
            'A learner must not hold the analytics capability -- it is the exact capability a '
                . 'mis-gated flashcard endpoint would demand, so holding it hides the defect.'
        );
        // NOTE: the requested name 'moodle/course:manage' does not exist in Moodle core --
        // has_capability() on it emits a debugging() warning and answers false for everybody,
        // which is an assertion that cannot fail. 'moodle/course:manageactivities' is the real
        // capability that carries that meaning, so it is the one checked here.
        $this->assertFalse(
            has_capability('moodle/course:manageactivities', $context),
            'A learner must not be able to manage the course activities, or this test is running as staff.'
        );
        $this->assertFalse(
            has_capability('moodle/course:update', $context),
            'A learner must not be able to edit the course, or this test is running as staff.'
        );
        $this->assertFalse(
            has_capability('moodle/grade:viewall', $context),
            'A learner must not be able to read everybody\'s grades, or the per-learner '
                . 'scoping guarantees are being checked as somebody who sees them all anyway.'
        );
        $this->assertFalse(
            has_capability('moodle/course:viewhiddenactivities', $context),
            'A learner must not be able to read hidden activities, or the page-content '
                . 'guarantee is being checked as somebody allowed to read everything.'
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
     * Create a learner enrolled as a student on a course.
     *
     * @param \stdClass $course Course to enrol into.
     * @return \stdClass The new user.
     */
    private function enrolled_learner(\stdClass $course): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        return $user;
    }

    /**
     * A Page activity whose body survives get_module_content()'s text floor.
     *
     * @param \stdClass $course Course to build the page in.
     * @return \stdClass The generated module, carrying ->cmid.
     */
    private function readable_page(\stdClass $course): \stdClass {
        return $this->getDataGenerator()->create_module('page', [
            'course'  => $course->id,
            'name'    => 'Stub page',
            'content' => str_repeat(self::PAGE_BODY, 20),
        ]);
    }

    /**
     * Insert a card that is due right now, with an explicit schedule.
     *
     * Raw insert rather than save_batch so the scheduling assertions depend on
     * stated values instead of on clock drift, following mastery_test.php.
     *
     * @param \stdClass $user Owner of the card.
     * @param \stdClass $course Course the card belongs to.
     * @param string $question Question text, used as the identifying marker.
     * @return int The new card id.
     */
    private function seed_due_card(\stdClass $user, \stdClass $course, string $question): int {
        global $DB;

        $now = time();
        return (int) $DB->insert_record('local_ai_course_assistant_flashcards', (object) [
            'userid'        => $user->id,
            'courseid'      => $course->id,
            'cmid'          => null,
            'objectiveid'   => null,
            'question'      => $question,
            'answer'        => 'Answer to ' . $question,
            'ease'          => 2.50,
            'interval_days' => 1,
            'repetitions'   => 1,
            'next_review'   => $now - 100,
            'timecreated'   => $now - 1000,
            'timemodified'  => $now - 1000,
        ]);
    }

    /**
     * Read one card row straight from the database.
     *
     * @param int $cardid Card to read.
     * @return \stdClass The raw row.
     */
    private function raw_card(int $cardid): \stdClass {
        global $DB;
        return $DB->get_record('local_ai_course_assistant_flashcards', ['id' => $cardid], '*', MUST_EXIST);
    }

    /**
     * Assert that nothing in a card's schedule moved.
     *
     * @param \stdClass $before Row as it was before the refused call.
     * @param \stdClass $after Row as it is afterwards.
     * @param string $why Message explaining what a moved schedule would mean.
     * @return void
     */
    private function assert_schedule_unmoved(\stdClass $before, \stdClass $after, string $why): void {
        $this->assertSame((int) $before->next_review, (int) $after->next_review, $why);
        $this->assertSame((int) $before->repetitions, (int) $after->repetitions, $why);
        $this->assertSame((int) $before->interval_days, (int) $after->interval_days, $why);
        $this->assertSame((float) $before->ease, (float) $after->ease, $why);
        $this->assertSame((int) $before->timemodified, (int) $after->timemodified, $why);
    }

    /**
     * A learner who holds only :use can make flashcards from the page they are reading.
     *
     * G1. If this breaks, the "make flashcards" button 500s for every student
     * on the site while it keeps working for every teacher and administrator
     * who tries it -- the exact release-day shape this file exists to stop.
     * The learner is asserted to hold no staff capability first, then drives
     * the real endpoint and reads the cards back out of their own deck.
     *
     * @return void
     */
    public function test_an_enrolled_learner_holding_only_use_can_generate_and_immediately_review(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->enrolled_learner($course);
        $page = $this->readable_page($course);
        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $result = generate_flashcards::execute((int) $course->id, (int) $page->cmid, 3);

        $this->assertTrue($result['success'], 'A learner holding only :use was refused their own flashcards.');
        $this->assertSame('ok', $result['message']);
        $this->assertCount(3, $result['cards']);

        // What the learner actually receives: their own deck, readable now.
        $due = array_values(flashcard_manager::get_due((int) $learner->id, (int) $course->id, 30));
        $this->assertCount(3, $due, 'The learner generated three cards and their deck did not show them.');
        $questions = array_map(static fn($c) => $c->question, $due);
        $this->assertContains('Stub front 1', $questions);
        $this->assertContains('Stub front 3', $questions);
    }

    /**
     * A learner can grade their own card and the schedule actually advances.
     *
     * G2. If this breaks, every learner's Again/Hard/Easy button silently
     * fails while it works perfectly for the teacher testing the page, so the
     * deck never empties and the same cards come back forever.
     *
     * @return void
     */
    public function test_a_learner_can_grade_their_own_card_through_the_endpoint(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->enrolled_learner($course);
        $ids = flashcard_manager::save_batch(
            (int) $learner->id,
            (int) $course->id,
            null,
            [['question' => 'What drives photosynthesis?', 'answer' => 'Light.']]
        );
        $cardid = (int) $ids[0];
        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $result = review_flashcard::execute($cardid, flashcard_manager::QUALITY_EASY);

        $this->assertTrue($result['success'], 'A learner could not self-grade their own flashcard.');
        $row = $this->raw_card($cardid);
        $this->assertSame(1, (int) $row->repetitions, 'The grade did not count as a repetition.');
        $this->assertSame(4, (int) $row->interval_days, 'A first EASY grade must schedule four days out.');
        $this->assertGreaterThan(
            time() + (3 * 86400),
            (int) $row->next_review,
            'The card stayed due, so the learner is handed it again in the same session.'
        );
    }

    /**
     * Another learner's card id is refused, and the refusal says nothing about it.
     *
     * G3. The victim's card sits in a course the caller cannot enter. A plain
     * ['success' => false] tells the caller nothing. Drop the endpoint's own
     * ownership filter and the same request throws require_login_exception
     * instead -- an oracle telling any learner who guesses an id that the card
     * exists and which course it lives in.
     *
     * @return void
     */
    public function test_the_endpoint_refuses_another_learners_card_without_leaking_its_course(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $callerscourse = $this->getDataGenerator()->create_course();
        $victimscourse = $this->getDataGenerator()->create_course();
        $caller = $this->enrolled_learner($callerscourse);
        $victim = $this->enrolled_learner($victimscourse);
        $cardid = $this->seed_due_card($victim, $victimscourse, 'VICTIM-CARD');
        $before = $this->raw_card($cardid);

        $this->setUser($caller);
        $this->assert_this_is_an_entitled_learner((int) $callerscourse->id);
        $this->assertFalse(
            is_enrolled(\context_course::instance($victimscourse->id), $caller),
            'The caller must be outside the victim\'s course, or the leak this test names cannot happen.'
        );

        $result = review_flashcard::execute($cardid, flashcard_manager::QUALITY_EASY);

        $this->assertSame(
            ['success' => false],
            $result,
            'Another learner\'s card must be a flat refusal that reveals nothing about it.'
        );
        $this->assert_schedule_unmoved(
            $before,
            $this->raw_card($cardid),
            'A stranger rescheduled a card in a course they cannot even open.'
        );
    }

    /**
     * The manager's own ownership check is real, not decorative.
     *
     * G4. Ownership is defended twice; this pins the inner one on its own, by
     * calling flashcard_manager::review() directly the way any future caller
     * would. If it goes, the learner whose card it is finds their revision
     * schedule rewritten by a classmate and can neither see nor undo it.
     *
     * @return void
     */
    public function test_the_manager_refuses_a_non_owner_and_leaves_the_schedule_byte_identical(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $owner = $this->enrolled_learner($course);
        $other = $this->enrolled_learner($course);
        $cardid = $this->seed_due_card($owner, $course, 'OWNER-CARD');
        $before = $this->raw_card($cardid);

        $this->setUser($other);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $ok = flashcard_manager::review($cardid, (int) $other->id, flashcard_manager::QUALITY_EASY);

        $this->assertFalse($ok, 'The manager rescheduled a card belonging to somebody else.');
        $this->assert_schedule_unmoved(
            $before,
            $this->raw_card($cardid),
            'A classmate\'s grade moved this learner\'s card.'
        );
        $this->assertSame(
            (int) $owner->id,
            (int) $this->raw_card($cardid)->userid,
            'The card changed hands.'
        );
    }

    /**
     * A quality the buttons cannot produce is refused, and nothing moves.
     *
     * G5. flashcard_manager::review() only tests equality against AGAIN and
     * treats everything that is not HARD as EASY, so a stray 2 or 4 arriving
     * from a hand-made request would hand the learner a full four-day easy
     * interval they never earned and bury the card they just failed.
     *
     * @return void
     */
    public function test_an_out_of_range_quality_is_refused_and_the_schedule_does_not_move(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->enrolled_learner($course);
        $cardid = $this->seed_due_card($learner, $course, 'IN-RANGE-ONLY');
        $before = $this->raw_card($cardid);

        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $result = review_flashcard::execute($cardid, 2);

        $this->assertFalse(
            $result['success'],
            'A quality outside {1,3,5} was accepted; the SM-2 branch treats it as a fluent recall.'
        );
        $this->assert_schedule_unmoved(
            $before,
            $this->raw_card($cardid),
            'An out-of-range grade rescheduled the card anyway.'
        );
    }

    /**
     * A learner's deck holds their cards and nobody else's.
     *
     * G6. Two learners revise the same course. Loosen the ownership predicate
     * and this learner opens their review page to find a classmate's questions
     * -- and answers -- mixed into it, and their own progress stops making sense.
     *
     * @return void
     */
    public function test_the_deck_contains_only_this_learners_cards(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->enrolled_learner($course);
        $classmate = $this->enrolled_learner($course);
        $this->assertLessThan(
            (int) $classmate->id,
            (int) $learner->id,
            'The classmate must sort after the learner, or a loosened >= predicate would still exclude them.'
        );
        $this->seed_due_card($learner, $course, 'MINE');
        $this->seed_due_card($classmate, $course, 'THEIRS');

        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $due = array_values(flashcard_manager::get_due((int) $learner->id, (int) $course->id, 30));

        $this->assertCount(1, $due, 'The learner\'s deck picked up a card that is not theirs.');
        $this->assertSame('MINE', $due[0]->question);
        $this->assertSame((int) $learner->id, (int) $due[0]->userid);
    }

    /**
     * A learner's deck holds this course's cards and no other course's.
     *
     * G7. flashcards.php passes the courseid straight through, so a loosened
     * course predicate drops another subject's material into the middle of
     * this course's revision session, with no way for the learner to tell
     * where it came from.
     *
     * @return void
     */
    public function test_the_deck_contains_only_this_courses_cards(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $thiscourse = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $this->assertLessThan(
            (int) $othercourse->id,
            (int) $thiscourse->id,
            'The other course must sort after this one, or a loosened >= predicate would still exclude it.'
        );
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $thiscourse->id, 'student');
        $this->getDataGenerator()->enrol_user($learner->id, $othercourse->id, 'student');
        $this->seed_due_card($learner, $thiscourse, 'THIS-COURSE');
        $this->seed_due_card($learner, $othercourse, 'OTHER-COURSE');

        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $thiscourse->id);

        $due = array_values(flashcard_manager::get_due((int) $learner->id, (int) $thiscourse->id, 30));

        $this->assertCount(1, $due, 'A card from another course appeared in this course\'s deck.');
        $this->assertSame('THIS-COURSE', $due[0]->question);
        $this->assertSame((int) $thiscourse->id, (int) $due[0]->courseid);
    }

    /**
     * The card a learner just made is waiting for them straight away.
     *
     * G8. A card written with a future next_review still returns success:true
     * from the endpoint, so the learner presses "make flashcards", is told it
     * worked, opens the deck and finds it empty. Invisible on the API
     * response; obvious only to the learner.
     *
     * @return void
     */
    public function test_a_freshly_generated_card_is_due_immediately(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->enrolled_learner($course);
        $page = $this->readable_page($course);
        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $result = generate_flashcards::execute((int) $course->id, (int) $page->cmid, 3);
        $this->assertTrue($result['success']);

        $due = flashcard_manager::get_due((int) $learner->id, (int) $course->id, 30);

        $this->assertNotEmpty(
            $due,
            'The learner made flashcards, was told it worked, and opened an empty deck.'
        );
        $this->assertCount(3, $due);
        foreach ($due as $card) {
            $this->assertLessThanOrEqual(
                time(),
                (int) $card->next_review,
                'A just-generated card must be reviewable now, not scheduled into the future.'
            );
        }
    }

    /**
     * Cards a learner generates belong to that learner and to nobody else.
     *
     * G9. Attribution written as anything but the caller leaves the generator
     * reporting success while the cards land where the learner can never reach
     * them -- and, if they land on a shared id, in somebody else's deck.
     *
     * @return void
     */
    public function test_generated_cards_are_owned_by_the_generating_learner_only(): void {
        global $DB;

        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $author = $this->enrolled_learner($course);
        $classmate = $this->enrolled_learner($course);
        $page = $this->readable_page($course);

        $this->setUser($author);
        $this->assert_this_is_an_entitled_learner((int) $course->id);
        $this->assertTrue(generate_flashcards::execute((int) $course->id, (int) $page->cmid, 3)['success']);

        $rows = $DB->get_records('local_ai_course_assistant_flashcards', ['courseid' => $course->id]);
        $this->assertCount(3, $rows, 'Generation did not persist the learner\'s cards.');
        foreach ($rows as $row) {
            $this->assertSame(
                (int) $author->id,
                (int) $row->userid,
                'A generated card was filed against somebody other than the learner who made it.'
            );
            $this->assertSame((int) $course->id, (int) $row->courseid);
            $this->assertSame((int) $page->cmid, (int) $row->cmid, 'The card lost the page it came from.');
        }

        $this->assertCount(
            3,
            flashcard_manager::get_due((int) $author->id, (int) $course->id, 30),
            'The learner who generated the cards cannot see them.'
        );
        $this->setUser($classmate);
        $this->assert_this_is_an_entitled_learner((int) $course->id);
        $this->assertSame(
            [],
            flashcard_manager::get_due((int) $classmate->id, (int) $course->id, 30),
            'A classmate who generated nothing was handed somebody else\'s cards.'
        );
    }

    /**
     * An untagged card stores objectiveid as NULL, never as 0.
     *
     * G10. This is the exact hole in the existing assertion, which casts to int
     * and so reads NULL and 0 identically. A stored 0 is a foreign key to a row
     * that does not exist: the mastery boost below reads !empty($objectiveid)
     * and compute_mastery() would be asked about objective 0, so the learner's
     * scheduling silently changes shape on cards nobody ever tagged.
     *
     * @return void
     */
    public function test_an_untagged_card_stores_null_objectiveid_not_zero(): void {
        global $DB;

        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->enrolled_learner($course);
        objective_manager::set_enabled_for_course((int) $course->id, true);
        $realid = objective_manager::create((int) $course->id, 'Read a balance sheet');

        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $ids = flashcard_manager::save_batch((int) $learner->id, (int) $course->id, null, [
            ['question' => 'Tagged', 'answer' => 'A', 'objectiveid' => $realid],
            ['question' => 'Untagged', 'answer' => 'B'],
            ['question' => 'Invented', 'answer' => 'C', 'objectiveid' => 999999],
        ]);
        $this->assertCount(3, $ids);

        $rows = $DB->get_records('local_ai_course_assistant_flashcards', ['userid' => $learner->id], 'id ASC');
        $rows = array_values($rows);

        // Positive control: a real tag really is kept, so the nulls below mean something.
        $this->assertSame($realid, (int) $rows[0]->objectiveid, 'A real objective tag was thrown away.');
        $this->assertNotNull($rows[0]->objectiveid);

        $this->assertNull(
            $rows[1]->objectiveid,
            'A card with no objective stored 0, which points at an objective that does not exist.'
        );
        $this->assertNull(
            $rows[2]->objectiveid,
            'An objective id this course does not have was coerced to 0 instead of dropped to NULL.'
        );
    }

    /**
     * A card made outside any activity stores cmid as NULL, never as 0.
     *
     * G11. cmid is nullable and 0 is not a valid course-module id, so a coerced
     * 0 is a row claiming it came from a module that does not exist. The
     * learner's card then advertises a source they can never open.
     *
     * @return void
     */
    public function test_a_card_with_no_module_stores_null_cmid_not_zero(): void {
        global $DB;

        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->enrolled_learner($course);
        $page = $this->readable_page($course);

        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $nomodule = flashcard_manager::save_batch((int) $learner->id, (int) $course->id, null, [
            ['question' => 'No module', 'answer' => 'A'],
        ]);
        $frompage = flashcard_manager::save_batch((int) $learner->id, (int) $course->id, (int) $page->cmid, [
            ['question' => 'From a page', 'answer' => 'B'],
        ]);

        $bare = $DB->get_record('local_ai_course_assistant_flashcards', ['id' => $nomodule[0]], '*', MUST_EXIST);
        $sourced = $DB->get_record('local_ai_course_assistant_flashcards', ['id' => $frompage[0]], '*', MUST_EXIST);

        $this->assertNull(
            $bare->cmid,
            'A card with no source module stored cmid 0, claiming provenance from a module that does not exist.'
        );
        // Positive control: a real cmid survives, so the null above is a choice, not a failure to write.
        $this->assertSame((int) $page->cmid, (int) $sourced->cmid, 'A real source module was lost.');
    }

    /**
     * A card on an objective the learner has not mastered comes back sooner.
     *
     * G12. The v4.0/M5 boost halves the next interval for a tagged card whose
     * objective is not yet mastered. Delete it and every existing test stays
     * green while the learner quietly stops getting extra practice on exactly
     * the material they are weakest on.
     *
     * @return void
     */
    public function test_a_card_tagged_to_an_unmastered_objective_comes_back_sooner(): void {
        $this->resetAfterTest();
        $this->use_the_stub_provider();

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->enrolled_learner($course);
        objective_manager::set_enabled_for_course((int) $course->id, true);
        $objectiveid = objective_manager::create((int) $course->id, 'Balance a ledger');

        $ids = flashcard_manager::save_batch((int) $learner->id, (int) $course->id, null, [
            ['question' => 'Tagged card', 'answer' => 'A', 'objectiveid' => $objectiveid],
            ['question' => 'Control card', 'answer' => 'B'],
        ]);
        [$tagged, $control] = [(int) $ids[0], (int) $ids[1]];

        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        // The boost only fires while the objective is unmastered, so pin that first.
        $mastery = objective_manager::compute_mastery((int) $learner->id, (int) $objectiveid);
        $this->assertSame(
            'not_started',
            $mastery['status'],
            'The learner must be unmastered on this objective, or the boost would never apply.'
        );

        $this->assertTrue(review_flashcard::execute($tagged, flashcard_manager::QUALITY_EASY)['success']);
        $this->assertTrue(review_flashcard::execute($control, flashcard_manager::QUALITY_EASY)['success']);

        $this->assertSame(
            4,
            (int) $this->raw_card($control)->interval_days,
            'The untagged control card must keep vanilla SM-2, or the comparison means nothing.'
        );
        $this->assertSame(
            2,
            (int) $this->raw_card($tagged)->interval_days,
            'A card on an objective the learner has not mastered was not brought forward.'
        );
        $this->assertLessThan(
            (int) $this->raw_card($control)->next_review,
            (int) $this->raw_card($tagged)->next_review,
            'The weaker material is not scheduled ahead of the material the learner already knows.'
        );
    }
}
