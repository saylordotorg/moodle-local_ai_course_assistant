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
 * Which turns that ended in an exception count as a failure (v7.8.8).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\conversation_manager::failed_turn_outcome
 */
final class failed_turn_outcome_test extends \basic_testcase {
    public function test_a_paused_chat_is_blocked_even_when_a_provider_exists(): void {
        $this->assertSame('blocked', conversation_manager::failed_turn_outcome(true, false, 'error'));
        $this->assertSame('blocked', conversation_manager::failed_turn_outcome(true, true, 'chat:error'));
    }

    public function test_a_guard_refusal_before_any_provider_is_blocked(): void {
        // A spend cap, quiz lock, rate limit or off-topic lock: generic 'error' code, no provider built.
        $this->assertSame('blocked', conversation_manager::failed_turn_outcome(false, false, 'error'));
    }

    public function test_provider_and_setup_faults_are_failures(): void {
        $this->assertSame('provider_error', conversation_manager::failed_turn_outcome(false, true, 'error'), 'no tokens received');
        $this->assertSame('provider_error', conversation_manager::failed_turn_outcome(false, true, 'chat:error'));
        $this->assertSame('provider_error', conversation_manager::failed_turn_outcome(false, false, 'chat:error_notconfigured'));
        $this->assertSame('provider_error', conversation_manager::failed_turn_outcome(false, false, 'chat:error_auth'));
        $this->assertSame('provider_error', conversation_manager::failed_turn_outcome(false, false, 'chat:error_unavailable'));
    }
}
