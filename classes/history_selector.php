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
use local_ai_course_assistant\embedding_provider\voyage_embedding_provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Selects which conversation history to send to the model.
 *
 * Two admin-selectable modes (history_mode):
 *  - recency  : keep the most recent N pairs (the long-standing behaviour).
 *  - semantic : keep only the recent pairs whose user turn is relevant to the
 *               current question (plus the most recent pair, always), so stale
 *               off-topic turns do not inflate cost or invite drift.
 *
 * Semantic mode embeds the current query and the recent candidate user turns,
 * scores by cosine similarity, and keeps those at/above a threshold. Any
 * failure (embedding misconfigured/unavailable) falls back to recency.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class history_selector {

    /**
     * Build the API-shaped history for a conversation, honouring history_mode.
     *
     * @param int    $conversationid
     * @param string $query The current user message (used by semantic mode).
     * @return array Array of ['role' => ..., 'content' => ...] in chronological order.
     */
    public static function select_for_api(int $conversationid, string $query): array {
        $mode = (string) (get_config('local_ai_course_assistant', 'history_mode') ?: 'semantic');
        if ($mode !== 'semantic' || trim($query) === '') {
            return conversation_manager::get_history_for_api($conversationid);
        }
        try {
            return self::select_semantic($conversationid, $query);
        } catch (\Throwable $e) {
            debugging('SOLA semantic history selection failed, using recency: '
                . $e->getMessage(), DEBUG_DEVELOPER);
            return conversation_manager::get_history_for_api($conversationid);
        }
    }

    /**
     * Semantic selection: embed the query + recent candidate user turns, keep
     * the relevant ones (and always the most recent pair), capped to maxhistory.
     *
     * @param int    $conversationid
     * @param string $query
     * @return array API-shaped messages.
     */
    private static function select_semantic(int $conversationid, string $query): array {
        $rawmax = get_config('local_ai_course_assistant', 'maxhistory');
        $maxpairs = ($rawmax === false || $rawmax === '') ? 20 : (int) $rawmax;
        $rawcand = get_config('local_ai_course_assistant', 'history_candidates');
        $candpairs = ($rawcand === false || $rawcand === '') ? 12 : (int) $rawcand;
        $rawmin = get_config('local_ai_course_assistant', 'history_semantic_minscore');
        $minscore = ($rawmin === false || $rawmin === '') ? 0.20 : (float) $rawmin;

        $messages = array_values(conversation_manager::get_messages($conversationid));
        $pairs = self::pair_messages($messages);
        if (count($pairs) <= 1) {
            // Nothing to filter.
            return self::flatten(array_slice($pairs, -$maxpairs));
        }

        // Bound cost: only score the most recent candidate window. Pairs older
        // than the window are dropped in semantic mode (they are, by
        // definition, stale relative to the current question).
        $candidates = array_slice($pairs, -$candpairs);

        $provider = base_embedding_provider::create_from_config();
        $queryvec = ($provider instanceof voyage_embedding_provider)
            ? $provider->embed_query($query)
            : $provider->embed($query);
        if (empty($queryvec)) {
            return conversation_manager::get_history_for_api($conversationid);
        }

        $usertexts = array_map(
            fn($p) => isset($p['user']['content']) ? (string) $p['user']['content'] : '',
            $candidates
        );
        $vecs = $provider->embed_batch($usertexts);

        $scored = [];
        foreach ($candidates as $i => $p) {
            $vec = $vecs[$i] ?? [];
            $score = (!empty($vec) && !empty($p['user'])) ? self::cosine($queryvec, $vec) : 0.0;
            $scored[] = ['pair' => $p, 'score' => $score];
        }

        return self::flatten(self::pick($scored, $maxpairs, $minscore));
    }

    /**
     * Pure selection: keep pairs scoring at/above the floor, always keep the
     * most recent pair, cap to $maxpairs (dropping the lowest-scoring first),
     * and return them in chronological order.
     *
     * @param array $scored Ordered (chronological) rows of ['pair'=>array, 'score'=>float].
     * @param int   $maxpairs Maximum pairs to keep.
     * @param float $minscore Relevance floor in [0,1].
     * @return array[] The kept 'pair' values, chronological.
     */
    public static function pick(array $scored, int $maxpairs, float $minscore): array {
        if (empty($scored) || $maxpairs <= 0) {
            return [];
        }
        $lastidx = count($scored) - 1;
        $keep = [];
        foreach ($scored as $i => $s) {
            if ($i === $lastidx || (float) ($s['score'] ?? 0.0) >= $minscore) {
                // The most recent pair must never be dropped — give it top
                // priority so a maxpairs cap never evicts it.
                $keep[$i] = ($i === $lastidx) ? PHP_FLOAT_MAX : (float) ($s['score'] ?? 0.0);
            }
        }
        if (count($keep) > $maxpairs) {
            arsort($keep);
            $keep = array_slice($keep, 0, $maxpairs, true);
        }
        ksort($keep);
        $out = [];
        foreach (array_keys($keep) as $i) {
            $out[] = $scored[$i]['pair'];
        }
        return $out;
    }

    /**
     * Group a flat message list into user/assistant pairs.
     *
     * @param array $messages Message records (->role, ->message) in chronological order.
     * @return array[] Pairs ['user'=>?array, 'assistant'=>?array].
     */
    public static function pair_messages(array $messages): array {
        $pairs = [];
        $n = count($messages);
        $i = 0;
        while ($i < $n) {
            $m = $messages[$i];
            if (($m->role ?? '') === 'user') {
                $pair = ['user' => ['role' => 'user', 'content' => $m->message], 'assistant' => null];
                if ($i + 1 < $n && ($messages[$i + 1]->role ?? '') === 'assistant') {
                    $pair['assistant'] = ['role' => 'assistant', 'content' => $messages[$i + 1]->message];
                    $i += 2;
                } else {
                    $i += 1;
                }
                $pairs[] = $pair;
            } else {
                // Stray assistant (or other) message; keep as its own entry so
                // nothing is silently dropped before scoring.
                $pairs[] = ['user' => null, 'assistant' => ['role' => $m->role, 'content' => $m->message]];
                $i += 1;
            }
        }
        return $pairs;
    }

    /**
     * Flatten pairs back into API-shaped messages in chronological order.
     *
     * @param array $pairs
     * @return array[] ['role'=>..., 'content'=>...] entries.
     */
    public static function flatten(array $pairs): array {
        $out = [];
        foreach ($pairs as $p) {
            if (!empty($p['user'])) {
                $out[] = $p['user'];
            }
            if (!empty($p['assistant'])) {
                $out[] = $p['assistant'];
            }
        }
        return $out;
    }

    /**
     * Cosine similarity between two equal-length float vectors.
     *
     * @param float[] $a
     * @param float[] $b
     * @return float
     */
    private static function cosine(array $a, array $b): float {
        $dot = $norma = $normb = 0.0;
        $len = count($a);
        for ($i = 0; $i < $len; $i++) {
            $ai = (float) ($a[$i] ?? 0.0);
            $bi = (float) ($b[$i] ?? 0.0);
            $dot += $ai * $bi;
            $norma += $ai * $ai;
            $normb += $bi * $bi;
        }
        if ($norma == 0.0 || $normb == 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($norma) * sqrt($normb));
    }
}
