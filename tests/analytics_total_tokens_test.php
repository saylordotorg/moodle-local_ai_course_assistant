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
 * Tests for analytics::get_total_tokens(), which powers the "Tokens (30d)" chip.
 *
 * The chip used to run its own inline SQL filtered on role='assistant', so it
 * undercounted by the entire embedding and rerank spend ledger. The predicate now
 * lives in one place (analytics::spend_rows_predicate) shared with
 * get_token_costs(), and the last two tests here pin that the two stay in
 * agreement about what counts as spend, since drift between duplicated copies of
 * that condition is what caused the original bug.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\analytics::get_total_tokens
 */
final class analytics_total_tokens_test extends \advanced_testcase {
    /**
     * Insert one msgs row; overrides win over the defaults.
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

    public function test_chat_and_background_spend_are_both_counted(): void {
        $this->resetAfterTest();
        $this->msg(['prompt_tokens' => 100, 'completion_tokens' => 20]);
        $this->msg(['role' => 'system', 'interaction_type' => 'embedding',
            'provider' => 'embedding', 'model_name' => 'text-embedding-3-small',
            'prompt_tokens' => 5000]);
        $this->msg(['role' => 'system', 'interaction_type' => 'rerank',
            'provider' => 'rerank', 'model_name' => 'rerank-2.5', 'prompt_tokens' => 300]);

        // 120 chat + 5000 embedding + 300 rerank.
        $this->assertSame(5420, analytics::get_total_tokens(0, 0));
    }

    public function test_premium_router_metadata_adds_nothing(): void {
        $this->resetAfterTest();
        $this->msg(['prompt_tokens' => 100, 'completion_tokens' => 20]);
        // Zero-token metadata row; must not change the total either way.
        $this->msg(['role' => 'system', 'interaction_type' => 'premium_route',
            'provider' => 'premium_router', 'model_name' => 'claude-opus-4-8']);

        $this->assertSame(120, analytics::get_total_tokens(0, 0));
    }

    public function test_learner_messages_are_not_spend(): void {
        $this->resetAfterTest();
        // A user row carrying token counts must not be added on top of the
        // assistant row that already accounts for the same exchange.
        $this->msg(['role' => 'user', 'prompt_tokens' => 999, 'interaction_type' => 'chat']);
        $this->msg(['prompt_tokens' => 10, 'completion_tokens' => 5]);

        $this->assertSame(15, analytics::get_total_tokens(0, 0));
    }

    public function test_counts_rows_with_no_model_name(): void {
        $this->resetAfterTest();
        // Unlike get_token_costs(), this total does not require a model name, so a
        // row whose model was never recorded still counts toward spend.
        $this->msg(['model_name' => null, 'prompt_tokens' => 40, 'completion_tokens' => 10]);

        $this->assertSame(50, analytics::get_total_tokens(0, 0));
        $this->assertSame([], analytics::get_token_costs(0, 0));
    }

    public function test_since_window_is_applied(): void {
        $this->resetAfterTest();
        $now = time();
        $this->msg(['prompt_tokens' => 700, 'timecreated' => $now - (40 * DAYSECS)]);
        $this->msg(['prompt_tokens' => 30, 'timecreated' => $now - HOURSECS]);
        $this->msg(['role' => 'system', 'interaction_type' => 'embedding',
            'provider' => 'embedding', 'model_name' => 'text-embedding-3-small',
            'prompt_tokens' => 90, 'timecreated' => $now - HOURSECS]);

        // 30-day window as the chip uses: recent chat plus recent embedding.
        $this->assertSame(120, analytics::get_total_tokens(0, $now - (30 * DAYSECS)));
        $this->assertSame(820, analytics::get_total_tokens(0, 0));
    }

    public function test_per_course_total_excludes_site_level_background_spend(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->msg(['courseid' => $course->id, 'prompt_tokens' => 200]);
        $this->msg(['role' => 'system', 'interaction_type' => 'embedding',
            'provider' => 'embedding', 'model_name' => 'text-embedding-3-small',
            'prompt_tokens' => 9000]);

        // Per-course attribution: chat only, because indexing is logged on SITEID.
        $this->assertSame(200, analytics::get_total_tokens($course->id, 0));
        // Site-wide total is deliberately larger than the sum of course totals.
        $this->assertSame(9200, analytics::get_total_tokens(0, 0));
    }

    public function test_empty_table_returns_zero_not_null(): void {
        $this->resetAfterTest();
        $this->assertSame(0, analytics::get_total_tokens(0, 0));
    }

    public function test_agrees_with_get_token_costs_on_what_counts_as_spend(): void {
        $this->resetAfterTest();
        // Every row here carries a model name, so the two aggregates cover exactly
        // the same population and must reconcile. This is the guard against the
        // shared spend predicate drifting apart again.
        $this->msg(['prompt_tokens' => 100, 'completion_tokens' => 20]);
        $this->msg(['role' => 'system', 'interaction_type' => 'embedding',
            'provider' => 'embedding', 'model_name' => 'text-embedding-3-small',
            'prompt_tokens' => 5000]);
        $this->msg(['role' => 'system', 'interaction_type' => 'premium_route',
            'provider' => 'premium_router', 'model_name' => 'claude-opus-4-8']);

        $bymodel = 0;
        foreach (analytics::get_token_costs(0, 0) as $row) {
            $bymodel += $row['total_prompt_tokens'] + $row['total_completion_tokens'];
        }
        $this->assertSame(analytics::get_total_tokens(0, 0), $bymodel);
    }
}
