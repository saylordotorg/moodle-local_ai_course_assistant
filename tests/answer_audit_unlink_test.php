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
 * Tests for 8.4.1's unlinking of the answer audit (Marketplace review): after a learner's erasure no audit row,
 * verdict or review note links back to them, the course chain still verifies, other learners' rows are untouched,
 * tampering is still caught, and an 8.4.0 chain is re-sealed once by the upgrade.
 *
 * @package    local_ai_course_assistant
 * @category   test
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\answer_audit
 */
final class answer_audit_unlink_test extends \advanced_testcase {

    /** @var string table name */
    private const TABLE = 'local_ai_course_assistant_answer_audit';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * One answer row's fields.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $messageid
     * @param int $convid
     * @return array
     */
    private function row(int $courseid, int $userid, int $messageid, int $convid): array {
        return [
            'messageid' => $messageid,
            'conversationid' => $convid,
            'userid' => $userid,
            'courseid' => $courseid,
            'cmid' => 42,
            'mode' => 'text',
            'question' => 'Question ' . $messageid,
            'answer' => 'Answer ' . $messageid,
            'model' => 'gemini-2.5-flash',
            'provider' => 'gemini',
            'chunks' => [['cmid' => 42, 'score' => 0.8, 'ref' => 'chunk:7']],
            'rules' => [['id' => 'r1', 'action' => 'warn']],
        ];
    }

    /**
     * A verdict with claim text.
     *
     * @return array
     */
    private function verdict(): array {
        return ['status' => 'warn', 'severity' => 'medium', 'claims' => [['text' => 'The exam is in week 9.',
            'type' => 'date', 'status' => 'unsupported', 'evidence_ref' => 'chunk:7', 'severity' => 'medium']]];
    }

    /**
     * A resolved review queue item for an answer row, with a note.
     *
     * @param int $answerid
     * @param int $courseid
     * @param string $note
     * @return int
     */
    private function review(int $answerid, int $courseid, string $note): int {
        global $DB;
        return (int) $DB->insert_record('local_ai_course_assistant_review_res', (object) [
            'source' => 'claim', 'sourceid' => $answerid, 'courseid' => $courseid, 'resolved_by' => 2,
            'note' => $note, 'outcome' => 'false_alarm', 'timecreated' => time(),
        ]);
    }

