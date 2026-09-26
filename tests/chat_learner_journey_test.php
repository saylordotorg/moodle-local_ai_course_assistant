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

use local_ai_course_assistant\external\get_history;
use local_ai_course_assistant\external\rate_message;
use local_ai_course_assistant\external\send_message;
use local_ai_course_assistant\provider\stub_provider;

/**
 * The chat turn, driven end to end as the learner it was written for.
 *
 * WHY THIS FILE EXISTS. Three capability defects reached a release on one day.
 * Each made a learner-facing feature read its data through an API needing a
 * capability no student holds, so the feature was invisible to learners and
 * perfect for everybody who checked it. Across 200+ test files exactly ONE
 * asserted that a learner LACKS a staff capability; every other test would pass
 * unchanged running as a teacher or an administrator. So every test here
 * asserts FIRST that the caller holds none of viewanalytics, manage,
 * course:update, course:viewhiddenactivities or grade:viewall, and DOES hold
 * local/ai_course_assistant:use -- so a red test reads as "the learner was
 * refused" and not "the learner was never entitled".
 *
 * WHAT IT COVERS, AND WHAT IT HONESTLY CANNOT. sse.php is the streaming
 * transport and it is not reachable from PHPUnit: it is a page script that
 * requires config.php and die()s on a non-POST request before a single class is
 * defined, and its helpers are plain functions declared inside it. Including it
 * kills the runner, which is why every existing sse.php test reads the file as
 * text. Nothing here pretends otherwise. The guarantees below are driven
 * through send_message (the non-streaming web-service/mobile fallback that the
 * Moodle app calls), rate_message and get_history, or through the collaborators
 * sse.php executes line for line -- conversation_manager and protocol_markers.
 *
 * Beyond the capability floor it pins what the existing files on this surface
 * genuinely do not reach. send_message_service_test deliberately runs with NO
 * provider configured, so the success path of that endpoint has never once
 * executed: not the strip-before-storage, not the assistant row's token fields.
 * rate_message has no ownership test at all, so the block that stops a
 * classmate hallucination-flagging another learner's private reply can be
 * deleted with the whole suite staying green. get_history and all three
 * conversation-cap tests use a single user and a single conversation, so the
 * per-user and per-conversation predicates that keep one learner out of
 * another's transcript are currently free to disappear. And the F82 shared
 * throttle has never been called from either chat endpoint.
 *
 * No network and no provider key: the flow runs on the plugin's own stub
 * provider seam, so this is CI-safe and cannot be disabled by an expiring key.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\send_message
 * @covers     \local_ai_course_assistant\external\rate_message
 * @covers     \local_ai_course_assistant\external\get_history
 * @covers     \local_ai_course_assistant\conversation_manager
 */
final class chat_learner_journey_test extends \advanced_testcase {
    /**
     * Route every provider call to the in-process stub and keep the turn off the network.
     *
     * rag_enabled off stops the retrieval path reaching an embedding provider;
     * history_mode 'recency' stops history_selector::select_for_api doing the
     * same, since its default is 'semantic' and semantic instantiates one.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        stub_provider::reset();
        set_config('provider', 'stub', 'local_ai_course_assistant');
        set_config('apikey', 'stub-key', 'local_ai_course_assistant');
        set_config('rag_enabled', 0, 'local_ai_course_assistant');
        set_config('history_mode', 'recency', 'local_ai_course_assistant');
    }

    /**
     * The learner running this test must not be able to see everybody's work.
     *
     * Without these five assertions every test in this file would pass while
     * running as somebody who can read hidden activities and every learner's
     * grades, which is exactly how three defects hid on release day. manage is
     * doubly load-bearing here: it is also what sse.php reads to classify the
     * caller as 'staff' rather than 'student'.
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
            'A learner must not hold the plugin manage capability: sse.php reads it to decide '
                . 'whether the caller is staff, so a holder is not the person this file is about.'
        );
        $this->assertFalse(
            has_capability('moodle/course:update', $context),
            'A learner must not be able to edit the course, or this test is running as a teacher.'
        );
        $this->assertFalse(
            has_capability('moodle/course:viewhiddenactivities', $context),
            'A learner must not be able to read hidden activities.'
        );
        $this->assertFalse(
            has_capability('moodle/grade:viewall', $context),
            'A learner must not be able to read everybody\'s grades.'
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
     * A dedicated role rather than an edit to the shipped 'student' role, so
     * the other learners in the same test stay entitled and the cases stay
     * independent.
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
     * Seed one assistant reply in a learner's own conversation and return its id.
     *
     * Written through conversation_manager so the row carries the real column
     * shape rather than a hand-built approximation.
     *
     * @param \stdClass $user Owner of the conversation.
     * @param \stdClass $course Course the conversation belongs to.
     * @param string $text Message body.
     * @return int Message id.
     */
    private function seed_assistant_reply(\stdClass $user, \stdClass $course, string $text): int {
        $conv = conversation_manager::get_or_create_conversation((int) $user->id, (int) $course->id);
        return conversation_manager::add_message(
            (int) $conv->id,
            (int) $user->id,
            (int) $course->id,
            'assistant',
            $text
        );
    }

