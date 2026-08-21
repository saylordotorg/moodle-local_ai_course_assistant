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
 * Tests for packing, decoding and scoring quantized embedding vectors.
 *
 * The properties under test are all about not silently misreading bytes. A
 * vector that decodes to the wrong length, or is compared against a vector of a
 * different encoding, produces a number rather than an error — so these
 * assertions are the only thing standing between a storage change and an index
 * that ranks confidently and wrongly.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\rag_retriever
 */
final class rag_quantization_test extends \basic_testcase {

    // ---------- float: unchanged behaviour ----------

    public function test_float_roundtrip_is_lossless_for_float32_values(): void {
        // 0.5 and 0.25 are exact in binary floating point, so this is an
        // equality test rather than a tolerance test.
        $vec = [0.5, -0.25, 0.125, -1.0, 0.0];
        $packed = rag_retriever::pack_vector($vec, embedding_compat::DTYPE_FLOAT);
        $out = rag_retriever::decode_vector($packed, null, embedding_compat::DTYPE_FLOAT);
        $this->assertSame($vec, array_map('floatval', $out));
    }

    public function test_float_is_the_default_dtype_for_pack_and_decode(): void {
        // Callers that predate quantization must keep working untouched.
        $vec = [0.5, -0.25];
        $this->assertSame(
            rag_retriever::pack_vector($vec, embedding_compat::DTYPE_FLOAT),
            rag_retriever::pack_vector($vec)
        );
        $packed = rag_retriever::pack_vector($vec);
        $this->assertCount(2, rag_retriever::decode_vector($packed, null));
    }

    // The truncated-float-blob fallback is deliberately NOT retested here:
    // rag_retriever_hydrate_test already covers it, and it emits a
    // debugging() warning guarded by a per-process static, so a second copy
    // would be order-dependent (whichever test runs first consumes the one
    // warning) as well as redundant.

    // ---------- int8 ----------

    public function test_int8_roundtrip_preserves_every_value_in_range(): void {
        $vec = [0, 1, -1, 127, -128, 57, -53];
        $packed = rag_retriever::pack_vector($vec, embedding_compat::DTYPE_INT8);
        $this->assertSame(count($vec), strlen($packed), 'int8 must use exactly one byte per dimension');
        $out = rag_retriever::decode_vector($packed, null, embedding_compat::DTYPE_INT8);
        $this->assertSame(array_map('floatval', $vec), array_map('floatval', $out));
    }

    public function test_int8_clamps_rather_than_wrapping(): void {
        // This is the red-team case. pack('c', 130) wraps to -126, which would
        // invert that dimension's contribution to every score. Clamping keeps
        // the sign, which is the information that matters most.
        $packed = rag_retriever::pack_vector([130, -200, 1000, -1000], embedding_compat::DTYPE_INT8);
        $out = rag_retriever::decode_vector($packed, null, embedding_compat::DTYPE_INT8);
        $this->assertSame([127.0, -128.0, 127.0, -128.0], array_map('floatval', $out));
    }

    public function test_int8_cosine_ranks_the_same_as_float_cosine(): void {
        // Cosine is scale-invariant, which is why an int8 document vector can be
        // scored against a full-precision query without dequantizing. If this
        // ever stops holding, quantization silently degrades ranking.
        $a = [10, 0, 0];
        $b = [0, 10, 0];
        $query = [1.0, 0.1, 0.0];

        $sa = self::cosine($query, rag_retriever::decode_vector(
            rag_retriever::pack_vector($a, embedding_compat::DTYPE_INT8), null, embedding_compat::DTYPE_INT8));
        $sb = self::cosine($query, rag_retriever::decode_vector(
            rag_retriever::pack_vector($b, embedding_compat::DTYPE_INT8), null, embedding_compat::DTYPE_INT8));

        $this->assertGreaterThan($sb, $sa, 'int8 scoring must preserve the float ordering');
    }

    // ---------- binary ----------

