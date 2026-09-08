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
 * Tests for the gate that decides which embedding vectors may be compared.
 *
 * This class exists because the failure it prevents is silent. Scoring a query
 * from one embedding space against documents from another does not throw — it
 * returns plausible cosine values that rank nothing correctly, which on a dev
 * site once looked exactly like "retrieval is broken" for ten minutes with no
 * error anywhere. Every assertion here is about refusing to guess.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\embedding_compat
 */
final class embedding_compat_test extends \basic_testcase {

    // ---------- normalize_dtype: this value reaches an API payload ----------

    public function test_known_dtypes_pass_through(): void {
        foreach (embedding_compat::DTYPES as $d) {
            $this->assertSame($d, embedding_compat::normalize_dtype($d));
        }
    }

    public function test_unknown_dtype_resolves_to_float(): void {
        // Anything we do not recognize must become float, because float is what
        // the bytes of an unlabelled row actually contain.
        foreach (['', 'FLOAT64', 'int4', 'garbage', 'binary; DROP TABLE', '../../etc/passwd'] as $bad) {
            $this->assertSame(
                embedding_compat::DTYPE_FLOAT,
                embedding_compat::normalize_dtype($bad),
                "expected float for input '{$bad}'"
            );
        }
    }

    public function test_null_dtype_resolves_to_float(): void {
        // Null is the value every row written before the column existed has.
        $this->assertSame(embedding_compat::DTYPE_FLOAT, embedding_compat::normalize_dtype(null));
    }

    public function test_dtype_is_case_and_whitespace_insensitive(): void {
        $this->assertSame(embedding_compat::DTYPE_INT8, embedding_compat::normalize_dtype('  INT8 '));
        $this->assertSame(embedding_compat::DTYPE_BINARY, embedding_compat::normalize_dtype("Binary\n"));
    }

    // ---------- family resolution ----------

    public function test_voyage_4_members_share_a_family(): void {
        foreach (['voyage-4', 'voyage-4-lite', 'voyage-4-large', 'voyage-4-nano'] as $m) {
            $this->assertSame('voyage-4', embedding_compat::family($m), "{$m} should be in the voyage-4 family");
        }
    }

    public function test_models_with_no_declared_shared_space_have_no_family(): void {
        foreach (['voyage-3.5', 'voyage-3.5-lite', 'text-embedding-3-small', 'nomic-embed-text', ''] as $m) {
            $this->assertNull(embedding_compat::family($m), "{$m} should have no family");
        }
    }

    public function test_contextualized_model_is_not_in_the_plain_family(): void {
        // voyage-context-4 produces chunk vectors through a different pipeline.
        // Voyage does not claim interchangeability with the plain 4 series, so
        // we must not assume it either.
        $this->assertNull(embedding_compat::family('voyage-context-4'));
    }

    public function test_family_prefix_does_not_swallow_an_unrelated_longer_name(): void {
        // 'voyage-4' must not claim 'voyage-40-xl' or 'voyage-45'. A naive
        // strpos() prefix match would, and would then permit mixing vectors
        // from genuinely different models.
        $this->assertNull(embedding_compat::family('voyage-40-xl'));
        $this->assertNull(embedding_compat::family('voyage-45'));
        $this->assertNull(embedding_compat::family('voyage-4x'));
    }

    // ---------- are_comparable ----------

    public function test_identical_models_are_comparable(): void {
        $this->assertTrue(embedding_compat::are_comparable('text-embedding-3-small', 'text-embedding-3-small'));
        $this->assertTrue(embedding_compat::are_comparable('voyage-3.5', 'voyage-3.5'));
    }

    public function test_same_family_models_are_comparable(): void {
        $this->assertTrue(embedding_compat::are_comparable('voyage-4-lite', 'voyage-4-large'));
        $this->assertTrue(embedding_compat::are_comparable('voyage-4-nano', 'voyage-4'));
    }

