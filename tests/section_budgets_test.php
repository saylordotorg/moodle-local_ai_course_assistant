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

use local_ai_course_assistant\prompt\section;
use local_ai_course_assistant\prompt\builder;

/**
 * v5.6.0 tests for the proportional-budget model in context_builder
 * and the max_chars enforcement in prompt\builder.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\context_builder
 * @covers     \local_ai_course_assistant\prompt\builder
 */
final class section_budgets_test extends \advanced_testcase {

    /**
     * Default weights at the benchmarked 10/10/40/40 split, with the
     * page_focus boost applied because pageid > 0. The boost shifts
     * 15 weight points from course_content + course_structure into
     * current_page, so the expected per-bucket share is roughly:
     *   safety_identity:  10%
     *   course_structure:  7% (loses 3 points)
     *   course_content:   28% (loses 12 points)
     *   current_page:     55% (gains 15 points)
     */
    public function test_section_budgets_page_in_scope_applies_boost(): void {
        $this->resetAfterTest();
        unset_config('prompt_section_weights', 'local_ai_course_assistant');
        unset_config('prompt_context_boost_mode', 'local_ai_course_assistant');

        $b = context_builder::section_budgets(12000, /*pageid*/ 42, /*quizmode*/ '');

        // Total should be ~12000 (rounding tolerance).
        $this->assertEqualsWithDelta(12000, array_sum($b), 5,
            'Per-bucket budgets must sum to the total within rounding tolerance.');
        // Current page should have the largest slice after the boost.
        $this->assertGreaterThan($b['course_content'], $b['current_page'],
            'page_focus boost should make current_page the largest bucket.');
        $this->assertGreaterThan($b['course_structure'], $b['course_content']);
        // Safety floor preserved.
        $this->assertGreaterThanOrEqual(1100, $b['safety_identity']);
    }

    /**
     * When pageid==0 the current_page bucket has nothing to allocate;
     * its share gets redistributed 70/30 into course_content and
     * course_structure.
     */
    public function test_section_budgets_no_page_redistributes_to_content(): void {
        $this->resetAfterTest();
        unset_config('prompt_section_weights', 'local_ai_course_assistant');
        unset_config('prompt_context_boost_mode', 'local_ai_course_assistant');

        $b = context_builder::section_budgets(12000, /*pageid*/ 0, /*quizmode*/ '');

        $this->assertEqualsWithDelta(12000, array_sum($b), 5);
        // current_page contributes nothing when not in scope.
        $this->assertEquals(0, $b['current_page']);
        // course_content should be the dominant bucket.
        $this->assertGreaterThan($b['course_structure'], $b['course_content']);
        $this->assertGreaterThan($b['safety_identity'], $b['course_content']);
    }

    /**
     * Coach mode is treated as "page in scope" regardless of pageid value,
     * so the page_focus boost fires the same way.
     */
    public function test_section_budgets_coach_mode_triggers_page_boost(): void {
        $this->resetAfterTest();
        unset_config('prompt_section_weights', 'local_ai_course_assistant');
        unset_config('prompt_context_boost_mode', 'local_ai_course_assistant');

        $with_coach    = context_builder::section_budgets(12000, /*pageid*/ 0, 'coach');
        $with_page     = context_builder::section_budgets(12000, /*pageid*/ 42, '');
        $without_either = context_builder::section_budgets(12000, /*pageid*/ 0, '');

        // Coach mode + no pageid should still allocate to current_page (boost fires).
        $this->assertGreaterThan(0, $with_coach['current_page'],
            'Coach mode must allocate to current_page even when pageid==0.');
        // Coach budgets should mirror the with-page budgets reasonably closely.
        $this->assertEquals($with_page['current_page'], $with_coach['current_page']);
        // Without either, current_page is 0.
        $this->assertEquals(0, $without_either['current_page']);
    }

    /**
     * Per-coach-mode override: when the admin sets a coach-specific weight
     * JSON, it takes precedence over the base weights during coach mode.
     */
    public function test_section_budgets_coach_override_takes_precedence(): void {
        $this->resetAfterTest();
        set_config('prompt_context_boost_mode', 'off', 'local_ai_course_assistant');
        // Base weights: 10/10/40/40
        set_config('prompt_section_weights', json_encode([
            'safety_identity'  => 10, 'course_structure' => 10,
            'course_content'   => 40, 'current_page'     => 40,
        ]), 'local_ai_course_assistant');
        // Coach-mode override: pour everything into current_page.
        set_config('prompt_section_weights_coach', json_encode([
            'safety_identity'  => 10, 'course_structure' => 10,
            'course_content'   => 10, 'current_page'     => 70,
        ]), 'local_ai_course_assistant');

        $base_mode  = context_builder::section_budgets(12000, 42, '');
        $coach_mode = context_builder::section_budgets(12000, 42, 'coach');

        // In base mode, current_page is 40%.
        $this->assertEqualsWithDelta(4800, $base_mode['current_page'], 5);
        // In coach mode, current_page is 70%.
        $this->assertEqualsWithDelta(8400, $coach_mode['current_page'], 5);
    }