    public function test_binary_normalizes_signed_and_unsigned_to_the_same_bytes(): void {
        // The API may hand back -124 or 132 for the same byte. Both describe
        // identical bits, so both must store identically — otherwise the same
        // document embedded twice would compare as different.
        $signed = rag_retriever::pack_vector([-124, 32, -120], embedding_compat::DTYPE_BINARY);
        $unsigned = rag_retriever::pack_vector([132, 32, 136], embedding_compat::DTYPE_BINARY);
        $this->assertSame(bin2hex($signed), bin2hex($unsigned));
    }

    public function test_binary_uses_one_byte_per_returned_value(): void {
        $packed = rag_retriever::pack_vector(array_fill(0, 128, 255), embedding_compat::DTYPE_BINARY);
        $this->assertSame(128, strlen($packed));
    }

    public function test_binary_decode_returns_empty_because_scoring_uses_the_packed_form(): void {
        // Expanding 128 bytes into 1024 floats would discard both the memory and
        // the speed advantage of the encoding. decode_vector() deliberately
        // declines, and the retriever reads the blob directly.
        $packed = rag_retriever::pack_vector([1, 2, 3], embedding_compat::DTYPE_BINARY);
        $this->assertSame([], rag_retriever::decode_vector($packed, null, embedding_compat::DTYPE_BINARY));
    }

    public function test_identical_binary_vectors_score_one(): void {
        $a = rag_retriever::pack_vector([0b10101010, 0b11110000], embedding_compat::DTYPE_BINARY);
        $this->assertSame(1.0, rag_retriever::binary_similarity($a, $a));
    }

    public function test_bitwise_opposite_binary_vectors_score_minus_one(): void {
        // On the cosine scale, every bit differing is -1, not 0. This is the
        // regression that matters: under the old [0,1] rescaling this returned
        // 0.0 and two unrelated vectors returned 0.5, which cleared the default
        // 0.25 relevance floor and disabled filtering entirely.
        $a = rag_retriever::pack_vector([0b11111111, 0b00000000], embedding_compat::DTYPE_BINARY);
        $b = rag_retriever::pack_vector([0b00000000, 0b11111111], embedding_compat::DTYPE_BINARY);
        $this->assertSame(-1.0, rag_retriever::binary_similarity($a, $b));
    }

    public function test_half_differing_bits_score_zero_like_an_unrelated_cosine(): void {
        // Half the bits differing is what two unrelated vectors look like, and
        // it must score 0 so rag_min_similarity rejects it.
        $a = rag_retriever::pack_vector([0b11111111, 0b11111111], embedding_compat::DTYPE_BINARY);
        $b = rag_retriever::pack_vector([0b11111111, 0b00000000], embedding_compat::DTYPE_BINARY);
        $this->assertSame(0.0, rag_retriever::binary_similarity($a, $b));
    }

    public function test_binary_similarity_is_bounded(): void {
        // Scores are compared against rag_min_similarity, which is calibrated
        // for cosine's [0,1] working range. A score outside that would make the
        // floor meaningless.
        for ($i = 0; $i < 64; $i++) {
            $a = rag_retriever::pack_vector([$i * 3 % 256, $i * 7 % 256], embedding_compat::DTYPE_BINARY);
            $b = rag_retriever::pack_vector([$i * 11 % 256, $i * 13 % 256], embedding_compat::DTYPE_BINARY);
            $s = rag_retriever::binary_similarity($a, $b);
            $this->assertGreaterThanOrEqual(-1.0, $s);
            $this->assertLessThanOrEqual(1.0, $s);
        }
    }

    public function test_binary_similarity_of_mismatched_lengths_is_zero(): void {
        // Red team: two vectors of different widths are not from the same space.
        // Comparing the overlap would produce a real-looking score for an
        // impossible comparison, so this must refuse rather than truncate.
        $a = rag_retriever::pack_vector([255, 255], embedding_compat::DTYPE_BINARY);
        $b = rag_retriever::pack_vector([255], embedding_compat::DTYPE_BINARY);
        $this->assertSame(0.0, rag_retriever::binary_similarity($a, $b));
        $this->assertSame(0.0, rag_retriever::binary_similarity($b, $a));
    }