    public function test_different_families_are_not_comparable(): void {
        $this->assertFalse(embedding_compat::are_comparable('voyage-4-lite', 'voyage-3.5'));
        $this->assertFalse(embedding_compat::are_comparable('voyage-4', 'text-embedding-3-small'));
        // The case that caused a real incident: different widths, same vendor.
        $this->assertFalse(embedding_compat::are_comparable('voyage-3.5', 'text-embedding-3-small'));
    }

    public function test_empty_model_names_are_never_comparable(): void {
        // Unknown provenance is not a safe basis for mixing. The retriever
        // handles legacy rows with no recorded model separately and explicitly,
        // rather than having this return true for them.
        $this->assertFalse(embedding_compat::are_comparable('', 'voyage-4'));
        $this->assertFalse(embedding_compat::are_comparable('voyage-4', ''));
        $this->assertFalse(embedding_compat::are_comparable('', ''));
    }

    public function test_comparability_ignores_case_and_padding(): void {
        $this->assertTrue(embedding_compat::are_comparable(' Voyage-4-Lite ', 'voyage-4-large'));
    }

    public function test_comparability_is_symmetric(): void {
        // Asymmetry here would mean retrieval behaved differently depending on
        // which side of the pair happened to be the query.
        $pairs = [
            ['voyage-4-lite', 'voyage-4-large'],
            ['voyage-4', 'voyage-3.5'],
            ['text-embedding-3-small', 'voyage-4'],
            ['', 'voyage-4'],
        ];
        foreach ($pairs as [$a, $b]) {
            $this->assertSame(
                embedding_compat::are_comparable($a, $b),
                embedding_compat::are_comparable($b, $a),
                "comparability of ('{$a}','{$b}') is not symmetric"
            );
        }
    }

    // ---------- sizing ----------

    public function test_vector_bytes_matches_the_encoding(): void {
        $this->assertSame(4096, embedding_compat::vector_bytes(1024, embedding_compat::DTYPE_FLOAT));
        $this->assertSame(1024, embedding_compat::vector_bytes(1024, embedding_compat::DTYPE_INT8));
        $this->assertSame(128, embedding_compat::vector_bytes(1024, embedding_compat::DTYPE_BINARY));
    }

    public function test_vector_bytes_rounds_a_partial_byte_up(): void {
        // 10 bits need two bytes, not one and a quarter.
        $this->assertSame(2, embedding_compat::vector_bytes(10, embedding_compat::DTYPE_BINARY));
    }

    public function test_vector_bytes_of_nothing_is_zero(): void {
        $this->assertSame(0, embedding_compat::vector_bytes(0, embedding_compat::DTYPE_FLOAT));
        $this->assertSame(0, embedding_compat::vector_bytes(-5, embedding_compat::DTYPE_INT8));
    }

    public function test_expected_element_count_accounts_for_bit_packing(): void {
        // The API returns one value per dimension for float and int8, but packs
        // eight dimensions into each value for binary. Getting this wrong is how
        // a width/dtype mismatch would reach storage unnoticed.
        $this->assertSame(1024, embedding_compat::expected_element_count(1024, embedding_compat::DTYPE_FLOAT));
        $this->assertSame(1024, embedding_compat::expected_element_count(1024, embedding_compat::DTYPE_INT8));
        $this->assertSame(128, embedding_compat::expected_element_count(1024, embedding_compat::DTYPE_BINARY));
        $this->assertSame(32, embedding_compat::expected_element_count(256, embedding_compat::DTYPE_BINARY));
    }

    public function test_expected_element_count_of_nothing_is_zero(): void {
        $this->assertSame(0, embedding_compat::expected_element_count(0, embedding_compat::DTYPE_BINARY));
    }

    public function test_declared_dtypes_all_have_a_size(): void {
        // Guards against adding a dtype to DTYPES and forgetting BYTES_PER_DIM,
        // which would make vector_bytes() throw on the new value.
        foreach (embedding_compat::DTYPES as $d) {
            $this->assertArrayHasKey($d, embedding_compat::BYTES_PER_DIM, "no size declared for dtype '{$d}'");
            $this->assertGreaterThan(0, embedding_compat::vector_bytes(8, $d));
        }
    }
}
