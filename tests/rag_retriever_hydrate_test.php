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
 * Tests for deferred chunk-text hydration and the hoisted-norm scorer.
 *
 * Scoring reads vectors only, so retrieve() no longer selects chunk text for
 * the whole course; it fetches text for the few chunks that survive selection.
 * The risk this introduces is a row reaching the model with empty content —
 * which would look like a retrieval hit but inject nothing — so that path is
 * pinned here.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\rag_retriever::hydrate_content
 */
final class rag_retriever_hydrate_test extends \advanced_testcase {

    /**
     * Insert a chunk and return its id.
     *
     * @param string $content
     * @param int $courseid
     * @return int
     */
    private function make_chunk(string $content, int $courseid = 42): int {
        global $DB;
        return (int) $DB->insert_record('local_ai_course_assistant_chunks', (object) [
            'courseid'   => $courseid,
            'cmid'       => 1,
            'modtype'    => 'page',
            'chunkindex' => 0,
            'content'    => $content,
            'embedding'  => json_encode([0.1, 0.2, 0.3]),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    public function test_hydrates_text_and_preserves_order(): void {
        $this->resetAfterTest();
        $a = $this->make_chunk('alpha text');
        $b = $this->make_chunk('bravo text');

        // Deliberately pass in non-id order; ranking order must be preserved.
        $out = rag_retriever::hydrate_content([
            ['id' => $b, 'content' => '', 'score' => 0.9],
            ['id' => $a, 'content' => '', 'score' => 0.5],
        ]);

        $this->assertCount(2, $out);
        $this->assertSame('bravo text', $out[0]['content']);
        $this->assertSame('alpha text', $out[1]['content']);
        $this->assertSame(0.9, $out[0]['score'], 'score must survive hydration');
    }

    /**
     * A chunk deleted between the scoring query and the hydration query (a
     * concurrent reindex) must be dropped, not returned with empty text. An
     * empty passage reaching the prompt is worse than one fewer passage.
     */
    public function test_vanished_chunk_is_dropped_not_returned_empty(): void {
        global $DB;
        $this->resetAfterTest();
        $a = $this->make_chunk('still here');
        $gone = $this->make_chunk('about to vanish');
        $DB->delete_records('local_ai_course_assistant_chunks', ['id' => $gone]);

        $out = rag_retriever::hydrate_content([
            ['id' => $gone, 'content' => '', 'score' => 0.99],
            ['id' => $a,    'content' => '', 'score' => 0.10],
        ]);

        $this->assertCount(1, $out);
        $this->assertSame('still here', $out[0]['content']);
    }

    /**
     * A chunk whose stored text is blank is equally useless downstream.
     */
    public function test_blank_content_is_dropped(): void {
        $this->resetAfterTest();
        $blank = $this->make_chunk('   ');
        $ok = $this->make_chunk('real text');

        $out = rag_retriever::hydrate_content([
            ['id' => $blank, 'content' => '', 'score' => 0.9],
            ['id' => $ok,    'content' => '', 'score' => 0.1],
        ]);

        $this->assertCount(1, $out);
        $this->assertSame('real text', $out[0]['content']);
    }

    public function test_empty_input_returns_empty(): void {
        $this->resetAfterTest();
        $this->assertSame([], rag_retriever::hydrate_content([]));
        $this->assertSame([], rag_retriever::hydrate_content([['id' => 0, 'content' => '']]));
    }

    /**
     * The hoisted-norm scorer must be numerically identical to the original
     * cosine(), not merely close — otherwise ranking could change and every
     * recall figure measured to date would need re-establishing.
     */
    public function test_hoisted_norm_scorer_matches_original_cosine_exactly(): void {
        $this->resetAfterTest();
        $rc = new \ReflectionClass(rag_retriever::class);
        $new = $rc->getMethod('cosine_against_query');
        $new->setAccessible(true);
        $old = $rc->getMethod('cosine');
        $old->setAccessible(true);

        mt_srand(1234);
        for ($t = 0; $t < 25; $t++) {
            $a = [];
            $b = [];
            for ($i = 0; $i < 256; $i++) {
                $a[] = mt_rand(-1000, 1000) / 1000;
                $b[] = mt_rand(-1000, 1000) / 1000;
            }
            $qnorm = 0.0;
            foreach ($a as $x) {
                $qnorm += $x * $x;
            }
            $this->assertSame(
                $old->invoke(null, $a, $b),
                $new->invoke(null, $a, sqrt($qnorm), $b),
                'hoisting the query norm must not change the score'
            );
        }
    }

    public function test_scorer_guards_zero_vectors(): void {
        $this->resetAfterTest();
        $rc = new \ReflectionClass(rag_retriever::class);
        $m = $rc->getMethod('cosine_against_query');
        $m->setAccessible(true);

        $this->assertSame(0.0, $m->invoke(null, [0.0, 0.0], 0.0, [1.0, 2.0]));
        $this->assertSame(0.0, $m->invoke(null, [1.0, 2.0], sqrt(5.0), [0.0, 0.0]));
    }
}
