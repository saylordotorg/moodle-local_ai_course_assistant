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
 * Consent gate before a conversation is escalated to the support desk
 * (security finding #40).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\zendesk_client::should_send_now
 */
final class zendesk_consent_test extends \advanced_testcase {

    public function test_blocked_by_default_without_consent(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        // Default: require_consent is on, learner has not accepted first-run consent.
        $this->assertFalse(zendesk_client::should_send_now((int) $user->id));
    }

    public function test_allowed_after_consent(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        set_user_preference('aica_sola_consent_given', time(), $user->id);
        $this->assertTrue(zendesk_client::should_send_now((int) $user->id));
    }

    public function test_allowed_when_requirement_disabled(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        set_config('zendesk_require_consent', 0, 'local_ai_course_assistant');
        // No consent recorded, but the admin disabled the requirement.
        $this->assertTrue(zendesk_client::should_send_now((int) $user->id));
    }
}
