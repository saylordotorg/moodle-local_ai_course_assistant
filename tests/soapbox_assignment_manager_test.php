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
 * Soapbox assignment manager CRUD, clamp-on-write, and capability gating.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\soapbox_assignment_manager
 */
final class soapbox_assignment_manager_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $teacher;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('soapbox_max_seconds', 720, 'local_ai_course_assistant');
        set_config('soapbox_max_recordings', 3, 'local_ai_course_assistant');
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
    }

    public function test_create_clamps_and_persists(): void {
        $this->setUser($this->teacher);
        $id = soapbox_assignment_manager::create_assignment($this->course->id, [
            'name' => 'Elevator pitch',
            'ptype' => 'Persuasive',
            'mode' => 'video',
            'min_seconds' => 300,
            'max_seconds' => 3000, // above the 720 cap
            'max_attempts' => 0,
            'stored_attempts' => 9, // above the 3 cap
        ]);
        $this->assertGreaterThan(0, $id);

        $a = soapbox_assignment_manager::get_assignment($id);
        $this->assertSame('Elevator pitch', $a->name);
        $this->assertSame('persuasive', $a->ptype);
        $this->assertEquals(720, $a->max_seconds);
        $this->assertEquals(3, $a->stored_attempts);
        $this->assertEquals(0, $a->max_attempts);
        $this->assertEquals(1, $a->visible);
    }

    public function test_create_requires_name(): void {
        $this->setUser($this->teacher);
        $this->expectException(\moodle_exception::class);
        soapbox_assignment_manager::create_assignment($this->course->id, ['name' => '  ']);
    }

    public function test_update_reclamps_changed_fields(): void {
        $this->setUser($this->teacher);
        $id = soapbox_assignment_manager::create_assignment($this->course->id, [
            'name' => 'Case brief', 'max_seconds' => 400, 'stored_attempts' => 2,
        ]);
        soapbox_assignment_manager::update_assignment($id, ['max_seconds' => 5000, 'name' => 'Case brief v2']);
        $a = soapbox_assignment_manager::get_assignment($id);
        $this->assertSame('Case brief v2', $a->name);
        $this->assertEquals(720, $a->max_seconds); // re-clamped
        $this->assertEquals(2, $a->stored_attempts); // untouched, still valid
    }

    public function test_topics_crud(): void {
        $this->setUser($this->teacher);
        $aid = soapbox_assignment_manager::create_assignment($this->course->id, ['name' => 'Debate']);
        $t1 = soapbox_assignment_manager::save_topic($aid, ['title' => 'Free trade', 'sortorder' => 1]);
        $t2 = soapbox_assignment_manager::save_topic($aid, ['title' => 'Tariffs', 'sortorder' => 0]);
        $topics = soapbox_assignment_manager::get_topics($aid);
        $this->assertCount(2, $topics);
        $this->assertSame('Tariffs', $topics[0]->title); // sortorder 0 first

        soapbox_assignment_manager::save_topic($aid, ['id' => $t1, 'title' => 'Free trade (rev)', 'sortorder' => 2]);
        $this->assertSame('Free trade (rev)',
            soapbox_assignment_manager::get_topics($aid)[1]->title);

        soapbox_assignment_manager::delete_topic($t2);
        $this->assertCount(1, soapbox_assignment_manager::get_topics($aid));
    }

    public function test_delete_assignment_cascades_topics(): void {
        global $DB;
        $this->setUser($this->teacher);
        $aid = soapbox_assignment_manager::create_assignment($this->course->id, ['name' => 'Gone soon']);
        soapbox_assignment_manager::save_topic($aid, ['title' => 't']);
        soapbox_assignment_manager::delete_assignment($aid);
        $this->assertNull(soapbox_assignment_manager::get_assignment($aid));
        $this->assertEquals(0, $DB->count_records(
            soapbox_assignment_manager::T_TOPIC, ['assignid' => $aid]));
    }

    public function test_student_cannot_create(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        soapbox_assignment_manager::create_assignment($this->course->id, ['name' => 'Nope']);
    }
}
