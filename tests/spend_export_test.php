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
 * Tests for the monthly spend export (Saylor AI Spend dashboard pull contract).
 *
 * Three failure modes are pinned here because each one is silent in production:
 *
 *  1. A month window with an open lower bound. Every month would then contain
 *     every earlier month and the dashboard's yearly figure would be a sum of
 *     running sums -- a wrong number that still looks plausible and rises
 *     smoothly. The boundary tests below sit on the exact second.
 *  2. An unpriced model dropped instead of counted. That is the bug this whole
 *     work exists to fix: gemini-2.5-flash matched no rate-card prefix, priced
 *     as null, and every consumer read null as "add nothing", so 100% of
 *     production chat spend reported as $0.00 and every dashboard agreed.
 *  3. A key accepted from somewhere it must not be (query string), or compared
 *     in a way that lets an empty configuration authenticate.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\spend_export
 * @covers     \local_ai_course_assistant\analytics::get_monthly_provider_spend
 */
final class spend_export_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        // The rate map is cached for the life of the request and a test process
        // is one long request.
        model_registry::reset_cache();
    }

    protected function tearDown(): void {
        model_registry::reset_cache();
        parent::tearDown();
    }

    /**
     * Insert one messages row.
     *
     * @param array $overrides Field overrides.
     * @return int Inserted id.
     */
    private function msg(array $overrides = []): int {
        global $DB;

        $row = (object) array_merge([
            'conversationid' => 1,
            'userid' => 2,
            'courseid' => 2,
            'role' => 'assistant',
            'message' => 'x',
            'tokens_used' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'model_name' => 'gpt-4o-mini',
            'provider' => 'openai',
            'interaction_type' => 'chat',
            'timecreated' => gmmktime(12, 0, 0, 9, 15, 2026),
        ], $overrides);

        return (int) $DB->insert_record('local_ai_course_assistant_msgs', $row);
    }

    // ------------------------------------------------------------------
    // 1. Month parsing.
    // ------------------------------------------------------------------

    public function test_month_range_is_a_closed_utc_month(): void {
        $range = spend_export::month_range('2026-09');
        $this->assertNotNull($range);
        [$start, $end] = $range;
        $this->assertSame(gmmktime(0, 0, 0, 9, 1, 2026), $start);
        // Exclusive upper bound: the first instant of October, not the last of
        // September. Never an open bound -- see the class docblock.
        $this->assertSame(gmmktime(0, 0, 0, 10, 1, 2026), $end);
        $this->assertSame('2026-09-01T00:00:00+00:00', gmdate('c', $start));
        $this->assertSame('2026-10-01T00:00:00+00:00', gmdate('c', $end));
    }

    public function test_month_range_december_rolls_into_next_year(): void {
        $range = spend_export::month_range('2026-12');
        $this->assertNotNull($range);
        $this->assertSame('2027-01-01T00:00:00+00:00', gmdate('c', $range[1]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function bad_month_provider(): array {
        return [
            'month 13' => ['2026-13'],
            'month 00' => ['2026-00'],
            'unpadded month' => ['2026-1'],
            'three-digit month' => ['2026-012'],
            'full date' => ['2026-09-01'],
            'empty' => [''],
            'letters' => ['abcd-ef'],
            'leading space' => [' 2026-09'],
            'trailing newline' => ["2026-09\n"],
            'newline then valid' => ["x\n2026-09"],
            'sql injection' => ["2026-09' OR '1'='1"],
            'sql drop' => ['2026-09; DROP TABLE mdl_user'],
            'sql comment' => ['2026-09--'],
            'union select' => ['2026-09 UNION SELECT 1'],
            'path traversal' => ['../../2026-09'],
            'two-digit year' => ['26-09'],
            'year zero' => ['0000-01'],
            'slash separator' => ['2026/09'],
        ];
    }

    /**
     * @dataProvider bad_month_provider
     * @param string $month Candidate.
     */
    public function test_month_range_rejects_bad_input(string $month): void {
        $this->assertNull(
            spend_export::month_range($month),
            "'{$month}' must be refused, never repaired into a valid month"
        );
    }

    public function test_analytics_throws_on_bad_month(): void {
        $this->expectException(\invalid_parameter_exception::class);
        analytics::get_monthly_provider_spend('2026-13');
    }

    public function test_current_month_is_utc(): void {
        $this->assertSame(gmdate('Y-m'), spend_export::current_month());
        $this->assertNotNull(spend_export::month_range(spend_export::current_month()));
    }

    // ------------------------------------------------------------------
    // 2. The UTC boundary, on the exact second.
    // ------------------------------------------------------------------

    public function test_last_second_of_month_is_included_and_first_of_next_is_not(): void {
        // 2026-09-30 23:59:59 UTC -- in.
        $this->msg([
            'model_name' => 'gpt-4o-mini',
            'prompt_tokens' => 1_000_000,
            'timecreated' => gmmktime(23, 59, 59, 9, 30, 2026),
        ]);
        // 2026-10-01 00:00:00 UTC -- out.
        $this->msg([
            'model_name' => 'gpt-4o-mini',
            'prompt_tokens' => 1_000_000,
            'timecreated' => gmmktime(0, 0, 0, 10, 1, 2026),
        ]);
        // 2026-08-31 23:59:59 UTC -- out (an open lower bound would swallow it).
        $this->msg([
            'model_name' => 'gpt-4o-mini',
            'prompt_tokens' => 1_000_000,
            'timecreated' => gmmktime(23, 59, 59, 8, 31, 2026),
        ]);
        // 2026-09-01 00:00:00 UTC -- in.
        $this->msg([
            'model_name' => 'gpt-4o-mini',
            'prompt_tokens' => 1_000_000,
            'timecreated' => gmmktime(0, 0, 0, 9, 1, 2026),
        ]);

        $rates = model_registry::rate_for('gpt-4o-mini');
        $this->assertNotNull($rates);
        $expected = 2 * $rates['input'];

        $spend = analytics::get_monthly_provider_spend('2026-09');
        $this->assertEqualsWithDelta($expected, $spend['by_provider']['openai'], 1e-9);
    }

    public function test_neighbouring_months_do_not_overlap(): void {
        foreach ([8, 9, 10] as $mon) {
            $this->msg([
                'prompt_tokens' => 1_000_000,
                'timecreated' => gmmktime(12, 0, 0, $mon, 10, 2026),
            ]);
        }
        $rates = model_registry::rate_for('gpt-4o-mini');
        $one = $rates['input'];

        foreach (['2026-08', '2026-09', '2026-10'] as $month) {
            $spend = analytics::get_monthly_provider_spend($month);
            $this->assertEqualsWithDelta(
                $one,
                $spend['by_provider']['openai'],
                1e-9,
                "{$month} must contain exactly its own row"
            );
        }
    }

    // ------------------------------------------------------------------
    // 3. Row selection: spend_rows_predicate() verbatim.
    // ------------------------------------------------------------------

    public function test_only_billable_rows_are_priced(): void {
        // Learner turn: carries a model_name in voice mode but is not a bill.
        $this->msg(['role' => 'user', 'prompt_tokens' => 1_000_000, 'interaction_type' => 'voice']);
        // premium_router telemetry: role=system with an interaction_type that is
        // NOT in the billable list, which is what stops the double count.
        $this->msg([
            'role' => 'system',
            'interaction_type' => 'premium_router',
            'prompt_tokens' => 1_000_000,
        ]);
        // Background embedding spend: role=system and billable.
        $this->msg([
            'role' => 'system',
            'interaction_type' => 'embedding',
            'model_name' => 'text-embedding-3-small',
            'prompt_tokens' => 1_000_000,
        ]);
        // A row with no model recorded cannot be priced at all.
        $this->msg(['model_name' => null, 'prompt_tokens' => 1_000_000]);
        $this->msg(['model_name' => '', 'prompt_tokens' => 1_000_000]);

        $spend = analytics::get_monthly_provider_spend('2026-09');
        $embedrates = model_registry::rate_for('text-embedding-3-small');
        $this->assertNotNull($embedrates);
        $this->assertEqualsWithDelta(
            $embedrates['input'],
            $spend['by_provider']['openai'],
            1e-9,
            'only the embedding row is a billable API call here'
        );
        $this->assertSame(['text-embedding-3-small' => round($embedrates['input'], 6)], $spend['by_model']);
        $this->assertSame(0, $spend['unpriced_rows']);
    }

    // ------------------------------------------------------------------
    // 4. Unpriced rows are counted, never silently dropped.
    // ------------------------------------------------------------------

    public function test_unpriced_rows_are_counted_not_dropped(): void {
        $this->assertNull(
            model_registry::rate_for('brand-new-model-nobody-priced'),
            'fixture assumes this model has no rate card'
        );
        for ($i = 0; $i < 3; $i++) {
            $this->msg([
                'model_name' => 'brand-new-model-nobody-priced',
                'provider' => 'openai',
                'prompt_tokens' => 500_000,
                'completion_tokens' => 500_000,
            ]);
        }
        // One priced row alongside, so the response is a partial truth rather
        // than an obvious total zero -- the harder case to notice.
        $this->msg(['model_name' => 'gpt-4o-mini', 'prompt_tokens' => 1_000_000]);

        $spend = analytics::get_monthly_provider_spend('2026-09');
        $this->assertSame(3, $spend['unpriced_rows']);
        $this->assertSame(['brand-new-model-nobody-priced'], $spend['unpriced_models']);
        // The unpriced model contributes nothing to the money, and does not
        // appear in by_model pretending to be free.
        $this->assertArrayNotHasKey('brand-new-model-nobody-priced', $spend['by_model']);
        $rates = model_registry::rate_for('gpt-4o-mini');
        $this->assertEqualsWithDelta($rates['input'], $spend['by_provider']['openai'], 1e-9);
    }

    public function test_provider_with_only_unpriced_models_still_appears_at_zero(): void {
        $this->msg([
            'model_name' => 'brand-new-model-nobody-priced',
            'provider' => 'together',
            'prompt_tokens' => 1_000_000,
        ]);
        $spend = analytics::get_monthly_provider_spend('2026-09');
        $this->assertArrayHasKey('together', $spend['by_provider']);
        $this->assertSame(0.0, $spend['by_provider']['together']);
        $this->assertSame(1, $spend['unpriced_rows']);
    }

    public function test_an_empty_month_is_zero_rows_not_an_error(): void {
        $spend = analytics::get_monthly_provider_spend('2026-09');
        $this->assertSame([], $spend['by_provider']);
        $this->assertSame([], $spend['by_model']);
        $this->assertSame(0, $spend['unpriced_rows']);
        $this->assertSame([], $spend['unpriced_models']);
    }

    // ------------------------------------------------------------------
    // 5. Pricing goes through the registry (so the gemini fix is reflected).
    // ------------------------------------------------------------------

    public function test_gemini_flash_spend_is_not_zero(): void {
        // The production chat tutor. Before v7.4.0 this whole month read $0.00.
        $this->msg([
            'model_name' => 'gemini-2.5-flash',
            'provider' => 'gemini',
            'prompt_tokens' => 1_000_000,
            'completion_tokens' => 1_000_000,
        ]);
        $spend = analytics::get_monthly_provider_spend('2026-09');
        $this->assertEqualsWithDelta(0.30 + 2.50, $spend['by_provider']['gemini'], 1e-9);
        $this->assertSame(0, $spend['unpriced_rows']);
    }

    public function test_registry_table_price_changes_the_reported_spend(): void {
        // An admin correcting a price in the model registry (no code deploy)
        // must move this number, or the constraint that pricing be editable
        // through the admin UI alone is not actually satisfied.
        $this->msg([
            'model_name' => 'gemini-2.5-flash',
            'provider' => 'gemini',
            'prompt_tokens' => 1_000_000,
            'completion_tokens' => 0,
        ]);
        model_registry::upsert(
            ['modelkey' => 'gemini-2.5-flash', 'input_rate' => 9.0, 'output_rate' => 2.50],
            'manual',
            null
        );
        model_registry::reset_cache();

        $spend = analytics::get_monthly_provider_spend('2026-09');
        $this->assertEqualsWithDelta(9.0, $spend['by_provider']['gemini'], 1e-9);
    }

    public function test_by_model_aggregates_the_same_model_across_providers(): void {
        // One model served by two providers: the per-provider split must survive
        // (a first-column-keyed get_records_sql would have collapsed it) and the
        // per-model figure must be the sum.
        $this->msg(['model_name' => 'gpt-4o-mini', 'provider' => 'openai', 'prompt_tokens' => 1_000_000]);
        $this->msg(['model_name' => 'gpt-4o-mini', 'provider' => 'openrouter', 'prompt_tokens' => 1_000_000]);

        $rates = model_registry::rate_for('gpt-4o-mini');
        $spend = analytics::get_monthly_provider_spend('2026-09');
        $this->assertEqualsWithDelta($rates['input'], $spend['by_provider']['openai'], 1e-9);
        $this->assertEqualsWithDelta($rates['input'], $spend['by_provider']['openrouter'], 1e-9);
        $this->assertEqualsWithDelta(2 * $rates['input'], $spend['by_model']['gpt-4o-mini'], 1e-9);
    }

    // ------------------------------------------------------------------
    // 6. Auth behaviour.
    // ------------------------------------------------------------------

    public function test_endpoint_is_off_until_a_key_is_configured(): void {
        $this->assertFalse(spend_export::enabled());
        $this->assertFalse(spend_export::authenticate(''));
        // The load-bearing case: with no key configured, hash_equals('', '')
        // would be TRUE, so an empty bearer must be rejected before comparison.
        $this->assertFalse(spend_export::authenticate('anything'));

        set_config('spend_export_key', '   ', 'local_ai_course_assistant');
        $this->assertFalse(spend_export::enabled(), 'whitespace is not a key');
        $this->assertFalse(spend_export::authenticate('   '));
    }

    public function test_authenticate_accepts_only_the_exact_key(): void {
        set_config('spend_export_key', 's3cret-key', 'local_ai_course_assistant');
        $this->assertTrue(spend_export::enabled());
        $this->assertTrue(spend_export::authenticate('s3cret-key'));
        $this->assertFalse(spend_export::authenticate('s3cret-ke'));
        $this->assertFalse(spend_export::authenticate('s3cret-keyy'));
        $this->assertFalse(spend_export::authenticate('S3CRET-KEY'));
        $this->assertFalse(spend_export::authenticate(''));
    }

    public function test_bearer_is_read_from_both_header_spellings(): void {
        $this->assertSame('abc', spend_export::bearer_from_server(['HTTP_AUTHORIZATION' => 'Bearer abc']));
        // CGI/FastCGI rewrite.
        $this->assertSame('abc', spend_export::bearer_from_server(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc']));
        // Scheme is case-insensitive per RFC 7235.
        $this->assertSame('abc', spend_export::bearer_from_server(['HTTP_AUTHORIZATION' => 'bearer abc']));
        $this->assertSame('abc', spend_export::bearer_from_server(['HTTP_AUTHORIZATION' => '  Bearer   abc  ']));
    }

    public function test_bearer_rejects_everything_that_is_not_a_bearer_header(): void {
        $this->assertSame('', spend_export::bearer_from_server([]));
        $this->assertSame('', spend_export::bearer_from_server(['HTTP_AUTHORIZATION' => '']));
        $this->assertSame('', spend_export::bearer_from_server(['HTTP_AUTHORIZATION' => 'Basic YWJjOmRlZg==']));
        $this->assertSame('', spend_export::bearer_from_server(['HTTP_AUTHORIZATION' => 'abc']));
        $this->assertSame('', spend_export::bearer_from_server(['HTTP_AUTHORIZATION' => 'Bearer']));
        $this->assertSame('', spend_export::bearer_from_server(['HTTP_AUTHORIZATION' => 'Bearer   ']));
    }

    public function test_the_key_is_never_read_from_the_query_string(): void {
        // A secret in a URL lands in access logs, browser history and Referer
        // headers. The extractor is given the whole request environment and must
        // still find nothing.
        set_config('spend_export_key', 's3cret-key', 'local_ai_course_assistant');
        $server = [
            'QUERY_STRING' => 'month=2026-09&apikey=s3cret-key&key=s3cret-key',
            'REQUEST_URI' => '/local/ai_course_assistant/spend_export.php?apikey=s3cret-key',
        ];
        $this->assertSame('', spend_export::bearer_from_server($server));
        $this->assertFalse(spend_export::authenticate(spend_export::bearer_from_server($server)));
        // And the page itself must not consult a query parameter either. Comment
        // lines are stripped first: the file docblock legitimately discusses
        // ?apikey= (redash_export.php's backward-compatible path) and names the
        // setting, and prose must not be mistaken for code.
        $code = preg_replace('/^\s*(\*|\/\/|\/\*).*$/m', '', (string) file_get_contents(
            __DIR__ . '/../spend_export.php'
        ));
        foreach (['apikey', 'api_key', '$_GET', '$_REQUEST', 'spend_export_key'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $code,
                'the page must reach the key only through spend_export::authenticate()'
            );
        }
        // The one parameter it does read.
        $this->assertStringContainsString("optional_param('month'", $code);
    }

    // ------------------------------------------------------------------
    // 7. The response shape the dashboard contract expects.
    // ------------------------------------------------------------------

    public function test_response_matches_the_pull_contract(): void {
        $this->msg([
            'model_name' => 'gpt-4o-mini',
            'provider' => 'openai',
            'prompt_tokens' => 1_000_000,
        ]);
        $this->msg([
            'model_name' => 'gemini-2.5-flash',
            'provider' => 'gemini',
            'prompt_tokens' => 1_000_000,
            'completion_tokens' => 1_000_000,
        ]);

        $payload = spend_export::build('2026-09');
        $decoded = json_decode(spend_export::encode($payload), true);

        $this->assertSame(['by_provider', 'meta'], array_keys($decoded));
        // Vendor keys, as the dashboard bills: SOLA's "gemini" is Google's invoice.
        $this->assertSame(['google', 'openai'], array_keys($decoded['by_provider']));
        $this->assertEqualsWithDelta(0.30 + 2.50, $decoded['by_provider']['google'], 1e-9);
        $this->assertIsFloat($decoded['by_provider']['openai']);

        $this->assertSame('2026-09', $decoded['meta']['month']);
        $this->assertSame(0, $decoded['meta']['unpriced_rows']);
        $this->assertSame([], $decoded['meta']['unpriced_models']);
        $this->assertSame(['gemini' => 'google'], $decoded['meta']['provider_aliases']);
        $this->assertArrayHasKey('gemini-2.5-flash', $decoded['meta']['by_model']);
        // generated_at is an ISO-8601 UTC instant.
        $this->assertSame(
            $decoded['meta']['generated_at'],
            gmdate('c', strtotime($decoded['meta']['generated_at']))
        );
    }

    public function test_two_provider_ids_billing_to_one_vendor_are_summed(): void {
        $this->msg(['model_name' => 'gemini-2.5-flash', 'provider' => 'gemini', 'prompt_tokens' => 1_000_000]);
        $this->msg(['model_name' => 'gemini-2.5-flash', 'provider' => 'vertex', 'prompt_tokens' => 1_000_000]);
        $payload = spend_export::build('2026-09');
        $this->assertSame(['google'], array_keys($payload['by_provider']));
        $this->assertEqualsWithDelta(0.60, $payload['by_provider']['google'], 1e-9);
    }

    public function test_unknown_provider_passes_through_rather_than_being_dropped(): void {
        $this->assertSame('a-vendor-invented-later', spend_export::vendor_for('a-vendor-invented-later'));
        $this->assertSame('unknown', spend_export::vendor_for(''));
        $this->assertSame('google', spend_export::vendor_for('GEMINI'));
    }

    public function test_empty_month_encodes_as_objects_not_arrays(): void {
        // json_encode([]) is "[]", which breaks a consumer that indexes into
        // by_provider as an object. The contract says object.
        $json = spend_export::encode(spend_export::build('2026-09'));
        $this->assertStringContainsString('"by_provider":{}', $json);
        $this->assertStringContainsString('"unpriced_models":[]', $json);
        $decoded = json_decode($json);
        $this->assertInstanceOf(\stdClass::class, $decoded->by_provider);
        $this->assertIsArray($decoded->meta->unpriced_models);
    }

    public function test_unpriced_caveat_travels_in_the_response_meta(): void {
        $this->msg([
            'model_name' => 'brand-new-model-nobody-priced',
            'provider' => 'openai',
            'prompt_tokens' => 1_000_000,
        ]);
        $decoded = json_decode(spend_export::encode(spend_export::build('2026-09')), true);
        $this->assertSame(1, $decoded['meta']['unpriced_rows']);
        $this->assertSame(['brand-new-model-nobody-priced'], $decoded['meta']['unpriced_models']);
        // A reported zero that IS a floor, and says so.
        $this->assertSame(0.0, $decoded['by_provider']['openai']);
    }
}
