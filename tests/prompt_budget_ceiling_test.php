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
 * The metrics budget recommendation must never exceed the backend window
 * ceiling (v5.10.0).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\prompt_metrics_logger::recommend
 */
final class prompt_budget_ceiling_test extends \advanced_testcase {

    /** An aggregate that, uncapped, recommends raising the budget well above a small window. */
    private function truncating_agg(): array {
        return [
            'samples' => 100,
            'avg_budget' => 12000,
            'avg_total' => 11000,
            'max_total' => 40000,
            'pct_truncated' => 30.0,
        ];
    }

    public function test_recommend_capped_by_window(): void {
        $this->resetAfterTest();
        set_config('backend_context_tokens', 8192, 'local_ai_course_assistant');
        set_config('max_tokens', 768, 'local_ai_course_assistant');
        $rec = prompt_metrics_logger::recommend($this->truncating_agg());
        $this->assertNotNull($rec);
        $ceiling = token_estimator::budget_chars_for_window(8192, 768, 0, 'en');
        $this->assertLessThanOrEqual($ceiling, $rec['budget']);
    }

    public function test_recommend_uncapped_when_window_zero(): void {
        $this->resetAfterTest();
        set_config('backend_context_tokens', 0, 'local_ai_course_assistant');
        $rec = prompt_metrics_logger::recommend($this->truncating_agg());
        $this->assertNotNull($rec);
        // Uncapped, the recommendation clears the 40000-char max observed.
        $this->assertGreaterThan(40000, $rec['budget']);
    }
}
