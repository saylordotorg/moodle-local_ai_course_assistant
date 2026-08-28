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

use local_ai_course_assistant\provider\base_provider;

/**
 * The emergency chat stop must actually stop chat.
 *
 * Regression cover for the v7.2.0 staging finding: the emergency panel showed
 * chat DISABLED while chat kept answering. Three separate faults, each of which
 * was sufficient on its own:
 *
 *  1. base_provider.php referenced spend_guard unqualified from the
 *     \provider namespace with no import, so every call resolved to
 *     local_ai_course_assistant\provider\spend_guard -- a class that does not
 *     exist -- and threw a fatal Error into a `catch (\Throwable $ignore)`.
 *     The spend cap, cap failover and per-call failover chain were all inert.
 *  2. CAP_BLOCKED was answered by switching to the failover provider. That is
 *     right for a spend cap and exactly wrong for a kill switch.
 *  3. enforce_learner_guards() returned early for site admins, and the switch
 *     was being tested from an admin session.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\base_provider
 * @covers     \local_ai_course_assistant\spend_guard
 */
final class emergency_chat_stop_test extends \advanced_testcase {

    public function test_spend_guard_resolves_from_the_provider_namespace(): void {
        // Fault 1 in isolation. The guard is wrapped in a Throwable catch, so a
        // missing import produces no symptom at all -- only this assertion does.
        $this->assertTrue(
            class_exists(\local_ai_course_assistant\spend_guard::class),
            'spend_guard must be resolvable by the name base_provider refers to it by.'
        );
        $ref = new \ReflectionClass(base_provider::class);
        $src = file_get_contents($ref->getFileName());
        $this->assertMatchesRegularExpression(
            '/^use\s+local_ai_course_assistant\\\\spend_guard\s*;/m',
            $src,
            'base_provider must import spend_guard; without it every guard call '
                . 'throws a fatal Error straight into a silent catch.'
        );
    }

    public function test_check_reports_blocked_when_the_stop_is_engaged(): void {
        $this->resetAfterTest();
        set_config('emergency_chat_disabled', '1', 'local_ai_course_assistant');

        $this->assertTrue(spend_guard::emergency_chat_stopped());
        $this->assertSame(spend_guard::CAP_BLOCKED, spend_guard::check(0, 'chat'));
        $this->assertSame(spend_guard::CAP_BLOCKED, spend_guard::check(0, null));
    }

    public function test_stop_is_distinguishable_from_a_spend_cap(): void {
        $this->resetAfterTest();
        // A cap that is merely exhausted must NOT look like an emergency stop,
        // or the failover path would be disabled for ordinary spend blocks.
        set_config('spend_cap_site', '0.01', 'local_ai_course_assistant');

        $this->assertFalse(
            spend_guard::emergency_chat_stopped(),
            'A spend cap must not be reported as an emergency stop; they are '
                . 'answered differently -- one fails over, the other refuses.'
        );
    }

    /**
     * Assert the callable throws specifically because of the emergency stop.
     *
     * expectException(moodle_exception) alone is not regression cover here: on a
     * site with no provider configured both factories throw anyway, so such a
     * test passes with the fix reverted. The message has to be checked.
     *
     * @param callable $fn
     * @param string $context
     */
    private function assert_refuses_with_emergency_message(callable $fn, string $context): void {
        $expected = \local_ai_course_assistant\branding::str('emergency:chat_stopped');
        try {
            $fn();
            $this->fail("{$context}: expected a refusal while the emergency stop was engaged.");
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(
                $expected,
                $e->getMessage(),
                "{$context}: threw, but not because of the emergency stop -- which is "
                    . 'what this test exists to detect.'
            );
        }
    }

    public function test_chat_refuses_for_a_site_admin_while_stopped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('emergency_chat_disabled', '1', 'local_ai_course_assistant');

        $this->assert_refuses_with_emergency_message(
            fn() => base_provider::create_from_config(),
            'create_from_config as admin'
        );
    }

    public function test_the_comparison_path_also_refuses_while_stopped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('emergency_chat_disabled', '1', 'local_ai_course_assistant');

        // sse.php reaches the provider through this factory too, and it carried
        // no spend or emergency check of its own at all.
        $this->assert_refuses_with_emergency_message(
            fn() => base_provider::create_for_comparison('openai', 'gpt-4o-mini', 0),
            'create_for_comparison as admin'
        );
    }

    public function test_diagnostics_still_reach_the_provider_while_stopped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('emergency_chat_disabled', '1', 'local_ai_course_assistant');

        // backend_probe and health_check are what an operator opens DURING the
        // incident to decide whether it is safe to clear the flag. If the stop
        // blocks them they report the backend as broken when the only thing
        // wrong is the operator's own switch.
        try {
            base_provider::create_from_config(0, true);
        } catch (\moodle_exception $e) {
            $this->assertStringNotContainsString(
                \local_ai_course_assistant\branding::str('emergency:chat_stopped'),
                $e->getMessage(),
                'The diagnostic path must not be blocked by the emergency stop.'
            );
        }
    }

    public function test_clearing_the_stop_lets_the_guard_pass_again(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('emergency_chat_disabled', '1', 'local_ai_course_assistant');
        unset_config('emergency_chat_disabled', 'local_ai_course_assistant');

        $this->assertFalse(spend_guard::emergency_chat_stopped());
        $this->assertSame(spend_guard::CAP_OK, spend_guard::check(0, 'chat'));
    }
}
