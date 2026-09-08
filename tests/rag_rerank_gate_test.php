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

use local_ai_course_assistant\embedding_provider\voyage_embedding_provider;
use local_ai_course_assistant\embedding_provider\voyage_reranker;

defined('MOODLE_INTERNAL') || die();

/**
 * Counting reranker double.
 *
 * Extends the real class so apply_rerank()'s type hint is satisfied without
 * introducing an interface for one test. Overrides everything that would touch
 * the network: a call to the parent rerank() would hit api.voyageai.com.
 *
 * The point of the counter is that "did not rerank" and "reranked and the
 * result happened to look the same" are indistinguishable from the return
 * value alone -- the gate saves money only if the call is genuinely not made.
 */
class counting_reranker extends voyage_reranker {
    /** @var int Number of rerank() calls received. */
    public int $calls = 0;

    /** @var array<int,string> Queries received, in order. */
    public array $queries = [];

    /** @var bool What is_configured() should report. */
    public bool $configured = true;

    public function is_configured(): bool {
        return $this->configured;
    }

    public function rerank(string $query, array $documents, int $topk): array {
        $this->calls++;
        $this->queries[] = $query;
        // Reverse the cosine order so a rerank that ran is visible in the
        // output: same rows, deliberately different ranking.
        $out = [];
        $n = min($topk, count($documents));
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['index' => $n - 1 - $i, 'score' => 0.9 - ($i * 0.1)];
        }
        return $out;
    }
}

/**
 * The v7.4.0 RAG rerank length gate, and the shared embedding space.
 *
 * Three operator decisions are pinned here, all of them measured rather than
 * argued:
 *
 *  1. Stage 2 is skipped for queries of `rerank_min_query_chars` (default 50)
 *     characters or fewer -- 67.7% recall gated vs 67.2% always-on at about a
 *     third of the cost. The boundary is the interesting part, so 50 and 51 are
 *     both asserted, along with the whitespace-only query that must not slip
 *     through as "50 characters of spaces".
 *  2. A skipped rerank must not call the reranker AT ALL, and must leave a
 *     telemetry row saying why -- otherwise a working gate and an expired API
 *     key look identical (both are "spend went down").
 *  3. Voyage sends ONE input_type for queries and documents by default. The
 *     asymmetric projection failed to reproduce twice and a shared space is
 *     migration insurance; see voyage_embedding_provider's docblock.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\rag_retriever::query_long_enough_to_rerank
 * @covers     \local_ai_course_assistant\rag_retriever::rerank_skip_reason
 * @covers     \local_ai_course_assistant\rag_retriever::apply_rerank
 * @covers     \local_ai_course_assistant\embedding_provider\voyage_reranker::log_skip
 * @covers     \local_ai_course_assistant\embedding_provider\voyage_embedding_provider::resolve_input_type
 * @covers     \local_ai_course_assistant\embedding_provider\voyage_embedding_provider::build_embed_payload
 * @covers     \local_ai_course_assistant\embedding_provider\voyage_embedding_provider::mrl_output_dimension
 */
final class rag_rerank_gate_test extends \advanced_testcase {
    /**
     * A query of exactly $n characters.
     *
     * @param int $n
     * @return string
     */
    private function query_of(int $n): string {
        return str_repeat('a', $n);
    }

