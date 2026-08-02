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
 * Tests for the per-query rerank ambiguity gate.
 *
 * Reranking only helps when the embedding stage is unsure. Measured
 * 2026-08-01 over 1,008 queries across 16 courses: the most ambiguous decile
 * gained +24.8 pp recall@3 from reranking, the least ambiguous gained
 * +0.0 pp, and reranking displaced an already-correct top-1 result in 12% of
 * confident cases. The gate uses the cosine margin between the top-1 and
 * top-3 candidates as the ambiguity signal.
 *
 * These tests pin the decision boundary and, more importantly, the failure
 * modes: a gate that silently returns false would disable reranking site-wide
 * without any error, which is exactly the kind of regression that would not
 * surface until someone measured recall again.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\rag_retriever::should_rerank
 */
final class rag_retriever_rerank_gate_test extends \advanced_testcase {

    /**
     * Build a descending-scored candidate list.
     *
     * @param array $scores
     * @return array
     */
    private function candidates(array $scores): array {
        return array_map(fn($s) => ['id' => 1, 'score' => $s, 'content' => 'x'], $scores);
    }

    public function test_ambiguous_query_is_reranked(): void {
        $this->resetAfterTest();
        // margin = 0.700 - 0.660 = 0.040, below the 0.086 default.
        $this->assertTrue(rag_retriever::should_rerank(
            $this->candidates([0.700, 0.680, 0.660])));
    }

    public function test_confident_query_is_not_reranked(): void {
        $this->resetAfterTest();
        // margin = 0.800 - 0.600 = 0.200, well above the default.
        $this->assertFalse(rag_retriever::should_rerank(
            $this->candidates([0.800, 0.700, 0.600])));
    }

    /**
     * The comparison is strict less-than, so margins clearly below the
     * threshold rerank and margins clearly above do not.
     *
     * Behaviour for a margin *exactly* equal to the threshold is deliberately
     * NOT pinned: cosine scores are binary floats, so a nominal 0.900 - 0.800
     * evaluates to 0.09999999999999998 and lands below a 0.100 threshold.
     * Asserting exact-boundary behaviour would be testing IEEE-754 rounding,
     * not the gate. Nothing depends on it — a query sitting within 1e-16 of
     * the threshold is a coin flip either way, and both outcomes are correct.
     */
    public function test_threshold_separates_clearly_ambiguous_from_clearly_confident(): void {
        $this->resetAfterTest();
        set_config('rerank_margin_threshold', '0.100', 'local_ai_course_assistant');
        // Margin 0.05, unambiguously below.
        $this->assertTrue(rag_retriever::should_rerank(
            $this->candidates([0.900, 0.870, 0.850])));
        // Margin 0.15, unambiguously above.
        $this->assertFalse(rag_retriever::should_rerank(
            $this->candidates([0.900, 0.820, 0.750])));
    }

    /**
     * Only the first and third scores matter. A different second-place score
     * must not change the decision.
     */
    public function test_only_top1_and_top3_are_used(): void {
        $this->resetAfterTest();
        $a = rag_retriever::should_rerank($this->candidates([0.700, 0.699, 0.660]));
        $b = rag_retriever::should_rerank($this->candidates([0.700, 0.661, 0.660]));
        $this->assertSame($a, $b);
        $this->assertTrue($a);
    }

    /**
     * Setting the threshold to 0 restores the pre-2026-08 behaviour of
     * reranking every query.
     */
    public function test_zero_threshold_disables_the_gate(): void {
        $this->resetAfterTest();
        set_config('rerank_margin_threshold', '0', 'local_ai_course_assistant');
        // Would otherwise be far too confident to rerank.
        $this->assertTrue(rag_retriever::should_rerank(
            $this->candidates([0.990, 0.500, 0.100])));
    }

    /**
     * An unset config must fall back to the measured default, NOT to zero.
     * get_config() returns false when unset, and casting false to float
     * yields 0.0 -- which would silently disable the gate.
     */
    public function test_unset_config_uses_the_measured_default_not_zero(): void {
        $this->resetAfterTest();
        unset_config('rerank_margin_threshold', 'local_ai_course_assistant');
        // Confident: would rerank only if the gate had collapsed to 0.
        $this->assertFalse(rag_retriever::should_rerank(
            $this->candidates([0.800, 0.700, 0.600])));
        // Ambiguous: still reranks.
        $this->assertTrue(rag_retriever::should_rerank(
            $this->candidates([0.700, 0.680, 0.660])));
    }

    /**
     * With fewer than three candidates the margin cannot be computed. The
     * signal is then unmeasurable rather than confident, so reranking must
     * still run -- a missing measurement must never silently disable it.
     */
    public function test_fewer_than_three_candidates_still_reranks(): void {
        $this->resetAfterTest();
        $this->assertTrue(rag_retriever::should_rerank($this->candidates([0.9, 0.1])));
        $this->assertTrue(rag_retriever::should_rerank($this->candidates([0.9])));
        $this->assertTrue(rag_retriever::should_rerank([]));
    }

    /**
     * A negative threshold is nonsense but must not invert the gate.
     */
    public function test_negative_threshold_behaves_as_disabled(): void {
        $this->resetAfterTest();
        set_config('rerank_margin_threshold', '-1', 'local_ai_course_assistant');
        $this->assertTrue(rag_retriever::should_rerank(
            $this->candidates([0.800, 0.700, 0.600])));
    }

    /**
     * The shipped default must remain the measured value. If someone changes
     * it, that should be a conscious edit with new evidence behind it.
     */
    public function test_default_threshold_is_the_measured_value(): void {
        $this->resetAfterTest();
        unset_config('rerank_margin_threshold', 'local_ai_course_assistant');
        // Margin 0.0859 is just inside 0.086; 0.0861 is just outside.
        $this->assertTrue(rag_retriever::should_rerank(
            $this->candidates([0.9000, 0.8900, 0.8141])));
        $this->assertFalse(rag_retriever::should_rerank(
            $this->candidates([0.9000, 0.8900, 0.8139])));
    }
}