    /**
     * A learner cannot push a question into a course they are not in.
     *
     * The learner is enrolled in one course and edits the courseid on the
     * request. If this breaks, anybody with an account can open a conversation
     * inside a course they were never admitted to, and -- because the refusal
     * would then arrive from the provider factory further down -- their
     * question and a `message_sent` audit row would already be committed
     * against that course. The audit row is the one line an academic-integrity
     * review reads, so it must never record a turn that was refused.
     *
     * @return void
     */
    public function test_a_learner_cannot_chat_into_a_course_they_are_not_enrolled_in(): void {
        global $DB;

        $mine = $this->getDataGenerator()->create_course();
        $theirs = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $mine->id, 'student');
        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $mine->id);

        $this->assertFalse(
            is_enrolled(\context_course::instance($theirs->id), $learner),
            'The learner must be a stranger to the other course, or this test proves nothing.'
        );

        try {
            send_message::execute((int) $theirs->id, 'Let me in', 0);
            $this->fail('A stranger to the course was allowed to open a conversation in it.');
        } catch (\require_login_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame(
            0,
            $DB->count_records('local_ai_course_assistant_msgs', ['courseid' => $theirs->id]),
            'The refusal landed after the learner\'s question had already been committed.'
        );
        $this->assertSame(
            0,
            $DB->count_records('local_ai_course_assistant_audit', [
                'action' => 'message_sent',
                'courseid' => $theirs->id,
            ]),
            'A refused turn was audited as a sent message, which is the opposite of what happened.'
        );
        $this->assertSame([], stub_provider::$calls, 'A refused turn must not be billed for a provider call.');
    }

    /**
     * A learner the site has switched the assistant off for gets no answer at all.
     *
     * Not an empty reply, not a silent success: a refusal, and a refusal named
     * for the assistant capability. If it arrived for some unrelated reason the
     * gate could be removed without anybody noticing, and the learner would be
     * talking to a model their institution had decided they may not use.
     *
     * @return void
     */
    public function test_a_learner_without_the_use_capability_is_refused_the_chat_turn(): void {
        global $DB;

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
            send_message::execute((int) $course->id, 'What is a balance sheet?', 0);
            $this->fail('A learner without the assistant capability was answered by the model.');
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
     * A learner cannot rate a reply in a course they are not in.
     *
     * rate_message resolves the course FROM the message id, so a stranger who
     * guesses an id is inside the endpoint before anything has looked at
     * enrolment. If the context check goes, an outsider can thumbs-down and
     * hallucination-flag replies in courses they have never seen, and the
     * teacher's feedback analytics are whatever a stranger decided.
     *
     * @return void
     */
    public function test_a_learner_cannot_rate_a_message_in_a_course_they_are_not_enrolled_in(): void {
        global $DB;

        $mine = $this->getDataGenerator()->create_course();
        $theirs = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $insider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $mine->id, 'student');
        $this->getDataGenerator()->enrol_user($insider->id, $theirs->id, 'student');
        $messageid = $this->seed_assistant_reply($insider, $theirs, 'A reply in a course you are not in.');

        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $mine->id);
        $this->assertFalse(is_enrolled(\context_course::instance($theirs->id), $learner));

        try {
            rate_message::execute($messageid, -1, 1, 'Not true');
            $this->fail('A stranger to the course rated a reply inside it.');
        } catch (\require_login_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame(
            0,
            $DB->count_records('local_ai_course_assistant_msg_ratings', ['messageid' => $messageid]),
            'A stranger\'s rating reached the table the teacher\'s feedback report reads.'
        );
    }

    /**
     * Being in the course is not enough to rate: the assistant gate applies too.
     *
     * A learner the institution has switched the assistant off for is still
     * enrolled. If this breaks they can still flag replies as hallucinations in
     * a feature they are not entitled to use, and the refusal the chat turn
     * gives them stops meaning anything.
     *
     * @return void
     */
    public function test_a_learner_without_the_use_capability_cannot_rate_a_message(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id, 'student');
        $messageid = $this->seed_assistant_reply($learner, $course, 'Their own reply, in their own conversation.');
        $this->prohibit_the_assistant_for($learner, $course);
        $this->setUser($learner);

        $this->assert_holds_no_staff_capability((int) $course->id);
        $this->assertFalse(
            has_capability('local/ai_course_assistant:use', \context_course::instance($course->id)),
            'The prohibition did not take effect, so this test would prove nothing.'
        );

        try {
            rate_message::execute($messageid, 1, 0, '');
            $this->fail('Course membership alone let a learner rate a feature they may not use.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(
                get_capability_string('local/ai_course_assistant:use'),
                $e->a,
                'Refused, but for some capability other than the assistant one.'
            );
        }

        $this->assertSame(
            0,
            $DB->count_records('local_ai_course_assistant_msg_ratings', ['messageid' => $messageid])
        );
    }

    /**
     * A classmate cannot hallucination-flag a reply that was never theirs.
     *
     * Two learners in one course, both fully entitled. If the ownership check
     * goes, any enrolled learner who can guess a message id can thumbs-down and
     * hallucination-flag another learner's private conversation. The victim
     * never sees it; the teacher's feedback analytics show their answers being
     * reported as false by somebody who never read them.
     *
     * @return void
     */
    public function test_a_learner_cannot_rate_another_learners_message(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_user();
        $classmate = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($owner->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($classmate->id, $course->id, 'student');
        $messageid = $this->seed_assistant_reply($owner, $course, 'A private reply to the owner.');

        $this->setUser($classmate);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        try {
            rate_message::execute($messageid, -1, 1, 'This is made up');
            $this->fail('A classmate hallucination-flagged a reply from somebody else\'s conversation.');
        } catch (\moodle_exception $e) {
            $this->assertNotInstanceOf(
                \invalid_parameter_exception::class,
                $e,
                'Refused, but for the shape of the request rather than for whose message it is.'
            );
        }

        $this->assertSame(
            0,
            $DB->count_records('local_ai_course_assistant_msg_ratings', ['messageid' => $messageid]),
            'A rating on another learner\'s message reached the feedback table.'
        );

        // The owner, who does own it, is not blocked by the same check: without
        // this the test above would pass if ratings were broken for everybody.
        $this->setUser($owner);
        $this->assertSame(['success' => true], rate_message::execute($messageid, 1, 0, ''));
        $this->assertSame(
            1,
            $DB->count_records('local_ai_course_assistant_msg_ratings', [
                'messageid' => $messageid,
                'userid' => $owner->id,
            ])
        );
    }

    /**
     * The transcript a learner reads back is their own, not the course's.
     *
     * Two learners in one course. If the per-user predicate goes, whoever opens
     * the drawer second is handed the first learner's conversation: they read a
     * stranger's questions, and their own next turn is appended to that
     * stranger's transcript and sent to the model as context.
     *
     * @return void
     */
    public function test_the_history_a_learner_reads_back_is_their_own(): void {
        $course = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($first->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($second->id, $course->id, 'student');

        // The first learner's conversation exists first, so a lookup that has
        // lost its userid predicate finds theirs.
        $firstconv = conversation_manager::get_or_create_conversation((int) $first->id, (int) $course->id);
        conversation_manager::add_message(
            (int) $firstconv->id,
            (int) $first->id,
            (int) $course->id,
            'user',
            'CANARY-FIRST I am failing this course, what do I do?'
        );
        $secondconv = conversation_manager::get_or_create_conversation((int) $second->id, (int) $course->id);
        conversation_manager::add_message(
            (int) $secondconv->id,
            (int) $second->id,
            (int) $course->id,
            'user',
            'CANARY-SECOND what is a balance sheet?'
        );

        $this->setUser($second);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $result = get_history::execute((int) $course->id);
        $clean = \core_external\external_api::clean_returnvalue(get_history::execute_returns(), $result);

        $text = json_encode($clean['messages']);
        $this->assertStringContainsString(
            'CANARY-SECOND',
            $text,
            'The learner\'s own transcript did not come back, so the negative below proves nothing.'
        );
        $this->assertStringNotContainsString(
            'CANARY-FIRST',
            $text,
            'A learner was handed another learner\'s conversation.'
        );

        $owned = conversation_manager::get_messages((int) $secondconv->id);
        foreach ($clean['messages'] as $msg) {
            $this->assertArrayHasKey(
                $msg['id'],
                $owned,
                'A row from outside this learner\'s conversation was returned to them.'
            );
        }
    }

    /**
     * Control markers never reach the learner, and never reach the teacher's CSV.
     *
     * The model is made to emit both a well-formed suggestion block and an
     * unterminated opener -- the shape a truncated response produces, and the
     * one the 2026-09-12 production run actually caught. If the strip stops
     * happening before storage, the mobile app shows "[SOLA_NEXT]Tell me more"
     * inside the answer, and classes/transcript_report.php writes the raw
     * column into the teacher-facing transcript CSV verbatim. The learner's own
     * prose must survive both removals intact.
     *
     * @return void
     */
    public function test_control_markers_are_stripped_before_the_reply_is_stored(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id, 'student');
        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        stub_provider::program_response(
            'chat',
            "PROSE-OPENING a balance sheet has two sides.\n\n"
                . "[SOLA_NEXT]Tell me more||Give an example||Quiz me||Summarise[/SOLA_NEXT]\n\n"
                . "PROSE-CLOSING the liabilities sit on the right.\n\n"
                . '[SOLA_NEXT]Tell me more'
        );

        $result = send_message::execute((int) $course->id, 'What is a balance sheet?', 0);

        $this->assertTrue($result['success'], 'The turn never completed, so nothing below is being checked.');
        $this->assertSame(
            'chat',
            stub_provider::$calls[0]['kind'],
            'The assembled chat prompt was detected as another kind, so the programmed body was not used.'
        );

        $this->assertStringNotContainsString(
            'SOLA_NEXT',
            $result['response'],
            'A protocol marker was handed to the learner.'
        );
        $this->assertStringContainsString('PROSE-OPENING', $result['response']);
        $this->assertStringContainsString(
            'PROSE-CLOSING',
            $result['response'],
            'The marker sweep ate the learner\'s answer along with the marker.'
        );

        $row = $DB->get_record('local_ai_course_assistant_msgs', [
            'courseid' => $course->id,
            'userid' => $learner->id,
            'role' => 'assistant',
        ]);
        $this->assertNotFalse($row, 'The reply was never stored.');
        $this->assertStringNotContainsString(
            'SOLA_NEXT',
            $row->message,
            'The stored row still carries a protocol marker, and the transcript CSV emits it verbatim.'
        );
        $this->assertStringContainsString('PROSE-OPENING', $row->message);
        $this->assertStringContainsString('PROSE-CLOSING', $row->message);
    }

    /**
     * A cache count nobody reported is stored as null, never as a zero.
     *
     * This is the first test to observe the row send_message actually writes;
     * every existing token test drives conversation_manager directly or greps
     * the source. The stub reports prompt, completion and model and says
     * nothing about caching. A 0 in cached_tokens claims the provider reported
     * no cache hits, which is a different and unrecoverable statement from
     * "this provider does not report caching" -- and the institution's cost
     * reconciliation cannot tell the two apart after the fact.
     *
     * @return void
     */
    public function test_an_unreported_cache_count_stays_null_on_the_row_this_endpoint_writes(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id, 'student');
        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $result = send_message::execute((int) $course->id, 'What is a balance sheet?', 0);
        $this->assertTrue($result['success'], 'The turn never completed, so no row is being checked.');

        $row = $DB->get_record('local_ai_course_assistant_msgs', [
            'courseid' => $course->id,
            'userid' => $learner->id,
            'role' => 'assistant',
        ]);
        $this->assertNotFalse($row, 'The endpoint returned a reply it never stored.');

        $this->assertNull(
            $row->cached_tokens,
            'The provider reported no cache; a 0 claims it reported one and priced it as measured.'
        );
        $this->assertSame('stub', $row->provider, 'The row cannot be attributed to a provider.');
        $this->assertSame('stub-model', $row->model_name, 'The row cannot be priced without a model.');
        $this->assertSame(100, (int) $row->prompt_tokens);
        $this->assertSame(50, (int) $row->completion_tokens);
        $this->assertSame(
            'chat',
            $row->interaction_type,
            'sse.php writes "chat" for the same turn; rows from the two transports must be comparable.'
        );
    }

    /**
     * Thinking tokens nobody reported are stored as null, never as a zero.
     *
     * Same shape, worse consequence. A null reads as "this provider does not
     * report thinking". A 0 asserts the provider reported zero thinking tokens,
     * so a model silently billing reasoning as output is reconciled against a
     * measurement that was never taken, and the gap looks like a pricing error
     * rather than a missing field.
     *
     * @return void
     */
    public function test_unreported_thinking_tokens_stay_null_on_the_row_this_endpoint_writes(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id, 'student');
        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        $usage = (new stub_provider())->get_last_token_usage();
        $this->assertArrayNotHasKey(
            'reasoning_tokens',
            (array) $usage,
            'The stub started reporting thinking tokens, so this test no longer checks an absent value.'
        );

        $result = send_message::execute((int) $course->id, 'Prove that the sum is finite.', 0);
        $this->assertTrue($result['success'], 'The turn never completed, so no row is being checked.');

        $row = $DB->get_record('local_ai_course_assistant_msgs', [
            'courseid' => $course->id,
            'userid' => $learner->id,
            'role' => 'assistant',
        ]);
        $this->assertNotFalse($row, 'The endpoint returned a reply it never stored.');
        $this->assertNull(
            $row->reasoning_tokens,
            'The provider reported no thinking tokens; a 0 asserts it reported zero, which it did not.'
        );
    }

    /**
     * The 21st turn in a minute is refused, and refused before anything is written.
     *
     * The learner must get a readable "wait a moment" string back rather than an
     * exception, because the mobile client reads the return value and shows an
     * exception as a hard failure. And the refusal has to land before the write:
     * otherwise a throttled learner accumulates questions in their own
     * transcript with no answer under any of them, and the audit log records a
     * `message_sent` for every turn that was actually turned away.
     *
     * @return void
     */
    public function test_the_shared_stream_throttle_refuses_the_twenty_first_turn(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id, 'student');
        $this->setUser($learner);
        $this->assert_this_is_an_entitled_learner((int) $course->id);

        for ($i = 0; $i < 20; $i++) {
            $ok = send_message::execute((int) $course->id, 'Turn ' . $i, 0);
            $this->assertTrue($ok['success'], 'Turn ' . $i . ' was refused while inside the budget.');
        }

        $msgsbefore = $DB->count_records('local_ai_course_assistant_msgs', ['courseid' => $course->id]);
        $auditbefore = $DB->count_records('local_ai_course_assistant_audit', [
            'action' => 'message_sent',
            'courseid' => $course->id,
        ]);
        $callsbefore = count(stub_provider::$calls);

        $refused = send_message::execute((int) $course->id, 'One too many', 0);

        $this->assertFalse($refused['success'], 'The 21st turn in the window was served.');
        $this->assertSame(
            get_string('chat:error_ratelimit', 'local_ai_course_assistant'),
            $refused['response'],
            'The throttled learner was not told to wait; the mobile client shows this string.'
        );
        $this->assertSame(
            $msgsbefore,
            $DB->count_records('local_ai_course_assistant_msgs', ['courseid' => $course->id]),
            'A refused turn left the learner\'s question in their transcript with no answer under it.'
        );
        $this->assertSame(
            $auditbefore,
            $DB->count_records('local_ai_course_assistant_audit', [
                'action' => 'message_sent',
                'courseid' => $course->id,
            ]),
            'A refused turn was audited as a sent message.'
        );
        $this->assertSame($callsbefore, count(stub_provider::$calls), 'A refused turn still paid for a model call.');
    }

    /**
     * One noisy learner cannot silence a classmate.
     *
     * The budget is per learner. Shared, the second learner in a busy course
     * opens the drawer, asks their first question of the day and is told to
     * wait a moment -- for as long as anybody else in the cohort keeps typing.
     *
     * @return void
     */
    public function test_the_stream_throttle_is_per_learner(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $heavy = $this->getDataGenerator()->create_user();
        $quiet = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($heavy->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($quiet->id, $course->id, 'student');

        $this->setUser($heavy);
        $this->assert_this_is_an_entitled_learner((int) $course->id);
        for ($i = 0; $i < 20; $i++) {
            send_message::execute((int) $course->id, 'Turn ' . $i, 0);
        }

        $this->setUser($quiet);
        $this->assert_this_is_an_entitled_learner((int) $course->id);
        $result = send_message::execute((int) $course->id, 'My first question today', 0);

        $this->assertTrue(
            $result['success'],
            'The quiet learner\'s first turn was refused because of somebody else\'s traffic.'
        );
        $this->assertSame(
            1,
            $DB->count_records('local_ai_course_assistant_msgs', [
                'userid' => $quiet->id,
                'role' => 'assistant',
            ]),
            'The quiet learner got a reply that was never stored for them.'
        );
    }

    /**
     * Chatting past the cap never deletes a classmate's messages.
     *
     * The 100-row cap bounds ONE conversation. If it loses its conversation
     * predicate, a talkative learner's 101st turn silently deletes the oldest
     * rows in the course -- which belong to somebody else. The victim opens the
     * drawer the next morning and the start of their conversation is gone, with
     * nothing anywhere saying why. All three existing cap tests use a single
     * conversation, so this predicate is currently free to disappear.
     *
     * @return void
     */
    public function test_the_conversation_cap_never_deletes_another_learners_messages(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $quiet = $this->getDataGenerator()->create_user();
        $talkative = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($quiet->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($talkative->id, $course->id, 'student');

        // The quiet learner's conversation is created first, so it holds the
        // lower id and the oldest rows in the course.
        $quietconv = conversation_manager::get_or_create_conversation((int) $quiet->id, (int) $course->id);
        $quietids = [];
        foreach (['CANARY-QUIET-ONE', 'CANARY-QUIET-TWO'] as $text) {
            $quietids[] = conversation_manager::add_message(
                (int) $quietconv->id,
                (int) $quiet->id,
                (int) $course->id,
                'user',
                $text
            );
        }

        $this->setUser($talkative);
        $this->assert_this_is_an_entitled_learner((int) $course->id);
        $talkconv = conversation_manager::get_or_create_conversation((int) $talkative->id, (int) $course->id);
        for ($i = 0; $i < 102; $i++) {
            conversation_manager::add_message(
                (int) $talkconv->id,
                (int) $talkative->id,
                (int) $course->id,
                $i % 2 === 0 ? 'user' : 'assistant',
                'Turn ' . $i
            );
        }

        $this->assertCount(
            100,
            conversation_manager::get_messages((int) $talkconv->id),
            'The cap stopped bounding the conversation it is meant to bound.'
        );
        foreach ($quietids as $id) {
            $this->assertTrue(
                $DB->record_exists('local_ai_course_assistant_msgs', ['id' => $id]),
                'A classmate\'s message was deleted by somebody else chatting past the cap.'
            );
        }

        $this->setUser($quiet);
        $result = get_history::execute((int) $course->id);
        $this->assertCount(2, $result['messages'], 'The quiet learner lost part of their own transcript.');
        $this->assertSame('CANARY-QUIET-ONE', $result['messages'][0]['message']);
    }
}