    public function test_binary_similarity_of_empty_input_is_zero(): void {
        $this->assertSame(0.0, rag_retriever::binary_similarity('', ''));
        $this->assertSame(0.0, rag_retriever::binary_similarity('', "\xFF"));
    }

    public function test_binary_similarity_is_symmetric(): void {
        $a = rag_retriever::pack_vector([0b10110011, 0b01001101], embedding_compat::DTYPE_BINARY);
        $b = rag_retriever::pack_vector([0b11010010, 0b00101011], embedding_compat::DTYPE_BINARY);
        $this->assertSame(
            rag_retriever::binary_similarity($a, $b),
            rag_retriever::binary_similarity($b, $a)
        );
    }

    public function test_binary_similarity_counts_every_bit_position(): void {
        // Exercises each of the eight bit positions in isolation, so a
        // popcount table or shift error cannot hide behind an aggregate.
        for ($bit = 0; $bit < 8; $bit++) {
            $a = rag_retriever::pack_vector([0], embedding_compat::DTYPE_BINARY);
            $b = rag_retriever::pack_vector([1 << $bit], embedding_compat::DTYPE_BINARY);
            $this->assertSame(
                1.0 - (2.0 / 8),
                rag_retriever::binary_similarity($a, $b),
                "bit position {$bit} was not counted"
            );
        }
    }


    public function test_binary_scores_are_on_the_same_scale_as_cosine(): void {
        // The relevance floor (rag_min_similarity, default 0.25) is shared by
        // every encoding, so binary must put unrelated vectors near 0 and
        // identical ones near 1 — exactly where cosine puts them. Verified on
        // live vectors: a relevant document scored 0.318 and two irrelevant ones
        // 0.045 and 0.012, against float cosine's 0.499 / 0.062 / 0.019.
        $identical = rag_retriever::pack_vector([0b10110011, 0b01001101], embedding_compat::DTYPE_BINARY);
        $this->assertSame(1.0, rag_retriever::binary_similarity($identical, $identical));

        // Unrelated: half the bits differ.
        $a = rag_retriever::pack_vector([0b11111111, 0b00000000], embedding_compat::DTYPE_BINARY);
        $b = rag_retriever::pack_vector([0b11111111, 0b11111111], embedding_compat::DTYPE_BINARY);
        $unrelated = rag_retriever::binary_similarity($a, $b);
        $this->assertSame(0.0, $unrelated);
        $this->assertLessThan(
            0.25,
            $unrelated,
            'an unrelated pair must fall below the default relevance floor'
        );
    }

    // ---------- red team: an unknown dtype must never widen behaviour ----------

    public function test_unknown_dtype_is_treated_as_float_on_both_sides(): void {
        $vec = [0.5, -0.5];
        $packed = rag_retriever::pack_vector($vec, 'nonsense');
        $this->assertSame(rag_retriever::pack_vector($vec, embedding_compat::DTYPE_FLOAT), $packed);
        $out = rag_retriever::decode_vector($packed, null, 'nonsense');
        $this->assertSame([0.5, -0.5], array_map('floatval', $out));
    }

    public function test_empty_vector_packs_to_empty_string_for_every_dtype(): void {
        // Guards against pack('c*') with no arguments, which is a TypeError.
        foreach (embedding_compat::DTYPES as $d) {
            $this->assertSame('', rag_retriever::pack_vector([], $d), "dtype {$d} failed on an empty vector");
        }
    }


    // ---------- the two refusal rules that protect retrieval ----------

    public function test_matching_model_and_encoding_is_scoreable(): void {
        $this->assertSame('ok', rag_retriever::classify_row(
            'voyage-4-lite', embedding_compat::DTYPE_FLOAT, 'voyage-4-lite', embedding_compat::DTYPE_FLOAT));
    }

    public function test_shared_space_models_are_scoreable_across_the_family(): void {
        // This is the whole point of the separate query-model setting.
        $this->assertSame('ok', rag_retriever::classify_row(
            'voyage-4-lite', embedding_compat::DTYPE_FLOAT, 'voyage-4-large', embedding_compat::DTYPE_FLOAT));
    }

