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
 * The JSON duplicate of every embedding is no longer written (issue #266).
 *
 * Float indexes used to store each vector twice: packed into embedding_bin,
 * and again as a JSON array in embedding. The JSON copy was roughly three times
 * the size of the binary it duplicated, about 25 KB against 8 KB for a
 * 2048-dimension vector, and retrieval never read it while the binary was
 * present. Across the five dev sites it accounted for 443 MB of 463.
 *
 * It was deliberate and temporary, to keep a rollback possible without a
 * reindex, and the comment in content_indexer said a later release would drop
 * it. That release did not arrive, so the cost became permanent and grew back
 * after every cleanup, because the column was rewritten on each index.
 *
 * Two halves, and the second matters as much as the first. The writers stop
 * producing new duplicates, and the reader keeps its fallback, because on two
 * of five dev sites 1,054 rows held the JSON copy and NO binary. For those rows
 * the JSON is the vector, not a duplicate, and removing the fallback would
 * silently degrade retrieval until someone noticed.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class embedding_json_copy_test extends \basic_testcase {

    /** Files that write a chunk row. */
    private const WRITERS = [
        'classes/content_indexer.php',
        'classes/faq_manager.php',
    ];

    /**
     * Nothing encodes a vector to JSON for storage any more.
     */
    public function test_no_writer_stores_a_json_copy_of_the_vector(): void {
        $offenders = [];

        foreach (self::WRITERS as $file) {
            $src = file_get_contents(__DIR__ . '/../' . $file);
            $this->assertNotFalse($src, $file . ' must be readable');

            if (preg_match_all('/->embedding\s*=\s*([^;]+);/', $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $value = trim($hit[1]);
                    if ($value !== 'null') {
                        $offenders[] = $file . ': $record->embedding = ' . $value;
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            "These writers still store a JSON copy of the embedding:\n  "
                . implode("\n  ", $offenders)
                . "\nIt is about three times the size of embedding_bin, nothing reads it "
                . "while the binary is present, and it is rewritten on every index, so "
                . "the storage grows back after any cleanup. See issue #266.");
    }

    /**
     * The reader keeps its fallback for rows that have only the JSON.
     *
     * This is the half that protects data. A site that indexed before the
     * binary column existed, or whose binary write failed, has rows where the
     * JSON IS the vector. Removing the fallback would not throw; retrieval
     * would just quietly skip those chunks.
     */
    public function test_the_retriever_still_falls_back_to_an_existing_json_vector(): void {
        $src = file_get_contents(__DIR__ . '/../classes/rag_retriever.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString('decode_vector(null, $row->embedding', $src,
            'rag_retriever no longer falls back to the JSON column. Rows that hold the '
                . 'JSON and no binary still exist on real sites, and for those the JSON '
                . 'is the only copy of the vector. Dropping the fallback silently '
                . 'degrades retrieval rather than failing loudly.');
    }

    /**
     * The staleness predicates accept a row that has only the binary.
     *
     * If any of them required the JSON column, a freshly indexed chunk would
     * look unindexed and be re-embedded on every run, which costs money.
     */
    public function test_nothing_treats_a_binary_only_row_as_unindexed(): void {
        $root = __DIR__ . '/..';
        $files = array_merge(
            glob($root . '/classes/*.php') ?: [],
            glob($root . '/admin/cli/*.php') ?: [],
            [$root . '/rag_admin.php']
        );

        $bad = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $src = file_get_contents($file);
            // A predicate naming the JSON column must also accept the binary,
            // except in the backfill tool, whose whole job is finding JSON rows.
            if (basename($file) === 'backfill_embedding_bin.php') {
                continue;
            }
            // The alias matters: real predicates are written 'ch.embedding IS NOT
            // NULL OR ch.embedding_bin IS NOT NULL'. A pattern that did not allow
            // the prefix flagged rag_admin.php, which is correct, so the test
            // would have cried wolf on day one.
            $pattern = '/(?:\w+\.)?embedding IS NOT NULL'
                . '(?!\s+OR\s+(?:\w+\.)?embedding_bin IS NOT NULL)/';
            if (preg_match_all($pattern, $src, $m)) {
                $bad[] = basename($file) . ' (' . count($m[0]) . ')';
            }
        }

        $this->assertSame([], $bad,
            "These treat a row with only embedding_bin as unindexed, so every freshly "
                . "indexed chunk would be re-embedded on the next run at real cost:\n  "
                . implode("\n  ", $bad));
    }
}
