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

use local_ai_course_assistant\external\send_message;
use local_ai_course_assistant\provider\stub_provider;

/**
 * The non-streaming fallback endpoint must actually work.
 *
 * `local_ai_course_assistant_send_message` is registered as the non-streaming
 * fallback and is exposed to MOODLE_OFFICIAL_MOBILE_SERVICE, yet it threw a
 * TypeError on every call: argument 11 of add_message() is $interactiontype,
 * typed string, and this path passed an explicit null. The throw happened after
 * the provider request had completed, so each call billed an AI request and
 * returned nothing.
 *
 * It survived because no shipped client calls it -- the widget streams through
 * sse.php -- so the only consumer is the mobile app, which nothing in the test
 * suite exercised. Hence this file: the contract is checked without a provider,
 * because the defect was in the bookkeeping around the provider call, not in
 * the call itself.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\send_message
 */
final class send_message_service_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $student;

    /**
     * An enrolled student in a course with the assistant available.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->student = $gen->create_user();
        $gen->enrol_user($this->student->id, $this->course->id, 'student');
        $this->setUser($this->student);
        // No provider is configured, so the call fails at the factory. That is
        // deliberate: it exercises everything around the provider, which is
        // where the defect lived.
        set_config('rag_enabled', 0, 'local_ai_course_assistant');
    }

    /**
     * Reset the 8.4.0 test seams.
     */
    protected function tearDown(): void {
        send_message::$beforeanswercheck = null;
        answer_flow::$spendcheck = null;
        answer_audit::$locktimeout = answer_audit::DEFAULT_LOCK_TIMEOUT;
        stub_provider::reset();
        parent::tearDown();
    }

    /**
     * The user's own row is written before anything can fail.
     *
     * This is the row that proves the endpoint got as far as the provider, and
     * it must carry the same interaction type the streaming path writes or the
     * two are not comparable in analytics.
     */
    public function test_user_row_matches_what_the_streaming_path_writes(): void {
        global $DB;

        try {
            send_message::execute((int) $this->course->id, 'What is management?', 0);
        } catch (\Throwable $e) {
            // No provider configured; the user row is already committed.
            $this->assertInstanceOf(\moodle_exception::class, $e);
        }

        $row = $DB->get_record('local_ai_course_assistant_msgs', [
            'courseid' => $this->course->id,
            'userid' => $this->student->id,
            'role' => 'user',
        ]);
        $this->assertNotFalse($row, 'The learner question was never recorded.');
        $this->assertSame(
            'chat',
            $row->interaction_type,
            'sse.php defaults interaction_type to "chat"; this path must agree.'
        );
    }

    /**
     * add_message() must never be handed a null interaction type.
     *
     * Pinned as a property of the call rather than of one code path: argument
     * 11 is typed `string`, so a null anywhere is a TypeError after the model
     * has already been paid for.
     */
    public function test_add_message_rejects_a_null_interaction_type(): void {
        global $DB;

        $conv = conversation_manager::get_or_create_conversation(
            (int) $this->student->id,
            (int) $this->course->id
        );

        $this->expectException(\TypeError::class);
        conversation_manager::add_message(
            (int) $conv->id,
            (int) $this->student->id,
            (int) $this->course->id,
            'assistant',
            'text',
            0,
            '',
            null,
            null,
            null,
            // Argument 11. This is exactly what send_message passed.
            null
        );
    }

    /**
     * An internal failure returns a generic message, not a class path.
     *
     * The TypeError reached the caller complete with the class name and the
     * dirroot path of the file that threw. A learner-facing endpoint must not
     * describe its own internals to whoever called it.
     */
    public function test_internal_failure_is_not_described_to_the_caller(): void {
        // A course the student cannot see makes context validation fail deep in
        // the call rather than at the parameter check.
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('apikey', '', 'local_ai_course_assistant');

        try {
            $result = send_message::execute((int) $this->course->id, 'Hello', 0);
            // If it returned rather than threw, the body must be clean.
            $blob = json_encode($result);
            foreach (['TypeError', 'dirroot', '.php:', 'local_ai_course_assistant\\\\'] as $leak) {
                $this->assertStringNotContainsString($leak, (string) $blob);
            }
            $this->assertFalse($result['success']);
        } catch (\moodle_exception $e) {
            // A deliberate operator-facing message is allowed through, but it
            // still must not carry a file path or a class name.
            $this->assertStringNotContainsString('.php:', $e->getMessage());
            $this->assertStringNotContainsString('TypeError', $e->getMessage());
        }
    }

    /**
     * The endpoint is registered for the mobile service.
     *
     * Recorded because the staging report proposed deleting it as unreachable.
     * No shipped client calls it, which is true and is why the TypeError went
     * unnoticed -- but it is the declared non-streaming fallback and is exposed
     * to the Moodle Mobile app, so removing it would break that.
     */
    public function test_endpoint_is_still_registered_for_mobile(): void {
        global $CFG;
        $services = [];
        $functions = [];
        require($CFG->dirroot . '/local/ai_course_assistant/db/services.php');

        $this->assertArrayHasKey('local_ai_course_assistant_send_message', $functions);
        $this->assertContains(
            MOODLE_OFFICIAL_MOBILE_SERVICE,
            $functions['local_ai_course_assistant_send_message']['services'],
            'The non-streaming fallback must stay available to the mobile app.'
        );
    }

    /**
     * The turn is audited, as the streaming path audits it.
     *
     * This endpoint wrote no message_sent row at all, so calls through the
     * mobile fallback were invisible to the audit log. The staging probe made
     * that concrete: three questions committed to a learner's visible history
     * with no reply under them and no audit row explaining why.
     */
    public function test_the_turn_is_audited(): void {
        global $DB;

        try {
            send_message::execute((int) $this->course->id, 'What is management?', 0);
        } catch (\Throwable $e) {
            // No provider configured; the audit row precedes the model call.
            $this->assertInstanceOf(\moodle_exception::class, $e);
        }

        $row = $DB->get_record('local_ai_course_assistant_audit', [
            'action' => 'message_sent',
            'userid' => $this->student->id,
        ]);
        $this->assertNotFalse($row, 'The mobile fallback must audit its turns.');
        $this->assertSame((int) $this->course->id, (int) $row->courseid);

        $details = json_decode($row->details, true);
        $this->assertSame('user', $details['role']);
        $this->assertSame(strlen('What is management?'), $details['message_length']);
    }

    // 8.4.0: the guidance rules, runtime_guard and the answer audit on this path. The answer is returned whole, so it
    // is checked like a held answer: no replace event, and a correct rule may retry once.

    /** @var string A plain question in no risky category. */
    private const PLAIN_Q = 'What is a balance sheet?';

    /** @var string A grades question (risky). */
    private const GRADE_Q = 'What grade will I get on the final exam?';

    /**
     * Answer through the in-process stub provider, with the answer safety mode set.
     *
     * @param string $mode off|watch|enforce
     * @param array $replies the stub's replies in call order (a Throwable is thrown)
     * @return void
     */
    private function use_stub(string $mode, array $replies): void {
        stub_provider::reset();
        stub_provider::$sequence = $replies;
        set_config('provider', 'stub', 'local_ai_course_assistant');
        set_config('apikey', 'stub-key', 'local_ai_course_assistant');
        set_config('history_mode', 'recency', 'local_ai_course_assistant');
        set_config('answer_safety_mode', $mode, 'local_ai_course_assistant');
    }

    /**
     * A site rule whose action is correct, on the reply (no built-in rule corrects).
     *
     * @return void
     */
    private function correct_rule(): void {
        set_config('answer_safety_rules', json_encode([[
            'id' => 'site.no_zork', 'category' => 'grades', 'kind' => 'keyword', 'value' => 'zorkmid',
            'applies_to' => 'reply', 'action' => 'correct',
        ]]), 'local_ai_course_assistant');
    }

    /**
     * The assistant message this turn stored.
     *
     * @return \stdClass
     */
    private function stored_answer(): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_ai_course_assistant_msgs',
            ['courseid' => $this->course->id, 'userid' => $this->student->id, 'role' => 'assistant'], 'id ASC');
        $this->assertCount(1, $rows, 'one assistant message per turn');
        return reset($rows);
    }

    /**
     * The audit rows for the course.
     *
     * @return \stdClass[]
     */
    private function audit_rows(): array {
        global $DB;
        return array_values($DB->get_records(answer_audit::TABLE, ['courseid' => $this->course->id], 'id ASC'));
    }

    /**
     * The rules recorded on an audit row, as [{id, action}].
     *
     * @param \stdClass $row
     * @return array[]
     */
    private function audit_rules(\stdClass $row): array {
        // Stored as canonical JSON (keys sorted); compare as {id, action}.
        return array_map(static fn(array $r): array => ['id' => $r['id'], 'action' => $r['action']],
            json_decode((string) $row->rules, true) ?: []);
    }

    public function test_send_message_answer_is_recorded_when_mode_on(): void {
        $this->use_stub('watch', ['A balance sheet lists assets, liabilities and equity.']);
        $result = send_message::execute((int) $this->course->id, self::PLAIN_Q, 0);
        $this->assertTrue($result['success']);
        $this->assertSame(['response', 'success'], array_keys($result), 'the return shape is unchanged');
        $this->assertSame('A balance sheet lists assets, liabilities and equity.', $result['response']);

        $msg = $this->stored_answer();
        $rows = $this->audit_rows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertEquals($msg->id, $row->messageid);
        $this->assertEquals($msg->conversationid, $row->conversationid);
        $this->assertEquals($this->student->id, $row->userid);
        $this->assertSame('text', $row->mode);
        $this->assertSame(self::PLAIN_Q, $row->question);
        $this->assertSame($result['response'], $row->answer, 'the audit holds what the learner got');
        $this->assertSame('stub-model', $row->model);
        $this->assertSame([['id' => 'mode.watch', 'action' => 'none'], ['id' => 'outcome.delivered', 'action' => 'none']],
            $this->audit_rules($row), 'no rule fired; the row still says the mode and what happened');
        $this->assertTrue(answer_audit::verify_chain((int) $this->course->id)['ok']);
    }

    public function test_send_message_block_rule_replaces_the_returned_text(): void {
        $this->use_stub('enforce', ['Good news: I guarantee you will pass this course.']);
        $result = send_message::execute((int) $this->course->id, self::GRADE_Q, 0);
        $this->assertTrue($result['success']);
        $this->assertSame(answer_rules::safe_message('grades'), $result['response']);
        $this->assertSame($result['response'], $this->stored_answer()->message,
            'the stored message is exactly what the learner was returned');
        $this->assertCount(1, stub_provider::$calls, 'a block never retries');
        $row = $this->audit_rows()[0];
        $this->assertSame($result['response'], $row->answer);
        $this->assertContains(['id' => 'grades.guarantee', 'action' => 'block'], $this->audit_rules($row));
        $this->assertContains('grades', json_decode((string) $row->categories, true));
    }

    public function test_send_message_warn_appends_the_warning_line(): void {
        $reply = 'You should stop taking your medication before the exam.';
        $this->use_stub('enforce', [$reply]);
        $result = send_message::execute((int) $this->course->id, self::PLAIN_Q, 0);
        $line = get_string('answer_rules:warn_line', 'local_ai_course_assistant');
        $this->assertStringStartsWith($reply, $result['response']);
        $this->assertStringContainsString($line, $result['response']);
        $this->assertSame($result['response'], $this->stored_answer()->message);
        $this->assertContains(['id' => 'advice.medical_legal_financial', 'action' => 'warn'],
            $this->audit_rules($this->audit_rows()[0]));
    }

    public function test_send_message_mode_off_changes_nothing(): void {
        global $DB;
        $reply = 'Good news: I guarantee you will pass this course.';
        $this->use_stub('off', [$reply]);
        $result = send_message::execute((int) $this->course->id, self::GRADE_Q, 0);
        $this->assertSame(['response' => $reply, 'success' => true], $result);
        $this->assertSame($reply, $this->stored_answer()->message);
        $this->assertSame([], $this->audit_rows(), 'off: no audit row');
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_audit',
            ['action' => answer_audit::RULES_FAILED_ACTION]));
        $this->assertCount(1, stub_provider::$calls);
    }

    public function test_send_message_watch_records_but_never_changes_text(): void {
        $reply = 'Good news: I guarantee you will pass this course.';
        $this->use_stub('watch', [$reply]);
        $result = send_message::execute((int) $this->course->id, self::GRADE_Q, 0);
        $this->assertSame($reply, $result['response'], 'watch never changes what the learner sees');
        $this->assertSame($reply, $this->stored_answer()->message);
        $row = $this->audit_rows()[0];
        $this->assertSame($reply, $row->answer);
        $this->assertContains(['id' => 'grades.guarantee', 'action' => 'block'], $this->audit_rules($row),
            'the rule that would have blocked is recorded');
        $this->assertCount(1, stub_provider::$calls);
    }

    public function test_send_message_record_failure_does_not_break_the_answer(): void {
        global $CFG, $DB;
        $reply = 'A balance sheet lists assets, liabilities and equity.';
        $this->use_stub('watch', [$reply]);
        // A busy course lock: record() throws locktimeout inside record_answer().
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        $factory = \core\lock\lock_config::get_lock_factory(answer_audit::LOCK_TYPE);
        $held = $factory->get_lock(answer_audit::lock_resource((int) $this->course->id), 0);
        $this->assertNotFalse($held);
        answer_audit::$locktimeout = 0;
        try {
            $result = send_message::execute((int) $this->course->id, self::PLAIN_Q, 0);
        } finally {
            $held->release();
        }
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertSame(['response' => $reply, 'success' => true], $result);
        $this->assertSame($reply, $this->stored_answer()->message);
        $this->assertSame([], $this->audit_rows());
        $logged = $DB->get_record('local_ai_course_assistant_audit', ['action' => answer_audit::RECORD_FAILED_ACTION],
            '*', MUST_EXIST);
        $this->assertSame('error', json_decode($logged->details, true)['reason']);
        $this->assertStringNotContainsString('balance', $logged->details, 'no learner text in the log');

        // Anything else in the new check that throws: the answer is delivered exactly as before, and counted.
        $DB->delete_records('local_ai_course_assistant_msgs');
        $this->use_stub('enforce', ['Good news: I guarantee you will pass this course.']);
        send_message::$beforeanswercheck = function (): void {
            throw new \RuntimeException('boom with learner text');
        };
        $result = send_message::execute((int) $this->course->id, self::GRADE_Q, 0);
        $this->assertSame(['response' => 'Good news: I guarantee you will pass this course.', 'success' => true],
            $result);
        $this->assertSame($result['response'], $this->stored_answer()->message);
        $failed = $DB->get_record('local_ai_course_assistant_audit', ['action' => answer_audit::RULES_FAILED_ACTION],
            '*', MUST_EXIST);
        $this->assertSame(['courseid' => (int) $this->course->id, 'reason' => 'rules_error',
            'error_class' => 'RuntimeException'], json_decode($failed->details, true));
        $this->assertSame(['mode.enforce', 'outcome.delivered', 'outcome.check_failed'],
            array_column($this->audit_rules($this->audit_rows()[0]), 'id'), 'the row says the check failed');
    }

    public function test_send_message_correct_retries_once_and_blocks_when_retry_fails(): void {
        global $DB;
        $this->correct_rule();

        // A retry that passes is delivered, and stored.
        $this->use_stub('enforce', ['You will get 500 zorkmid points. [SOURCE:course]',
            "Please ask your instructor about grades.\n[SOURCE:general]"]);
        $result = send_message::execute((int) $this->course->id, self::GRADE_Q, 0);
        $this->assertCount(2, stub_provider::$calls, 'one retry');
        $this->assertSame('general', $this->stored_answer()->source, 'the source pill comes from the delivered retry');
        $retrymsgs = stub_provider::$calls[1]['messages'];
        $this->assertSame('assistant', $retrymsgs[count($retrymsgs) - 2]['role']);
        $this->assertStringContainsString('site.no_zork', end($retrymsgs)['content'], 'the failure is stated');
        $this->assertSame('Please ask your instructor about grades.', $result['response']);
        $this->assertSame($result['response'], $this->stored_answer()->message);
        $this->assertEquals(200, $this->stored_answer()->prompt_tokens, 'the retry is billed on the same row');

        // A retry that still breaks the rule: blocked, never a second retry.
        $DB->delete_records('local_ai_course_assistant_msgs');
        $this->use_stub('enforce', ['You will get 500 zorkmid points.', 'Still 500 zorkmid.', 'never asked']);
        $result = send_message::execute((int) $this->course->id, self::GRADE_Q, 0);
        $this->assertCount(2, stub_provider::$calls);
        $this->assertSame(answer_rules::safe_message('grades'), $result['response']);
        $this->assertSame($result['response'], $this->stored_answer()->message);

        // A retry that throws (provider down): blocked, and the answer still returns.
        $DB->delete_records('local_ai_course_assistant_msgs');
        $this->use_stub('enforce', ['You will get 500 zorkmid points.',
            new \moodle_exception('generalexceptionmessage', 'error', '', 'provider down')]);
        $result = send_message::execute((int) $this->course->id, self::GRADE_Q, 0);
        $this->assertTrue($result['success']);
        $this->assertSame(answer_rules::safe_message('grades'), $result['response']);
        $this->assertSame($result['response'], $this->stored_answer()->message);
        $rows = $this->audit_rows();
        $this->assertSame($result['response'], end($rows)->answer);
        $this->assertContains(['id' => 'site.no_zork', 'action' => 'correct'], $this->audit_rules(end($rows)));
    }

    public function test_send_message_correct_on_a_non_risky_question_downgrades_to_warn_without_a_retry(): void {
        $this->correct_rule();
        $reply = 'A balance sheet is worth 500 zorkmid points.';
        $this->use_stub('enforce', [$reply, 'never asked']);
        $result = send_message::execute((int) $this->course->id, self::PLAIN_Q, 0);
        $this->assertCount(1, stub_provider::$calls, 'no retry: the question is not risky, so the answer is not held');
        $this->assertStringStartsWith($reply, $result['response']);
        $this->assertStringContainsString(get_string('answer_rules:warn_line', 'local_ai_course_assistant'),
            $result['response']);
        $this->assertSame($result['response'], $this->stored_answer()->message);
        $this->assertContains(['id' => 'site.no_zork', 'action' => 'correct'],
            $this->audit_rules($this->audit_rows()[0]));
    }

    public function test_send_message_retry_skipped_at_spend_cap(): void {
        global $DB;
        $this->correct_rule();
        $this->use_stub('enforce', ['You will get 500 zorkmid points.', 'Please ask your instructor.']);
        $asked = [];
        answer_flow::$spendcheck = function (int $courseid) use (&$asked): string {
            $asked[] = $courseid;
            return spend_guard::CAP_BLOCKED;
        };
        $result = send_message::execute((int) $this->course->id, self::GRADE_Q, 0);
        $this->assertSame([(int) $this->course->id], $asked);
        $this->assertCount(1, stub_provider::$calls, 'no second model call for a course at its cap');
        $this->assertSame(answer_rules::safe_message('grades'), $result['response']);
        $this->assertSame($result['response'], $this->stored_answer()->message);
        $logged = $DB->get_record('local_ai_course_assistant_audit', ['action' => answer_audit::RULES_FAILED_ACTION],
            '*', MUST_EXIST);
        $this->assertSame(['courseid' => (int) $this->course->id, 'reason' => 'retry_skipped_spend'],
            json_decode($logged->details, true));
    }

    public function test_send_message_runtime_guard_block_mode_still_wins(): void {
        global $DB;
        set_config('validators_runtime_mode', 'block', 'local_ai_course_assistant');
        // An AWS access key id: credential_leak_validator fails on it, and response_safety's three key regexes
        // (sk-, sk-ant-, AIza) do not touch it, so the guard, not the scrub, is what must remove it.
        $awskey = 'AKIA' . str_repeat('Q', 16);
        $leak = 'Here you go. My access key id is ' . $awskey . '.';
        $safe = "I generated a response but SOLA's safety review held it back (credential_leak). "
            . "Could you try rephrasing your question, or ask a course instructor for help?";
        foreach (['off', 'enforce'] as $mode) {
            $DB->delete_records('local_ai_course_assistant_msgs');
            $this->use_stub($mode, [$leak]);
            $result = send_message::execute((int) $this->course->id, self::PLAIN_Q, 0);
            $this->assertTrue($result['success']);
            // Mode off: runtime_guard's own fallback. Enforce: the failed validator is also a block rule, so the
            // rules' general safe text is delivered over it.
            $this->assertSame($mode === 'off' ? $safe : answer_rules::safe_message(answer_rules::VALIDATOR_CATEGORY),
                $result['response'], $mode . ': block mode returns the safe fallback');
            $this->assertStringNotContainsString($awskey, $result['response'], $mode);
            $this->assertSame($result['response'], $this->stored_answer()->message,
                $mode . ': the returned and stored text agree');
        }
        $rows = $this->audit_rows();
        $this->assertCount(1, $rows, 'only the enforce turn is recorded');
        $byname = array_column(json_decode((string) $rows[0]->validators, true), 'severity', 'name');
        $this->assertSame('fail', $byname['credential_leak'] ?? null, 'the credential_leak validator failed');
        $this->assertStringNotContainsString($awskey, (string) $rows[0]->answer);
        $this->assertContains(['id' => 'validator.credential_leak', 'action' => 'block'], $this->audit_rules($rows[0]));
    }

    public function test_send_message_audit_row_records_the_mode_and_what_happened(): void {
        global $DB;
        $q = 'I want to kill myself, I cannot do this anymore';
        // Watch: the wellbeing rule is recorded as block, and the row says the answer was delivered unchanged.
        $this->use_stub('watch', ['Here is a study plan for the week.']);
        $result = send_message::execute((int) $this->course->id, $q, 0);
        $this->assertSame('Here is a study plan for the week.', $result['response']);
        $rules = $this->audit_rules($this->audit_rows()[0]);
        $this->assertContains(['id' => answer_rules::WELLBEING_RULE, 'action' => 'block'], $rules);
        $this->assertContains(['id' => 'mode.watch', 'action' => 'none'], $rules);
        $this->assertContains(['id' => 'outcome.delivered', 'action' => 'none'], $rules);
        $this->assertNotContains(['id' => 'outcome.blocked', 'action' => 'none'], $rules);

        // Enforce: the same question is blocked, held and says so.
        $DB->delete_records('local_ai_course_assistant_answer_audit');
        $this->use_stub('enforce', ['Here is a study plan for the week.']);
        send_message::execute((int) $this->course->id, $q, 0);
        $ids = array_column($this->audit_rules($this->audit_rows()[0]), 'id');
        $this->assertSame(['mode.enforce', 'outcome.blocked', 'outcome.held'],
            array_values(array_filter($ids, static fn(string $id): bool => (bool) preg_match('/^(mode|outcome)\./', $id))));

        // A corrected retry.
        $DB->delete_records('local_ai_course_assistant_answer_audit');
        $this->correct_rule();
        $this->use_stub('enforce', ['You will get 500 zorkmid points.', 'Please ask your instructor about grades.']);
        send_message::execute((int) $this->course->id, self::GRADE_Q, 0);
        $rules = $this->audit_rules($this->audit_rows()[0]);
        foreach (['mode.enforce', 'outcome.corrected', 'outcome.held', 'outcome.retried'] as $id) {
            $this->assertContains(['id' => $id, 'action' => 'none'], $rules);
        }
    }

    public function test_send_message_wellbeing_question_in_enforce_returns_the_safe_text(): void {
        $this->use_stub('enforce', ['Here is a study plan for the week.']);
        $result = send_message::execute((int) $this->course->id, 'I want to kill myself, I cannot do this anymore', 0);
        $this->assertTrue($result['success']);
        $this->assertSame(answer_rules::safe_message('wellbeing'), $result['response']);
        $this->assertSame($result['response'], $this->stored_answer()->message);
        $row = $this->audit_rows()[0];
        $this->assertContains(['id' => answer_rules::WELLBEING_RULE, 'action' => 'block'], $this->audit_rules($row));
    }

    /**
     * A credential-shaped string the scrub redacts.
     *
     * @return string
     */
    private function fake_key(): string {
        return 'sk-ant-' . str_repeat('a', 40);
    }

    public function test_send_message_scrubs_leaked_prompt_and_credentials_before_saving(): void {
        global $DB;
        $reply = 'A balance sheet lists assets. ## Off-topic Detection Key ' . $this->fake_key()
            . ' KEEP RESPONSES BRIEF: done.';
        foreach (['off', 'watch', 'enforce'] as $mode) {
            $DB->delete_records('local_ai_course_assistant_msgs');
            $this->use_stub($mode, [$reply]);
            $result = send_message::execute((int) $this->course->id, self::PLAIN_Q, 0);
            $this->assertDebuggingCalled();
            $this->assertTrue($result['success']);
            $this->assertStringNotContainsString('sk-ant-', $result['response'], $mode);
            $this->assertStringNotContainsString('Off-topic Detection', $result['response'], $mode);
            $this->assertStringNotContainsString('KEEP RESPONSES BRIEF', $result['response'], $mode);
            $this->assertStringContainsString('[key redacted]', $result['response'], $mode);
            $this->assertSame($result['response'], $this->stored_answer()->message, $mode . ': stored == returned');
        }
        $rows = $this->audit_rows();
        $this->assertCount(2, $rows, 'watch and enforce are audited');
        $this->assertSame($result['response'], end($rows)->answer, 'the audit row holds the final, scrubbed text');
        $this->assertStringNotContainsString('sk-ant-', (string) end($rows)->answer);
    }

    public function test_send_message_retry_text_is_scrubbed_too(): void {
        $this->correct_rule();
        $this->use_stub('enforce', ['You will get 500 zorkmid points.',
            'Please ask your instructor about grades. Key ' . $this->fake_key() . ' ## Off-topic Detection']);
        $result = send_message::execute((int) $this->course->id, self::GRADE_Q, 0);
        $this->assertDebuggingCalled();
        $this->assertCount(2, stub_provider::$calls, 'one retry');
        $this->assertSame('Please ask your instructor about grades. Key [key redacted]', trim($result['response']));
        $this->assertSame($result['response'], $this->stored_answer()->message);
        $rows = $this->audit_rows();
        $this->assertSame($result['response'], end($rows)->answer);
    }

    public function test_send_message_scrub_runs_before_runtime_guard_and_rules(): void {
        // With the guard in block mode, a raw credential would make it replace the whole reply. The scrub
        // runs first, so the guard sees the redacted text and the reply survives (redacted).
        set_config('validators_runtime_mode', 'block', 'local_ai_course_assistant');
        $this->use_stub('off', ['Here you go. My API key is ' . $this->fake_key() . '.']);
        $result = send_message::execute((int) $this->course->id, self::PLAIN_Q, 0);
        $this->assertSame('Here you go. My API key is [key redacted].', $result['response']);
        $this->assertSame($result['response'], $this->stored_answer()->message);
    }
}
