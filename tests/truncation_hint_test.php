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
 * The truncation-aware "not found" hint predicate (v5.10.0).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\context_builder::should_add_truncation_hint
 */
final class truncation_hint_test extends \advanced_testcase {

    public function test_hint_present_when_content_truncated_and_no_page(): void {
        $this->assertTrue(context_builder::should_add_truncation_hint(true, 0));
    }

    public function test_hint_absent_when_page_in_scope(): void {
        $this->assertFalse(context_builder::should_add_truncation_hint(true, 42));
    }

    public function test_hint_absent_when_not_truncated(): void {
        $this->assertFalse(context_builder::should_add_truncation_hint(false, 0));
    }
}
