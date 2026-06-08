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
 * Unit tests for token_estimator (v5.10.0).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\token_estimator
 */
final class token_estimator_test extends \advanced_testcase {

    public function test_chars_per_token_known_and_fallback(): void {
        $this->assertEqualsWithDelta(4.0, token_estimator::chars_per_token('en'), 0.01);
        $this->assertEqualsWithDelta(2.8, token_estimator::chars_per_token('hu'), 0.01);
        $this->assertEqualsWithDelta(1.5, token_estimator::chars_per_token('zh_cn'), 0.01);
        // Bare prefix fallback: pt_br -> pt.
        $this->assertEqualsWithDelta(3.8, token_estimator::chars_per_token('pt_br'), 0.01);
        // Unknown language uses the conservative fallback.
        $this->assertEqualsWithDelta(3.0, token_estimator::chars_per_token('xx'), 0.01);
    }

    public function test_estimate_tokens_rounds_up(): void {
        // 10 chars / 4.0 = 2.5 -> ceil -> 3.
        $this->assertSame(3, token_estimator::estimate_tokens('abcdefghij', 'en'));
        $this->assertSame(0, token_estimator::estimate_tokens('', 'en'));
    }

    public function test_budget_chars_for_window_reserves_output_and_history(): void {
        $chars = token_estimator::budget_chars_for_window(8192, 768, 0, 'en');
        $this->assertGreaterThan(1500, $chars);
        $this->assertLessThan((int) (8192 * 4.0), $chars);
    }

    public function test_budget_chars_for_window_never_negative(): void {
        // Output larger than the whole window -> 0, not negative.
        $chars = token_estimator::budget_chars_for_window(512, 4096, 0, 'en');
        $this->assertGreaterThanOrEqual(0, $chars);
    }
}
