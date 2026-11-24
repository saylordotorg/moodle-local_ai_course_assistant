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
 * A lock factory that records every resource asked for, so a test can see that the store goes through
 * \core\lock and which resource it locks. It is the real database-row factory underneath, which does not
 * hand the same lock to a second caller in one process (the MySQL and Postgres factories would).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_audit_test_lock_factory extends \core\lock\db_record_lock_factory {
    /** @var array[] each get_lock call as [type, resource] */
    public static array $calls = [];

    /** @var \Closure|null run once at the next get_lock call, before locking (a seam for race tests) */
    public static ?\Closure $onlock = null;

    /** @var string the factory type */
    private string $locktype;

    /**
     * Constructor.
     *
     * @param string $type
     */
    public function __construct($type) {
        parent::__construct($type);
        $this->locktype = $type;
    }

    /**
     * Record the call, then lock as the parent does.
     *
     * @param string $resource
     * @param int $timeout
     * @param int $maxlifetime
     * @return \core\lock\lock|bool
     */
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        self::$calls[] = [$this->locktype, $resource];
        if (self::$onlock) {
            $run = self::$onlock;
            self::$onlock = null;
            $run();
        }
        return parent::get_lock($resource, $timeout, $maxlifetime);
    }
}

/**
 * Tests for the answer audit store (8.4.0): append-only rows, the per-course hash chain, verdict rows,
 * redaction that keeps the chain checkable, and the refusal to store anything key-like.
 *
 * @package    local_ai_course_assistant
 * @category   test
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\answer_audit
 */
final class answer_audit_test extends \advanced_testcase {

