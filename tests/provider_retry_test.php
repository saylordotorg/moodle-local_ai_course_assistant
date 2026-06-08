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

namespace local_ai_course_assistant\provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for the bounded transient-retry wrapper (v5.10.0).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\base_provider::with_transient_retry
 */
final class provider_retry_test extends \advanced_testcase {

    public function test_retries_then_succeeds(): void {
        $this->resetAfterTest();
        set_config('backend_retry_attempts', 2, 'local_ai_course_assistant');
        $calls = 0;
        $result = base_provider::with_transient_retry(function () use (&$calls) {
            $calls++;
            if ($calls < 2) {
                throw base_provider::transient_http_exception(429, null);
            }
            return 'ok';
        }, 0);
        $this->assertSame('ok', $result);
        $this->assertSame(2, $calls);
    }

    public function test_gives_up_after_attempts(): void {
        $this->resetAfterTest();
        set_config('backend_retry_attempts', 2, 'local_ai_course_assistant');
        $calls = 0;
        $this->expectException(\moodle_exception::class);
        try {
            base_provider::with_transient_retry(function () use (&$calls) {
                $calls++;
                throw base_provider::transient_http_exception(503, null);
            }, 0);
        } finally {
            // 1 initial + 2 retries = 3 attempts.
            $this->assertSame(3, $calls);
        }
    }

    public function test_no_retry_after_first_byte(): void {
        $this->resetAfterTest();
        set_config('backend_retry_attempts', 2, 'local_ai_course_assistant');
        $calls = 0;
        try {
            base_provider::with_transient_retry(function () use (&$calls) {
                $calls++;
                throw base_provider::transient_http_exception(429, null);
            }, 5); // 5 tokens already streamed -> must NOT retry.
        } catch (\moodle_exception $e) {
            // expected
        }
        $this->assertSame(1, $calls);
    }

    public function test_non_transient_not_retried(): void {
        $this->resetAfterTest();
        set_config('backend_retry_attempts', 2, 'local_ai_course_assistant');
        $calls = 0;
        try {
            base_provider::with_transient_retry(function () use (&$calls) {
                $calls++;
                throw new \moodle_exception('chat:error', 'local_ai_course_assistant');
            }, 0);
        } catch (\moodle_exception $e) {
            // expected
        }
        $this->assertSame(1, $calls);
    }
}
