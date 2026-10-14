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
 * Who may read the audit log (v7.8.8).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\audit_access
 */
final class audit_access_test extends \advanced_testcase {
    /**
     * A user holding only the given capability at system level.
     *
     * @param string $capability
     * @return \stdClass
     */
    private function user_with(string $capability): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Audit test role', 'audittest' . random_int(1000, 9999), '');
        assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        return $user;
    }

    public function test_an_ordinary_user_may_not_read_the_log_and_is_told_which_capability_to_ask_for(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(audit_access::can_view());
        try {
            audit_access::require_view();
            $this->fail('An ordinary user must be refused.');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString('View the AI tutor audit log', $e->getMessage());
        }
    }

    public function test_the_audit_capability_alone_opens_the_log_without_site_config(): void {
        $this->resetAfterTest();
        $user = $this->user_with('local/ai_course_assistant:viewaudit');
        $this->setUser($user);
        $this->assertTrue(audit_access::can_view());
        $this->assertFalse(
            has_capability('moodle/site:config', \context_system::instance()),
            'The point of the capability: reading the log does not need the right to change settings.'
        );
        audit_access::require_view();
    }

    public function test_site_config_still_opens_the_log_so_nobody_loses_access(): void {
        $this->resetAfterTest();
        $user = $this->user_with('moodle/site:config');
        $this->setUser($user);
        $this->assertFalse(has_capability('local/ai_course_assistant:viewaudit', \context_system::instance()));
        $this->assertTrue(audit_access::can_view());
    }

    public function test_an_administrator_may_read_it(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertTrue(audit_access::can_view());
    }

    public function test_the_capability_is_personal_data_risk_and_held_by_no_role_by_default(): void {
        $this->resetAfterTest();
        $info = get_capability_info('local/ai_course_assistant:viewaudit');
        $this->assertNotNull($info);
        $this->assertSame('read', $info->captype);
        $this->assertSame(CONTEXT_SYSTEM, (int) $info->contextlevel);
        $this->assertSame(RISK_PERSONAL, (int) $info->riskbitmask & RISK_PERSONAL);
        $granted = get_roles_with_capability('local/ai_course_assistant:viewaudit', CAP_ALLOW);
        $this->assertSame([], $granted, 'No role may hold it until an administrator grants it.');
    }

    public function test_the_page_asks_the_gate_not_site_config_directly(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/audit_log.php');
        $this->assertStringContainsString('audit_access::require_view()', $source);
        $this->assertStringNotContainsString("require_capability('moodle/site:config'", $source);
    }
}
