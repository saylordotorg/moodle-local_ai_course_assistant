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
 * Cross-course mastery rollup (v5.7.0).
 *
 * Recognizes that a learning objective in one course is "the same
 * competency" as one the learner has already worked in another course,
 * so SOLA can acknowledge transferable mastery instead of re-drilling
 * from scratch. Objective identity is matched two ways: exact Moodle
 * competency reference (`external_ref`) and normalized/fuzzy title
 * similarity. Matches are precomputed into a link table; the read-side
 * resolver folds them into prompt injection and the mastery-aware
 * starter as advisory context only — stored mastery numbers are never
 * mutated by another course.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cross_course_mastery {

    /** Title similarity at or above this counts as a fuzzy match. */
    private const FUZZY_THRESHOLD = 0.88;

    /**
     * Normalize an objective title for cross-course comparison.
     *
     * Lowercases, strips a leading enumerator/code prefix ("LO1:",
     * "Unit 3 —", "1."), collapses internal whitespace, and trims
     * surrounding whitespace and punctuation.
     *
     * @param string $title
     * @return string
     */
    public static function normalize_title(string $title): string {
        $t = \core_text::strtolower($title);
        // Strip a leading enumerator/code prefix: "lo1:", "unit 3 —", "1.".
        $t = preg_replace('/^\s*(?:[a-z]+\.?\s*)?\d+\s*[:.\-\x{2013}\x{2014}]\s*/u', '', $t);
        // Collapse internal whitespace to single spaces.
        $t = preg_replace('/\s+/u', ' ', $t);
        // Trim surrounding whitespace, then surrounding punctuation.
        $t = trim($t);
        $t = preg_replace('/^[\p{P}\s]+|[\p{P}\s]+$/u', '', $t);
        return $t;
    }

    /**
     * Similarity between two objective titles, 0.0 .. 1.0.
     *
     * Single seam for the matching strategy: identical after
     * normalization is 1.0; otherwise a string-similarity ratio. An
     * embedding-backed implementation can later override this when RAG
     * embeddings are configured.
     *
     * @param string $a
     * @param string $b
     * @return float
     */
    public static function title_similarity(string $a, string $b): float {
        $na = self::normalize_title($a);
        $nb = self::normalize_title($b);
        if ($na === $nb) {
            return 1.0;
        }
        if ($na === '' || $nb === '') {
            return 0.0;
        }
        $percent = 0.0;
        similar_text($na, $nb, $percent);
        return $percent / 100.0;
    }
}