    /** @var string table name */
    private const TABLE = 'local_ai_course_assistant_answer_audit';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        answer_audit_test_lock_factory::$calls = [];
        answer_audit_test_lock_factory::$onlock = null;
    }

    protected function tearDown(): void {
        answer_audit::$locktimeout = answer_audit::DEFAULT_LOCK_TIMEOUT;
        parent::tearDown();
    }

    /**
     * A full answer row.
     *
     * @param int $courseid
     * @param array $overrides
     * @return array
     */
    private function row(int $courseid, array $overrides = []): array {
        return array_merge([
            'messageid' => 101,
            'conversationid' => 11,
            'userid' => 5,
            'courseid' => $courseid,
            'cmid' => 42,
            'mode' => 'text',
            'question' => 'When is the final exam due?',
            'answer' => 'The final exam is due at the end of Unit 9.',
            'model' => 'gemini-2.5-flash',
            'provider' => 'gemini',
            'chunks' => [['cmid' => 42, 'score' => 0.81, 'ref' => 'chunk:7'], ['cmid' => 43, 'score' => 0.55, 'ref' => 'chunk:9']],
            'citations' => ['activity:42'],
            'categories' => ['deadline'],
            'rules' => [['id' => 'r1', 'action' => 'warn']],
            'validators' => ['grounding' => 'pass'],
        ], $overrides);
    }

    public function test_record_stores_row_and_hash(): void {
        global $DB;
        $id = answer_audit::record($this->row(7));

        $rec = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('answer', $rec->kind);
        $this->assertEquals(101, $rec->messageid);
        $this->assertEquals(11, $rec->conversationid);
        $this->assertEquals(5, $rec->userid);
        $this->assertEquals(7, $rec->courseid);
        $this->assertEquals(42, $rec->cmid);
        $this->assertSame('text', $rec->mode);
        $this->assertSame('When is the final exam due?', $rec->question);
        $this->assertSame('The final exam is due at the end of Unit 9.', $rec->answer);
        $this->assertSame('gemini-2.5-flash', $rec->model);
        $this->assertSame('gemini', $rec->provider);
        $this->assertEquals([['cmid' => 42, 'score' => 0.81, 'ref' => 'chunk:7'], ['cmid' => 43, 'score' => 0.55, 'ref' => 'chunk:9']],
            json_decode($rec->chunks, true));
        $this->assertSame(['deadline'], json_decode($rec->categories, true));
        $this->assertSame([['action' => 'warn', 'id' => 'r1']], json_decode($rec->rules, true));
        $this->assertEquals(0, $rec->redacted);
        $this->assertNull($rec->supersedes);
        $this->assertGreaterThan(0, (int) $rec->timecreated);

        // Hashes are lowercase sha256 hex, the first row of a course links to nothing.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rec->rowhash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rec->texthash);
        $this->assertSame('', $rec->prevhash);
        $this->assertSame(answer_audit::compute_rowhash($rec), $rec->rowhash);
        $this->assertSame(['ok' => true, 'brokenat' => null], answer_audit::verify_chain(7));
    }

    public function test_chain_links_previous_row(): void {
        global $DB;
        $a = answer_audit::record($this->row(7));
        $other = answer_audit::record($this->row(8, ['messageid' => 300]));
        $b = answer_audit::record($this->row(7, ['messageid' => 102, 'answer' => 'Another answer.']));

        $ra = $DB->get_record(self::TABLE, ['id' => $a]);
        $rb = $DB->get_record(self::TABLE, ['id' => $b]);
        $rother = $DB->get_record(self::TABLE, ['id' => $other]);

        // The chain is per course: course 8's row does not sit between course 7's two rows.
        $this->assertSame($ra->rowhash, $rb->prevhash);
        $this->assertSame('', $rother->prevhash);
        $this->assertNotSame($ra->rowhash, $rb->rowhash);
        $this->assertTrue(answer_audit::verify_chain(7)['ok']);
        $this->assertTrue(answer_audit::verify_chain(8)['ok']);
        // A course with no rows is an intact (empty) chain.
        $this->assertSame(['ok' => true, 'brokenat' => null], answer_audit::verify_chain(999));
    }

    public function test_verdict_appends_row_and_leaves_original(): void {
        global $DB;
        $id = answer_audit::record($this->row(7));
        $before = $DB->get_record(self::TABLE, ['id' => $id]);

        $vid = answer_audit::append_verdict($id, [
            'status' => 'needs_review',
            'severity' => 'high',
            'claims' => [[
                'text' => 'The final exam is due at the end of Unit 9.',
                'type' => 'date',
                'status' => 'contradicted',
                'evidence_ref' => 'chunk:7',
                'severity' => 'high',
            ]],
            'judge_model' => 'gpt-4o-mini',
            'judge_cost' => 0.00123,
        ]);

        $this->assertGreaterThan($id, $vid);
        $after = $DB->get_record(self::TABLE, ['id' => $id]);
        $this->assertEquals($before, $after, 'the original row must not change when a verdict is added');

        $v = $DB->get_record(self::TABLE, ['id' => $vid]);
        $this->assertSame('verdict', $v->kind);
        $this->assertEquals($id, $v->supersedes);
        $this->assertSame('needs_review', $v->status);
        $this->assertSame('high', $v->severity);
        $this->assertSame('gpt-4o-mini', $v->judge_model);
        $this->assertEqualsWithDelta(0.00123, (float) $v->judge_cost, 0.0000001);
        // The verdict carries the original's identity so every redaction path finds it too.
        $this->assertEquals($before->messageid, $v->messageid);
        $this->assertEquals($before->conversationid, $v->conversationid);
        $this->assertEquals($before->userid, $v->userid);
        $this->assertEquals($before->courseid, $v->courseid);
        $this->assertNull($v->question);
        $this->assertNull($v->answer);
        $claims = json_decode($v->claims, true);
        $this->assertSame('contradicted', $claims[0]['status']);
        $this->assertSame('The final exam is due at the end of Unit 9.', $claims[0]['text']);
        $this->assertSame($before->rowhash, $v->prevhash);
        $this->assertTrue(answer_audit::verify_chain(7)['ok']);

        // A bad status or an unknown original is refused, and nothing is written.
        $count = $DB->count_records(self::TABLE);
        try {
            answer_audit::append_verdict($id, ['status' => 'fine']);
            $this->fail('an unknown status must be refused');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('status', $e->getMessage());
        }
        try {
            answer_audit::append_verdict($id + 1000, ['status' => 'ok']);
            $this->fail('a verdict for a missing row must be refused');
        } catch (\dml_missing_record_exception $e) {
            $this->assertTrue(true);
        }
        try {
            answer_audit::append_verdict($vid, ['status' => 'ok']);
            $this->fail('a verdict on a verdict row must be refused');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('answer row', $e->getMessage());
        }
        $this->assertSame($count, $DB->count_records(self::TABLE));
    }

    public function test_verify_chain_detects_edited_row(): void {
        global $DB;
        $a = answer_audit::record($this->row(7));
        $b = answer_audit::record($this->row(7, ['messageid' => 102]));
        $c = answer_audit::record($this->row(7, ['messageid' => 103]));
        $this->assertTrue(answer_audit::verify_chain(7)['ok']);

        // A metadata edit.
        $DB->set_field(self::TABLE, 'model', 'gpt-4o', ['id' => $b]);
        $this->assertSame(['ok' => false, 'brokenat' => (int) $b], answer_audit::verify_chain(7));
        $DB->set_field(self::TABLE, 'model', 'gemini-2.5-flash', ['id' => $b]);
        $this->assertTrue(answer_audit::verify_chain(7)['ok']);

        // A text edit on an unredacted row (the text is not hashed directly, its texthash is).
        $DB->set_field(self::TABLE, 'answer', 'The exam has no deadline.', ['id' => $a]);
        $this->assertSame(['ok' => false, 'brokenat' => (int) $a], answer_audit::verify_chain(7));
        $DB->set_field(self::TABLE, 'answer', 'The final exam is due at the end of Unit 9.', ['id' => $a]);

        // An edit that recomputes the row's own hash still breaks the link to the next row.
        $rb = $DB->get_record(self::TABLE, ['id' => $b]);
        $rb->cmid = 99;
        $rb->rowhash = answer_audit::compute_rowhash($rb);
        $DB->update_record(self::TABLE, $rb);
        $this->assertSame(['ok' => false, 'brokenat' => (int) $c], answer_audit::verify_chain(7));

        // A deleted row breaks the link too.
        $d = answer_audit::record($this->row(8, ['messageid' => 201]));
        $e = answer_audit::record($this->row(8, ['messageid' => 202]));
        $f = answer_audit::record($this->row(8, ['messageid' => 203]));
        $DB->delete_records(self::TABLE, ['id' => $e]);
        $this->assertSame(['ok' => false, 'brokenat' => (int) $f], answer_audit::verify_chain(8));
        $this->assertNotEmpty($d);
    }

    public function test_redact_drops_text_keeps_metadata_and_chain_still_verifies(): void {
        global $DB;
        $a = answer_audit::record($this->row(7, ['messageid' => 101, 'conversationid' => 11, 'userid' => 5]));
        $va = answer_audit::append_verdict($a, [
            'status' => 'warn', 'severity' => 'medium',
            'claims' => [['text' => 'Exam is in week 9.', 'type' => 'date', 'status' => 'unsupported',
                'evidence_ref' => 'chunk:7', 'severity' => 'medium']],
            'judge_model' => 'gpt-4o-mini', 'judge_cost' => 0.0004,
        ]);
        $b = answer_audit::record($this->row(7, ['messageid' => 102, 'conversationid' => 12, 'userid' => 6]));
        $c = answer_audit::record($this->row(7, ['messageid' => 103, 'conversationid' => 13, 'userid' => 5]));
        $d = answer_audit::record($this->row(7, ['messageid' => 104, 'conversationid' => 14, 'userid' => 8]));
        $other = answer_audit::record($this->row(9, ['messageid' => 105, 'conversationid' => 15, 'userid' => 5]));
        $beforea = $DB->get_record(self::TABLE, ['id' => $a]);

        // By message: the answer row and its verdict row (same messageid).
        $this->assertSame(2, answer_audit::redact_for_messages([101]));
        $ra = $DB->get_record(self::TABLE, ['id' => $a]);
        $rva = $DB->get_record(self::TABLE, ['id' => $va]);
        $this->assertNull($ra->question);
        $this->assertNull($ra->answer);
        $this->assertEquals(1, $ra->redacted);
        $this->assertGreaterThan(0, (int) $ra->timeredacted);
        // Metadata, sources, rules and hashes are kept.
        foreach (['messageid', 'conversationid', 'userid', 'courseid', 'cmid', 'mode', 'model', 'provider',
                'chunks', 'citations', 'categories', 'rules', 'validators', 'texthash', 'prevhash', 'rowhash'] as $f) {
            $this->assertEquals($beforea->$f, $ra->$f, "field $f must survive redaction");
        }
        // Claim text is dropped, the claim's verdict stays.
        $claims = json_decode($rva->claims, true);
        $this->assertArrayNotHasKey('text', $claims[0]);
        $this->assertSame('unsupported', $claims[0]['status']);
        $this->assertSame('date', $claims[0]['type']);
        $this->assertSame('warn', $rva->status);
        $this->assertTrue(answer_audit::verify_chain(7)['ok'], 'redaction must keep the chain checkable');

        // A second redaction of the same rows changes nothing.
        $this->assertSame(0, answer_audit::redact_for_messages([101]));
        $this->assertSame(0, answer_audit::redact_for_messages([]));

        // By conversation.
        $this->assertSame(1, answer_audit::redact_for_conversation(12));
        $this->assertNull($DB->get_field(self::TABLE, 'answer', ['id' => $b]));

        // By user: every course.
        $this->assertSame(2, answer_audit::redact_for_user(5));
        $this->assertNull($DB->get_field(self::TABLE, 'answer', ['id' => $c]));
        $this->assertNull($DB->get_field(self::TABLE, 'answer', ['id' => $other]));
        $this->assertNotNull($DB->get_field(self::TABLE, 'answer', ['id' => $d]));

        // By course and user, then by course.
        $this->assertSame(0, answer_audit::redact_for_course(7, 99));
        $this->assertSame(1, answer_audit::redact_for_course(7, 8));
        $this->assertNull($DB->get_field(self::TABLE, 'answer', ['id' => $d]));
        $e = answer_audit::record($this->row(7, ['messageid' => 106]));
        $this->assertSame(1, answer_audit::redact_for_course(7));
        $this->assertNull($DB->get_field(self::TABLE, 'question', ['id' => $e]));

        $this->assertSame(['ok' => true, 'brokenat' => null], answer_audit::verify_chain(7));
        $this->assertSame(['ok' => true, 'brokenat' => null], answer_audit::verify_chain(9));

        // A redacted row that has text put back is not a valid redaction.
        $DB->set_field(self::TABLE, 'answer', 'restored', ['id' => $b]);
        $this->assertSame(['ok' => false, 'brokenat' => (int) $b], answer_audit::verify_chain(7));
    }

    public function test_two_records_same_course_do_not_fork_chain(): void {
        global $CFG, $DB;
        $CFG->lock_factory = '\\' . answer_audit_test_lock_factory::class;

        $a = answer_audit::record($this->row(7));
        $b = answer_audit::record($this->row(7, ['messageid' => 102]));

        // Each insert went through \core\lock with a per-course resource.
        $this->assertSame([
            [answer_audit::LOCK_TYPE, answer_audit::lock_resource(7)],
            [answer_audit::LOCK_TYPE, answer_audit::lock_resource(7)],
        ], answer_audit_test_lock_factory::$calls);
        $this->assertNotSame(answer_audit::lock_resource(7), answer_audit::lock_resource(8));

        $ra = $DB->get_record(self::TABLE, ['id' => $a]);
        $rb = $DB->get_record(self::TABLE, ['id' => $b]);
        $this->assertSame($ra->rowhash, $rb->prevhash);
        $this->assertSame(0, $DB->count_records_select(self::TABLE,
            'courseid = ? AND prevhash = ? AND id <> ?', [7, $ra->prevhash, $a]), 'no two rows may share a parent');

        // The lock was released after each insert: another holder can take it now.
        $factory = \core\lock\lock_config::get_lock_factory(answer_audit::LOCK_TYPE);
        $held = $factory->get_lock(answer_audit::lock_resource(7), 0);
        $this->assertNotFalse($held, 'record() must release its lock');

        // While someone else holds the course's lock, record() waits, then refuses without writing.
        answer_audit::$locktimeout = 1;
        $count = $DB->count_records(self::TABLE);
        try {
            answer_audit::record($this->row(7, ['messageid' => 103]));
            $this->fail('record() must not write without the course lock');
        } catch (\moodle_exception $e) {
            $this->assertSame('locktimeout', $e->errorcode);
        } finally {
            $held->release();
        }
        $this->assertSame($count, $DB->count_records(self::TABLE));

        // Another course is not blocked by course 7's lock.
        $held = $factory->get_lock(answer_audit::lock_resource(7), 0);
        $this->assertNotFalse($held);
        try {
            $this->assertGreaterThan(0, answer_audit::record($this->row(8, ['messageid' => 104])));
        } finally {
            $held->release();
        }

        $this->assertTrue(answer_audit::verify_chain(7)['ok']);
    }

    public function test_record_never_stores_keys(): void {
        global $DB;

        $bad = [
            'apikey at top level' => $this->row(7, ['apikey' => 'sk-proj-abcdefghijklmnop']),
            'system prompt at top level' => $this->row(7, ['systemprompt' => 'You are SOLA']),
            'unknown field' => $this->row(7, ['notes' => 'x']),
            'secret nested in a chunk' => $this->row(7, ['chunks' => [['cmid' => 1, 'score' => 0.5, 'ref' => 'c', 'secret' => 'x']]]),
            'key nested in validators' => $this->row(7, ['validators' => ['grounding' => ['api_key' => 'x']]]),
            'prompt nested in rules' => $this->row(7, ['rules' => [['id' => 'r1', 'action' => 'warn', 'prompt' => 'x']]]),
            'token nested in validators' => $this->row(7, ['validators' => ['access_token' => 'x']]),
        ];
        foreach ($bad as $label => $row) {
            try {
                answer_audit::record($row);
                $this->fail("$label must be refused");
            } catch (\coding_exception $e) {
                $this->assertTrue(true);
            }
        }

        // A verdict cannot carry one either.
        $id = answer_audit::record($this->row(7));
        foreach ([
            ['status' => 'ok', 'apikey' => 'x'],
            ['status' => 'ok', 'claims' => [['text' => 't', 'status' => 'supported', 'secret' => 'x']]],
            ['status' => 'ok', 'judge_prompt' => 'x'],
        ] as $verdict) {
            try {
                answer_audit::append_verdict($id, $verdict);
                $this->fail('a key-like verdict field must be refused');
            } catch (\coding_exception $e) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(1, $DB->count_records(self::TABLE));
        // And no column of the table is named like a key or a prompt.
        foreach (array_keys($DB->get_columns(self::TABLE)) as $column) {
            $this->assertDoesNotMatchRegularExpression('/key|secret|token|prompt|password/i', $column);
        }
    }

    public function test_course_deletion_redacts_the_course_audit_and_keeps_its_chain(): void {
        global $DB;
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $a = answer_audit::record($this->row((int) $course->id));
        $b = answer_audit::record($this->row((int) $other->id, ['messageid' => 102]));

        delete_course($course->id, false);

        $ra = $DB->get_record(self::TABLE, ['id' => $a]);
        $this->assertNotFalse($ra, 'the audit row stays when its course is deleted');
        $this->assertNull($ra->question);
        $this->assertNull($ra->answer);
        $this->assertEquals(1, $ra->redacted);
        $this->assertTrue(answer_audit::verify_chain((int) $course->id)['ok']);
        $this->assertNotNull($DB->get_field(self::TABLE, 'answer', ['id' => $b]), 'another course is untouched');
    }

    public function test_verdict_written_while_its_answer_is_redacted_keeps_no_text(): void {
        global $CFG, $DB;
        $CFG->lock_factory = '\\' . answer_audit_test_lock_factory::class;
        $a = answer_audit::record($this->row(7, ['conversationid' => 11]));

        // The judge has read the answer row (not redacted) and is about to insert its verdict when the learner
        // clears the conversation: the seam runs the redaction between append_verdict's check and its insert.
        answer_audit_test_lock_factory::$onlock = function() {
            answer_audit::redact_for_conversation(11);
        };
        $vid = answer_audit::append_verdict($a, [
            'status' => 'needs_review', 'severity' => 'high',
            'claims' => [['text' => 'The exam is on 3 March.', 'type' => 'date', 'status' => 'contradicted',
                'evidence_ref' => 'chunk:7', 'severity' => 'high']],
            'judge_model' => 'gpt-4o-mini', 'judge_cost' => 0.001,
        ]);
        $this->assertNull(answer_audit_test_lock_factory::$onlock, 'the seam must have run');

        $this->assertEquals(1, $DB->get_field(self::TABLE, 'redacted', ['id' => $a]));
        $v = $DB->get_record(self::TABLE, ['id' => $vid]);
        $this->assertEquals(1, $v->redacted, 'the verdict must end redacted');
        $this->assertStringNotContainsString('3 March', $v->claims);
        $claims = json_decode($v->claims, true);
        $this->assertArrayNotHasKey('text', $claims[0]);
        $this->assertSame('contradicted', $claims[0]['status']);
        $this->assertSame(['ok' => true, 'brokenat' => null], answer_audit::verify_chain(7));
    }

    public function test_secret_names_are_refused_and_ordinary_names_are_not(): void {
        global $DB;
        // Names a validator, category or citation may well use.
        $id = answer_audit::record($this->row(7, [
            'validators' => ['prompt_injection' => 'pass', 'tokens_used' => 812, 'keywords' => ['exam'],
                'key_terms' => ['deadline'], 'monkey' => 1, 'nested' => ['prompt_injection' => ['score' => 0.1]]],
            'categories' => ['prompt_injection', ['keywords' => ['grade']]],
            'citations' => [['tokens_used' => 3, 'ref' => 'activity:42']],
        ]));
        $rec = $DB->get_record(self::TABLE, ['id' => $id]);
        $this->assertSame('pass', json_decode($rec->validators, true)['prompt_injection']);

        foreach (['apikey', 'api_key', 'API-Key', 'secret', 'client_secret', 'password', 'system_prompt',
                'systemprompt', 'access_token', 'token', 'private_key', 'authorization'] as $name) {
            foreach ([
                'validators' => ['grounding' => ['deep' => [$name => 'x']]],
                'citations' => [[$name => 'x']],
                'categories' => [['deep' => [$name => 'x']]],
            ] as $field => $value) {
                try {
                    answer_audit::record($this->row(7, [$field => $value]));
                    $this->fail("'$name' nested in $field must be refused");
                } catch (\coding_exception $e) {
                    $this->assertStringContainsString($name, $e->getMessage());
                }
            }
        }
        $this->assertSame(1, $DB->count_records(self::TABLE));
    }

    public function test_invalid_utf8_still_writes_a_row_with_a_verifiable_chain(): void {
        global $DB;
        $id = answer_audit::record($this->row(7, [
            'question' => "Bad bytes \xFF\xFE here",
            'answer' => "Half a character \xC3\x28 and a valid caf\u{00E9}",
            'validators' => ["k\xFFey" => "v\xC3"],
        ]));
        $rec = $DB->get_record(self::TABLE, ['id' => $id]);
        $this->assertTrue(mb_check_encoding($rec->question, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($rec->answer, 'UTF-8'));
        $this->assertStringContainsString("\u{FFFD}", $rec->question);
        $this->assertStringContainsString("caf\u{00E9}", $rec->answer);
        $this->assertSame(answer_audit::compute_texthash($rec->question, $rec->answer, $rec->claims), $rec->texthash);

        $vid = answer_audit::append_verdict($id, ['status' => 'ok',
            'claims' => [['text' => "claim \xE2\x82", 'type' => 'fact', 'status' => 'supported']]]);
        $v = $DB->get_record(self::TABLE, ['id' => $vid]);
        $this->assertTrue(mb_check_encoding($v->claims, 'UTF-8'));
        $this->assertSame(['ok' => true, 'brokenat' => null], answer_audit::verify_chain(7));

        answer_audit::redact_for_course(7);
        $this->assertSame(['ok' => true, 'brokenat' => null], answer_audit::verify_chain(7));
    }

    public function test_the_lock_wait_is_short(): void {
        $this->assertLessThanOrEqual(5, answer_audit::LOCK_WAIT_SECONDS);
        $this->assertSame(answer_audit::LOCK_WAIT_SECONDS, answer_audit::$locktimeout);
    }

    public function test_rows_for_export_filters_and_decodes(): void {
        $a = answer_audit::record($this->row(7, ['userid' => 5]));
        answer_audit::append_verdict($a, ['status' => 'ok', 'severity' => 'low', 'claims' => [],
            'judge_model' => 'gpt-4o-mini', 'judge_cost' => 0.0001]);
        answer_audit::record($this->row(7, ['userid' => 6, 'messageid' => 102]));
        answer_audit::record($this->row(8, ['userid' => 5, 'messageid' => 103]));

        $rows = answer_audit::rows_for_export(7, []);
        $this->assertCount(3, $rows);
        $this->assertSame('answer', $rows[0]['kind']);
        $this->assertIsArray($rows[0]['chunks']);
        $this->assertSame('verdict', $rows[1]['kind']);
        $this->assertSame((int) $a, $rows[1]['supersedes']);

        $this->assertCount(2, answer_audit::rows_for_export(7, ['userid' => 5]));
        $this->assertCount(1, answer_audit::rows_for_export(7, ['kind' => 'verdict']));
        $this->assertCount(1, answer_audit::rows_for_export(7, ['status' => 'ok']));
        $this->assertCount(0, answer_audit::rows_for_export(7, ['since' => time() + 3600]));
        $this->assertCount(1, answer_audit::rows_for_export(7, ['limit' => 1]));

        $this->expectException(\coding_exception::class);
        answer_audit::rows_for_export(7, ['sql' => '1=1']);
    }
}
