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

/**
 * RAG benchmark harness + fixture generator: the three defects the 2026-09-08
 * readout exposed.
 *
 * 1. The per-course summary printed course115/116/117 as three rows all
 *    labelled "course11" and course130/131/132 as three labelled "course13" --
 *    six distinct courses reading as two duplicated ones, because the identity
 *    column was padded AND truncated to the 8 characters the numeric columns
 *    use. Grouping was never wrong; the table was.
 * 2. Anchor soundness has to be judged at the harness's 50-byte truncation
 *    length. Two anchors that differ only after byte 50 are one string as far
 *    as scoring is concerned, and that is what produced the 189 unrepairable
 *    fixtures of the 2026-08-21 run.
 * 3. The 40-row bus101_pol101 set is regression smoke only. It must never
 *    print as a decision instrument.
 *
 * The functions under test are pure helpers living inside the two CLI scripts,
 * which cannot be included (they define CLI_SCRIPT and require config.php). The
 * marker-delimited region of each is extracted and evaluated here instead, so
 * these tests exercise the shipped code without the harness making a single
 * paid API call.
 *
 * @package    local_ai_course_assistant
 * @covers     ::local_ai_course_assistant_ragbench_group_results
 * @covers     ::local_ai_course_assistant_ragbench_summary_table
 * @covers     ::local_ai_course_assistant_ragbench_set_grade
 * @covers     ::local_ai_course_assistant_ragbench_anchor_status
 * @covers     ::local_ai_course_assistant_genconv_anchor
 * @covers     ::local_ai_course_assistant_genconv_anchor_status
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rag_harness_test extends \basic_testcase {
    /** @var int The length the harness truncates a text anchor to before matching. */
    const ANCHOR_BYTES = 50;

    /**
     * Load the pure-helper region of a CLI script.
     *
     * @param string $relpath  Plugin-relative path of the CLI script.
     * @param string $marker   Marker stem, e.g. 'RAGBENCH-PURE'.
     * @param string $probefn  A function the region declares; used to load once.
     */
    private function load_pure_region(string $relpath, string $marker, string $probefn): void {
        global $CFG;
        if (function_exists($probefn)) {
            return;
        }
        $path = $CFG->dirroot . '/local/ai_course_assistant/' . $relpath;
        $src = file_get_contents($path);
        $this->assertNotFalse($src, "cannot read {$relpath}");

        // Anchored to a whole comment line, so a mention of the marker in prose
        // cannot be mistaken for the marker itself.
        $found = preg_match('#^// ' . preg_quote($marker, '#') . '-BEGIN$#m', $src, $bm, PREG_OFFSET_CAPTURE)
            && preg_match('#^// ' . preg_quote($marker, '#') . '-END$#m', $src, $em, PREG_OFFSET_CAPTURE);
        $this->assertSame(1, (int) $found, "{$relpath} has no {$marker}-BEGIN/-END marker lines");
        $begin = $bm[0][1] + strlen($bm[0][0]);
        $end = $em[0][1];
        $this->assertGreaterThan($begin, $end, "{$marker} markers are out of order in {$relpath}");

        $region = substr($src, $begin, $end - $begin);
        // The region must be declarations only: anything else would run here.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*(?:echo|print|exit|require|include|global|const|define)\b/m',
            $region,
            "the {$marker} region of {$relpath} contains executable statements; it must "
            . 'hold function declarations only so it can be unit-tested in isolation'
        );
        // phpcs:ignore moodle.PHP.ForbiddenFunctions.Found
        eval($region);
        $this->assertTrue(function_exists($probefn),
            "the {$marker} region of {$relpath} did not declare {$probefn}()");
    }

    /**
     * Load the harness helpers.
     */
    private function load_harness(): void {
        $this->load_pure_region(
            'admin/cli/run_rag_fixture_benchmark.php',
            'RAGBENCH-PURE',
            'local_ai_course_assistant_ragbench_group_results'
        );
    }

    /**
     * Load the fixture-generator helpers.
     */
    private function load_generator(): void {
        $this->load_pure_region(
            'admin/cli/generate_conversational_fixtures.php',
            'GENCONV-PURE',
            'local_ai_course_assistant_genconv_anchor'
        );
    }

    /**
     * Per-fixture result rows for the courses whose labels collided in the
     * 2026-09-08 readout: three sharing the first 8 characters "course11",
     * three sharing "course13".
     *
     * @return array
     */
    private function colliding_rows(): array {
        $rows = [];
        foreach ([115, 116, 117, 130, 131, 132] as $i => $cid) {
            $rows[] = [
                'fixture_id'          => sprintf('conv_%d_%06d', $cid, 900 + $i),
                'course'              => 'course' . $cid,
                'courseid'            => $cid,
                'cosine_rank'         => $i + 1,
                'total_embed_ms'      => 100 + $i,
            ];
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // 1. The group-label collision.
    // ------------------------------------------------------------------

    public function test_grouping_yields_one_group_per_course_with_distinct_labels(): void {
        $this->load_harness();
        $groups = local_ai_course_assistant_ragbench_group_results($this->colliding_rows());

        // overall + one per course.
        $this->assertCount(7, $groups, 'expected the overall group plus one per course');
        $this->assertSame('overall', $groups[0]['label']);
        $this->assertCount(6, $groups[0]['rows'], 'the overall group must hold every row');

        $labels = array_column(array_slice($groups, 1), 'label');
        $this->assertSame($labels, array_values(array_unique($labels)),
            'per-course labels must be distinct: repeated labels are what made the '
            . '13-course readout unusable');
        $this->assertSame(
            ['course115', 'course116', 'course117', 'course130', 'course131', 'course132'],
            $labels
        );
        foreach (array_slice($groups, 1) as $g) {
            $this->assertCount(1, $g['rows'], 'each course must keep its own row');
        }
    }

    public function test_summary_table_never_truncates_the_identity_column(): void {
        $this->load_harness();
        $summary = [];
        foreach (['overall', 'course115', 'course116', 'course117',
                'course130', 'course131', 'course132'] as $label) {
            $summary[] = [
                'group'              => $label,
                'n'                  => 1,
                'cosine_recall_at_1' => 0.5,
                'cosine_recall_at_3' => 0.5,
                'cosine_recall_at_5' => 0.5,
                'cosine_mrr'         => 0.5,
                'rerank_recall_at_1' => null,
                'rerank_recall_at_3' => null,
                'rerank_recall_at_5' => null,
                'rerank_mrr'         => null,
                'delta_recall_at_3'  => null,
                'cosine_p50_embed_ms' => 120,
                'rerank_p50_ms'      => null,
                'rerank_cost_per_query_usd' => 0.0,
            ];
        }
        $lines = local_ai_course_assistant_ragbench_summary_table($summary);

        // Header + rule + one line per group.
        $this->assertCount(9, $lines);
        $printed = [];
        foreach (array_slice($lines, 2) as $line) {
            $printed[] = trim(explode('|', $line)[0]);
        }
        $this->assertSame($printed, array_values(array_unique($printed)),
            "the table printed duplicate group labels for distinct groups:\n" . implode("\n", $lines));
        foreach (['course115', 'course116', 'course117', 'course130', 'course131', 'course132'] as $label) {
            $this->assertContains($label, $printed, "label {$label} did not survive rendering intact");
        }
        // The specific historical symptom.
        $this->assertNotContains('course11', $printed, 'a label was truncated to 8 characters again');
        $this->assertNotContains('course13', $printed, 'a label was truncated to 8 characters again');
    }

    public function test_two_courses_sharing_a_shortname_stay_separate_rows(): void {
        // The BUS101/POLSC101 sets label by shortname, and two distinct courses
        // can carry one shortname. Keying the group on the label merged them.
        $this->load_harness();
        $rows = [
            ['fixture_id' => 'a', 'course' => 'BUS101', 'courseid' => 11, 'cosine_rank' => 1],
            ['fixture_id' => 'b', 'course' => 'BUS101', 'courseid' => 99, 'cosine_rank' => 4],
        ];
        $groups = local_ai_course_assistant_ragbench_group_results($rows);
        $this->assertCount(3, $groups, 'two distinct course ids must not merge into one group');
        $labels = array_column(array_slice($groups, 1), 'label');
        $this->assertSame($labels, array_values(array_unique($labels)),
            'a shared shortname must be disambiguated, not repeated');
        $this->assertSame(['BUS101#11', 'BUS101#99'], $labels);
    }

    // ------------------------------------------------------------------
    // 2. Anchor uniqueness at the truncation length.
    // ------------------------------------------------------------------

    public function test_anchors_differing_only_after_byte_50_are_duplicates(): void {
        $this->load_harness();
        $this->load_generator();

        $shared = str_pad('The Federal Reserve sets the target rate ', self::ANCHOR_BYTES, 'x');
        $this->assertSame(self::ANCHOR_BYTES, strlen($shared));
        $one = $shared . ' and then it publishes the minutes.';
        $two = $shared . ' and then the committee votes again.';
        $this->assertNotSame($one, $two, 'the two anchors must differ as whole strings');
        $this->assertSame(
            substr($one, 0, self::ANCHOR_BYTES),
            substr($two, 0, self::ANCHOR_BYTES),
            'the two anchors must be identical up to the truncation length'
        );

        // Both spans live in the course (overlapping neighbours), so the shared
        // 50-byte prefix occurs twice in the haystack.
        $blob = $one . "\x00" . $two;

        foreach ([
            'harness'   => 'local_ai_course_assistant_ragbench_anchor_status',
            'generator' => 'local_ai_course_assistant_genconv_anchor_status',
        ] as $side => $fn) {
            $this->assertSame('ambiguous', $fn($one, $one, $blob, self::ANCHOR_BYTES),
                "{$side}: an anchor whose first " . self::ANCHOR_BYTES . ' bytes occur in an '
                . 'overlapping neighbour must be rejected as a duplicate, however far the '
                . 'full strings diverge');
            $this->assertSame('ambiguous', $fn($two, $two, $blob, self::ANCHOR_BYTES));
            // Same pair, but unique in a course that holds only one of them.
            $this->assertSame('', $fn($one, $one, $one, self::ANCHOR_BYTES),
                "{$side}: a genuinely unique anchor must pass");
            $this->assertSame('missing', $fn('', $one, $one, self::ANCHOR_BYTES),
                "{$side}: an empty anchor is not an anchor");
            $this->assertSame('notverbatim', $fn('Not in this chunk at all, not one word of it.',
                $one, $blob, self::ANCHOR_BYTES),
                "{$side}: an anchor that is not verbatim in its own chunk can never match");
        }
    }

    public function test_generator_refuses_a_chunk_with_no_anchor_unique_at_50_bytes(): void {
        $this->load_generator();

        // A chunk that is a byte-for-byte duplicate of its neighbour: every
        // candidate span occurs twice, so there is no anchor to be had.
        $chunk = 'Opportunity cost is the value of the next best alternative forgone when a '
            . 'choice is made, and it is measured in what you gave up rather than what you paid. '
            . 'Every allocation of a scarce resource therefore carries one.';
        $blob = $chunk . "\x00" . $chunk;
        $this->assertSame(
            '',
            local_ai_course_assistant_genconv_anchor($chunk, $blob, self::ANCHOR_BYTES),
            'a near-duplicate chunk must yield NO anchor, so the generator rejects it by name '
            . 'instead of emitting a fixture that cannot be scored after a reindex'
        );

        // The same chunk in a course where it is the only copy: anchorable, and
        // the anchor it picks must satisfy the harness predicate.
        $anchor = local_ai_course_assistant_genconv_anchor($chunk, $chunk, self::ANCHOR_BYTES);
        $this->assertNotSame('', $anchor, 'a unique chunk must yield an anchor');
        $this->assertGreaterThanOrEqual(self::ANCHOR_BYTES, strlen($anchor),
            'an anchor shorter than the truncation length cannot be tested at that length');
        $this->assertStringContainsString($anchor, $chunk, 'the anchor must be verbatim');
        $this->assertSame('', local_ai_course_assistant_genconv_anchor_status(
            $anchor, $chunk, $chunk, self::ANCHOR_BYTES));
    }

    public function test_generator_never_emits_a_fixture_without_an_anchor(): void {
        global $CFG;
        $src = file_get_contents($CFG->dirroot
            . '/local/ai_course_assistant/admin/cli/generate_conversational_fixtures.php');

        // The emit path is guarded: anchoring happens first, and an unanchorable
        // chunk is named on the rejected list rather than written out.
        $this->assertMatchesRegularExpression(
            '/\$anchor\s*===\s*\'\'\s*\)\s*\{\s*\n\s*\$rejected\[\]/',
            $src,
            'an unanchorable chunk must be pushed onto the named rejected list'
        );
        $this->assertStringContainsString("'expected_substring' => \$anchor", $src,
            'every emitted fixture must carry the derived anchor');
        $this->assertStringContainsString('cli_error(', $src,
            'the generator must be able to fail loudly, not only warn');
        // Naming, not counting: the course and the item both appear in the message.
        $this->assertStringContainsString('"course {$cid} / {$item}', $src,
            'a rejection must name the course and the item');
    }

    public function test_generator_fails_the_run_when_anchoring_leaves_a_short_set(): void {
        global $CFG;
        $src = file_get_contents($CFG->dirroot
            . '/local/ai_course_assistant/admin/cli/generate_conversational_fixtures.php');

        // Loud, not silent: a shortfall is a non-zero exit unless the operator
        // says the smaller set was intended.
        $this->assertStringContainsString('$shortfall[] =', $src);
        $this->assertMatchesRegularExpression(
            '#if \(!\$options\[.allow-short.\]\) \{\s+cli_error\(#',
            $src,
            'a short set must exit non-zero unless --allow-short was passed'
        );
        $this->assertStringContainsString("'allow-short' => false", $src,
            '--allow-short must default to off, so the loud failure is the default');
    }

    public function test_generator_anchors_before_it_pays_for_a_question(): void {
        global $CFG;
        $src = file_get_contents($CFG->dirroot
            . '/local/ai_course_assistant/admin/cli/generate_conversational_fixtures.php');
        $body = substr($src, strpos($src, 'foreach ($courses as $cid) {'));
        $anchor = strpos($body, 'local_ai_course_assistant_genconv_anchor(');
        $ask = strpos($body, 'local_ai_course_assistant_genconv_ask(');
        $this->assertNotFalse($anchor);
        $this->assertNotFalse($ask);
        $this->assertLessThan($ask, $anchor,
            'the anchor must be derived before the paid chat call, so rejecting an '
            . 'unanchorable chunk costs nothing');
    }

    public function test_the_two_scripts_agree_on_the_anchor_length(): void {
        global $CFG;
        $root = $CFG->dirroot . '/local/ai_course_assistant/admin/cli/';
        $this->assertStringContainsString(
            "define('ANCHOR_MATCH_BYTES', " . self::ANCHOR_BYTES . ')',
            file_get_contents($root . 'run_rag_fixture_benchmark.php'),
            'the harness truncation length moved; the generator enforces uniqueness at it'
        );
        $this->assertStringContainsString(
            'const GENCONV_ANCHOR_BYTES = ' . self::ANCHOR_BYTES . ';',
            file_get_contents($root . 'generate_conversational_fixtures.php'),
            'the generator must enforce uniqueness at the length the harness matches on'
        );
    }

    // ------------------------------------------------------------------
    // 3. The 40-row set is smoke, not a decision instrument.
    // ------------------------------------------------------------------

    public function test_the_40_row_set_is_labelled_non_decision_grade_and_needs_a_flag(): void {
        $this->load_harness();

        $grade = local_ai_course_assistant_ragbench_set_grade(
            'tests/golden/rag_fixtures_bus101_pol101.json', 40, false);
        $this->assertFalse($grade['decision_grade'], 'the 40-row set is regression smoke only');
        $this->assertSame('SMOKE', $grade['grade']);
        $this->assertTrue($grade['blocked'],
            'a small-set run must refuse to start without an explicit flag');
        $this->assertNotSame('', $grade['reason'], 'the refusal must say why');

        $stamp = local_ai_course_assistant_ragbench_grade_stamp($grade);
        $this->assertStringContainsString('SMOKE', $stamp);
        $this->assertStringContainsString('NOT decision-grade', $stamp);
        // Every run header names the set and the count, so no readout is
        // ambiguous about what it was measured on.
        $this->assertStringContainsString('rag_fixtures_bus101_pol101.json', $stamp);
        $this->assertStringContainsString('n=40', $stamp);

        // With the flag it may run -- still stamped SMOKE.
        $allowed = local_ai_course_assistant_ragbench_set_grade(
            'tests/golden/rag_fixtures_bus101_pol101.json', 40, true);
        $this->assertFalse($allowed['blocked'], '--allow-small-set must let it run');
        $this->assertFalse($allowed['decision_grade'], 'the flag must not promote it');
        $this->assertSame('SMOKE', $allowed['grade']);
    }

    public function test_padding_the_smoke_set_does_not_promote_it(): void {
        $this->load_harness();
        // Two courses cannot represent the 13-course production shape however
        // many questions are asked of them.
        $grade = local_ai_course_assistant_ragbench_set_grade(
            'tests/golden/rag_fixtures_bus101_pol101_anchored_2026-08-27.json', 900, false);
        $this->assertFalse($grade['decision_grade']);
        $this->assertTrue($grade['blocked']);
    }

    public function test_the_816_row_production_shaped_set_is_decision_grade(): void {
        $this->load_harness();
        $grade = local_ai_course_assistant_ragbench_set_grade(
            'tests/golden/rag_fixtures_prodshape_anchored_2026-08-27.json', 816, false);
        $this->assertTrue($grade['decision_grade'], 'the production-shaped set is the decision instrument');
        $this->assertSame('DECISION-GRADE', $grade['grade']);
        $this->assertFalse($grade['blocked']);

        $stamp = local_ai_course_assistant_ragbench_grade_stamp($grade);
        $this->assertStringContainsString('DECISION-GRADE', $stamp);
        $this->assertStringNotContainsString('SMOKE', $stamp);
        $this->assertStringContainsString('n=816', $stamp);
    }

    public function test_a_middling_set_below_the_floor_is_smoke(): void {
        $this->load_harness();
        $grade = local_ai_course_assistant_ragbench_set_grade('some_other_set.json', 199, false);
        $this->assertFalse($grade['decision_grade']);
        $this->assertSame(200, $grade['min_n'], 'the floor must be stated, not implied');
        $ok = local_ai_course_assistant_ragbench_set_grade('some_other_set.json', 200, false);
        $this->assertTrue($ok['decision_grade']);
    }

    public function test_the_harness_blocks_a_small_set_before_spending_anything(): void {
        global $CFG;
        $src = file_get_contents($CFG->dirroot
            . '/local/ai_course_assistant/admin/cli/run_rag_fixture_benchmark.php');

        // The guard has to sit above the provider construction and every arm
        // loop, or a refused run still pays for embeddings.
        $guard = strpos($src, "if (\$setgrade['blocked'])");
        $this->assertNotFalse($guard, 'the harness must act on the blocked grade');
        $provider = strpos($src, 'base_embedding_provider::create_from_config()');
        $this->assertNotFalse($provider);
        $this->assertLessThan($provider, $guard,
            'the small-set guard must run before any provider is built or any call is paid for');
        $this->assertStringContainsString('--allow-small-set', $src,
            'the flag that permits a smoke run must be documented in the script');
    }
}
