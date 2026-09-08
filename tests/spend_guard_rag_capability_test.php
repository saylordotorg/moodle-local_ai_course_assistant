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
 * Regression tests for the 'rag' spend capability.
 *
 * compute_spend()'s base clause was role='assistant' while capability_sql('rag')
 * requires an embedding/rerank interaction_type. Embedding and rerank rows are
 * always role='system', so that intersection matched no row that has ever been
 * written: RAG spend computed as $0.00 regardless of indexing volume, and the
 * "RAG" line in the admin spend panel (spend_guard::status_rows, rendered by
 * token_analytics.php) was permanently zero.
 *
 * Fourth instance of the same inline-predicate drift, so the billable-row
 * condition now comes from analytics::spend_rows_predicate() rather than being
 * hand-written here. The other capability buckets are pinned too, because
 * widening the base clause must not leak background spend into chat or voice.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\spend_guard::get_spend
 */
final class spend_guard_rag_capability_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Insert one msgs row inside the current spend period.
     *
     * @param array $overrides
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
     * One million embedding tokens on the default Voyage model ($0.06).
     *
     * @param int $tokens
     */
    private function embedding(int $tokens = 1000000): void {
        $this->msg(['role' => 'system', 'message' => '[Embedding]', 'provider' => 'embedding',
            'interaction_type' => 'embedding', 'model_name' => 'voyage-3.5',
            'prompt_tokens' => $tokens, 'tokens_used' => $tokens]);
    }

    /**
     * One million rerank tokens on the default reranker ($0.05).
     *
     * @param int $tokens
     */
    private function rerank(int $tokens = 1000000): void {
        $this->msg(['role' => 'system', 'message' => '[Rerank]', 'provider' => 'rerank',
            'interaction_type' => 'rerank', 'model_name' => 'rerank-2.5',
            'prompt_tokens' => $tokens, 'tokens_used' => $tokens]);
    }

    public function test_rag_spend_counts_embedding_cost(): void {
        $this->embedding();
        // Was 0.0 for every input before the fix.
        $this->assertEqualsWithDelta(0.06, spend_guard::get_spend(0, 'rag'), 1e-9);
    }

    public function test_rag_spend_counts_rerank_cost(): void {
        $this->rerank();
        // rerank was in no capability bucket at all, so its cost was outside
        // every per-capability total even once the base clause was widened.
        $this->assertEqualsWithDelta(0.05, spend_guard::get_spend(0, 'rag'), 1e-9);
    }

    public function test_rag_spend_sums_both_halves_of_the_pipeline(): void {
        $this->embedding();
        $this->rerank();
        $this->assertEqualsWithDelta(0.11, spend_guard::get_spend(0, 'rag'), 1e-9);
    }

    public function test_chat_bucket_does_not_absorb_rag_spend(): void {
        $this->embedding();
        $this->rerank();
        $this->msg(['prompt_tokens' => 1000000, 'completion_tokens' => 0]);

        // gpt-4o-mini input is $0.15/M: chat sees only its own row.
        $this->assertEqualsWithDelta(0.15, spend_guard::get_spend(0, 'chat'), 1e-9);
    }

    public function test_voice_bucket_does_not_absorb_rag_spend(): void {
        $this->embedding();
        $this->msg(['interaction_type' => 'openai_tts', 'model_name' => 'tts-1',
            'prompt_tokens' => 1000000]);

        // tts-1 is $60/M in the card; the embedding row must not be added to it.
        $this->assertEqualsWithDelta(60.0, spend_guard::get_spend(0, 'voice'), 1e-9);
    }

    public function test_site_total_includes_both_chat_and_rag(): void {
        $this->embedding();
        $this->msg(['prompt_tokens' => 1000000]);

        // Capability null = all capabilities: 0.15 chat + 0.06 embedding.
        $this->assertEqualsWithDelta(0.21, spend_guard::get_spend(0, null), 1e-9);
    }

    public function test_premium_router_rows_add_nothing(): void {
        $this->msg(['prompt_tokens' => 1000000]);
        // Zero-token metadata row against an expensive model. If the base clause
        // were widened by role instead of interaction_type this would still add
        // nothing here, but it would inflate response counts elsewhere; pinned so
        // the row stays outside spend accounting either way.
        $this->msg(['role' => 'system', 'interaction_type' => 'premium_route',
            'provider' => 'premium_router', 'model_name' => 'claude-opus']);

        $this->assertEqualsWithDelta(0.15, spend_guard::get_spend(0, null), 1e-9);
        $this->assertEqualsWithDelta(0.0, spend_guard::get_spend(0, 'rag'), 1e-9);
    }

    public function test_learner_messages_are_not_spend(): void {
        $this->msg(['role' => 'user', 'prompt_tokens' => 1000000, 'model_name' => 'gpt-4o-mini']);

        $this->assertEqualsWithDelta(0.0, spend_guard::get_spend(0, null), 1e-9);
    }

    public function test_per_course_rag_spend_excludes_site_level_indexing(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->embedding();
        $this->msg(['courseid' => $course->id, 'prompt_tokens' => 1000000]);

        // Indexing is booked against SITEID, so a course-scoped RAG figure is 0
        // and the course's chat spend is unaffected.
        $this->assertEqualsWithDelta(0.0, spend_guard::get_spend((int) $course->id, 'rag'), 1e-9);
        $this->assertEqualsWithDelta(0.15, spend_guard::get_spend((int) $course->id, 'chat'), 1e-9);
    }

    public function test_spend_outside_the_current_period_is_ignored(): void {
        // 40 days back is outside a monthly period on any day of the month.
        set_config('spend_cap_period', 'monthly', 'local_ai_course_assistant');
        $this->msg(['role' => 'system', 'message' => '[Embedding]', 'provider' => 'embedding',
            'interaction_type' => 'embedding', 'model_name' => 'voyage-3.5',
            'prompt_tokens' => 1000000, 'timecreated' => time() - (40 * DAYSECS)]);

        $this->assertEqualsWithDelta(0.0, spend_guard::get_spend(0, 'rag'), 1e-9);
    }

    public function test_status_rows_reports_a_non_zero_rag_line(): void {
        set_config('spend_cap_site', '100', 'local_ai_course_assistant');
        $this->embedding();

        $rag = null;
        foreach (spend_guard::status_rows() as $row) {
            if ($row['label'] === 'RAG') {
                $rag = $row;
            }
        }
        $this->assertNotNull($rag, 'the admin spend panel must still render a RAG row');
        // The visible symptom of the bug: this was always 0.00.
        $this->assertGreaterThan(0.0, $rag['spent']);
    }
}
