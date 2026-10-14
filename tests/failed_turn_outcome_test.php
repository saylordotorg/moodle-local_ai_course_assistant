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

    /**
     * sse.php is a script and cannot be loaded by a test, so pin the call site by its source (whitespace
     * collapsed): the classification must be given the pause flag, whether a provider exists and the error
     * code; a blocked turn must not be audited as an sse_error; and the result must reach the stored row.
     */
    public function test_sse_classifies_a_failed_turn_before_it_audits_and_stores_it(): void {
        global $CFG;
        $source = preg_replace('/\s+/', ' ', file_get_contents($CFG->dirroot . '/local/ai_course_assistant/sse.php'));
        $classify = '$failedoutcome = \\local_ai_course_assistant\\conversation_manager::failed_turn_outcome( '
            . '$auditaction === \'chat_paused\', is_object($provider ?? null), (string) ($e->errorcode ?? \'\') );';
        $this->assertStringContainsString($classify, $source);
        $this->assertStringContainsString('$auditaction = \'chat_blocked\';', $source);
        $audit = '\\local_ai_course_assistant\\audit_logger::log( $auditaction,';
        $this->assertGreaterThan(strpos($source, '$failedoutcome = '), strpos($source, $audit), 'classified before audited');
        $this->assertStringContainsString('$learnernotice ?? \'\', $provider ?? null, $failedoutcome );', $source);
    }
}
