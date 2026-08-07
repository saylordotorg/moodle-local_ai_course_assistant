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

    public function test_every_json_vector_write_is_paired_with_a_packed_write(): void {
        $src = $this->indexer_source();

        // Assignments of the JSON column from an encoded vector. Excludes the
        // reuse path, which copies an existing row rather than encoding a vector.
        $jsonwrites = preg_match_all('/->embedding\s*=\s*json_encode\(/', $src);
        $packedwrites = preg_match_all('/->embedding_bin\s*=\s*rag_retriever::pack_vector\(/', $src);

        $this->assertGreaterThan(0, $jsonwrites, 'scan pattern has drifted from the source');
        $this->assertSame($jsonwrites, $packedwrites,
            "content_indexer writes the JSON vector {$jsonwrites} time(s) but the packed "
            . "vector {$packedwrites} time(s). Every writer must set both columns until the "
            . 'JSON column is dropped; a JSON-only row is correct but silently slow.');
    }

    public function test_both_known_index_paths_write_the_packed_column(): void {
        $src = $this->indexer_source();

        // Split at index_module so each path can be asserted independently: a
        // count-only check would pass if one path wrote both columns twice.
        $split = strpos($src, 'function index_module');
        $this->assertNotFalse($split, 'index_module not found; this guard needs updating');

        foreach (['index_course (before index_module)' => substr($src, 0, $split),
                  'index_module (and after)' => substr($src, $split)] as $label => $part) {
            $this->assertMatchesRegularExpression(
                '/->embedding_bin\s*=\s*rag_retriever::pack_vector\(/', $part,
                "the {$label} path does not write embedding_bin");
        }
    }

    public function test_the_packed_column_exists_in_the_schema(): void {
        global $CFG;
        $xml = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/db/install.xml');
        // Pins the column name the writers use against the schema, so a rename in
        // one place cannot pass this file silently.
        $this->assertStringContainsString('NAME="embedding_bin"', $xml);
        $this->assertStringContainsString('NAME="embedding"', $xml);
    }
}
