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
 * Tests for analytics::get_token_costs(), the Redash export's cost section.
 *
 * The old inline filter in redash_export.php was role='assistant', which hid the
 * background RAG spend that log_embedding_cost()/log_rerank_cost() write with
 * role='system'. Confirmed live on dev.sylr.org 2026-08-03: token_costs returned
 * one gpt-4o-mini row and no embedding row, despite roughly 50k embedding rows in
 * the table. These tests pin that background spend is reported, that
 * premium_router metadata is not counted as spend, and that the two never merge.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\analytics::get_token_costs
 */
final class analytics_token_costs_test extends \advanced_testcase {

    /**
     * Insert one msgs row.
     *
     * @param array $overrides field overrides.
     */
    private function msg(array $overrides): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_msgs', (object) ($overrides + [
            'conversationid' => 0,
            'userid' => 0,
            'courseid' => SITEID,
            'role' => 'assistant',
            'message' => 'x',
            'tokens_used' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'model_name' => 'gpt-4o-mini',
            'provider' => 'openai',
            'interaction_type' => 'chat',
            'timecreated' => time(),
        ]));
    }

    /**
     * Mirror of base_embedding_provider::log_embedding_cost().
     *
     * @param int $tokens
     * @param string $model
     */
    private function embedding_row(int $tokens, string $model = 'text-embedding-3-small'): void {
        $this->msg(['role' => 'system', 'message' => '[Embedding]', 'model_name' => $model,
            'provider' => 'embedding', 'interaction_type' => 'embedding',
            'tokens_used' => $tokens, 'prompt_tokens' => $tokens, 'completion_tokens' => 0]);
    }

    /**
     * Mirror of voyage_reranker::log_rerank_cost().
     *
     * @param int $tokens
     */
    private function rerank_row(int $tokens): void {
        $this->msg(['role' => 'system', 'message' => '[Rerank]', 'model_name' => 'rerank-2.5',
            'provider' => 'rerank', 'interaction_type' => 'rerank',
            'tokens_used' => $tokens, 'prompt_tokens' => $tokens, 'completion_tokens' => 0]);
    }

    /**
     * Find one row of the result by model name.
     *
     * @param array $rows
     * @param string $model
     * @return array|null
     */
    private function row(array $rows, string $model): ?array {
        foreach ($rows as $row) {
            if ($row['model'] === $model) {
                return $row;
            }
        }
        return null;
    }

    public function test_embedding_spend_is_reported(): void {
        $this->resetAfterTest();
        $this->embedding_row(1000);
        $this->embedding_row(500);

        $row = $this->row(analytics::get_token_costs(0, 0), 'text-embedding-3-small');
        $this->assertNotNull($row, 'embedding spend must appear in the cost aggregate');
        $this->assertSame('embedding', $row['category']);
        $this->assertSame(2, $row['response_count']);
        $this->assertSame(1500, $row['total_prompt_tokens']);
        $this->assertSame(1500, $row['total_tokens']);
        // Priced in the rate card at $0.02 per million input tokens.
        $this->assertEqualsWithDelta(1500 / 1_000_000 * 0.02, $row['estimated_cost_usd'], 1e-12);
    }

    public function test_rerank_spend_is_reported_with_unknown_cost_not_zero(): void {
        $this->resetAfterTest();
        $this->rerank_row(800);

        $row = $this->row(analytics::get_token_costs(0, 0), 'rerank-2.5');
        $this->assertNotNull($row);
        $this->assertSame('rerank', $row['category']);
        $this->assertSame(800, $row['total_tokens']);
        // rerank-2.5 is absent from the rate card: null means unknown, not free.
        $this->assertNull($row['estimated_cost_usd']);
    }

    public function test_chat_spend_still_reported(): void {
        $this->resetAfterTest();
        $this->msg(['prompt_tokens' => 100, 'completion_tokens' => 20, 'tokens_used' => 120]);

        $row = $this->row(analytics::get_token_costs(0, 0), 'gpt-4o-mini');
        $this->assertNotNull($row);
        $this->assertSame('chat', $row['category']);
        $this->assertSame(1, $row['response_count']);
        $this->assertSame(120, $row['total_tokens']);
        $this->assertNotNull($row['estimated_cost_usd']);
    }

    public function test_premium_router_metadata_is_not_counted_as_spend(): void {
        $this->resetAfterTest();
        // The real escalated call.
        $this->msg(['model_name' => 'claude-opus-4-8', 'provider' => 'claude',
            'prompt_tokens' => 300, 'completion_tokens' => 100, 'tokens_used' => 400]);
        // premium_router's telemetry row: same model name, zero tokens, role=system.
        $this->msg(['role' => 'system', 'message' => '[PremiumRouter] stem',
            'model_name' => 'claude-opus-4-8', 'provider' => 'premium_router',
            'interaction_type' => 'premium_route']);

        $rows = analytics::get_token_costs(0, 0);
        $opus = array_values(array_filter($rows, fn($r) => $r['model'] === 'claude-opus-4-8'));
        // Exactly one bucket, counting the call once. Counting the telemetry row
        // would report two responses for a single escalated turn.
        $this->assertCount(1, $opus);
        $this->assertSame(1, $opus[0]['response_count']);
        $this->assertSame(400, $opus[0]['total_tokens']);
        $this->assertSame('chat', $opus[0]['category']);
    }

    public function test_chat_and_background_spend_never_merge_into_one_bucket(): void {
        $this->resetAfterTest();
        // Same model name used for chat and for embedding: the category is part of
        // the grouping key, so these must stay two rows.
        $this->msg(['model_name' => 'shared-model', 'prompt_tokens' => 10,
            'completion_tokens' => 5, 'tokens_used' => 15]);
        $this->embedding_row(70, 'shared-model');

        $rows = array_values(array_filter(analytics::get_token_costs(0, 0),
            fn($r) => $r['model'] === 'shared-model'));
        $this->assertCount(2, $rows);
        $categories = array_column($rows, 'category');
        sort($categories);
        $this->assertSame(['chat', 'embedding'], $categories);
    }

    public function test_per_course_call_excludes_site_level_background_spend(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->msg(['courseid' => $course->id, 'prompt_tokens' => 50,
            'completion_tokens' => 10, 'tokens_used' => 60]);
        // Background indexing is logged against SITEID, not the indexed course.
        $this->embedding_row(9000);

        $rows = analytics::get_token_costs($course->id, 0);
        $this->assertNotNull($this->row($rows, 'gpt-4o-mini'));
        $this->assertNull($this->row($rows, 'text-embedding-3-small'),
            'site-level indexing spend must not be attributed to one course');

        // The whole-site aggregate sees both.
        $all = analytics::get_token_costs(0, 0);
        $this->assertNotNull($this->row($all, 'text-embedding-3-small'));
        $this->assertNotNull($this->row($all, 'gpt-4o-mini'));
    }

    public function test_since_window_applies_to_background_spend_too(): void {
        $this->resetAfterTest();
        $now = time();
        $this->msg(['role' => 'system', 'message' => '[Embedding]',
            'model_name' => 'text-embedding-3-small', 'provider' => 'embedding',
            'interaction_type' => 'embedding', 'tokens_used' => 400,
            'prompt_tokens' => 400, 'timecreated' => $now - (30 * DAYSECS)]);
        $this->embedding_row(100);

        $recent = $this->row(analytics::get_token_costs(0, $now - DAYSECS), 'text-embedding-3-small');
        $this->assertSame(100, $recent['total_tokens']);
        $alltime = $this->row(analytics::get_token_costs(0, 0), 'text-embedding-3-small');
        $this->assertSame(500, $alltime['total_tokens']);
    }

    public function test_rows_without_a_model_name_are_ignored(): void {
        $this->resetAfterTest();
        $this->msg(['model_name' => '', 'tokens_used' => 999]);
        $this->msg(['model_name' => null, 'tokens_used' => 999]);
        $this->embedding_row(10);

        $rows = analytics::get_token_costs(0, 0);
        $this->assertCount(1, $rows);
        $this->assertSame('text-embedding-3-small', $rows[0]['model']);
    }
}
