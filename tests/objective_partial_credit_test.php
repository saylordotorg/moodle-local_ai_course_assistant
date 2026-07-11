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
 * Partial-credit (continuous) mastery observations (v6.8.29).
 *
 * A stored score in [0,1] contributes fractionally to the Beta posterior instead
 * of the binary iscorrect; attempts without a score behave exactly as before.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\objective_manager
 */
final class objective_partial_credit_test extends \advanced_testcase {

    /** @var int */
    private $courseid;
    /** @var int */
    private $userid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Isolate the estimator from time-decay for a deterministic assertion.
        set_config('mastery_decay_enabled', 0, 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->courseid = (int) $course->id;
        $this->userid = (int) $user->id;
    }

    private function make_objective(): int {
        return objective_manager::create($this->courseid, 'Constructs an argument', 'CLO-1');
    }

    public function test_partial_credit_lands_between_wrong_and_right(): void {
        // Same objective assessed once with a 0.8 partial-credit attempt.
        $oid = $this->make_objective();
        objective_manager::record_attempt($this->userid, $this->courseid, $oid,
            true, 'rubric', 1.0, null, null, 0.8);
        $partial = objective_manager::compute_mastery($this->userid, $oid)['score'];

        // Compare against a fully-correct and a fully-wrong single attempt on
        // separate objectives (same prior, weight, no decay).
        $oidc = $this->make_objective();
        objective_manager::record_attempt($this->userid, $this->courseid, $oidc, true, 'quiz', 1.0);
        $correct = objective_manager::compute_mastery($this->userid, $oidc)['score'];

        $oidw = $this->make_objective();
        objective_manager::record_attempt($this->userid, $this->courseid, $oidw, false, 'quiz', 1.0);
        $wrong = objective_manager::compute_mastery($this->userid, $oidw)['score'];

        $this->assertGreaterThan($wrong, $partial);
        $this->assertLessThan($correct, $partial);
    }

    public function test_score_of_one_matches_binary_correct(): void {
        $oids = $this->make_objective();
        objective_manager::record_attempt($this->userid, $this->courseid, $oids,
            true, 'rubric', 1.0, null, null, 1.0);
        $scored = objective_manager::compute_mastery($this->userid, $oids)['score'];

        $oidb = $this->make_objective();
        objective_manager::record_attempt($this->userid, $this->courseid, $oidb, true, 'quiz', 1.0);
        $binary = objective_manager::compute_mastery($this->userid, $oidb)['score'];

        $this->assertEqualsWithDelta($binary, $scored, 0.0001);
    }

    public function test_record_attempt_rounds_iscorrect_from_score(): void {
        global $DB;
        $oid = $this->make_objective();
        $id = objective_manager::record_attempt($this->userid, $this->courseid, $oid,
            false, 'rubric', 1.0, null, null, 0.7);
        $row = $DB->get_record(objective_manager::TABLE_ATTS, ['id' => $id]);
        $this->assertEquals(1, (int) $row->iscorrect); // 0.7 >= 0.5
        $this->assertEqualsWithDelta(0.7, (float) $row->score, 0.0001);
    }
}
