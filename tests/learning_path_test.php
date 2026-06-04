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

use local_ai_course_assistant\program\learning_path;
use local_ai_course_assistant\program\stub_program_source;

/**
 * Unit tests for the v5.9.0 learning_path aggregator (readiness + full_path),
 * driven by an in-memory stub program source.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\program\learning_path
 */
final class learning_path_test extends \advanced_testcase {

    /**
     * A 3-course ordered program; learner allocated; sitting on the middle course.
     *
     * @param int $userid
     * @param array $courseids [c1, c2, c3]
     * @return array Stub fixture.
     */
    private function threecourse_cfg(int $userid, array $courseids): array {
        [$c1, $c2, $c3] = $courseids;
        return [
            'available' => true,
            'user_programs' => [$userid => [['programid' => 10, 'name' => 'Business Path']]],
            'program_courses' => [10 => [
                ['itemid' => 101, 'courseid' => $c1, 'coursename' => 'Intro', 'visible' => true, 'ordered' => true, 'position' => 1],
                ['itemid' => 102, 'courseid' => $c2, 'coursename' => 'Mid', 'visible' => true, 'ordered' => true, 'position' => 2],
                ['itemid' => 103, 'courseid' => $c3, 'coursename' => 'Adv', 'visible' => true, 'ordered' => true, 'position' => 3],
            ]],
        ];
    }

    /** An always-available stub source with no programs (mastery-readiness tests). */
    private function avail_stub(): stub_program_source {
        return new stub_program_source(['available' => true]);
    }

    /** Master the first $count objectives in $courseid for $userid (enough attempts to cross the bar). */
    private function master(int $userid, int $courseid, array $objids, int $count): void {
        for ($i = 0; $i < $count; $i++) {
            for ($a = 0; $a < 8; $a++) {
                objective_manager::record_attempt($userid, $courseid, $objids[$i], true);
            }
        }
    }

    /** Create $n objectives in $courseid, return their ids. */
    private function objectives(int $courseid, int $n): array {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[$i] = objective_manager::create($courseid, "Objective $i");
        }
        return $ids;
    }

    public function test_readiness_false_when_flag_off(): void {
        $this->resetAfterTest();
        set_config('learning_path_enabled', '0', 'local_ai_course_assistant');
        $lp = new learning_path(new stub_program_source($this->threecourse_cfg(100, [201, 202, 203])));
        $r = $lp->readiness(100, 202);
        $this->assertFalse($r['ready']);
        $this->assertNull($r['reason']);
        $this->assertNull($r['next_course']);
    }

    public function test_readiness_mastery_at_threshold(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $c = $this->getDataGenerator()->create_course();
        set_config('learning_path_enabled', '1', 'local_ai_course_assistant');
        set_config('mastery_enabled', '1', 'local_ai_course_assistant');
        $objids = $this->objectives((int) $c->id, 5);
        // Master 4 of 5 = 80%, exactly the default threshold.
        $this->master((int) $user->id, (int) $c->id, $objids, 4);
        $lp = new learning_path($this->avail_stub());
        $r = $lp->readiness((int) $user->id, (int) $c->id);
        $this->assertTrue($r['ready']);
        $this->assertSame('mastery', $r['reason']);
    }

    public function test_readiness_not_ready_below_threshold(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $c = $this->getDataGenerator()->create_course();
        set_config('learning_path_enabled', '1', 'local_ai_course_assistant');
        set_config('mastery_enabled', '1', 'local_ai_course_assistant');
        $objids = $this->objectives((int) $c->id, 5);
        // Master only 3 of 5 = 60%, below threshold.
        $this->master((int) $user->id, (int) $c->id, $objids, 3);
        $lp = new learning_path($this->avail_stub());
        $r = $lp->readiness((int) $user->id, (int) $c->id);
        $this->assertFalse($r['ready']);
    }

    public function test_full_path_null_when_flag_off(): void {
        $this->resetAfterTest();
        set_config('learning_path_enabled', '0', 'local_ai_course_assistant');
        $lp = new learning_path(new stub_program_source($this->threecourse_cfg(100, [201, 202, 203])));
        $this->assertNull($lp->full_path(100, 202));
    }

    public function test_full_path_structure(): void {
        $this->resetAfterTest();
        set_config('learning_path_enabled', '1', 'local_ai_course_assistant');
        $lp = new learning_path(new stub_program_source($this->threecourse_cfg(100, [201, 202, 203])));
        $model = $lp->full_path(100, 202);

        $this->assertNotNull($model);
        $this->assertSame('Business Path', $model['program']['name']);
        $this->assertSame(2, $model['position']['index']);
        $this->assertSame(3, $model['position']['total']);
        $this->assertCount(3, $model['courses']);
        // Learner is on the middle course; the others are upcoming (no completion in the stub).
        $this->assertSame('upcoming', $model['courses'][0]['status']);
        $this->assertSame('current', $model['courses'][1]['status']);
        $this->assertTrue($model['courses'][1]['is_current']);
        $this->assertSame(202, $model['courses'][1]['courseid']);
        $this->assertSame(2, $model['courses'][1]['position']);
    }

    public function test_external_get_learning_path_empty_without_program(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user((int) $user->id, (int) $course->id, 'student');
        set_config('learning_path_enabled', '1', 'local_ai_course_assistant');
        $this->setUser($user);

        $raw = \local_ai_course_assistant\external\get_learning_path::execute((int) $course->id);
        // clean_returnvalue enforces the declared external structure.
        $result = \core_external\external_api::clean_returnvalue(
            \local_ai_course_assistant\external\get_learning_path::execute_returns(),
            $raw
        );
        // No Programs plugin tables in the test DB -> live source unavailable -> empty path.
        $this->assertFalse($result['has_path']);
        $this->assertSame('', $result['program_name']);
        $this->assertSame([], $result['courses']);
    }

    public function test_external_get_learning_path_requires_enrolment(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        // Not enrolled: no local/ai_course_assistant:use in the course context.
        $this->setUser($user);
        $this->expectException(\moodle_exception::class);
        \local_ai_course_assistant\external\get_learning_path::execute((int) $course->id);
    }
}