    /**
     * Rows of the table that still mention a user, a message or a conversation of the erased learner.
     *
     * @param int $userid
     * @param int[] $messageids
     * @param int[] $convids
     * @return array
     */
    private function rows_linking(int $userid, array $messageids, array $convids): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records(self::TABLE) as $rec) {
            if ((int) $rec->userid === $userid || in_array((int) $rec->messageid, $messageids, true)
                    || in_array((int) $rec->conversationid, $convids, true)) {
                $out[] = (int) $rec->id;
            }
        }
        return $out;
    }

    public function test_erasure_leaves_no_link_and_the_chain_still_verifies(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $erased = (int) $gen->create_user()->id;
        $kept = (int) $gen->create_user()->id;
        $c1 = (int) $gen->create_course()->id;
        $c2 = (int) $gen->create_course()->id;

        $a1 = answer_audit::record($this->row($c1, $erased, 9101, 9011));
        $v1 = answer_audit::append_verdict($a1, $this->verdict());
        $b1 = answer_audit::record($this->row($c1, $kept, 9102, 9012));
        $vb = answer_audit::append_verdict($b1, $this->verdict());
        $a2 = answer_audit::record($this->row($c2, $erased, 9103, 9013));
        $b2 = answer_audit::record($this->row($c1, $kept, 9104, 9012));
        $ra = $this->review($a1, $c1, 'Asked by the learner in row ' . $a1);
        $rb = $this->review($b1, $c1, 'Keep this note');
        $keptbefore = $DB->get_records_list(self::TABLE, 'id', [$b1, $vb, $b2], 'id');

        conversation_manager::delete_user_data($erased);

        $this->assertSame([], $this->rows_linking($erased, [9101, 9103], [9011, 9013]),
            'no audit row may keep the erased learner\'s user, message or conversation id');
        foreach ([$a1, $v1, $a2] as $id) {
            $rec = $DB->get_record(self::TABLE, ['id' => $id]);
            $this->assertEquals(1, $rec->redacted);
            $this->assertNull($rec->question);
            $this->assertNull($rec->answer);
            $this->assertNull($rec->linksalt, 'the salt must go, or the key could be recomputed');
            $this->assertNotEmpty($rec->linkhash);
            $this->assertTrue(answer_audit::is_unlinked($rec));
        }
        $this->assertNull($DB->get_field('local_ai_course_assistant_review_res', 'note', ['id' => $ra]));
        $this->assertSame('false_alarm',
            $DB->get_field('local_ai_course_assistant_review_res', 'outcome', ['id' => $ra]));

        // Both chains still verify.
        $this->assertSame(['ok' => true, 'brokenat' => null], answer_audit::verify_chain($c1));
        $this->assertSame(['ok' => true, 'brokenat' => null], answer_audit::verify_chain($c2));

        // The other learner's rows and note are exactly as they were.
        $this->assertEquals($keptbefore, $DB->get_records_list(self::TABLE, 'id', [$b1, $vb, $b2], 'id'));
        $this->assertSame('Keep this note',
            $DB->get_field('local_ai_course_assistant_review_res', 'note', ['id' => $rb]));

        // The export and its user filter still work for live rows; the salt is never exported.
        $rows = answer_audit::rows_for_export($c1, ['userid' => $kept]);
        $this->assertCount(3, $rows);
        $this->assertArrayNotHasKey('linksalt', $rows[0]);
        $this->assertArrayHasKey('linkhash', $rows[0]);
        $this->assertSame([], answer_audit::rows_for_export($c1, ['userid' => $erased]));
    }

    public function test_privacy_erasure_in_one_course_unlinks_only_that_course(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        $c1 = $gen->create_course();
        $c2 = $gen->create_course();
        $a1 = answer_audit::record($this->row((int) $c1->id, (int) $user->id, 9201, 9021));
        $a2 = answer_audit::record($this->row((int) $c2->id, (int) $user->id, 9202, 9022));

        $context = \context_course::instance($c1->id);
        $list = new \core_privacy\local\request\approved_contextlist($user, 'local_ai_course_assistant', [$context->id]);
        privacy\provider::delete_data_for_user($list);

        $this->assertTrue(answer_audit::is_unlinked($DB->get_record(self::TABLE, ['id' => $a1])));
        $this->assertEquals($user->id, $DB->get_field(self::TABLE, 'userid', ['id' => $a2]));
        $this->assertTrue(answer_audit::verify_chain((int) $c1->id)['ok']);
        $this->assertTrue(answer_audit::verify_chain((int) $c2->id)['ok']);
    }

    public function test_a_verdict_on_an_unlinked_answer_is_written_unlinked(): void {
        global $DB;
        $a = answer_audit::record($this->row(7, 5, 9301, 9031));
        answer_audit::redact_for_user(5);
        $v = answer_audit::append_verdict($a, $this->verdict());
        $rec = $DB->get_record(self::TABLE, ['id' => $v]);
        $this->assertTrue(answer_audit::is_unlinked($rec));
        $this->assertEquals(1, $rec->redacted);
        $this->assertTrue(answer_audit::verify_chain(7)['ok']);
    }

    public function test_tampering_with_the_link_is_still_detected(): void {
        global $DB;
        $a = answer_audit::record($this->row(7, 5, 9401, 9041));
        $b = answer_audit::record($this->row(7, 6, 9402, 9042));
        $c = answer_audit::record($this->row(7, 7, 9403, 9043));
        $this->assertTrue(answer_audit::verify_chain(7)['ok']);

        // A live row moved to another learner, message or conversation.
        foreach (['userid' => 8, 'messageid' => 1, 'conversationid' => 1] as $field => $value) {
            $old = $DB->get_field(self::TABLE, $field, ['id' => $b]);
            $DB->set_field(self::TABLE, $field, $value, ['id' => $b]);
            $this->assertSame(['ok' => false, 'brokenat' => (int) $b], answer_audit::verify_chain(7), $field);
            $DB->set_field(self::TABLE, $field, $old, ['id' => $b]);
        }
        $this->assertTrue(answer_audit::verify_chain(7)['ok']);

        // A new salt and key for the moved row: its own hash no longer matches.
        $rb = $DB->get_record(self::TABLE, ['id' => $b]);
        $rb->userid = 8;
        $rb->linksalt = str_repeat('a', 64);
        $rb->linkhash = answer_audit::link_hash($rb->linksalt, 8, $rb->messageid, $rb->conversationid);
        $DB->update_record(self::TABLE, $rb);
        $this->assertSame(['ok' => false, 'brokenat' => (int) $b], answer_audit::verify_chain(7));
        // ... and recomputing that hash breaks the link to the next row.
        $rb->rowhash = answer_audit::compute_rowhash($rb);
        $DB->update_record(self::TABLE, $rb);
        $this->assertSame(['ok' => false, 'brokenat' => (int) $c], answer_audit::verify_chain(7));

        // An unlinked row with a learner put back is not a valid redaction.
        answer_audit::redact_for_user(5);
        $DB->set_field(self::TABLE, 'userid', 5, ['id' => $a]);
        $this->assertSame(['ok' => false, 'brokenat' => (int) $a], answer_audit::verify_chain(7));
        $DB->set_field(self::TABLE, 'userid', 0, ['id' => $a]);
        $DB->set_field(self::TABLE, 'linksalt', str_repeat('b', 64), ['id' => $a]);
        $this->assertSame(['ok' => false, 'brokenat' => (int) $a], answer_audit::verify_chain(7));
    }

    /**
     * Write rows the way 8.4.0 did: raw ids in the hash, no salt or key.
     *
     * @param int $courseid
     * @param array[] $rows each [userid, messageid, conversationid, redacted]
     * @return int[] ids
     */
    private function legacy_chain(int $courseid, array $rows): array {
        global $DB;
        $prev = '';
        $ids = [];
        foreach ($rows as [$userid, $messageid, $convid, $redacted]) {
            $rec = (object) [
                'kind' => 'answer', 'supersedes' => null, 'messageid' => $messageid, 'conversationid' => $convid,
                'userid' => $userid, 'courseid' => $courseid, 'cmid' => 42, 'mode' => 'text', 'model' => 'm',
                'provider' => 'p', 'question' => $redacted ? null : 'Q' . $messageid,
                'answer' => $redacted ? null : 'A' . $messageid, 'chunks' => null, 'citations' => null,
                'categories' => null, 'rules' => null, 'validators' => null, 'status' => null, 'severity' => null,
                'claims' => null, 'judge_model' => null, 'judge_cost' => null,
                'redacted' => $redacted ? 1 : 0, 'timeredacted' => $redacted ? time() : null,
                'timecreated' => time(), 'prevhash' => $prev, 'linksalt' => null, 'linkhash' => null,
            ];
            $rec->texthash = answer_audit::compute_texthash('Q' . $messageid, 'A' . $messageid, null);
            $rec->rowhash = answer_audit::compute_legacy_rowhash($rec);
            $ids[] = (int) $DB->insert_record(self::TABLE, $rec);
            $prev = $rec->rowhash;
        }
        return $ids;
    }

    public function test_the_upgrade_reseals_an_840_chain_once(): void {
        global $DB;
        [$live, $erased, $live2] = $this->legacy_chain(7, [[5, 9501, 9051, false], [6, 9502, 9052, true],
            [5, 9503, 9051, false]]);
        $this->assertFalse(answer_audit::verify_chain(7)['ok'], 'an 8.4.0 chain is not valid under the new scheme');

        $result = answer_audit::reseal_course(7);
        $this->assertSame(['rows' => 3, 'linked' => 2, 'unlinked' => 1, 'legacy_ok' => true, 'legacy_brokenat' => null],
            $result);
        $this->assertSame(['ok' => true, 'brokenat' => null], answer_audit::verify_chain(7));

        // The redacted 8.4.0 row (an erasure that kept the userid) is unlinked now.
        $this->assertTrue(answer_audit::is_unlinked($DB->get_record(self::TABLE, ['id' => $erased])));
        $this->assertSame([], $this->rows_linking(6, [9502], [9052]));
        // Live rows keep their learner, now behind a salted key.
        $rec = $DB->get_record(self::TABLE, ['id' => $live]);
        $this->assertEquals(5, $rec->userid);
        $this->assertSame(answer_audit::link_hash($rec->linksalt, 5, 9501, 9051), $rec->linkhash);
        $this->assertNotEmpty($live2);

        // One audit log line says the chain was re-sealed.
        $logs = $DB->get_records('local_ai_course_assistant_audit', ['action' => answer_audit::RESEALED_ACTION]);
        $this->assertCount(1, $logs);
        $details = json_decode(reset($logs)->details, true);
        $this->assertSame(7, (int) $details['courseid']);
        $this->assertTrue($details['legacy_ok']);

        // A second run changes nothing and logs nothing.
        $before = $DB->get_records(self::TABLE, ['courseid' => 7], 'id');
        $this->assertSame(0, answer_audit::reseal_course(7)['rows']);
        $this->assertEquals($before, $DB->get_records(self::TABLE, ['courseid' => 7], 'id'));
        $this->assertSame(1, $DB->count_records('local_ai_course_assistant_audit',
            ['action' => answer_audit::RESEALED_ACTION]));

        // New rows chain on after the re-sealed ones, and erasure still works on them.
        answer_audit::record($this->row(7, 5, 9504, 9051));
        answer_audit::redact_for_user(5);
        $this->assertSame([], $this->rows_linking(5, [9501, 9503, 9504], [9051]));
        $this->assertTrue(answer_audit::verify_chain(7)['ok']);
    }

    public function test_a_tampered_840_chain_is_reported_by_the_reseal(): void {
        global $DB;
        [, $b] = $this->legacy_chain(8, [[5, 9601, 9061, false], [5, 9602, 9061, false], [5, 9603, 9061, false]]);
        $DB->set_field(self::TABLE, 'model', 'edited', ['id' => $b]);
        $result = answer_audit::reseal_course(8);
        $this->assertFalse($result['legacy_ok']);
        $this->assertSame($b, $result['legacy_brokenat']);
        $log = $DB->get_record('local_ai_course_assistant_audit', ['action' => answer_audit::RESEALED_ACTION]);
        $this->assertSame($b, (int) json_decode($log->details, true)['legacy_brokenat']);
    }
}
