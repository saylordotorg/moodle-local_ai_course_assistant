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

use local_ai_course_assistant\task\model_price_drift_check;

/**
 * The operator-facing surface of the model registry.
 *
 * These tests are about what an operator is TOLD, because that is where this
 * page can do damage: a price that reads as $0.00 when it is unknown, a write
 * that reports success when it was refused, a benchmark that looks comparable
 * when it was scored on six items. The arithmetic itself is covered by
 * model_registry_test, price_source_test, model_bench_test and
 * model_recommender_test.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\model_registry_page
 */
final class model_registry_page_test extends \advanced_testcase {
    /**
     * Fresh caches for every test: the registry caches the merged rate map for
     * the life of the request, and a test that wrote a row would otherwise read
     * the previous test's map.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        model_registry::reset_cache();
    }

    /**
     * A billable message row for a model.
     *
     * @param string $model
     * @param string $provider
     * @param int $when
     * @return void
     */
    private function bill(string $model, string $provider = 'gemini', int $when = 0): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => 0,
            'userid' => 0,
            'courseid' => SITEID,
            'role' => 'assistant',
            'message' => 'x',
            'tokens_used' => 100,
            'prompt_tokens' => 60,
            'completion_tokens' => 40,
            'model_name' => $model,
            'provider' => $provider,
            'interaction_type' => 'chat',
            'timecreated' => $when > 0 ? $when : time() - HOURSECS,
        ]);
    }

    // ------------------------------------------------------------- Unpriced.

    public function test_a_model_in_traffic_with_no_price_is_reported(): void {
        $this->bill('some-unpriced-model-xyz');

        $block = model_registry_page::unpriced_block();

        $this->assertTrue($block['has'], 'An unpriced model in billable traffic must be reported.');
        $this->assertSame(1, $block['count']);
        $this->assertSame('some-unpriced-model-xyz', $block['rows'][0]['model']);
        $this->assertSame('gemini', $block['rows'][0]['provider']);
        $this->assertSame('1', $block['rows'][0]['calls']);
    }

    public function test_gemini_25_flash_is_priced_and_therefore_absent(): void {
        // The regression guard for the defect this release exists to fix: this
        // model was in every production message row and in no rate card, so
        // 100% of chat spend computed as $0.00.
        $this->bill('gemini-2.5-flash');

        $block = model_registry_page::unpriced_block();

        $this->assertFalse($block['has'], 'gemini-2.5-flash must resolve to a price.');
        $this->assertSame(0, $block['count']);
    }

    public function test_an_unpriced_model_a_source_knows_carries_the_proposal(): void {
        $this->bill('mystery-model-1');
        set_config(model_price_drift_check::SUMMARY_KEY, json_encode([
            'status' => 'findings',
            'timerun' => time(),
            'findings' => [[
                'type' => 'missing',
                'modelkey' => 'mystery-model-1',
                'provider' => 'openai',
                'capability' => 'chat',
                'input' => 1.25,
                'output' => 5.0,
                'context' => 200000,
                'sourcename' => 'Test feed',
            ]],
        ]), 'local_ai_course_assistant');

        $row = model_registry_page::unpriced_block()['rows'][0];

        $this->assertTrue($row['hasproposal']);
        $this->assertSame('1.25', $row['proposal']['input']);
        $this->assertSame('Test feed', $row['proposal']['sourcename']);
    }

    public function test_the_unpriced_banner_makes_no_network_call_when_no_check_has_run(): void {
        // No stored summary at all: the banner must still render from traffic
        // alone. A page load that fetched every pricing source would make the
        // most important block on the page the slowest and the least reliable.
        $this->bill('mystery-model-2');

        $row = model_registry_page::unpriced_block()['rows'][0];

        $this->assertFalse($row['hasproposal']);
        $this->assertNull($row['proposal']);
    }

    // ---------------------------------------------------------- Provenance.

    public function test_a_baseline_price_is_labelled_as_shipped_not_as_administered(): void {
        $rows = model_registry_page::effective_rows();
        $bykey = array_column($rows['rows'], null, 'modelkey');

        $this->assertArrayHasKey('gemini-2.5-flash', $bykey);
        $row = $bykey['gemini-2.5-flash'];
        $this->assertSame(
            get_string('modelregistry:layer_baseline', 'local_ai_course_assistant'),
            $row['layerlabel']
        );
        $this->assertFalse($row['intable'], 'A baseline row has no registry row to edit or delete.');
        $this->assertSame('', $row['setby'], 'Nobody set a shipped price, so nobody is named.');
    }

    public function test_a_manual_row_names_the_administrator_who_set_it(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);

        model_registry_page::save_model([
            'modelkey' => 'test-model-prov',
            'provider' => 'openai',
            'capability' => 'chat',
            'input_rate' => '1.0',
            'output_rate' => '4.0',
            'notes' => 'checked against the vendor page',
        ], (int) $user->id);

        $bykey = array_column(model_registry_page::effective_rows()['rows'], null, 'modelkey');
        $row = $bykey['test-model-prov'];

        $this->assertSame(
            get_string('modelregistry:layer_table', 'local_ai_course_assistant'),
            $row['layerlabel']
        );
        $this->assertSame(
            get_string('modelregistry:source_manual', 'local_ai_course_assistant'),
            $row['sourcelabel']
        );
        $this->assertSame('Ada Lovelace', $row['setby']);
        $this->assertTrue($row['intable']);
        $this->assertNotSame('', $row['updated']);
        $this->assertSame('checked against the vendor page', $row['notes']);
    }

    public function test_a_feed_row_is_attributed_to_the_feed_and_not_to_a_person(): void {
        // addedby is null for a feed write on purpose: stamping the cron user
        // would misreport who is accountable for the number.
        model_registry::upsert([
            'modelkey' => 'test-feed-model',
            'input_rate' => 2.0,
            'output_rate' => 6.0,
        ], 'upstream', null);
        model_registry::reset_cache();

        $bykey = array_column(model_registry_page::effective_rows()['rows'], null, 'modelkey');
        $row = $bykey['test-feed-model'];

        $this->assertSame(
            get_string('modelregistry:setby_feed', 'local_ai_course_assistant'),
            $row['setby']
        );
        $this->assertSame(
            get_string('modelregistry:source_upstream', 'local_ai_course_assistant'),
            $row['sourcelabel']
        );
    }

    // -------------------------------------------------------------- Writes.

    public function test_saving_a_model_reports_insert_and_update_distinctly(): void {
        $result = model_registry_page::save_model([
            'modelkey' => 'brand-new-model', 'input_rate' => '1', 'output_rate' => '2',
        ], 0);
        $this->assertSame(model_registry_page::OK, $result['level']);
        $this->assertStringContainsString('brand-new-model', $result['message']);

        $again = model_registry_page::save_model([
            'modelkey' => 'brand-new-model', 'input_rate' => '3', 'output_rate' => '4',
        ], 0);
        $this->assertSame(model_registry_page::OK, $again['level']);
        $this->assertNotSame(
            $result['message'],
            $again['message'],
            'An update must not be reported with the same sentence as an insert.'
        );

        model_registry::reset_cache();
        $this->assertSame(['input' => 3.0, 'output' => 4.0], model_registry::rate_for('brand-new-model'));
    }

    public function test_an_empty_price_stays_unknown_rather_than_becoming_zero(): void {
        // The whole point of the release: an unpriced model must read as
        // unknown, never as free. A form that turned an empty field into 0.0
        // would recreate the $0.00 reading with an administrator's name on it.
        model_registry_page::save_model([
            'modelkey' => 'unknown-price-model',
            'provider' => 'openai',
            'input_rate' => '',
            'output_rate' => '',
        ], 0);
        model_registry::reset_cache();

        global $DB;
        $row = $DB->get_record(
            model_registry::TABLE_MODELS,
            ['modelkey' => 'unknown-price-model']
        );
        $this->assertNull($row->input_rate);
        $this->assertNull($row->output_rate);
        $this->assertSame('—', model_registry_page::rate($row->input_rate));
        $this->assertNotSame(
            model_registry_page::rate(0.0),
            model_registry_page::rate(null),
            'Unknown and zero must not render identically.'
        );
    }

    public function test_a_non_numeric_price_is_refused_with_a_reason(): void {
        $result = model_registry_page::save_model([
            'modelkey' => 'bad-rate-model', 'input_rate' => 'free', 'output_rate' => '2',
        ], 0);

        $this->assertSame(model_registry_page::ERROR, $result['level']);
        global $DB;
        $this->assertFalse(
            $DB->record_exists(model_registry::TABLE_MODELS, ['modelkey' => 'bad-rate-model']),
            'A refused save must write nothing.'
        );
    }

    public function test_a_missing_model_key_is_refused(): void {
        $result = model_registry_page::save_model(['modelkey' => '   '], 0);
        $this->assertSame(model_registry_page::ERROR, $result['level']);
    }

    public function test_deleting_a_registry_row_falls_back_to_the_baseline(): void {
        model_registry_page::save_model([
            'modelkey' => 'gemini-2.5-flash', 'input_rate' => '99', 'output_rate' => '99',
        ], 0);
        model_registry::reset_cache();
        $this->assertSame(99.0, model_registry::rate_for('gemini-2.5-flash')['input']);

        $result = model_registry_page::delete_model('gemini-2.5-flash');
        $this->assertSame(model_registry_page::OK, $result['level']);
        model_registry::reset_cache();

        $this->assertSame(
            0.30,
            model_registry::rate_for('gemini-2.5-flash')['input'],
            'Deleting the override must restore the shipped price, not remove the price.'
        );
    }

    public function test_deleting_a_row_that_does_not_exist_says_so(): void {
        $result = model_registry_page::delete_model('never-existed');
        $this->assertSame(model_registry_page::ERROR, $result['level']);
    }

    // --------------------------------------------------- Applying a finding.

    public function test_applying_a_drift_price_is_recorded_as_a_feed_not_as_a_human(): void {
        $user = $this->getDataGenerator()->create_user();

        $result = model_registry_page::apply_drift([
            'modelkey' => 'drifted-model',
            'provider' => 'openai',
            'capability' => 'chat',
            'input_rate' => '0.5',
            'output_rate' => '1.5',
        ], (int) $user->id);

        $this->assertSame(model_registry_page::OK, $result['level']);
        global $DB;
        $row = $DB->get_record(model_registry::TABLE_MODELS, ['modelkey' => 'drifted-model']);
        $this->assertSame('drift', $row->source);
        $this->assertNull(
            $row->addedby,
            'The number came from a feed. Recording a person as its author would make provenance lie.'
        );
    }

    public function test_a_finding_with_no_price_cannot_be_applied(): void {
        $result = model_registry_page::apply_drift([
            'modelkey' => 'no-proposal-model', 'input_rate' => '', 'output_rate' => '',
        ], 0);

        $this->assertSame(model_registry_page::ERROR, $result['level']);
        global $DB;
        $this->assertFalse(
            $DB->record_exists(model_registry::TABLE_MODELS, ['modelkey' => 'no-proposal-model']),
            'Applying an empty proposal must not create a row priced at zero.'
        );
    }

    public function test_a_feed_cannot_overwrite_an_administrators_price_and_says_so(): void {
        // The pre-v7.4.0 defect, in miniature: the weekly run wrote the whole
        // override blob and destroyed every hand-entered price.
        model_registry_page::save_model([
            'modelkey' => 'pinned-model', 'input_rate' => '1', 'output_rate' => '2',
        ], 0);
        model_registry::reset_cache();

        $result = model_registry_page::apply_drift([
            'modelkey' => 'pinned-model', 'input_rate' => '9', 'output_rate' => '9',
        ], 0);

        $this->assertSame(model_registry_page::WARN, $result['level']);
        model_registry::reset_cache();
        $this->assertSame(
            1.0,
            model_registry::rate_for('pinned-model')['input'],
            'The administrator price must survive.'
        );
    }

    // ------------------------------------------------------ Pricing sources.

    public function test_a_source_is_a_row_an_operator_can_add(): void {
        $result = model_registry_page::save_source([
            'name' => 'Vendor page',
            'url' => 'https://example.org/pricing.json',
            'format' => 'json_generic',
            'spec' => '{"inputpath": "in"}',
            'enabled' => 1,
        ], 0);

        $this->assertSame(model_registry_page::OK, $result['level']);
        $rows = model_registry_page::source_rows();
        $this->assertTrue($rows['has']);
        $this->assertSame('Vendor page', $rows['rows'][0]['name']);
        $this->assertTrue($rows['rows'][0]['enabled']);
    }

    public function test_an_invalid_parse_spec_is_refused_at_the_form(): void {
        // Refused here rather than discovered as a failed cron fetch tomorrow.
        $result = model_registry_page::save_source([
            'name' => 'Broken', 'url' => 'https://example.org/x.json',
            'format' => 'litellm', 'spec' => '{not json',
        ], 0);

        $this->assertSame(model_registry_page::ERROR, $result['level']);
        global $DB;
        $this->assertSame(0, $DB->count_records(model_registry::TABLE_SOURCES));
    }

    public function test_a_non_http_source_url_is_refused(): void {
        foreach (['', 'file:///etc/passwd', 'ftp://example.org/x'] as $url) {
            $result = model_registry_page::save_source([
                'name' => 'Bad', 'url' => $url, 'format' => 'litellm',
            ], 0);
            $this->assertSame(model_registry_page::ERROR, $result['level'], "URL: {$url}");
        }
    }

    public function test_an_unknown_source_format_is_refused(): void {
        $result = model_registry_page::save_source([
            'name' => 'Bad', 'url' => 'https://example.org/x', 'format' => 'my_own_parser',
        ], 0);
        $this->assertSame(model_registry_page::ERROR, $result['level']);
    }

    public function test_a_source_can_be_disabled_and_re_enabled_without_deleting_it(): void {
        model_registry_page::save_source([
            'name' => 'Feed', 'url' => 'https://example.org/x.json', 'format' => 'litellm', 'enabled' => 1,
        ], 0);
        global $DB;
        $id = (int) $DB->get_field(model_registry::TABLE_SOURCES, 'id', ['name' => 'Feed']);

        $this->assertSame(model_registry_page::OK, model_registry_page::toggle_source($id, false)['level']);
        $this->assertSame(0, (int) $DB->get_field(model_registry::TABLE_SOURCES, 'enabled', ['id' => $id]));
        model_registry_page::toggle_source($id, true);
        $this->assertSame(1, (int) $DB->get_field(model_registry::TABLE_SOURCES, 'enabled', ['id' => $id]));
    }

    public function test_deleting_a_source_takes_its_regression_marker_with_it(): void {
        model_registry_page::save_source([
            'name' => 'Feed', 'url' => 'https://example.org/x.json', 'format' => 'litellm',
        ], 0);
        global $DB;
        $id = (int) $DB->get_field(model_registry::TABLE_SOURCES, 'id', ['name' => 'Feed']);
        set_config(price_source::count_key($id), '812', 'local_ai_course_assistant');

        model_registry_page::delete_source($id);

        $this->assertFalse(
            $DB->record_exists(model_registry::TABLE_SOURCES, ['id' => $id])
        );
        $this->assertFalse(
            get_config('local_ai_course_assistant', price_source::count_key($id)),
            'A stale count would make the next source with a recycled id report a false regression.'
        );
    }

    public function test_a_failed_source_shows_its_message_verbatim(): void {
        model_registry_page::save_source([
            'name' => 'Feed', 'url' => 'https://example.org/x.json', 'format' => 'litellm',
        ], 0);
        global $DB;
        $row = $DB->get_record(model_registry::TABLE_SOURCES, ['name' => 'Feed']);
        $message = 'REGRESSION: this source returned 812 prices on its previous run and 0 now. '
            . 'Source returned an empty body.';
        price_source::record_status($row, false, $message, 0);
        // price_source is loud about a failed source on purpose.
        $this->assertDebuggingCalled();

        $rendered = model_registry_page::source_rows()['rows'][0];

        $this->assertTrue($rendered['statuserror']);
        $this->assertSame(
            $message,
            $rendered['lastmessage'],
            'Truncating this would remove the only explanation an operator gets.'
        );
    }

    // ------------------------------------------- Benchmarks / recommendations.

    public function test_every_sola_function_gets_a_card_even_with_no_benchmarks(): void {
        $block = model_registry_page::bench_block();

        $this->assertNotEmpty($block['cards']);
        $this->assertCount(count(model_recommender::FUNCTIONS), $block['cards']);
        foreach ($block['cards'] as $card) {
            $this->assertNotSame('', $card['label'], 'Every card needs a localized function label.');
            // With nothing measured, a card must carry a stated reason rather
            // than an empty space or an invented score.
            $this->assertNotNull($card['norecommendation']);
            $this->assertNotSame('', $card['norecommendation']);
            $this->assertNull($card['recommendation']);
        }
    }

    public function test_a_function_label_is_localized_not_a_php_literal(): void {
        $this->assertSame(
            get_string('modelregistry:function_chat', 'local_ai_course_assistant'),
            model_registry_page::function_label('chat')
        );
        $this->assertNotSame(
            model_recommender::FUNCTIONS['chat']['label'],
            'modelregistry:function_chat',
            'The English label in FUNCTIONS is for logs; the page must not render it.'
        );
    }

    public function test_a_low_sample_refusal_names_the_count_and_the_floor(): void {
        $label = model_registry_page::not_comparable_label(
            model_recommender::NC_LOW_QUALITY_N,
            ['quality_n' => 6],
            ['minqualityn' => 20]
        );

        $this->assertStringContainsString('6', $label);
        $this->assertStringContainsString('20', $label);
    }

    public function test_a_release_mismatch_refusal_names_both_releases(): void {
        $label = model_registry_page::not_comparable_label(
            model_recommender::NC_RELEASE_MISMATCH,
            ['plugin_release' => '7.3.5'],
            ['release' => '7.4.0']
        );

        $this->assertStringContainsString('7.3.5', $label);
        $this->assertStringContainsString('7.4.0', $label);
    }

    public function test_every_refusal_code_renders_as_something(): void {
        // A refusal with no sentence is worse than no card: the operator is
        // shown an empty verdict and cannot tell whether the tool is broken.
        $codes = [
            model_recommender::NC_INCOMPLETE_RUN,
            model_recommender::NC_MISSING_METRIC,
            model_recommender::NC_LOW_QUALITY_N,
            model_recommender::NC_RELEASE_MISMATCH,
            model_recommender::NC_FIXTURE_MISMATCH,
        ];
        foreach ($codes as $code) {
            $this->assertNotSame('', model_registry_page::not_comparable_label(
                $code,
                ['quality_n' => 1, 'plugin_release' => '7.0.0'],
                ['minqualityn' => 20, 'release' => '7.4.0']
            ), $code);
        }
        $nrcodes = [
            model_recommender::NR_NOT_CONFIGURED,
            model_recommender::NR_NO_BENCHMARKS,
            model_recommender::NR_CURRENT_UNMEASURED,
            model_recommender::NR_NO_COMPARABLE_CANDIDATES,
            model_recommender::NR_NO_MATERIAL_GAIN,
            model_recommender::NR_CURRENT_NOT_COMPARABLE,
        ];
        foreach ($nrcodes as $code) {
            $this->assertNotSame(
                '',
                model_registry_page::no_recommendation_label($code, ['not_comparable_reason' => 'low_quality_n'], []),
                $code
            );
        }
        foreach ([
            model_recommender::VERDICT_COST_SAVING,
            model_recommender::VERDICT_QUALITY_GAIN,
            model_recommender::VERDICT_NO_GAIN,
            model_recommender::VERDICT_NOT_COMPARABLE,
        ] as $verdict) {
            $this->assertNotSame('', model_registry_page::verdict_label($verdict), $verdict);
        }
    }

    public function test_a_stored_run_is_shown_with_its_sample_size_and_grade(): void {
        $runid = model_bench::start_run([
            'harness' => 'tutor_golden',
            'sola_function' => 'chat',
            'registry_key' => 'gemini-2.5-flash',
            'provider' => 'gemini',
            'model_name' => 'gemini-2.5-flash',
            'fixture_set' => 'tests/golden/tutor_prompts.json',
            'quality_metric' => 'rubric_mean',
            'status' => model_bench::STATUS_RUNNING,
        ]);
        model_bench::complete_run($runid, [
            'quality_raw' => 14.56,
            'quality_max' => 15.0,
            'quality_n' => 50,
            'fixture_n' => 50,
            'cost_cents_per_call' => 0.056,
            'p50_ttft_ms' => 640,
            'calls' => 50,
            'errors' => 0,
        ]);

        $cards = array_column(model_registry_page::bench_block()['cards'], null, 'function');
        $runs = $cards['chat']['runs'];

        $this->assertNotEmpty($runs);
        $run = $runs[0];
        $this->assertSame('gemini-2.5-flash', $run['model']);
        // Native units, not the normalized score: "0.97" hides that the scale
        // was 15 and that fifty items were scored.
        $this->assertStringContainsString('14.56', $run['quality']['value']);
        $this->assertStringContainsString('15', $run['quality']['value']);
        $this->assertStringContainsString('50', $run['quality']['n']);
        // 50 fixtures is under the 200 floor, so it must be stamped SMOKE even
        // though it is the full golden set.
        $this->assertStringContainsString('SMOKE', $run['grade']);
    }

    public function test_the_fixture_grade_demotes_the_two_course_smoke_set_at_any_size(): void {
        // Two courses cannot represent the 13-course production shape however
        // many fixtures are padded into them.
        $this->assertStringContainsString(
            'SMOKE',
            model_registry_page::grade_stamp('rag_fixtures_bus101_pol101.json', 900)
        );
        $this->assertStringContainsString(
            'SMOKE',
            model_registry_page::grade_stamp('anything.json', 199)
        );
        $this->assertStringNotContainsString(
            'SMOKE',
            model_registry_page::grade_stamp('rag_fixtures_prodshape_anchored_2026-08-27.json', 816)
        );
    }

    public function test_a_below_floor_run_is_labelled_reference_only_not_hidden(): void {
        set_config('bench_min_quality_n', 20, 'local_ai_course_assistant');
        $runid = model_bench::start_run([
            'harness' => 'tutor_golden', 'sola_function' => 'chat',
            'model_name' => 'tiny-sample-model', 'provider' => 'openai',
            'quality_metric' => 'rubric_mean', 'status' => model_bench::STATUS_RUNNING,
        ]);
        model_bench::complete_run($runid, [
            'quality_raw' => 15.0, 'quality_max' => 15.0, 'quality_n' => 3,
            'cost_cents_per_call' => 0.001, 'calls' => 3, 'errors' => 0,
        ]);

        $cards = array_column(model_registry_page::bench_block()['cards'], null, 'function');
        $models = array_column($cards['chat']['runs'], null, 'model');

        $this->assertArrayHasKey(
            'tiny-sample-model',
            $models,
            'A below-floor run must still be visible: dropping it hides real evidence.'
        );
        $this->assertTrue($models['tiny-sample-model']['belowfloor']);
        $this->assertStringContainsString('3', $models['tiny-sample-model']['floorlabel']);
    }

    // --------------------------------------------------- Queueing a benchmark.

    public function test_queueing_a_benchmark_creates_a_visible_queued_row_and_a_task(): void {
        global $DB;
        model_registry_page::save_model([
            'modelkey' => 'claude-sonnet-5', 'provider' => 'claude', 'capability' => 'chat',
            'input_rate' => '3', 'output_rate' => '15',
        ], 0);

        $result = model_registry_page::queue_benchmark('claude-sonnet-5', 'chat', 5, 0);

        $this->assertSame(model_registry_page::OK, $result['level']);
        $this->assertNotEmpty($result['runid']);
        $row = model_bench::get_run($result['runid']);
        $this->assertSame(
            model_bench::STATUS_QUEUED,
            $row['status'],
            'The row must exist as queued BEFORE cron runs, so the operator can see the request landed.'
        );
        $this->assertSame('claude-sonnet-5', $row['registry_key']);
        $this->assertSame(
            1,
            $DB->count_records('task_adhoc', [
                'classname' => '\\local_ai_course_assistant\\task\\run_model_benchmark',
            ]),
            'Exactly one adhoc task must be queued.'
        );
    }

    public function test_a_benchmark_cannot_be_queued_for_an_unregistered_key(): void {
        global $DB;
        $result = model_registry_page::queue_benchmark('not-in-the-registry', 'chat', 5, 0);

        $this->assertSame(model_registry_page::ERROR, $result['level']);
        $this->assertSame(0, $DB->count_records(model_registry::TABLE_BENCH));
        $this->assertSame(0, $DB->count_records('task_adhoc'));
    }

    public function test_a_benchmark_cannot_be_queued_for_an_unknown_function(): void {
        model_registry_page::save_model([
            'modelkey' => 'some-model', 'input_rate' => '1', 'output_rate' => '1',
        ], 0);

        $result = model_registry_page::queue_benchmark('some-model', 'not_a_function', 5, 0);

        global $DB;
        $this->assertSame(model_registry_page::ERROR, $result['level']);
        $this->assertSame(0, $DB->count_records('task_adhoc'));
    }

    public function test_the_queue_form_only_offers_registered_keys(): void {
        $this->assertSame([], model_registry_page::queue_options());

        model_registry_page::save_model([
            'modelkey' => 'gpt-4o-mini', 'provider' => 'openai', 'input_rate' => '0.15',
            'output_rate' => '0.6',
        ], 0);

        $options = model_registry_page::queue_options();
        $this->assertCount(1, $options);
        $this->assertSame('gpt-4o-mini', $options[0]['value']);
        $this->assertStringContainsString('openai', $options[0]['label']);
    }

    // ------------------------------------------------------------ Formatting.

    public function test_a_price_never_loses_a_significant_digit(): void {
        // 0.075 rounded to two places is 0.08, a 7% error on a rate card.
        $this->assertSame('$0.075', model_registry_page::rate(0.075));
        $this->assertSame('$0.3', model_registry_page::rate(0.30));
        $this->assertSame('$2.5', model_registry_page::rate(2.50));
        $this->assertSame('$0', model_registry_page::rate(0.0));
        $this->assertSame('—', model_registry_page::rate(null));
        $this->assertSame('—', model_registry_page::rate(''));
    }

    public function test_a_delta_carries_its_sign(): void {
        $this->assertSame('+0.05', model_registry_page::signed(0.05, 4));
        $this->assertSame('-0.05', model_registry_page::signed(-0.05, 4));
        $this->assertSame('0', model_registry_page::signed(0.0, 4));
    }

    public function test_a_form_field_holds_the_number_and_not_the_currency(): void {
        $this->assertSame('0.3', model_registry_page::numberfield(0.30));
        $this->assertSame('', model_registry_page::numberfield(null));
    }

    // -------------------------------------------------------------- Template.

    public function test_the_page_template_renders_with_real_data(): void {
        // A mustache typo, or a section name that does not exist in the data,
        // is otherwise only discovered by loading the page. This renders the
        // real template with the real builders in both interesting states:
        // nothing configured, and something in every section.
        global $OUTPUT, $PAGE;
        $this->setAdminUser();
        $PAGE->set_url('/local/ai_course_assistant/model_registry.php');

        $html = $OUTPUT->render_from_template(
            'local_ai_course_assistant/model_registry',
            $this->templatedata()
        );
        $this->assertStringContainsString(
            get_string('modelregistry:effective_heading', 'local_ai_course_assistant'),
            $html
        );
        $this->assertStringNotContainsString('[[', $html, 'An unresolved lang key reached the page.');
        // A mustache comment ends at its first "}}", so a brace pair anywhere in
        // the docblock (an example context, or a tag being described) terminates
        // it early and prints the rest of the documentation into the page. It
        // did, on the first live load.
        $this->assertStringNotContainsString(
            'Context variables required for this template',
            $html,
            'The template docblock leaked into the rendered page.'
        );

        // Now with an unpriced model, a source, a finding and a stored run.
        $this->bill('some-unpriced-model-xyz');
        model_registry_page::save_model([
            'modelkey' => 'gpt-4o-mini', 'provider' => 'openai', 'capability' => 'chat',
            'input_rate' => '0.15', 'output_rate' => '0.6',
        ], 0);
        model_registry_page::save_source([
            'name' => 'Feed', 'url' => 'https://example.org/x.json',
            'format' => 'litellm', 'enabled' => 1,
        ], 0);
        set_config(model_price_drift_check::SUMMARY_KEY, json_encode([
            'status' => 'findings',
            'timerun' => time(),
            'counts' => ['missing' => 1, 'mismatch' => 0, 'new' => 0],
            'findings' => [[
                'type' => 'missing', 'modelkey' => 'some-unpriced-model-xyz',
                'provider' => 'openai', 'capability' => 'chat',
                'input' => 1.0, 'output' => 2.0, 'calls' => 12,
                'sourcename' => 'Feed',
            ]],
        ]), 'local_ai_course_assistant');
        model_registry::reset_cache();

        $html = $OUTPUT->render_from_template(
            'local_ai_course_assistant/model_registry',
            $this->templatedata()
        );

        $this->assertStringContainsString('some-unpriced-model-xyz', $html);
        $this->assertStringContainsString('gpt-4o-mini', $html);
        $this->assertStringContainsString('Feed', $html);
        $this->assertStringContainsString(
            get_string('modelregistry:drift_apply', 'local_ai_course_assistant'),
            $html
        );
        $this->assertStringNotContainsString('[[', $html);
        $this->assertStringNotContainsString('Context variables required for this template', $html);
    }

    /**
     * The same context the page builds, minus the request-scoped parts.
     *
     * @return array
     */
    private function templatedata(): array {
        $unpriced = model_registry_page::unpriced_block();
        $drift = model_registry_page::drift_block();
        $queuemodels = model_registry_page::queue_options();

        return [
            'backurl' => '/admin/category.php?category=local_ai_course_assistant',
            'backlabel' => get_string('modelregistry:back_to_settings', 'local_ai_course_assistant'),
            'posturl' => model_registry_page::PAGE_URL,
            'pageurl' => model_registry_page::PAGE_URL,
            'sesskey' => 'testsesskey',
            'l' => [
                'intro' => branding::str('modelregistry:intro'),
                'unpricedheading' => get_string('modelregistry:unpriced_heading', 'local_ai_course_assistant'),
                'unpriceddesc' => get_string(
                    'modelregistry:unpriced_desc',
                    'local_ai_course_assistant',
                    $unpriced['days']
                ),
                'unpricednone' => get_string(
                    'modelregistry:unpriced_none',
                    'local_ai_course_assistant',
                    $unpriced['days']
                ),
                'unpricedcount' => get_string(
                    'modelregistry:unpriced_count',
                    'local_ai_course_assistant',
                    $unpriced['count']
                ),
                'effectiveheading' => get_string('modelregistry:effective_heading', 'local_ai_course_assistant'),
                'driftapply' => get_string('modelregistry:drift_apply', 'local_ai_course_assistant'),
                'benchheading' => get_string('bench:heading', 'local_ai_course_assistant'),
            ],
            'unpriced' => $unpriced,
            'effective' => model_registry_page::effective_rows(),
            'modelform' => [
                'modelkey' => '', 'provider' => '', 'capability' => '', 'input_rate' => '',
                'output_rate' => '', 'context_tokens' => '', 'notes' => '', 'editing' => false,
                'statuses' => model_registry_page::status_options(),
            ],
            'sources' => model_registry_page::source_rows(),
            'sourceform' => [
                'sourceid' => 0, 'name' => '', 'url' => '', 'spec' => '', 'enabled' => false,
                'editing' => false, 'formats' => model_registry_page::format_options(),
            ],
            'drift' => $drift,
            'bench' => model_registry_page::bench_block(),
            'queue' => [
                'models' => $queuemodels,
                'hasmodels' => !empty($queuemodels),
                'functions' => model_registry_page::function_options(),
                'samples' => 10,
                'maxsamples' => 50,
            ],
        ];
    }

    public function test_the_drift_block_is_empty_and_honest_before_any_run(): void {
        $block = model_registry_page::drift_block();

        $this->assertFalse($block['hasrun']);
        $this->assertFalse($block['has']);
        $this->assertSame(
            get_string('modelregistry:drift_never', 'local_ai_course_assistant'),
            $block['lastrun']
        );
    }

    public function test_the_drift_block_renders_a_mismatch_with_both_prices(): void {
        set_config(model_price_drift_check::SUMMARY_KEY, json_encode([
            'status' => 'findings',
            'timerun' => time(),
            'counts' => ['missing' => 0, 'mismatch' => 1, 'new' => 0],
            'truncated' => ['missing' => 0, 'new' => 0],
            'findings' => [[
                'type' => 'mismatch',
                'modelkey' => 'claude-opus-5',
                'provider' => 'claude',
                'input' => 6.0,
                'output' => 30.0,
                'registry_input' => 5.0,
                'registry_output' => 25.0,
                'delta_pct_input' => 20.0,
                'delta_pct_output' => 20.0,
                'sourcename' => 'Feed',
            ]],
        ]), 'local_ai_course_assistant');

        $row = model_registry_page::drift_block()['rows'][0];

        $this->assertTrue($row['ismismatch']);
        $this->assertStringContainsString('6', $row['proposed']);
        $this->assertStringContainsString('5', $row['registry']);
        $this->assertStringContainsString('20', $row['delta']);
        $this->assertTrue($row['hasrates']);
        $this->assertSame('6', $row['applyinput']);
    }

    public function test_a_missing_finding_with_no_proposal_offers_no_apply_button(): void {
        set_config(model_price_drift_check::SUMMARY_KEY, json_encode([
            'status' => 'findings',
            'timerun' => time(),
            'counts' => ['missing' => 1, 'mismatch' => 0, 'new' => 0],
            'findings' => [[
                'type' => 'missing',
                'modelkey' => 'nobody-knows-this',
                'input' => null,
                'output' => null,
                'calls' => 400,
            ]],
        ]), 'local_ai_course_assistant');

        $row = model_registry_page::drift_block()['rows'][0];

        $this->assertFalse(
            $row['hasrates'],
            'Offering an apply button with no price would write a zero rate.'
        );
        $this->assertSame('', $row['proposed']);
    }
}
