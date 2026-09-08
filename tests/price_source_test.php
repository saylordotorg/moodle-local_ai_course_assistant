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

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the v7.4.0 declarative pricing sources and the price-drift check.
 *
 * EVERY parse here is driven from a FIXTURE STRING. Not one test touches the
 * network: a test that fetched openrouter.ai would fail on a plane, pass when
 * the vendor changed a price, and tell you nothing about the parser either way.
 *
 * The tests are grouped around the properties that actually matter:
 *  - all four formats parse, and per-token feeds are scaled to SOLA's per-1M schema;
 *  - a parse failure is LOUD (recorded on the source row, never an empty set),
 *    and an empty parse from a source that worked before is flagged as a regression;
 *  - the MISMATCH tolerance boundary behaves at exactly the tolerance;
 *  - a model in real billable traffic with no rate produces a MISSING finding —
 *    the class of defect that let 100% of production chat spend compute as $0.00;
 *  - findings are PROPOSED: a run writes nothing to the models table.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\price_source
 * @covers     \local_ai_course_assistant\task\model_price_drift_check
 */
final class price_source_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        model_registry::reset_cache();
    }

    protected function tearDown(): void {
        model_registry::reset_cache();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixtures.
    // ------------------------------------------------------------------

    /**
     * A LiteLLM model_prices_and_context_window.json excerpt.
     *
     * Deliberately includes: the sample_spec placeholder LiteLLM ships, a
     * priced chat model, a rerank model (the mode the pre-v7.4.0 transform
     * dropped entirely), an embedding model with no output cost, a chat model
     * with no output cost, and an image-generation row.
     *
     * @return string
     */
    private function litellm_fixture(): string {
        return json_encode([
            'sample_spec' => [
                'input_cost_per_token' => 0.0,
                'output_cost_per_token' => 0.0,
                'mode' => 'chat',
            ],
            'gemini-2.5-flash' => [
                'litellm_provider' => 'vertex_ai',
                'mode' => 'chat',
                'input_cost_per_token' => 0.0000003,
                'output_cost_per_token' => 0.0000025,
                'max_input_tokens' => 1048576,
            ],
            'rerank-2.5' => [
                'litellm_provider' => 'voyage',
                'mode' => 'rerank',
                'input_cost_per_token' => 0.00000005,
            ],
            'text-embedding-3-small' => [
                'litellm_provider' => 'openai',
                'mode' => 'embedding',
                'input_cost_per_token' => 0.00000002,
                'max_tokens' => 8191,
            ],
            'half-priced-chat' => [
                'litellm_provider' => 'openai',
                'mode' => 'chat',
                'input_cost_per_token' => 0.000001,
            ],
            'dall-e-3' => [
                'litellm_provider' => 'openai',
                'mode' => 'image_generation',
                'input_cost_per_token' => 0.00004,
                'output_cost_per_token' => 0.00004,
            ],
        ]);
    }

    /**
     * An openrouter /api/v1/models excerpt: per-TOKEN decimal strings,
     * vendor-prefixed ids, a ":free" variant and a "-1" variable price.
     *
     * @return string
     */
    private function openrouter_fixture(): string {
        return json_encode(['data' => [
            [
                'id' => 'google/gemini-2.5-flash',
                'context_length' => 1048576,
                'pricing' => ['prompt' => '0.0000003', 'completion' => '0.0000025'],
            ],
            [
                'id' => 'anthropic/claude-sonnet-5',
                'context_length' => 200000,
                'pricing' => ['prompt' => '0.000003', 'completion' => '0.000015'],
            ],
            [
                'id' => 'meta/llama-3-8b:free',
                'pricing' => ['prompt' => '0', 'completion' => '0'],
            ],
            [
                'id' => 'mystery/on-request-model',
                'pricing' => ['prompt' => '-1', 'completion' => '-1'],
            ],
            [
                'id' => 'azure/claude-sonnet-5',
                'pricing' => ['prompt' => '0.000009', 'completion' => '0.000045'],
            ],
        ]]);
    }

    /**
     * Seed a msgs row so the traffic-side query has something to see.
     *
     * @param string $model
     * @param string $provider
     * @param int $calls
     * @return void
     */
    private function log_calls(string $model, string $provider, int $calls = 1): void {
        global $DB;
        for ($i = 0; $i < $calls; $i++) {
            $DB->insert_record('local_ai_course_assistant_msgs', (object) [
                'conversationid'    => 1,
                'userid'            => 2,
                'courseid'          => SITEID,
                'role'              => 'assistant',
                'message'           => 'x',
                'prompt_tokens'     => 1000,
                'completion_tokens' => 500,
                'model_name'        => $model,
                'provider'          => $provider,
                'interaction_type'  => 'chat',
                'timecreated'       => time() - 60,
            ]);
        }
    }

    /**
     * Insert a pricing-source row.
     *
     * @param string $format
     * @param array|null $spec
     * @param int $enabled
     * @param string $name
     * @return \stdClass
     */
    private function make_source(string $format, ?array $spec = null, int $enabled = 1,
            string $name = 'Test source'): \stdClass {
        global $DB;
        $id = $DB->insert_record(model_registry::TABLE_SOURCES, (object) [
            'name' => $name,
            'url' => 'https://example.com/prices.json',
            'format' => $format,
            'spec' => $spec === null ? null : json_encode($spec),
            'enabled' => $enabled,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        return $DB->get_record(model_registry::TABLE_SOURCES, ['id' => $id]);
    }

    // ------------------------------------------------------------------
    // Format: litellm.
    // ------------------------------------------------------------------

    public function test_litellm_scales_per_token_prices_to_per_million(): void {
        $result = price_source::parse('litellm', $this->litellm_fixture());
        $this->assertTrue($result['ok'], $result['message']);
        // 0.0000003 USD/token x 1e6 = $0.30 per 1M — the verified Google price.
        $this->assertEqualsWithDelta(0.30, $result['prices']['gemini-2.5-flash']['input'], 0.000001);
        $this->assertEqualsWithDelta(2.50, $result['prices']['gemini-2.5-flash']['output'], 0.000001);
        $this->assertSame('vertex_ai', $result['prices']['gemini-2.5-flash']['provider']);
        $this->assertSame('chat', $result['prices']['gemini-2.5-flash']['capability']);
        $this->assertSame(1048576, $result['prices']['gemini-2.5-flash']['context']);
    }

    public function test_litellm_prices_rerank_models(): void {
        // The pre-v7.4.0 transform had no 'rerank' mode, so a reranker could
        // never be auto-priced while voyage_reranker was live in the RAG path.
        $result = price_source::parse('litellm', $this->litellm_fixture());
        $this->assertArrayHasKey('rerank-2.5', $result['prices']);
        $this->assertEqualsWithDelta(0.05, $result['prices']['rerank-2.5']['input'], 0.000001);
        $this->assertSame(0.0, $result['prices']['rerank-2.5']['output']);
        $this->assertSame('rerank', $result['prices']['rerank-2.5']['capability']);
    }

    public function test_litellm_zero_fills_embedding_output_but_skips_incomplete_chat(): void {
        $result = price_source::parse('litellm', $this->litellm_fixture());
        // Input-only capability: a missing output cost legitimately means 0.
        $this->assertSame(0.0, $result['prices']['text-embedding-3-small']['output']);
        // Chat with no output cost is INCOMPLETE, not free. Pricing an LLM's
        // output at zero is the exact failure class this feature exists to fix.
        $this->assertArrayNotHasKey('half-priced-chat', $result['prices']);
    }

    public function test_litellm_skips_placeholder_and_unpriceable_modes(): void {
        $result = price_source::parse('litellm', $this->litellm_fixture());
        $this->assertArrayNotHasKey('sample_spec', $result['prices']);
        $this->assertArrayNotHasKey('dall-e-3', $result['prices']);
    }

    // ------------------------------------------------------------------
    // Format: openrouter.
    // ------------------------------------------------------------------

    public function test_openrouter_scales_per_token_and_splits_the_vendor_prefix(): void {
        $result = price_source::parse('openrouter', $this->openrouter_fixture());
        $this->assertTrue($result['ok'], $result['message']);
        // The vendor prefix has to become the provider, not part of the key:
        // SOLA's rate-card keys are the bare model name the provider returns,
        // so "google/gemini-2.5-flash" would otherwise match nothing.
        $this->assertArrayHasKey('gemini-2.5-flash', $result['prices']);
        $this->assertArrayNotHasKey('google/gemini-2.5-flash', $result['prices']);
        $this->assertSame('google', $result['prices']['gemini-2.5-flash']['provider']);
        $this->assertEqualsWithDelta(0.30, $result['prices']['gemini-2.5-flash']['input'], 0.000001);
        $this->assertEqualsWithDelta(2.50, $result['prices']['gemini-2.5-flash']['output'], 0.000001);
        $this->assertSame(1048576, $result['prices']['gemini-2.5-flash']['context']);
        $this->assertEqualsWithDelta(3.0, $result['prices']['claude-sonnet-5']['input'], 0.000001);
        $this->assertEqualsWithDelta(15.0, $result['prices']['claude-sonnet-5']['output'], 0.000001);
    }

    public function test_openrouter_skips_free_and_variable_priced_entries(): void {
        $result = price_source::parse('openrouter', $this->openrouter_fixture());
        $this->assertArrayNotHasKey('llama-3-8b:free', $result['prices']);
        $this->assertArrayNotHasKey('on-request-model', $result['prices']);
        // First vendor wins on a duplicate bare id, deterministically.
        $this->assertEqualsWithDelta(3.0, $result['prices']['claude-sonnet-5']['input'], 0.000001);
        $this->assertStringContainsString('duplicate model ids', $result['message']);
    }

    // ------------------------------------------------------------------
    // Format: json_generic.
    // ------------------------------------------------------------------

    public function test_json_generic_walks_dot_paths_with_a_scale_factor(): void {
        $body = json_encode(['result' => ['models' => [
            ['name' => 'GPT-4o-mini', 'cost' => ['in' => 0.15, 'out' => 0.60],
                'window' => 128000, 'vendor' => 'openai', 'kind' => 'Chat'],
            ['name' => 'nomic-embed', 'cost' => ['in' => 0.02], 'vendor' => 'nomic'],
        ]]]);
        $result = price_source::parse('json_generic', $body, [
            'rowspath' => 'result.models',
            'modelpath' => 'name',
            'inputpath' => 'cost.in',
            'outputpath' => 'cost.out',
            'providerpath' => 'vendor',
            'capabilitypath' => 'kind',
            'contextpath' => 'window',
            'scale' => 1,
        ]);
        $this->assertTrue($result['ok'], $result['message']);
        // Keys are lowercased so they compare against the rate card.
        $this->assertEqualsWithDelta(0.15, $result['prices']['gpt-4o-mini']['input'], 0.000001);
        $this->assertEqualsWithDelta(0.60, $result['prices']['gpt-4o-mini']['output'], 0.000001);
        $this->assertSame('chat', $result['prices']['gpt-4o-mini']['capability']);
        $this->assertSame('openai', $result['prices']['gpt-4o-mini']['provider']);
        $this->assertSame(128000, $result['prices']['gpt-4o-mini']['context']);
        // No outputpath value at all: input-priced, output 0.
        $this->assertSame(0.0, $result['prices']['nomic-embed']['output']);
    }

    public function test_json_generic_applies_the_scale_to_per_token_feeds(): void {
        $body = json_encode([['id' => 'some-model', 'in' => 0.0000005, 'out' => 0.000002]]);
        $result = price_source::parse('json_generic', $body, [
            'modelpath' => 'id', 'inputpath' => 'in', 'outputpath' => 'out', 'scale' => 1000000,
        ]);
        $this->assertEqualsWithDelta(0.5, $result['prices']['some-model']['input'], 0.000001);
        $this->assertEqualsWithDelta(2.0, $result['prices']['some-model']['output'], 0.000001);
    }

    public function test_json_generic_uses_map_keys_as_model_ids_when_modelpath_is_absent(): void {
        $body = json_encode(['pricing' => [
            'claude-opus-5' => ['input' => 5, 'output' => 25],
            'claude-haiku-4-5' => ['input' => 1, 'output' => 5],
        ]]);
        $result = price_source::parse('json_generic', $body, [
            'rowspath' => 'pricing', 'inputpath' => 'input', 'outputpath' => 'output',
            'provider' => 'anthropic',
        ]);
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertEqualsWithDelta(25.0, $result['prices']['claude-opus-5']['output'], 0.000001);
        $this->assertSame('anthropic', $result['prices']['claude-haiku-4-5']['provider']);
    }

    public function test_json_generic_without_an_inputpath_says_so(): void {
        $result = price_source::parse('json_generic', json_encode(['a' => 1]), ['modelpath' => 'id']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('inputpath', $result['message']);
        $this->assertSame([], $result['prices']);
    }

    public function test_json_generic_reports_an_unresolvable_rowspath(): void {
        $result = price_source::parse('json_generic', json_encode(['data' => []]), [
            'rowspath' => 'result.models', 'inputpath' => 'in',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('rowspath', $result['message']);
    }

    // ------------------------------------------------------------------
    // Format: html_regex.
    // ------------------------------------------------------------------

    public function test_html_regex_extracts_models_and_prices_from_a_page(): void {
        $html = '<table><tr><td>Gemini 2.5 Flash</td></tr>'
            . '<tr><td>gemini-2.5-flash</td><td>$0.30</td><td>$2.50</td></tr>'
            . '<tr><td>gemini-2.5-flash-lite</td><td>$0.10</td><td>$0.40</td></tr>'
            . '<tr><td>broken-row</td><td>n/a</td><td>n/a</td></tr></table>';
        $result = price_source::parse('html_regex', $html, [
            'pattern' => '<tr><td>([a-z0-9.\-]+)</td><td>\$?([0-9.]+|n/a)</td><td>\$?([0-9.]+|n/a)</td></tr>',
            'flags' => 'i',
            'groups' => ['model' => 1, 'input' => 2, 'output' => 3],
        ]);
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertEqualsWithDelta(0.30, $result['prices']['gemini-2.5-flash']['input'], 0.000001);
        $this->assertEqualsWithDelta(2.50, $result['prices']['gemini-2.5-flash']['output'], 0.000001);
        $this->assertEqualsWithDelta(0.40, $result['prices']['gemini-2.5-flash-lite']['output'], 0.000001);
        // A matched row with no number in the price group is skipped and counted.
        $this->assertArrayNotHasKey('broken-row', $result['prices']);
        $this->assertStringContainsString('no usable number', $result['message']);
    }

    public function test_html_regex_rejects_an_uncompilable_pattern(): void {
        $result = price_source::parse('html_regex', '<p>anything</p>', [
            'pattern' => '([0-9]+',
            'groups' => ['model' => 1, 'input' => 2],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('compile', $result['message']);
        $this->assertSame([], $result['prices']);
    }

    // ------------------------------------------------------------------
    // Loudness.
    // ------------------------------------------------------------------

    public function test_unknown_format_is_reported_not_swallowed(): void {
        $result = price_source::parse('scrape_pdf', '{}', []);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Unknown source format', $result['message']);
    }

    public function test_non_json_body_for_a_json_format_is_reported(): void {
        $result = price_source::parse('litellm', '<html>403 Forbidden</html>');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Expected a JSON object', $result['message']);
    }

    public function test_an_empty_parse_is_a_failure_never_no_prices(): void {
        // Valid JSON, nothing priceable in it. This is the shape that must NOT
        // read as "this vendor has no prices": downstream, an unpriced model
        // reports $0.00 spend rather than an error.
        $result = price_source::parse('litellm', json_encode(['dall-e-3' => ['mode' => 'image_generation',
            'input_cost_per_token' => 0.1]]));
        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['prices']);
        $this->assertStringContainsString('broken source', $result['message']);
    }

    public function test_a_parse_failure_is_recorded_loudly_on_the_source_row(): void {
        global $DB;
        $source = $this->make_source('litellm');
        $result = price_source::parse_into_source($source, '<html>404</html>');

        $this->assertFalse($result['ok']);
        // Loud on both channels: a developer-level debugging notice AND a
        // durable row an admin can read weeks later.
        $this->assertDebuggingCalled();
        $stored = $DB->get_record(model_registry::TABLE_SOURCES, ['id' => $source->id]);
        $this->assertSame('error', $stored->laststatus);
        $this->assertNotEmpty($stored->lastmessage);
        $this->assertStringContainsString('Expected a JSON object', $stored->lastmessage);
        $this->assertGreaterThan(0, (int) $stored->lastfetch);
    }

    public function test_a_source_that_worked_before_and_parses_empty_now_is_flagged_as_a_regression(): void {
        global $DB;
        $source = $this->make_source('litellm');

        // Run one: healthy. The count is remembered.
        $ok = price_source::parse_into_source($source, $this->litellm_fixture());
        $this->assertTrue($ok['ok']);
        $this->assertSame(3, $ok['count']);
        $this->assertSame(3, price_source::previous_count($source));

        // Run two: the vendor renamed a key, so nothing parses. That is a
        // regression, not an absence of prices, and the message must say which.
        $bad = price_source::parse_into_source($source, json_encode(['x' => ['mode' => 'video']]));
        $this->assertFalse($bad['ok']);
        $this->assertDebuggingCalled();
        $stored = $DB->get_record(model_registry::TABLE_SOURCES, ['id' => $source->id]);
        $this->assertSame('error', $stored->laststatus);
        $this->assertStringContainsString('REGRESSION', $stored->lastmessage);
        $this->assertStringContainsString('3 prices on its previous run', $stored->lastmessage);
    }

    public function test_a_malformed_spec_is_recorded_rather_than_ignored(): void {
        global $DB;
        $source = $this->make_source('json_generic');
        $DB->set_field(model_registry::TABLE_SOURCES, 'spec', '{not json', ['id' => $source->id]);
        $source = $DB->get_record(model_registry::TABLE_SOURCES, ['id' => $source->id]);

        $result = price_source::parse_into_source($source, json_encode(['a' => 1]));
        $this->assertFalse($result['ok']);
        $this->assertDebuggingCalled();
        $stored = $DB->get_record(model_registry::TABLE_SOURCES, ['id' => $source->id]);
        $this->assertSame('error', $stored->laststatus);
        $this->assertStringContainsString('not valid JSON', $stored->lastmessage);
    }

    public function test_a_url_outside_the_ssrf_allowlist_is_refused_before_any_request(): void {
        // No network reachable from a test either way; what is asserted is that
        // the gate rejects it and says so, rather than attempting the fetch.
        $result = price_source::fetch('http://169.254.169.254/latest/meta-data/');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('SSRF allowlist', $result['message']);
    }

    // ------------------------------------------------------------------
    // Comparison and the tolerance boundary.
    // ------------------------------------------------------------------

    public function test_tolerance_boundary_is_inclusive(): void {
        $rates = ['gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50]];
        $row = ['provider' => 'google', 'capability' => 'chat', 'context' => null];

        // Exactly 1.0% off: within tolerance, no finding.
        $atlimit = price_source::compare(
            ['gemini-2.5-flash' => $row + ['input' => 0.30, 'output' => 2.525]], $rates, 1.0);
        $this->assertSame([], $atlimit['mismatch']);

        // A hair over 1.0%: reported.
        $over = price_source::compare(
            ['gemini-2.5-flash' => $row + ['input' => 0.30, 'output' => 2.5255]], $rates, 1.0);
        $this->assertCount(1, $over['mismatch']);
        $this->assertSame('gemini-2.5-flash', $over['mismatch'][0]['modelkey']);
        $this->assertSame(2.50, $over['mismatch'][0]['registry_output']);
        $this->assertEqualsWithDelta(1.02, $over['mismatch'][0]['delta_pct_output'], 0.01);
        $this->assertSame(0.0, $over['mismatch'][0]['delta_pct_input']);

        // Under tolerance on both: silent.
        $under = price_source::compare(
            ['gemini-2.5-flash' => $row + ['input' => 0.3015, 'output' => 2.51]], $rates, 1.0);
        $this->assertSame([], $under['mismatch']);
    }

    public function test_a_zero_tolerance_reports_any_difference(): void {
        $rates = ['claude-opus-5' => ['input' => 5.0, 'output' => 25.0]];
        $prices = ['claude-opus-5' => ['input' => 5.0, 'output' => 25.01,
            'provider' => 'anthropic', 'capability' => 'chat', 'context' => null]];
        $this->assertCount(1, price_source::compare($prices, $rates, 0.0)['mismatch']);
    }

    public function test_a_registry_rate_of_zero_against_a_real_price_is_a_full_mismatch(): void {
        // The worst shape there is: a card that says the model is free.
        $this->assertSame(100.0, price_source::delta_pct(0.0, 2.50));
        $this->assertSame(0.0, price_source::delta_pct(0.0, 0.0));
    }

    public function test_a_model_covered_by_a_shorter_card_prefix_is_neither_new_nor_mismatched(): void {
        // Registry keys are longest-prefix matched. A dated variant the card
        // already covers is priced; calling that a price mismatch would be a
        // false positive, and calling it new would be wrong too.
        $rates = ['gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50]];
        $prices = ['gemini-2.5-flash-preview-09-2025' => ['input' => 0.90, 'output' => 5.0,
            'provider' => 'google', 'capability' => 'chat', 'context' => null]];
        $cmp = price_source::compare($prices, $rates, 1.0);
        $this->assertSame([], $cmp['mismatch']);
        $this->assertSame([], $cmp['new']);
        $this->assertSame(1, $cmp['covered']);
    }

    public function test_a_model_the_registry_has_never_heard_of_is_reported_as_new(): void {
        $cmp = price_source::compare(
            ['brand-new-model-9' => ['input' => 1.0, 'output' => 2.0,
                'provider' => 'acme', 'capability' => 'chat', 'context' => 4096]],
            ['gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50]],
            1.0
        );
        $this->assertCount(1, $cmp['new']);
        $this->assertSame('brand-new-model-9', $cmp['new'][0]['modelkey']);
        $this->assertSame('new', $cmp['new'][0]['type']);
        // The finding carries the whole proposed row, so one-click apply works.
        $this->assertSame('acme', $cmp['new'][0]['provider']);
        $this->assertSame(4096, $cmp['new'][0]['context']);
    }

    // ------------------------------------------------------------------
    // MISSING: the highest-severity finding.
    // ------------------------------------------------------------------

    public function test_a_model_in_traffic_with_no_rate_produces_a_missing_finding(): void {
        // 'zzz-unpriced-model' matches no rate-card prefix, exactly like
        // gemini-2.5-flash did in production while it served 100% of chat.
        $this->log_calls('zzz-unpriced-model', 'openai', 3);
        model_registry::reset_cache();
        $this->assertNull(model_registry::rate_for('zzz-unpriced-model'));

        $findings = model_price_drift_check::missing_findings();
        $keys = array_column($findings, 'modelkey');
        $this->assertContains('zzz-unpriced-model', $keys);
        $finding = $findings[array_search('zzz-unpriced-model', $keys, true)];
        $this->assertSame('missing', $finding['type']);
        $this->assertSame(3, $finding['calls']);
        // No source knows it, so there is nothing to propose — and that is
        // itself the message: we are billing for this and cannot price it.
        $this->assertNull($finding['input']);
        $this->assertNull($finding['sourcename']);
    }

    public function test_a_missing_finding_carries_a_proposal_when_a_source_knows_the_price(): void {
        $this->log_calls('zzz-unpriced-model-2', 'openai', 5);
        model_registry::reset_cache();

        $merged = ['zzz-unpriced-model-2' => ['input' => 1.25, 'output' => 6.0,
            'provider' => 'acme', 'capability' => 'chat', 'context' => 32000]];
        $owner = ['zzz-unpriced-model-2' => ['id' => 7, 'name' => 'Acme price page']];

        $findings = model_price_drift_check::missing_findings($merged, $owner);
        $keys = array_column($findings, 'modelkey');
        $finding = $findings[array_search('zzz-unpriced-model-2', $keys, true)];
        $this->assertEqualsWithDelta(1.25, $finding['input'], 0.000001);
        $this->assertEqualsWithDelta(6.0, $finding['output'], 0.000001);
        $this->assertSame('Acme price page', $finding['sourcename']);
        $this->assertSame(7, $finding['sourceid']);
        $this->assertSame(32000, $finding['context']);
    }

    public function test_a_priced_model_in_traffic_produces_no_finding(): void {
        // gemini-2.5-flash is priced from v7.4.0 on, so heavy traffic on it is
        // no longer a finding. This is the regression guard for the fix.
        $this->log_calls('gemini-2.5-flash', 'gemini', 4);
        model_registry::reset_cache();
        $this->assertNotNull(model_registry::rate_for('gemini-2.5-flash'));
        $keys = array_column(model_price_drift_check::missing_findings(), 'modelkey');
        $this->assertNotContains('gemini-2.5-flash', $keys);
    }

    // ------------------------------------------------------------------
    // The task end to end (no sources enabled => no network).
    // ------------------------------------------------------------------

    public function test_run_persists_a_summary_and_proposes_without_applying(): void {
        global $DB;
        $this->log_calls('zzz-unpriced-model-3', 'openai', 2);
        model_registry::reset_cache();

        $summary = model_price_drift_check::run();

        $this->assertSame('findings', $summary['status']);
        $this->assertGreaterThanOrEqual(1, $summary['counts']['missing']);
        $this->assertEqualsWithDelta(1.0, $summary['tolerance_pct'], 0.0001);
        // Findings are PROPOSED, never applied: nothing has been written to the
        // models table. An automated feed rewriting a price is the v7.3.x
        // defect this release removed.
        $this->assertSame(0, $DB->count_records(model_registry::TABLE_MODELS));

        $stored = model_price_drift_check::last_summary();
        $this->assertIsArray($stored);
        $this->assertSame($summary['counts']['missing'], $stored['counts']['missing']);
        $this->assertNotEmpty($stored['findings']);
    }

    public function test_run_records_per_source_finding_counts_on_the_row(): void {
        global $DB;
        $source = $this->make_source('litellm', null, 1, 'Fixture feed');
        // Sidestep the network by pre-running the parse the way refresh_source
        // would, then asserting the recorded shape the admin page reads.
        price_source::parse_into_source($source, $this->litellm_fixture());
        price_source::append_message($source, 'Findings: 1 price mismatch, 2 unknown to the registry.');

        $stored = $DB->get_record(model_registry::TABLE_SOURCES, ['id' => $source->id]);
        $this->assertSame('ok', $stored->laststatus);
        $this->assertStringContainsString('Parsed 3 prices', $stored->lastmessage);
        $this->assertStringContainsString('1 price mismatch', $stored->lastmessage);
    }

    public function test_the_tolerance_setting_is_read_and_clamped(): void {
        $this->assertEqualsWithDelta(1.0, model_price_drift_check::tolerance_pct(), 0.0001);
        set_config('price_drift_tolerance_pct', '5.5', 'local_ai_course_assistant');
        $this->assertEqualsWithDelta(5.5, model_price_drift_check::tolerance_pct(), 0.0001);
        set_config('price_drift_tolerance_pct', '-3', 'local_ai_course_assistant');
        $this->assertEqualsWithDelta(0.0, model_price_drift_check::tolerance_pct(), 0.0001);
        set_config('price_drift_tolerance_pct', 'banana', 'local_ai_course_assistant');
        $this->assertEqualsWithDelta(1.0, model_price_drift_check::tolerance_pct(), 0.0001);
    }

    public function test_the_task_is_off_by_default_and_does_nothing_when_disabled(): void {
        $this->log_calls('zzz-unpriced-model-4', 'openai', 2);
        model_registry::reset_cache();
        $this->assertEmpty(get_config('local_ai_course_assistant', 'price_drift_check_enabled'));

        $task = new model_price_drift_check();
        ob_start();
        $task->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('disabled', $output);
        $this->assertNull(model_price_drift_check::last_summary());
    }

    public function test_the_task_runs_and_emails_when_enabled(): void {
        set_config('price_drift_check_enabled', 1, 'local_ai_course_assistant');
        set_config('spend_notify_emails', 'ops@example.com', 'local_ai_course_assistant');
        $this->log_calls('zzz-unpriced-model-5', 'openai', 9);
        model_registry::reset_cache();

        $sink = $this->redirectEmails();
        $task = new model_price_drift_check();
        ob_start();
        $task->execute();
        $output = ob_get_clean();
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertStringContainsString('missing=1', $output);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('unpriced model', $messages[0]->subject);
        $this->assertStringContainsString('zzz-unpriced-model-5', $messages[0]->body);
        $this->assertStringContainsString('NO SOURCE KNOWS THIS MODEL', $messages[0]->body);
    }

    // ------------------------------------------------------------------
    // Alerting.
    // ------------------------------------------------------------------

    public function test_the_same_findings_do_not_email_twice(): void {
        set_config('spend_notify_emails', 'ops@example.com', 'local_ai_course_assistant');
        $summary = ['counts' => ['missing' => 1, 'mismatch' => 0, 'new' => 0,
            'sources_ok' => 0, 'sources_failed' => 0],
            'tolerance_pct' => 1.0, 'sources' => [],
            'findings' => [['type' => 'missing', 'modelkey' => 'x-model', 'calls' => 4,
                'input' => null, 'output' => null, 'sourcename' => null]]];

        $sink = $this->redirectEmails();
        $this->assertTrue(model_price_drift_check::maybe_send_alert($summary));
        $this->assertFalse(model_price_drift_check::maybe_send_alert($summary));
        $this->assertCount(1, $sink->get_messages());
        $sink->close();
    }

    public function test_a_new_finding_the_same_day_still_alerts(): void {
        set_config('spend_notify_emails', 'ops@example.com', 'local_ai_course_assistant');
        $base = ['counts' => ['missing' => 1, 'mismatch' => 0, 'new' => 0,
            'sources_ok' => 0, 'sources_failed' => 0],
            'tolerance_pct' => 1.0, 'sources' => [],
            'findings' => [['type' => 'missing', 'modelkey' => 'x-model', 'calls' => 4,
                'input' => null, 'output' => null, 'sourcename' => null]]];
        $extra = $base;
        $extra['counts']['missing'] = 2;
        $extra['findings'][] = ['type' => 'missing', 'modelkey' => 'y-model', 'calls' => 1,
            'input' => null, 'output' => null, 'sourcename' => null];

        $sink = $this->redirectEmails();
        $this->assertTrue(model_price_drift_check::maybe_send_alert($base));
        $this->assertTrue(model_price_drift_check::maybe_send_alert($extra));
        $this->assertCount(2, $sink->get_messages());
        $sink->close();
    }

    public function test_informational_new_findings_alone_do_not_email(): void {
        set_config('spend_notify_emails', 'ops@example.com', 'local_ai_course_assistant');
        $summary = ['counts' => ['missing' => 0, 'mismatch' => 0, 'new' => 340,
            'sources_ok' => 1, 'sources_failed' => 0],
            'tolerance_pct' => 1.0, 'sources' => [], 'findings' => []];
        $sink = $this->redirectEmails();
        $this->assertFalse(model_price_drift_check::maybe_send_alert($summary));
        $this->assertCount(0, $sink->get_messages());
        $sink->close();
    }

    public function test_an_opted_out_recipient_is_not_emailed(): void {
        set_config('spend_notify_emails', 'ops@example.com', 'local_ai_course_assistant');
        email_optout::record('ops@example.com', email_optout::TYPE_SPEND_ALERT);
        $summary = ['counts' => ['missing' => 1, 'mismatch' => 0, 'new' => 0,
            'sources_ok' => 0, 'sources_failed' => 0],
            'tolerance_pct' => 1.0, 'sources' => [],
            'findings' => [['type' => 'missing', 'modelkey' => 'x-model', 'calls' => 4,
                'input' => null, 'output' => null, 'sourcename' => null]]];

        $sink = $this->redirectEmails();
        $this->assertFalse(model_price_drift_check::maybe_send_alert($summary));
        $this->assertCount(0, $sink->get_messages());
        $sink->close();
    }

    public function test_the_alert_body_names_the_stale_price_on_both_sides(): void {
        set_config('spend_notify_emails', 'ops@example.com', 'local_ai_course_assistant');
        $summary = ['counts' => ['missing' => 0, 'mismatch' => 1, 'new' => 0,
            'sources_ok' => 1, 'sources_failed' => 0],
            'tolerance_pct' => 1.0, 'sources' => [],
            'findings' => [['type' => 'mismatch', 'modelkey' => 'claude-opus-5',
                'registry_input' => 15.0, 'registry_output' => 75.0,
                'input' => 5.0, 'output' => 25.0,
                'delta_pct_input' => 66.7, 'delta_pct_output' => 66.7,
                'sourcename' => 'LiteLLM']]];

        $sink = $this->redirectEmails();
        $this->assertTrue(model_price_drift_check::maybe_send_alert($summary));
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertStringContainsString('claude-opus-5', $messages[0]->body);
        $this->assertStringContainsString('15.0000', $messages[0]->body);
        $this->assertStringContainsString('5.0000', $messages[0]->body);
        $this->assertStringContainsString('Nothing has been changed', $messages[0]->body);
    }

    // ------------------------------------------------------------------
    // Small helpers with real failure modes.
    // ------------------------------------------------------------------

    public function test_number_coercion_tolerates_vendor_page_formatting(): void {
        $this->assertSame(0.30, price_source::to_number('$0.30'));
        $this->assertSame(0.30, price_source::to_number('0.30 per 1M tokens'));
        $this->assertSame(1000.0, price_source::to_number('1,000'));
        $this->assertSame(0.0000025, price_source::to_number('0.0000025'));
        $this->assertSame(2.5, price_source::to_number(2.5));
        $this->assertNull(price_source::to_number('n/a'));
        $this->assertNull(price_source::to_number(null));
    }

    public function test_dot_paths_walk_lists_and_maps(): void {
        $data = ['a' => ['b' => [['c' => 7]]]];
        $this->assertSame(7, price_source::dig($data, 'a.b.0.c'));
        $this->assertNull(price_source::dig($data, 'a.b.9.c'));
        $this->assertNull(price_source::dig($data, 'a.missing'));
        $this->assertSame($data, price_source::dig($data, ''));
    }

    public function test_only_enabled_sources_are_returned(): void {
        $this->make_source('litellm', null, 1, 'On');
        $this->make_source('openrouter', null, 0, 'Off');
        $enabled = price_source::enabled_sources();
        $this->assertCount(1, $enabled);
        $this->assertSame('On', reset($enabled)->name);
    }
}
