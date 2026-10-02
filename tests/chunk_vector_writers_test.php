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
 * Guards that every writer of a chunk vector writes BOTH storage columns.
 *
 * v6.9.5 stores chunk vectors twice: packed float32 in `embedding_bin`, which
 * retrieval prefers, and the original JSON in `embedding`, retained so the
 * release can be rolled back without a reindex. Retrieval falls back per row, so
 * a writer that sets only the JSON column produces rows that are CORRECT but
 * slow, with nothing at all to signal it.
 *
 * That is exactly what happened: `index_course()` wrote both, `index_module()`
 * wrote only JSON, and `index_module()` is the path the auto_reindex_rag_drifted
 * scheduled task uses. So a site that had been backfilled quietly drifted back
 * toward the slow decode every time a module's content changed.
 *
 * A behavioural test would need a real course module plus a live embedding
 * provider, so this is a source-level guard in the style of
 * settings_secret_masking_test and lang_completeness_test: cheap, and it fails
 * for the next writer who forgets the second column rather than only for this
 * one instance.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\content_indexer
 */
final class chunk_vector_writers_test extends \basic_testcase {
    /**
     * Source of the indexer.
     *
     * @return string
     */
    private function indexer_source(): string {
        global $CFG;
        $path = $CFG->dirroot . '/local/ai_course_assistant/classes/content_indexer.php';
        $this->assertFileExists($path);
        return file_get_contents($path);
    }

    public function test_every_chunk_write_sets_the_packed_vector(): void {
        $src = $this->indexer_source();

        // v7.6.2 (issue #266) inverted this test's original invariant, and the
        // concern behind it got STRONGER rather than weaker.
        //
        // It used to require that every writer set BOTH columns, because a
        // JSON-only row was correct but slow, with nothing to signal it. The
        // JSON copy is no longer written at all: it was about three times the
        // size of the binary it duplicated, nothing read it while the binary
        // was present, and it was rewritten on every index so the cost grew
        // back after each cleanup.
        //
        // Which means a writer that forgets embedding_bin no longer produces a
        // slow row. It produces a row with NO vector at all, invisible to
        // retrieval, and indistinguishable from an unindexed chunk. So the
        // guard now counts chunk writes against packed writes.
        // Only the paths that build a NEW $record. The third insert in this
        // file re-inserts $existing on the content-reuse path, carrying the
        // vector that row already had, so it correctly does not call
        // pack_vector(). Counting it would make this test demand a re-pack of
        // an unchanged vector.
        //
        // Single-quoted on purpose: in a double-quoted PHP string "\$record"
        // collapses to '$record', which the regex engine then reads as an
        // end-of-string anchor, and the pattern silently matches nothing.
        $chunkwrites = preg_match_all(
            '/insert_record\(\s*\x27local_ai_course_assistant_chunks\x27\s*,\s*\$record\s*\)/',
            $src);
        $packedwrites = preg_match_all(
            '/->embedding_bin\s*=\s*rag_retriever::pack_vector\(/', $src);

        $this->assertGreaterThan(0, $chunkwrites, 'scan pattern has drifted from the source');
        $this->assertSame(
            $chunkwrites,
            $packedwrites,
            "content_indexer inserts a chunk {$chunkwrites} time(s) but writes the packed "
            . "vector {$packedwrites} time(s). Since the JSON copy stopped being written, "
            . 'a chunk inserted without embedding_bin has no vector in either column: it '
            . 'is invisible to retrieval and looks exactly like an unindexed chunk.'
        );
    }

