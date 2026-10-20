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
 * The course-structure block in the prompt lists only what the learner can see (#341).
 *
 * It tested $section->visible and $cm->visible, so the names of sections and activities behind an
 * access restriction, and short summaries of restricted sections, reached every learner. It now uses
 * uservisible, for the learner the prompt is built for (not whoever is logged in).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\context_builder::build_system_prompt
 * @covers     \local_ai_course_assistant\context_builder::get_course_topics_text
 */
final class course_structure_visibility_test extends \advanced_testcase {
    /**
     * A course with one open section and one group-restricted section, plus a group-restricted activity.
     *
     * @return array [course, member, outsider, teacher]
     */
    private function setup_course(): array {
        global $DB;
        set_config('enableavailability', 1);
        set_config('rag_enabled', 0, 'local_ai_course_assistant');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['numsections' => 2, 'fullname' => 'Structure 101']);
        $member = $gen->create_and_enrol($course, 'student');
        $outsider = $gen->create_and_enrol($course, 'student');
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $group = $gen->create_group(['courseid' => $course->id]);
        $gen->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
        $groupjson = json_encode(['op' => '&', 'c' => [['type' => 'group', 'id' => (int) $group->id]], 'showc' => [true]]);

        $DB->set_field('course_sections', 'name', 'OPENSECTION', ['course' => $course->id, 'section' => 1]);
        $DB->set_field('course_sections', 'name', 'RESTRICTEDSECTION', ['course' => $course->id, 'section' => 2]);
        $DB->set_field(
            'course_sections',
            'summary',
            'RESTRICTEDSUMMARY short note',
            ['course' => $course->id, 'section' => 2]
        );
        $DB->set_field('course_sections', 'availability', $groupjson, ['course' => $course->id, 'section' => 2]);
        $gen->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'OPENPAGE']);
        $gen->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'GROUPPAGE',
            'availability' => $groupjson]);
        rebuild_course_cache($course->id, true);
        return [$course, $member, $outsider, $teacher];
    }

    /**
     * Restricted section and activity are absent for a learner who doesn't meet the condition.
     */
    public function test_restricted_section_and_activity_are_absent_for_a_learner_outside_the_group(): void {
        $this->resetAfterTest();
        [$course, , $outsider] = $this->setup_course();
        // Logged in as an administrator, as in a task: the prompt must still be built for the outsider.
        $this->setAdminUser();

        $prompt = context_builder::build_system_prompt((int) $course->id, (int) $outsider->id);

        $this->assertStringContainsString('OPENSECTION', $prompt);
        $this->assertStringContainsString('OPENPAGE', $prompt);
        $this->assertStringNotContainsString('RESTRICTEDSECTION', $prompt);
        $this->assertStringNotContainsString('RESTRICTEDSUMMARY', $prompt);
        $this->assertStringNotContainsString('GROUPPAGE', $prompt);
    }

    /**
     * A learner in the group, and a teacher, see both.
     */
    public function test_restricted_section_and_activity_are_present_for_a_member_and_a_teacher(): void {
        $this->resetAfterTest();
        [$course, $member, , $teacher] = $this->setup_course();
        $this->setAdminUser();

        foreach (['member' => $member, 'teacher' => $teacher] as $who => $user) {
            $prompt = context_builder::build_system_prompt((int) $course->id, (int) $user->id);
            $this->assertStringContainsString('RESTRICTEDSECTION', $prompt, $who);
            $this->assertStringContainsString('GROUPPAGE', $prompt, $who);
        }
    }

    /**
     * The quiz and objective paths read the block for the logged-in user.
     */
    public function test_topics_text_uses_the_logged_in_learner(): void {
        $this->resetAfterTest();
        [$course, , $outsider] = $this->setup_course();
        $this->setUser($outsider);

        $topics = context_builder::get_course_topics_text((int) $course->id);

        $this->assertStringContainsString('OPENPAGE', $topics);
        $this->assertStringNotContainsString('RESTRICTEDSECTION', $topics);
        $this->assertStringNotContainsString('GROUPPAGE', $topics);
    }
}
