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

use local_ai_course_assistant\embedding_provider\base_embedding_provider;

/**
 * Retrieves semantically relevant chunks for a user query via cosine similarity.
 *
 * Algorithm:
 *  1. Embed the user query (single embedding API call).
 *  2. Load all embedded chunks for the course from DB.
 *  3. Compute cosine similarity for each chunk.
 *  4. Return top-k chunks sorted by descending similarity.
 *
 * Performance, measured on the dev fleet 2026-09-17 over a 2,020-chunk course
 * at 2048 dimensions: scoring every vector costs ~71 ms, but reading them out
 * of the database costs ~4,380 ms. The scan is not the expensive part; the
 * fetch is. Step 2 is therefore cached as packed bytes in the `vectors`
 * application cache, which leaves ~210 ms of decode on a warm hit. Reach for a
 * vector database when a single course index makes the scan itself the cost,
 * which at these numbers is well past 10,000 chunks.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rag_retriever {
    /**
     * @var array Decoded chunk vectors, keyed "course_<id>".
     *
     * The innermost of three layers: this static, then the `vectors`
     * application cache holding the packed bytes, then the database. This one
     * holds the DECODED vectors and is never serialized, because serializing
     * decoded float arrays is slower than re-reading them (see
     * build_packed_index_from_db()).
     *
     * Lives for the life of the PHP process, so it MUST be flushed whenever the
     * index changes -- see flush_cache(). For a web request that window is a
     * single page load, but CLI processes (reindex tools, benchmark harnesses,
     * scheduled tasks) can hold it across an entire run and would otherwise
     * score against vectors that no longer exist.
     */
    private static array $vectorcache = [];

    /**
     * Largest packed index we will hand to the application cache, in bytes.
     *
     * 24 MB, sized against what a WEB REQUEST can afford rather than against the
     * corpus. The previous value was 64 MB, which was indefensible: PHP's
     * default memory_limit for a web request here is 128 MB, and serializing a
     * 64 MB blob for the cache store needs another 64 MB on top of the blob
     * itself, so the ceiling could not be reached without fatalling first. A
     * ceiling that can only be hit by crashing is not a ceiling.
     *
     * 24 MB clears our largest measured course (2,020 chunks at 2048 float32
     * dimensions is 15.8 MB) with headroom, and anything larger degrades to
     * reading the database each time, which is slow but correct.
     */
    private const MAX_CACHED_INDEX_BYTES = 25165824;

    /**
     * Default for `rerank_min_query_chars`: the longest query still skipped.
     *
     * 50 as measured on the RAG fixture set (gated 67.7% recall vs 67.2%
     * always-on, ~a third cheaper). Only the DEFAULT lives in code — the
     * effective value is the admin setting, because after the next release
     * there are no code deploys.
     */
    public const RERANK_MIN_QUERY_CHARS_DEFAULT = 50;

    /** Skip reason: query at or under `rerank_min_query_chars`. */
    public const RERANK_SKIP_SHORT_QUERY = 'short_query';

    /** Skip reason: cosine top-1/top-3 margin says the ranking is already confident. */
    public const RERANK_SKIP_CONFIDENT = 'confident_margin';

    /**
     * Discard cached vectors after the index changes.
     *
     * Called by every path that inserts or deletes chunks: content_indexer,
     * faq_manager, observer::course_deleted(), embedding_migration.
     *
     * No longer cheap, and no longer per-process. Flushing one course bumps a
     * shared generation counter (two cache ops). Flushing everything purges the
     * whole `vectors` definition across the site, so do not call the null form
     * in a loop -- call it once after the loop.
     *
     * @param int|null $courseid Course to flush, or null for every course.
     */
    public static function flush_cache(?int $courseid = null): void {
        if ($courseid === null) {
            self::$vectorcache = [];
            // The persistent layer has no key enumeration, so "everything"
            // means purging the definition. Safe: it only forces rebuilds.
            \cache_helper::purge_by_definition('local_ai_course_assistant', 'vectors');
            return;
        }

        // Bump the course's generation before touching the static, so that a
        // concurrent request on this node cannot repopulate the old key from
        // the old generation. Every web node reads the same counter, which is
        // the behaviour the old per-process static could not provide: a
        // reindex on one node left every other node scoring against vectors
        // that no longer existed until its PHP process recycled.
        // max(counter + 1, time()) rather than counter + 1, because the counter
        // shares this definition's 24h TTL with the entries it guards AND is
        // written before them, so it expires first. Once it is gone
        // course_version() falls back to 1, and a plain increment would set 2 --
        // a generation whose entries can still be live, reviving a pre-reindex
        // index for the remainder of their TTL. Eviction does the same thing:
        // a tiny counter and a 16 MB blob are not evicted together. Wall clock
        // is monotonic, so a generation is never reused.
        // The counter lives in config_plugins, NOT in a cache. A cached counter
        // can be lost independently of the entries it guards -- it expires on
        // the same clock (and is written first, so it goes first), and eviction
        // under memory pressure drops a tiny key long before a 16 MB blob. After
        // such a loss there is no memory of which generations were already used,
        // so the next bump restarts low and revives a pre-reindex index whose
        // entries are still live. A clock-derived value only narrows that window;
        // two flushes inside the same millisecond still collide. config_plugins
        // is durable, shared across nodes, and survives a cache purge, which
        // closes the hole rather than shrinking it. Reads are free: Moodle keeps
        // plugin config in memory for the request.
        set_config(
            self::GENERATION_CONFIG_PREFIX . $courseid,
            self::course_version($courseid) + 1,
            'local_ai_course_assistant'
        );
        // Keys are "course_<id>_<querymodel hash>", because a cached set is
        // filtered for comparability against one query model. Flushing has to
        // clear every variant for the course: an exact unset() of
        // "course_<id>" matches nothing and would leave a reindexed course
        // scoring against vectors that no longer exist.
        $prefix = "course_{$courseid}_";
        foreach (array_keys(self::$vectorcache) as $key) {
            if ($key === "course_{$courseid}" || strpos($key, $prefix) === 0) {
                unset(self::$vectorcache[$key]);
            }
        }
    }

    /**
     * Application cache holding packed per-course vector indexes.
     *
     * Untyped on purpose: the concrete class moved to core_cache\application_cache
     * in Moodle 5.0 and we support 4.5 through 5.2.
     *
     * @return \cache_loader
     */
    private static function vector_cache() {
        return \cache::make('local_ai_course_assistant', 'vectors');
    }

    /** Config key prefix for the per-course index generation. */
    private const GENERATION_CONFIG_PREFIX = 'ragvecgen_';

    /**
     * Current generation number for a course's index.
     *
     * Every cached index embeds this in its key, so bumping it orphans every
     * variant for the course at once. That is the only invalidation primitive
     * MUC gives us that works across processes and web nodes: there is no way
     * to enumerate or wildcard-delete keys.
     *
     * @param int $courseid
     * @return int
     */
    private static function course_version(int $courseid): int {
        $ver = get_config('local_ai_course_assistant', self::GENERATION_CONFIG_PREFIX . $courseid);
        return ($ver === false || $ver === null || $ver === '') ? 1 : (int) $ver;
    }

    /**
     * Key for one course's packed index under one query model and encoding.
     *
     * Both discriminators matter: the stored set is filtered for comparability
     * against the query model AND against the configured encoding, so an index
     * built for one is wrong for the other.
     *
     * @param int    $courseid
     * @param string $querymodel
     * @param string $dtype
     * @return string
     */
    private static function persist_key(int $courseid, string $querymodel, string $dtype): string {
        // Every course whose chunks are packed into this index contributes BOTH
        // its id and its generation counter.
        //
        // The id alone is not enough, and the difference is a real 24-hour bug.
        // flush_cache() bumps ragvecgen_<courseid> for one course, and there is
        // no reverse map from a supplemental course to the courses listing it.
        // So an admin who edits the orientation course and reindexes it bumps a
        // counter that no host course's key reads: with the id alone in the key,
        // ninety courses keep scoring the pre-edit chunks until the 24h TTL
        // expires. New policy text is unreachable, and chunk ids that the
        // reindex deleted still occupy top-k slots -- hydrate_content() drops
        // them AFTER the slice, so the learner silently gets fewer passages than
        // before the edit, with nothing logged.
        //
        // Reading a generation per course costs nothing: they are plugin config
        // values already in memory for this request.
        $scope = [$courseid . ':' . self::course_version($courseid)];
        foreach (supplemental_sources::usable_course_ids($courseid) as $supplementalid) {
            $scope[] = $supplementalid . ':' . self::course_version($supplementalid);
        }

        return 'c' . $courseid
            . '_v' . self::course_version($courseid)
            . '_' . md5($querymodel . '|' . $dtype . '|' . implode(',', $scope));
    }

    /**
     * Fetch a course's packed index, from the application cache or the database.
     *
     * @param int    $courseid
     * @param string $querymodel Model that produced the query vector.
     * @param string $dtype      Encoding the query is prepared for.
     * @return array Packed index; see build_packed_index_from_db() for the shape.
     */
    private static function load_packed_index(int $courseid, string $querymodel, string $dtype): array {
        $cache = self::vector_cache();
        $key = self::persist_key($courseid, $querymodel, $dtype);

        $packed = $cache->get($key);
        if (self::packed_index_is_intact($packed)) {
            self::report_skips($packed, $courseid, $querymodel, $dtype);
            return $packed;
        }

        $packed = self::build_packed_index_from_db($courseid, $querymodel, $dtype);
        self::report_skips($packed, $courseid, $querymodel, $dtype);

        $size = strlen($packed['blob']);
        if ($size <= self::MAX_CACHED_INDEX_BYTES && !$cache->set($key, $packed)) {
            // MAX_CACHED_INDEX_BYTES is our ceiling; the store has its own and it
            // is usually lower. cachestore_memcached refuses items over 1 MB by
            // default, so on such a site every request would pay the full
            // database read AND a failed multi-megabyte serialize, with the
            // headline speedup simply not happening and nothing to say why.
            debugging(
                sprintf(
                    'rag_retriever: the application cache store rejected a %d-byte packed index for '
                    . 'course %d. Retrieval will read the database on every request. Cache stores cap '
                    . 'item size (memcached defaults to 1 MB); map the "vectors" definition to a store '
                    . 'that can hold it, or reduce the index with int8 embeddings.',
                    $size,
                    $courseid
                ),
                DEBUG_NORMAL
            );
        }
        return $packed;
    }

    /**
     * Read a course's embedded chunks and pack them into one cacheable structure.
     *
     * The returned shape is deliberately NOT a list of decoded float arrays.
     * Measured on the dev fleet over a 2,020-chunk course at 2048 dimensions:
     * serializing decoded arrays costs ~3,580 ms and 117 MB, which is slower
     * than the ~4,380 ms database read it exists to avoid, whereas the packed
     * form serializes in ~6 ms at 16 MB. Everything here is a scalar or one
     * binary string for exactly that reason.
     *
     *   [
     *     'ids'          => int[],           // chunk ids, in blob order
     *     'lens'         => int[],           // bytes each chunk occupies
     *     'dtypes'       => string[],        // per-chunk encoding
     *     'cmids'        => (int|null)[],
     *     'modtypes'     => string[],
     *     'chunkindexes' => int[],
     *     'blob'         => string,          // every chunk's packed bytes, concatenated
     *   ]
     *
     * @param int    $courseid
     * @param string $querymodel
     * @param string $dtype
     * @return array
     */
    private static function build_packed_index_from_db(int $courseid, string $querymodel, string $dtype): array {
        global $DB;

        // Even streaming, a large course index is a genuinely heavy structure:
        // 2,020 chunks at 2048 float32 dimensions peaks around 116 MB against a
        // default web limit of 128 MB, and a bigger course would cross it. This
        // is the case raise_memory_limit() exists for, and Moodle core uses it
        // for the same reason in backup, restore and search indexing. It only
        // ever raises, so a site already configured higher is untouched.
        raise_memory_limit(MEMORY_EXTRA);

        // One IN clause covering this course plus any supplemental ones, rather
        // than a second query: retrieval scores them together, so loading them
        // together keeps the ordering and the memory profile unchanged.
        $scope = array_merge([$courseid], supplemental_sources::usable_course_ids($courseid));
        [$coursesql, $courseparams] = $DB->get_in_or_equal($scope, SQL_PARAMS_NAMED, 'scope');

        $packed = [
            'ids' => [], 'lens' => [], 'dtypes' => [],
            'cmids' => [], 'modtypes' => [], 'chunkindexes' => [], 'blob' => '',
            // Carried rather than reported here so the warnings survive caching.
            // They used to fire on every retrieval; once the build happens at
            // most once a day per course they would otherwise go quiet, and the
            // whole point of them is that the symptom (retrieval silently
            // returning nothing) looks nothing like the cause.
            'skipped_model' => 0, 'skipped_model_name' => '',
            'skipped_dtype' => 0, 'skipped_dtype_name' => '',
        ];

        // get_recordSET, not get_records: materializing every row first peaks at
        // 166 MB on a 2,020-chunk course at 2048 dimensions, which is over the
        // 128 MB a web request gets and is why retrieval fatalled with
        // "Allowed memory size exhausted" on the largest courses. That predates
        // the packed cache -- the read alone was already over budget, so the
        // cache could never populate on exactly the courses it was built for.
        // A recordset streams, so peak is one row plus what we keep.
        $rs = $DB->get_recordset_select(
            'local_ai_course_assistant_chunks',
            // Either column is sufficient. Testing only `embedding` made
            // every quantized row invisible: an int8 or binary index writes
            // the packed blob and leaves the JSON column null, because
            // storing a second, larger copy of the vector would defeat the
            // point of quantizing it.
            // v7.2.7: site-wide FAQ chunks are scored alongside the
            // course's own material. The FAQ is one admin setting, so it is
            // embedded once against SITEID rather than copied into every
            // course index; without this clause it would be invisible to
            // every course that is not the site course.
            //
            // It competes on relevance like anything else, which is the
            // point: a question about certificates retrieves the
            // certificate answer, and a question about marginal cost
            // retrieves none of it. Previously all 4,451 characters were
            // injected into every prompt regardless.
            // v7.5.0: supplemental courses. An administrator can name courses
            // whose already-indexed content is retrievable from here -- the
            // orientation course being the case this exists for, so a question
            // about exams reaches the Student Resource Center instead of
            // finding nothing. No new embedding: these chunks are already in
            // this table, only out of scope until now.
            '(courseid ' . $coursesql . ' OR (courseid = :siteid AND modtype = :faqtype))
               AND (embedding IS NOT NULL OR embedding_bin IS NOT NULL)',
            array_merge($courseparams, [
                'siteid' => SITEID,
                'faqtype' => \local_ai_course_assistant\faq_manager::MODTYPE,
            ]),
            '',
            // NB: `content` is deliberately NOT selected here. Scoring
            // reads vectors only, so the text is fetched later for the
            // handful of chunks that survive selection. Measured
            // 2026-08-02 over repeated cold runs this is a small time win
            // but a real memory one: the largest course holds 56 MB of
            // chunk text that scoring never looks at.
            'id, courseid, embedding, embedding_bin, embed_dtype, embed_model, cmid, modtype, chunkindex'
        );

        $skippedincompatible = 0;
        $skippedmodel = '';
        $skippeddtype = 0;
        $skippeddtypename = '';

        foreach ($rs as $row) {
            $rowdtype = \local_ai_course_assistant\embedding_compat::normalize_dtype(
                $row->embed_dtype ?? null
            );
            $rowmodel = (string) ($row->embed_model ?? '');

            // Refuse to score across embedding spaces or encodings.
            // Both refusals are decided by classify_row(), which is pure
            // and unit-tested; retrieve() itself cannot be exercised in a
            // test because it makes a billable API call first.
            $verdict = self::classify_row($querymodel, $dtype, $rowmodel, $rowdtype);
            if ($verdict === 'model') {
                $skippedincompatible++;
                $skippedmodel = $rowmodel;
                continue;
            }
            if ($verdict === 'dtype') {
                $skippeddtype++;
                $skippeddtypename = $rowdtype;
                continue;
            }

            // Prefer the stored bytes. Where a legacy row has only the JSON
            // column, decode it and re-pack: pack('g*') is float32 and the
            // embeddings are float32, so the round trip is lossless (verified
            // on dev over a full course at max element error 0.0).
            $bytes = (string) ($row->embedding_bin ?? '');
            if ($bytes === '') {
                if ($rowdtype === \local_ai_course_assistant\embedding_compat::DTYPE_BINARY) {
                    // A binary index has no JSON representation to fall back to.
                    continue;
                }
                $vec = self::decode_vector(null, $row->embedding ?? null, $rowdtype);
                if (!is_array($vec) || empty($vec)) {
                    continue;
                }
                // decode_vector()'s JSON branch ignores $dtype and returns the
                // literal decoded values, so an int8-LABELLED row that only has
                // the JSON column yields float32 magnitudes in roughly [-1, 1].
                // Packing those as int8 would round every one to -1, 0 or +1 and
                // then score the result, which is silent garbage and a
                // regression against main, where the same row scored correctly.
                // Pack float32 and record float: lossless either way, and
                // classify_row() only distinguishes binary from non-binary.
                $rowdtype = \local_ai_course_assistant\embedding_compat::DTYPE_FLOAT;
                $bytes = self::pack_vector($vec, $rowdtype);
            }
            if ($bytes === '') {
                continue;
            }

            $packed['ids'][]          = (int) $row->id;
            $packed['lens'][]         = strlen($bytes);
            $packed['dtypes'][]       = $rowdtype;
            $packed['cmids'][]        = isset($row->cmid) ? (int) $row->cmid : null;
            $packed['modtypes'][]     = (string) ($row->modtype ?? '');
            $packed['chunkindexes'][] = (int) ($row->chunkindex ?? 0);
            $packed['blob']          .= $bytes;

            // Drop every reference to this row's payload before the next one is
            // fetched. Without this the blobs accumulate anyway and the
            // recordset buys nothing.
            unset($bytes, $row->embedding, $row->embedding_bin, $row);
        }
        $rs->close();

        $packed['skipped_model'] = $skippedincompatible;
        $packed['skipped_model_name'] = $skippedmodel;
        $packed['skipped_dtype'] = $skippeddtype;
        $packed['skipped_dtype_name'] = $skippeddtypename;

        return $packed;
    }

    /**
     * Emit the "some chunks were skipped" warnings for a packed index.
     *
     * Called on the cache HIT path as well as the miss path. Loud, because the
     * symptom otherwise is "retrieval quietly returns nothing" and the cause is
     * a config change made days earlier.
     *
     * @param array  $packed
     * @param int    $courseid
     * @param string $querymodel
     * @param string $dtype
     */
    private static function report_skips(array $packed, int $courseid, string $querymodel, string $dtype): void {
        $skippedincompatible = (int) ($packed['skipped_model'] ?? 0);
        $skippedmodel = (string) ($packed['skipped_model_name'] ?? '');
        $skippeddtype = (int) ($packed['skipped_dtype'] ?? 0);
        $skippeddtypename = (string) ($packed['skipped_dtype_name'] ?? '');

        if ($skippedincompatible > 0) {
            debugging(
                sprintf(
                    'rag_retriever: skipped %d chunk(s) in course %d embedded with "%s", '
                    . 'which is not comparable to the query model "%s". '
                    . 'Re-index the course, or set embed_query_model back to a compatible model.',
                    $skippedincompatible,
                    $courseid,
                    shorten_text($skippedmodel, 100),
                    shorten_text($querymodel, 100)
                ),
                DEBUG_NORMAL
            );
        }

        if ($skippeddtype > 0) {
            debugging(
                sprintf(
                    'rag_retriever: skipped %d chunk(s) in course %d stored as "%s" while the '
                    . 'configured embed_dtype is "%s". Re-index the course after changing '
                    . 'embed_dtype — the encodings are not interchangeable.',
                    $skippeddtype,
                    $courseid,
                    shorten_text($skippeddtypename, 40),
                    $dtype
                ),
                DEBUG_NORMAL
            );
        }
    }

    /**
     * Is a cached packed index structurally self-consistent?
     *
     * hydrate_index() walks the blob by the recorded lengths, so a short or
     * corrupt `lens` shifts every subsequent offset and yields plausible
     * garbage rather than an error. An array_sum over a few thousand ints is
     * microseconds against a ~210 ms decode, so the certainty is worth buying.
     *
     * @param mixed $packed
     * @return bool
     */
    private static function packed_index_is_intact($packed): bool {
        if (!is_array($packed) || !isset($packed['ids'], $packed['blob'], $packed['lens'])) {
            return false;
        }
        if (!is_array($packed['ids']) || !is_array($packed['lens']) || !is_string($packed['blob'])) {
            return false;
        }
        if (count($packed['lens']) !== count($packed['ids'])) {
            return false;
        }
        return array_sum($packed['lens']) === strlen($packed['blob']);
    }

    /**
     * Expand a packed index into the scoring structure keyed by chunk id.
     *
     * Binary vectors stay packed: binary_similarity() compares the bytes
     * directly, which is the entire point of that encoding.
     *
     * @param array $packed From build_packed_index_from_db() or the cache.
     * @return array chunkid => ['vec' => float[], 'bin' => string|null, 'dtype' => string,
     *               'cmid' => int|null, 'modtype' => string, 'chunkindex' => int]
     */
    private static function hydrate_index(array $packed): array {
        $out = [];
        if (empty($packed['ids']) || !isset($packed['blob'])) {
            return $out;
        }

        $offset = 0;
        $count = count($packed['ids']);
        for ($i = 0; $i < $count; $i++) {
            $len = (int) $packed['lens'][$i];
            if ($len <= 0) {
                // substr() reads a negative length as "stop N from the end",
                // which would slice the wrong bytes and walk $offset backwards.
                continue;
            }
            $slice = substr($packed['blob'], $offset, $len);
            $offset += $len;
            if ($slice === '') {
                continue;
            }

            $rowdtype = (string) $packed['dtypes'][$i];
            $entry = [
                'cmid'       => $packed['cmids'][$i],
                'modtype'    => (string) $packed['modtypes'][$i],
                'chunkindex' => (int) $packed['chunkindexes'][$i],
                'dtype'      => $rowdtype,
            ];

            if ($rowdtype === \local_ai_course_assistant\embedding_compat::DTYPE_BINARY) {
                $entry['vec'] = [];
                $entry['bin'] = $slice;
            } else {
                $vec = self::decode_vector($slice, null, $rowdtype);
                if (!is_array($vec) || empty($vec)) {
                    continue;
                }
                $entry['vec'] = $vec;
                $entry['bin'] = null;
            }

            $out[(int) $packed['ids'][$i]] = $entry;
        }

        return $out;
    }

    /**
     * Retrieve the top-k most relevant chunks for a user query.
     *
     * @param int    $courseid
     * @param string $query    The user's message / question.
     * @param int    $topk     Number of chunks to return.
     * @param int    $currentcmid Course-module id of the document the learner is
     *                            on (0 if none). Drives the current-page ordering
     *                            boost and, when `rag_scope` constrains to the
     *                            document, filters retrieval to that document.
     * @return array Array of [
     *                  'content'    => string,
     *                  'score'      => float,
     *                  'cmid'       => int|null,
     *                  'modtype'    => string,
     *                  'chunkindex' => int,
     *               ] sorted by score desc, filtered to those at/above the
     *               configured relevance floor. Empty array if no chunks clear
     *               the floor, no chunks exist, or embedding fails.
     */
    public static function retrieve(int $courseid, string $query, int $topk = 5, int $currentcmid = 0): array {
        global $DB;

        $final = null;

        $cache_key = "course_{$courseid}";

        // Relevance gate + current-page bias, admin-tunable. The floor drops
        // weakly-matched chunks so an off-topic or sparse query injects fewer
        // (or zero) passages instead of always padding to top-k. The boost
        // prefers chunks from the page the learner is on among near-ties
        // (ordering only). Defaults assume the text-embedding-3-small cosine
        // scale; re-tune for other embedding models.
        $rawfloor = get_config('local_ai_course_assistant', 'rag_min_similarity');
        $minscore = ($rawfloor === false || $rawfloor === '') ? 0.25 : (float) $rawfloor;
        $rawboost = get_config('local_ai_course_assistant', 'rag_currentpage_boost');
        $boost = ($rawboost === false || $rawboost === '') ? 0.05 : (float) $rawboost;

        // v6.8.7: retrieval scope. When the learner is viewing a specific
        // document ($currentcmid > 0), 'document_first' grounds the answer on
        // that document's chunks when it has any that clear the floor, and falls
        // back to the whole course otherwise; 'document_only' never falls back
        // (no relevant chunk on the page means retrieve nothing, so the tutor
        // answers from general knowledge rather than citing unrelated pages);
        // 'course' keeps the legacy course-wide search (the current page still
        // gets the ordering boost). Default document_first.
        $rawscope = get_config('local_ai_course_assistant', 'rag_scope');
        $scope = ($rawscope === false || $rawscope === '') ? 'document_first' : (string) $rawscope;

        // Budget gate. Every RAG API call downstream of here is billable: the
        // query embedding, and the rerank pass if it runs. A 'rag' cap that has
        // been exceeded therefore stops at this one point rather than being
        // checked twice.
        //
        // Returning [] degrades to no retrieved context, which is the identical
        // path taken when the index is empty or the embedding call fails, so chat
        // continues to work and only loses its citations. Unreachable unless an
        // admin has actually set a cap: get_cap() returns 0 when unset and
        // check() treats 0 as unlimited.
        if (spend_guard::check(0, 'rag') === spend_guard::CAP_BLOCKED) {
            return [];
        }

        // Embed the query. Voyage exposes a separate embed_query() entrypoint;
        // other providers (OpenAI, Ollama) have only embed(). Whether the
        // Voyage query call actually asks for a different projection from the
        // document call is the provider's business and is now a setting
        // (embed_input_type_mode, asymmetric by default since v7.4.5 because
        // that is the option that measures better; see
        // voyage_embedding_provider). Either way
        // this call site is unchanged: it asks for "a query vector".
        $provider = base_embedding_provider::create_from_config();
        if ($provider instanceof \local_ai_course_assistant\embedding_provider\voyage_embedding_provider) {
            $queryvec = $provider->embed_query($query);
        } else {
            $queryvec = $provider->embed($query);
        }

        if (empty($queryvec)) {
            return [];
        }

        // Which embedding space this query vector lives in. Compared against
        // each stored chunk's recorded model below, so that a query embedded by
        // one model is never scored against documents embedded by an
        // incomparable one.
        $querymodel = $provider->get_query_model();

        // Encoding the index is expected to be in. Used to decide whether the
        // query vector is a float array (float/int8 indexes) or packed bits
        // (binary indexes), and to reject rows stored in a different encoding.
        $configureddtype = $provider->effective_dtype();

        // The cached set is filtered by comparability against the query model,
        // so it is only reusable for the same query model. Two retrievals in one
        // request with different query models would otherwise share a cache
        // entry built for the first one.
        $cache_key .= '_' . md5($querymodel);

        // The persistent layer is keyed by encoding as well as query model,
        // because the filtered set depends on both.
        $cache_key .= '_' . $configureddtype;

        // ...and by the supplemental scope, for the same reason persist_key()
        // is. supplemental_sources::reset_cache() clears that class's memo, not
        // this static, so without the scope here a settings change inside one
        // request would keep serving the index built under the old scope.
        $cache_key .= '_' . md5(implode(',', supplemental_sources::usable_course_ids($courseid)));

        // Load embeddings. Three layers, cheapest first: a per-request static,
        // a cross-request application cache holding the packed bytes, and the
        // database.
        if (!isset(self::$vectorcache[$cache_key])) {
            $packed = self::load_packed_index($courseid, $querymodel, $configureddtype);
            self::$vectorcache[$cache_key] = self::hydrate_index($packed);
        }

        if (empty(self::$vectorcache[$cache_key])) {
            return [];
        }

        // Score each chunk.
        // The query norm is constant across every chunk, so compute it once.
        // cosine() recomputed it per chunk, which on the largest course meant
        // ~2,000 redundant passes over a 1,536-element vector.
        $qnorm = 0.0;
        foreach ($queryvec as $qv) {
            $qnorm += $qv * $qv;
        }
        $qnorm = sqrt($qnorm);

        // Binary indexes are scored by Hamming distance, which needs the query
        // in the same bit-packed form. When the configured dtype is binary the
        // provider has already asked the API for a binary query, so $queryvec
        // holds packed bytes rather than floats and only needs packing into a
        // string. It is deliberately NOT derived from a float query: sign(float)
        // reproduces the API's bits only ~87.5% of the time.
        $querybin = null;
        if ($configureddtype === \local_ai_course_assistant\embedding_compat::DTYPE_BINARY) {
            $querybin = self::pack_vector($queryvec, \local_ai_course_assistant\embedding_compat::DTYPE_BINARY);
        }

        $scored = [];
        foreach (self::$vectorcache[$cache_key] as $chunkid => $entry) {
            if (($entry['dtype'] ?? '') === \local_ai_course_assistant\embedding_compat::DTYPE_BINARY) {
                $score = ($querybin === null || $querybin === '')
                    ? 0.0
                    : self::binary_similarity($querybin, (string) $entry['bin']);
                $scored[] = [
                    'id'         => $chunkid,
                    'content'    => '',
                    'score'      => $score,
                    'cmid'       => $entry['cmid'],
                    'modtype'    => $entry['modtype'],
                    'chunkindex' => $entry['chunkindex'],
                ];
                continue;
            }
            $score    = self::cosine_against_query($queryvec, $qnorm, $entry['vec']);
            $scored[] = [
                'id'         => $chunkid,
                'content'    => '',
                'score'      => $score,
                'cmid'       => $entry['cmid'],
                'modtype'    => $entry['modtype'],
                'chunkindex' => $entry['chunkindex'],
            ];
        }

        if (empty($scored)) {
            return [];
        }

        // Relevance gate + current-page bias. Applied before reranking, so
        // genuinely irrelevant chunks never reach the (more expensive) reranker
        // and an off-topic query returns fewer (or zero) passages.
        $scored = self::filter_and_rank($scored, $minscore, $currentcmid, $boost);
        if (empty($scored)) {
            return [];
        }

        // Constrain to the current document when the learner is viewing one.
        // Applied before reranking, so the reranker only sees the scoped set.
        $scored = self::scope_to_document($scored, $currentcmid, $scope);
        if (empty($scored)) {
            return [];
        }

        // Hydrate text for the survivors only. Everything above ranks on
        // vectors alone, so this is the first point that needs the actual
        // chunk text -- and by now the set is at most a few dozen rows rather
        // than the whole course.
        // Hydrate wider than top-k only when stage 2 can actually use the extra
        // rows. The length gate is evaluated here as well as in apply_rerank
        // because it needs nothing but the query: on a gated-off short query
        // there is no point fetching 20 chunk bodies to then rank 5 of them by
        // cosine. The margin gate cannot be hoisted the same way -- it is
        // measured on the candidate scores, which is upstream of the text.
        $hydratelimit = $topk;
        if (
            (bool) get_config('local_ai_course_assistant', 'rerank_enabled')
                && self::query_long_enough_to_rerank($query)
        ) {
            $rawcand = get_config('local_ai_course_assistant', 'rerank_candidates');
            $hydratelimit = max(
                $topk,
                ($rawcand === false || $rawcand === '') ? 20 : (int) $rawcand
            );
        }
        $scored = self::hydrate_content(array_slice($scored, 0, $hydratelimit));
        if (empty($scored)) {
            return [];
        }

        // Optional stage 2: two-stage retrieval with Voyage rerank-2.5. Gated
        // per query (see apply_rerank / rerank_skip_reason) and falling back to
        // the single-stage cosine top-k whenever it is skipped or fails.
        if ((bool) get_config('local_ai_course_assistant', 'rerank_enabled')) {
            $final = self::apply_rerank($query, $scored, $topk, null, $courseid);
        }

        $final = $final ?? array_slice($scored, 0, $topk);

        // Parent-document expansion (opt-in). Selection above is unchanged;
        // here we optionally widen each hit to a neighbour window or full page.
        $rawreturn = get_config('local_ai_course_assistant', 'rag_return_scope');
        $returnscope = ($rawreturn === false || $rawreturn === '') ? 'chunk' : (string) $rawreturn;
        if ($returnscope === 'chunk') {
            return $final;
        }
        $rawwin = get_config('local_ai_course_assistant', 'rag_window_size');
        $windowsize = ($rawwin === false || $rawwin === '') ? 1 : max(0, (int) $rawwin);
        $rawcap = get_config('local_ai_course_assistant', 'rag_parent_max_chars');
        $maxchars = ($rawcap === false || $rawcap === '') ? 6000 : max(500, (int) $rawcap);

        $cmids = array_values(array_unique(array_filter(
            array_map(fn($r) => (int) ($r['cmid'] ?? 0), $final)
        )));
        $siblingsbycmid = [];
        if (!empty($cmids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
            // Same course scope the index was built over. Pinning this to
            // $courseid alone was correct until supplemental courses existed:
            // FAQ chunks carry cmid 0 and are dropped by the $cmid <= 0 guard
            // above, so every cmid reaching here did belong to this course.
            // Now one can come from a supplemental course, and course_modules.id
            // is a site-wide sequence, so `courseid = :cid` could never match
            // it. Its sibling set came back empty and merge_parents() returned
            // the bare chunk with no expand_mode marker -- indistinguishable
            // downstream from a chunk that was deliberately not expanded. The
            // effect, wherever rag_return_scope is window or page: same-course
            // hits expand and cross-course ones never do, which is worse
            // grounding for exactly the orientation content this is for.
            [$coursesql, $courseparams] = $DB->get_in_or_equal(
                array_merge([$courseid], supplemental_sources::usable_course_ids($courseid)),
                SQL_PARAMS_NAMED,
                'expandscope'
            );
            $rows = $DB->get_records_select(
                'local_ai_course_assistant_chunks',
                "courseid {$coursesql} AND cmid {$insql}",
                array_merge($courseparams, $inparams),
                'cmid, chunkindex',
                // v7.4.5: embed_model/embed_dtype are selected because this
                // query has to make the same comparability decision the scoring
                // loop above makes. Selection is filtered by classify_row(); the
                // expansion was not, so on an index holding two embedding
                // generations -- exactly what a migration produces, and what
                // content_indexer's shadow mode deliberately preserves -- every
                // sibling arrived twice.
                'id, cmid, chunkindex, content, embed_model, embed_dtype'
            );
            foreach ($rows as $r) {
                // Same predicate as selection, so the two can never disagree
                // about what belongs to the live index.
                if (self::classify_row($querymodel, $configureddtype, $r->embed_model ?? null,
                        $r->embed_dtype ?? null) !== 'ok') {
                    continue;
                }
                // Keyed by chunkindex rather than appended: merge_parents sorts
                // by chunkindex and hands the list to content_chunker, whose
                // overlap dedupe caps at 100 words and so only partially strips
                // a duplicated ~1,000-character chunk. Belt and braces -- a
                // duplicate cannot survive even if a future caller reaches this
                // array by another route.
                $siblingsbycmid[(int) $r->cmid][(int) $r->chunkindex] = [
                    'content'    => (string) $r->content,
                    'chunkindex' => (int) $r->chunkindex,
                ];
            }
            foreach ($siblingsbycmid as $cmid => $bychunkindex) {
                ksort($bychunkindex);
                $siblingsbycmid[$cmid] = array_values($bychunkindex);
            }
        }
        return self::merge_parents($final, $siblingsbycmid, $returnscope, $windowsize, $maxchars);
    }

    /**
     * Fill in the `content` of already-selected chunks.
     *
     * Selection above runs on vectors alone, so chunk text is fetched once the
     * candidate set is small. Rows whose chunk has vanished between the two
     * queries (a concurrent reindex) are dropped rather than returned with
     * empty text, which would otherwise reach the model as a blank passage.
     *
     * @param array $rows Scored rows carrying an 'id'.
     * @return array Same rows, ordered as given, with 'content' populated.
     */
    public static function hydrate_content(array $rows): array {
        global $DB;

        if (empty($rows)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(
            array_map(fn($r) => (int) ($r['id'] ?? 0), $rows)
        )));
        if (empty($ids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $texts = $DB->get_records_select_menu(
            'local_ai_course_assistant_chunks',
            "id {$insql}",
            $params,
            '',
            'id, content'
        );

        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if (!isset($texts[$id]) || trim((string) $texts[$id]) === '') {
                continue;
            }
            $row['content'] = (string) $texts[$id];
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Stage 2: rerank the hydrated candidate set, or skip and say why.
     *
     * Split out of retrieve() so the gate is testable: retrieve() cannot be
     * exercised in a unit test because it makes a billable embedding call
     * first, which is exactly why the rerank decision went unmeasured for so
     * long. $reranker is injectable for the same reason — a test can pass a
     * counting double and assert that a gated-off query never reaches it.
     *
     * Returns null for "stage 2 did not produce a result", which the caller
     * treats identically however it happened: gated off, no API key, empty
     * response, or an exception. That collapse is deliberate — every one of
     * those degrades to the cosine top-k, which is a correct answer, just a
     * cheaper one.
     *
     * @param string $query The learner's query, as typed.
     * @param array  $scored Hydrated candidates, best cosine first.
     * @param int    $topk Number of rows to keep.
     * @param \local_ai_course_assistant\embedding_provider\voyage_reranker|null $reranker
     *               Injected reranker, or null to construct the configured one.
     * @param int    $courseid Course being retrieved from, for skip telemetry.
     * @return array|null Reranked rows, or null to keep the cosine ranking.
     */
    public static function apply_rerank(
        string $query,
        array $scored,
        int $topk,
        ?\local_ai_course_assistant\embedding_provider\voyage_reranker $reranker = null,
        int $courseid = 0
    ): ?array {
        if (empty($scored) || $topk <= 0) {
            return null;
        }

        $skip = self::rerank_skip_reason($query, $scored);
        if ($skip !== null) {
            // Recorded, not merely not-done: a gate whose only symptom is lower
            // spend is indistinguishable from a broken reranker.
            \local_ai_course_assistant\embedding_provider\voyage_reranker::log_skip($skip, $courseid);
            return null;
        }

        $rawcand = get_config('local_ai_course_assistant', 'rerank_candidates');
        $candidates = ($rawcand === false || $rawcand === '') ? 20 : (int) $rawcand;
        $candidates = max($topk, min($candidates, count($scored)));
        $stage1 = array_slice($scored, 0, $candidates);

        try {
            $reranker = $reranker
                ?? new \local_ai_course_assistant\embedding_provider\voyage_reranker();
            if (!$reranker->is_configured()) {
                return null;
            }
            $documents = array_map(fn($r) => $r['content'], $stage1);
            $reranked = $reranker->rerank($query, $documents, $topk);
            if (empty($reranked)) {
                return null;
            }
            $out = [];
            foreach ($reranked as $entry) {
                $idx = $entry['index'];
                if (isset($stage1[$idx])) {
                    $row = $stage1[$idx];
                    // Replace the cosine score with the rerank relevance score
                    // so downstream telemetry reflects the actual ranking
                    // signal used.
                    $row['score'] = $entry['score'];
                    $row['cosine_score'] = $stage1[$idx]['score'];
                    $out[] = $row;
                }
            }
            return empty($out) ? null : $out;
        } catch (\Throwable $e) {
            debugging('rag_retriever rerank failed, falling back to cosine top-k: '
                . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Why stage 2 should be skipped for this query, or null to run it.
     *
     * Two independent gates, cheapest first:
     *
     *  1. LENGTH (`rerank_min_query_chars`, default 50). Measured on the RAG
     *     fixture set: skipping the rerank on queries of 50 characters or fewer
     *     scored 67.7% recall against 67.2% for always-on, and cost about a
     *     third less. Short queries are keyword-shaped ("marginal cost",
     *     "chapter 3 quiz") and a cross-encoder has almost no extra signal to
     *     add over cosine on two or three words; longer queries are where its
     *     sentence-level understanding earns the call.
     *  2. MARGIN (`rerank_margin_threshold`) — the pre-existing ambiguity gate,
     *     see should_rerank().
     *
     * The reasons are returned rather than folded into one boolean so the
     * telemetry row can say WHICH gate fired; "reranks went down" on its own
     * does not distinguish a working gate from an expired API key.
     *
     * @param string $query The learner's query, as typed.
     * @param array $scored Candidates sorted by descending cosine score.
     * @return string|null Skip reason, or null when reranking should run.
     */
    public static function rerank_skip_reason(string $query, array $scored): ?string {
        if (!self::query_long_enough_to_rerank($query)) {
            return self::RERANK_SKIP_SHORT_QUERY;
        }
        if (!self::should_rerank($scored)) {
            return self::RERANK_SKIP_CONFIDENT;
        }
        return null;
    }

    /**
     * Whether the query is long enough to be worth a rerank call.
     *
     * Length is measured with mb_strlen on the TRIMMED query: the corpus is
     * 46-language, so counting bytes would gate a 20-character Japanese
     * question as if it were a 60-character English one, and trailing
     * whitespace must not buy a query a rerank it did not earn. An empty or
     * whitespace-only query measures 0 and is therefore always skipped.
     *
     * The threshold is a setting rather than a constant because there are no
     * code deploys after the next release; 0 (or negative) disables the length
     * gate entirely, matching how rerank_margin_threshold treats 0, so an
     * operator can revert to the pre-v7.4.0 behaviour from a form.
     *
     * Boundary: the setting is the largest length that is SKIPPED. At the
     * default 50, a 50-character query is skipped and a 51-character query is
     * reranked.
     *
     * @param string $query The learner's query, as typed.
     * @return bool True when the query clears the length gate.
     */
    public static function query_long_enough_to_rerank(string $query): bool {
        $raw = get_config('local_ai_course_assistant', 'rerank_min_query_chars');
        // Unset means "use the measured default"; an explicit 0 disables the
        // gate. Distinguishing the two matters, so test for false/'' first.
        $threshold = ($raw === false || $raw === '') ? self::RERANK_MIN_QUERY_CHARS_DEFAULT : (int) $raw;
        if ($threshold <= 0) {
            return true;
        }
        return mb_strlen(trim($query)) > $threshold;
    }

    /**
     * Whether this query is ambiguous enough to be worth reranking.
     *
     * The SECOND of the two stage-2 gates; callers should go through
     * rerank_skip_reason(), which applies the cheaper length gate first and
     * reports which one fired. Kept public and unchanged in behaviour because
     * it is the measured margin rule and has its own test.
     *
     * Measured 2026-08-01 over 1,008 queries across 16 courses: the cosine
     * margin between the top-1 and top-3 candidates predicts whether
     * reranking helps. In the most ambiguous decile reranking gained
     * +24.8 pp recall@3; in the least ambiguous decile it gained +0.0 pp,
     * because the embedding stage was already right 99% of the time.
     * Reranking a confident result is not merely wasted spend — it moved an
     * already-correct top-1 hit out of rank 1 in 12% of such cases.
     *
     * Gating at the default 0.086 kept recall@3 at 89.2% against 89.3% for
     * always-rerank while skipping ~30% of queries. The absolute *score* is
     * deliberately not used: it varies with course vocabulary, so a value
     * meaning "unsure" in one course means "confident" in another, and it
     * tested non-monotone.
     *
     * Set `rerank_margin_threshold` to 0 to disable the gate and rerank
     * every query, which is the pre-2026-08 behaviour.
     *
     * @param array $scored Candidates sorted by descending cosine score.
     * @return bool True when reranking should run.
     */
    public static function should_rerank(array $scored): bool {
        $raw = get_config('local_ai_course_assistant', 'rerank_margin_threshold');
        // Unset means "use the measured default"; an explicit 0 disables the
        // gate. Distinguishing the two matters, so test for false/'' first.
        $threshold = ($raw === false || $raw === '') ? 0.086 : (float) $raw;
        if ($threshold <= 0) {
            return true;
        }
        // The margin needs a third candidate to exist. When it does not, the
        // signal is unmeasurable rather than confident, so fall through to
        // reranking rather than let a missing measurement disable the feature.
        if (count($scored) < 3) {
            return true;
        }
        $margin = (float) $scored[0]['score'] - (float) $scored[2]['score'];
        return $margin < $threshold;
    }

    /**
     * Apply the relevance floor and current-page ordering boost to scored chunks.
     *
     * Pure function (no DB or provider) so it is unit-testable. Chunks scoring
     * below $minscore on raw cosine are dropped; the remainder are sorted by a
     * rank that adds $boost to chunks from $currentcmid. The boost is ordering
     * only — the floor compares the raw cosine score, so an irrelevant
     * current-page chunk is never force-kept.
     *
     * @param array $scored Rows with at least 'score' (float) and 'cmid' (int|null).
     * @param float $minscore Cosine floor in [0,1]; 0 disables the gate.
     * @param int   $currentcmid Current page course-module id (0 = none).
     * @param float $boost Ordering bonus added to current-page chunks.
     * @return array Filtered, rank-sorted rows (same shape as input).
     */
    public static function filter_and_rank(array $scored, float $minscore, int $currentcmid, float $boost): array {
        if ($minscore > 0.0) {
            $scored = array_values(array_filter(
                $scored,
                fn($r) => (float) ($r['score'] ?? 0.0) >= $minscore
            ));
        }
        if (empty($scored)) {
            return [];
        }
        $rank = function (array $r) use ($currentcmid, $boost): float {
            $bonus = ($currentcmid > 0 && (int) ($r['cmid'] ?? 0) === $currentcmid) ? $boost : 0.0;
            return (float) ($r['score'] ?? 0.0) + $bonus;
        };
        usort($scored, fn($a, $b) => $rank($b) <=> $rank($a));
        return $scored;
    }

    /**
     * Constrain floor-passed, rank-sorted chunks to the current document.
     *
     * Pure function (no DB or provider) so it is unit-testable. When the learner
     * is viewing a specific document ($currentcmid > 0):
     *  - 'document_first': if that document contributed any chunks to the set,
     *    return only those; otherwise return the full set (course-wide fallback).
     *  - 'document_only': return only that document's chunks, or an empty array if
     *    it contributed none (no fallback — the caller then grounds on general
     *    knowledge rather than citing unrelated pages).
     *  - 'course' (or $currentcmid <= 0): return the set unchanged.
     *
     * Input is assumed already sorted by descending rank; the returned subset
     * preserves that order.
     *
     * @param array  $ranked      Rows with at least 'cmid' (int|null), rank-sorted.
     * @param int    $currentcmid Current document course-module id (0 = none).
     * @param string $scope       'document_first' | 'document_only' | 'course'.
     * @return array The scoped rows (same shape as input).
     */
    public static function scope_to_document(array $ranked, int $currentcmid, string $scope): array {
        if ($currentcmid <= 0 || $scope === 'course') {
            return $ranked;
        }

        // v7.2.7: site-wide FAQ rows are exempt from the document filter.
        //
        // They carry cmid = 0 because they belong to no module, so the filter
        // below discarded every one of them as soon as the current page
        // contributed a single chunk -- and under 'document_only' it discarded
        // them even when it did not. That is a hard filter, not a re-rank, and
        // it runs before hydration, so nothing downstream could put them back.
        // Combined with context_builder dropping the inline copy on the strength
        // of the FAQ being retrievable, a turn started from a module page -- the
        // ordinary case, and exactly where a learner asks "how do I get my
        // certificate?" -- reached the model with the FAQ in neither the prompt
        // nor the retrieved set.
        // v7.2.9 (S14): course-level rows are exempt for the same reason. The
        // course summary and section summaries carry cmid = null (the schema
        // documents it as "null = course-level content"), which reads back as 0
        // here, so without this they would be discarded on exactly the
        // module-page turns where a learner asks about the course as a whole.
        $faqtype = \local_ai_course_assistant\faq_manager::MODTYPE;
        $exempt = [$faqtype, 'course'];

        $docpresent = false;
        foreach ($ranked as $row) {
            if (in_array($row['modtype'] ?? '', $exempt, true)) {
                continue;
            }
            if ((int) ($row['cmid'] ?? 0) === $currentcmid) {
                $docpresent = true;
                break;
            }
        }

        // Single pass, so the caller's rank order survives across both partitions.
        $out = [];
        foreach ($ranked as $row) {
            if (in_array($row['modtype'] ?? '', $exempt, true)) {
                $out[] = $row;
                continue;
            }
            if ($docpresent) {
                if ((int) ($row['cmid'] ?? 0) === $currentcmid) {
                    $out[] = $row;
                }
            } else if ($scope !== 'document_only') {
                // The current document contributed no chunks to the set.
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Expand selected chunks into parent units (neighbor window or whole page),
     * deduplicated by cmid, size-capped with fallback. Pure (no DB/provider).
     *
     * @param array  $topkrows       Final selected rows (post-rerank), rank-sorted.
     * @param array  $siblingsbycmid [cmid => [ ['content'=>string,'chunkindex'=>int], ... ]]
     * @param string $mode           'window' | 'page'.
     * @param int    $windowsize     Neighbors each side for 'window' mode.
     * @param int    $maxchars       Per-passage cap; over-cap pages fall back.
     * @return array Expanded rows (same shape + 'expand_mode', 'expanded_from').
     */
    public static function merge_parents(
        array $topkrows,
        array $siblingsbycmid,
        string $mode,
        int $windowsize,
        int $maxchars
    ): array {
        $out = [];
        $seen = [];
        foreach ($topkrows as $row) {
            $cmid = (int) ($row['cmid'] ?? 0);
            if ($cmid <= 0 || empty($siblingsbycmid[$cmid])) {
                $out[] = $row;
                continue;
            }
            if (isset($seen[$cmid])) {
                continue; // page already emitted from a higher-ranked hit
            }
            $seen[$cmid] = true;

            // v7.4.5: one entry per chunkindex. A migrated index holds the same
            // logical chunk under two embed_models, and content_chunker's
            // overlap dedupe caps at 100 words -- far short of a ~1,000
            // character chunk -- so a duplicate that reaches here is emitted
            // twice as garbled near-duplicate text that also eats the
            // rag_parent_max_chars budget. The caller filters by embedding
            // space; this is the second line of defence, and it is here because
            // merge_parents is the pure, testable half.
            $siblings = [];
            foreach ($siblingsbycmid[$cmid] as $s) {
                $siblings[(int) $s['chunkindex']] ??= $s;
            }
            $siblings = array_values($siblings);
            usort($siblings, fn($a, $b) => ((int) $a['chunkindex']) <=> ((int) $b['chunkindex']));
            $center = (int) ($row['chunkindex'] ?? 0);

            $pick = function (int $win) use ($siblings, $center) {
                return array_values(array_filter(
                    $siblings,
                    fn($s) => abs(((int) $s['chunkindex']) - $center) <= $win
                ));
            };

            $selected = ($mode === 'window') ? $pick($windowsize) : $siblings;
            $merged = content_chunker::reconstruct(array_map(fn($s) => (string) $s['content'], $selected));

            // Size cap: page -> window -> single matched chunk.
            if (mb_strlen($merged) > $maxchars) {
                $selected = $pick(max(1, $windowsize));
                $merged = content_chunker::reconstruct(array_map(fn($s) => (string) $s['content'], $selected));
                if (mb_strlen($merged) > $maxchars) {
                    $selected = [['content' => (string) $row['content'], 'chunkindex' => $center]];
                    $merged = (string) $row['content'];
                }
            }

            $newrow = $row;
            $newrow['content']       = $merged;
            $newrow['expand_mode']   = $mode;
            $newrow['expanded_from'] = count($selected);
            $out[] = $newrow;
        }
        return $out;
    }

    /**
     * Pack a float vector into the compact storage form.
     *
     * `g` is little-endian float32. Embeddings arrive as float32 from every
     * provider we use, so this is lossless in practice -- verified on dev over
     * a full course: max element error 0.0 and max cosine-score delta 0.0.
     *
     * @param array $vec
     * @param string $dtype One of embedding_compat::DTYPES. Defaults to float so
     *                      every pre-quantization caller keeps its behaviour.
     * @return string Binary blob.
     */
    public static function pack_vector(array $vec, string $dtype = 'float'): string {
        $dtype = \local_ai_course_assistant\embedding_compat::normalize_dtype($dtype);
        $vals = array_values($vec);
        if ($dtype === \local_ai_course_assistant\embedding_compat::DTYPE_INT8) {
            // Signed bytes, one per dimension. Clamped rather than trusted:
            // pack('c') on an out-of-range value wraps silently, turning a 130
            // into -126 and inverting that dimension's contribution.
            $ints = [];
            foreach ($vals as $v) {
                $i = (int) round((float) $v);
                $ints[] = max(-128, min(127, $i));
            }
            return $ints ? pack('c*', ...$ints) : '';
        }
        if ($dtype === \local_ai_course_assistant\embedding_compat::DTYPE_BINARY) {
            // Already bit-packed by the API: each returned value is one byte
            // holding eight dimensions. Stored verbatim as unsigned bytes.
            $ints = [];
            foreach ($vals as $v) {
                $i = (int) round((float) $v);
                // The API may hand these back signed (-128..127) or unsigned
                // (0..255); both describe the same bits. Normalize to unsigned
                // so the stored blob is one canonical form.
                $ints[] = ($i < 0) ? ($i + 256) & 0xFF : $i & 0xFF;
            }
            return $ints ? pack('C*', ...$ints) : '';
        }
        return pack('g*', ...array_map('floatval', $vals));
    }

    /**
     * Decide whether a stored chunk row may be scored against this query.
     *
     * Pure and static so the two refusal rules can be tested without a live
     * embedding provider — retrieve() cannot be exercised in a unit test
     * because it makes a billable API call before it reaches this logic.
     *
     * Returns:
     *  - 'ok'    scoreable
     *  - 'model' the row's embedding model is not comparable with the query's
     *  - 'dtype' the row's encoding does not match the query's
     *
     * A row with no recorded model is treated as usable: it predates the column
     * and was written by whichever model was configured then, which is the best
     * assumption available and preserves existing indexes.
     *
     * @param string $querymodel Model that produced the query vector.
     * @param string $configureddtype Encoding the query is prepared for.
     * @param string|null $rowmodel Model recorded on the chunk, if any.
     * @param string|null $rowdtype Encoding recorded on the chunk, if any.
     * @return string One of 'ok', 'model', 'dtype'.
     */
    public static function classify_row(
        string $querymodel,
        string $configureddtype,
        ?string $rowmodel,
        ?string $rowdtype
    ): string {
        $rowmodel = trim((string) $rowmodel);
        if (
            $rowmodel !== '' && trim($querymodel) !== ''
            && !\local_ai_course_assistant\embedding_compat::are_comparable($querymodel, $rowmodel)
        ) {
            return 'model';
        }
        // Encoding is a stricter test than the model one: binary is scored by
        // Hamming distance on bits and float/int8 by cosine on numbers, so a
        // mismatch is not a degraded comparison but a meaningless one.
        $binaryrow = (\local_ai_course_assistant\embedding_compat::normalize_dtype($rowdtype)
            === \local_ai_course_assistant\embedding_compat::DTYPE_BINARY);
        $binaryquery = (\local_ai_course_assistant\embedding_compat::normalize_dtype($configureddtype)
            === \local_ai_course_assistant\embedding_compat::DTYPE_BINARY);
        if ($binaryrow !== $binaryquery) {
            return 'dtype';
        }
        return 'ok';
    }

    /**
     * Precomputed population count for every byte value.
     *
     * Binary vectors are scored by Hamming distance, which needs the number of
     * set bits in each XOR byte. A 256-entry table turns that into an array
     * lookup: on a 1024-dimension vector this is 128 lookups per chunk against
     * 1024 float multiplications, so quantizing to binary makes scoring cheaper
     * as well as smaller.
     *
     * @return int[]
     */
    private static function popcount_table(): array {
        static $table = null;
        if ($table === null) {
            $table = [];
            for ($i = 0; $i < 256; $i++) {
                $c = 0;
                $v = $i;
                while ($v) {
                    $c += $v & 1;
                    $v >>= 1;
                }
                $table[$i] = $c;
            }
        }
        return $table;
    }

    /**
     * Similarity between two bit-packed vectors, on the same scale as cosine.
     *
     * Cosine is not defined on bits, so this uses the standard substitute: the
     * normalized dot product of the sign vectors, which for equal-length bit
     * strings is (bits - 2 * hamming) / bits. That lands in [-1, 1] with
     * unrelated vectors near 0 and identical vectors at 1 — the same scale
     * cosine occupies in practice.
     *
     * Returning that raw value matters, and an earlier version of this method
     * got it wrong by rescaling to [0, 1]. Under that mapping two unrelated
     * vectors scored 0.5 rather than 0, so every chunk cleared the default
     * rag_min_similarity of 0.25 and the relevance floor silently stopped
     * filtering anything. Measured on live vectors, the rescaled scores for a
     * relevant and two irrelevant documents were 0.659 / 0.523 / 0.506, against
     * float cosine's 0.499 / 0.062 / 0.019; the raw form gives 0.318 / 0.045 /
     * 0.012, which the same floor handles correctly.
     *
     * @param string $a Packed bytes.
     * @param string $b Packed bytes.
     * @return float In [-1, 1].
     */
    public static function binary_similarity(string $a, string $b): float {
        $len = strlen($a);
        // Different lengths mean different widths, which means the two vectors
        // are not from the same space. Returning 0 keeps such a row out of the
        // results instead of scoring it against a truncated comparison.
        if ($len === 0 || $len !== strlen($b)) {
            return 0.0;
        }
        $table = self::popcount_table();
        $xor = $a ^ $b;
        $hamming = 0;
        for ($i = 0; $i < $len; $i++) {
            $hamming += $table[ord($xor[$i])];
        }
        $bits = $len * 8;
        // Raw sign-vector cosine, NOT rescaled to [0, 1]: unrelated vectors must
        // score near 0 so the shared relevance floor keeps working.
        return 1.0 - (2.0 * $hamming / $bits);
    }

    /**
     * Decode a stored vector, preferring the packed binary form.
     *
     * Falls back to the legacy JSON column so a partially-backfilled index
     * keeps working: rows converted by the backfill read fast, rows not yet
     * converted still read correctly. Once every row has a binary vector the
     * JSON column can be dropped in a later release.
     *
     * @param string|null $bin Packed vector blob, or null.
     * @param string|null $json Legacy JSON array, or null.
     * @param string $dtype How $bin is encoded; one of embedding_compat::DTYPES.
     *                      Defaults to float, which is what every row written
     *                      before the embed_dtype column contains.
     * @return array Float vector, empty on failure and empty for binary — see
     *               the note above on why binary is not expanded here.
     */
    public static function decode_vector(?string $bin, ?string $json, string $dtype = 'float'): array {
        $dtype = \local_ai_course_assistant\embedding_compat::normalize_dtype($dtype);

        if ($bin !== null && $bin !== '') {
            if ($dtype === \local_ai_course_assistant\embedding_compat::DTYPE_INT8) {
                // One signed byte per dimension, so any length is structurally
                // valid; there is no alignment check to make.
                $vec = unpack('c*', $bin);
                if (is_array($vec) && !empty($vec)) {
                    // Returned as-is. Cosine is scale-invariant, so int8
                    // magnitudes score identically to their dequantized
                    // counterparts without a multiply per element.
                    return array_map('floatval', array_values($vec));
                }
            } else if ($dtype === \local_ai_course_assistant\embedding_compat::DTYPE_BINARY) {
                // Binary vectors are NOT expanded here. Scoring compares packed
                // bytes directly via binary_similarity(), which is the whole
                // point of the encoding: expanding 128 bytes into 1024 floats
                // would throw away both the memory and the speed win.
                //
                // There is no separate accessor: callers that need the packed
                // bytes read the embedding_bin column directly and hand it to
                // binary_similarity(), as retrieve() does. An earlier version of
                // this comment pointed at a decode_binary_vector() that has never
                // existed, which sent readers looking for a method rather than
                // the column.
                return [];
            } else if (strlen($bin) % 4 === 0) {
                // A truncated blob would silently yield a short vector and score
                // nonsense, so require a whole number of float32s.
                $vec = unpack('g*', $bin);
                if (is_array($vec) && !empty($vec)) {
                    return array_values($vec);
                }
            }
            // Warn once per request. A corrupt column would otherwise emit
            // this for every chunk in the course -- thousands of identical
            // lines that bury the signal they are meant to raise.
            static $warned = false;
            if (!$warned) {
                $warned = true;
                debugging(
                    'rag_retriever: unreadable embedding_bin, falling back to JSON. '
                    . 'Re-run admin/cli/backfill_embedding_bin.php --verify',
                    DEBUG_DEVELOPER
                );
            }
        }
        if ($json !== null && $json !== '') {
            $vec = json_decode($json, true);
            if (is_array($vec) && !empty($vec)) {
                return $vec;
            }
        }
        return [];
    }

    /**
     * Cosine similarity against a query whose norm is already known.
     *
     * Equivalent to cosine($query, $vec) but skips recomputing the query norm
     * for every chunk. On a 2,000-chunk course that removes roughly a third of
     * the arithmetic in the scoring loop.
     *
     * @param array $query Query vector.
     * @param float $qnorm Precomputed sqrt(sum(query[i]^2)).
     * @param array $vec Chunk vector.
     * @return float
     */
    private static function cosine_against_query(array $query, float $qnorm, array $vec): float {
        if ($qnorm == 0.0) {
            return 0.0;
        }
        $dot = 0.0;
        $vnorm = 0.0;
        $len = count($query);
        for ($i = 0; $i < $len; $i++) {
            $vi = (float) ($vec[$i] ?? 0.0);
            $dot += $query[$i] * $vi;
            $vnorm += $vi * $vi;
        }
        if ($vnorm == 0.0) {
            return 0.0;
        }
        return $dot / ($qnorm * sqrt($vnorm));
    }

    /**
     * Compute cosine similarity between two equal-length float vectors.
     *
     * @param float[] $a
     * @param float[] $b
     * @return float Value in [-1, 1]; returns 0.0 if either vector has zero norm.
     */
    private static function cosine(array $a, array $b): float {
        $dot = $norma = $normb = 0.0;
        $len = count($a);
        for ($i = 0; $i < $len; $i++) {
            $ai     = (float) ($a[$i] ?? 0.0);
            $bi     = (float) ($b[$i] ?? 0.0);
            $dot   += $ai * $bi;
            $norma += $ai * $ai;
            $normb += $bi * $bi;
        }
        if ($norma == 0.0 || $normb == 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($norma) * sqrt($normb));
    }
}
