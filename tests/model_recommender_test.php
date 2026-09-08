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
 * Tests for the v7.4.0 model recommendation engine.
 *
 * The tests that matter most here are the refusals. A recommender that always
 * produces a number is easy to write and impossible to trust: llm_optimizer
 * already substitutes a neutral 0.5 quality score when ratings are thin, which
 * means its output looks identical whether it is built on ten thousand ratings
 * or on none. So each refusal path is pinned explicitly — low sample, release
 * mismatch, unmeasured current model — and each assertion checks that NO score
 * and NO delta was invented alongside the refusal.
 *
 * The threshold tests are set exactly ON the boundary rather than near it,
 * because "cheaper by 15%" and "cheaper by 14.9999%" are the two cases an admin
 * will actually meet and the sign of the comparison is the whole rule.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\model_recommender
 */
final class model_recommender_test extends \advanced_testcase {

    /** @var string Release string used by every synthetic run. */
    private const REL = '7.4.0';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        model_registry::reset_cache();
    }

    protected function tearDown(): void {
        model_registry::reset_cache();
        parent::tearDown();
    }

    /**
     * Build a synthetic benchmark run.
     *
     * @param string $model
     * @param float|null $quality 0..1 normalized score.
     * @param float|null $cost cents per call.
     * @param array $extra Overrides (quality_n, plugin_release, status, provider, ...).
     * @return array
     */
    private function bench_row(string $model, ?float $quality, ?float $cost, array $extra = []): array {
        return array_merge([
            'runid' => 'run-' . $model . '-' . count($extra),
            'harness' => 'tutor_golden',
            'sola_function' => 'chat',
            'registry_key' => $model,
            'provider' => 'openai',
            'model_name' => $model,
            'quality_metric' => 'rubric_mean',
            'quality_score' => $quality,
            'quality_n' => 50,
            'cost_cents_per_call' => $cost,
            'p50_ttft_ms' => 500,
            'plugin_release' => self::REL,
            'status' => 'complete',
            'timecreated' => 1000,
        ], $extra);
    }

    /**
     * Default tunables with optional overrides.
     *
     * @param array $over
     * @return array
     */
    private function tunables(array $over = []): array {
        return array_merge(model_recommender::tunables(), $over);
    }

    // ------------------------------------------------------------------
    // Epsilon boundary: how much quality a cost saving may buy back.
    // ------------------------------------------------------------------

    public function test_epsilon_boundary_exactly_at_tolerance_is_recommended(): void {
        // Current 0.90; epsilon 0.02; candidate lands exactly on 0.88.
        $current = $this->bench_row('current-model', 0.90, 1.0);
        $cand = $this->bench_row('cheap-model', 0.88, 0.50);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 0.0);

        $this->assertNotNull($r['recommendation'], 'exactly at the epsilon boundary must still be recommendable');
        $this->assertSame('cheap-model', $r['recommendation']['model']);
        $this->assertSame(model_recommender::VERDICT_COST_SAVING, $r['recommendation']['verdict']);
        $this->assertNull($r['no_recommendation']);
    }

    public function test_epsilon_boundary_one_notch_past_tolerance_is_refused(): void {
        $current = $this->bench_row('current-model', 0.90, 1.0);
        $cand = $this->bench_row('cheap-model', 0.8799, 0.50);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 0.0);

        $this->assertNull($r['recommendation']);
        $this->assertSame(model_recommender::NR_NO_MATERIAL_GAIN, $r['no_recommendation']['reason']);
        $this->assertSame(model_recommender::VERDICT_NO_GAIN, $r['candidates'][0]['verdict']);
        // Comparable, so a delta IS computed — the refusal is about materiality,
        // not about missing data.
        $this->assertTrue($r['candidates'][0]['comparable']);
        $this->assertEqualsWithDelta(-0.0201, $r['candidates'][0]['quality_delta'], 1.0e-9);
    }

    public function test_zero_epsilon_setting_is_honoured_not_defaulted(): void {
        // A configured 0 must mean "trade away no quality at all". Resolving the
        // setting with `?:` would restore 0.02 and trade some.
        set_config('rec_quality_epsilon', '0', 'local_ai_course_assistant');
        $this->assertSame(0.0, model_recommender::tunables()['epsilon']);

        $current = $this->bench_row('current-model', 0.90, 1.0);
        $slightlyworse = $this->bench_row('cheap-model', 0.8999, 0.10);
        $exactlyequal = $this->bench_row('equal-model', 0.90, 0.10);

        $r = model_recommender::evaluate($current, [$slightlyworse, $exactlyequal], model_recommender::tunables(), self::REL, 0.0);
        $this->assertSame('equal-model', $r['recommendation']['model']);
    }

    // ------------------------------------------------------------------
    // Savings floor boundary.
    // ------------------------------------------------------------------

    public function test_savings_floor_exactly_on_the_floor_is_not_recommended(): void {
        // Floor 0.15 of a 1.0c baseline => threshold 0.85c. The test is strict:
        // "15% cheaper" has to mean more than 15%, not 15% after rounding.
        $current = $this->bench_row('current-model', 0.90, 1.0);
        $cand = $this->bench_row('cheap-model', 0.90, 0.85);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 0.0);

        $this->assertNull($r['recommendation']);
        $this->assertSame(model_recommender::VERDICT_NO_GAIN, $r['candidates'][0]['verdict']);
        $this->assertStringContainsString('savings floor', $r['candidates'][0]['why']);
    }

    public function test_savings_floor_just_past_the_floor_is_recommended(): void {
        $current = $this->bench_row('current-model', 0.90, 1.0);
        $cand = $this->bench_row('cheap-model', 0.90, 0.8499);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 0.0);

        $this->assertSame('cheap-model', $r['recommendation']['model']);
        $this->assertSame(model_recommender::VERDICT_COST_SAVING, $r['recommendation']['verdict']);
    }

    // ------------------------------------------------------------------
    // Quality-margin rule.
    // ------------------------------------------------------------------

    public function test_quality_margin_exactly_at_margin_at_equal_cost_is_recommended(): void {
        $current = $this->bench_row('current-model', 0.80, 1.0);
        $cand = $this->bench_row('better-model', 0.85, 1.0);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 0.0);

        $this->assertSame('better-model', $r['recommendation']['model']);
        $this->assertSame(model_recommender::VERDICT_QUALITY_GAIN, $r['recommendation']['verdict']);
    }

    public function test_quality_margin_short_of_margin_is_not_recommended(): void {
        $current = $this->bench_row('current-model', 0.80, 1.0);
        $cand = $this->bench_row('better-model', 0.8499, 1.0);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 0.0);

        $this->assertNull($r['recommendation']);
        $this->assertSame(model_recommender::VERDICT_NO_GAIN, $r['candidates'][0]['verdict']);
    }

    public function test_better_but_more_expensive_is_not_recommended(): void {
        $current = $this->bench_row('current-model', 0.80, 1.0);
        $cand = $this->bench_row('better-model', 0.95, 1.0001);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 0.0);

        $this->assertNull($r['recommendation'], 'a quality gain at a higher price is a budget decision, not a recommendation');
        $this->assertSame(model_recommender::VERDICT_NO_GAIN, $r['candidates'][0]['verdict']);
    }

    // ------------------------------------------------------------------
    // Cheaper AND better; cheaper but materially worse.
    // ------------------------------------------------------------------

    public function test_cheaper_and_better_candidate_is_recommended(): void {
        $current = $this->bench_row('current-model', 0.80, 2.224);
        $cand = $this->bench_row('sonnet-5', 0.97, 0.352);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 1000.0);

        $this->assertSame('sonnet-5', $r['recommendation']['model']);
        // Both rules fire; cost_saving is checked first and is the reported verdict.
        $this->assertSame(model_recommender::VERDICT_COST_SAVING, $r['recommendation']['verdict']);
        $this->assertGreaterThan(0.0, $r['recommendation']['quality_delta']);
        $this->assertLessThan(0.0, $r['recommendation']['monthly_delta_usd']);
    }

    public function test_cheaper_but_materially_worse_candidate_is_not_recommended(): void {
        $current = $this->bench_row('current-model', 0.90, 1.0);
        $cand = $this->bench_row('very-cheap-model', 0.70, 0.05);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 0.0);

        $this->assertNull($r['recommendation'], 'a 95% cost cut does not buy 0.20 of quality');
        $this->assertSame(model_recommender::NR_NO_MATERIAL_GAIN, $r['no_recommendation']['reason']);
        $this->assertSame(model_recommender::VERDICT_NO_GAIN, $r['candidates'][0]['verdict']);
        $this->assertStringContainsString('tolerance', $r['candidates'][0]['why']);
    }

    // ------------------------------------------------------------------
    // Refusals: the reason matters more than a number.
    // ------------------------------------------------------------------

    public function test_low_quality_n_candidate_is_refused_not_scored(): void {
        $current = $this->bench_row('current-model', 0.90, 1.0);
        $cand = $this->bench_row('cheap-model', 0.95, 0.10, ['quality_n' => 19]);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 1000.0);

        $this->assertNull($r['recommendation']);
        $this->assertSame(model_recommender::NR_NO_COMPARABLE_CANDIDATES, $r['no_recommendation']['reason']);
        $c = $r['candidates'][0];
        $this->assertFalse($c['comparable']);
        $this->assertSame(model_recommender::NC_LOW_QUALITY_N, $c['not_comparable_reason']);
        $this->assertStringContainsString('19', (string) $c['not_comparable_detail']);
        // No arithmetic on a refused run.
        $this->assertNull($c['quality_delta']);
        $this->assertNull($c['cost_delta_cents']);
        $this->assertNull($c['monthly_delta_usd']);
        $this->assertSame(1, $r['no_recommendation']['detail']['refusals'][model_recommender::NC_LOW_QUALITY_N]);
    }

    public function test_quality_n_exactly_at_the_floor_is_comparable(): void {
        $current = $this->bench_row('current-model', 0.90, 1.0, ['quality_n' => 20]);
        $cand = $this->bench_row('cheap-model', 0.90, 0.10, ['quality_n' => 20]);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 0.0);

        $this->assertTrue($r['candidates'][0]['comparable']);
        $this->assertSame('cheap-model', $r['recommendation']['model']);
    }

    public function test_low_quality_n_on_the_current_model_refuses_the_whole_function(): void {
        $current = $this->bench_row('current-model', 0.90, 1.0, ['quality_n' => 5]);
        $cand = $this->bench_row('cheap-model', 0.90, 0.10);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 1000.0);

        $this->assertNull($r['recommendation']);
        $this->assertSame(model_recommender::NR_CURRENT_NOT_COMPARABLE, $r['no_recommendation']['reason']);
        $this->assertSame(model_recommender::NC_LOW_QUALITY_N, $r['current']['not_comparable_reason']);
        // The candidate's own run is fine, but there is no baseline to subtract.
        $this->assertTrue($r['candidates'][0]['comparable']);
        $this->assertNull($r['candidates'][0]['quality_delta']);
    }

    public function test_release_mismatch_candidate_is_refused(): void {
        $current = $this->bench_row('current-model', 0.90, 1.0);
        $cand = $this->bench_row('cheap-model', 0.95, 0.10, ['plugin_release' => '7.3.5']);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 1000.0);

        $this->assertNull($r['recommendation']);
        $c = $r['candidates'][0];
        $this->assertFalse($c['comparable']);
        $this->assertSame(model_recommender::NC_RELEASE_MISMATCH, $c['not_comparable_reason']);
        $this->assertStringContainsString('7.3.5', (string) $c['not_comparable_detail']);
        $this->assertNull($c['monthly_delta_usd']);
    }

    public function test_missing_release_on_a_run_is_a_mismatch(): void {
        $current = $this->bench_row('current-model', 0.90, 1.0);
        $cand = $this->bench_row('cheap-model', 0.95, 0.10, ['plugin_release' => null]);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 0.0);

        $this->assertSame(model_recommender::NC_RELEASE_MISMATCH, $r['candidates'][0]['not_comparable_reason']);
    }

    public function test_unfinished_run_is_refused(): void {
        $current = $this->bench_row('current-model', 0.90, 1.0);
        $cand = $this->bench_row('cheap-model', null, null, ['status' => 'queued']);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 0.0);

        $this->assertSame(model_recommender::NC_INCOMPLETE_RUN, $r['candidates'][0]['not_comparable_reason']);
    }

    public function test_missing_cost_is_refused_rather_than_treated_as_free(): void {
        // The $0.00 class of bug this whole feature exists to fix: a null price
        // must never read as "free and therefore cheapest".
        $current = $this->bench_row('current-model', 0.90, 1.0);
        $cand = $this->bench_row('unpriced-model', 0.90, null);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 0.0);

        $this->assertNull($r['recommendation']);
        $this->assertSame(model_recommender::NC_MISSING_METRIC, $r['candidates'][0]['not_comparable_reason']);
        $this->assertStringContainsString('cost per call', (string) $r['candidates'][0]['not_comparable_detail']);
    }

    // ------------------------------------------------------------------
    // Unmeasured: the honest day-one state.
    // ------------------------------------------------------------------

    public function test_unmeasured_current_model_yields_no_score_and_no_recommendation(): void {
        $cand = $this->bench_row('cheap-model', 0.95, 0.10);

        $r = model_recommender::evaluate(null, [$cand], $this->tunables(), self::REL, 5000.0);

        $this->assertNull($r['recommendation']);
        $this->assertSame(model_recommender::NR_CURRENT_UNMEASURED, $r['no_recommendation']['reason']);
        $this->assertFalse($r['current']['measured']);
        $this->assertFalse($r['current']['comparable']);
        // No 0.5 neutral stand-in, anywhere.
        $this->assertNull($r['current']['quality_score']);
        $this->assertNull($r['current']['cost_cents_per_call']);
        $this->assertNull($r['candidates'][0]['quality_delta']);
        $this->assertNull($r['candidates'][0]['monthly_delta_usd']);
        $this->assertStringContainsString('never been benchmarked', $r['candidates'][0]['why']);
    }

    public function test_no_benchmarks_at_all(): void {
        $r = model_recommender::evaluate(null, [], $this->tunables(), self::REL, 0.0);

        $this->assertNull($r['recommendation']);
        $this->assertSame(model_recommender::NR_NO_BENCHMARKS, $r['no_recommendation']['reason']);
        $this->assertSame([], $r['candidates']);
    }

    public function test_unmeasured_list_names_every_function_with_no_bench_row(): void {
        set_config('provider', 'google', 'local_ai_course_assistant');
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');

        $unmeasured = model_recommender::unmeasured(['novolume' => true]);
        $functions = array_column($unmeasured, 'function');

        // Nothing is benchmarked on a fresh site, so every configured function
        // must appear — and none of them may carry a score.
        $this->assertContains('chat', $functions);
        $this->assertContains('quiz', $functions);
        $this->assertContains('analytics', $functions);
        foreach ($unmeasured as $row) {
            $this->assertSame(model_recommender::NR_NO_BENCHMARKS, $row['reason']);
            $this->assertArrayNotHasKey('quality_score', $row);
            $this->assertNotSame('', $row['rationale']);
        }
        $chat = $unmeasured[array_search('chat', $functions, true)];
        $this->assertSame('gemini-2.5-flash', $chat['model']);
    }

    public function test_unconfigured_function_says_so(): void {
        set_config('embed_provider', '', 'local_ai_course_assistant');
        set_config('embed_model', '', 'local_ai_course_assistant');

        $card = model_recommender::for_function('rag', ['bench' => [], 'novolume' => true]);

        $this->assertSame(model_recommender::NR_NOT_CONFIGURED, $card['no_recommendation']['reason']);
        $this->assertFalse($card['current']['configured']);
        $this->assertStringContainsString('nothing to compare', $card['rationale']);
    }

    // ------------------------------------------------------------------
    // Ranking.
    // ------------------------------------------------------------------

    public function test_biggest_saving_leads_and_refusals_sink_to_the_bottom(): void {
        $current = $this->bench_row('current-model', 0.90, 1.0);
        $ok1 = $this->bench_row('saves-a-little', 0.90, 0.80);
        $ok2 = $this->bench_row('saves-a-lot', 0.90, 0.20);
        $nogain = $this->bench_row('no-gain', 0.90, 0.99);
        $refused = $this->bench_row('refused', 0.99, 0.01, ['quality_n' => 2]);

        $r = model_recommender::evaluate($current, [$nogain, $ok1, $refused, $ok2], $this->tunables(), self::REL, 1000.0);

        $order = array_column($r['candidates'], 'model');
        $this->assertSame(['saves-a-lot', 'saves-a-little', 'no-gain', 'refused'], $order);
        $this->assertSame('saves-a-lot', $r['recommendation']['model']);
    }

    // ------------------------------------------------------------------
    // Volume and the monthly projection (DB-backed).
    // ------------------------------------------------------------------

    public function test_monthly_delta_uses_observed_call_volume_from_msgs(): void {
        $this->seed_msgs('gemini-2.5-flash', 300, 'assistant', null);

        set_config('provider', 'google', 'local_ai_course_assistant');
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');

        $bench = [
            $this->bench_row('gemini-2.5-flash', 0.90, 0.056),
            $this->bench_row('gemini-2.5-flash-lite', 0.89, 0.010),
        ];
        $card = model_recommender::for_function('chat', ['bench' => $bench, 'release' => self::REL]);

        $this->assertTrue($card['volume']['observed']);
        $this->assertSame(300, $card['volume']['calls']);
        $this->assertGreaterThan(0.0, $card['volume']['calls_per_month']);

        $rec = $card['recommendation'];
        $this->assertSame('gemini-2.5-flash-lite', $rec['model']);
        $expected = (0.010 - 0.056) * $card['volume']['calls_per_month'] / 100.0;
        $this->assertEqualsWithDelta($expected, $rec['monthly_delta_usd'], 1.0e-9);
        $this->assertLessThan(0.0, $rec['monthly_delta_usd']);
        $this->assertStringContainsString('less per month', $card['rationale']);
        $this->assertStringContainsString('gemini-2.5-flash-lite', $card['rationale']);
    }

    public function test_volume_excludes_non_billable_rows(): void {
        // Learner rows and other models must not inflate the projection: the
        // predicate is analytics::spend_rows_predicate(), reused verbatim.
        $this->seed_msgs('gemini-2.5-flash', 10, 'assistant', null);
        $this->seed_msgs('gemini-2.5-flash', 40, 'user', null);
        $this->seed_msgs('some-other-model', 40, 'assistant', null);

        $v = model_recommender::volume_for('chat', 'gemini-2.5-flash');

        $this->assertSame(10, $v['calls']);
    }

    public function test_no_observed_volume_reports_unavailable_not_zero_dollars(): void {
        $bench = [
            $this->bench_row('current-model', 0.90, 1.0),
            $this->bench_row('cheap-model', 0.90, 0.10),
        ];
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('model', 'current-model', 'local_ai_course_assistant');

        $card = model_recommender::for_function('chat', ['bench' => $bench, 'release' => self::REL]);

        $this->assertFalse($card['volume']['observed']);
        $this->assertSame(0, $card['volume']['calls']);
        // Null, not 0.0: "we do not know" and "it costs the same" are opposite
        // statements to an admin reading the card.
        $this->assertNull($card['recommendation']['monthly_delta_usd']);
        $this->assertStringContainsString('no monthly figure is projected', $card['rationale']);
    }

    public function test_shared_volume_between_functions_is_declared(): void {
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('model', 'gpt-4o-mini', 'local_ai_course_assistant');
        set_config('quiz_provider', '', 'local_ai_course_assistant');
        set_config('quiz_model', '', 'local_ai_course_assistant');

        $v = model_recommender::volume_for('chat', 'gpt-4o-mini', ['novolume' => true]);

        // quiz inherits the site model in the same capability bucket, so the two
        // functions are reading one population.
        $this->assertContains('quiz', $v['shared_with']);
    }

    // ------------------------------------------------------------------
    // Configuration resolution.
    // ------------------------------------------------------------------

    public function test_inherited_model_is_flagged_as_inherited(): void {
        set_config('provider', 'google', 'local_ai_course_assistant');
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');
        set_config('quiz_model', '', 'local_ai_course_assistant');

        $quiz = model_recommender::configured_model('quiz');
        $this->assertSame('gemini-2.5-flash', $quiz['model']);
        $this->assertSame('model', $quiz['resolved_from']);
        $this->assertTrue($quiz['inherited']);

        set_config('quiz_model', 'gpt-4o-mini', 'local_ai_course_assistant');
        $quiz = model_recommender::configured_model('quiz');
        $this->assertSame('gpt-4o-mini', $quiz['model']);
        $this->assertSame('quiz_model', $quiz['resolved_from']);
        $this->assertFalse($quiz['inherited']);
    }

    public function test_configured_model_carries_the_live_price_and_its_provenance(): void {
        set_config('provider', 'google', 'local_ai_course_assistant');
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');

        $chat = model_recommender::configured_model('chat');

        // The registry corrected this in v7.4.0; before that the model matched
        // no prefix and all production chat spend computed as $0.00.
        $this->assertNotNull($chat['rate']);
        $this->assertEqualsWithDelta(0.30, (float) $chat['rate']['input'], 0.0001);
        $this->assertEqualsWithDelta(2.50, (float) $chat['rate']['output'], 0.0001);
        $this->assertNotEmpty($chat['rate_provenance']);
    }

    public function test_unknown_function_is_a_coding_exception(): void {
        $this->expectException(\coding_exception::class);
        model_recommender::for_function('not-a-function');
    }

    // ------------------------------------------------------------------
    // Bench-table read path.
    // ------------------------------------------------------------------

    public function test_bench_rows_prefer_the_current_release_over_a_newer_stale_run(): void {
        // A fresher run measured on the previous release must not hide a
        // comparable run for the same model — otherwise the model silently
        // disappears from the comparison instead of being usable.
        $this->insert_bench('chat', 'cheap-model', 0.90, 0.10, ['plugin_release' => self::REL, 'timecreated' => 100]);
        $this->insert_bench('chat', 'cheap-model', 0.99, 0.01, ['plugin_release' => '7.3.5', 'timecreated' => 900]);
        $this->insert_bench('chat', 'current-model', 0.90, 1.0, ['plugin_release' => self::REL, 'timecreated' => 100]);

        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('model', 'current-model', 'local_ai_course_assistant');

        $card = model_recommender::for_function('chat', ['release' => self::REL, 'novolume' => true]);

        $this->assertSame('cheap-model', $card['recommendation']['model']);
        $this->assertSame(self::REL, $card['recommendation']['plugin_release']);
        $this->assertEqualsWithDelta(0.10, (float) $card['recommendation']['cost_cents_per_call'], 1.0e-9);
    }

    public function test_bench_rows_ignore_another_functions_runs(): void {
        $this->insert_bench('quiz', 'quiz-only-model', 0.99, 0.01, ['plugin_release' => self::REL]);
        $this->insert_bench('chat', 'current-model', 0.90, 1.0, ['plugin_release' => self::REL]);

        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('model', 'current-model', 'local_ai_course_assistant');

        $card = model_recommender::for_function('chat', ['release' => self::REL, 'novolume' => true]);

        $this->assertSame([], array_column($card['candidates'], 'model'));
        $this->assertSame(model_recommender::NR_NO_BENCHMARKS, $card['no_recommendation']['reason']);
    }

    public function test_a_run_from_a_different_fixture_set_is_refused_not_compared(): void {
        // 0.95 on a RAG recall fixture and 0.90 on the tutor rubric are not the
        // same number. Comparing them would be the most confident wrong answer
        // this class could give.
        $current = $this->bench_row('current-model', 0.90, 1.0, [
            'harness' => 'tutor_golden',
            'fixture_set' => 'tutor-golden-50',
            'fixture_n' => 50,
        ]);
        $cand = $this->bench_row('cheap-model', 0.95, 0.10, [
            'harness' => 'rag_fixture',
            'fixture_set' => 'rag-bus101',
            'fixture_n' => 40,
            'quality_metric' => 'recall_at_3',
        ]);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 1000.0);

        $this->assertNull($r['recommendation']);
        $this->assertSame(model_recommender::NC_FIXTURE_MISMATCH, $r['candidates'][0]['not_comparable_reason']);
        $this->assertNull($r['candidates'][0]['monthly_delta_usd']);
        $this->assertStringContainsString('recall_at_3', (string) $r['candidates'][0]['not_comparable_detail']);
    }

    public function test_same_metric_with_different_fixture_set_is_refused(): void {
        $current = $this->bench_row('current-model', 0.90, 1.0, [
            'fixture_set' => 'tutor-golden-50',
            'fixture_n' => 50,
        ]);
        $cand = $this->bench_row('cheap-model', 0.95, 0.10, [
            'fixture_set' => 'tutor-golden-10',
            'fixture_n' => 10,
        ]);

        $r = model_recommender::evaluate($current, [$cand], $this->tunables(), self::REL, 0.0);

        $this->assertSame(model_recommender::NC_FIXTURE_MISMATCH, $r['candidates'][0]['not_comparable_reason']);
    }

    public function test_a_low_sample_run_survives_the_bench_read_to_be_refused_by_name(): void {
        // model_bench::latest_by_function() applies its OWN quality_n floor, so
        // the recommender asks for a floor of 1: a run dropped by the loader
        // cannot be reported as refused, and the model would silently vanish
        // from the card instead.
        $this->insert_bench('chat', 'current-model', 0.90, 1.0, ['quality_n' => 50]);
        $this->insert_bench('chat', 'thin-model', 0.99, 0.01, ['quality_n' => 6]);

        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('model', 'current-model', 'local_ai_course_assistant');

        $card = model_recommender::for_function('chat', ['release' => self::REL, 'novolume' => true]);

        $this->assertSame(['thin-model'], array_column($card['candidates'], 'model'));
        $this->assertSame(model_recommender::NC_LOW_QUALITY_N, $card['candidates'][0]['not_comparable_reason']);
        $this->assertNull($card['recommendation']);
        $this->assertStringContainsString('too few items', $card['rationale']);
    }

    public function test_report_covers_every_sola_function(): void {
        $r = model_recommender::report(['novolume' => true]);

        $this->assertSame(
            ['chat', 'quiz', 'classifier', 'rag', 'analytics', 'safety', 'soapbox'],
            array_keys($r['functions'])
        );
        $this->assertSame(model_recommender::DEFAULT_SAVINGS_FLOOR, $r['tunables']['savingsfloor']);
        $this->assertNotSame('', $r['release']);
    }

    public function test_tunable_settings_override_the_defaults(): void {
        set_config('rec_quality_epsilon', '0.10', 'local_ai_course_assistant');
        set_config('rec_savings_floor', '0.50', 'local_ai_course_assistant');
        set_config('rec_quality_margin', '0.20', 'local_ai_course_assistant');
        set_config('rec_min_quality_n', '75', 'local_ai_course_assistant');

        $t = model_recommender::tunables();
        $this->assertEqualsWithDelta(0.10, $t['epsilon'], 1.0e-9);
        $this->assertEqualsWithDelta(0.50, $t['savingsfloor'], 1.0e-9);
        $this->assertEqualsWithDelta(0.20, $t['margin'], 1.0e-9);
        $this->assertSame(75, $t['minqualityn']);

        // A 30% cut clears the default floor but not a configured 50% floor.
        $current = $this->bench_row('current-model', 0.90, 1.0);
        $cand = $this->bench_row('cheap-model', 0.90, 0.70, ['quality_n' => 100]);
        $r = model_recommender::evaluate($current, [$cand], $t, self::REL, 0.0);
        $this->assertNull($r['recommendation']);
    }

    /**
     * Insert a benchmark row.
     *
     * @param string $function
     * @param string $model
     * @param float|null $quality
     * @param float|null $cost
     * @param array $extra
     * @return int
     */
    private function insert_bench(string $function, string $model, ?float $quality, ?float $cost, array $extra = []): int {
        global $DB;
        static $seq = 0;
        $seq++;
        $row = (object) array_merge([
            'runid' => 'run-' . $seq,
            'harness' => 'tutor_golden',
            'sola_function' => $function,
            'registry_key' => $model,
            'provider' => 'openai',
            'model_name' => $model,
            'quality_metric' => 'rubric_mean',
            'quality_score' => $quality,
            'quality_n' => 50,
            'cost_cents_per_call' => $cost,
            'p50_ttft_ms' => 500,
            'plugin_release' => self::REL,
            'status' => 'complete',
            'timecreated' => 1000 + $seq,
        ], $extra);
        return (int) $DB->insert_record(model_registry::TABLE_BENCH, $row);
    }

    /**
     * Insert billable-looking message rows.
     *
     * @param string $model
     * @param int $count
     * @param string $role
     * @param string|null $interactiontype
     * @return void
     */
    private function seed_msgs(string $model, int $count, string $role, ?string $interactiontype): void {
        global $DB;
        $now = time() - 5 * 86400;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = (object) [
                'conversationid' => 1,
                'userid' => 2,
                'courseid' => 2,
                'role' => $role,
                'message' => 'x',
                'provider' => 'google',
                'model_name' => $model,
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'interaction_type' => $interactiontype,
                'timecreated' => $now + $i,
            ];
        }
        $DB->insert_records('local_ai_course_assistant_msgs', $rows);
    }
}
