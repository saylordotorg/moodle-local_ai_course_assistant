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
 * Unit tests for content_extractor::is_indexable_text (v6.8.8).
 *
 * The too-short / empty skip decision is what makes a page silently produce no
 * chunk, so it is pulled into a pure seam and pinned here.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\content_extractor::is_indexable_text
 */
final class content_extractor_test extends \advanced_testcase {

    public function test_null_and_empty_are_not_indexable(): void {
        $this->assertFalse(content_extractor::is_indexable_text(null));
        $this->assertFalse(content_extractor::is_indexable_text(''));
    }

    public function test_text_below_minimum_is_not_indexable(): void {
        // MIN_CHARS is 80; 79 characters must be rejected.
        $this->assertFalse(content_extractor::is_indexable_text(str_repeat('a', 79)));
    }

    public function test_text_at_or_above_minimum_is_indexable(): void {
        $this->assertTrue(content_extractor::is_indexable_text(str_repeat('a', 80)));
        $this->assertTrue(content_extractor::is_indexable_text(str_repeat('word ', 40)));
    }
}
