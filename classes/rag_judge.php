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
 * Pure scoring + parse helpers for the RAG judge-mode benchmark.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rag_judge {

    /** nDCG@k using graded relevance (gain = 2^grade - 1). 0.0 when nothing is relevant. */
    public static function ndcg_at_k(array $grades, int $k): float {
        $g = array_slice(array_map('intval', $grades), 0, $k);
        $dcg = 0.0;
        foreach ($g as $i => $gi) {
            $dcg += (pow(2, $gi) - 1) / log($i + 2, 2);
        }
        $ideal = $g;
        rsort($ideal);
        $idcg = 0.0;
        foreach ($ideal as $i => $gi) {
            $idcg += (pow(2, $gi) - 1) / log($i + 2, 2);
        }
        return $idcg > 0 ? $dcg / $idcg : 0.0;
    }

    /**
     * Fraction of the top-k passages graded >= threshold.
     *
     * Divides by $k (not count($grades)) so arms that return fewer than k
     * passages (e.g. page-mode dedups) are comparable: missing slots count
     * as not-relevant / grade 0, per standard precision@k semantics.
     */
    public static function precision_at_k(array $grades, int $k, int $threshold = 2): float {
        if ($k <= 0) {
            return 0.0;
        }
        $g = array_slice(array_map('intval', $grades), 0, $k);
        $rel = count(array_filter($g, fn($x) => $x >= $threshold));
        return $rel / $k;
    }

    /** 1 if any top-k passage is graded >= threshold, else 0. */
    public static function hit_at_k(array $grades, int $k, int $threshold = 2): int {
        foreach (array_slice(array_map('intval', $grades), 0, $k) as $x) {
            if ($x >= $threshold) {
                return 1;
            }
        }
        return 0;
    }

    /**
     * Mean grade over the top-k passages.
     *
     * Divides by $k (not count($grades)) so arms that return fewer than k
     * passages are comparable: missing slots count as grade 0.
     */
    public static function mean_relevance(array $grades, int $k): float {
        if ($k <= 0) {
            return 0.0;
        }
        $g = array_slice(array_map('intval', $grades), 0, $k);
        return array_sum($g) / $k;
    }

    /**
     * Parse a judge reply into exactly $expected integer grades clamped to 0-3.
     * Extracts the first JSON array in the reply. Returns null on parse failure
     * or length mismatch (so the caller can count a judge error, never zero-fill).
     *
     * @return int[]|null
     */
    public static function parse_grades(string $reply, int $expected): ?array {
        if (!preg_match('/\[[\s\S]*?\]/', $reply, $m)) {
            return null;
        }
        $arr = json_decode($m[0], true);
        if (!is_array($arr) || count($arr) !== $expected) {
            return null;
        }
        $out = [];
        foreach ($arr as $v) {
            if (!is_numeric($v)) {
                return null;
            }
            $out[] = max(0, min(3, (int) $v));
        }
        return $out;
    }
}
