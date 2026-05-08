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

use local_ai_course_assistant\external\submit_feedback;
use local_ai_course_assistant\external\save_practice_score;
use local_ai_course_assistant\external\email_study_notes;
use local_ai_course_assistant\external\get_analytics_overall;
use local_ai_course_assistant\external\get_analytics_by_course;

/**
 * Additional external service contract tests (v5.3.23).
 *
 * Continues the v5.3.20 work for the next batch of services. Same
 * pattern: capability/parameter rejection, happy-path state-change,
 * clean_returnvalue round-trip.
 *
 * Coverage in this file: submit_feedback, save_practice_score,
 * email_study_notes, get_analytics_overall, get_analytics_by_course.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class external_services_more_test extends \advanced_testcase {

    /**
     * Common: enrolled student + setUser. Same pattern as v5.3.20.
     *
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function enrolled_student(): array {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        return [$course, $user];
    }

    // ───────────────────────────────────────────────────────────
    // submit_feedback
    // ───────────────────────────────────────────────────────────

    public function test_submit_feedback_writes_row_with_clamped_rating(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $user] = $this->enrolled_student();

        // Pass a rating outside 1-5; the service must clamp to the range.
        $result = submit_feedback::execute(
            (int)$course->id, 99, 'Great chat session.',
            'Chrome 134', 'macOS', 'desktop', '1920x1080', 'Mozilla/5.0', 'https://x/y');

        $this->assertSame(['success' => true], $result);
        $row = $DB->get_record('local_ai_course_assistant_feedback',
            ['userid' => $user->id, 'courseid' => $course->id]);
        $this->assertNotFalse($row);
        $this->assertEquals(5, (int)$row->rating, 'Rating must be clamped to <=5.');
        $this->assertEquals('Great chat session.', $row->comment);
    }

    public function test_submit_feedback_clamps_negative_rating(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $user] = $this->enrolled_student();

        submit_feedback::execute(
            (int)$course->id, -3, '',
            '', '', '', '', '', '');

        $row = $DB->get_record('local_ai_course_assistant_feedback',
            ['userid' => $user->id, 'courseid' => $course->id]);
        $this->assertEquals(1, (int)$row->rating, 'Rating must be clamped to >=1.');
    }

    public function test_submit_feedback_truncates_oversized_fields(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $user] = $this->enrolled_student();

        $long_comment = str_repeat('x', 5000);
        $long_ua = str_repeat('y', 1000);
        submit_feedback::execute(
            (int)$course->id, 3, $long_comment,
            '', '', '', '', $long_ua, '');

        $row = $DB->get_record('local_ai_course_assistant_feedback',
            ['userid' => $user->id, 'courseid' => $course->id]);
        $this->assertLessThanOrEqual(2000, strlen($row->comment),
            'Comment must be truncated to fit DB column.');
        $this->assertLessThanOrEqual(500, strlen($row->user_agent),
            'User agent must be truncated to fit DB column.');
    }

    public function test_submit_feedback_clean_returnvalue_round_trip(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled_student();

        $result = submit_feedback::execute(
            (int)$course->id, 4, 'OK', '', '', '', '', '', '');
        $clean = \core_external\external_api::clean_returnvalue(
            submit_feedback::execute_returns(), $result);
        $this->assertEquals($result, $clean);
    }

    // ───────────────────────────────────────────────────────────
    // save_practice_score
    // ───────────────────────────────────────────────────────────

    public function test_save_practice_score_writes_row(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $user] = $this->enrolled_student();

        // Signature: (courseid, rubricid, session_type, scores_json,
        //            overall_score, ai_feedback, session_duration)
        // Scores is a JSON list of {name, score[, feedback]} entries.
        $scores = json_encode([
            ['name' => 'clarity', 'score' => 4],
            ['name' => 'depth',   'score' => 3, 'feedback' => 'Could go deeper'],
        ]);
        $result = save_practice_score::execute(
            (int)$course->id, 0, 'conversation', $scores, 4, 'Good session.', 180);

        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('scoreid', $result);
        $this->assertGreaterThan(0, $result['scoreid']);
        $row = $DB->get_record('local_ai_course_assistant_practice_scores',
            ['userid' => $user->id, 'courseid' => $course->id]);
        $this->assertNotFalse($row);
        $this->assertEquals('conversation', $row->session_type);
    }

    public function test_save_practice_score_returns_failure_for_empty_scores(): void {
        // Defensive: a malformed scores JSON that decodes to nothing
        // useful must not insert a row.
        $this->resetAfterTest();
        global $DB;
        [$course, $user] = $this->enrolled_student();

        $result = save_practice_score::execute(
            (int)$course->id, 0, 'conversation', '[]', 0, '', 0);

        $this->assertFalse($result['success']);
        $this->assertEquals(0,
            $DB->count_records('local_ai_course_assistant_practice_scores',
                ['userid' => $user->id]));
    }

    // ───────────────────────────────────────────────────────────
    // email_study_notes
    // ───────────────────────────────────────────────────────────

    public function test_email_study_notes_returns_success_shape(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled_student();

        $result = email_study_notes::execute((int)$course->id,
            "Notes:\n- Photosynthesis happens in chloroplasts.\n- ATP is energy.");

        $this->assertArrayHasKey('success', $result);
        // Email may not actually send in test env (no mail config), but
        // the call must not throw and the return shape must round-trip.
        $clean = \core_external\external_api::clean_returnvalue(
            email_study_notes::execute_returns(), $result);
        $this->assertEquals($result, $clean);
    }

    // ───────────────────────────────────────────────────────────
    // get_analytics_overall
    // ───────────────────────────────────────────────────────────

    public function test_get_analytics_overall_requires_admin_capability(): void {
        $this->resetAfterTest();
        // Plain student must NOT be allowed to read site-wide analytics.
        [$course, $user] = $this->enrolled_student();

        $this->expectException(\required_capability_exception::class);
        get_analytics_overall::execute(0, 0);
    }

    public function test_get_analytics_overall_admin_returns_struct(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = get_analytics_overall::execute(0, 0);

        $this->assertIsArray($result);
        // The exact keys are defined in execute_returns; round-trip the
        // result through it so we know the contract holds.
        $clean = \core_external\external_api::clean_returnvalue(
            get_analytics_overall::execute_returns(), $result);
        $this->assertEquals($result, $clean);
    }

    // ───────────────────────────────────────────────────────────
    // get_analytics_by_course
    // ───────────────────────────────────────────────────────────

    public function test_get_analytics_by_course_admin_returns_struct(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = get_analytics_by_course::execute(0, 0);

        $clean = \core_external\external_api::clean_returnvalue(
            get_analytics_by_course::execute_returns(), $result);
        $this->assertEquals($result, $clean);
    }

    public function test_get_analytics_by_course_requires_admin(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled_student();

        $this->expectException(\required_capability_exception::class);
        get_analytics_by_course::execute(0, 0);
    }
}
