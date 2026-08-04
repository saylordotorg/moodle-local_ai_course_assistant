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
 * Tests that llm_optimizer shares the capability mapping instead of mirroring it.
 *
 * llm_optimizer carried a private copy of spend_guard's capability mapping that
 * had drifted: it lacked 'rerank', so reranker spend belonged to no bucket, and
 * combined with its role='assistant' clause the 'rag' branch could never match a
 * row, because embedding and rerank rows are always role='system'. Both are now
 * shared, so the copies cannot drift again.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\llm_optimizer
 */
final class llm_optimizer_rag_capability_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_capability_mapping_is_not_duplicated(): void {
        // The private mirror is gone: llm_optimizer must not declare its own.
        $this->assertFalse(method_exists(llm_optimizer::class, 'capability_clause'),
            'llm_optimizer should use spend_guard::capability_sql, not a local copy');
        $this->assertTrue(method_exists(spend_guard::class, 'capability_sql'));
    }

    public function test_rag_bucket_covers_both_embedding_and_rerank(): void {
        $sql = spend_guard::capability_sql('rag');
        $this->assertStringContainsString('embedding', $sql);
        // The omission that put reranker spend outside every bucket.
        $this->assertStringContainsString('rerank', $sql);
    }

    public function test_rag_bucket_can_actually_match_a_row(): void {
        global $DB;
        // The regression in one assertion: build the exact WHERE llm_optimizer
        // and spend_guard use, and confirm a real embedding row satisfies it.
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => 0, 'userid' => 0, 'courseid' => SITEID,
            'role' => 'system', 'message' => '[Embedding]', 'tokens_used' => 100,
            'prompt_tokens' => 100, 'completion_tokens' => 0,
            'model_name' => 'voyage-3.5', 'provider' => 'embedding',
            'interaction_type' => 'embedding', 'timecreated' => time(),
        ]);

        $where = analytics::spend_rows_predicate('m')
            . ' AND m.model_name IS NOT NULL AND ' . spend_guard::capability_sql('rag');
        $count = $DB->count_records_sql(
            "SELECT COUNT(m.id) FROM {local_ai_course_assistant_msgs} m WHERE {$where}");

        // Was 0 for every possible input while the base clause said role='assistant'.
        $this->assertSame(1, $count);
    }

    public function test_chat_bucket_still_excludes_background_rows(): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => 0, 'userid' => 0, 'courseid' => SITEID,
            'role' => 'system', 'message' => '[Rerank]', 'tokens_used' => 100,
            'prompt_tokens' => 100, 'completion_tokens' => 0,
            'model_name' => 'rerank-2.5', 'provider' => 'rerank',
            'interaction_type' => 'rerank', 'timecreated' => time(),
        ]);

        $where = analytics::spend_rows_predicate('m')
            . ' AND ' . spend_guard::capability_sql('chat');
        $count = $DB->count_records_sql(
            "SELECT COUNT(m.id) FROM {local_ai_course_assistant_msgs} m WHERE {$where}");

        $this->assertSame(0, $count, 'background spend must not leak into the chat bucket');
    }
}
