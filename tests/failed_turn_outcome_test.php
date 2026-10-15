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
    public function test_a_paused_chat_is_blocked_whatever_was_thrown(): void {
        $this->assertSame('blocked', conversation_manager::failed_turn_outcome(true, new \moodle_exception('error', 'local_ai_course_assistant')));
        $this->assertSame('blocked', conversation_manager::failed_turn_outcome(true, new \RuntimeException('anything')));
    }

    public function test_a_guard_that_refused_on_purpose_is_blocked(): void {
        // A quiz lock, rate limit or spend cap with no failover.
        $refused = new provider\chat_refused_exception('Too many AI requests; please wait a moment and try again.');
        $this->assertSame('blocked', conversation_manager::failed_turn_outcome(false, $refused));
    }

    public function test_the_refusal_is_still_an_ordinary_moodle_exception_with_the_old_code_and_message(): void {
        $refused = new provider\chat_refused_exception('Chat is paused.');
        $this->assertInstanceOf(\moodle_exception::class, $refused);
        $this->assertSame('error', $refused->errorcode);
        $this->assertStringContainsString('Chat is paused.', $refused->getMessage());
    }

    public function test_provider_and_setup_faults_are_failures(): void {
        foreach (
            [
                new \moodle_exception('error', 'local_ai_course_assistant', '', 'No tokens received from openai'),
                new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null, 'HTTP 400: x'),
                new \moodle_exception('chat:error_notconfigured', 'local_ai_course_assistant'),
                new \moodle_exception('chat:error_auth', 'local_ai_course_assistant'),
                new \moodle_exception('chat:error_unavailable', 'local_ai_course_assistant'),
            ] as $fault
        ) {
            $this->assertSame('provider_error', conversation_manager::failed_turn_outcome(false, $fault), $fault->errorcode);
        }
    }

    /**
     * v7.8.8 filed these as refusals: they arrive before a provider exists and with a code that does not start
     * with chat:error. A database or coding error while assembling the prompt fails every turn on the site and
     * must read as a failure.
     */
    public function test_a_server_fault_before_the_provider_is_built_is_a_failure(): void {
        $this->assertSame('provider_error', conversation_manager::failed_turn_outcome(false, new \dml_read_exception('select failed')));
        $this->assertSame('provider_error', conversation_manager::failed_turn_outcome(false, new \coding_exception('bug in the context builder')));
        $this->assertSame('provider_error', conversation_manager::failed_turn_outcome(false, new \moodle_exception('invalidcourseid')));
    }

    /**
     * sse.php is a script and cannot be loaded by a test, so pin the call site by its source (whitespace
     * collapsed): the classification must be given the pause flag and the exception, a blocked turn must not be
     * audited as an sse_error, and the result must reach the stored row.
     */
    public function test_sse_classifies_a_failed_turn_before_it_audits_and_stores_it(): void {
        global $CFG;
        $source = preg_replace('/\s+/', ' ', file_get_contents($CFG->dirroot . '/local/ai_course_assistant/sse.php'));
        $classify = '$failedoutcome = \\local_ai_course_assistant\\conversation_manager::failed_turn_outcome( '
            . '$auditaction === \'chat_paused\', $e );';
        $this->assertStringContainsString($classify, $source);
        $this->assertStringContainsString('$auditaction = \'chat_blocked\';', $source);
        $audit = '\\local_ai_course_assistant\\audit_logger::log( $auditaction,';
        $this->assertGreaterThan(strpos($source, '$failedoutcome = '), strpos($source, $audit), 'classified before audited');
        $this->assertStringContainsString('$learnernotice ?? \'\', $provider ?? null, $failedoutcome );', $source);
    }

    /**
     * Every guard that refuses a turn on purpose must throw the dedicated exception, or its turns would be
     * counted as failures by the alert.
     */
    public function test_every_guard_that_refuses_a_turn_throws_the_dedicated_exception(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/classes/provider/base_provider.php');
        $this->assertSame(5, substr_count($source, 'throw new chat_refused_exception('));
        foreach (['emergency:chat_stopped', 'quizlock:blocked', 'Too many AI requests', 'SOLA spend cap reached'] as $guard) {
            $this->assertMatchesRegularExpression(
                '/throw new chat_refused_exception\(\s*[^;]*' . preg_quote($guard, '/') . '/',
                $source,
                $guard
            );
        }
    }
}
