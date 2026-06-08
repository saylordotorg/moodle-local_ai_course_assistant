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
 * Unit tests for the backend-window budget clamp (v5.10.0).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\context_builder::effective_budget_chars
 */
final class token_budget_clamp_test extends \advanced_testcase {

    public function test_clamp_shrinks_budget_when_window_small(): void {
        $this->resetAfterTest();
        // 4096-token English window: (4096-768-512)*4.0 = 11264 chars, which is
        // below a 12000 raw budget, so the budget is clamped down.
        $clamped = context_builder::effective_budget_chars(12000, 4096, 768, 0, 'en');
        $this->assertLessThan(12000, $clamped);
        $this->assertGreaterThan(0, $clamped);
    }

    public function test_clamp_noop_when_window_roomy(): void {
        $this->resetAfterTest();
        // 8192-token English window comfortably holds a 12000-char prompt, so
        // the safety clamp does not shrink it.
        $this->assertSame(12000, context_builder::effective_budget_chars(12000, 8192, 768, 0, 'en'));
    }

    public function test_clamp_noop_when_window_zero(): void {
        $this->resetAfterTest();
        // window 0 = hosted/unlimited -> return the raw budget unchanged.
        $this->assertSame(12000, context_builder::effective_budget_chars(12000, 0, 768, 0, 'en'));
    }

    public function test_clamp_respects_min_floor(): void {
        $this->resetAfterTest();
        // Absurdly small window: never drops below the safety floor.
        $clamped = context_builder::effective_budget_chars(12000, 600, 768, 0, 'en');
        $this->assertGreaterThanOrEqual(context_builder::MIN_BUDGET_FLOOR, $clamped);
    }

    public function test_clamp_keeps_smaller_raw_budget(): void {
        $this->resetAfterTest();
        // If the admin raw budget is already smaller than the ceiling, keep it.
        $clamped = context_builder::effective_budget_chars(2000, 8192, 768, 0, 'en');
        $this->assertSame(2000, $clamped);
    }
}
