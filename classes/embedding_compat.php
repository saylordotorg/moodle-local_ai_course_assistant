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
 * Which embedding models may be mixed, and how vectors are encoded.
 *
 * Two vectors are only comparable if they come from the same embedding space.
 * Comparing across spaces does not fail loudly — it returns plausible-looking
 * cosine scores that are meaningless, which is exactly the failure mode that
 * once made a dev site look like retrieval was broken (1024-dimension queries
 * scored against 1536-dimension stored vectors produced a ~0.03 mean cosine and
 * 0% recall). Every mixing decision therefore goes through this class rather
 * than being inferred at the call site.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class embedding_compat {
    /** Stored-vector encodings we can write and read. */
    public const DTYPE_FLOAT  = 'float';
    /** Signed 8-bit quantization: one byte per dimension. */
    public const DTYPE_INT8   = 'int8';
    /** Bit-packed: one bit per dimension, eight dimensions per byte. */
    public const DTYPE_BINARY = 'binary';

    /**
     * Every dtype this plugin can store and score.
     *
     * Voyage also offers `uint8` and `ubinary`, which carry the same
     * information as `int8`/`binary` with a +128 offset. They are deliberately
     * not offered: a second encoding of an identical vector is one more branch
     * in the scoring path and one more way for a stored blob to be misread,
     * with nothing gained.
     */
    public const DTYPES = [self::DTYPE_FLOAT, self::DTYPE_INT8, self::DTYPE_BINARY];

    /**
     * Bytes each dtype occupies per dimension. Binary is a fraction because
     * eight dimensions share a byte.
     */
    public const BYTES_PER_DIM = [
        self::DTYPE_FLOAT  => 4.0,
        self::DTYPE_INT8   => 1.0,
        self::DTYPE_BINARY => 0.125,
    ];

    /**
     * Model-name prefixes that share one embedding space.
     *
     * Voyage's 4 series is the first family to guarantee this: their launch
     * announcement states that all four models produce interchangeable
     * embeddings, so documents indexed with one may be queried with another.
     * That is what lets the query model be upgraded without re-embedding a
     * corpus.
     *
     * Members are matched by prefix so unreleased siblings (voyage-4-nano, or a
     * future voyage-4-xl) are covered on the day they ship, without a code
     * change. `voyage-context-4` is deliberately NOT in this family: it is a
     * contextualized chunk model whose vectors are produced by a different
     * pipeline, and Voyage does not claim interchangeability with the plain 4
     * series.
     *
     * NOTE ON TRUST: this encodes a vendor claim, not something measured here.
     * Benchmarking on the SOLA corpus (2026-08-21) found asymmetric pairing was
     * a wash for voyage-4-lite and 1.0 pp WORSE for voyage-4 than querying with
     * the same model that indexed. Mixing is therefore permitted but is not
     * recommended on quality grounds; its real value is that it decouples a
     * query-model change from a full reindex.
     */
    private const SHARED_SPACES = [
        'voyage-4' => ['voyage-4'],
    ];

    /**
     * Normalize a dtype string to something we can store.
     *
     * Anything unrecognized — including an empty value from a row written
     * before the column existed — resolves to float, which is what those rows
     * actually contain.
     *
     * @param string|null $dtype
     * @return string One of self::DTYPES.
     */
    public static function normalize_dtype(?string $dtype): string {
        $d = strtolower(trim((string) $dtype));
        return in_array($d, self::DTYPES, true) ? $d : self::DTYPE_FLOAT;
    }

    /**
     * Resolve the family key a model belongs to, or null if it is in no
     * declared shared space.
     *
     * @param string $model
     * @return string|null
     */
    public static function family(string $model): ?string {
        $m = strtolower(trim($model));
        if ($m === '') {
            return null;
        }
        foreach (self::SHARED_SPACES as $key => $prefixes) {
            foreach ($prefixes as $prefix) {
                // Guard against a shorter family name swallowing a longer,
                // unrelated one: 'voyage-4' must not claim 'voyage-40-foo'.
                // Require the prefix to be followed by a separator or nothing.
                if ($m === $prefix || strpos($m, $prefix . '-') === 0) {
                    return $key;
                }
            }
        }
        return null;
    }

    /**
     * May a query embedded with one model be scored against documents embedded
     * with another?
     *
     * True when the models are identical, or when both belong to the same
     * declared shared embedding space. False otherwise — including when either
     * name is empty, because an unknown provenance is not a safe basis for
     * mixing.
     *
     * @param string $querymodel Model that produced the query vector.
     * @param string $docmodel Model recorded against the stored chunk.
     * @return bool
     */
    public static function are_comparable(string $querymodel, string $docmodel): bool {
        $q = strtolower(trim($querymodel));
        $d = strtolower(trim($docmodel));
        if ($q === '' || $d === '') {
            return false;
        }
        if ($q === $d) {
            return true;
        }
        $qf = self::family($q);
        return $qf !== null && $qf === self::family($d);
    }

    /**
     * Estimated bytes to store one vector at the given width and dtype.
     *
     * Used by the RAG admin page to show what a re-index would cost in storage
     * before an administrator commits to one.
     *
     * @param int $dimensions
     * @param string $dtype
     * @return int Bytes, rounded up.
     */
    public static function vector_bytes(int $dimensions, string $dtype): int {
        if ($dimensions <= 0) {
            return 0;
        }
        $per = self::BYTES_PER_DIM[self::normalize_dtype($dtype)];
        return (int) ceil($dimensions * $per);
    }

    /**
     * How many values the API returns for a vector of this width at this dtype.
     *
     * Float and int8 return one value per dimension; binary packs eight
     * dimensions into each returned byte. Used to validate a response before it
     * is stored, so a width/dtype mismatch is caught at write time rather than
     * producing silently unscoreable rows.
     *
     * @param int $dimensions Logical vector width.
     * @param string $dtype
     * @return int Expected element count in the API response.
     */
    public static function expected_element_count(int $dimensions, string $dtype): int {
        if ($dimensions <= 0) {
            return 0;
        }
        if (self::normalize_dtype($dtype) === self::DTYPE_BINARY) {
            return (int) ceil($dimensions / 8);
        }
        return $dimensions;
    }
}