    /**
     * Ambiguous candidates (top1-top3 margin 0.04, under the 0.086 default) so
     * the margin gate never masks a length-gate result.
     *
     * @param int $count
     * @return array
     */
    private function ambiguous_candidates(int $count = 20): array {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'id'         => $i + 1,
                'score'      => 0.700 - ($i * 0.002),
                'content'    => 'chunk ' . $i,
                'cmid'       => 0,
                'modtype'    => 'page',
                'chunkindex' => $i,
            ];
        }
        return $rows;
    }

    /**
     * Rerank-skip telemetry rows currently in the table.
     *
     * @return array
     */
    private function skip_rows(): array {
        global $DB;
        // Keyed by the table's own id, not by message: get_records_sql keys on
        // the first selected column, and two skips share a message.
        return $DB->get_records('local_ai_course_assistant_msgs', ['interaction_type' => 'rerank_skipped']);
    }

    // ------------------------------------------------------------------ gate.

    public function test_gate_boundary_at_exactly_fifty_characters(): void {
        $this->resetAfterTest();
        // 50 is the largest length still skipped.
        $this->assertFalse(rag_retriever::query_long_enough_to_rerank($this->query_of(50)));
    }

    public function test_gate_boundary_at_fifty_one_characters(): void {
        $this->resetAfterTest();
        $this->assertTrue(rag_retriever::query_long_enough_to_rerank($this->query_of(51)));
    }

    public function test_gate_default_matches_the_documented_fifty(): void {
        $this->resetAfterTest();
        unset_config('rerank_min_query_chars', 'local_ai_course_assistant');
        $this->assertSame(50, rag_retriever::RERANK_MIN_QUERY_CHARS_DEFAULT);
        $this->assertFalse(rag_retriever::query_long_enough_to_rerank($this->query_of(50)));
        $this->assertTrue(rag_retriever::query_long_enough_to_rerank($this->query_of(51)));
    }

    public function test_empty_and_whitespace_queries_are_skipped(): void {
        $this->resetAfterTest();
        $this->assertFalse(rag_retriever::query_long_enough_to_rerank(''));
        $this->assertFalse(rag_retriever::query_long_enough_to_rerank('   '));
        // Whitespace must not be able to buy a rerank: 60 spaces is a 0-char
        // query, not a long one.
        $this->assertFalse(rag_retriever::query_long_enough_to_rerank(str_repeat(' ', 60)));
        // ...and padding must not lift a short query over the line either.
        $this->assertFalse(
            rag_retriever::query_long_enough_to_rerank('   ' . $this->query_of(50) . '   ')
        );
    }

    public function test_length_is_counted_in_characters_not_bytes(): void {
        $this->resetAfterTest();
        // 26 Japanese characters: 78 bytes, 26 characters. A byte count would
        // wrongly send this to the reranker on a 46-language corpus.
        $q = str_repeat('質', 26);
        $this->assertSame(78, strlen($q));
        $this->assertSame(26, mb_strlen($q));
        $this->assertFalse(rag_retriever::query_long_enough_to_rerank($q));
    }

    public function test_threshold_is_an_admin_setting(): void {
        $this->resetAfterTest();
        set_config('rerank_min_query_chars', '10', 'local_ai_course_assistant');
        $this->assertTrue(rag_retriever::query_long_enough_to_rerank($this->query_of(11)));
        $this->assertFalse(rag_retriever::query_long_enough_to_rerank($this->query_of(10)));

        set_config('rerank_min_query_chars', '200', 'local_ai_course_assistant');
        $this->assertFalse(rag_retriever::query_long_enough_to_rerank($this->query_of(120)));
    }

    public function test_zero_threshold_disables_the_length_gate(): void {
        $this->resetAfterTest();
        // Reverting to pre-v7.4.0 behaviour must be possible from the form.
        set_config('rerank_min_query_chars', '0', 'local_ai_course_assistant');
        $this->assertTrue(rag_retriever::query_long_enough_to_rerank(''));
        $this->assertTrue(rag_retriever::query_long_enough_to_rerank($this->query_of(1)));
    }

    // --------------------------------------------------------- skip reasons.

    public function test_skip_reason_names_the_length_gate(): void {
        $this->resetAfterTest();
        $this->assertSame(
            rag_retriever::RERANK_SKIP_SHORT_QUERY,
            rag_retriever::rerank_skip_reason($this->query_of(50), $this->ambiguous_candidates())
        );
    }

    public function test_skip_reason_names_the_margin_gate(): void {
        $this->resetAfterTest();
        // Long enough for the length gate, but confident: margin 0.2 > 0.086.
        $confident = [
            ['id' => 1, 'score' => 0.900, 'content' => 'a'],
            ['id' => 2, 'score' => 0.750, 'content' => 'b'],
            ['id' => 3, 'score' => 0.700, 'content' => 'c'],
        ];
        $this->assertSame(
            rag_retriever::RERANK_SKIP_CONFIDENT,
            rag_retriever::rerank_skip_reason($this->query_of(80), $confident)
        );
    }

    public function test_skip_reason_null_when_both_gates_pass(): void {
        $this->resetAfterTest();
        $this->assertNull(
            rag_retriever::rerank_skip_reason($this->query_of(80), $this->ambiguous_candidates())
        );
    }

    public function test_length_gate_is_checked_before_the_margin_gate(): void {
        $this->resetAfterTest();
        // Short AND ambiguous: the cheap gate must be the one that reports, so
        // an operator reading the telemetry sees why the call was not made.
        $this->assertSame(
            rag_retriever::RERANK_SKIP_SHORT_QUERY,
            rag_retriever::rerank_skip_reason($this->query_of(5), $this->ambiguous_candidates())
        );
    }

    // ------------------------------------------------- reranker not invoked.

    public function test_skipped_rerank_never_calls_the_reranker(): void {
        $this->resetAfterTest();
        $fake = new counting_reranker();

        $result = rag_retriever::apply_rerank(
            $this->query_of(50),
            $this->ambiguous_candidates(),
            5,
            $fake,
            0
        );

        $this->assertSame(0, $fake->calls, 'a gated-off query must not reach the reranker');
        $this->assertNull($result, 'skipping must fall back to the cosine ranking');
    }

    public function test_long_query_does_call_the_reranker(): void {
        $this->resetAfterTest();
        $fake = new counting_reranker();
        $candidates = $this->ambiguous_candidates();

        $result = rag_retriever::apply_rerank($this->query_of(51), $candidates, 5, $fake, 0);

        $this->assertSame(1, $fake->calls);
        $this->assertCount(5, $result);
        // The double reverses the order, so a real rerank is observable rather
        // than inferred: row 0 must no longer be the top cosine hit.
        $this->assertNotSame($candidates[0]['content'], $result[0]['content']);
        // The pre-rerank cosine score is preserved for telemetry.
        $this->assertArrayHasKey('cosine_score', $result[0]);
    }

    public function test_unconfigured_reranker_falls_back_without_calling(): void {
        $this->resetAfterTest();
        $fake = new counting_reranker();
        $fake->configured = false;

        $result = rag_retriever::apply_rerank(
            $this->query_of(80),
            $this->ambiguous_candidates(),
            5,
            $fake,
            0
        );

        $this->assertSame(0, $fake->calls);
        $this->assertNull($result);
    }

    // ------------------------------------------------------------ telemetry.

    public function test_skip_is_recorded_with_its_reason(): void {
        $this->resetAfterTest();
        $this->assertCount(0, $this->skip_rows());

        rag_retriever::apply_rerank(
            $this->query_of(50),
            $this->ambiguous_candidates(),
            5,
            new counting_reranker(),
            7
        );

        $rows = $this->skip_rows();
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('[Rerank skipped: short_query]', $row->message);
        $this->assertSame('system', $row->role);
        $this->assertSame('rerank', $row->provider);
        $this->assertSame(7, (int) $row->courseid, 'the gated course must be identifiable');
        $this->assertSame(0, (int) $row->tokens_used, 'a skip costs nothing and must say so');
    }

    public function test_skip_telemetry_is_excluded_from_billable_rows(): void {
        $this->resetAfterTest();
        global $DB;

        rag_retriever::apply_rerank(
            $this->query_of(50),
            $this->ambiguous_candidates(),
            5,
            new counting_reranker(),
            7
        );

        // A zero-token telemetry row must not join the spend set, or every
        // gated query would dilute cost-per-rerank instead of explaining it.
        $count = $DB->count_records_sql(
            'SELECT COUNT(1) FROM {local_ai_course_assistant_msgs} m WHERE '
                . analytics::spend_rows_predicate('m')
        );
        $this->assertSame(0, $count);
    }

    public function test_margin_skip_is_recorded_with_its_own_reason(): void {
        $this->resetAfterTest();
        $confident = [
            ['id' => 1, 'score' => 0.900, 'content' => 'a'],
            ['id' => 2, 'score' => 0.750, 'content' => 'b'],
            ['id' => 3, 'score' => 0.700, 'content' => 'c'],
        ];
        rag_retriever::apply_rerank($this->query_of(80), $confident, 3, new counting_reranker(), 0);

        $rows = $this->skip_rows();
        $this->assertCount(1, $rows);
        $this->assertSame('[Rerank skipped: confident_margin]', reset($rows)->message);
    }

    public function test_reason_slug_is_sanitized_before_storage(): void {
        $this->resetAfterTest();
        voyage_reranker::log_skip("weird reason]\n[injected", 0);
        $rows = $this->skip_rows();
        $this->assertCount(1, $rows);
        $this->assertSame('[Rerank skipped: weirdreasoninjected]', reset($rows)->message);
    }

    public function test_running_rerank_writes_no_skip_row(): void {
        $this->resetAfterTest();
        rag_retriever::apply_rerank(
            $this->query_of(80),
            $this->ambiguous_candidates(),
            5,
            new counting_reranker(),
            0
        );
        $this->assertCount(0, $this->skip_rows());
    }

    // ----------------------------------------------- shared embedding space.

    public function test_shared_mode_is_the_default(): void {
        $this->resetAfterTest();
        unset_config('embed_input_type_mode', 'local_ai_course_assistant');
        $this->assertSame('document', voyage_embedding_provider::resolve_input_type('query'));
        $this->assertSame('document', voyage_embedding_provider::resolve_input_type('document'));
    }

    public function test_unrecognized_mode_resolves_to_shared(): void {
        $this->resetAfterTest();
        set_config('embed_input_type_mode', 'ASYMETRIC-typo', 'local_ai_course_assistant');
        $this->assertSame('document', voyage_embedding_provider::resolve_input_type('query'));
    }

    public function test_asymmetric_mode_is_still_available(): void {
        $this->resetAfterTest();
        set_config('embed_input_type_mode', 'asymmetric', 'local_ai_course_assistant');
        $this->assertSame('query', voyage_embedding_provider::resolve_input_type('query'));
        $this->assertSame('document', voyage_embedding_provider::resolve_input_type('document'));
    }

    public function test_shared_mode_sends_one_input_type_for_query_and_document(): void {
        $this->resetAfterTest();
        set_config('embed_provider', 'voyage', 'local_ai_course_assistant');
        set_config('embed_model', 'voyage-4-large', 'local_ai_course_assistant');
        unset_config('embed_input_type_mode', 'local_ai_course_assistant');

        $provider = new voyage_embedding_provider();
        $doc = $provider->build_embed_payload(['a chunk of course material'], 'document');
        $query = $provider->build_embed_payload(['what is marginal cost'], 'query');

        $this->assertSame($doc['input_type'], $query['input_type']);
        $this->assertSame('document', $query['input_type']);
    }

    public function test_asymmetric_mode_sends_two_input_types(): void {
        $this->resetAfterTest();
        set_config('embed_provider', 'voyage', 'local_ai_course_assistant');
        set_config('embed_model', 'voyage-4-large', 'local_ai_course_assistant');
        set_config('embed_input_type_mode', 'asymmetric', 'local_ai_course_assistant');

        $provider = new voyage_embedding_provider();
        $this->assertSame('document', $provider->build_embed_payload(['x'], 'document')['input_type']);
        $this->assertSame('query', $provider->build_embed_payload(['x'], 'query')['input_type']);
    }

    public function test_shared_mode_still_honours_the_query_model(): void {
        $this->resetAfterTest();
        // The mode changes the wire input_type only. The query-side MODEL is
        // chosen from the logical side of the call, so embed_query_model keeps
        // working -- collapsing the two would have silently sent the document
        // model on every query.
        set_config('embed_provider', 'voyage', 'local_ai_course_assistant');
        set_config('embed_model', 'voyage-4-large', 'local_ai_course_assistant');
        set_config('embed_query_model', 'voyage-4-lite', 'local_ai_course_assistant');
        unset_config('embed_input_type_mode', 'local_ai_course_assistant');

        $provider = new voyage_embedding_provider();
        $this->assertSame('voyage-4-large', $provider->build_embed_payload(['x'], 'document')['model']);
        $this->assertSame('voyage-4-lite', $provider->build_embed_payload(['x'], 'query')['model']);
    }

    public function test_shared_mode_still_sends_a_float_query_for_int8_documents(): void {
        $this->resetAfterTest();
        set_config('embed_provider', 'voyage', 'local_ai_course_assistant');
        set_config('embed_model', 'voyage-4-large', 'local_ai_course_assistant');
        set_config('embed_dtype', 'int8', 'local_ai_course_assistant');
        unset_config('embed_input_type_mode', 'local_ai_course_assistant');

        $provider = new voyage_embedding_provider();
        $doc = $provider->build_embed_payload(['x'], 'document');
        $query = $provider->build_embed_payload(['x'], 'query');

        $this->assertSame('int8', $doc['output_dtype']);
        // Float is the API default, so the query payload omits it entirely.
        $this->assertArrayNotHasKey('output_dtype', $query);
    }

    // ------------------------------------------------------ voyage-4-large.

    public function test_voyage_4_large_is_a_supported_model(): void {
        $this->resetAfterTest();
        $this->assertContains('voyage-4-large', voyage_embedding_provider::supported_models());
        $profile = voyage_embedding_provider::model_profile('voyage-4-large');
        $this->assertNotNull($profile);
        $this->assertContains(2048, $profile['dims']);
    }

    public function test_voyage_4_large_profile_is_not_swallowed_by_voyage_4(): void {
        $this->resetAfterTest();
        // Longest prefix must win, the same trap the rate card documents.
        $models = voyage_embedding_provider::supported_models();
        $this->assertLessThan(
            array_search('voyage-4', $models, true),
            array_search('voyage-4-large', $models, true)
        );
    }

    public function test_dimension_2048_is_accepted_for_voyage_4_large(): void {
        $this->resetAfterTest();
        $this->assertSame(2048, voyage_embedding_provider::mrl_output_dimension(2048, 'voyage-4-large'));
        // An OpenAI-shaped width left over from a provider switch is still
        // omitted rather than sent and rejected.
        $this->assertNull(voyage_embedding_provider::mrl_output_dimension(1536, 'voyage-4-large'));
    }

    public function test_dimension_2048_reaches_both_payloads(): void {
        $this->resetAfterTest();
        set_config('embed_provider', 'voyage', 'local_ai_course_assistant');
        set_config('embed_model', 'voyage-4-large', 'local_ai_course_assistant');
        set_config('embed_dimensions', '2048', 'local_ai_course_assistant');
        unset_config('embed_query_model', 'local_ai_course_assistant');
        unset_config('embed_input_type_mode', 'local_ai_course_assistant');

        $provider = new voyage_embedding_provider();
        $doc = $provider->build_embed_payload(['a chunk'], 'document');
        $query = $provider->build_embed_payload(['a question'], 'query');

        // Both sides, or the index and the queries would be different widths
        // and score as noise.
        $this->assertSame(2048, $doc['output_dimension']);
        $this->assertSame(2048, $query['output_dimension']);
        $this->assertSame('voyage-4-large', $doc['model']);
        $this->assertSame('voyage-4-large', $query['model']);
    }

    public function test_native_width_is_still_omitted(): void {
        $this->resetAfterTest();
        set_config('embed_provider', 'voyage', 'local_ai_course_assistant');
        set_config('embed_model', 'voyage-4-large', 'local_ai_course_assistant');
        set_config('embed_dimensions', '1024', 'local_ai_course_assistant');

        $provider = new voyage_embedding_provider();
        $this->assertArrayNotHasKey(
            'output_dimension',
            $provider->build_embed_payload(['x'], 'document')
        );
    }
}
