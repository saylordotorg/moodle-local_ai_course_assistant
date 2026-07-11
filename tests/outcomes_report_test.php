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
 * WSCUC outcomes report: benchmark resolution and the aggregate (v6.8.28).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\outcomes_report
 */
final class outcomes_report_test extends \advanced_testcase {

    public function test_aggregate_counts_students_meeting_benchmark(): void {
        // 5 students, benchmark 0.70: three at/above, two below -> 60%.
        $scores = [0.9, 0.7, 0.72, 0.55, 0.1];
        $agg = outcomes_report::aggregate($scores, 0.70);
        $this->assertSame(5, $agg['n']);
        $this->assertSame(3, $agg['met']); // 0.9, 0.7 (at benchmark), 0.72
        $this->assertSame(60.0, $agg['pct']);
    }

    public function test_aggregate_empty_is_zero_not_division_error(): void {
        $agg = outcomes_report::aggregate([], 0.70);
        $this->assertSame(0, $agg['n']);
        $this->assertSame(0, $agg['met']);
        $this->assertSame(0.0, $agg['pct']);
    }

    public function test_benchmark_default_and_per_outcome_override(): void {
        $this->resetAfterTest();
        // Unset -> the class default (70).
        unset_config('outcomes_benchmark_default', 'local_ai_course_assistant');
        $this->assertSame(70, outcomes_report::default_benchmark_pct());
        // Site override, clamped.
        set_config('outcomes_benchmark_default', 80, 'local_ai_course_assistant');
        $this->assertSame(80, outcomes_report::default_benchmark_pct());
        set_config('outcomes_benchmark_default', 250, 'local_ai_course_assistant');
        $this->assertSame(100, outcomes_report::default_benchmark_pct());
        // Per-outcome override wins over the site default.
        $this->assertSame(100, outcomes_report::benchmark_pct_for(42)); // falls back to site (100 now)
        set_config('outcomes_benchmark_obj_42', 75, 'local_ai_course_assistant');
        $this->assertSame(75, outcomes_report::benchmark_pct_for(42));
    }
}
