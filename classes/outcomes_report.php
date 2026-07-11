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
 * Outcomes-based assessment report (v6.8.28), aligned to WSCUC practice: the
 * institution sets a benchmark per course learning outcome (default 70%), and
 * we report, for each outcome, that benchmark and the percentage of assessed
 * students who met or exceeded it. This is aggregate reporting (no individual
 * pass/fail gating); a student "meets" an outcome when their mastery estimate
 * (the same obj_att-derived signal used elsewhere) is at or above the benchmark.
 *
 * The benchmark is distinct from the coaching mastery threshold: the mastery
 * threshold drives in-tutor nudges; this benchmark is the reporting standard the
 * institution states and reports against.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outcomes_report {

    /** @var int Fallback institution benchmark, as a percentage. */
    const DEFAULT_BENCHMARK_PCT = 70;

    /**
     * The site-default outcome benchmark as a percentage, clamped to [1, 100].
     *
     * @return int
     */
    public static function default_benchmark_pct(): int {
        $v = get_config('local_ai_course_assistant', 'outcomes_benchmark_default');
        $pct = ($v === false || $v === '') ? self::DEFAULT_BENCHMARK_PCT : (int) $v;
        return max(1, min(100, $pct));
    }

    /**
     * The benchmark for one outcome as a percentage: a per-outcome override
     * (config key outcomes_benchmark_obj_<id>, set by faculty) if present,
     * otherwise the site default.
     *
     * @param int $objectiveid
     * @return int
     */
    public static function benchmark_pct_for(int $objectiveid): int {
        $ov = get_config('local_ai_course_assistant', 'outcomes_benchmark_obj_' . $objectiveid);
        if ($ov === false || $ov === '') {
            return self::default_benchmark_pct();
        }
        return max(1, min(100, (int) $ov));
    }

    /**
     * Aggregate a set of per-student mastery scores (0..1) against a benchmark
     * fraction (0..1). Pure and unit-testable. A student "meets" the outcome
     * when their score is at or above the benchmark.
     *
     * @param float[] $scores Per-student mastery scores in [0, 1].
     * @param float $benchmark Benchmark fraction in [0, 1].
     * @return array{n:int, met:int, pct:float}
     */
    public static function aggregate(array $scores, float $benchmark): array {
        $n = count($scores);
        $met = 0;
        foreach ($scores as $s) {
            if ((float) $s >= $benchmark) {
                $met++;
            }
        }
        return [
            'n'   => $n,
            'met' => $met,
            'pct' => $n > 0 ? round(100.0 * $met / $n, 1) : 0.0,
        ];
    }

    /**
     * Per-outcome report for a course: for each objective, the benchmark and the
     * percentage of assessed students (those with at least one attempt on that
     * objective) who met it.
     *
     * @param int $courseid
     * @return array[] Rows: id, title, code, benchmark_pct, n, met, pct.
     */
    public static function course_report(int $courseid): array {
        global $DB;

        $objectives = objective_manager::list_for_course($courseid);
        $rows = [];
        foreach ($objectives as $obj) {
            // Students with any attempt on this objective are the assessed set.
            $userids = $DB->get_fieldset_select(objective_manager::TABLE_ATTS,
                'DISTINCT userid', 'objectiveid = :oid', ['oid' => $obj->id]);
            $benchmark = self::benchmark_pct_for((int) $obj->id) / 100;
            $scores = [];
            foreach ($userids as $uid) {
                $m = objective_manager::compute_mastery((int) $uid, (int) $obj->id);
                $scores[] = (float) ($m['score'] ?? 0.0);
            }
            $agg = self::aggregate($scores, $benchmark);
            $rows[] = [
                'id'            => (int) $obj->id,
                'title'         => (string) $obj->title,
                'code'          => (string) ($obj->code ?? ''),
                'benchmark_pct' => self::benchmark_pct_for((int) $obj->id),
                'n'             => $agg['n'],
                'met'           => $agg['met'],
                'pct'           => $agg['pct'],
            ];
        }
        return $rows;
    }
}
