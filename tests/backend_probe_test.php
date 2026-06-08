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
 * Unit tests for the network-free helpers of the backend self-test probe
 * (v5.10.0). The live methods (probe_chat, detect_window) require a backend
 * and are exercised manually from the self-test page.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\backend_probe
 */
final class backend_probe_test extends \advanced_testcase {

    public function test_window_mismatch_warns(): void {
        $r = backend_probe::compare_window(8192, 4096);
        $this->assertSame(backend_probe::STATUS_WARN, $r['status']);
    }

    public function test_window_match_passes(): void {
        $r = backend_probe::compare_window(8192, 8192);
        $this->assertSame(backend_probe::STATUS_PASS, $r['status']);
    }

    public function test_window_unknown_warns(): void {
        $r = backend_probe::compare_window(8192, 0); // 0 = could not detect
        $this->assertSame(backend_probe::STATUS_WARN, $r['status']);
    }

    public function test_window_off_with_detected_warns(): void {
        $r = backend_probe::compare_window(0, 8192); // clamping off but backend has a limit
        $this->assertSame(backend_probe::STATUS_WARN, $r['status']);
    }

    public function test_floor_fits_fails_on_tiny_window(): void {
        $r = backend_probe::check_floor_fits(600, 768, 'en');
        $this->assertSame(backend_probe::STATUS_FAIL, $r['status']);
    }

    public function test_floor_fits_passes_on_roomy_window(): void {
        $r = backend_probe::check_floor_fits(8192, 768, 'en');
        $this->assertSame(backend_probe::STATUS_PASS, $r['status']);
    }
}