    public function test_no_writer_reintroduces_the_json_copy(): void {
        $src = $this->indexer_source();

        // Parse each assignment and compare the VALUE, rather than trying to
        // express "not null" inside the pattern. Both earlier attempts failed
        // against correct code: [^n] backtracks onto the space before "null",
        // and so does a negative lookahead after a greedy \s*.
        $offenders = [];
        if (preg_match_all('/->embedding\s*=\s*([^;]+);/', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (trim($hit[1]) !== 'null') {
                    $offenders[] = trim($hit[0]);
                }
            }
        }

        $this->assertSame([], $offenders,
            'content_indexer assigns something other than null to the JSON embedding '
                . 'column: ' . implode(', ', $offenders) . '. That copy is roughly three '
                . 'times the size of embedding_bin, '
                . 'nothing reads it while the binary is present, and it is rewritten on '
                . 'every index, so the storage grows back after any cleanup. See #266.');
    }

    public function test_every_packed_write_records_the_encoding_and_passes_it_to_pack(): void {
        $src = $this->indexer_source();

        $packedwrites = preg_match_all('/->embedding_bin\s*=\s*rag_retriever::pack_vector\(/', $src);
        $this->assertGreaterThan(0, $packedwrites, 'scan pattern has drifted from the source');

        // Every packed write must pass the dtype. pack_vector() defaults to
        // float, so omitting the argument would silently write float32 bytes
        // while embed_dtype claimed otherwise — a row that decodes to garbage.
        $dtypepassed = preg_match_all(
            '/->embedding_bin\s*=\s*rag_retriever::pack_vector\(\s*\$vector\s*,\s*\$dtype\s*\)/',
            $src
        );
        $this->assertSame(
            $packedwrites,
            $dtypepassed,
            "content_indexer packs a vector {$packedwrites} time(s) but passes the dtype only "
            . "{$dtypepassed} time(s). pack_vector() defaults to float, so a missing dtype "
            . 'writes float bytes under whatever label embed_dtype carries.'
        );

        // And must record it, or retrieval cannot know how to decode the bytes.
        $dtyperecorded = preg_match_all('/->embed_dtype\s*=\s*\$dtype\s*;/', $src);
        $this->assertSame(
            $packedwrites,
            $dtyperecorded,
            "content_indexer packs a vector {$packedwrites} time(s) but records embed_dtype only "
            . "{$dtyperecorded} time(s). An unrecorded encoding is read as float."
        );
    }

    public function test_the_dtype_comes_from_the_provider_not_from_config(): void {
        $src = $this->indexer_source();

        // effective_dtype() downgrades to float when the provider cannot
        // actually return quantized vectors. Reading embed_dtype from config
        // directly would let a provider that ignores output_dtype write float
        // bytes labelled int8 — unscoreable rows, with no error.
        $this->assertMatchesRegularExpression(
            '/\$dtype\s*=\s*\$provider->effective_dtype\(\)/',
            $src,
            'the indexer must take its dtype from the provider, not from config'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/\\\$dtype\s*=\s*get_config\(/",
            $src,
            'the indexer must not read embed_dtype from config directly'
        );
    }

    public function test_both_known_index_paths_write_the_packed_column(): void {
        $src = $this->indexer_source();

        // Split at index_module so each path can be asserted independently: a
        // count-only check would pass if one path wrote both columns twice.
        $split = strpos($src, 'function index_module');
        $this->assertNotFalse($split, 'index_module not found; this guard needs updating');

        foreach (
            ['index_course (before index_module)' => substr($src, 0, $split),
                  'index_module (and after)' => substr($src, $split)] as $label => $part
        ) {
            $this->assertMatchesRegularExpression(
                '/->embedding_bin\s*=\s*rag_retriever::pack_vector\(/',
                $part,
                "the {$label} path does not write embedding_bin"
            );
        }
    }

    public function test_the_packed_column_exists_in_the_schema(): void {
        global $CFG;
        $xml = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/db/install.xml');
        // Pins the column name the writers use against the schema, so a rename in
        // one place cannot pass this file silently.
        $this->assertStringContainsString('NAME="embedding_bin"', $xml);
        $this->assertStringContainsString('NAME="embedding"', $xml);
        // v7.0.3: the encoding label lives alongside the bytes it describes.
        $this->assertStringContainsString('NAME="embed_dtype"', $xml);
    }
}
