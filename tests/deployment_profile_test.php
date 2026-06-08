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
 * Unit tests for apply-once deployment presets (v5.10.0).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\deployment_profile
 */
final class deployment_profile_test extends \advanced_testcase {

    public function test_apply_small_context_writes_expected_keys(): void {
        $this->resetAfterTest();
        deployment_profile::apply('self_hosted_small');
        $this->assertSame('8192', get_config('local_ai_course_assistant', 'backend_context_tokens'));
        $this->assertSame('768', get_config('local_ai_course_assistant', 'max_tokens'));
        $this->assertSame('6', get_config('local_ai_course_assistant', 'maxhistory'));
        $this->assertSame('4', get_config('local_ai_course_assistant', 'rag_topk'));
        $this->assertSame('self_hosted_small', get_config('local_ai_course_assistant', 'deployment_profile'));
    }

    public function test_apply_hosted_resets_window(): void {
        $this->resetAfterTest();
        deployment_profile::apply('hosted_large');
        $this->assertSame('0', get_config('local_ai_course_assistant', 'backend_context_tokens'));
        $this->assertSame('1024', get_config('local_ai_course_assistant', 'max_tokens'));
        $this->assertSame('hosted_large', get_config('local_ai_course_assistant', 'deployment_profile'));
    }

    public function test_unknown_profile_throws(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);
        deployment_profile::apply('nope');
    }
}
