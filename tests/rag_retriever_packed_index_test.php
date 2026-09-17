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
 * Tests the packed per-course vector index and its persistent cache.
 *
 * Retrieval used to read and decode every vector in the course on EVERY
 * request, because the only cache was a PHP static. Measured on the dev fleet
 * over a 2,020-chunk course at 2048 dimensions, that database read cost about
 * 4,380 ms against 71 ms to actually score the vectors.
 *
 * The fix caches the packed bytes rather than the decoded float arrays, which
 * matters: serializing the decoded arrays for the same course measured ~3,580 ms
 * and 117 MB, i.e. slower than the read it was supposed to replace. These tests
 * pin the round trip and the invalidation, since a wrong answer here is silent
 * (retrieval scores against vectors that no longer exist).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\rag_retriever
 */
final class rag_retriever_packed_index_test extends \advanced_testcase {
    /**
     * Call a private static on the retriever.
     *
     * @param string $name
     * @param array $args
     * @return mixed
     */
    private function call(string $name, array $args = []) {
        $m = new \ReflectionMethod(rag_retriever::class, $name);
        $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    }

    /**
     * Insert one chunk row.
     *
     * @param int $courseid
     * @param array $fields Overrides for the row.
     * @return int Inserted id.
     */
    private function chunk(int $courseid, array $fields): int {
        global $DB;
        return (int) $DB->insert_record('local_ai_course_assistant_chunks', (object) array_merge([
            'courseid'    => $courseid,
            'cmid'        => 7,
            'modtype'     => 'page',
            'chunkindex'  => 0,
            'content'     => 'body text',
            'contenthash' => sha1('body text' . $courseid . random_int(0, PHP_INT_MAX)),
            'embedding'   => null,
            'embedding_bin' => null,
            'embed_model' => 'text-embedding-3-small',
            'embed_dtype' => 'float',
            'timecreated' => 0,
            'timeindexed' => 0,
        ], $fields));
    }

    /**
     * A float32 vector survives the pack, cache and hydrate round trip exactly.
     */
    public function test_float_vector_round_trips_bit_for_bit(): void {
        $this->resetAfterTest();
        $vec = [0.5, -0.25, 0.125, 0.0, 1.0];
        $id = $this->chunk(42, ['embedding_bin' => rag_retriever::pack_vector($vec, 'float')]);

        $packed = $this->call('build_packed_index_from_db', [42, 'text-embedding-3-small', 'float']);
        $out = $this->call('hydrate_index', [$packed]);

        $this->assertArrayHasKey($id, $out);
        $this->assertSame($vec, $out[$id]['vec']);
        $this->assertNull($out[$id]['bin']);
        $this->assertSame(7, $out[$id]['cmid']);
        $this->assertSame('page', $out[$id]['modtype']);
    }

    /**
     * A legacy row holding only the JSON column is packed losslessly.
     *
     * These rows predate embedding_bin. Re-packing them through pack('g*') is
     * only safe because the values are float32 to begin with.
     */
    public function test_json_only_legacy_row_is_packed_losslessly(): void {
        $this->resetAfterTest();
        $vec = [0.5, -0.25, 0.125];
        $id = $this->chunk(42, ['embedding' => json_encode($vec), 'embedding_bin' => null]);

        $out = $this->call('hydrate_index', [
            $this->call('build_packed_index_from_db', [42, 'text-embedding-3-small', 'float']),
        ]);

        $this->assertSame($vec, $out[$id]['vec']);
    }

    /**
     * Chunks of different lengths are sliced by recorded length, not a stride.
     *
     * A fixed stride would silently mis-slice every vector after the first
     * short one, producing plausible garbage rather than an error.
     */
    public function test_vectors_of_differing_lengths_are_sliced_correctly(): void {
        $this->resetAfterTest();
        $short = [1.0, 2.0];
        $long  = [3.0, 4.0, 5.0, 6.0];
        $ids = [
            $this->chunk(42, ['embedding_bin' => rag_retriever::pack_vector($short, 'float')]),
            $this->chunk(42, ['embedding_bin' => rag_retriever::pack_vector($long, 'float')]),
        ];

        $out = $this->call('hydrate_index', [
            $this->call('build_packed_index_from_db', [42, 'text-embedding-3-small', 'float']),
        ]);

        $this->assertSame($short, $out[$ids[0]]['vec']);
        $this->assertSame($long, $out[$ids[1]]['vec']);
    }

