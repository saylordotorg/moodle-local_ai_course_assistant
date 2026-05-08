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
 * Tests for learner_goals_manager (v5.3.0 / regression coverage v5.3.8).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\learner_goals_manager
 */
final class learner_goals_manager_test extends \advanced_testcase {

    public function test_get_returns_null_when_no_row(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->assertNull(learner_goals_manager::get($user->id, $course->id));
    }

    public function test_save_creates_row_and_consents_at_is_set(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        learner_goals_manager::save($user->id, $course->id, 'I want to be a nurse.', 'Doctorate.', '');

        $row = $DB->get_record('local_ai_course_assistant_learner_goals',
            ['userid' => $user->id, 'courseid' => $course->id]);
        $this->assertNotFalse($row);
        $this->assertEquals('I want to be a nurse.', $row->q1_answer);
        $this->assertEquals('Doctorate.', $row->q2_answer);
        $this->assertNull($row->q3_answer);
        $this->assertGreaterThan(0, (int)$row->consented_at);
    }

    public function test_save_then_save_keeps_original_consented_at(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        learner_goals_manager::save($user->id, $course->id, 'A');
        $first = learner_goals_manager::get($user->id, $course->id);
        $firsttime = (int)$first->consented_at;
        // Force a clock tick so the second save would otherwise differ.
        sleep(1);
        learner_goals_manager::save($user->id, $course->id, 'A revised', 'B');
        $second = learner_goals_manager::get($user->id, $course->id);
        $this->assertEquals($firsttime, (int)$second->consented_at,
            'consented_at must remain anchored to the first save, not advance on edit.');
    }

    public function test_should_prompt_disabled_globally(): void {
        $this->resetAfterTest();
        set_config('goals_feature_enabled', '0', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->assertFalse(learner_goals_manager::should_prompt($user->id, $course->id));
    }

    public function test_should_prompt_returns_true_for_fresh_learner(): void {
        $this->resetAfterTest();
        set_config('goals_feature_enabled', '1', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->assertTrue(learner_goals_manager::should_prompt($user->id, $course->id));
    }

    public function test_should_prompt_returns_false_when_answers_present(): void {
        $this->resetAfterTest();
        set_config('goals_feature_enabled', '1', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        learner_goals_manager::save($user->id, $course->id, 'I want X.');
        $this->assertFalse(learner_goals_manager::should_prompt($user->id, $course->id));
    }

    public function test_dismiss_then_should_prompt_returns_false_within_cooldown(): void {
        $this->resetAfterTest();
        set_config('goals_feature_enabled', '1', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        learner_goals_manager::dismiss($user->id, $course->id);
        $this->assertFalse(learner_goals_manager::should_prompt($user->id, $course->id),
            'Within the 30-day cooldown the prompt must not re-fire.');
    }

    public function test_clear_wipes_answers_but_keeps_consent_timestamp(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        learner_goals_manager::save($user->id, $course->id, 'A', 'B', 'C');
        $before = learner_goals_manager::get($user->id, $course->id);

        learner_goals_manager::clear($user->id, $course->id);

        $after = learner_goals_manager::get($user->id, $course->id);
        $this->assertNotNull($after, 'Row should still exist after clear.');
        $this->assertNull($after->q1_answer);
        $this->assertNull($after->q2_answer);
        $this->assertNull($after->q3_answer);
        $this->assertEquals((int)$before->consented_at, (int)$after->consented_at);
    }

    public function test_build_prompt_section_empty_without_answers(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->assertSame('', learner_goals_manager::build_prompt_section($user->id, $course->id));
    }

    public function test_build_prompt_section_includes_answers_and_no_quote_directive(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        learner_goals_manager::save($user->id, $course->id, 'I want to be a paramedic.',
            'EMT certification.', 'I learn best with case studies.');

        $block = learner_goals_manager::build_prompt_section($user->id, $course->id);

        $this->assertStringContainsString('paramedic', $block);
        $this->assertStringContainsString('EMT certification', $block);
        $this->assertStringContainsString('case studies', $block);
        // The model is told NOT to quote the learner's words back.
        $this->assertStringContainsString("do not quote the learner's words back at them", $block);
    }
}