    public function test_incomparable_models_are_refused(): void {
        // The incident this prevents: a query model changed without reindexing,
        // producing confident cosine scores against vectors from another space.
        $this->assertSame('model', rag_retriever::classify_row(
            'voyage-4-lite', embedding_compat::DTYPE_FLOAT, 'text-embedding-3-small', embedding_compat::DTYPE_FLOAT));
        $this->assertSame('model', rag_retriever::classify_row(
            'voyage-4', embedding_compat::DTYPE_FLOAT, 'voyage-3.5', embedding_compat::DTYPE_FLOAT));
    }

    public function test_rows_with_no_recorded_model_are_kept(): void {
        // Legacy rows predate the column. Refusing them would silently empty
        // every index built before this release.
        foreach ([null, '', '   '] as $legacy) {
            $this->assertSame('ok', rag_retriever::classify_row(
                'voyage-4-lite', embedding_compat::DTYPE_FLOAT, $legacy, null));
        }
    }

    public function test_binary_rows_are_refused_by_a_float_query(): void {
        // A 128-byte bit string cannot be dotted against a 1024-float query.
        $this->assertSame('dtype', rag_retriever::classify_row(
            'voyage-4-lite', embedding_compat::DTYPE_FLOAT, 'voyage-4-lite', embedding_compat::DTYPE_BINARY));
    }

    public function test_float_rows_are_refused_by_a_binary_query(): void {
        $this->assertSame('dtype', rag_retriever::classify_row(
            'voyage-4-lite', embedding_compat::DTYPE_BINARY, 'voyage-4-lite', embedding_compat::DTYPE_FLOAT));
    }

    public function test_int8_rows_are_scoreable_by_a_float_query(): void {
        // Deliberately allowed, and the reason quantization is worth having:
        // cosine is scale-invariant, so a full-precision query scores int8
        // documents correctly. Only binary needs matched encodings.
        $this->assertSame('ok', rag_retriever::classify_row(
            'voyage-4-lite', embedding_compat::DTYPE_FLOAT, 'voyage-4-lite', embedding_compat::DTYPE_INT8));
        $this->assertSame('ok', rag_retriever::classify_row(
            'voyage-4-lite', embedding_compat::DTYPE_INT8, 'voyage-4-lite', embedding_compat::DTYPE_FLOAT));
    }

    public function test_legacy_null_dtype_is_treated_as_float(): void {
        // Rows written before embed_dtype existed hold float32 bytes.
        $this->assertSame('ok', rag_retriever::classify_row(
            'voyage-4-lite', embedding_compat::DTYPE_FLOAT, 'voyage-4-lite', null));
        $this->assertSame('dtype', rag_retriever::classify_row(
            'voyage-4-lite', embedding_compat::DTYPE_BINARY, 'voyage-4-lite', null));
    }

    public function test_model_refusal_takes_precedence_over_encoding(): void {
        // Both wrong: report the model, because reindexing fixes both and the
        // model is the cause an administrator can act on.
        $this->assertSame('model', rag_retriever::classify_row(
            'voyage-4-lite', embedding_compat::DTYPE_FLOAT, 'text-embedding-3-small', embedding_compat::DTYPE_BINARY));
    }

    public function test_garbage_dtype_on_a_row_is_read_as_float(): void {
        // Red team: a corrupted or hand-edited column must not select a decode
        // path that does not match the bytes.
        $this->assertSame('ok', rag_retriever::classify_row(
            'voyage-4-lite', embedding_compat::DTYPE_FLOAT, 'voyage-4-lite', 'int4; DROP TABLE'));
    }

    /**
     * Local cosine, so the test does not depend on a private method.
     *
     * @param array $a
     * @param array $b
     * @return float
     */
    private static function cosine(array $a, array $b): float {
        $dot = $na = $nb = 0.0;
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot += (float) $a[$i] * (float) $b[$i];
            $na += (float) $a[$i] ** 2;
            $nb += (float) $b[$i] ** 2;
        }
        if ($na == 0.0 || $nb == 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }
}
