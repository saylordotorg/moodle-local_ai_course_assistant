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
 * A rubric-mapped criterion feeds the WSCUC outcomes report (v6.8.30).
 *
 * Mirrors what score_speech does after scoring: a criterion carrying an
 * objectiveid records a normalized partial-credit attempt (source 'rubric'),
 * which then surfaces per-outcome in outcomes_report::course_report.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\outcomes_report
 */
final class outcomes_rubric_source_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('mastery_decay_enabled', 0, 'local_ai_course_assistant');
        set_config('outcomes_benchmark_default', 70, 'local_ai_course_assistant');
    }

    public function test_rubric_criterion_surfaces_in_outcomes_report(): void {
        $course = $this->getDataGenerator()->create_course();
        $courseid = (int) $course->id;
        $oid = objective_manager::create($courseid, 'Constructs and defends an argument', 'CLO-A');

        // Three learners scored on a max_score=5 criterion mapped to the outcome:
        // 5/5, 4/5, 2/5 -> normalized 1.0, 0.8, 0.4 (what score_speech records).
        foreach ([[1.0], [0.8], [0.4]] as $i => $pair) {
            $user = $this->getDataGenerator()->create_user();
            $norm = $pair[0];
            objective_manager::record_attempt((int) $user->id, $courseid, $oid,
                $norm >= 0.5, 'rubric', 1.0, null, null, $norm);
        }

        $rows = outcomes_report::course_report($courseid);
        $row = null;
        foreach ($rows as $r) {
            if ((int) $r['id'] === $oid) {
                $row = $r;
            }
        }
        $this->assertNotNull($row, 'Mapped outcome missing from the report');
        $this->assertEquals(70, (int) $row['benchmark_pct']);
        $this->assertEquals(3, (int) $row['n'], 'All three rubric attempts should be assessed');
    }
}
