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
 * Ownership check for the talking-avatar viewer (security finding #39).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\talking_avatar_session_manager::user_owns_session
 */
final class talking_avatar_ownership_test extends \advanced_testcase {

    public function test_owner_can_view_others_cannot(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        talking_avatar_session_manager::start(
            (int) $owner->id, (int) $course->id, 'did', 'persona-1', 'upstream-abc');

        $this->assertTrue(
            talking_avatar_session_manager::user_owns_session((int) $owner->id, 'did', 'upstream-abc'));
        $this->assertFalse(
            talking_avatar_session_manager::user_owns_session((int) $other->id, 'did', 'upstream-abc'),
            'A different user must not be treated as the owner of the session.');
    }

    public function test_empty_or_unknown_session_is_not_owned(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->assertFalse(
            talking_avatar_session_manager::user_owns_session((int) $user->id, 'did', ''));
        $this->assertFalse(
            talking_avatar_session_manager::user_owns_session((int) $user->id, 'did', 'does-not-exist'));
    }

    public function test_provider_must_match(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        talking_avatar_session_manager::start(
            (int) $owner->id, (int) $course->id, 'did', 'persona-1', 'upstream-xyz');
        // Same session id, wrong provider -> not owned.
        $this->assertFalse(
            talking_avatar_session_manager::user_owns_session((int) $owner->id, 'heygen', 'upstream-xyz'));
    }
}
