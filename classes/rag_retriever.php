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
 * Performance: cosine similarity in PHP is fast for < ~2000 chunks.
 * For larger corpora consider a vector DB or pgvector extension.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rag_retriever {

    /**
     * Retrieve the top-k most relevant chunks for a user query.
     *
     * @param int    $courseid
     * @param string $query    The user's message / question.
     * @param int    $topk     Number of chunks to return.
     * @param int    $currentcmid Course-module id of the page the learner is on
     *                            (0 if none); its chunks get a small ordering
     *                            boost so "explain this" grounds on the page.
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

        // Static cache of decoded embeddings — avoids re-decoding JSON on
        // subsequent RAG queries within the same PHP request.
        static $embedding_cache = [];
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

        // Embed the query. When the configured embedding provider is Voyage,
        // ask for the asymmetric "query" projection so the vector pairs
        // properly with the "document"-typed index vectors. Other providers
        // (OpenAI, Ollama) expose a single embed() entrypoint and project
        // symmetrically.
        $provider = base_embedding_provider::create_from_config();
        if ($provider instanceof \local_ai_course_assistant\embedding_provider\voyage_embedding_provider) {
            $queryvec = $provider->embed_query($query);
        } else {
            $queryvec = $provider->embed($query);
        }

        if (empty($queryvec)) {
            return [];
        }

        // Load and decode embeddings (cached per-course within the request).
        if (!isset($embedding_cache[$cache_key])) {
            $rows = $DB->get_records_select(
                'local_ai_course_assistant_chunks',
                'courseid = :courseid AND embedding IS NOT NULL',
                ['courseid' => $courseid],
                '',
                'id, content, embedding, cmid, modtype, chunkindex'
            );

            $embedding_cache[$cache_key] = [];
            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $vec = json_decode($row->embedding, true);
                    if (is_array($vec) && !empty($vec)) {
                        $embedding_cache[$cache_key][$row->id] = [
                            'content'    => $row->content,
                            'vec'        => $vec,
                            'cmid'       => isset($row->cmid) ? (int) $row->cmid : null,
                            'modtype'    => (string) ($row->modtype ?? ''),
                            'chunkindex' => (int) ($row->chunkindex ?? 0),
                        ];
                    }
                }
            }
        }

        if (empty($embedding_cache[$cache_key])) {
            return [];
        }

        // Score each chunk.
        $scored = [];
        foreach ($embedding_cache[$cache_key] as $entry) {
            $score    = self::cosine($queryvec, $entry['vec']);
            $scored[] = [
                'content'    => $entry['content'],
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

        // Optional stage 2: two-stage retrieval with Voyage rerank-2.5.
        // When `rerank_enabled` is on AND a Voyage rerank API key is configured,
        // take the top `rerank_candidates` cosine matches (default 50) and
        // re-score them with rerank-2.5 (cross-encoder), then keep the top-k.
        // Published recall lifts: +15 Recall@10 enterprise / +39% NDCG BEIR.
        // Falls back to single-stage cosine top-k if reranker fails or is unset.
        if ((bool) get_config('local_ai_course_assistant', 'rerank_enabled')) {
            $rawcand = get_config('local_ai_course_assistant', 'rerank_candidates');
            $candidates = ($rawcand === false || $rawcand === '') ? 50 : (int) $rawcand;
            $candidates = max($topk, min($candidates, count($scored)));
            $stage1 = array_slice($scored, 0, $candidates);
            try {
                $reranker = new \local_ai_course_assistant\embedding_provider\voyage_reranker();
                if ($reranker->is_configured()) {
                    $documents = array_map(fn($r) => $r['content'], $stage1);
                    $reranked = $reranker->rerank($query, $documents, $topk);
                    if (!empty($reranked)) {
                        $out = [];
                        foreach ($reranked as $entry) {
                            $idx = $entry['index'];
                            if (isset($stage1[$idx])) {
                                $row = $stage1[$idx];
                                // Replace the cosine score with the rerank
                                // relevance score so downstream telemetry
                                // reflects the actual ranking signal used.
                                $row['score'] = $entry['score'];
                                $row['cosine_score'] = $stage1[$idx]['score'];
                                $out[] = $row;
                            }
                        }
                        if (!empty($out)) {
                            return $out;
                        }
                    }
                }
            } catch (\Throwable $e) {
                debugging('rag_retriever rerank failed, falling back to cosine top-k: '
                    . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return array_slice($scored, 0, $topk);
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
