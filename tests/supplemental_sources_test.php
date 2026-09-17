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
 * Tests for supplemental course resolution.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\supplemental_sources
 */
final class supplemental_sources_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        supplemental_sources::reset_cache();
    }

    /**
     * Ids are read from the site-wide setting and the course's own id is dropped.
     */
    public function test_site_list_is_used_and_self_is_excluded(): void {
        $this->resetAfterTest();
        set_config('supplemental_courses', '16, 42, 7', 'local_ai_course_assistant');

        $this->assertSame([16, 42], supplemental_sources::course_ids(7),
            'a course must not list itself: its chunks are already in scope');
    }

    /**
     * A per-course value replaces the site list rather than extending it.
     *
     * Additive would make "this course must NOT see the orientation material"
     * impossible to express, and opting one course out is the likelier need.
     */
    public function test_per_course_replaces_rather_than_extends(): void {
        $this->resetAfterTest();
        set_config('supplemental_courses', '16,17', 'local_ai_course_assistant');
        set_config('supplemental_courses_course_5', '99', 'local_ai_course_assistant');

        $this->assertSame([99], supplemental_sources::course_ids(5));
        $this->assertSame([16, 17], supplemental_sources::course_ids(6), 'other courses still inherit');
    }

    /**
     * Junk is dropped rather than poisoning the whole list.
     */
    public function test_malformed_entries_are_dropped_not_fatal(): void {
        $this->resetAfterTest();
        $this->assertSame([16, 20], supplemental_sources::parse('16, banana, -4, 0, 20, 16'));
        $this->assertSame([], supplemental_sources::parse('   '));
    }

    /**
     * The cap is enforced, because every listed course is loaded per retrieval.
     */
    public function test_list_is_capped(): void {
        $this->resetAfterTest();
        $ids = supplemental_sources::parse('1 2 3 4 5 6 7 8 9');
        $this->assertCount(supplemental_sources::MAX_COURSES, $ids);
    }

    /**
     * SITEID is never included: the FAQ clause already reaches those chunks.
     */
    public function test_site_course_is_excluded(): void {
        $this->resetAfterTest();
        $this->assertSame([16], supplemental_sources::parse('16,' . SITEID));
    }

    /**
     * A hidden course is ignored, so this cannot surface material a learner is
     * not meant to see. Learn's orientation candidate is hidden today, so this
     * is a live case rather than a theoretical one.
     */
    public function test_hidden_courses_are_not_usable(): void {
        $this->resetAfterTest();
        $visible = $this->getDataGenerator()->create_course(['visible' => 1]);
        $hidden  = $this->getDataGenerator()->create_course(['visible' => 0]);
        set_config('supplemental_courses', $visible->id . ',' . $hidden->id, 'local_ai_course_assistant');

        $this->assertSame([(int) $visible->id], supplemental_sources::usable_course_ids(999));
    }

    /**
     * A course id that no longer exists is ignored rather than breaking retrieval.
     */
    public function test_missing_course_is_ignored(): void {
        $this->resetAfterTest();
        $visible = $this->getDataGenerator()->create_course(['visible' => 1]);
        set_config('supplemental_courses', $visible->id . ',99999999', 'local_ai_course_assistant');

        $this->assertSame([(int) $visible->id], supplemental_sources::usable_course_ids(999));
    }

    /**
     * Changing the list changes the cached index key.
     *
     * Without this a course would keep serving an index built under the old
     * scope until its generation happened to bump, which is exactly the class of
     * staleness the generation counter exists to prevent.
     */
    public function test_supplemental_list_is_part_of_the_cache_key(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['visible' => 1]);
        $m = new \ReflectionMethod(rag_retriever::class, 'persist_key');
        $m->setAccessible(true);

        $before = $m->invoke(null, 42, 'text-embedding-3-small', 'float');
        set_config('supplemental_courses', (string) $course->id, 'local_ai_course_assistant');
        supplemental_sources::reset_cache();
        $after = $m->invoke(null, 42, 'text-embedding-3-small', 'float');

        $this->assertNotSame($before, $after,
            'adding a supplemental course must not serve the pre-change index from cache');
    }

    /**
     * Reindexing a supplemental course invalidates every course that lists it.
     *
     * The list being in the key covers a change to the SETTING. It does not
     * cover a change to the listed course's CONTENT, and that is the case an
     * administrator will actually hit: edit the exam-policy page in the
     * orientation course, press Reindex, and every course pointing at it should
     * see the new text.
     *
     * flush_cache() bumps one course's generation counter and there is no
     * reverse map from a supplemental course to its hosts, so with only the
     * host's generation in the key those courses kept scoring the pre-edit
     * chunks until the 24-hour TTL expired -- serving stale answers and
     * spending top-k slots on chunk ids the reindex had deleted.
     *
     * @return void
     */
    public function test_reindexing_a_supplemental_course_invalidates_its_hosts(): void {
        $this->resetAfterTest();
        $orientation = $this->getDataGenerator()->create_course(['visible' => 1]);
        set_config('supplemental_courses', (string) $orientation->id, 'local_ai_course_assistant');
        supplemental_sources::reset_cache();

        $m = new \ReflectionMethod(rag_retriever::class, 'persist_key');
        $m->setAccessible(true);
        $before = $m->invoke(null, 42, 'text-embedding-3-small', 'float');

        // What content_indexer::index_course() does at the end of a reindex.
        rag_retriever::flush_cache((int) $orientation->id);
        supplemental_sources::reset_cache();

        $after = $m->invoke(null, 42, 'text-embedding-3-small', 'float');
        $this->assertNotSame($before, $after,
            'reindexing a supplemental course must invalidate the courses that list it');
    }

    /**
     * The host course's own generation still rotates the key.
     *
     * Guards against a fix for the above that replaces the host's generation
     * with the supplemental set rather than combining them.
     *
     * @return void
     */
    public function test_reindexing_the_host_course_still_invalidates_its_own_key(): void {
        $this->resetAfterTest();
        $m = new \ReflectionMethod(rag_retriever::class, 'persist_key');
        $m->setAccessible(true);

        $before = $m->invoke(null, 42, 'text-embedding-3-small', 'float');
        rag_retriever::flush_cache(42);
        $after = $m->invoke(null, 42, 'text-embedding-3-small', 'float');

        $this->assertNotSame($before, $after);
    }
}
