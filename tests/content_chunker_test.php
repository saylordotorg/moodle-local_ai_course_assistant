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
 * Unit tests for content_chunker::reconstruct().
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_chunker_test extends \basic_testcase {
    public function test_reconstruct_single_chunk_unchanged() {
        $c = "[2.1: Legal Forms] Corporations: a corporation is a legal entity";
        $this->assertSame($c, content_chunker::reconstruct([$c]));
    }

    public function test_reconstruct_dedupes_overlap_and_prefix() {
        $prefix = "[2.1: Legal Forms] Corporations: ";
        $c0 = $prefix . "a corporation is a legal entity separate from its owners";
        // c1 = prefix + 4-word overlap tail of c0 + new body
        $c1 = $prefix . "separate from its owners and can raise capital by issuing stock";
        $out = content_chunker::reconstruct([$c0, $c1]);
        $this->assertSame(
            $prefix . "a corporation is a legal entity separate from its owners "
                . "and can raise capital by issuing stock",
            $out
        );
        // Prefix header appears exactly once.
        $this->assertSame(1, substr_count($out, "Corporations:"));
    }

    public function test_reconstruct_empty() {
        $this->assertSame('', content_chunker::reconstruct([]));
    }
}
