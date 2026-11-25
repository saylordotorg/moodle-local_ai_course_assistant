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
 * Review queue sources `claim` and `rule` (8.4.0): items derived from the answer audit, one per answer, scoped to the
 * audit row's course, and the teacher's outcome on resolve.
 *
 * @package    local_ai_course_assistant
 * @category   test
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\review_queue
 */
final class review_queue_answer_sources_test extends \advanced_testcase {

    /** @var \stdClass */
    private \stdClass $course;

    /** @var \stdClass */
    private \stdClass $other;

    /** @var \stdClass */
    private \stdClass $student;

    /** @var \stdClass */
    private \stdClass $teacher;

    /** @var int conversation id counter (the audit store does not check it exists) */
    private int $conv = 1000;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->other = $gen->create_course();
        $this->student = $gen->create_user();
        $this->teacher = $gen->create_user();
        set_config('answer_safety_mode', 'enforce', 'local_ai_course_assistant');
    }

    protected function tearDown(): void {
        review_queue::$wellbeingmax = review_queue::WELLBEING_MAX;
        parent::tearDown();
    }

    /**
     * Record an answer row.
     *
     * @param int $courseid
     * @param array $rules [{id, action}]
     * @param string $question
     * @param string $answer
     * @param array $chunks [{cmid, score, ref}]
     * @return int
     */
    private function answer(int $courseid, array $rules = [], string $question = 'When is the final due?',
            string $answer = 'The final is due on Friday.', array $chunks = []): int {
        $this->conv++;
        return answer_audit::record([
            'messageid' => $this->conv,
            'conversationid' => $this->conv,
            'userid' => (int) $this->student->id,
            'courseid' => $courseid,
            'question' => $question,
            'answer' => $answer,
            'chunks' => $chunks,
            'categories' => ['deadlines'],
            'rules' => $rules,
        ]);
    }

    /**
     * Append a verdict row.
     *
     * @param int $auditid
     * @param string $status
     * @param string|null $severity
     * @param array $claims
     * @param array $rules
     * @return int
     */
    private function verdict(int $auditid, string $status, ?string $severity, array $claims = [], array $rules = []): int {
        $v = ['status' => $status, 'severity' => $severity, 'claims' => $claims];
        if ($rules) {
            $v['rules'] = $rules;
        }
        return answer_audit::append_verdict($auditid, $v);
    }

    /**
     * One contradicted claim.
     *
     * @param string $severity
     * @param string|null $ref
     * @return array
     */
    private function contradicted(string $severity = 'high', ?string $ref = null): array {
        return ['text' => 'The final is due on Friday.', 'type' => 'deadline', 'status' => 'contradicted',
            'evidence_ref' => $ref, 'severity' => $severity];
    }

    /**
     * Record a negative rating in a course (the legacy `rating` source).
     *
     * @param int $courseid
     * @return int
     */
    private function rating(int $courseid): int {
        global $DB;
        return (int) $DB->insert_record('local_ai_course_assistant_msg_ratings', (object) [
            'messageid' => 1, 'userid' => $this->student->id, 'courseid' => $courseid, 'rating' => -1,
            'rated_excerpt' => 'Unhelpful', 'timecreated' => time(),
        ]);
    }

    /**
     * The pending rows of one source.
     *
     * @param int $courseid
     * @param string $source
     * @return array
     */
    private function rows(int $courseid, string $source): array {
        return array_values(array_filter(review_queue::pending_for_course($courseid),
            static fn(array $r): bool => $r['source'] === $source));
    }

    public function test_contradicted_high_claim_with_needs_review_is_a_claim_item_in_its_course_only(): void {
        $aid = $this->answer((int) $this->course->id);
        $this->verdict($aid, 'needs_review', 'high', [$this->contradicted('high')]);

        $rows = $this->rows((int) $this->course->id, 'claim');
        $this->assertCount(1, $rows);
        $this->assertSame($aid, $rows[0]['sourceid']);
        $this->assertStringContainsString('When is the final due?', $rows[0]['summary']);
        $this->assertLessThanOrEqual(200, \core_text::strlen($rows[0]['summary']));
        $this->assertSame(anonymizer::name((int) $this->student->id), $rows[0]['who']);
        $this->assertSame([], review_queue::pending_for_course((int) $this->other->id));
    }

    public function test_a_medium_contradicted_claim_counts_and_a_low_or_supported_one_does_not(): void {
        $medium = $this->answer((int) $this->course->id);
        $this->verdict($medium, 'needs_review', 'medium', [$this->contradicted('medium')]);
        $low = $this->answer((int) $this->course->id);
        $this->verdict($low, 'needs_review', 'low', [$this->contradicted('low')]);
        $supported = $this->answer((int) $this->course->id);
        $this->verdict($supported, 'ok', 'low', [['text' => 'x', 'type' => 'general', 'status' => 'supported',
            'evidence_ref' => 'chunk:1', 'severity' => 'low']]);

        $ids = array_column($this->rows((int) $this->course->id, 'claim'), 'sourceid');
        $this->assertSame([$medium], $ids);
    }

    public function test_the_wellbeing_model_flag_is_a_claim_item_listed_first(): void {
        $wb = $this->answer((int) $this->course->id, [], 'I feel like giving up on everything');
        $this->verdict($wb, 'needs_review', 'high', [], [['id' => claim_checker::WELLBEING_MODEL_RULE,
            'action' => 'review']]);
        // Newer items that would sort first by time: a claim, a rule and a negative rating.
        $this->waitForSecond();
        $newerclaim = $this->answer((int) $this->course->id);
        $this->verdict($newerclaim, 'needs_review', 'high', [$this->contradicted()]);
        $newer = $this->answer((int) $this->course->id, [['id' => 'grades.promise', 'action' => 'block']]);
        $this->waitForSecond();
        $this->rating((int) $this->course->id);

        $rows = review_queue::pending_for_course((int) $this->course->id);
        $this->assertCount(4, $rows);
        $this->assertSame(['claim', $wb], [$rows[0]['source'], $rows[0]['sourceid']]);
        $this->assertSame('rating', $rows[1]['source']);
        $this->assertEqualsCanonicalizing([['claim', $newerclaim], ['rule', $newer]],
            [[$rows[2]['source'], $rows[2]['sourceid']], [$rows[3]['source'], $rows[3]['sourceid']]]);
        $this->assertStringContainsString(get_string('instructor_dashboard:review_kind_wellbeing',
            'local_ai_course_assistant'), $rows[0]['summary']);
    }

    public function test_a_wellbeing_flag_is_kept_first_in_a_busy_course(): void {
        $wb = $this->answer((int) $this->course->id, [], 'I want to give up');
        $this->verdict($wb, 'needs_review', 'high', [], [['id' => claim_checker::WELLBEING_MODEL_RULE,
            'action' => 'review']]);
        $this->waitForSecond();
        for ($i = 0; $i < 51; $i++) {
            $aid = $this->answer((int) $this->course->id);
            $this->verdict($aid, 'needs_review', 'high', [$this->contradicted()]);
        }
        $rows = review_queue::pending_for_course((int) $this->course->id);
        $this->assertCount(50, $rows);
        $this->assertSame(['claim', $wb], [$rows[0]['source'], $rows[0]['sourceid']]);
        $this->assertSame(1, $rows[0]['priority']);
    }

    public function test_a_rule_caught_wellbeing_question_is_listed_first_and_never_capped(): void {
        // The judge's model flag is suppressed when a wellbeing rule already fired (claim_checker::wellbeing_caught()),
        // so the rule item is the only signal: it must carry the same priority as the model flag.
        $wb = $this->answer((int) $this->course->id, [['id' => answer_rules::WELLBEING_RULE, 'action' => 'block']],
            'I want to end it all');
        $this->waitForSecond();
        for ($i = 0; $i < 51; $i++) {
            $this->answer((int) $this->course->id, [['id' => 'deadlines.date', 'action' => 'block']]);
        }
        $rows = review_queue::pending_for_course((int) $this->course->id);
        $this->assertCount(50, $rows);
        $this->assertSame(['rule', $wb], [$rows[0]['source'], $rows[0]['sourceid']]);
        $this->assertSame(1, $rows[0]['priority']);
        $this->assertSame(0, $rows[1]['priority'], 'an ordinary rule item keeps priority 0');
        $ctx = review_queue::template_context($rows, (int) $this->course->id, 7, 3);
        $this->assertTrue($ctx['review_rows'][0]['priority_high'], 'the wellbeing rule item gets the badge');
        $this->assertFalse($ctx['review_rows'][1]['priority_high']);
    }

    public function test_a_flood_of_wellbeing_rule_items_is_capped_newest_first_and_still_listed_first(): void {
        // RT4: one learner (or a script) can trip the wellbeing rule as often as they like. The list used to load
        // every one of those rows, text and all. Now: the newest WELLBEING_MAX, still ahead of everything else.
        review_queue::$wellbeingmax = 5;
        $cid = (int) $this->course->id;
        $wb = [];
        for ($i = 0; $i < 12; $i++) {
            $wb[] = $this->answer($cid, [['id' => answer_rules::WELLBEING_RULE, 'action' => 'block']],
                'I want to end it all ' . $i);
        }
        $other = $this->answer($cid, [['id' => 'deadlines.date', 'action' => 'block']]);
        $rows = review_queue::pending_for_course($cid);
        $first = array_slice($rows, 0, 5);
        $this->assertSame(array_reverse(array_slice($wb, -5)), array_column($first, 'sourceid'), 'the newest five');
        $this->assertSame([1, 1, 1, 1, 1], array_column($first, 'priority'));
        $this->assertCount(6, $rows, 'the cap, plus the ordinary item: the second pass adds no more wellbeing items');
        $this->assertSame($other, $rows[5]['sourceid']);

        // Resolving the newest two makes room for the next two: resolved rows do not use up the cap.
        foreach (array_slice($wb, -2) as $aid) {
            review_queue::mark_resolved('rule', $aid, $cid, (int) $this->teacher->id, '', 'confirmed_bad');
        }
        $rows = review_queue::pending_for_course($cid);
        $this->assertSame(array_reverse(array_slice($wb, -7, 5)), array_column(array_slice($rows, 0, 5), 'sourceid'));

        // With the newest eight resolved, the four oldest are what is left, and they are listed.
        foreach (array_slice($wb, -8, 6) as $aid) {
            review_queue::mark_resolved('rule', $aid, $cid, (int) $this->teacher->id, '', 'confirmed_bad');
        }
        $rows = review_queue::pending_for_course($cid);
        $this->assertSame(array_merge(array_reverse(array_slice($wb, 0, 4)), [$other]), array_column($rows, 'sourceid'));

        // Rows that match the wellbeing filter but make no wellbeing item (a watch-action wellbeing rule recorded
        // in Enforce) cannot push a real one out: they take places in the capped wellbeing pass, and the ordinary
        // pass still finds what it skipped, up to the cap.
        for ($i = 0; $i < 9; $i++) {
            $this->answer($cid, [['id' => 'wellbeing.site_note', 'action' => 'watch']], 'note ' . $i);
        }
        $rows = review_queue::pending_for_course($cid);
        $this->assertSame(array_merge(array_reverse(array_slice($wb, 0, 4)), [$other]), array_column($rows, 'sourceid'));
        // And the ordinary pass keeps to the cap as well.
        review_queue::$wellbeingmax = 3;
        $rows = review_queue::pending_for_course($cid);
        $this->assertSame(array_merge(array_reverse(array_slice($wb, 1, 3)), [$other]), array_column($rows, 'sourceid'));
    }

    public function test_the_wellbeing_cap_defaults(): void {
        $this->assertSame(200, review_queue::WELLBEING_MAX);
        $this->assertSame(review_queue::WELLBEING_MAX, review_queue::$wellbeingmax);
    }

    public function test_a_watch_wellbeing_rule_recorded_in_watch_stays_first_after_the_course_moves_to_enforce(): void {
        // A site wellbeing rule with the watch action fired while the course was in Watch. The claim check
        // suppressed the judge's flag for it (claim_checker::wellbeing_caught()), so this rule item is the only
        // signal. It is decided by the mode recorded on the answer row, not the course's mode when the teacher
        // opens the queue: moving the course to Enforce must not make it leave.
        $cid = (int) $this->course->id;
        course_config_manager::set_answer_safety_mode($cid, 'watch');
        $wb = $this->answer($cid, [['id' => 'wellbeing.site_extra', 'action' => 'watch'],
            ['id' => 'mode.watch', 'action' => 'none'], ['id' => 'outcome.delivered', 'action' => 'none']],
            'I feel hopeless');
        $this->waitForSecond();
        $newer = $this->answer($cid, [['id' => 'deadlines.date', 'action' => 'block'],
            ['id' => 'mode.watch', 'action' => 'none'], ['id' => 'outcome.delivered', 'action' => 'none']]);
        $plainwatch = $this->answer($cid, [['id' => 'grades.certain', 'action' => 'watch'],
            ['id' => 'mode.watch', 'action' => 'none'], ['id' => 'outcome.delivered', 'action' => 'none']]);

        $rows = review_queue::pending_for_course($cid);
        $this->assertSame(['rule', $wb, 1], [$rows[0]['source'], $rows[0]['sourceid'], $rows[0]['priority']]);

        course_config_manager::set_answer_safety_mode($cid, 'enforce');
        $rows = review_queue::pending_for_course($cid);
        $this->assertSame(['rule', $wb, 1], [$rows[0]['source'], $rows[0]['sourceid'], $rows[0]['priority']],
            'the wellbeing item left the queue, or lost its place, when the course moved to Enforce');
        $ids = array_column($rows, 'sourceid');
        $this->assertContains($newer, $ids);
        // An ordinary watch-action rule is still listed only while the course is in Watch.
        $this->assertNotContains($plainwatch, $ids);
        $html = $this->render($rows);
        $first = substr($html, strpos($html, '<tbody>'), strpos($html, '</tr>', strpos($html, '<tbody>')));
        $this->assertStringContainsString(get_string('instructor_dashboard:review_priority_high',
            'local_ai_course_assistant'), $first);

        // The other way round: recorded in Enforce (a watch-action wellbeing rule makes no item there, and the
        // judge's flag was not suppressed), it does not become a wellbeing rule item when the course moves to Watch.
        $enforced = $this->answer($cid, [['id' => 'wellbeing.site_extra', 'action' => 'watch'],
            ['id' => 'mode.enforce', 'action' => 'none'], ['id' => 'outcome.delivered', 'action' => 'none']]);
        course_config_manager::set_answer_safety_mode($cid, 'watch');
        foreach (review_queue::pending_for_course($cid) as $row) {
            if ($row['sourceid'] === $enforced) {
                $this->assertSame(0, $row['priority'], 'not a wellbeing item: the flag covers it');
            }
        }
    }

    public function test_a_claim_item_takes_its_wellbeing_priority_from_the_recorded_mode(): void {
        $cid = (int) $this->course->id;
        $wb = $this->answer($cid, [['id' => 'wellbeing.site_extra', 'action' => 'watch'],
            ['id' => 'mode.watch', 'action' => 'none']], 'I feel hopeless');
        $this->verdict($wb, 'needs_review', 'high', [$this->contradicted()]);
        // The course is in Enforce (setUp); the row says Watch.
        $rows = $this->rows($cid, 'claim');
        $this->assertSame([$wb, 1], [$rows[0]['sourceid'], $rows[0]['priority']]);
    }

    public function test_a_rule_caught_wellbeing_answer_with_a_serious_claim_is_still_first_and_uncapped(): void {
        // The wellbeing rule fired (so the judge's flag was suppressed) and the claim check found a serious
        // contradicted claim: the claim item wins the answer, and must carry the wellbeing priority.
        $wb = $this->answer((int) $this->course->id, [['id' => answer_rules::WELLBEING_RULE, 'action' => 'block']],
            'I want to end it all');
        $this->verdict($wb, 'needs_review', 'high', [$this->contradicted()]);
        $this->waitForSecond();
        for ($i = 0; $i < 51; $i++) {
            $aid = $this->answer((int) $this->course->id);
            $this->verdict($aid, 'needs_review', 'high', [$this->contradicted()]);
        }
        $rows = review_queue::pending_for_course((int) $this->course->id);
        $this->assertCount(50, $rows);
        $this->assertSame(['claim', $wb], [$rows[0]['source'], $rows[0]['sourceid']]);
        $this->assertSame(1, $rows[0]['priority']);
        $this->assertSame(0, $rows[1]['priority']);
        $this->assertSame([], array_values(array_filter($rows, static fn(array $r): bool => $r['source'] === 'rule')),
            'one item per answer');
    }

    public function test_an_activity_name_is_escaped_once(): void {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id,
            'name' => 'Q&A week 2']);
        $aid = $this->answer((int) $this->course->id, [], 'When?', 'Friday',
            [['cmid' => (int) $page->cmid, 'score' => 0.9, 'ref' => 'chunk:41']]);
        $this->verdict($aid, 'needs_review', 'high', [$this->contradicted('high', 'chunk:41')]);
        $pending = review_queue::pending_for_course((int) $this->course->id);
        $this->assertSame('Q&A week 2', $pending[0]['detail']['claims'][0]['evidence']);
        $html = $this->render($pending);
        $this->assertStringContainsString('Q&amp;A week 2', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    public function test_only_claim_and_rule_rows_get_an_outcome_select(): void {
        $this->rating((int) $this->course->id);
        $html = $this->render(review_queue::pending_for_course((int) $this->course->id));
        $this->assertStringNotContainsString('name="outcome"', $html);
        $this->assertStringContainsString('name="note"', $html);

        $this->answer((int) $this->course->id, [['id' => 'deadlines.date', 'action' => 'block']]);
        $html = $this->render(review_queue::pending_for_course((int) $this->course->id));
        $this->assertSame(1, substr_count($html, 'name="outcome"'));
        $this->assertStringContainsString('required', $html);
    }

    public function test_a_recorded_block_warn_or_correct_rule_is_a_rule_item(): void {
        $ids = [];
        foreach (['block', 'warn', 'correct'] as $action) {
            $ids[] = $this->answer((int) $this->course->id, [['id' => 'deadlines.date', 'action' => $action]]);
        }
        $this->answer((int) $this->course->id, []);
        $got = array_column($this->rows((int) $this->course->id, 'rule'), 'sourceid');
        sort($got);
        $this->assertSame($ids, $got);
        $this->assertSame([], $this->rows((int) $this->other->id, 'rule'));
        $this->assertStringContainsString('deadlines.date', $this->rows((int) $this->course->id, 'rule')[0]['summary']);
    }

    public function test_watch_only_rule_hits_are_items_only_when_the_course_mode_is_watch(): void {
        $aid = $this->answer((int) $this->course->id, [['id' => 'grades.certain', 'action' => 'watch']]);
        $this->assertSame([], $this->rows((int) $this->course->id, 'rule'));

        course_config_manager::set_answer_safety_mode((int) $this->course->id, 'watch');
        $rows = $this->rows((int) $this->course->id, 'rule');
        $this->assertCount(1, $rows);
        $this->assertSame($aid, $rows[0]['sourceid']);
    }

    public function test_one_row_per_answer_and_the_claim_wins(): void {
        $aid = $this->answer((int) $this->course->id, [['id' => 'deadlines.date', 'action' => 'block']]);
        $this->verdict($aid, 'needs_review', 'high', [$this->contradicted()]);

        $rows = review_queue::pending_for_course((int) $this->course->id);
        $this->assertCount(1, $rows);
        $this->assertSame('claim', $rows[0]['source']);
        // The rules list is in the detail.
        $this->assertSame('deadlines.date', $rows[0]['detail']['rules'][0]['id']);

        // Resolving the claim does not bring the rule item back.
        review_queue::mark_resolved('claim', $aid, (int) $this->course->id, (int) $this->teacher->id, '',
            'confirmed_bad');
        $this->assertSame([], review_queue::pending_for_course((int) $this->course->id));
    }

    public function test_failed_checks_create_nothing(): void {
        $failed = $this->answer((int) $this->course->id);
        $this->verdict($failed, 'check_failed', null, [], [['id' => claim_checker::FAILED_ERROR, 'action' => 'none']]);
        $failedrule = $this->answer((int) $this->course->id, [['id' => 'claim_check.spend', 'action' => 'block']]);
        // A later check_failed verdict replaces an earlier needs_review one: the latest verdict decides.
        // A check_failed verdict never makes an item, even one that carries a contradicted claim.
        $odd = $this->answer((int) $this->course->id);
        $this->verdict($odd, 'check_failed', 'high', [$this->contradicted()]);
        $later = $this->answer((int) $this->course->id);
        $this->verdict($later, 'needs_review', 'high', [$this->contradicted()]);
        $this->verdict($later, 'check_failed', null, [], [['id' => claim_checker::FAILED_MALFORMED, 'action' => 'none']]);

        $this->assertSame([], review_queue::pending_for_course((int) $this->course->id));
        $this->assertNotEmpty($failedrule);
    }

    public function test_sourceid_is_the_original_answer_row_when_verdict_rows_follow(): void {
        $aid = $this->answer((int) $this->course->id);
        $this->verdict($aid, 'ok', 'low', []);
        $vid = $this->verdict($aid, 'needs_review', 'high', [$this->contradicted()]);
        $rows = $this->rows((int) $this->course->id, 'claim');
        $this->assertCount(1, $rows);
        $this->assertSame($aid, $rows[0]['sourceid']);
        $this->assertNotSame($vid, $rows[0]['sourceid']);
    }

    public function test_resolving_stores_the_outcome_and_removes_the_item(): void {
        global $DB;
        $aid = $this->answer((int) $this->course->id, [['id' => 'deadlines.date', 'action' => 'block']]);
        review_queue::mark_resolved('rule', $aid, (int) $this->course->id, (int) $this->teacher->id, 'too eager',
            'rule_too_strict');
        $rec = $DB->get_record('local_ai_course_assistant_review_res', ['source' => 'rule', 'sourceid' => $aid],
            '*', MUST_EXIST);
        $this->assertSame('rule_too_strict', $rec->outcome);
        $this->assertSame('too eager', $rec->note);
        $this->assertSame([], review_queue::pending_for_course((int) $this->course->id));
    }

    public function test_an_unknown_outcome_is_rejected(): void {
        global $DB;
        $aid = $this->answer((int) $this->course->id, [['id' => 'deadlines.date', 'action' => 'block']]);
        try {
            review_queue::mark_resolved('rule', $aid, (int) $this->course->id, (int) $this->teacher->id, '', 'meh');
            $this->fail('An unknown outcome must be refused');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('review_bad_outcome', $e->errorcode);
        }
        // Not on a legacy source either.
        try {
            review_queue::mark_resolved('rating', 5, (int) $this->course->id, (int) $this->teacher->id, '', 'meh');
            $this->fail('An unknown outcome must be refused on a legacy source');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('review_bad_outcome', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_review_res'));
    }

    public function test_claim_and_rule_need_an_outcome_and_legacy_sources_do_not(): void {
        global $DB;
        $aid = $this->answer((int) $this->course->id, [['id' => 'deadlines.date', 'action' => 'block']]);
        foreach (['claim', 'rule'] as $source) {
            try {
                review_queue::mark_resolved($source, $aid, (int) $this->course->id, (int) $this->teacher->id);
                $this->fail("$source without an outcome must be refused");
            } catch (\moodle_exception $e) {
                $this->assertStringContainsString('review_outcome_required', $e->errorcode);
            }
        }
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_review_res'));

        review_queue::mark_resolved('rating', 77, (int) $this->course->id, (int) $this->teacher->id);
        $this->assertSame('', $DB->get_field('local_ai_course_assistant_review_res', 'outcome',
            ['source' => 'rating', 'sourceid' => 77]));
        review_queue::mark_resolved('offtopic', 78, (int) $this->course->id, (int) $this->teacher->id, '', 'false_alarm');
        $this->assertSame('false_alarm', $DB->get_field('local_ai_course_assistant_review_res', 'outcome',
            ['source' => 'offtopic', 'sourceid' => 78]));
    }

    public function test_a_teacher_cannot_resolve_an_item_from_another_course(): void {
        global $DB;
        $aid = $this->answer((int) $this->other->id, [['id' => 'deadlines.date', 'action' => 'block']]);
        try {
            review_queue::mark_resolved('rule', $aid, (int) $this->course->id, (int) $this->teacher->id, '',
                'false_alarm');
            $this->fail('An item of another course must be refused');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('review_not_in_course', $e->errorcode);
        }
        // A verdict row's id is not an answer either.
        $vid = $this->verdict($aid, 'needs_review', 'high', [$this->contradicted()]);
        try {
            review_queue::mark_resolved('claim', $vid, (int) $this->other->id, (int) $this->teacher->id, '',
                'false_alarm');
            $this->fail('A verdict row id must be refused');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('review_not_in_course', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_review_res'));
        $this->assertCount(1, review_queue::pending_for_course((int) $this->other->id));
    }

    public function test_a_redacted_row_lists_with_text_removed_and_no_text(): void {
        $question = 'Secret question about my grade 4471';
        $answer = 'Secret answer 9932 about the grade';
        $aid = $this->answer((int) $this->course->id, [['id' => 'grades.promise', 'action' => 'block']], $question,
            $answer);
        $this->verdict($aid, 'needs_review', 'high', [$this->contradicted()]);
        answer_audit::redact_for_conversation($this->conv);

        $rows = $this->rows((int) $this->course->id, 'claim');
        $this->assertCount(1, $rows);
        $removed = get_string('instructor_dashboard:review_text_removed', 'local_ai_course_assistant');
        $this->assertStringContainsString($removed, $rows[0]['summary']);
        $detail = $rows[0]['detail'];
        $this->assertTrue($detail['redacted']);
        $this->assertSame('needs_review', $detail['status']);
        $this->assertSame('high', $detail['severity']);
        $this->assertSame(['grades.promise'], array_column($detail['rules'], 'id'));
        $this->assertSame('contradicted', $detail['claims'][0]['status']);

        $html = $this->render(review_queue::pending_for_course((int) $this->course->id));
        $blob = json_encode($rows) . $html;
        foreach (['4471', '9932', 'Secret', 'due on Friday'] as $needle) {
            $this->assertStringNotContainsString($needle, $blob);
        }
        $this->assertStringContainsString('grades.promise', $html);
    }

    public function test_the_claim_detail_names_the_activity_behind_the_evidence(): void {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id,
            'name' => 'Unit 2 schedule']);
        $aid = $this->answer((int) $this->course->id, [], 'When is it due?', 'Friday',
            [['cmid' => (int) $page->cmid, 'score' => 0.9, 'ref' => 'chunk:41'],
             ['cmid' => 0, 'score' => 0.5, 'ref' => 'chunk:42']]);
        $this->verdict($aid, 'needs_review', 'high', [$this->contradicted('high', 'chunk:41'),
            $this->contradicted('medium', 'chunk:42')]);
        $detail = $this->rows((int) $this->course->id, 'claim')[0]['detail'];
        $this->assertSame('Unit 2 schedule', $detail['claims'][0]['evidence']);
        $this->assertStringNotContainsString('chunk:', json_encode($detail));
        $this->assertSame(2, $detail['passages']);
    }

    public function test_the_dashboard_renders_the_rows_through_the_template_and_escapes_text(): void {
        $aid = $this->answer((int) $this->course->id, [['id' => 'deadlines.date', 'action' => 'block']],
            '<script>alert("q")</script>', '<img src=x onerror=alert(1)>' . str_repeat('a', 3000));
        $this->verdict($aid, 'needs_review', 'high', [$this->contradicted()]);
        $rule = $this->answer((int) $this->course->id, [['id' => 'grades.promise', 'action' => 'warn']]);

        $html = $this->render(review_queue::pending_for_course((int) $this->course->id));
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
        $this->assertStringNotContainsString(str_repeat('a', 1600), $html);
        $this->assertStringContainsString(get_string('instructor_dashboard:review_source_claim',
            'local_ai_course_assistant'), $html);
        $this->assertStringContainsString(get_string('instructor_dashboard:review_source_rule',
            'local_ai_course_assistant'), $html);
        foreach (review_queue::OUTCOMES as $outcome) {
            $this->assertStringContainsString('value="' . $outcome . '"', $html);
        }
        $this->assertStringContainsString('name="sesskey" value="' . sesskey() . '"', $html);
        $this->assertStringContainsString('name="sourceid" value="' . $rule . '"', $html);
        $this->assertStringContainsString('name="note"', $html);
    }

    public function test_the_upgrade_step_adds_the_outcome_column_and_can_run_twice(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_ai_course_assistant_review_res');
        $field = new \xmldb_field('outcome');
        $dbman->drop_field($table, $field);
        $this->assertFalse($dbman->field_exists($table, $field));

        review_queue::add_outcome_field();
        $this->assertTrue($dbman->field_exists($table, $field));
        review_queue::add_outcome_field();
        $this->assertTrue($dbman->field_exists($table, $field));
        $columns = $DB->get_columns('local_ai_course_assistant_review_res');
        $this->assertSame(20, (int) $columns['outcome']->max_length);
        $this->assertTrue((bool) $columns['outcome']->not_null);
    }

    public function test_install_xml_declares_the_outcome_column_as_the_upgrade_does(): void {
        global $CFG;
        $xml = simplexml_load_file($CFG->dirroot . '/local/ai_course_assistant/db/install.xml');
        $found = $xml->xpath('//TABLE[@NAME="local_ai_course_assistant_review_res"]/FIELDS/FIELD[@NAME="outcome"]');
        $this->assertCount(1, $found);
        $f = $found[0];
        // CHAR NOT NULL with no DEFAULT: XMLDB refuses DEFAULT="" and gives the column '' itself.
        $this->assertSame(['char', '20', 'true'], [(string) $f['TYPE'], (string) $f['LENGTH'], (string) $f['NOTNULL']]);
        $this->assertFalse(isset($f['DEFAULT']));
        $upgrade = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/db/upgrade.php');
        $this->assertStringContainsString('review_queue::add_outcome_field()', $upgrade);
    }

    public function test_the_queue_shows_labels_and_never_a_stored_code(): void {
        $str = static fn(string $id, $a = null): string => get_string($id, 'local_ai_course_assistant', $a);
        $aid = $this->answer((int) $this->course->id, [['id' => 'deadlines.date', 'action' => 'block']]);
        $this->verdict($aid, 'needs_review', 'high', [$this->contradicted()]);
        $this->answer((int) $this->course->id, [['id' => 'grades.promise', 'action' => 'warn']], 'Will I pass?');

        $pending = review_queue::pending_for_course((int) $this->course->id);
        $html = $this->render($pending);
        $verdict = $str('instructor_dashboard:review_verdict', (object) [
            'status' => $str('answeraudit:status_needs_review'), 'severity' => $str('answeraudit:severity_high')]);
        $this->assertStringContainsString($verdict, $html);
        $this->assertStringContainsString($str('answeraudit:claim_contradicted'), $html);
        $this->assertStringContainsString('<code>deadlines.date</code>: ' . $str('answeraudit:action_block'), $html);
        $this->assertStringContainsString('grades.promise: ' . $str('answeraudit:action_warn'), $html);
        $text = strip_tags($html);
        foreach (['needs_review', 'contradicted', '(block)', '(warn)', ', high', 'high severity'] as $code) {
            $this->assertStringNotContainsString($code, $text, "{$code} in the queue");
        }
        // The rows keep the codes for callers: only what is shown is a label.
        $claim = $this->rows((int) $this->course->id, 'claim')[0];
        $this->assertSame('needs_review', $claim['detail']['status']);
        $this->assertSame('contradicted', $claim['detail']['claims'][0]['status']);
        $this->assertSame('block', $claim['detail']['rules'][0]['action']);
        $this->assertLessThanOrEqual(200, \core_text::strlen($claim['summary']));
    }

    public function test_the_queue_table_is_labelled_and_links_to_the_audit_only_when_given_one(): void {
        global $OUTPUT;
        $this->answer((int) $this->course->id, [['id' => 'grades.promise', 'action' => 'warn']]);
        $pending = review_queue::pending_for_course((int) $this->course->id);
        $html = $this->render($pending);
        $review = substr($html, strpos($html, 'id="aica-id-review"'));
        $this->assertSame(0, preg_match('~<th(?![^>]*scope=)[ >]~', $review), 'a review table header has no scope');
        $this->assertStringContainsString('<caption', $review);
        $this->assertStringContainsString('<label for="aica-review-rule-', $review);
        $this->assertStringNotContainsString('answer_audit.php', $review);

        $ctx = review_queue::template_context($pending, (int) $this->course->id, 30, 7);
        $ctx['has_review'] = true;
        $ctx['review_auditurl'] = 'https://www.example.com/moodle/local/ai_course_assistant/answer_audit.php?courseid=5';
        $html = $OUTPUT->render_from_template('local_ai_course_assistant/instructor_dashboard', $ctx);
        $this->assertStringContainsString('answer_audit.php?courseid=5">'
            . get_string('instructor_dashboard:review_audit_link', 'local_ai_course_assistant') . '</a>', $html);
    }

    public function test_the_intro_names_claim_and_rule_items(): void {
        $intro = get_string('instructor_dashboard:review_intro', 'local_ai_course_assistant');
        $this->assertStringContainsString('claim', $intro);
        $this->assertStringContainsString('guidance rule', $intro);
        $this->assertStringContainsString(get_string('instructor_dashboard:review_resolve', 'local_ai_course_assistant'),
            $intro);
    }

    public function test_a_row_written_without_an_outcome_reads_back_empty(): void {
        global $DB;
        $id = $DB->insert_record('local_ai_course_assistant_review_res', (object) ['source' => 'rating',
            'sourceid' => 9, 'courseid' => $this->course->id, 'resolved_by' => $this->teacher->id, 'timecreated' => 1]);
        $this->assertSame('', $DB->get_field('local_ai_course_assistant_review_res', 'outcome', ['id' => $id]));
    }

    /**
     * Render the dashboard template with the review rows the page builds.
     *
     * @param array $pending
     * @return string
     */
    private function render(array $pending): string {
        global $PAGE, $OUTPUT;
        $PAGE->set_url(new \moodle_url('/local/ai_course_assistant/instructor_dashboard.php'));
        $PAGE->set_context(\context_course::instance((int) $this->course->id));
        $ctx = review_queue::template_context($pending, (int) $this->course->id, 30, 7);
        $ctx['has_review'] = true;
        return $OUTPUT->render_from_template('local_ai_course_assistant/instructor_dashboard', $ctx);
    }
}
