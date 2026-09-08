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
 * Tests for the v7.4.0 model registry (pricing overlay, provenance, refusal rule).
 *
 * The headline test here is test_gemini_25_flash_is_priced(). Its absence is
 * what let the $0.00 bug ship: gemini-2.5-flash was the production chat model,
 * matched no rate-card prefix, and every consumer of estimate_cost() treats a
 * null return as "no cost to attribute" rather than as an error — so the spend
 * caps, the anomaly detector and the dashboards all reported zero and all
 * agreed with each other. Nothing in the suite compared the models the plugin
 * actually calls against the models it can price.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\model_registry
 */
final class model_registry_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        // The rate map is cached for the life of the request, and a test
        // process is one long request: without this, layer state leaks between
        // test methods.
        model_registry::reset_cache();
    }

    protected function tearDown(): void {
        model_registry::reset_cache();
        parent::tearDown();
    }

    /**
     * Write the legacy single-blob override setting.
     *
     * @param array $map prefix => ['input' => float, 'output' => float]
     * @return void
     */
    private function set_legacy(array $map): void {
        set_config('rate_card_overrides', json_encode($map), 'local_ai_course_assistant');
        model_registry::reset_cache();
    }

    // ------------------------------------------------------------------
    // The regression that pins the production bug.
    // ------------------------------------------------------------------

    public function test_gemini_25_flash_is_priced(): void {
        $rates = model_registry::rate_for('gemini-2.5-flash');
        $this->assertNotNull(
            $rates,
            'gemini-2.5-flash is the production chat model; an unpriced model bills as $0.00, not as an error'
        );
        $this->assertEqualsWithDelta(0.30, $rates['input'], 1e-9);
        $this->assertEqualsWithDelta(2.50, $rates['output'], 1e-9);
    }

    public function test_gemini_25_flash_costs_real_money_end_to_end(): void {
        // Through the public surface every caller actually uses. 1M in + 1M out
        // must be 0.30 + 2.50; a null anywhere in the chain reads as $0.00.
        $cost = token_cost_manager::estimate_cost('gemini-2.5-flash', 1_000_000, 1_000_000);
        $this->assertNotNull($cost);
        $this->assertEqualsWithDelta(2.80, $cost, 1e-9);
    }

    public function test_dated_gemini_variant_inherits_the_prefix(): void {
        // Provider responses carry dated ids; prefix matching is the mechanism
        // that keeps those priced without a code edit per snapshot.
        $rates = model_registry::rate_for('gemini-2.5-flash-preview-09-2026');
        $this->assertNotNull($rates);
        $this->assertEqualsWithDelta(0.30, $rates['input'], 1e-9);
    }

    public function test_gemini_flash_lite_is_not_swallowed_by_the_shorter_prefix(): void {
        // 'gemini-2.5-flash' is a strict prefix of 'gemini-2.5-flash-lite' at
        // 3x the price. Longest-wins is what keeps lite from billing as flash.
        $lite = model_registry::rate_for('gemini-2.5-flash-lite');
        $this->assertEqualsWithDelta(0.10, $lite['input'], 1e-9);
        $this->assertEqualsWithDelta(0.40, $lite['output'], 1e-9);
    }

    public function test_corrected_anthropic_baseline(): void {
        // The committed table was a generation stale: opus 15/75, haiku 0.80/4.
        $this->assertEqualsWithDelta(5.00, model_registry::rate_for('claude-opus-5')['input'], 1e-9);
        $this->assertEqualsWithDelta(25.00, model_registry::rate_for('claude-opus-5')['output'], 1e-9);
        $this->assertEqualsWithDelta(3.00, model_registry::rate_for('claude-sonnet-5')['input'], 1e-9);
        $this->assertEqualsWithDelta(15.00, model_registry::rate_for('claude-sonnet-5')['output'], 1e-9);
        $this->assertEqualsWithDelta(1.00, model_registry::rate_for('claude-haiku-4-5')['input'], 1e-9);
        $this->assertEqualsWithDelta(5.00, model_registry::rate_for('claude-haiku-4-5')['output'], 1e-9);
    }

    // ------------------------------------------------------------------
    // Layer precedence.
    // ------------------------------------------------------------------

    public function test_baseline_layer_alone(): void {
        $this->assertEqualsWithDelta(0.15, model_registry::rate_for('gpt-4o-mini')['input'], 1e-9);
        $this->assertSame('baseline', model_registry::provenance_for('gpt-4o-mini')['layer']);
    }

    public function test_legacy_blob_beats_baseline(): void {
        $this->set_legacy(['gpt-4o-mini' => ['input' => 1.11, 'output' => 2.22]]);
        $rates = model_registry::rate_for('gpt-4o-mini');
        $this->assertEqualsWithDelta(1.11, $rates['input'], 1e-9);
        $this->assertEqualsWithDelta(2.22, $rates['output'], 1e-9);
        $this->assertSame('legacy_overrides', model_registry::provenance_for('gpt-4o-mini')['layer']);
    }

    public function test_table_beats_legacy_beats_baseline(): void {
        $this->set_legacy(['gpt-4o-mini' => ['input' => 1.11, 'output' => 2.22]]);
        model_registry::upsert(
            ['modelkey' => 'gpt-4o-mini', 'input_rate' => 9.99, 'output_rate' => 8.88],
            'manual',
            2
        );
        $rates = model_registry::rate_for('gpt-4o-mini');
        $this->assertEqualsWithDelta(9.99, $rates['input'], 1e-9);
        $this->assertEqualsWithDelta(8.88, $rates['output'], 1e-9);
        $this->assertSame('table', model_registry::provenance_for('gpt-4o-mini')['layer']);
    }

    public function test_table_row_adds_a_model_the_baseline_never_heard_of(): void {
        $this->assertNull(model_registry::rate_for('brand-new-model-9'));
        model_registry::upsert(
            ['modelkey' => 'brand-new-model-9', 'input_rate' => 0.5, 'output_rate' => 1.5,
             'capability' => 'chat', 'provider' => 'somevendor'],
            'manual',
            2
        );
        $this->assertEqualsWithDelta(0.5, model_registry::rate_for('brand-new-model-9')['input'], 1e-9);
    }

    public function test_metadata_only_row_does_not_null_out_a_real_price(): void {
        // A row carrying capability/status but no rate must leave the lower
        // layer's number alone — overwriting a real price with null would
        // recreate the $0.00 failure through the very table meant to fix it.
        model_registry::upsert(
            ['modelkey' => 'gpt-4o-mini', 'capability' => 'chat', 'status' => 'deprecated'],
            'manual',
            2
        );
        $this->assertEqualsWithDelta(0.15, model_registry::rate_for('gpt-4o-mini')['input'], 1e-9);
        $this->assertSame('baseline', model_registry::provenance_for('gpt-4o-mini')['layer']);
    }

    public function test_null_output_rate_is_treated_as_zero(): void {
        // Embedding and rerank rows are input-only; an admin leaving output
        // blank must not make the whole row unusable.
        model_registry::upsert(
            ['modelkey' => 'some-embedder-1', 'input_rate' => 0.04, 'output_rate' => null,
             'capability' => 'embedding'],
            'manual',
            2
        );
        $rates = model_registry::rate_for('some-embedder-1');
        $this->assertEqualsWithDelta(0.04, $rates['input'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $rates['output'], 1e-9);
    }

    // ------------------------------------------------------------------
    // Longest-prefix resolution.
    // ------------------------------------------------------------------

    public function test_longest_prefix_wins_across_layers(): void {
        // The specific key lives in the table, the general one in the baseline.
        model_registry::upsert(
            ['modelkey' => 'gpt-4o-mini-audio', 'input_rate' => 40.0, 'output_rate' => 80.0],
            'manual',
            2
        );
        $this->assertEqualsWithDelta(40.0, model_registry::rate_for('gpt-4o-mini-audio-preview')['input'], 1e-9);
        $this->assertEqualsWithDelta(0.15, model_registry::rate_for('gpt-4o-mini-2026-01-01')['input'], 1e-9);
    }

    public function test_lookup_is_case_and_whitespace_insensitive(): void {
        $this->assertEqualsWithDelta(0.30, model_registry::rate_for('  GEMINI-2.5-Flash ')['input'], 1e-9);
    }

    public function test_unknown_model_returns_null_not_zero(): void {
        $this->assertNull(model_registry::rate_for('llama-running-on-my-laptop'));
        $this->assertNull(model_registry::rate_for(''));
        $this->assertNull(token_cost_manager::estimate_cost('llama-running-on-my-laptop', 100, 100));
    }

    public function test_upsert_lowercases_the_key(): void {
        model_registry::upsert(['modelkey' => '  MiXeD-Case-Model ', 'input_rate' => 1.0, 'output_rate' => 2.0],
            'manual', 2);
        $this->assertEqualsWithDelta(1.0, model_registry::rate_for('mixed-case-model-v2')['input'], 1e-9);
    }

    // ------------------------------------------------------------------
    // Provenance.
    // ------------------------------------------------------------------

    public function test_provenance_reports_the_writer_and_the_time(): void {
        $before = time();
        model_registry::upsert(
            ['modelkey' => 'gemini-2.5-flash', 'input_rate' => 0.31, 'output_rate' => 2.55,
             'capability' => 'chat', 'provider' => 'google', 'notes' => 'checked the pricing page'],
            'manual',
            7
        );
        $prov = model_registry::provenance_for('gemini-2.5-flash-002');
        $this->assertSame('gemini-2.5-flash', $prov['prefix']);
        $this->assertSame('table', $prov['layer']);
        $this->assertSame('manual', $prov['source']);
        $this->assertSame(7, $prov['addedby']);
        $this->assertGreaterThanOrEqual($before, $prov['timemodified']);
        $this->assertEqualsWithDelta(0.31, $prov['input'], 1e-9);
        $this->assertEqualsWithDelta(2.55, $prov['output'], 1e-9);
    }

    public function test_provenance_of_an_unknown_model_is_the_none_layer(): void {
        $prov = model_registry::provenance_for('who-even-is-this');
        $this->assertSame('none', $prov['layer']);
        $this->assertNull($prov['prefix']);
        $this->assertNull($prov['input']);
        $this->assertNull($prov['source']);
    }

    public function test_provenance_distinguishes_an_upstream_write_from_a_human(): void {
        model_registry::upsert(['modelkey' => 'feed-model-1', 'input_rate' => 1.0, 'output_rate' => 2.0],
            'upstream', null);
        $prov = model_registry::provenance_for('feed-model-1');
        $this->assertSame('table', $prov['layer']);
        $this->assertSame('upstream', $prov['source']);
        $this->assertNull($prov['addedby']);
    }

    // ------------------------------------------------------------------
    // The refusal rule: a human outranks a feed.
    // ------------------------------------------------------------------

    public function test_upstream_cannot_clobber_a_manual_row(): void {
        $id = model_registry::upsert(
            ['modelkey' => 'gemini-2.5-flash', 'input_rate' => 0.30, 'output_rate' => 2.50],
            'manual',
            7
        );

        $outcome = null;
        $again = model_registry::upsert(
            ['modelkey' => 'gemini-2.5-flash', 'input_rate' => 99.0, 'output_rate' => 99.0],
            'upstream',
            null,
            $outcome
        );

        $this->assertSame($id, $again, 'the existing id must come back unchanged');
        $this->assertSame('skipped_manual', $outcome, 'the caller must be able to see that it wrote nothing');

        $rates = model_registry::rate_for('gemini-2.5-flash');
        $this->assertEqualsWithDelta(0.30, $rates['input'], 1e-9);
        $this->assertEqualsWithDelta(2.50, $rates['output'], 1e-9);
        $prov = model_registry::provenance_for('gemini-2.5-flash');
        $this->assertSame('manual', $prov['source']);
        $this->assertSame(7, $prov['addedby']);
    }

    public function test_drift_cannot_clobber_a_manual_row_either(): void {
        model_registry::upsert(['modelkey' => 'pinned-model', 'input_rate' => 1.0, 'output_rate' => 2.0],
            'manual', 7);
        $outcome = null;
        model_registry::upsert(['modelkey' => 'pinned-model', 'input_rate' => 50.0, 'output_rate' => 60.0],
            'drift', null, $outcome);
        $this->assertSame('skipped_manual', $outcome);
        $this->assertEqualsWithDelta(1.0, model_registry::rate_for('pinned-model')['input'], 1e-9);
    }

    public function test_upstream_may_update_its_own_row(): void {
        model_registry::upsert(['modelkey' => 'feedy', 'input_rate' => 1.0, 'output_rate' => 2.0],
            'upstream', null);
        $outcome = null;
        model_registry::upsert(['modelkey' => 'feedy', 'input_rate' => 1.5, 'output_rate' => 2.5],
            'upstream', null, $outcome);
        $this->assertSame('updated', $outcome);
        $this->assertEqualsWithDelta(1.5, model_registry::rate_for('feedy')['input'], 1e-9);
    }

    public function test_a_human_may_correct_a_human(): void {
        model_registry::upsert(['modelkey' => 'handmade', 'input_rate' => 1.0, 'output_rate' => 2.0],
            'manual', 7);
        $outcome = null;
        model_registry::upsert(['modelkey' => 'handmade', 'input_rate' => 3.0, 'output_rate' => 4.0],
            'manual', 8, $outcome);
        $this->assertSame('updated', $outcome);
        $this->assertEqualsWithDelta(3.0, model_registry::rate_for('handmade')['input'], 1e-9);
        $this->assertSame(8, model_registry::provenance_for('handmade')['addedby']);
    }

    public function test_a_signed_bundle_may_correct_a_manual_row(): void {
        // 'bundle' is an authorized channel (Ed25519-signed, allowlisted), not
        // a scraper, so it is deliberately NOT in the refusal set.
        model_registry::upsert(['modelkey' => 'bundled', 'input_rate' => 1.0, 'output_rate' => 2.0],
            'manual', 7);
        $outcome = null;
        model_registry::upsert(['modelkey' => 'bundled', 'input_rate' => 5.0, 'output_rate' => 6.0],
            'bundle', null, $outcome);
        $this->assertSame('updated', $outcome);
        $this->assertEqualsWithDelta(5.0, model_registry::rate_for('bundled')['input'], 1e-9);
    }

    public function test_upsert_inserts_once_and_reports_it(): void {
        global $DB;
        $outcome = null;
        $id = model_registry::upsert(['modelkey' => 'once', 'input_rate' => 1.0, 'output_rate' => 2.0],
            'manual', 7, $outcome);
        $this->assertSame('inserted', $outcome);
        $this->assertSame(1, $DB->count_records(model_registry::TABLE_MODELS, ['modelkey' => 'once']));
        $row = $DB->get_record(model_registry::TABLE_MODELS, ['id' => $id]);
        $this->assertSame('active', $row->status, 'status defaults to active');
        $this->assertGreaterThan(0, (int) $row->timecreated);
    }

    public function test_upsert_rejects_an_empty_key(): void {
        $this->expectException(\coding_exception::class);
        model_registry::upsert(['modelkey' => '   '], 'manual', 7);
    }

    // ------------------------------------------------------------------
    // unpriced_models(): the query whose absence hid the bug.
    // ------------------------------------------------------------------

    public function test_unpriced_models_finds_an_observed_model_with_no_rate(): void {
        $this->log_call('mystery-model-x', 'openai', 1000, 500);
        $this->log_call('mystery-model-x', 'openai', 1000, 500);
        $this->log_call('gpt-4o-mini', 'openai', 1000, 500);

        $found = model_registry::unpriced_models();
        $names = array_column($found, 'model_name');
        $this->assertContains('mystery-model-x', $names);
        $this->assertNotContains('gpt-4o-mini', $names, 'a priced model is not a gap');

        $row = $found[array_search('mystery-model-x', $names, true)];
        $this->assertSame(2, $row['calls']);
        $this->assertSame(3000, $row['tokens']);
        $this->assertSame('openai', $row['provider']);
    }

    public function test_unpriced_model_disappears_once_an_admin_prices_it(): void {
        $this->log_call('mystery-model-x', 'openai', 10, 10);
        $this->assertContains('mystery-model-x', array_column(model_registry::unpriced_models(), 'model_name'));

        model_registry::upsert(['modelkey' => 'mystery-model-x', 'input_rate' => 1.0, 'output_rate' => 2.0],
            'manual', 7);

        $this->assertNotContains(
            'mystery-model-x',
            array_column(model_registry::unpriced_models(), 'model_name'),
            'the admin form is the whole point: pricing a model must close the gap with no deploy'
        );
    }

    public function test_unpriced_models_ignores_learner_rows_and_stale_rows(): void {
        // A learner's own message carries no model and must never appear; and a
        // row outside the window is not a current gap.
        $this->log_call('mystery-model-x', 'openai', 10, 10, 'user');
        $this->log_call('ancient-model', 'openai', 10, 10, 'assistant', time() - (400 * DAYSECS));
        $names = array_column(model_registry::unpriced_models(), 'model_name');
        $this->assertNotContains('mystery-model-x', $names);
        $this->assertNotContains('ancient-model', $names);
    }

    public function test_unpriced_models_keeps_both_providers_of_one_model(): void {
        // get_records_sql would key this result by model_name and collapse the
        // two rows into one; the recordset is what keeps them distinct.
        $this->log_call('dual-hosted-model', 'openai', 10, 10);
        $this->log_call('dual-hosted-model', 'together', 10, 10);
        $rows = array_values(array_filter(
            model_registry::unpriced_models(),
            static fn(array $r): bool => $r['model_name'] === 'dual-hosted-model'
        ));
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['openai', 'together'], array_column($rows, 'provider'));
    }

    // ------------------------------------------------------------------
    // The refresher no longer destroys hand-entered prices.
    // ------------------------------------------------------------------

    public function test_refresher_never_writes_the_legacy_blob_wholesale(): void {
        global $CFG;
        $src = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/classes/rate_card_refresher.php');
        $this->assertNotFalse($src);
        // Comments are stripped first: the class docblock quotes the offending
        // call while explaining why it is gone, and a lint that its own
        // explanation trips is a lint nobody keeps.
        $code = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        // The defect in one line: a weekly task calling
        // set_config('rate_card_overrides', <whole blob>) replaced the only
        // place an admin could hand-enter a price. A source assertion is the
        // right shape here because the failure was structural, not conditional.
        $this->assertStringNotContainsString(
            "set_config('rate_card_overrides'",
            $code,
            'the weekly refresher must never overwrite the admin override blob wholesale'
        );
        $this->assertStringContainsString(
            "'upstream'",
            $code,
            'refresher writes must be stamped as upstream so the refusal rule can protect manual rows'
        );
    }

    public function test_refresher_prices_rerankers(): void {
        // 'rerank' was absent from the kept-mode list, so a reranker could
        // never be auto-priced while voyage_reranker was live in the RAG path.
        $modes = (new \ReflectionClass(rate_card_refresher::class))->getConstants()['KEPT_MODES'];
        $this->assertArrayHasKey('rerank', $modes);
        $this->assertSame('rerank', $modes['rerank']);
        $this->assertArrayHasKey('chat', $modes);
        $this->assertArrayHasKey('embedding', $modes);
    }

    /**
     * Insert one billable message row.
     *
     * @param string $model
     * @param string $provider
     * @param int $prompt
     * @param int $completion
     * @param string $role
     * @param int|null $time
     * @return void
     */
    private function log_call(string $model, string $provider, int $prompt, int $completion,
            string $role = 'assistant', ?int $time = null): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid'    => 1,
            'userid'            => 2,
            'courseid'          => SITEID,
            'role'              => $role,
            'message'           => 'x',
            'prompt_tokens'     => $prompt,
            'completion_tokens' => $completion,
            'model_name'        => $model,
            'provider'          => $provider,
            'interaction_type'  => 'chat',
            'timecreated'       => $time ?? time(),
        ]);
    }
}