    /**
     * Binary vectors stay packed, because binary_similarity() compares bytes.
     */
    public function test_binary_vectors_stay_packed(): void {
        $this->resetAfterTest();
        $bits = pack('C*', 0b10110010, 0b00011101);
        $id = $this->chunk(42, [
            'embedding_bin' => $bits,
            'embed_dtype'   => 'binary',
            'embed_model'   => 'voyage-4-large',
        ]);

        $out = $this->call('hydrate_index', [
            $this->call('build_packed_index_from_db', [42, 'voyage-4-large', 'binary']),
        ]);

        $this->assertSame($bits, $out[$id]['bin']);
        $this->assertSame([], $out[$id]['vec']);
    }

    /**
     * Rows embedded by an incomparable model never enter the packed index.
     */
    public function test_incompatible_model_rows_are_excluded(): void {
        $this->resetAfterTest();
        $keep = $this->chunk(42, ['embedding_bin' => rag_retriever::pack_vector([1.0, 2.0], 'float')]);
        $this->chunk(42, [
            'embedding_bin' => rag_retriever::pack_vector([3.0, 4.0], 'float'),
            'embed_model'   => 'voyage-4-large',
        ]);

        $packed = $this->call('load_packed_index', [42, 'text-embedding-3-small', 'float']);
        $this->assertDebuggingCalled();

        $out = $this->call('hydrate_index', [$packed]);
        $this->assertSame([$keep], array_keys($out));

        // And again from the cache: the warning must not go quiet for 24 hours
        // just because the index is no longer rebuilt on every request.
        $this->call('load_packed_index', [42, 'text-embedding-3-small', 'float']);
        $this->assertDebuggingCalled();
    }

    /**
     * The second load is served from the cache, not the database.
     *
     * Proven by deleting the row underneath it: a cache hit still returns the
     * vector, which is exactly the staleness the version counter then fixes.
     */
    public function test_second_load_is_served_from_the_cache(): void {
        global $DB;
        $this->resetAfterTest();
        $id = $this->chunk(42, ['embedding_bin' => rag_retriever::pack_vector([1.0, 2.0], 'float')]);

        $first = $this->call('load_packed_index', [42, 'text-embedding-3-small', 'float']);
        $this->assertSame([$id], $first['ids']);

        $DB->delete_records('local_ai_course_assistant_chunks', ['id' => $id]);

        $second = $this->call('load_packed_index', [42, 'text-embedding-3-small', 'float']);
        $this->assertSame([$id], $second['ids'], 'expected the cached copy, so the cache is doing its job');
    }

    /**
     * Flushing one course rebuilds it from the database, and leaves others alone.
     */
    public function test_flush_cache_for_one_course_rebuilds_only_that_course(): void {
        global $DB;
        $this->resetAfterTest();
        $a = $this->chunk(42, ['embedding_bin' => rag_retriever::pack_vector([1.0, 2.0], 'float')]);
        $b = $this->chunk(99, ['embedding_bin' => rag_retriever::pack_vector([3.0, 4.0], 'float')]);

        $this->call('load_packed_index', [42, 'text-embedding-3-small', 'float']);
        $this->call('load_packed_index', [99, 'text-embedding-3-small', 'float']);

        $DB->delete_records('local_ai_course_assistant_chunks', ['id' => $a]);
        $DB->delete_records('local_ai_course_assistant_chunks', ['id' => $b]);
        rag_retriever::flush_cache(42);

        $this->assertSame([], $this->call('load_packed_index', [42, 'text-embedding-3-small', 'float'])['ids'],
            'course 42 was flushed, so it must come back from the database');
        $this->assertSame([$b], $this->call('load_packed_index', [99, 'text-embedding-3-small', 'float'])['ids'],
            'course 99 was not flushed, so its cached copy must survive');
    }

    /**
     * Flushing everything purges the definition, not just the calling process.
     */
    public function test_flush_cache_all_purges_the_persistent_layer(): void {
        global $DB;
        $this->resetAfterTest();
        $id = $this->chunk(42, ['embedding_bin' => rag_retriever::pack_vector([1.0, 2.0], 'float')]);
        $this->call('load_packed_index', [42, 'text-embedding-3-small', 'float']);

        $DB->delete_records('local_ai_course_assistant_chunks', ['id' => $id]);
        rag_retriever::flush_cache();

        $this->assertSame([], $this->call('load_packed_index', [42, 'text-embedding-3-small', 'float'])['ids']);
    }

