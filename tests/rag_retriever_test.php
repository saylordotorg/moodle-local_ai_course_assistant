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

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for rag_retriever relevance gate + current-page bias (v6.2.0).
 *
 * Exercises the pure filter_and_rank() seam (no DB/provider) that retrieve()
 * delegates the floor and ordering boost to.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\rag_retriever::filter_and_rank
 */
final class rag_retriever_test extends \advanced_testcase {

    /** @return array[] Helper: three chunks with descending cosine scores. */
    private function sample(): array {
        return [
            ['content' => 'a', 'score' => 0.62, 'cmid' => 10, 'modtype' => 'page', 'chunkindex' => 0],
            ['content' => 'b', 'score' => 0.30, 'cmid' => 11, 'modtype' => 'page', 'chunkindex' => 0],
            ['content' => 'c', 'score' => 0.12, 'cmid' => 12, 'modtype' => 'book', 'chunkindex' => 0],
        ];
    }

    public function test_floor_drops_weak_chunks(): void {
        $out = rag_retriever::filter_and_rank($this->sample(), 0.25, 0, 0.05);
        // The 0.12 chunk is below the 0.25 floor and must be dropped.
        $this->assertCount(2, $out);
        $contents = array_column($out, 'content');
        $this->assertNotContains('c', $contents);
    }

    public function test_empty_when_nothing_clears_floor(): void {
        $out = rag_retriever::filter_and_rank($this->sample(), 0.9, 0, 0.05);
        $this->assertSame([], $out);
    }

    public function test_zero_floor_keeps_all(): void {
        $out = rag_retriever::filter_and_rank($this->sample(), 0.0, 0, 0.05);
        $this->assertCount(3, $out);
    }

    public function test_current_page_boost_wins_near_tie(): void {
        // Two near-tied chunks; the lower raw score belongs to the current page.
        $scored = [
            ['content' => 'other', 'score' => 0.50, 'cmid' => 20, 'modtype' => 'page', 'chunkindex' => 0],
            ['content' => 'page',  'score' => 0.48, 'cmid' => 21, 'modtype' => 'page', 'chunkindex' => 0],
        ];
        // Boost of 0.05 lifts the current-page chunk (0.48 + 0.05 = 0.53) above 0.50.
        $out = rag_retriever::filter_and_rank($scored, 0.0, 21, 0.05);
        $this->assertSame('page', $out[0]['content']);
        // The raw score is preserved (boost is ordering-only).
        $this->assertEqualsWithDelta(0.48, $out[0]['score'], 0.0001);
    }

    public function test_boost_does_not_rescue_irrelevant_page_chunk(): void {
        // Current-page chunk is below the floor; the boost must not save it.
        $scored = [
            ['content' => 'good', 'score' => 0.40, 'cmid' => 30, 'modtype' => 'page', 'chunkindex' => 0],
            ['content' => 'pagebad', 'score' => 0.10, 'cmid' => 31, 'modtype' => 'page', 'chunkindex' => 0],
        ];
        $out = rag_retriever::filter_and_rank($scored, 0.25, 31, 0.05);
        $this->assertCount(1, $out);
        $this->assertSame('good', $out[0]['content']);
    }
}
