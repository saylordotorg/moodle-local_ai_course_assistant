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
 * Slide-aware scoring context builder (v6.8.27).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\soapbox_scorer::build_slide_context
 */
final class soapbox_scorer_test extends \basic_testcase {

    public function test_build_slide_context_computes_time_per_slide(): void {
        $texts = ['Intro', 'Body', 'Conclusion'];
        // Advance to slide 1 at 8s, slide 2 at 20s; recording is 30s long.
        $timeline = [['t' => 0, 'i' => 0], ['t' => 8, 'i' => 1], ['t' => 20, 'i' => 2]];
        $ctx = soapbox_scorer::build_slide_context($texts, $timeline, 30);

        $this->assertStringContainsString('used 3 slide', $ctx);
        $this->assertStringContainsString('Slide 1 (~8s): Intro', $ctx);   // 0 -> 8
        $this->assertStringContainsString('Slide 2 (~12s): Body', $ctx);   // 8 -> 20
        $this->assertStringContainsString('Slide 3 (~10s): Conclusion', $ctx); // 20 -> 30
    }

    public function test_build_slide_context_empty_deck_is_empty(): void {
        $this->assertSame('', soapbox_scorer::build_slide_context([], [], 30));
    }

    public function test_build_slide_context_handles_no_timeline_and_blank_text(): void {
        $ctx = soapbox_scorer::build_slide_context(['', 'Only slide'], [], 60);
        $this->assertStringContainsString('used 2 slide', $ctx);
        $this->assertStringContainsString('(no text on this slide)', $ctx);
        // With no advances, no time is attributed, but the slides still list.
        $this->assertStringContainsString('Slide 2 (~0s): Only slide', $ctx);
    }
}