    /**
     * Invalid weights (don't sum to 100) fall back to the benchmarked
     * defaults silently rather than producing a degenerate prompt.
     */
    public function test_section_budgets_invalid_weights_fall_back_to_defaults(): void {
        $this->resetAfterTest();
        set_config('prompt_context_boost_mode', 'off', 'local_ai_course_assistant');
        set_config('prompt_section_weights', json_encode([
            'safety_identity'  => 5,  // sums to 25; way out of tolerance
            'course_structure' => 5,
            'course_content'   => 10,
            'current_page'     => 5,
        ]), 'local_ai_course_assistant');

        $b = context_builder::section_budgets(12000, 42, '');

        $this->assertEqualsWithDelta(12000, array_sum($b), 5,
            'Fall-back defaults must still produce a budget summing to the total.');
        // Defaults are 10/10/40/40, so current_page should be 4800.
        $this->assertEqualsWithDelta(4800, $b['current_page'], 5);
    }

    /**
     * Boost mode 'off' makes admin weights pass through unchanged
     * regardless of pageid.
     */
    public function test_section_budgets_boost_off_passes_weights_through(): void {
        $this->resetAfterTest();
        set_config('prompt_context_boost_mode', 'off', 'local_ai_course_assistant');
        set_config('prompt_section_weights', json_encode([
            'safety_identity'  => 25, 'course_structure' => 25,
            'course_content'   => 25, 'current_page'     => 25,
        ]), 'local_ai_course_assistant');

        $with_page    = context_builder::section_budgets(12000, 42, '');
        $without_page = context_builder::section_budgets(12000, 0,  '');

        // With boost off, every bucket gets exactly 25% regardless of pageid.
        foreach ($with_page as $bucket => $alloc) {
            $this->assertEqualsWithDelta(3000, $alloc, 5,
                "bucket=$bucket should be 25% under boost=off, got $alloc");
        }
        foreach ($without_page as $bucket => $alloc) {
            $this->assertEqualsWithDelta(3000, $alloc, 5,
                "bucket=$bucket should be 25% under boost=off, got $alloc");
        }
    }

    /**
     * builder::assemble honors a section's max_chars by truncating before
     * the drop-on-priority safety net runs. This is the wiring that lets
     * section_budgets() actually shape the assembled prompt.
     */
    public function test_builder_truncates_section_to_max_chars(): void {
        $this->resetAfterTest();

        $long = str_repeat('aaaaaaaaaaaaaaaa', 200); // 3200 chars
        $sec = new section('test', section::CAT_CONTEXT, 50, $long, /*min_chars*/ 100, /*max_chars*/ 500);
        $assembled = builder::assemble([$sec], /*budget*/ 100000);

        // The section should be truncated to ~500 chars + the truncation marker.
        $this->assertLessThan(700, $assembled['breakdown']['test']['chars'],
            'Section with max_chars=500 should be truncated at the cap.');
        $this->assertGreaterThan(450, $assembled['breakdown']['test']['chars'],
            'Section truncated should still carry at least max_chars-ish content.');
        $this->assertTrue($assembled['breakdown']['test']['truncated']);
    }

    /**
     * Safety-category sections are exempt from max_chars truncation
     * because identity + safety guidance must always land in full.
     */
    public function test_builder_does_not_truncate_safety_sections(): void {
        $this->resetAfterTest();

        $long = str_repeat('SAFETY ', 200); // 1400 chars
        $sec = new section('safety_test', section::CAT_SAFETY, 100, $long, 0, /*max_chars*/ 100);
        $assembled = builder::assemble([$sec], /*budget*/ 100000);

        // max_chars should be ignored on safety sections.
        $this->assertEquals(strlen($long), $assembled['breakdown']['safety_test']['chars'],
            'Safety sections must be exempt from max_chars truncation.');
        $this->assertFalse($assembled['breakdown']['safety_test']['truncated']);
    }
}
