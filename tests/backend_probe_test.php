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

    /**
     * The connectivity probe must not starve a reasoning model.
     *
     * Reasoning models spend the completion budget on internal thinking tokens
     * before emitting any content, so a tiny ceiling comes back HTTP 200 with
     * finish_reason=length and null content. Measured against live
     * gemini-2.5-flash on 2026-09-01: max_tokens 8 and 16 both returned null,
     * 64 returned "OK". At the old value of 8 this probe reported a completely
     * healthy production backend as FAIL.
     */
    public function test_probe_budget_is_large_enough_for_reasoning_models(): void {
        $this->resetAfterTest();
        \local_ai_course_assistant\provider\stub_provider::reset();
        set_config('provider', 'stub', 'local_ai_course_assistant');
        set_config('apikey', 'test-key', 'local_ai_course_assistant');

        \local_ai_course_assistant\backend_probe::probe_chat();

        $calls = \local_ai_course_assistant\provider\stub_provider::$calls;
        $this->assertNotEmpty($calls, 'probe_chat did not reach the provider');
        $maxtokens = $calls[0]['options']['max_tokens'] ?? 0;
        $this->assertGreaterThanOrEqual(
            64,
            $maxtokens,
            'probe max_tokens is below the measured knee for reasoning models'
        );
    }

    /**
     * A failing probe must report the backend detail, not just the generic
     * user-facing sentence. moodle_exception keeps the HTTP status and response
     * body in debuginfo, which getMessage() never includes.
     */
    public function test_probe_failure_surfaces_debuginfo(): void {
        $this->resetAfterTest();
        \local_ai_course_assistant\provider\stub_provider::reset();
        set_config('provider', 'stub', 'local_ai_course_assistant');
        set_config('apikey', 'test-key', 'local_ai_course_assistant');

        // NB: debuginfo is set AFTER construction on purpose. moodle_exception
        // folds debuginfo into the message whenever PHPUNIT_TEST is defined
        // (lib/classes/exception/moodle_exception.php), so an exception built
        // with debuginfo passed to the constructor would satisfy this assertion
        // through getMessage() alone and the test could never fail -- while in
        // production, where debugdisplay is off, the detail would still be lost.
        // Assigning it afterwards keeps it out of the message and makes the
        // assertion actually exercise the fix.
        $err = new \moodle_exception('chat:error', 'local_ai_course_assistant');
        $err->debuginfo = 'HTTP 418: teapot detail from the backend';
        \local_ai_course_assistant\provider\stub_provider::$throw_next = $err;

        $row = \local_ai_course_assistant\backend_probe::probe_chat();
        $detail = is_array($row) ? implode(' ', array_map('strval', $row)) : (string) $row;
        $this->assertStringContainsString('teapot detail from the backend', $detail);
    }

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
