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
 * Tests that a configured RAG spend cap actually stops RAG spend.
 *
 * Before this, nothing enforced the 'rag' capability: check() was only ever
 * called with 'chat' (base_provider) and 'voice' (voice_registry), and neither
 * the embedding nor the rerank path consulted spend_guard at all, so the cap was
 * a number on an admin screen with no effect.
 *
 * The two properties that matter most here are the safety ones: the cap must be
 * inert when unset (every existing site), and a run stopped by the cap must not
 * destroy the existing index.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\content_indexer::index_course
 * @covers     \local_ai_course_assistant\rag_retriever
 */
final class rag_spend_cap_enforcement_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Book enough embedding spend to blow any small cap.
     *
     * @param int $tokens
     */
    private function spend_on_rag(int $tokens): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => 0, 'userid' => 0, 'courseid' => SITEID,
            'role' => 'system', 'message' => '[Embedding]', 'tokens_used' => $tokens,
            'prompt_tokens' => $tokens, 'completion_tokens' => 0,
            'model_name' => 'voyage-3.5', 'provider' => 'embedding',
            'interaction_type' => 'embedding', 'timecreated' => time(),
        ]);
    }

    public function test_cap_is_inert_when_unset(): void {
        // The safety property for every existing install: no rag cap configured,
        // so no amount of spend blocks anything.
        $this->spend_on_rag(500_000_000);

        $this->assertNotSame(spend_guard::CAP_BLOCKED, spend_guard::check(0, 'rag'));
    }

    public function test_cap_blocks_once_exceeded(): void {
        set_config('spend_cap_rag', '1', 'local_ai_course_assistant');
        // 500M tokens on voyage-3.5 at $0.06/M is $30, well past a $1 cap.
        $this->spend_on_rag(500_000_000);

        $this->assertSame(spend_guard::CAP_BLOCKED, spend_guard::check(0, 'rag'));
    }

    public function test_cap_does_not_block_below_the_limit(): void {
        set_config('spend_cap_rag', '100', 'local_ai_course_assistant');
        $this->spend_on_rag(1_000_000);

        $this->assertNotSame(spend_guard::CAP_BLOCKED, spend_guard::check(0, 'rag'));
    }

    public function test_retrieval_returns_nothing_when_capped(): void {
        set_config('rag_enabled', 1, 'local_ai_course_assistant');
        set_config('spend_cap_rag', '1', 'local_ai_course_assistant');
        $this->spend_on_rag(500_000_000);
        $course = $this->getDataGenerator()->create_course();

        // Degrades to no context rather than raising: chat keeps working, it just
        // loses citations. Also proves no embedding API call is attempted, since
        // no embedding key is configured in the test environment and a call would
        // surface as an exception rather than an empty array.
        $this->assertSame([], rag_retriever::retrieve((int) $course->id, 'any query'));
    }

    public function test_indexing_refuses_to_start_when_capped(): void {
        set_config('spend_cap_rag', '1', 'local_ai_course_assistant');
        $this->spend_on_rag(500_000_000);
        $course = $this->getDataGenerator()->create_course();

        $stats = content_indexer::index_course((int) $course->id);

        $this->assertNotEmpty($stats['cap_blocked']);
        $this->assertSame(0, $stats['indexed']);
        $this->assertArrayHasKey('fatal', $stats);
    }

    public function test_a_capped_run_does_not_delete_the_existing_index(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        // An existing, good index for this course.
        $DB->insert_record('local_ai_course_assistant_chunks', (object) [
            'courseid' => $course->id, 'cmid' => 42, 'chunkindex' => 0,
            'content' => 'existing chunk', 'contenthash' => sha1('existing chunk'),
            'embedding' => '[0.1,0.2]', 'model' => 'voyage-3.5',
            'timemodified' => time(),
        ]);
        $before = $DB->count_records(
            'local_ai_course_assistant_chunks',
            ['courseid' => $course->id]
        );

        set_config('spend_cap_rag', '1', 'local_ai_course_assistant');
        $this->spend_on_rag(500_000_000);
        content_indexer::index_course((int) $course->id);

        // The stale-chunk prune keys off "hashes not seen this run", and a capped
        // run sees none. Pruning here would wipe a working index.
        $this->assertSame($before, $DB->count_records(
            'local_ai_course_assistant_chunks',
            ['courseid' => $course->id]
        ));
    }

    public function test_site_cap_also_gates_rag_when_no_rag_specific_cap_is_set(): void {
        // get_cap falls through to spend_cap_site when spend_cap_rag is unset, so
        // a site-wide cap must gate RAG too.
        set_config('spend_cap_site', '1', 'local_ai_course_assistant');
        $this->spend_on_rag(500_000_000);

        $this->assertSame(spend_guard::CAP_BLOCKED, spend_guard::check(0, 'rag'));
    }
}