    /**
     * An int8 index round trips, and its magnitudes are preserved.
     *
     * decode_vector() deliberately returns int8 values as-is rather than
     * dequantizing, because cosine is scale-invariant. The packed form has to
     * preserve that, not silently renormalize it.
     */
    public function test_int8_vectors_round_trip_with_magnitudes_intact(): void {
        $this->resetAfterTest();
        $vals = [127.0, -128.0, 0.0, 42.0];
        $id = $this->chunk(42, [
            'embedding_bin' => rag_retriever::pack_vector($vals, 'int8'),
            'embed_dtype'   => 'int8',
        ]);

        $out = $this->call('hydrate_index', [
            $this->call('build_packed_index_from_db', [42, 'text-embedding-3-small', 'int8']),
        ]);

        $this->assertSame($vals, $out[$id]['vec']);
        $this->assertSame('int8', $out[$id]['dtype']);
    }

    /**
     * A course holding two encodings decodes each chunk with its own decoder.
     *
     * This is what the parallel `lens`/`dtypes` arrays exist for: float is four
     * bytes per dimension and int8 is one, so a fixed stride or a single shared
     * dtype would mis-slice or mis-decode every row after the first.
     *
     * float and int8 are the only pair that can share a scored set: classify_row()
     * separates binary from non-binary, so a binary row is excluded outright when
     * the configured encoding is not binary.
     */
    public function test_mixed_dtype_course_decodes_each_chunk_with_its_own_decoder(): void {
        $this->resetAfterTest();
        $floatvals = [0.5, -0.25, 0.125, 1.0];   // 16 bytes
        $int8vals  = [127.0, -128.0, 7.0];       // 3 bytes
        $fid = $this->chunk(42, ['embedding_bin' => rag_retriever::pack_vector($floatvals, 'float')]);
        $iid = $this->chunk(42, [
            'embedding_bin' => rag_retriever::pack_vector($int8vals, 'int8'),
            'embed_dtype'   => 'int8',
        ]);

        $packed = $this->call('build_packed_index_from_db', [42, 'text-embedding-3-small', 'float']);
        // Map id => len rather than asserting positions: get_records_select has
        // no ORDER BY, so row order is not guaranteed and differs between an
        // isolated run and the full suite.
        $lens = array_combine($packed['ids'], $packed['lens']);
        $this->assertSame(16, $lens[$fid], 'float32 is four bytes per dimension');
        $this->assertSame(3, $lens[$iid], 'int8 is one byte per dimension');

        $out = $this->call('hydrate_index', [$packed]);
        $this->assertSame($floatvals, $out[$fid]['vec']);
        $this->assertSame($int8vals, $out[$iid]['vec'], 'the int8 row must not be decoded as float32');
    }

    /**
     * A legacy JSON-only row labelled int8 is packed as float, not quantized.
     *
     * decode_vector()'s JSON branch ignores the dtype and returns the literal
     * decoded values, so these are float32 magnitudes. Packing them as int8
     * would round each to -1, 0 or +1 and then score the result: silent garbage,
     * and a regression against the behaviour before this index was cached.
     */
    public function test_json_only_row_labelled_int8_is_not_quantized(): void {
        $this->resetAfterTest();
        $vec = [0.5, -0.25, 0.125];
        $id = $this->chunk(42, [
            'embedding'     => json_encode($vec),
            'embedding_bin' => null,
            'embed_dtype'   => 'int8',
        ]);

        $out = $this->call('hydrate_index', [
            $this->call('build_packed_index_from_db', [42, 'text-embedding-3-small', 'float']),
        ]);

        $this->assertSame($vec, $out[$id]['vec'], 'values must survive, not be rounded to 0/±1');
        $this->assertSame('float', $out[$id]['dtype']);
    }

