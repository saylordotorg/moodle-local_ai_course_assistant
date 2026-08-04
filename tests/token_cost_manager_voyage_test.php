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
 * Rate-card tests for the Voyage AI embedding and reranker entries.
 *
 * Rates from docs.voyageai.com/docs/pricing (2026-08-04). Before these entries
 * existed, RAG spend reported estimated_cost_usd = null: token counts were right
 * but indexing and rerank cost was invisible.
 *
 * The prefix matcher is longest-wins, which is the fragile part: 'voyage-3.5' is
 * a prefix of 'voyage-3.5-lite' at a different price, so a missing -lite key
 * silently bills the lite model at three times its rate. Most of these tests pin
 * that disambiguation rather than the headline numbers.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\token_cost_manager
 */
final class token_cost_manager_voyage_test extends \advanced_testcase {

    /**
     * Cost of one million input tokens, which equals the input rate.
     *
     * @param string $model
     * @return float|null
     */
    private function per_million(string $model): ?float {
        return token_cost_manager::estimate_cost($model, 1_000_000, 0);
    }

    public function test_plugin_default_models_are_priced(): void {
        // The two that actually run in production: base_embedding_provider's
        // voyage default and voyage_reranker's rerank_model default.
        $this->assertEqualsWithDelta(0.06, $this->per_million('voyage-3.5'), 1e-9);
        $this->assertEqualsWithDelta(0.05, $this->per_million('rerank-2.5'), 1e-9);
    }

    public function test_embedding_rates(): void {
        $this->assertEqualsWithDelta(0.12, $this->per_million('voyage-4-large'), 1e-9);
        $this->assertEqualsWithDelta(0.06, $this->per_million('voyage-4'), 1e-9);
        $this->assertEqualsWithDelta(0.02, $this->per_million('voyage-4-lite'), 1e-9);
        $this->assertEqualsWithDelta(0.12, $this->per_million('voyage-context-4'), 1e-9);
        $this->assertEqualsWithDelta(0.18, $this->per_million('voyage-3-large'), 1e-9);
        $this->assertEqualsWithDelta(0.18, $this->per_million('voyage-code-3'), 1e-9);
        $this->assertEqualsWithDelta(0.12, $this->per_million('voyage-finance-2'), 1e-9);
        $this->assertEqualsWithDelta(0.12, $this->per_million('voyage-law-2'), 1e-9);
        $this->assertEqualsWithDelta(0.12, $this->per_million('voyage-multimodal-3'), 1e-9);
        $this->assertEqualsWithDelta(0.12, $this->per_million('voyage-multimodal-3.5'), 1e-9);
    }

    public function test_reranker_rates(): void {
        $this->assertEqualsWithDelta(0.05, $this->per_million('rerank-2'), 1e-9);
        $this->assertEqualsWithDelta(0.02, $this->per_million('rerank-2-lite'), 1e-9);
        $this->assertEqualsWithDelta(0.02, $this->per_million('rerank-2.5-lite'), 1e-9);
    }

    public function test_lite_variants_are_not_billed_at_the_base_rate(): void {
        // Longest-prefix disambiguation. 'voyage-3.5-lite' also matches the
        // shorter 'voyage-3.5' key; if the -lite entry were missing it would be
        // billed at 0.06 instead of 0.02.
        $this->assertEqualsWithDelta(0.02, $this->per_million('voyage-3.5-lite'), 1e-9);
        $this->assertNotEquals($this->per_million('voyage-3.5'), $this->per_million('voyage-3.5-lite'));
        $this->assertEqualsWithDelta(0.02, $this->per_million('rerank-2.5-lite'), 1e-9);
        $this->assertNotEquals($this->per_million('rerank-2.5'), $this->per_million('rerank-2.5-lite'));
        $this->assertNotEquals($this->per_million('voyage-4'), $this->per_million('voyage-4-large'));
    }

    public function test_versioned_suffixes_still_match(): void {
        // Real API model strings may carry a suffix; prefix matching should hold.
        $this->assertEqualsWithDelta(0.06, $this->per_million('voyage-3.5-2026-01-01'), 1e-9);
    }

    public function test_unknown_voyage_model_is_unknown_not_free(): void {
        // No bare 'voyage'/'rerank' catch-all on purpose: a model we have never
        // priced must return null so it reads as unknown rather than $0.
        $this->assertNull($this->per_million('voyage-99-experimental'));
        $this->assertNull($this->per_million('rerank-99'));
        $this->assertNull($this->per_million('some-unlisted-embedder'));
    }

    public function test_output_tokens_add_nothing_for_input_only_models(): void {
        // Embeddings and rerankers have no output billing. Even if a caller
        // passes completion tokens, they must not add cost.
        $withoutput = token_cost_manager::estimate_cost('voyage-3.5', 1_000_000, 500_000);
        $this->assertEqualsWithDelta(0.06, $withoutput, 1e-9);
    }

    public function test_realistic_indexing_cost(): void {
        // Sanity-check the order of magnitude an admin would see: 50M tokens of
        // indexing on the default embedding model is $3, not $0 and not $3000.
        $this->assertEqualsWithDelta(3.0,
            token_cost_manager::estimate_cost('voyage-3.5', 50_000_000, 0), 1e-9);
    }

    public function test_admin_override_still_wins(): void {
        $this->resetAfterTest();
        set_config('rate_card_overrides',
            json_encode(['voyage-3.5' => ['input' => 0.99, 'output' => 0.00]]),
            'local_ai_course_assistant');

        // Overrides exist so a pricing change needs no code deploy; adding these
        // defaults must not break that path.
        $this->assertEqualsWithDelta(0.99, $this->per_million('voyage-3.5'), 1e-9);
        // Untouched keys keep the shipped rate.
        $this->assertEqualsWithDelta(0.05, $this->per_million('rerank-2.5'), 1e-9);
    }
}
