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
 * Tests for audit_logger (v5.3.22).
 *
 * audit_logger is the security-critical write path used by every
 * privacy-sensitive action: GDPR data download, conversation clear,
 * struggle classifier, milestone outreach, instructor-dashboard reveal,
 * SSE error capture, and so on. A silent regression here would
 * undermine our compliance posture across the board.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\audit_logger
 */
final class audit_logger_test extends \advanced_testcase {

    public function test_log_writes_a_row(): void {
        $this->resetAfterTest();
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        audit_logger::log('test_action', $user->id, $course->id, ['key' => 'value']);

        $row = $DB->get_record('local_ai_course_assistant_audit',
            ['action' => 'test_action', 'userid' => $user->id]);
        $this->assertNotFalse($row);
        $this->assertEquals($course->id, (int)$row->courseid);
        $details = json_decode($row->details, true);
        $this->assertEquals('value', $details['key']);
        $this->assertGreaterThan(0, (int)$row->timecreated);
    }

    public function test_log_with_zero_courseid_is_allowed(): void {
        // Several audit actions are not tied to a course (e.g. GDPR data
        // download, sitewide config changes). The log must accept courseid=0.
        $this->resetAfterTest();
        global $DB;
        $user = $this->getDataGenerator()->create_user();

        audit_logger::log('sitewide_action', $user->id, 0, []);

        $row = $DB->get_record('local_ai_course_assistant_audit',
            ['action' => 'sitewide_action', 'userid' => $user->id]);
        $this->assertNotFalse($row);
        $this->assertEquals(0, (int)$row->courseid);
    }

    public function test_log_serialises_complex_details_as_json(): void {
        $this->resetAfterTest();
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $details = [
            'nested' => ['array' => 'works'],
            'list' => [1, 2, 3],
            'special_chars' => "quotes ' and \" and \\ backslash",
        ];

        audit_logger::log('complex_details', $user->id, 0, $details);

        $row = $DB->get_record('local_ai_course_assistant_audit',
            ['action' => 'complex_details', 'userid' => $user->id]);
        $decoded = json_decode($row->details, true);
        $this->assertEquals($details, $decoded,
            'details must round-trip through JSON without lossy encoding.');
    }

    public function test_get_user_logs_returns_only_that_user(): void {
        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        audit_logger::log('a1', $user1->id, 0);
        audit_logger::log('a2', $user1->id, 0);
        audit_logger::log('b1', $user2->id, 0);

        $logs = audit_logger::get_user_logs($user1->id);

        $this->assertCount(2, $logs);
        foreach ($logs as $row) {
            $this->assertEquals($user1->id, (int)$row->userid);
        }
    }

    public function test_get_course_logs_returns_only_that_course(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        audit_logger::log('a', $user->id, $course1->id);
        audit_logger::log('b', $user->id, $course2->id);

        $logs = audit_logger::get_course_logs($course1->id);

        $this->assertCount(1, $logs);
        $row = reset($logs);
        $this->assertEquals('a', $row->action);
    }

    public function test_clean_old_logs_purges_past_retention(): void {
        $this->resetAfterTest();
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        // Plant rows with an old timecreated that pre-dates the retention window.
        $oldtime = time() - (400 * 86400);
        $DB->insert_record('local_ai_course_assistant_audit', (object)[
            'action' => 'old_action', 'userid' => $user->id,
            'courseid' => 0, 'details' => '{}', 'timecreated' => $oldtime,
        ]);
        $DB->insert_record('local_ai_course_assistant_audit', (object)[
            'action' => 'recent_action', 'userid' => $user->id,
            'courseid' => 0, 'details' => '{}', 'timecreated' => time(),
        ]);

        audit_logger::clean_old_logs(365);

        $this->assertFalse($DB->record_exists('local_ai_course_assistant_audit',
            ['action' => 'old_action']),
            'Rows older than the retention window must be purged.');
        $this->assertTrue($DB->record_exists('local_ai_course_assistant_audit',
            ['action' => 'recent_action']),
            'Recent rows must not be purged.');
    }

    public function test_log_records_ipaddress_and_useragent_when_available(): void {
        // Best-effort: when the current request has an IP and UA, the audit
        // row should capture them. In a unit test these are not always
        // populated, so we only assert the columns exist (not throw).
        $this->resetAfterTest();
        global $DB;
        $user = $this->getDataGenerator()->create_user();

        audit_logger::log('ip_check', $user->id, 0);

        $row = $DB->get_record('local_ai_course_assistant_audit',
            ['action' => 'ip_check', 'userid' => $user->id]);
        // Columns must exist; values may be null/empty in test env.
        $this->assertObjectHasProperty('ipaddress', $row);
        $this->assertObjectHasProperty('useragent', $row);
    }
}