    /**
     * A cached entry whose blob no longer matches its lengths is rebuilt.
     *
     * Trusting it would not throw: the offsets would simply shift and every
     * chunk after the damage would decode into plausible garbage.
     */
    public function test_corrupt_cached_entry_is_rebuilt_rather_than_mis_sliced(): void {
        $this->resetAfterTest();
        $vec = [1.0, 2.0, 3.0];
        $id = $this->chunk(42, ['embedding_bin' => rag_retriever::pack_vector($vec, 'float')]);

        $key = $this->call('persist_key', [42, 'text-embedding-3-small', 'float']);
        $cache = $this->call('vector_cache');
        $packed = $this->call('load_packed_index', [42, 'text-embedding-3-small', 'float']);
        $this->assertSame([$id], $packed['ids']);

        $packed['blob'] = substr($packed['blob'], 0, -4);   // lose one dimension
        $cache->set($key, $packed);

        $fresh = $this->call('load_packed_index', [42, 'text-embedding-3-small', 'float']);
        $this->assertSame(array_sum($fresh['lens']), strlen($fresh['blob']),
            'a truncated entry must be rebuilt from the database, not hydrated');
        $this->assertSame($vec, $this->call('hydrate_index', [$fresh])[$id]['vec']);
    }

    /**
     * Every flush produces a generation that has never been used before.
     *
     * The counter lives in config_plugins rather than a cache precisely so this
     * holds: a cached counter can be evicted or expire independently of the
     * entries it guards, and after that loss a fresh increment restarts low and
     * revives a still-live pre-reindex index. Purging every cache in the site
     * simulates the worst case; the generation must still move forward.
     */
    public function test_every_flush_produces_an_unused_generation(): void {
        $this->resetAfterTest();
        $this->chunk(42, ['embedding_bin' => rag_retriever::pack_vector([1.0, 2.0], 'float')]);

        $seen = [];
        for ($i = 0; $i < 3; $i++) {
            rag_retriever::flush_cache(42);
            $seen[] = $this->call('persist_key', [42, 'text-embedding-3-small', 'float']);
            // Back-to-back flushes land in the same millisecond, which is what
            // defeated a clock-derived counter.
        }

        // And again after every cache in the site has been thrown away.
        \cache_helper::purge_all();
        rag_retriever::flush_cache(42);
        $seen[] = $this->call('persist_key', [42, 'text-embedding-3-small', 'float']);

        $this->assertSame($seen, array_values(array_unique($seen)),
            'a generation must never be reused, or a flush can revive a cached index');
    }

    /**
     * Reindexing the site FAQ invalidates every course, not just the site course.
     *
     * FAQ chunks are stored once against SITEID and scored inside every course's
     * index. With the old per-request static this was nearly harmless. With a
     * 24 hour persistent cache, missing this invalidation would leave an edited
     * FAQ invisible to every course for a day.
     */
    public function test_faq_reindex_invalidates_every_course(): void {
        global $DB;
        $this->resetAfterTest();
        $id = $this->chunk(42, ['embedding_bin' => rag_retriever::pack_vector([1.0, 2.0], 'float')]);
        $this->call('load_packed_index', [42, 'text-embedding-3-small', 'float']);

        $DB->delete_records('local_ai_course_assistant_chunks', ['id' => $id]);
        set_config('faq_content', '', 'local_ai_course_assistant');
        faq_manager::index_faq();

        $this->assertSame([], $this->call('load_packed_index', [42, 'text-embedding-3-small', 'float'])['ids'],
            'clearing the FAQ must invalidate course indexes, which embed FAQ chunks');
    }

    /**
     * Two query models do not share one cached index.
     *
     * The stored set is filtered for comparability against the query model, so
     * reusing it across models would score vectors that were excluded on
     * purpose.
     */
    public function test_query_model_and_dtype_key_the_cache_separately(): void {
        $this->resetAfterTest();
        $oa = $this->chunk(42, ['embedding_bin' => rag_retriever::pack_vector([1.0, 2.0], 'float')]);
        $vo = $this->chunk(42, [
            'embedding_bin' => rag_retriever::pack_vector([3.0, 4.0], 'float'),
            'embed_model'   => 'voyage-4-large',
        ]);

        $first = $this->call('load_packed_index', [42, 'text-embedding-3-small', 'float']);
        $this->assertDebuggingCalled();
        $second = $this->call('load_packed_index', [42, 'voyage-4-large', 'float']);
        $this->assertDebuggingCalled();

        $this->assertSame([$oa], $first['ids']);
        $this->assertSame([$vo], $second['ids']);
    }

}
