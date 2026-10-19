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

use local_ai_course_assistant\autoupgrade\roles;

/**
 * Every automatic-upgrade role has a label string (7.10.0).
 *
 * model_upgrades.php, the notification emails and the switcher's refusal
 * messages all build the label as get_string('autoupgrade:role_' . $role). A
 * role added to autoupgrade\roles without its string raises a missing-string
 * error at developer debug (the essay role did, from 7.8.2 until 7.10.0).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\autoupgrade\roles
 */
final class autoupgrade_role_strings_test extends \advanced_testcase {
    public function test_every_role_has_a_label_string(): void {
        $this->resetAfterTest();
        $strings = get_string_manager();
        $roles = array_keys(roles::ROLES);
        $this->assertContains('essay', $roles, 'the role list this guard walks is not the one the product uses');
        $this->assertGreaterThanOrEqual(8, count($roles));

        foreach ($roles as $role) {
            $identifier = 'autoupgrade:role_' . $role;
            $this->assertTrue(
                $strings->string_exists($identifier, 'local_ai_course_assistant'),
                "autoupgrade\\roles lists '{$role}' but lang/en has no '{$identifier}' string"
            );
            $label = get_string($identifier, 'local_ai_course_assistant');
            $this->assertNotSame('', trim($label), $identifier);
        }
        $this->assertDebuggingNotCalled();
    }
}
