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
 * Tests that the retriever's per-process vector cache is invalidated when the
 * index changes.
 *
 * The cache lives for the life of the PHP process. In a web request that is a
 * single page load, so a stale entry is nearly harmless. In a CLI process —
 * the reindex tools, the benchmark harnesses, scheduled tasks — it can live
 * for an entire run, and retrieval would keep scoring against vectors for
 * chunks that have since been deleted or replaced.
 *
 * These tests drive the cache directly through reflection rather than through
 * retrieve(), because retrieve() needs a live embedding provider and the
 * behaviour under test is purely the cache lifecycle.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\rag_retriever::flush_cache
 */
final class rag_retriever_cache_invalidation_test extends \advanced_testcase {

    /**
     * Read the private cache.
     *
     * @return array
     */
    private function cache(): array {
        $p = new \ReflectionProperty(rag_retriever::class, 'vectorcache');
        $p->setAccessible(true);
        return $p->getValue();
    }

    /**
     * Seed the private cache as though a retrieval had populated it.
     *
     * @param array $courseids
     */
    private function seed(array $courseids): void {
        $p = new \ReflectionProperty(rag_retriever::class, 'vectorcache');
        $p->setAccessible(true);
        $val = [];
        foreach ($courseids as $cid) {
            $val["course_{$cid}"] = [1 => ['vec' => [0.1, 0.2], 'cmid' => 1,
                'modtype' => 'page', 'chunkindex' => 0]];
        }
        $p->setValue(null, $val);
    }

    protected function tearDown(): void {
        rag_retriever::flush_cache();
        parent::tearDown();
    }

    public function test_flush_removes_only_the_named_course(): void {
        $this->resetAfterTest();
        $this->seed([11, 22]);
        $this->assertArrayHasKey('course_11', $this->cache());

        rag_retriever::flush_cache(11);

        $after = $this->cache();
        $this->assertArrayNotHasKey('course_11', $after);
        $this->assertArrayHasKey('course_22', $after,
            'flushing one course must not discard another course\'s vectors');
    }

    public function test_flush_without_argument_clears_everything(): void {
        $this->resetAfterTest();
        $this->seed([11, 22, 33]);

        rag_retriever::flush_cache();

        $this->assertSame([], $this->cache());
    }

    public function test_flushing_an_uncached_course_is_harmless(): void {
        $this->resetAfterTest();
        $this->seed([11]);

        rag_retriever::flush_cache(999);

        $this->assertArrayHasKey('course_11', $this->cache());
    }

    /**
     * The real regression: deleting a course index must drop its cached
     * vectors. Without the flush this test leaves stale entries behind, and a
     * long-running process would score against chunks that no longer exist.
     */
    public function test_delete_course_index_invalidates_the_cache(): void {
        $this->resetAfterTest();
        $this->seed([11, 22]);

        content_indexer::delete_course_index(11);

        $after = $this->cache();
        $this->assertArrayNotHasKey('course_11', $after,
            'delete_course_index must invalidate the retriever cache');
        $this->assertArrayHasKey('course_22', $after);
    }

    /**
     * Guards the assumption the cache key encodes: entries are per course, so
     * two courses never collide. A key format change that broke this would
     * leak one course's content into another's retrieval, which is a privacy
     * problem rather than merely a staleness one.
     */
    public function test_course_entries_do_not_collide(): void {
        $this->resetAfterTest();
        $this->seed([1, 11, 111]);

        rag_retriever::flush_cache(1);

        $after = $this->cache();
        $this->assertArrayNotHasKey('course_1', $after);
        $this->assertArrayHasKey('course_11', $after);
        $this->assertArrayHasKey('course_111', $after);
    }
}
