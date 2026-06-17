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

namespace local_ai_course_assistant\external;

/**
 * Tests for the v6.8.1 external services that replaced the consent.php and
 * radar_cite.php AJAX_SCRIPT endpoints (CONTRIB-10574 #86).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\record_consent
 * @covers     \local_ai_course_assistant\external\get_radar_citation
 */
final class external_consent_citation_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_record_consent_sets_preference(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertEmpty(get_user_preferences('aica_sola_consent_given', '', $user->id));
        $result = record_consent::execute();
        $this->assertTrue($result['success']);
        $this->assertNotEmpty(get_user_preferences('aica_sola_consent_given', '', $user->id));
    }

    public function test_get_radar_citation_requires_siteconfig(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        get_radar_citation::execute(1);
    }

    public function test_get_radar_citation_missing_row_returns_not_ok(): void {
        $this->setAdminUser();
        $result = get_radar_citation::execute(999999);
        $this->assertFalse($result['ok']);
        $this->assertSame('', $result['message']);
    }

    public function test_get_radar_citation_returns_row(): void {
        global $DB;
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $id = $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => 0,
            'userid' => $student->id,
            'courseid' => $course->id,
            'role' => 'user',
            'message' => 'What is osmosis?',
            'timecreated' => time(),
        ]);
        $result = get_radar_citation::execute((int) $id);
        $this->assertTrue($result['ok']);
        $this->assertSame('user', $result['role']);
        $this->assertSame('What is osmosis?', $result['message']);
        // The author must be pseudonymized, never the real name.
        $this->assertStringNotContainsString($student->firstname, $result['who']);
    }
}
