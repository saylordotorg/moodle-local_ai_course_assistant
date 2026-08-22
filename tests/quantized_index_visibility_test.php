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
 * Guards that a quantized index is visible to the code that gates on it.
 *
 * v7.0.3 added int8/binary vector storage. A quantized row stores its vector in
 * `embedding_bin` and leaves the JSON `embedding` column NULL. Retrieval was
 * taught to read either column, but three predicates in front of it were not,
 * and each failure was silent:
 *
 *  - `is_course_indexed()` returned false forever on a quantized index, so both
 *    chat paths re-extracted and re-chunked the entire course before every
 *    reply. It embedded nothing and reported "indexed 0, skipped N", so the only
 *    symptom was latency and load — one authenticated message became a
 *    pdftotext subprocess per PDF plus a full chunk-table scan.
 *  - `rag_admin.php` reported 0 embedded chunks, which in turn gated off the
 *    v7.0.3 storage projection — the feature never rendered on the quantized
 *    indexes it exists to size.
 *  - Two dev CLI tools saw an empty corpus.
 *
 * None of it fired on a float index, which is why it shipped.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\content_indexer::is_course_indexed
 */
final class quantized_index_visibility_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Insert one chunk row.
     *
     * @param int $courseid
     * @param string|null $json Value for the JSON `embedding` column.
     * @param string|null $bin Value for the packed `embedding_bin` column.
     * @param string $model
     * @param string|null $dtype
     * @return int
     */
    private function make_chunk(int $courseid, ?string $json, ?string $bin,
                                string $model = 'voyage-4-large', ?string $dtype = null): int {
        global $DB;
        return (int) $DB->insert_record('local_ai_course_assistant_chunks', (object) [
            'courseid'      => $courseid,
            'cmid'          => 1,
            'modtype'       => 'page',
            'chunkindex'    => 0,
            'content'       => 'Opportunity cost is the value of the next-best alternative forgone.',
            'contenthash'   => sha1('chunk-' . $courseid . '-' . ($dtype ?? 'float')),
            'embedding'     => $json,
            'embedding_bin' => $bin,
            'embed_model'   => $model,
            'embed_dtype'   => $dtype,
            'timecreated'   => time(),
            'timeindexed'   => time(),
        ]);
    }

    public function test_a_float_index_is_visible(): void {
        $this->make_chunk(101, json_encode([0.1, 0.2]), null, 'text-embedding-3-small', 'float');
        $this->assertTrue(content_indexer::is_course_indexed(101));
    }

    public function test_an_int8_index_is_visible(): void {
        // The regression. Vector lives only in embedding_bin.
        $this->make_chunk(102, null, pack('C*', 1, 2, 3, 4), 'voyage-4-large', 'int8');
        $this->assertTrue(
            content_indexer::is_course_indexed(102),
            'an int8 index must not look unindexed — that makes every chat turn reindex the course'
        );
    }

    public function test_a_binary_index_is_visible(): void {
        $this->make_chunk(103, null, pack('C*', 0xFF, 0x00), 'voyage-4-large', 'binary');
        $this->assertTrue(content_indexer::is_course_indexed(103));
    }

    public function test_a_dual_written_row_is_visible(): void {
        // What a float reindex actually writes since v6.9.5: both columns.
        $this->make_chunk(104, json_encode([0.5]), pack('g*', 0.5), 'voyage-4-large', 'float');
        $this->assertTrue(content_indexer::is_course_indexed(104));
    }

    public function test_a_chunk_with_no_vector_at_all_is_not_indexed(): void {
        // Extracted and chunked but never embedded — genuinely not indexed.
        $this->make_chunk(105, null, null, 'voyage-4-large', 'int8');
        $this->assertFalse(content_indexer::is_course_indexed(105));
    }

    public function test_an_empty_course_is_not_indexed(): void {
        $this->assertFalse(content_indexer::is_course_indexed(106));
    }

    public function test_visibility_does_not_leak_across_courses(): void {
        $this->make_chunk(107, null, pack('C*', 9), 'voyage-4-large', 'int8');
        $this->assertTrue(content_indexer::is_course_indexed(107));
        $this->assertFalse(content_indexer::is_course_indexed(108));
    }

    // ---------- source-level guards, so the next writer of a predicate is caught ----------

    /**
     * Every PHP file that queries the chunks table.
     *
     * @return array<string, string> relative path => source
     */
    private function sources(): array {
        global $CFG;
        $base = $CFG->dirroot . '/local/ai_course_assistant';
        $out = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($rii as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }
            $rel = ltrim(str_replace($base, '', $file->getPathname()), '/');
            if (strpos($rel, 'tests/') === 0) {
                continue;
            }
            $out[$rel] = file_get_contents($file->getPathname());
        }
        return $out;
    }

    public function test_no_predicate_tests_the_json_column_alone(): void {
        // backfill_embedding_bin.php is the one legitimate exception: it packs
        // the float32 vector held in `embedding` into `embedding_bin`, so a row
        // with no JSON has nothing for it to read.
        $allowed = ['admin/cli/backfill_embedding_bin.php'];
        $offenders = [];
        foreach ($this->sources() as $rel => $src) {
            if (in_array($rel, $allowed, true)) {
                continue;
            }
            // Match "embedding IS NOT NULL" only where it is NOT paired with the
            // packed column in the same predicate.
            foreach (preg_split('/\R/', $src) as $i => $line) {
                if (stripos($line, 'embedding IS NOT NULL') === false) {
                    continue;
                }
                if (stripos($line, 'embedding_bin IS NOT NULL') !== false) {
                    continue;
                }
                // Allow a multi-line predicate that continues onto the next line.
                $next = preg_split('/\R/', $src)[$i + 1] ?? '';
                if (stripos($next, 'embedding_bin IS NOT NULL') !== false) {
                    continue;
                }
                $offenders[] = $rel . ':' . ($i + 1);
            }
        }
        $this->assertSame([], $offenders,
            'these predicates see a quantized index as empty: ' . implode(', ', $offenders));
    }

    public function test_the_hash_skip_also_compares_the_encoding(): void {
        // Content hash covers the text, not the model or dtype that vectorized
        // it. Without an encoding comparison, "change embed_dtype then reindex"
        // — the documented way to adopt quantization — silently does nothing.
        $src = $this->sources()['classes/content_indexer.php'] ?? '';
        $this->assertNotSame('', $src);

        $this->assertMatchesRegularExpression(
            '/get_record\(\s*\'local_ai_course_assistant_chunks\'.*?embed_model,\s*embed_dtype\'/s',
            $src,
            'the hash-skip lookup must select embed_model and embed_dtype to compare them'
        );
        $this->assertMatchesRegularExpression(
            '/embed_model\s*===\s*\(string\)\s*\$modelname/',
            $src,
            'the hash-skip must re-embed when the stored model differs from this run'
        );
        $this->assertMatchesRegularExpression(
            '/normalize_dtype\(\$existing->embed_dtype\s*\?\?\s*null\)\s*===\s*\$dtype/',
            $src,
            'the hash-skip must re-embed when the stored dtype differs from this run'
        );
    }
}
