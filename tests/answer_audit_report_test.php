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
 * The answer audit page (8.4.0): access, filters, the detail view's course scope, export (redaction, chain header,
 * formula injection) and the counts report.
 *
 * @package    local_ai_course_assistant
 * @category   test
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\answer_audit_report
 */
final class answer_audit_report_test extends \advanced_testcase {

    /** @var \stdClass */
    private \stdClass $course;

    /** @var \stdClass */
    private \stdClass $other;

    /** @var \stdClass */
    private \stdClass $student;

    /** @var \stdClass */
    private \stdClass $teacher;

    /** @var \stdClass */
    private \stdClass $otherteacher;

    /** @var int conversation id counter */
    private int $conv = 5000;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->other = $gen->create_course();
        $this->student = $gen->create_user();
        $this->teacher = $gen->create_user();
        $this->otherteacher = $gen->create_user();
        $gen->enrol_user($this->student->id, $this->course->id, 'student');
        $gen->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $gen->enrol_user($this->otherteacher->id, $this->other->id, 'editingteacher');
        set_config('answer_safety_mode', 'enforce', 'local_ai_course_assistant');
    }

    /**
     * Record an answer row.
     *
     * @param int $courseid
     * @param array $rules
     * @param string $question
     * @param int|null $cmid
     * @return int
     */
    private function answer(int $courseid, array $rules = [], string $question = 'When is the final due?',
            ?int $cmid = null): int {
        $this->conv++;
        return answer_audit::record([
            'messageid' => $this->conv,
            'conversationid' => $this->conv,
            'userid' => (int) $this->student->id,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'question' => $question,
            'answer' => 'The final is due on Friday.',
            'categories' => ['deadlines'],
            'rules' => $rules,
        ]);
    }

    /**
     * Append a verdict.
     *
     * @param int $aid
     * @param string $status
     * @param array $claims
     * @return int
     */
    private function verdict(int $aid, string $status, array $claims = []): int {
        return answer_audit::append_verdict($aid, ['status' => $status, 'severity' => 'high', 'claims' => $claims]);
    }

    /**
     * One claim.
     *
     * @param string $status
     * @param string $text
     * @return array
     */
    private function claim(string $status, string $text = 'Claim text SECRETCLAIM'): array {
        return ['text' => $text, 'type' => 'deadline', 'status' => $status, 'evidence_ref' => null,
            'severity' => 'high'];
    }

    /**
     * Ids of export rows.
     *
     * @param array $rows
     * @return int[]
     */
    private function ids(array $rows): array {
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        sort($ids);
        return $ids;
    }

    // Access.

    public function test_a_student_is_refused(): void {
        $this->setUser($this->student);
        $this->expectException(\required_capability_exception::class);
        answer_audit_report::require_view((int) $this->course->id);
    }

    public function test_another_courses_teacher_is_refused(): void {
        $this->setUser($this->otherteacher);
        $this->expectException(\required_capability_exception::class);
        answer_audit_report::require_view((int) $this->course->id);
    }

    public function test_the_courses_teacher_and_a_site_admin_may_view(): void {
        $this->setUser($this->teacher);
        $ctx = answer_audit_report::require_view((int) $this->course->id);
        $this->assertSame((int) $this->course->id, (int) $ctx->instanceid);
        $this->setAdminUser();
        $this->assertSame((int) $this->other->id, (int) answer_audit_report::require_view((int) $this->other->id)->instanceid);
    }

    // Detail scope.

    public function test_detail_refuses_an_answer_of_another_course_and_a_verdict_id(): void {
        $mine = $this->answer((int) $this->course->id);
        $vid = $this->verdict($mine, 'ok');
        $theirs = $this->answer((int) $this->other->id, [], 'Their question');

        $detail = answer_audit_report::detail((int) $this->course->id, $mine);
        $this->assertSame($mine, $detail['id']);
        $this->assertSame('When is the final due?', $detail['question']);

        foreach ([$theirs, $vid, 999999] as $bad) {
            try {
                answer_audit_report::detail((int) $this->course->id, $bad);
                $this->fail("detail() accepted id {$bad}");
            } catch (\moodle_exception $e) {
                $this->assertSame('answeraudit:notfound', $e->errorcode);
            }
        }
    }

    // Filters.

    public function test_export_respects_the_verdict_filter_using_the_latest_verdict(): void {
        $cid = (int) $this->course->id;
        $flagged = $this->answer($cid);
        $this->verdict($flagged, 'needs_review', [$this->claim('contradicted')]);
        $cleared = $this->answer($cid);
        $this->verdict($cleared, 'needs_review');
        $this->verdict($cleared, 'ok');
        $unchecked = $this->answer($cid);
        $this->answer((int) $this->other->id);

        $this->assertSame([$flagged], $this->ids(answer_audit_report::export_rows($cid, ['verdict' => 'needs_review'])));
        $this->assertSame([$cleared], $this->ids(answer_audit_report::export_rows($cid, ['verdict' => 'ok'])));
        $this->assertSame([$unchecked], $this->ids(answer_audit_report::export_rows($cid, ['verdict' => 'unchecked'])));
        $this->assertSame([$flagged, $cleared, $unchecked], $this->ids(answer_audit_report::export_rows($cid, [])));
    }

    public function test_export_respects_the_rule_and_date_filters(): void {
        global $DB;
        $cid = (int) $this->course->id;
        $ruled = $this->answer($cid, [['id' => 'deadlines.date', 'action' => 'warn']]);
        $other = $this->answer($cid, [['id' => 'grades.promise', 'action' => 'block']]);
        $old = $this->answer($cid);
        $DB->set_field(answer_audit::TABLE, 'timecreated', make_timestamp(2026, 1, 10, 12), ['id' => $old]);

        $this->assertSame([$ruled], $this->ids(answer_audit_report::export_rows($cid, ['rule' => 'deadlines.date'])));
        // A rule id is matched exactly, not as a substring.
        $this->assertSame([], $this->ids(answer_audit_report::export_rows($cid, ['rule' => 'deadlines'])));

        $filters = answer_audit_report::parse_filters('', '', '2026-01-01', '2026-01-31');
        $this->assertSame([$old], $this->ids(answer_audit_report::export_rows($cid, $filters)));
        $filters = answer_audit_report::parse_filters('', '', '2026-02-01', '');
        $this->assertSame([$ruled, $other], $this->ids(answer_audit_report::export_rows($cid, $filters)));
    }

    public function test_parse_filters_drops_unknown_values(): void {
        $f = answer_audit_report::parse_filters('bogus', 'x', 'not a date', '');
        $this->assertSame('', $f['verdict']);
        $this->assertSame(0, $f['since']);
        $this->assertSame(0, $f['until']);
    }

    public function test_rule_choices_list_content_rules_of_this_course_only(): void {
        $this->answer((int) $this->course->id, [['id' => 'deadlines.date', 'action' => 'warn'],
            ['id' => 'claim_check.error', 'action' => 'none']]);
        $this->answer((int) $this->other->id, [['id' => 'other.rule', 'action' => 'block']]);
        $this->assertSame(['deadlines.date'], answer_audit_report::rule_choices((int) $this->course->id));
    }

    // Export content.

    public function test_export_omits_redacted_text(): void {
        global $DB;
        $cid = (int) $this->course->id;
        $aid = $this->answer($cid, [], 'SECRETQUESTION about the exam');
        $this->verdict($aid, 'needs_review', [$this->claim('contradicted')]);
        answer_audit::redact_for_course($cid);

        // A row marked redacted that still carries text (restored by hand, or tampered) never exports it either.
        $kept = $this->answer($cid, [], 'TAMPEREDTEXT question');
        $this->verdict($kept, 'needs_review', [$this->claim('contradicted', 'TAMPEREDCLAIM text')]);
        $DB->set_field(answer_audit::TABLE, 'redacted', 1, ['id' => $kept]);

        $rows = answer_audit_report::export_rows($cid, []);
        $this->assertCount(2, $rows);
        $chain = answer_audit::verify_chain($cid);
        $csv = answer_audit_report::to_csv($rows, $chain);
        $json = answer_audit_report::to_json($rows, $chain, []);
        foreach (['SECRETQUESTION', 'SECRETCLAIM', 'TAMPEREDTEXT', 'TAMPEREDCLAIM', 'due on Friday'] as $needle) {
            $this->assertStringNotContainsString($needle, $csv, "{$needle} in CSV");
            $this->assertStringNotContainsString($needle, $json, "{$needle} in JSON");
        }
        foreach ($rows as $row) {
            $this->assertSame(1, $row['redacted']);
        }
        // The metadata is still there: the claim's status survives redaction.
        $this->assertStringContainsString('contradicted', $csv);
    }

    public function test_export_carries_text_of_an_unredacted_row(): void {
        $cid = (int) $this->course->id;
        $aid = $this->answer($cid, [['id' => 'deadlines.date', 'action' => 'warn']], 'Plain question');
        $this->verdict($aid, 'needs_review', [$this->claim('contradicted', 'Visible claim')]);
        $rows = answer_audit_report::export_rows($cid, []);
        $this->assertSame('Plain question', $rows[0]['question']);
        $this->assertSame('The final is due on Friday.', $rows[0]['answer']);
        $this->assertSame('needs_review', $rows[0]['verdict']);
        $this->assertSame('deadlines.date:warn', $rows[0]['rules']);
        $this->assertStringContainsString('Visible claim', $rows[0]['claims']);
        $this->assertStringNotContainsString((string) $this->student->id . ',', json_encode(array_keys($rows[0])));
        $this->assertArrayNotHasKey('userid', $rows[0], 'the export names learners by pseudonym only');
        $this->assertSame(anonymizer::name((int) $this->student->id), $rows[0]['learner']);
    }

    public function test_export_starts_with_the_chain_result(): void {
        global $DB;
        $cid = (int) $this->course->id;
        $this->answer($cid);
        $second = $this->answer($cid);

        $rows = answer_audit_report::export_rows($cid, []);
        $csv = answer_audit_report::to_csv($rows, answer_audit::verify_chain($cid));
        $first = strtok($csv, "\n");
        $this->assertSame('chain_verified,yes,broken_at,', trim($first));
        $decoded = json_decode(answer_audit_report::to_json($rows, answer_audit::verify_chain($cid), []), true);
        $this->assertTrue($decoded['chain']['verified']);

        // Edit a stored answer behind the store's back: the chain breaks at that row and the export says so.
        $DB->set_field(answer_audit::TABLE, 'answer', 'Edited later', ['id' => $second]);
        $chain = answer_audit::verify_chain($cid);
        $csv = answer_audit_report::to_csv(answer_audit_report::export_rows($cid, []), $chain);
        $this->assertSame("chain_verified,no,broken_at,{$second}", trim(strtok($csv, "\n")));
        $decoded = json_decode(answer_audit_report::to_json($rows, $chain, []), true);
        $this->assertFalse($decoded['chain']['verified']);
        $this->assertSame($second, $decoded['chain']['broken_at']);
    }

    public function test_csv_neutralises_formula_cells(): void {
        $cid = (int) $this->course->id;
        foreach (['=HYPERLINK("http://x")', '+1+1', '-2+3', '@SUM(A1)', "\tTAB", "\rCR"] as $q) {
            $this->answer($cid, [], $q);
        }
        $csv = answer_audit_report::to_csv(answer_audit_report::export_rows($cid, []), answer_audit::verify_chain($cid));
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        fgetcsv($handle);
        $header = fgetcsv($handle);
        $qcol = array_search('question', $header, true);
        $this->assertNotFalse($qcol);
        $seen = 0;
        while (($cells = fgetcsv($handle)) !== false) {
            foreach ($cells as $cell) {
                $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', (string) $cell, 'unescaped formula cell');
            }
            $this->assertStringStartsWith("'", $cells[$qcol]);
            $seen++;
        }
        fclose($handle);
        $this->assertSame(6, $seen);
    }

    public function test_export_has_no_row_cap_and_streams_every_row_oldest_first(): void {
        $cid = (int) $this->course->id;
        $ids = [];
        for ($i = 0; $i < 7; $i++) {
            $ids[] = $this->answer($cid, [], "question {$i}");
        }
        $rows = answer_audit_report::export_rows($cid, []);
        $this->assertSame($ids, array_map(static fn(array $r): int => $r['id'], $rows), 'every row, oldest first');

        $chain = answer_audit::verify_chain($cid);
        $handle = fopen('php://temp', 'r+');
        $this->assertSame(7, answer_audit_report::write_csv($handle, answer_audit_report::export_iter($cid, []), $chain));
        rewind($handle);
        $lines = array_filter(explode("\n", (string) stream_get_contents($handle)));
        fclose($handle);
        $this->assertCount(9, $lines, 'chain row, header row and 7 answers');
        $this->assertStringStartsWith(end($ids) . ',', end($lines), 'the newest answer is in the export');

        $handle = fopen('php://temp', 'r+');
        $this->assertSame(7, answer_audit_report::write_json($handle, answer_audit_report::export_iter($cid, []),
            $chain, []));
        rewind($handle);
        $decoded = json_decode((string) stream_get_contents($handle), true);
        fclose($handle);
        $this->assertTrue($decoded['chain']['verified']);
        $this->assertSame($ids, array_column($decoded['rows'], 'id'));
    }

    public function test_a_redacted_latest_verdict_hides_the_answer_everywhere(): void {
        global $DB, $OUTPUT, $PAGE;
        $cid = (int) $this->course->id;
        $PAGE->set_url('/local/ai_course_assistant/answer_audit.php', ['courseid' => $cid]);
        $aid = $this->answer($cid, [], 'VERDICTREDACTEDQ');
        $vid = $this->verdict($aid, 'needs_review', [$this->claim('contradicted', 'VERDICTREDACTEDCLAIM')]);
        $DB->set_field(answer_audit::TABLE, 'redacted', 1, ['id' => $vid]);

        $row = answer_audit_report::export_rows($cid, [])[0];
        $this->assertSame(1, $row['redacted']);
        $this->assertSame('', $row['question']);
        $this->assertSame('', $row['answer']);
        $this->assertStringNotContainsString('VERDICTREDACTEDCLAIM', $row['claims']);

        $detail = answer_audit_report::detail($cid, $aid);
        $this->assertTrue($detail['redacted']);
        $this->assertSame('', $detail['question']);
        $this->assertSame('', $detail['answer']);

        $ctx = answer_audit_report::template_context($cid, answer_audit_report::parse_filters('', '', '', ''), $aid);
        $html = $OUTPUT->render_from_template('local_ai_course_assistant/answer_audit', $ctx);
        $this->assertStringNotContainsString('VERDICTREDACTEDQ', $html);
        $this->assertStringNotContainsString('VERDICTREDACTEDCLAIM', $html);
    }

    public function test_rule_choices_include_rules_recorded_only_on_a_verdict(): void {
        $cid = (int) $this->course->id;
        $aid = $this->answer($cid);
        answer_audit::append_verdict($aid, ['status' => 'needs_review', 'severity' => 'high', 'claims' => [],
            'rules' => [['id' => 'wellbeing.model_flag', 'action' => 'flag']]]);
        $this->assertSame(['wellbeing.model_flag'], answer_audit_report::rule_choices($cid));
        $this->assertSame([$aid], $this->ids(answer_audit_report::export_rows($cid, ['rule' => 'wellbeing.model_flag'])));
    }

    public function test_detail_shows_both_hashes(): void {
        global $OUTPUT, $PAGE;
        $cid = (int) $this->course->id;
        $PAGE->set_url('/local/ai_course_assistant/answer_audit.php', ['courseid' => $cid]);
        $first = $this->answer($cid);
        $second = $this->answer($cid);
        $d1 = answer_audit_report::detail($cid, $first);
        $ctx = answer_audit_report::template_context($cid, answer_audit_report::parse_filters('', '', '', ''), $second);
        $html = $OUTPUT->render_from_template('local_ai_course_assistant/answer_audit', $ctx);
        $this->assertStringContainsString($ctx['detail']['rowhash'], $html);
        $this->assertSame($d1['rowhash'], $ctx['detail']['prevhash']);
        $this->assertStringContainsString('<code>' . $d1['rowhash'] . '</code>', $html);
    }

    public function test_status_entries_show_in_the_detail_only(): void {
        global $OUTPUT, $PAGE;
        $cid = (int) $this->course->id;
        $PAGE->set_url('/local/ai_course_assistant/answer_audit.php', ['courseid' => $cid]);
        // What sse.php and send_message record for a watch-mode answer the wellbeing rule would have blocked.
        $aid = $this->answer($cid, [['id' => 'wellbeing.crisis', 'action' => 'block'],
            ['id' => 'mode.watch', 'action' => 'none'], ['id' => 'outcome.delivered', 'action' => 'none']]);
        $plain = $this->answer($cid, [['id' => 'mode.enforce', 'action' => 'none'],
            ['id' => 'outcome.delivered', 'action' => 'none']]);

        $d = answer_audit_report::detail($cid, $aid);
        $this->assertSame('wellbeing.crisis:block', $d['rules']);
        $this->assertSame('mode.watch, outcome.delivered', $d['flow']);
        $ctx = answer_audit_report::template_context($cid, answer_audit_report::parse_filters('', '', '', ''), $aid);
        $html = $OUTPUT->render_from_template('local_ai_course_assistant/answer_audit', $ctx);
        $this->assertStringContainsString(get_string('answeraudit:flow', 'local_ai_course_assistant'), $html);
        // The page shows what the entries mean; the stored ids stay in detail() (above) and off the page.
        $this->assertStringContainsString(get_string('answeraudit:flow_mode_watch', 'local_ai_course_assistant'), $html);
        $this->assertStringContainsString(get_string('answeraudit:flow_outcome_delivered', 'local_ai_course_assistant'),
            $html);
        $this->assertStringNotContainsString('mode.watch', $html);
        $this->assertStringNotContainsString('outcome.delivered', $html);

        // Never a rule choice, a counted rule, or a rule in the list and export.
        $this->assertSame(['wellbeing.crisis'], answer_audit_report::rule_choices($cid));
        $this->assertSame(['wellbeing.crisis'], array_column(answer_audit_report::report($cid)['rules'], 'rule'));
        $this->assertSame([$aid], $this->ids(answer_audit_report::export_rows($cid, ['rule' => 'wellbeing.crisis'])));
        $this->assertSame([], answer_audit_report::export_rows($cid, ['rule' => 'mode.watch']));
        foreach (answer_audit_report::export_rows($cid, []) as $row) {
            $this->assertStringNotContainsString('mode.', $row['rules']);
            $this->assertStringNotContainsString('outcome.', $row['rules']);
        }
        $this->assertSame('', answer_audit_report::detail($cid, $plain)['rules']);
        // And no review item for a row that recorded only status entries.
        $this->assertSame([], array_values(array_filter(review_queue::pending_for_course($cid),
            static fn(array $r): bool => $r['sourceid'] === $plain)));
    }

    // Counts report.

    public function test_report_lists_unsupported_topics_by_activity_from_the_latest_verdict(): void {
        $cid = (int) $this->course->id;
        $page = $this->getDataGenerator()->create_module('page', ['course' => $cid, 'name' => 'Unit 1 <b>Intro</b>']);
        $a1 = $this->answer($cid, [], 'q1', (int) $page->cmid);
        $this->verdict($a1, 'needs_review', [$this->claim('contradicted'), $this->claim('not_in_course'),
            $this->claim('supported')]);
        $a2 = $this->answer($cid, [], 'q2', (int) $page->cmid);
        $this->verdict($a2, 'warn', [$this->claim('not_in_course')]);
        $a3 = $this->answer($cid);
        $this->verdict($a3, 'warn', [$this->claim('contradicted')]);
        // Superseded verdict: its claims no longer count.
        $a4 = $this->answer($cid);
        $this->verdict($a4, 'needs_review', [$this->claim('contradicted'), $this->claim('contradicted')]);
        $this->verdict($a4, 'ok', []);
        // Another course's claims never count here.
        $theirs = $this->answer((int) $this->other->id);
        $this->verdict($theirs, 'needs_review', [$this->claim('contradicted')]);

        $report = answer_audit_report::report($cid);
        $topics = $report['topics'];
        $this->assertCount(2, $topics);
        $this->assertSame('Unit 1 <b>Intro</b>', $topics[0]['topic'], 'raw name: the template escapes it');
        $this->assertSame(1, $topics[0]['contradicted']);
        $this->assertSame(2, $topics[0]['not_in_course']);
        $this->assertSame(3, $topics[0]['total']);
        $this->assertSame(get_string('answeraudit:coursewide', 'local_ai_course_assistant'), $topics[1]['topic']);
        $this->assertSame(1, $topics[1]['total']);
    }

    public function test_report_ranks_rules_by_hits_with_false_alarm_rates(): void {
        $cid = (int) $this->course->id;
        $r1 = [['id' => 'deadlines.date', 'action' => 'warn']];
        $r2 = [['id' => 'grades.promise', 'action' => 'block']];
        $hits = [];
        for ($i = 0; $i < 4; $i++) {
            $hits[] = $this->answer($cid, $r1);
        }
        $g = $this->answer($cid, array_merge($r2, [['id' => 'claim_check.spend', 'action' => 'none']]));
        $this->answer($cid);
        $this->answer((int) $this->other->id, $r1);

        review_queue::mark_resolved('rule', $hits[0], $cid, (int) $this->teacher->id, '', 'false_alarm');
        review_queue::mark_resolved('rule', $hits[1], $cid, (int) $this->teacher->id, '', 'confirmed_bad');
        review_queue::mark_resolved('rule', $g, $cid, (int) $this->teacher->id, '', 'rule_too_strict');

        $rules = answer_audit_report::report($cid)['rules'];
        $this->assertSame(['deadlines.date', 'grades.promise'], array_column($rules, 'rule'));
        $this->assertSame(4, $rules[0]['hits']);
        $this->assertEqualsWithDelta(4 / 6, $rules[0]['hitrate'], 0.0001);
        $this->assertSame(2, $rules[0]['reviewed']);
        $this->assertSame(1, $rules[0]['falsealarms']);
        $this->assertEqualsWithDelta(0.5, $rules[0]['falsealarmrate'], 0.0001);
        $this->assertSame(1, $rules[1]['hits']);
        $this->assertSame(1, $rules[1]['falsealarms']);
        $this->assertEqualsWithDelta(1.0, $rules[1]['falsealarmrate'], 0.0001);
    }

    public function test_an_outcome_counts_only_for_an_answer_where_one_content_rule_fired(): void {
        $cid = (int) $this->course->id;
        $tid = (int) $this->teacher->id;
        $a = ['id' => 'deadlines.date', 'action' => 'warn'];
        $b = ['id' => 'grades.promise', 'action' => 'block'];
        // Both rules fired; the teacher's "too strict" was about one of them, and the report cannot tell which.
        $both = $this->answer($cid, [$a, $b]);
        review_queue::mark_resolved('rule', $both, $cid, $tid, '', 'rule_too_strict');
        // One rule fired (plus a status entry, which is not a content rule): counted.
        $one = $this->answer($cid, [$b, ['id' => 'claim_check.spend', 'action' => 'none']]);
        review_queue::mark_resolved('rule', $one, $cid, $tid, '', 'confirmed_bad');
        // The same rule twice on one answer is still one rule.
        $twice = $this->answer($cid, [$a, $a]);
        review_queue::mark_resolved('rule', $twice, $cid, $tid, '', 'false_alarm');

        $rules = array_column(answer_audit_report::report($cid)['rules'], null, 'rule');
        $this->assertSame(2, $rules['deadlines.date']['hits']);
        $this->assertSame(1, $rules['deadlines.date']['reviewed'], 'the two-rule answer is not counted');
        $this->assertSame(1, $rules['deadlines.date']['falsealarms']);
        $this->assertSame(2, $rules['grades.promise']['hits']);
        $this->assertSame(1, $rules['grades.promise']['reviewed']);
        $this->assertSame(0, $rules['grades.promise']['falsealarms'], 'the other rule gets no false alarm from it');
    }

    public function test_an_outcome_on_the_claim_item_counts_for_the_single_rule_it_hid(): void {
        $cid = (int) $this->course->id;
        $tid = (int) $this->teacher->id;
        $rule = [['id' => 'deadlines.date', 'action' => 'warn']];
        // A claim item exists for the answer, so its rule item is hidden; the teacher resolved the claim item.
        $hidden = $this->answer($cid, $rule);
        $this->verdict($hidden, 'needs_review', [$this->claim('contradicted')]);
        review_queue::mark_resolved('claim', $hidden, $cid, $tid, '', 'rule_too_strict');
        // Both items resolved: the rule item's own outcome wins.
        $own = $this->answer($cid, $rule);
        review_queue::mark_resolved('rule', $own, $cid, $tid, '', 'confirmed_bad');
        review_queue::mark_resolved('claim', $own, $cid, $tid, '', 'false_alarm');
        // A claim outcome on an answer where two rules fired is not counted either.
        $two = $this->answer($cid, array_merge($rule, [['id' => 'grades.promise', 'action' => 'block']]));
        review_queue::mark_resolved('claim', $two, $cid, $tid, '', 'false_alarm');

        $rules = array_column(answer_audit_report::report($cid)['rules'], null, 'rule');
        $this->assertSame(3, $rules['deadlines.date']['hits']);
        $this->assertSame(2, $rules['deadlines.date']['reviewed']);
        $this->assertSame(1, $rules['deadlines.date']['falsealarms']);
        $this->assertSame(0, $rules['grades.promise']['reviewed']);
    }

    public function test_the_to_date_ends_at_the_next_days_start_on_a_daylight_saving_day(): void {
        $this->setTimezone('America/New_York');
        // 2026-03-08 is 23 hours long in New York, 2026-11-01 25 hours.
        foreach (['2026-03-08' => [2026, 3, 9], '2026-11-01' => [2026, 11, 2], '2026-02-10' => [2026, 2, 11]] as $day => $next) {
            $f = answer_audit_report::parse_filters('', '', '', $day);
            $this->assertSame(make_timestamp($next[0], $next[1], $next[2]) - 1, $f['until'], $day);
        }
    }

    public function test_report_rule_with_no_review_has_no_false_alarm_rate(): void {
        $cid = (int) $this->course->id;
        $this->answer($cid, [['id' => 'deadlines.date', 'action' => 'warn']]);
        $rules = answer_audit_report::report($cid)['rules'];
        $this->assertSame(0, $rules[0]['reviewed']);
        $this->assertNull($rules[0]['falsealarmrate']);
    }

    // Rendering.

    public function test_page_escapes_learner_text_and_activity_names(): void {
        global $OUTPUT, $PAGE;
        $cid = (int) $this->course->id;
        $PAGE->set_url('/local/ai_course_assistant/answer_audit.php', ['courseid' => $cid]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $cid, 'name' => '<img src=x onerror=1>']);
        $aid = $this->answer($cid, [['id' => '<b>rule</b>', 'action' => 'warn']], '<script>alert(1)</script>',
            (int) $page->cmid);
        $this->verdict($aid, 'needs_review', [$this->claim('contradicted', '<i>claim</i>')]);

        $filters = answer_audit_report::parse_filters('', '', '', '');
        $ctx = answer_audit_report::template_context($cid, $filters, $aid);
        $html = $OUTPUT->render_from_template('local_ai_course_assistant/answer_audit', $ctx);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>rule</b>', $html);
        $this->assertStringNotContainsString('<i>claim</i>', $html);
        $this->assertStringContainsString('&lt;i&gt;claim&lt;/i&gt;', $html);
    }

    public function test_page_shows_no_text_for_a_redacted_answer(): void {
        global $OUTPUT, $PAGE;
        $cid = (int) $this->course->id;
        $PAGE->set_url('/local/ai_course_assistant/answer_audit.php', ['courseid' => $cid]);
        $aid = $this->answer($cid, [], 'SECRETQUESTION');
        answer_audit::redact_for_course($cid);
        $ctx = answer_audit_report::template_context($cid, answer_audit_report::parse_filters('', '', '', ''), $aid);
        $html = $OUTPUT->render_from_template('local_ai_course_assistant/answer_audit', $ctx);
        $this->assertStringNotContainsString('SECRETQUESTION', $html);
        $this->assertStringContainsString(get_string('answeraudit:text_removed', 'local_ai_course_assistant'), $html);

        // A row marked redacted that still carries text (a hand restore, an edit) never shows it, list or detail.
        global $DB;
        $kept = $this->answer($cid, [], 'TAMPEREDQUESTION');
        $this->verdict($kept, 'needs_review', [$this->claim('contradicted', 'TAMPEREDCLAIM')]);
        $DB->set_field(answer_audit::TABLE, 'redacted', 1, ['id' => $kept]);
        $ctx = answer_audit_report::template_context($cid, answer_audit_report::parse_filters('', '', '', ''), $kept);
        $html = $OUTPUT->render_from_template('local_ai_course_assistant/answer_audit', $ctx);
        foreach (['TAMPEREDQUESTION', 'TAMPEREDCLAIM', 'due on Friday'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "{$needle} on the page");
        }
    }

    // Readable labels, empty states and links (8.4.0 polish).

    /**
     * Render the page for a course.
     *
     * @param int $courseid
     * @param array $filters
     * @param int $detailid
     * @return string
     */
    private function page(int $courseid, array $filters, int $detailid = 0): string {
        global $OUTPUT, $PAGE;
        $PAGE->set_url('/local/ai_course_assistant/answer_audit.php', ['courseid' => $courseid]);
        return $OUTPUT->render_from_template('local_ai_course_assistant/answer_audit',
            answer_audit_report::template_context($courseid, $filters, $detailid));
    }

    public function test_the_page_shows_labels_and_never_a_stored_code(): void {
        $cid = (int) $this->course->id;
        $str = static fn(string $id, $a = null): string => get_string($id, 'local_ai_course_assistant', $a);
        $aid = $this->answer($cid, [['id' => 'deadlines.date', 'action' => 'warn'],
            ['id' => 'mode.enforce', 'action' => 'none'], ['id' => 'outcome.warned', 'action' => 'none']]);
        $this->verdict($aid, 'needs_review', [$this->claim('contradicted', 'Visible claim'),
            $this->claim('not_in_course', 'Other claim')]);

        $html = $this->page($cid, answer_audit_report::parse_filters('', '', '', ''), $aid);
        foreach (['answeraudit:claim_contradicted', 'answeraudit:claim_not_in_course', 'answeraudit:type_deadline',
                'answeraudit:severity_high', 'answeraudit:flow_mode_enforce', 'answeraudit:flow_outcome_warned',
                'answer_rules:category_deadlines'] as $id) {
            $this->assertStringContainsString($str($id), $html, $id);
        }
        $this->assertStringContainsString('deadlines.date: ' . $str('answeraudit:action_warn'), $html);
        $this->assertStringContainsString($str('instructor_dashboard:review_verdict', (object) [
            'status' => $str('answeraudit:status_needs_review'), 'severity' => $str('answeraudit:severity_high')]), $html);
        // What a reader sees (tags, and with them the filter's option values, removed) carries no stored code.
        $text = strip_tags($html);
        foreach (['needs_review', 'not_in_course', 'contradicted', 'deadlines.date:warn', 'mode.', 'outcome.',
                ' deadline ', ' high', 'check_failed'] as $code) {
            $this->assertStringNotContainsString($code, $text, "{$code} on the page");
        }
        // The exports and detail() keep the codes.
        $row = answer_audit_report::export_rows($cid, [])[0];
        $this->assertSame('needs_review', $row['verdict']);
        $this->assertSame('deadlines.date:warn', $row['rules']);
        $this->assertStringContainsString('contradicted/deadline/high', $row['claims']);
        $this->assertSame('contradicted', answer_audit_report::detail($cid, $aid)['verdicts'][0]['claims'][0]['status']);
    }

    public function test_a_code_without_a_string_is_shown_as_it_is(): void {
        $this->assertSame('brand_new', answer_labels::severity('brand_new'));
        $this->assertSame('outcome.brand_new', answer_labels::flow('outcome.brand_new'));
        $this->assertSame('<b>x</b>', answer_labels::claim_status('<b>x</b>'));
        $this->assertSame('', answer_labels::claim_type(''));
        $this->assertSame(get_string('answeraudit:status_ok', 'local_ai_course_assistant'),
            answer_labels::verdict('ok', ''));
    }

    public function test_every_stored_code_has_a_label(): void {
        $codes = [];
        foreach (answer_audit_report::verdict_values() as $c) {
            $codes[$c] = answer_labels::status($c);
        }
        foreach (['high', 'medium', 'low'] as $c) {
            $codes[$c] = answer_labels::severity($c);
        }
        foreach (claim_checker::CLAIM_STATUSES as $c) {
            $codes[$c] = answer_labels::claim_status($c);
        }
        foreach (claim_checker::CLAIM_TYPES as $c) {
            $codes[$c] = answer_labels::claim_type($c);
        }
        foreach (answer_rules::RULE_ACTIONS as $c) {
            $codes[$c] = answer_labels::action($c);
        }
        foreach (answer_rules::CATEGORIES as $c) {
            $codes[$c] = answer_labels::category($c);
        }
        foreach (answer_rules::MODES as $c) {
            $codes['mode.' . $c] = answer_labels::flow('mode.' . $c);
        }
        // Every outcome.* id answer_flow can record.
        preg_match_all("/'(outcome\\.[a-z_]+)'/", file_get_contents(__DIR__ . '/../classes/answer_flow.php'), $m);
        $this->assertGreaterThanOrEqual(9, count(array_unique($m[1])));
        foreach (array_unique($m[1]) as $c) {
            $codes[$c] = answer_labels::flow($c);
        }
        // The live voice entries voice_safety::check_turn() records.
        foreach ([voice_safety::MARKER_RULE, voice_safety::TRUNCATED_RULE, voice_safety::QUESTION_ONLY_RULE] as $c) {
            $codes[$c] = answer_labels::flow($c);
        }
        foreach ($codes as $code => $label) {
            $this->assertNotSame($code, $label, "{$code} has no label");
            $this->assertNotSame('', $label);
        }
    }

    public function test_an_empty_course_says_so_and_a_filter_with_no_match_offers_the_reset(): void {
        $cid = (int) $this->course->id;
        $str = static fn(string $id): string => get_string($id, 'local_ai_course_assistant');
        $html = $this->page($cid, answer_audit_report::parse_filters('', '', '', ''));
        $this->assertStringContainsString($str('answeraudit:empty'), $html);
        $this->assertStringNotContainsString($str('answeraudit:norows'), $html);
        $this->assertStringNotContainsString('alert-link', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringContainsString($str('answeraudit:topics_none'), $html);
        $this->assertStringContainsString($str('answeraudit:rules_none'), $html);

        $this->answer($cid);
        $html = $this->page($cid, answer_audit_report::parse_filters('blocked', '', '', ''));
        $this->assertStringContainsString($str('answeraudit:norows'), $html);
        $this->assertStringNotContainsString($str('answeraudit:empty'), $html);
        $this->assertStringContainsString('class="alert-link"', $html);
    }

    public function test_the_page_links_to_the_dashboard_resets_filters_and_labels_the_exports(): void {
        $cid = (int) $this->course->id;
        $this->answer($cid);
        $html = $this->page($cid, answer_audit_report::parse_filters('ok', '', '2026-01-01', ''));
        $this->assertStringContainsString('/local/ai_course_assistant/instructor_dashboard.php?courseid=' . $cid . '"',
            $html);
        // The reset link is the page with the course only.
        $this->assertStringContainsString('href="https://www.example.com/moodle/local/ai_course_assistant/'
            . 'answer_audit.php?courseid=' . $cid . '" class="btn btn-secondary"', $html);
        // Exports: visible CSV and JSON, a full accessible name, the filters and the sesskey kept.
        $this->assertMatchesRegularExpression('~<a class="btn btn-sm btn-secondary" href="[^"]*verdict=ok[^"]*format=csv'
            . '[^"]*sesskey=' . sesskey() . '" aria-label="Download CSV">CSV</a>~', $html);
        $this->assertMatchesRegularExpression('~href="[^"]*format=json[^"]*" aria-label="Download JSON">JSON</a>~', $html);
        // Every field has a label, every table header a scope.
        foreach (['aica-aa-verdict', 'aica-aa-rule', 'aica-aa-from', 'aica-aa-to'] as $id) {
            $this->assertStringContainsString('<label for="' . $id . '"', $html);
            $this->assertStringContainsString('id="' . $id . '"', $html);
        }
        $this->assertSame(0, preg_match('~<th(?![^>]*scope=)[ >]~', $html), 'a table header has no scope');
        $this->assertStringNotContainsString('{{{', file_get_contents(__DIR__ . '/../templates/answer_audit.mustache'));
    }
}
