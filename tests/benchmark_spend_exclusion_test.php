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
 * Benchmark spend belongs in the ledger and nowhere near a decision.
 *
 * This test fails in BOTH directions on purpose, because the two mistakes have
 * opposite symptoms and only one of them is visible:
 *
 *  - A decision consumer that does NOT exclude benchmark rows silently poisons
 *    an automated judgement or fires a false alert.
 *  - A ledger consumer that DOES exclude them silently un-logs real money and
 *    returns the plugin to the defect this whole line of work exists to close:
 *    invoiced, counted by nothing.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\analytics::benchmark_rows_excluded
 */
final class benchmark_spend_exclusion_test extends \advanced_testcase {

    /** Consumers that make a decision or raise an alert => how many queries each. */
    private const MUST_EXCLUDE = [
        'classes/cost_anomaly_detector.php'    => 2,
        'classes/llm_optimizer.php'            => 3,
        'classes/model_recommender.php'        => 1,
        'classes/task/run_anomaly_digest.php'  => 2,
        'classes/spend_guard.php'              => 1,
    ];

    /** Consumers that are the REASON to log benchmark spend at all. */
    private const MUST_NOT_EXCLUDE = [
        'classes/spend_export.php',
        'classes/model_registry.php',
        'token_analytics.php',
    ];

    /**
     * Read a plugin file.
     *
     * @param string $rel Path relative to the plugin root.
     * @return string
     */
    private function src(string $rel): string {
        global $CFG;
        $s = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/' . $rel);
        $this->assertNotFalse($s, "{$rel} is unreadable");
        return $s;
    }

    /**
     * Every decision consumer must exclude benchmark rows, at every query.
     */
    public function test_every_decision_consumer_excludes_benchmark_rows(): void {
        $this->resetAfterTest();

        foreach (self::MUST_EXCLUDE as $rel => $expected) {
            $src = $this->src($rel);
            $found = substr_count($src, 'benchmark_rows_excluded(');
            $this->assertSame(
                $expected,
                $found,
                "{$rel} must call benchmark_rows_excluded() {$expected} time(s), once per "
                . 'query that selects billable rows. A query left out counts benchmark '
                . 'spend toward a decision or an alert -- an operator comparing three '
                . 'candidate models in an afternoon then reads as a cost anomaly, or '
                . 'exhausts a spend cap that exists to protect learners.'
            );
        }
    }

    /**
     * No ledger consumer may exclude them. This is the half that catches a
     * future reader "completing" the set.
     */
    public function test_no_ledger_consumer_excludes_benchmark_rows(): void {
        $this->resetAfterTest();

        foreach (self::MUST_NOT_EXCLUDE as $rel) {
            $this->assertStringNotContainsString(
                'benchmark_rows_excluded(',
                $this->src($rel),
                "{$rel} must NOT exclude benchmark rows. It is a money-truth consumer: "
                . 'the external dashboard contract is the whole bill, not a subset, and '
                . 'excluding here reintroduces by hand the floor that v7.4.2 removed. '
                . 'Benchmark calls are real invoiced money.'
            );
        }

        // analytics.php defines the helper, so it necessarily mentions it -- but its
        // three ledger queries must not USE it.
        $analytics = $this->src('classes/analytics.php');
        $this->assertSame(
            1,
            substr_count($analytics, 'benchmark_rows_excluded('),
            'analytics.php should mention benchmark_rows_excluded() exactly once -- its '
            . 'own definition. get_total_tokens(), get_token_costs() and '
            . 'get_monthly_provider_spend() are the ledger and must keep benchmark rows.'
        );
    }

    /**
     * The exclusion must not drop legacy rows whose interaction_type is NULL.
     *
     * Asserted behaviourally against real rows, not by matching the SQL string:
     * a string assertion is precisely the test that passes while the query
     * silently drops every pre-column chat row.
     */
    public function test_exclusion_keeps_legacy_null_interaction_rows(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $conv = conversation_manager::get_or_create_conversation((int) $user->id, (int) $course->id);

        $mk = function (?string $itype) use ($DB, $conv, $user, $course): int {
            $row = (object) [
                'conversationid' => (int) $conv->id,
                'userid' => (int) $user->id,
                'courseid' => (int) $course->id,
                'role' => 'assistant',
                'message' => 'x',
                'tokens_used' => 0,
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'model_name' => 'gemini-2.5-flash',
                'interaction_type' => $itype,
                'timecreated' => time(),
            ];
            return (int) $DB->insert_record('local_ai_course_assistant_msgs', $row);
        };

        $nullid  = $mk(null);
        $chatid  = $mk('chat');
        $benchid = $mk('model_bench');

        $where = analytics::spend_rows_predicate('m')
            . ' AND ' . analytics::benchmark_rows_excluded('m');

        // get_fieldset_sql returns the driver's native type, which is string on
        // mysqli -- cast, or assertContains' strict comparison fails on a row that
        // is actually present and the test reports a defect that does not exist.
        $kept = array_map('intval', $DB->get_fieldset_sql(
            "SELECT m.id FROM {local_ai_course_assistant_msgs} m
              WHERE {$where} AND m.id IN ({$nullid}, {$chatid}, {$benchid})"
        ));

        $this->assertContains(
            $nullid,
            $kept,
            'A legacy row with a NULL interaction_type was dropped. NOT IN is '
            . 'NULL-propagating, so the IS NULL branch is required. Without it every '
            . 'pre-column chat row disappears, which makes the anomaly median, the '
            . 'digest floor and the projection all SMALLER -- the symptom is an alert '
            . 'that never fires, not an exception.'
        );
        $this->assertContains($chatid, $kept, 'ordinary chat rows must survive');
        $this->assertNotContains($benchid, $kept, 'the benchmark row must be excluded');
    }

