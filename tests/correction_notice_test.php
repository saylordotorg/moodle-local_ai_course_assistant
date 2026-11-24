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
use local_ai_course_assistant\provider\stub_provider;
use local_ai_course_assistant\task\check_answer_claims;

/**
 * The correction message (8.4.0): when it is sent, what it says, where it is stored and what it never does.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\correction_notice
 */
final class correction_notice_test extends \advanced_testcase {

    /** @var string The question of every turn here; it must never appear in a correction. */
    private const QUESTION = 'When does the final exam open, my friend?';

    /** @var string The false claim. */
    private const CLAIM = 'The final exam opens on 3 May.';

    /** @var \stdClass */
    private $user;

    /** @var \stdClass */
    private $course;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        stub_provider::reset();
        claim_checker::$spendcheck = null;
        correction_notice::$beforesend = null;
        set_config('provider', 'stub', 'local_ai_course_assistant');
        set_config('apikey', 'stub-key', 'local_ai_course_assistant');
        set_config('answer_safety_mode', 'enforce', 'local_ai_course_assistant');
        $gen = $this->getDataGenerator();
        $this->user = $gen->create_user();
        $this->course = $gen->create_course();
        $gen->enrol_user($this->user->id, $this->course->id, 'student');
    }

    protected function tearDown(): void {
        claim_checker::$spendcheck = null;
        correction_notice::$beforesend = null;
        stub_provider::reset();
        parent::tearDown();
    }

    /**
     * A saved turn recorded in the audit, as sse.php records it.
     *
     * @param string $answer
     * @return array{auditid: int, convid: int, msgid: int}
     */
    private function turn(string $answer = 'The exam opens on 3 May.'): array {
        $conv = conversation_manager::get_or_create_conversation((int) $this->user->id, (int) $this->course->id);
        conversation_manager::add_message((int) $conv->id, (int) $this->user->id, (int) $this->course->id, 'user',
            self::QUESTION);
        $msgid = conversation_manager::add_message((int) $conv->id, (int) $this->user->id, (int) $this->course->id,
            'assistant', $answer);
        $auditid = answer_audit::record_answer([
            'messageid' => $msgid, 'conversationid' => (int) $conv->id, 'userid' => (int) $this->user->id,
            'courseid' => (int) $this->course->id, 'mode' => 'text', 'question' => self::QUESTION,
            'answer' => $answer, 'model' => 'stub-model', 'provider' => 'stub',
        ], [], [], [], []);
        $this->assertNotNull($auditid);
        return ['auditid' => (int) $auditid, 'convid' => (int) $conv->id, 'msgid' => $msgid];
    }

    /**
     * Append a verdict with one claim.
     *
     * @param int $auditid
     * @param string $status claim status
     * @param string $severity claim severity
     * @param string $text claim text
     * @param array $rules verdict rules
     * @return int the verdict id
     */
    private function verdict(int $auditid, string $status = 'contradicted', string $severity = 'high',
            string $text = self::CLAIM, array $rules = []): int {
        $verdict = ['status' => 'needs_review', 'severity' => $severity, 'claims' => [
            ['text' => $text, 'type' => 'date', 'status' => $status, 'evidence_ref' => null, 'severity' => $severity],
        ]];
        if ($rules) {
            $verdict['rules'] = $rules;
        }
        return answer_audit::append_verdict($auditid, $verdict);
    }

    /**
     * The correction rows.
     *
     * @return \stdClass[]
     */
    private function corrections(): array {
        global $DB;
        return array_values($DB->get_records('local_ai_course_assistant_msgs',
            ['interaction_type' => correction_notice::INTERACTION_TYPE], 'id ASC'));
    }

    /**
     * Whether a conversation still holds a message with this text.
     *
     * @param int $cid
     * @param string $text
     * @return bool
     */
    private function has_message(int $cid, string $text): bool {
        global $DB;
        return $DB->record_exists_select('local_ai_course_assistant_msgs',
            'conversationid = ? AND ' . $DB->sql_compare_text('message') . ' = ' . $DB->sql_compare_text('?'),
            [$cid, $text]);
    }

    /**
     * Run the adhoc task for an answer row, as cron would.
     *
     * @param int $auditid
     * @return void
     */
    private function run_task(int $auditid): void {
        $task = new check_answer_claims();
        $task->set_custom_data(['auditid' => $auditid]);
        ob_start();
        $task->execute();
        ob_end_clean();
    }

    public function test_sends_for_a_contradicted_high_claim_in_enforce_mode_through_the_task(): void {
        $turn = $this->turn();
        stub_provider::program_response('chat', json_encode(['claims' => [
            ['text' => self::CLAIM, 'type' => 'date', 'status' => 'contradicted', 'evidence' => 0],
        ], 'question_wellbeing' => false]));
        $this->run_task($turn['auditid']);

        $rows = $this->corrections();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('assistant', $row->role);
        $this->assertSame($turn['convid'], (int) $row->conversationid, 'the same conversation as the answer');
        $this->assertSame((int) $this->user->id, (int) $row->userid);
        $this->assertSame((int) $this->course->id, (int) $row->courseid);
        $this->assertStringStartsWith(get_string('correction:lead', 'local_ai_course_assistant'), $row->message);
        $this->assertStringContainsString(self::CLAIM, $row->message);
        $this->assertStringContainsString(get_string('correction:nextstep', 'local_ai_course_assistant'),
            $row->message);
        $this->assertStringNotContainsString('my friend', $row->message, 'the question is never quoted');
        // No spend, no provider, no model, no source pill.
        $this->assertSame(0, (int) $row->tokens_used);
        $this->assertNull($row->provider);
        $this->assertNull($row->model_name);
        $this->assertNull($row->prompt_tokens);
        $this->assertNull($row->completion_tokens);
        $this->assertNull($row->source);
        $this->assertNull($row->cmid);
    }

    public function test_direct_check_sends_too(): void {
        $turn = $this->turn();
        stub_provider::program_response('chat', json_encode(['claims' => [
            ['text' => self::CLAIM, 'type' => 'deadline', 'status' => 'contradicted', 'evidence' => 0],
        ], 'question_wellbeing' => false]));
        $result = claim_checker::check($turn['auditid']);
        $this->assertTrue($result['done']);
        $this->assertCount(1, $this->corrections());
    }

    public function test_send_returns_the_message_id_and_accepts_the_answer_row_too(): void {
        $turn = $this->turn();
        $this->verdict($turn['auditid']);
        $id = correction_notice::send($turn['auditid']);
        $this->assertNotNull($id);
        $this->assertSame((int) $this->corrections()[0]->id, $id);
    }

    public function test_not_sent_in_watch_mode(): void {
        global $DB;
        set_config('answer_safety_mode', 'watch', 'local_ai_course_assistant');
        $turn = $this->turn();
        $verdictid = $this->verdict($turn['auditid']);
        $this->assertNull(correction_notice::send($verdictid));

        stub_provider::program_response('chat', json_encode(['claims' => [
            ['text' => self::CLAIM, 'type' => 'date', 'status' => 'contradicted', 'evidence' => 0],
        ], 'question_wellbeing' => false]));
        $turn = $this->turn();
        $this->run_task($turn['auditid']);
        $this->assertSame([], $this->corrections(), 'watch records the verdict and sends nothing');
        $this->assertCount(1, $DB->get_records(answer_audit::TABLE, ['supersedes' => $turn['auditid']]));
    }

    public function test_not_sent_when_mode_is_off_for_the_course(): void {
        $turn = $this->turn();
        $verdictid = $this->verdict($turn['auditid']);
        set_config('answer_safety_mode', 'off', 'local_ai_course_assistant');
        $this->assertNull(correction_notice::send($verdictid));
    }

    public function test_not_sent_for_not_in_course_or_lower_severity(): void {
        $turn = $this->turn();
        $this->assertNull(correction_notice::send($this->verdict($turn['auditid'], 'not_in_course', 'high')));
        $this->assertNull(correction_notice::send($this->verdict($turn['auditid'], 'uncheckable', 'high')));
        $this->assertNull(correction_notice::send($this->verdict($turn['auditid'], 'supported', 'high')));
        $this->assertNull(correction_notice::send($this->verdict($turn['auditid'], 'contradicted', 'medium')));
        $this->assertNull(correction_notice::send($this->verdict($turn['auditid'], 'contradicted', 'low')));
        $this->assertSame([], $this->corrections());

        // Through the judge: a general claim contradicted is medium, a date not in the course is medium.
        stub_provider::program_response('chat', json_encode(['claims' => [
            ['text' => 'Ledgers are old.', 'type' => 'general', 'status' => 'contradicted', 'evidence' => 0],
            ['text' => 'The exam opens on 3 May.', 'type' => 'date', 'status' => 'not_in_course', 'evidence' => 0],
        ], 'question_wellbeing' => false]));
        $turn = $this->turn();
        $this->run_task($turn['auditid']);
        $this->assertSame([], $this->corrections());
    }

    public function test_not_sent_for_wellbeing_model_flag(): void {
        $turn = $this->turn();
        $flag = [['id' => claim_checker::WELLBEING_MODEL_RULE, 'action' => 'review']];
        $id = answer_audit::append_verdict($turn['auditid'], ['status' => 'needs_review', 'severity' => 'high',
            'claims' => [], 'rules' => $flag]);
        $this->assertNull(correction_notice::send($id));
        // Even with a severe false claim on the same verdict: a person reviews it first.
        $this->assertNull(correction_notice::send($this->verdict($turn['auditid'], 'contradicted', 'high',
            self::CLAIM, $flag)));

        stub_provider::program_response('chat', json_encode(['claims' => [], 'question_wellbeing' => true]));
        $turn = $this->turn();
        $this->run_task($turn['auditid']);
        $this->assertSame([], $this->corrections());
    }

    public function test_not_sent_when_the_learner_cleared_the_chat(): void {
        global $DB;
        $turn = $this->turn();
        $verdictid = $this->verdict($turn['auditid']);
        conversation_manager::clear_conversation($turn['convid'], (int) $this->user->id);
        $this->assertNull(correction_notice::send($verdictid));
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_convs'),
            'no empty conversation is created');
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_msgs'));

        // Review Focus 1 through the task: cleared while queued, nothing sent, nothing created.
        stub_provider::reset();
        $turn = $this->turn();
        conversation_manager::clear_conversation($turn['convid'], (int) $this->user->id);
        stub_provider::program_response('chat', json_encode(['claims' => [
            ['text' => self::CLAIM, 'type' => 'date', 'status' => 'contradicted', 'evidence' => 0],
        ], 'question_wellbeing' => false]));
        $this->run_task($turn['auditid']);
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_convs'));
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_msgs'));
    }

    public function test_not_sent_when_the_answer_row_is_redacted(): void {
        global $DB;
        $turn = $this->turn();
        $verdictid = $this->verdict($turn['auditid']);
        // The answer's text was dropped (for example a cap trim or an erasure) while the message stayed.
        answer_audit::redact_for_messages([$turn['msgid']]);
        $this->assertNull(correction_notice::send($verdictid));
        $this->assertSame([], $this->corrections());
        $this->assertSame(1, $DB->count_records('local_ai_course_assistant_convs'));
    }

    public function test_not_sent_when_only_the_answer_row_is_redacted(): void {
        global $DB;
        // A redaction landing between the verdict insert and send(): the answer row is redacted, the verdict still
        // holds its claim text and the message is still there. The answer row decides.
        $turn = $this->turn();
        $verdictid = $this->verdict($turn['auditid']);
        $DB->set_field(answer_audit::TABLE, 'redacted', 1, ['id' => $turn['auditid']]);
        $this->assertNull(correction_notice::send($verdictid));
        $this->assertSame([], $this->corrections());
    }

    public function test_not_sent_when_the_message_is_gone_or_the_conversation_is_not_the_learners(): void {
        global $DB;
        $turn = $this->turn();
        $verdictid = $this->verdict($turn['auditid']);
        $DB->delete_records('local_ai_course_assistant_msgs', ['id' => $turn['msgid']]);
        $this->assertNull(correction_notice::send($verdictid));

        $turn = $this->turn();
        $verdictid = $this->verdict($turn['auditid']);
        $other = $this->getDataGenerator()->create_user();
        $DB->set_field('local_ai_course_assistant_convs', 'userid', $other->id, ['id' => $turn['convid']]);
        $this->assertNull(correction_notice::send($verdictid), 'the learner must own the conversation');
        $this->assertSame([], $this->corrections());
    }

    public function test_not_sent_when_the_claim_text_is_missing(): void {
        $turn = $this->turn();
        $this->assertNull(correction_notice::send($this->verdict($turn['auditid'], 'contradicted', 'high', '')));
        $this->assertNull(correction_notice::send($this->verdict($turn['auditid'], 'contradicted', 'high',
            "  \n [SOLA_NEXT]a||b[/SOLA_NEXT] ")));
        $this->assertSame([], $this->corrections());
    }

    public function test_rate_limit_one_per_learner_per_ten_minutes(): void {
        global $DB;
        $first = $this->turn();
        $id = correction_notice::send($this->verdict($first['auditid']));
        $this->assertNotNull($id);

        $second = $this->turn();
        $secondverdict = $this->verdict($second['auditid']);
        $this->assertNull(correction_notice::send($secondverdict), 'a second correction within 10 minutes');

        // The limit is per learner, across courses.
        $course2 = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($this->user->id, $course2->id, 'student');
        $conv2 = conversation_manager::get_or_create_conversation((int) $this->user->id, (int) $course2->id);
        $msg2 = conversation_manager::add_message((int) $conv2->id, (int) $this->user->id, (int) $course2->id,
            'assistant', 'x');
        $audit2 = answer_audit::record_answer(['messageid' => $msg2, 'conversationid' => (int) $conv2->id,
            'userid' => (int) $this->user->id, 'courseid' => (int) $course2->id, 'question' => 'q', 'answer' => 'x'],
            [], [], [], []);
        $this->assertNull(correction_notice::send($this->verdict((int) $audit2)));

        // Ten minutes later it sends.
        $DB->set_field('local_ai_course_assistant_msgs', 'timecreated',
            time() - correction_notice::RATE_LIMIT_SECONDS - 1, ['id' => $id]);
        $this->assertNotNull(correction_notice::send($secondverdict));
        $this->assertCount(2, $this->corrections());

        // Another learner is not limited by this one.
        $this->assertFalse(correction_notice::recently_sent((int) $this->getDataGenerator()->create_user()->id));
    }

    public function test_a_learners_own_row_tagged_correction_does_not_count_for_the_rate_limit(): void {
        $conv = conversation_manager::get_or_create_conversation((int) $this->user->id, (int) $this->course->id);
        // A user row can never be a correction; only assistant rows are looked at.
        conversation_manager::add_message((int) $conv->id, (int) $this->user->id, (int) $this->course->id, 'user',
            'hello', 0, '', null, null, null, correction_notice::INTERACTION_TYPE);
        $this->assertFalse(correction_notice::recently_sent((int) $this->user->id));
    }

    public function test_sse_never_takes_correction_from_the_request(): void {
        global $CFG;
        $src = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/sse.php');
        $this->assertMatchesRegularExpression(
            '/if \(\$interactiontype === correction_notice::INTERACTION_TYPE\) \{\s*\$interactiontype = \'chat\';/',
            $src, 'a learner must not be able to tag their own rows as corrections (they would escape the cap)');
    }

    public function test_claim_text_is_plain_and_trimmed_to_300_characters(): void {
        $turn = $this->turn();
        $long = "**Bold** <b>html</b>\n\n# heading [link](javascript:alert(1)) `code` [SOLA_NEXT]a||b[/SOLA_NEXT] "
            . str_repeat('é', 400);
        $id = correction_notice::send($this->verdict($turn['auditid'], 'contradicted', 'high', $long));
        $this->assertNotNull($id);
        $claim = correction_notice::plain_claim($long);
        $this->assertLessThanOrEqual(correction_notice::MAX_CLAIM_CHARS, \core_text::strlen($claim));
        $this->assertStringNotContainsString('SOLA_NEXT', $claim);
        $this->assertStringNotContainsString("\n", $claim);
        $this->assertStringNotContainsString('[', $claim);
        $this->assertStringNotContainsString('*', $claim);
        $this->assertStringNotContainsString('`', $claim);
        $message = $this->corrections()[0]->message;
        $this->assertStringContainsString($claim, $message);
        // The whole message stays short: lead, the trimmed claim and the next step.
        $this->assertLessThan(500, \core_text::strlen($message));
    }

    public function test_failure_in_send_never_fails_the_claim_check(): void {
        global $DB;
        correction_notice::$beforesend = static function(): void {
            throw new \RuntimeException('planted: ' . self::CLAIM);
        };
        $turn = $this->turn();
        stub_provider::program_response('chat', json_encode(['claims' => [
            ['text' => self::CLAIM, 'type' => 'date', 'status' => 'contradicted', 'evidence' => 0],
        ], 'question_wellbeing' => false]));
        $result = claim_checker::check($turn['auditid']);
        $this->assertTrue($result['done']);
        $this->assertSame('needs_review', $result['status']);
        $this->assertDebuggingCalled('correction_notice: not sent: RuntimeException');
        $this->assertSame([], $this->corrections());

        $logs = array_values($DB->get_records('local_ai_course_assistant_audit',
            ['action' => correction_notice::FAILED_ACTION]));
        $this->assertCount(1, $logs);
        $details = json_decode($logs[0]->details, true);
        $this->assertSame(correction_notice::FAILED_REASON, $details['reason']);
        $this->assertSame('RuntimeException', $details['error_class']);
        $this->assertStringNotContainsString('3 May', $logs[0]->details, 'no learner text in the log');
        $this->assertStringNotContainsString('friend', $logs[0]->details);

        // The task path too: it finishes normally.
        $turn = $this->turn();
        stub_provider::program_response('chat', json_encode(['claims' => [
            ['text' => self::CLAIM, 'type' => 'date', 'status' => 'contradicted', 'evidence' => 0],
        ], 'question_wellbeing' => false]));
        $this->run_task($turn['auditid']);
        $this->assertDebuggingCalled('correction_notice: not sent: RuntimeException');
        $this->assertCount(2, $DB->get_records('local_ai_course_assistant_audit',
            ['action' => correction_notice::FAILED_ACTION]));
    }

    public function test_the_audit_store_is_untouched(): void {
        global $DB;
        $turn = $this->turn();
        $verdictid = $this->verdict($turn['auditid']);
        $before = $DB->get_records(answer_audit::TABLE, null, 'id ASC');
        $this->assertNotNull(correction_notice::send($verdictid));
        $this->assertEquals($before, $DB->get_records(answer_audit::TABLE, null, 'id ASC'),
            'no audit row is written or changed by a correction');
        $this->assertTrue(answer_audit::verify_chain((int) $this->course->id)['ok']);
    }

    public function test_correction_appears_in_the_next_history_load(): void {
        $turn = $this->turn();
        $id = correction_notice::send($this->verdict($turn['auditid']));
        $this->setUser($this->user);
        $result = get_history::execute((int) $this->course->id);
        $result = \core_external\external_api::clean_returnvalue(get_history::execute_returns(), $result);
        $last = end($result['messages']);
        $this->assertSame($id, (int) $last['id']);
        $this->assertSame('assistant', $last['role']);
        $this->assertStringStartsWith(get_string('correction:lead', 'local_ai_course_assistant'), $last['message']);
        $this->assertSame('', $last['source_type'], 'no source pill on a correction');
        $this->assertSame('', $last['lang_ask']);
    }

    public function test_correction_is_in_the_prompt_history(): void {
        set_config('history_mode', 'recency', 'local_ai_course_assistant');
        $turn = $this->turn();
        correction_notice::send($this->verdict($turn['auditid']));
        $conv = conversation_manager::get_or_create_conversation((int) $this->user->id, (int) $this->course->id);
        conversation_manager::add_message((int) $conv->id, (int) $this->user->id, (int) $this->course->id, 'user',
            'Next question');
        $history = history_selector::select_for_api((int) $conv->id, 'Next question');
        $roles = array_column($history, 'role');
        $this->assertSame(['user', 'assistant', 'assistant', 'user'], $roles);
        $this->assertStringStartsWith(get_string('correction:lead', 'local_ai_course_assistant'),
            $history[2]['content']);

        // Semantic selection: the correction is kept even though it has no user turn to score.
        $messages = array_values(conversation_manager::get_messages((int) $conv->id));
        $pairs = history_selector::pair_messages($messages);
        $this->assertCount(3, $pairs);
        $this->assertTrue(!empty($pairs[1]['correction']));
        $this->assertSame(1.0, history_selector::pair_score($pairs[1], [1.0, 0.0], [0.0, 1.0]),
            'a correction always scores as relevant');
        $scored = [
            ['pair' => $pairs[0], 'score' => 0.0],
            ['pair' => $pairs[1], 'score' => history_selector::pair_score($pairs[1], [1.0], [])],
            ['pair' => $pairs[2], 'score' => 0.0],
        ];
        $kept = history_selector::flatten(history_selector::pick($scored, 5, 0.2));
        $this->assertSame(['assistant', 'user'], array_column($kept, 'role'));
    }

    public function test_corrections_are_not_counted_by_the_cap_and_are_not_trimmed(): void {
        global $DB;
        $conv = conversation_manager::get_or_create_conversation((int) $this->user->id, (int) $this->course->id);
        $cid = (int) $conv->id;
        $uid = (int) $this->user->id;
        $courseid = (int) $this->course->id;
        $add = function(string $role, string $text, string $type = 'chat') use ($DB, $cid, $uid, $courseid): int {
            static $t = 1000000;
            $id = conversation_manager::add_message($cid, $uid, $courseid, $role, $text, 0, '', null, null, null,
                $type);
            $DB->set_field('local_ai_course_assistant_msgs', 'timecreated', $t++, ['id' => $id]);
            return $id;
        };
        $corrections = [];
        for ($i = 0; $i < 50; $i++) {
            $add('user', "q{$i}");
            $add('assistant', "a{$i}");
            if ($i % 10 === 0) {
                $corrections[] = $add('assistant', "c{$i}", correction_notice::INTERACTION_TYPE);
            }
        }
        $normal = "conversationid = ? AND role IN ('user', 'assistant') AND interaction_type <> ?";
        $this->assertSame(100, $DB->count_records_select('local_ai_course_assistant_msgs', $normal,
            [$cid, correction_notice::INTERACTION_TYPE]), 'exactly at the cap: nothing trimmed yet');
        $this->assertSame(105, $DB->count_records('local_ai_course_assistant_msgs', ['conversationid' => $cid]));

        // Two more ordinary rows: exactly the two oldest ordinary rows go, never a correction.
        $add('user', 'q50');
        $add('assistant', 'a50');
        $this->assertSame(100, $DB->count_records_select('local_ai_course_assistant_msgs', $normal,
            [$cid, correction_notice::INTERACTION_TYPE]));
        $this->assertFalse($this->has_message($cid, 'q0'));
        $this->assertFalse($this->has_message($cid, 'a0'));
        $this->assertTrue($this->has_message($cid, 'q1'));
        foreach ($corrections as $id) {
            $this->assertTrue($DB->record_exists('local_ai_course_assistant_msgs', ['id' => $id]),
                'a correction is never trimmed by the cap');
        }

        // A clear removes them.
        conversation_manager::clear_conversation($cid, $uid);
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_msgs', ['conversationid' => $cid]));
    }

    public function test_the_cap_still_redacts_the_audit_text_of_trimmed_answers(): void {
        global $DB;
        $turn = $this->turn();
        correction_notice::send($this->verdict($turn['auditid']));
        $conv = conversation_manager::get_or_create_conversation((int) $this->user->id, (int) $this->course->id);
        for ($i = 0; $i < 50; $i++) {
            conversation_manager::add_message((int) $conv->id, (int) $this->user->id, (int) $this->course->id,
                $i % 2 ? 'assistant' : 'user', "m{$i}");
        }
        // 2 + 50 = 52 ordinary rows: no trim yet. 49 more pairs push the first turn out.
        for ($i = 0; $i < 98; $i++) {
            conversation_manager::add_message((int) $conv->id, (int) $this->user->id, (int) $this->course->id,
                $i % 2 ? 'assistant' : 'user', "n{$i}");
        }
        $this->assertFalse($DB->record_exists('local_ai_course_assistant_msgs', ['id' => $turn['msgid']]));
        $row = $DB->get_record(answer_audit::TABLE, ['id' => $turn['auditid']]);
        $this->assertSame(1, (int) $row->redacted, 'the trimmed answer lost its audit text');
        $this->assertNull($row->answer);
        $this->assertCount(1, $this->corrections(), 'the correction outlives the answer it corrected');
    }

    public function test_unanswered_monitor_does_not_count_a_correction_as_an_answer(): void {
        $turn = $this->turn();
        correction_notice::send($this->verdict($turn['auditid']));
        $stats = unanswered_monitor::course_stats(24);
        $row = array_values(array_filter($stats, fn($r) => $r['courseid'] === (int) $this->course->id))[0];
        $this->assertSame(1, (int) $row['asked']);
        $this->assertSame(1, (int) $row['answered']);
    }
}

