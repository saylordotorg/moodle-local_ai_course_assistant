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

    /** Precomputed cross-course objective-pair link table. */
    public const TABLE_LINKS = 'local_ai_course_assistant_obj_links';

    /** Title similarity at or above this counts as a fuzzy match. */
    private const FUZZY_THRESHOLD = 0.88;

    /**
     * Whether cross-course rollup is active for a course. Gated on the
     * mastery master switch AND the per-course/site crossmastery flag.
     *
     * @param int $courseid
     * @return bool
     */
    public static function is_enabled_for_course(int $courseid): bool {
        if (!objective_manager::is_enabled_for_course($courseid)) {
            return false;
        }
        return feature_flags::resolve('crossmastery', $courseid);
    }

    /**
     * Recompute the cross-course objective link table from scratch.
     *
     * Pairs objectives in DIFFERENT courses by, in priority order: identical
     * non-empty competency ref ('ref'), identical normalized title
     * ('title_exact'), or title similarity at/above the fuzzy threshold
     * ('title_fuzzy'). Each pair is stored once with the lower id first.
     * Idempotent: truncate-and-rebuild inside a transaction so a mid-run
     * failure leaves the prior table intact.
     *
     * O(n^2) over all objectives site-wide; the expensive string-similarity
     * call only runs when the cheap ref/exact checks both miss. Objective
     * counts are small (tens per course) so this is fine for a daily task.
     *
     * @return array{ref:int, title_exact:int, title_fuzzy:int, total:int}
     */
    public static function rebuild_links(): array {
        global $DB;
        $counts = ['ref' => 0, 'title_exact' => 0, 'title_fuzzy' => 0, 'total' => 0];

        $list = array_values($DB->get_records(
            objective_manager::TABLE_OBJS, null, 'id ASC', 'id, courseid, title, external_ref'));
        $n = count($list);
        $norm = [];
        foreach ($list as $o) {
            $norm[(int) $o->id] = self::normalize_title((string) $o->title);
        }

        $pairs = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $oa = $list[$i];
                $ob = $list[$j];
                if ((int) $oa->courseid === (int) $ob->courseid) {
                    continue; // Never link two objectives in the same course.
                }
                $refa = trim((string) $oa->external_ref);
                $refb = trim((string) $ob->external_ref);
                $method = null;
                $score = 0.0;
                if ($refa !== '' && $refa === $refb) {
                    $method = 'ref';
                    $score = 1.0;
                } else if ($norm[(int) $oa->id] !== '' && $norm[(int) $oa->id] === $norm[(int) $ob->id]) {
                    $method = 'title_exact';
                    $score = 1.0;
                } else {
                    $sim = self::title_similarity((string) $oa->title, (string) $ob->title);
                    if ($sim >= self::FUZZY_THRESHOLD) {
                        $method = 'title_fuzzy';
                        $score = $sim;
                    }
                }
                if ($method === null) {
                    continue;
                }
                $lo = min((int) $oa->id, (int) $ob->id);
                $hi = max((int) $oa->id, (int) $ob->id);
                $pairs["$lo:$hi"] = ['a' => $lo, 'b' => $hi, 'method' => $method, 'score' => $score];
            }
        }

        $tx = $DB->start_delegated_transaction();
        $DB->delete_records(self::TABLE_LINKS);
        $now = time();
        foreach ($pairs as $p) {
            $DB->insert_record(self::TABLE_LINKS, (object) [
                'objectiveida' => $p['a'],
                'objectiveidb' => $p['b'],
                'method' => $p['method'],
                'score' => $p['score'],
                'timemodified' => $now,
            ]);
            $counts[$p['method']]++;
            $counts['total']++;
        }
        $tx->allow_commit();
        return $counts;
    }

    /**
     * Objectives in OTHER courses linked to the given objective.
     *
     * @param int $objectiveid
     * @return array List of ['objectiveid'=>int, 'courseid'=>int, 'method'=>string, 'score'=>float].
     */
    public static function linked_objectives(int $objectiveid): array {
        global $DB;
        $rows = $DB->get_records_select(self::TABLE_LINKS,
            'objectiveida = ? OR objectiveidb = ?', [$objectiveid, $objectiveid]);
        $out = [];
        foreach ($rows as $r) {
            $otherid = ((int) $r->objectiveida === $objectiveid)
                ? (int) $r->objectiveidb : (int) $r->objectiveida;
            $obj = objective_manager::get($otherid);
            if (!$obj) {
                continue; // Stale row pointing at a deleted objective — skip.
            }
            $out[] = [
                'objectiveid' => $otherid,
                'courseid' => (int) $obj->courseid,
                'method' => $r->method,
                'score' => (float) $r->score,
            ];
        }
        return $out;
    }

    /**
     * Transfer evidence for a learner in a course: objectives here that the
     * learner has NOT yet mastered locally but HAS mastered via a linked
     * objective in another course. Advisory only — never mutates stored
     * mastery. Returns [] when the feature is off for the course.
     *
     * @param int $userid
     * @param int $courseid
     * @return array List of ['objective'=>row, 'source_courseid'=>int,
     *               'source_coursename'=>string, 'source_status'=>string,
     *               'method'=>string, 'score'=>float].
     */
    public static function get_transfer_evidence(int $userid, int $courseid): array {
        global $DB;
        if (!self::is_enabled_for_course($courseid)) {
            return [];
        }
        $evidence = [];
        foreach (objective_manager::list_for_course($courseid) as $obj) {
            $local = objective_manager::compute_mastery($userid, (int) $obj->id);
            if ($local['status'] === 'mastered') {
                continue; // Already demonstrated here — nothing to transfer.
            }
            $best = null;
            foreach (self::linked_objectives((int) $obj->id) as $link) {
                if ($link['courseid'] === $courseid) {
                    continue;
                }
                $m = objective_manager::compute_mastery($userid, $link['objectiveid']);
                if ($m['status'] !== 'mastered') {
                    continue; // Only mastery elsewhere is strong enough to surface.
                }
                if ($best === null || $link['score'] > $best['score']) {
                    $best = $link + ['mastery' => $m];
                }
            }
            if ($best !== null) {
                $course = $DB->get_record('course', ['id' => $best['courseid']], 'id, fullname');
                $evidence[] = [
                    'objective' => $obj,
                    'source_courseid' => $best['courseid'],
                    'source_coursename' => $course ? format_string($course->fullname) : '',
                    'source_status' => $best['mastery']['status'],
                    'method' => $best['method'],
                    'score' => $best['score'],
                ];
            }
        }
        return $evidence;
    }

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