    /**
     * The same benchmark row must still be visible to the ledger.
     */
    public function test_benchmark_row_is_still_counted_by_the_ledger(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $before = analytics::get_total_tokens();

        conversation_manager::log_usage_array(
            [
                'prompt_tokens' => 4000,
                'completion_tokens' => 1000,
                'model' => 'gemini-2.5-flash',
                'provider' => 'gemini',
                'reasoning_tokens' => null,
            ],
            (int) $user->id, (int) $course->id, 'model_bench', '[Benchmark] answer: 10 call(s)'
        );

        $this->assertSame(
            $before + 5000,
            analytics::get_total_tokens(),
            'Benchmark spend must reach the ledger. If it does not, the row was written '
            . 'and counted by nothing -- the exact defect, just with a new name.'
        );
    }

    /**
     * The writer must aggregate, not write one row per prompt.
     *
     * The answer call sits inside a foreach bounded by MAX_SAMPLES (up to 50) and
     * the judge call inside a second loop of the same size, so a naive log writes
     * up to 100 rows per run into a table the conversation prune deliberately
     * never evicts. Source-level because the task needs a live provider to run.
     */
    public function test_benchmark_writer_aggregates_one_row_per_bucket(): void {
        $this->resetAfterTest();
        $src = $this->src('classes/task/run_model_benchmark.php');

        // The write must NOT happen from inside either loop.
        $this->assertSame(
            0,
            substr_count($src, 'log_ancillary_usage('),
            'run_model_benchmark must not use the per-call writer: it reads the '
            . "provider's LAST call, which inside a loop means one row per prompt."
        );
        // Count real CALLS, not every mention: the docblock explaining the
        // userid guard names the method too, and a naive substring count made
        // this assertion fail against correct code.
        $this->assertSame(
            1,
            substr_count($src, '::log_usage_array('),
            'exactly one write path, in flush_bench_spend()'
        );

        // Accumulation happens per call; the write happens after.
        // '$this->' prefix, so the method's own declaration is not counted. Three
        // assertions in this test were written as bare substring counts and two of
        // them failed against correct code before this was fixed.
        $this->assertSame(
            2,
            substr_count($src, '$this->accumulate_bench_spend('),
            'two accumulation points are expected: the answer call and the judge '
            . 'call. The judge was invisible in every number before this, including '
            . "model_bench's own cost_cents."
        );

        // The judge must accumulate INSIDE score_one, after its own call -- from the
        // caller's loop it would attribute the previous response's tokens to a judge
        // call that the empty-response early return skipped entirely.
        $scoreone = strpos($src, 'private function score_one(');
        $this->assertNotFalse($scoreone);
        $judgeacc = strpos($src, "accumulate_bench_spend('judge'", $scoreone);
        $this->assertNotFalse(
            $judgeacc,
            'the judge usage must be read inside score_one(), not in the caller loop'
        );

        // Flushed from BOTH the success tail and the catch: a run can die at prompt
        // 49 of 50 having already been billed for 49 calls.
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($src, '$this->flush_bench_spend('),
            'flush_bench_spend() must be called from the normal tail AND from the '
            . 'catch, or a run that throws loses everything it already spent.'
        );

        // And it must refuse to write with no resolvable user.
        $this->assertMatchesRegularExpression(
            '/\$userid\s*<=\s*0/',
            $src,
            'flush_bench_spend must refuse userid 0: get_or_create_conversation() has '
            . 'no FK guard and would insert a conversation owned by nobody.'
        );
    }

    /**
     * 'model_bench' must sit in no capability bucket, so per-capability caps
     * cannot be consumed by benchmarking.
     */
    public function test_model_bench_is_in_no_capability_bucket(): void {
        $this->resetAfterTest();

        $m = new \ReflectionMethod(spend_guard::class, 'capability_sql');
        $m->setAccessible(true);
        foreach (['chat', 'voice', 'rag', 'analytics'] as $cap) {
            $this->assertStringNotContainsString(
                'model_bench',
                $m->invoke(null, $cap),
                "capability_sql('{$cap}') must not name model_bench: putting benchmark "
                . 'spend in a capability bucket lets an operator exhaust a learner-facing '
                . 'cap by evaluating candidate models.'
            );
        }
    }
}
