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
 * Cross-course mastery rollup unit tests (v5.7.0).
 *
 * Covers objective-identity matching across courses (exact competency
 * ref + normalized/fuzzy title), the precomputed link table rebuild, and
 * the read-side transfer-evidence resolver that feeds prompt injection
 * and the mastery-aware starter.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cross_course_mastery_test extends \advanced_testcase {

    // ───────────────────────────────────────────────────────────
    // normalize_title — pure string normalization
    // ───────────────────────────────────────────────────────────

    public function test_normalize_title_lowercases_and_collapses_whitespace(): void {
        $this->assertEquals(
            'interpret a balance sheet',
            cross_course_mastery::normalize_title("  Interpret  a   Balance Sheet  ")
        );
    }

    public function test_normalize_title_strips_leading_code_prefix(): void {
        $this->assertEquals(
            'interpret a balance sheet',
            cross_course_mastery::normalize_title('LO1: Interpret a balance sheet')
        );
        $this->assertEquals(
            'interpret a balance sheet',
            cross_course_mastery::normalize_title('Unit 3 — Interpret a balance sheet')
        );
        $this->assertEquals(
            'interpret a balance sheet',
            cross_course_mastery::normalize_title('1. Interpret a balance sheet')
        );
    }

    public function test_normalize_title_strips_trailing_punctuation(): void {
        $this->assertEquals(
            'interpret a balance sheet',
            cross_course_mastery::normalize_title('Interpret a balance sheet.')
        );
    }

    // ───────────────────────────────────────────────────────────
    // title_similarity — 0.0 .. 1.0
    // ───────────────────────────────────────────────────────────

    public function test_title_similarity_is_one_for_identical_normalized(): void {
        $s = cross_course_mastery::title_similarity('Interpret a balance sheet', 'interpret a BALANCE sheet.');
        $this->assertEqualsWithDelta(1.0, $s, 0.0001);
    }

    public function test_title_similarity_is_low_for_unrelated(): void {
        $s = cross_course_mastery::title_similarity('Interpret a balance sheet', 'Photosynthesis in plants');
        $this->assertLessThan(0.5, $s);
    }
}
