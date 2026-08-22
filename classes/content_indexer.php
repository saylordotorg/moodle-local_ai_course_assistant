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
 * Orchestrates content extraction → chunking → embedding → DB storage.
 *
 * Change detection: chunks are only re-embedded when their sha1 hash changes,
 * so incremental re-indexing is cheap.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_indexer {
    /**
     * How many stale chunk rows are removed per DELETE statement. Bounded so
     * the IN() list stays well inside every DB's parameter limit.
     */
    private const DELETE_BATCH = 200;

    /**
     * Index all content in a course.
     *
     * For each module:
     *  1. Extract text via content_extractor.
     *  2. Chunk via content_chunker.
     *  3. Check DB for existing chunks with same sha1 — skip unchanged ones.
     *  4. Embed new/changed chunks and upsert into DB.
     *  5. Delete DB chunks that no longer exist in the source.
     *
     * @param int  $courseid
     * @param bool $force    If true, re-embed all chunks regardless of hash.
     * @return array ['indexed' => int, 'skipped' => int, 'errors' => int]
     */
    public static function index_course(int $courseid, bool $force = false): array {
        global $DB;

        $rawchunksize = get_config('local_ai_course_assistant', 'rag_chunksize');
        $chunksize = ($rawchunksize === false || $rawchunksize === '') ? 400 : (int) $rawchunksize;

        $extract = content_extractor::extract_course_modules_with_skips($courseid);
        $modules = $extract['modules'];

        // 'no_content' lists supported documents that produced no indexable text
        // (empty or shorter than MIN_CHARS) and were therefore never chunked —
        // surfaced by rag_admin so a page that silently produced no chunk is
        // visible instead of being missed.
        $stats = [
            'indexed'    => 0,
            'skipped'    => 0,
            'errors'     => 0,
            'sources'    => count($modules),
            'no_content' => $extract['skipped'],
        ];

        // Resolve the embedding provider up front. A missing/unknown provider
        // or misconfiguration must surface as a clear fatal reason rather than
        // a silently empty index — this is the "reindex produced zero chunks
        // with no explanation" failure mode admins hit when embed_apikey is
        // unset. The reason is returned to the caller (rag_admin) to display.
        try {
            $provider = base_embedding_provider::create_from_config();
        } catch (\Throwable $e) {
            $stats['fatal'] = $e->getMessage();
            return $stats;
        }
        // v5.11.0: ask the provider its actual model so non-OpenAI vendors
        // (e.g. Voyage) don't get vectors mis-labelled as text-embedding-3-small.
        $modelname = $provider->get_model();
        // Encoding for the vectors this run writes. effective_dtype() falls
        // back to float when the provider cannot actually return quantized
        // vectors, so a stored dtype always matches the stored bytes.
        $dtype = $provider->effective_dtype();
        // Contextualized-chunk models need the whole document in one call, so
        // the per-chunk embed() path does not apply to them.
        $iscontextualized = ($provider instanceof \local_ai_course_assistant\embedding_provider\voyage_embedding_provider)
            && $provider->is_contextualized();

        // Budget gate. Indexing is the bulk of RAG spend, so refuse to start a
        // run that is already over a configured 'rag' cap. Unreachable unless an
        // admin set a cap: an unset cap is 0, which check() treats as unlimited.
        // Returning here also means the stale-chunk prune below never runs, which
        // matters: pruning on a run that indexed nothing would delete the whole
        // existing index.
        if (spend_guard::check(0, 'rag') === spend_guard::CAP_BLOCKED) {
            $stats['cap_blocked'] = true;
            $stats['fatal'] = get_string('rag_cap_blocked', 'local_ai_course_assistant');
            return $stats;
        }

        // Track all chunk content-hashes we encounter (for cleanup later).
        $seenhashes = [];
        $capblocked = false;

        foreach ($modules as $mod) {
            if ($capblocked) {
                break;
            }
            try {
                $chunks = content_chunker::chunk(
                    $mod['text'],
                    $mod['title'],
                    $mod['section'],
                    $chunksize
                );

                // Contextualized models embed a whole document at once so each
                // chunk vector encodes its surroundings, so the vectors for this
                // module are computed in one call rather than per chunk. Filled
                // lazily on the first chunk that actually needs embedding: a
                // module whose content is unchanged skips the call entirely.
                //
                // Note this deliberately embeds EVERY chunk of the module even
                // when only some need storing. That is not waste — it is the
                // requirement. Embedding a subset would give the remaining
                // chunks a different, poorer context than a full-document pass,
                // so the vectors would not match the rest of the index.
                $ctxvectors = null;

                foreach ($chunks as $idx => $chunk) {
                    $hash = $chunk['contenthash'];
                    $seenhashes[] = $hash;

                    // One indexed lookup per chunk, on the admin/cron reindex
                    // path only. Deliberately not batched into a single
                    // hash->row map for the course: get_record() here resolves
                    // duplicate content hashes (the same boilerplate chunk in
                    // two modules) to one arbitrary row, and a grouped preload
                    // would quietly change which row wins. The embed() call
                    // below dominates this query whenever the hash misses.
                    // Check for existing identical chunk.
                    if (!$force) {
                        $existing = $DB->get_record('local_ai_course_assistant_chunks', [
                            'courseid'    => $courseid,
                            'contenthash' => $hash,
                        ], 'id, embedding, embedding_bin, embed_model, embed_dtype');

                        // Either column proves the chunk is embedded. Testing only the
                        // JSON column made every quantized row look unembedded, so a
                        // reindex re-embedded the entire course on every run and the
                        // hash-skip optimization silently stopped working.
                        //
                        // But identical content is NOT sufficient on its own: the
                        // encoding has to match too. The hash covers the text, not
                        // the model or the dtype that turned it into a vector, so a
                        // content-only skip made "change embed_dtype, then reindex"
                        // a silent no-op — the documented way to adopt quantization
                        // did nothing, and for binary it left a float index that the
                        // retriever then refused row by row, emptying retrieval with
                        // only a DEBUG_NORMAL line to show for it. Re-embed whenever
                        // the stored encoding differs from what this run writes.
                        $sameencoding = $existing
                            && (string) $existing->embed_model === (string) $modelname
                            && embedding_compat::normalize_dtype($existing->embed_dtype ?? null) === $dtype;

                        if ($existing && $sameencoding
                                && (!empty($existing->embedding) || !empty($existing->embedding_bin))) {
                            $stats['skipped']++;
                            continue;
                        }
                    }

                    // Re-check the cap as the run proceeds, so a long reindex stops
                    // when it crosses the cap rather than only being refused at
                    // the start. check() caches for 60s, so this is not a query
                    // per chunk. Stopping partway deliberately sets cap_blocked,
                    // which suppresses the stale-chunk prune below: the hashes we
                    // never reached would otherwise look stale and be deleted.
                    if (spend_guard::check(0, 'rag') === spend_guard::CAP_BLOCKED) {
                        $capblocked = true;
                        $stats['cap_blocked'] = true;
                        break;
                    }

                    // Embed this chunk.
                    if ($iscontextualized) {
                        if ($ctxvectors === null) {
                            $texts = [];
                            foreach ($chunks as $c) {
                                $texts[] = (string) $c['content'];
                            }
                            $groups = $provider->embed_contextualized([$texts], 'document');
                            $ctxvectors = array_values($groups)[0] ?? [];
                        }
                        if (!isset($ctxvectors[$idx]) || !is_array($ctxvectors[$idx])) {
                            // One vector per chunk is the contract. A gap means
                            // the response did not line up with what was sent,
                            // and storing a wrong-chunk vector would be worse
                            // than failing this module.
                            throw new \moodle_exception(
                                'chat:error',
                                'local_ai_course_assistant',
                                '',
                                null,
                                sprintf(
                                    'contextualized embedding missing vector for chunk %d of %d in cmid %d',
                                    $idx,
                                    count($chunks),
                                    (int) $mod['cmid']
                                )
                            );
                        }
                        $vector = $ctxvectors[$idx];
                    } else {
                        $vector = $provider->embed($chunk['content']);
                    }

                    // Upsert: delete any old row for this cmid+chunkindex first.
                    $DB->delete_records('local_ai_course_assistant_chunks', [
                        'courseid'   => $courseid,
                        'cmid'       => $mod['cmid'],
                        'chunkindex' => $idx,
                    ]);

                    // Neutralize prompt-injection markers embedded in course
                    // content before the chunk is stored. Role delimiters and
                    // system-instruction markers in PDFs/SCORM/etc would
                    // otherwise re-enter the system prompt at retrieval time.
                    $sanitized = \local_ai_course_assistant\security::sanitize_rag_chunk($chunk['content']);
                    if ($sanitized['neutralized'] > 0) {
                        $stats['injection_patterns_neutralized'] =
                            ($stats['injection_patterns_neutralized'] ?? 0) + $sanitized['neutralized'];
                    }

                    $record = new \stdClass();
                    $record->courseid    = $courseid;
                    $record->cmid        = $mod['cmid'];
                    $record->modtype     = $mod['modtype'];
                    $record->chunkindex  = $idx;
                    $record->content     = $sanitized['text'];
                    $record->contenthash = $hash;
                    // Float indexes write both forms during the transition:
                    // the packed vector is what retrieval reads, and the JSON
                    // copy keeps a rollback to the previous release possible
                    // without a reindex. A later release drops the JSON column.
                    //
                    // Quantized indexes write only the packed form. A JSON copy
                    // of int8 or bit-packed values would be larger than the
                    // binary it duplicates, which defeats the entire point of
                    // quantizing. The consequence is deliberate and documented:
                    // changing embed_dtype requires a reindex, and so does
                    // rolling back to a release that cannot read the encoding.
                    $isfloat = ($dtype === \local_ai_course_assistant\embedding_compat::DTYPE_FLOAT);
                    $record->embedding     = $isfloat ? json_encode($vector) : null;
                    $record->embedding_bin = rag_retriever::pack_vector($vector, $dtype);
                    $record->embed_model   = $modelname;
                    $record->embed_dtype   = $dtype;
                    $record->timecreated = time();
                    $record->timeindexed = time();

                    $DB->insert_record('local_ai_course_assistant_chunks', $record);
                    $stats['indexed']++;
                }
            } catch (\Throwable $e) {
                // \Throwable, not \Exception: a TypeError or OOM-class Error in
                // an embedding provider must count as a per-module error (so the
                // stale-chunk cleanup guard below stays correct) rather than
                // abort the whole reindex and risk leaving a half-built index.
                if (empty($stats['embed_error'])) {
                    // Keep the first failure so rag_admin can explain a
                    // zero-chunk outcome (bad API key, provider down, ...).
                    $stats['embed_error'] = $e->getMessage();
                }
                debugging(
                    'RAG indexing error for cmid=' . $mod['cmid'] . ': ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
                $stats['errors']++;
            }
        }

        // Remove stale chunks: DB rows for this course whose hash is no longer
        // in source. Only prune on a clean run (no embed errors) — otherwise a
        // transient embedding outage, where every embed() throws and nothing is
        // re-inserted, would silently delete a previously-good index.
        // `cap_blocked` joins `errors` as a reason not to prune: a run cut short by
        // the budget cap never reached the remaining chunks, so their hashes are
        // absent from $seenhashes and would be misread as stale and deleted.
        if ($stats['errors'] === 0 && empty($stats['cap_blocked']) && !empty($seenhashes)) {
            self::prune_stale_chunks($courseid, $seenhashes);
        } else if ($stats['sources'] === 0) {
            // Genuinely no extractable content in the course — clear the index.
            $DB->delete_records('local_ai_course_assistant_chunks', ['courseid' => $courseid]);
        }
        // Otherwise (sources existed but embeds errored) leave the existing
        // index untouched rather than risk wiping good data on a flaky run.

        // The retriever caches decoded vectors for the life of the process.
        // Reindexing inside a long-running CLI would otherwise keep scoring
        // against the vectors this run just replaced.
        rag_retriever::flush_cache($courseid);

        return $stats;
    }

    /**
     * Delete this course's chunk rows whose content hash is no longer present
     * in the freshly-extracted source.
     *
     * @param int $courseid
     * @param string[] $seenhashes Content hashes seen in this indexing run.
     */
    private static function prune_stale_chunks(int $courseid, array $seenhashes): void {
        global $DB;

        // O(1) membership via a hash set, not in_array (which was O(n) per
        // row, O(n^2) overall on large courses). Stream rows with a
        // recordset so the whole chunk table is never held in memory.
        $seenset = array_flip($seenhashes);
        $stale = [];
        $rs = $DB->get_recordset(
            'local_ai_course_assistant_chunks',
            ['courseid' => $courseid],
            '',
            'id, contenthash'
        );
        try {
            foreach ($rs as $row) {
                if (!isset($seenset[$row->contenthash])) {
                    // Collect and delete in batches below: one DELETE per stale
                    // row meant a statement per removed chunk, which scales with
                    // the index, not with what actually changed.
                    $stale[] = (int) $row->id;
                    if (count($stale) >= self::DELETE_BATCH) {
                        self::delete_chunks($stale);
                        $stale = [];
                    }
                }
            }
        } finally {
            $rs->close();
        }
        if (!empty($stale)) {
            self::delete_chunks($stale);
        }
    }

    /**
     * Delete a batch of chunk rows by id in a single statement.
     *
     * @param int[] $ids
     */
    private static function delete_chunks(array $ids): void {
        global $DB;
        if (empty($ids)) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'cid');
        $DB->delete_records_select('local_ai_course_assistant_chunks', "id {$insql}", $params);
    }

    /**
     * Re-index a single course module.
     *
     * @param int  $cmid
     * @param bool $force Re-embed even if hash matches.
     * @return bool True on success.
     */
    public static function index_module(int $cmid, bool $force = false): bool {
        global $DB;

        $mod = content_extractor::extract_module($cmid);
        if ($mod === null) {
            // Nothing extractable — delete any stale chunks.
            $DB->delete_records('local_ai_course_assistant_chunks', ['cmid' => $cmid]);
            rag_retriever::flush_cache();
            return true;
        }

        $cmrec    = $DB->get_record('course_modules', ['id' => $cmid], 'course', MUST_EXIST);
        $courseid = (int) $cmrec->course;

        // Get section name.
        $modinfo = get_fast_modinfo($courseid);
        $cm      = $modinfo->get_cm($cmid);
        $section = '';
        foreach ($modinfo->get_section_info_all() as $s) {
            if (!empty($modinfo->sections[$s->section]) && in_array($cmid, $modinfo->sections[$s->section], false)) {
                $section = get_section_name($courseid, $s);
                break;
            }
        }

        $rawchunksize = get_config('local_ai_course_assistant', 'rag_chunksize');
        $chunksize = ($rawchunksize === false || $rawchunksize === '') ? 400 : (int) $rawchunksize;
        $provider  = base_embedding_provider::create_from_config();
        // v5.11.0: ask the provider its actual model so non-OpenAI vendors
        // (e.g. Voyage) don't get vectors mis-labelled as text-embedding-3-small.
        $modelname = $provider->get_model();
        // v7.0.3: and its encoding, for the same reason — a row whose recorded
        // dtype disagreed with its stored bytes would be unscoreable. This path
        // is the one auto_reindex_rag_drifted uses, so omitting it here would
        // leave drifted modules writing float bytes labelled as quantized.
        $dtype = $provider->effective_dtype();

        $chunks = content_chunker::chunk($mod['text'], $mod['title'], $section, $chunksize);

        // Delete existing chunks for this module.
        $DB->delete_records('local_ai_course_assistant_chunks', [
            'courseid' => $courseid,
            'cmid'     => $cmid,
        ]);

        foreach ($chunks as $idx => $chunk) {
            if (!$force) {
                $existing = $DB->get_record('local_ai_course_assistant_chunks', [
                    'courseid'    => $courseid,
                    'contenthash' => $chunk['contenthash'],
                ], 'id, embedding, embedding_bin');
                // Either column proves the chunk is embedded. Testing only the
                // JSON column made every quantized row look unembedded, so a
                // reindex re-embedded the entire course on every run and the
                // hash-skip optimization silently stopped working.
                if ($existing && (!empty($existing->embedding) || !empty($existing->embedding_bin))) {
                    // WARNING, do not enable this path without rewriting it. It is
                    // currently unreachable: the only caller of index_module() is
                    // the auto_reindex_rag_drifted task, which passes $force=true.
                    // As written it would fail or insert a broken row, because the
                    // record was selected as 'id, embedding' only, so courseid,
                    // modtype, content, contenthash and timecreated (all NOT NULL)
                    // are absent, and embedding_bin would be dropped as well. Left
                    // as-is rather than half-fixed, since deciding what a reuse
                    // path should copy is a real change, not a tidy-up.
                    $existing->cmid       = $cmid;
                    $existing->chunkindex = $idx;
                    $DB->insert_record('local_ai_course_assistant_chunks', $existing);
                    continue;
                }
            }

            $vector = $provider->embed($chunk['content']);

            $record = new \stdClass();
            $record->courseid    = $courseid;
            $record->cmid        = $cmid;
            $record->modtype     = $mod['modtype'];
            $record->chunkindex  = $idx;
            $record->content     = $chunk['content'];
            $record->contenthash = $chunk['contenthash'];
            // Write BOTH forms, exactly as index_course() does. Omitting the
            // packed vector here silently un-converted rows: this is the path the
            // auto_reindex_rag_drifted scheduled task uses, so a site that had
            // been backfilled drifted back toward the slow JSON decode every time
            // a module's content changed, with nothing to show it had happened
            // (retrieval falls back to JSON per row, so it stays correct, just
            // slower). Any future writer of this table must set both columns
            // until the JSON column is dropped.
            $isfloat = ($dtype === \local_ai_course_assistant\embedding_compat::DTYPE_FLOAT);
            $record->embedding     = $isfloat ? json_encode($vector) : null;
            $record->embedding_bin = rag_retriever::pack_vector($vector, $dtype);
            $record->embed_model   = $modelname;
            $record->embed_dtype   = $dtype;
            $record->timecreated = time();
            $record->timeindexed = time();

            $DB->insert_record('local_ai_course_assistant_chunks', $record);
        }

        rag_retriever::flush_cache();
        return true;
    }

    /**
     * Check whether a course has any indexed (embedded) chunks.
     *
     * @param int $courseid
     * @return bool
     */
    public static function is_course_indexed(int $courseid): bool {
        global $DB;
        // Either column proves the course is indexed, matching the predicate
        // rag_retriever uses to read vectors. Testing only the JSON column made
        // a quantized index (int8/binary, where that column is null on every
        // row) look permanently unindexed, so both chat paths re-extracted and
        // re-chunked the whole course before every single reply — embedding
        // nothing, reporting "indexed 0, skipped N", and showing up only as
        // latency.
        return $DB->record_exists_select(
            'local_ai_course_assistant_chunks',
            'courseid = :courseid AND (embedding IS NOT NULL OR embedding_bin IS NOT NULL)',
            ['courseid' => $courseid]
        );
    }

    /**
     * Delete all indexed chunks for a course.
     *
     * @param int $courseid
     */
    public static function delete_course_index(int $courseid): void {
        global $DB;
        $DB->delete_records('local_ai_course_assistant_chunks', ['courseid' => $courseid]);
        rag_retriever::flush_cache($courseid);
    }
}
