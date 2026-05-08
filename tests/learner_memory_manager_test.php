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
 * Tests for learner_memory_manager (v5.3.0 / regression coverage v5.3.8).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\learner_memory_manager
 */
final class learner_memory_manager_test extends \advanced_testcase {

    public function test_get_notes_empty_shape_when_row_missing(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $notes = learner_memory_manager::get_notes($user->id, $course->id);
        $this->assertIsArray($notes);
        $this->assertSame([], $notes['sticking']);
        $this->assertSame([], $notes['style_prefs']);
        $this->assertSame(0, $notes['last_active']);
    }

    public function test_record_sticking_point_increments_count_for_same_topic(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        learner_memory_manager::record_sticking_point($user->id, $course->id, 'Photosynthesis');
        learner_memory_manager::record_sticking_point($user->id, $course->id, 'photosynthesis'); // case-insensitive
        $notes = learner_memory_manager::get_notes($user->id, $course->id);
        $this->assertCount(1, $notes['sticking']);
        $this->assertEquals(2, $notes['sticking'][0]['count']);
    }

    public function test_record_sticking_point_caps_at_max(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        // Push 7 distinct topics; the bound is 5 most-recent.
        for ($i = 1; $i <= 7; $i++) {
            learner_memory_manager::record_sticking_point($user->id, $course->id, "Topic {$i}");
        }
        $notes = learner_memory_manager::get_notes($user->id, $course->id);
        $this->assertCount(learner_memory_manager::MAX_STICKING, $notes['sticking']);
    }

    public function test_forget_sticking_point_removes_named_entry_only(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        learner_memory_manager::record_sticking_point($user->id, $course->id, 'Cell respiration');
        learner_memory_manager::record_sticking_point($user->id, $course->id, 'Mitosis');
        learner_memory_manager::forget_sticking_point($user->id, $course->id, 'cell respiration');
        $notes = learner_memory_manager::get_notes($user->id, $course->id);
        $this->assertCount(1, $notes['sticking']);
        $this->assertEquals('Mitosis', $notes['sticking'][0]['topic']);
    }

    public function test_set_style_pref_with_empty_value_unsets(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        learner_memory_manager::set_style_pref($user->id, $course->id, 'coaching_style', 'tutor');
        $notes = learner_memory_manager::get_notes($user->id, $course->id);
        $this->assertEquals('tutor', $notes['style_prefs']['coaching_style']);

        learner_memory_manager::set_style_pref($user->id, $course->id, 'coaching_style', '');
        $notes = learner_memory_manager::get_notes($user->id, $course->id);
        $this->assertArrayNotHasKey('coaching_style', $notes['style_prefs']);
    }

    public function test_clear_wipes_all_notes(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        learner_memory_manager::record_sticking_point($user->id, $course->id, 'X');
        learner_memory_manager::set_style_pref($user->id, $course->id, 'k', 'v');
        learner_memory_manager::clear($user->id, $course->id);
        $notes = learner_memory_manager::get_notes($user->id, $course->id);
        $this->assertSame([], $notes['sticking']);
        $this->assertSame([], $notes['style_prefs']);
    }

    public function test_build_prompt_section_disabled_globally(): void {
        $this->resetAfterTest();
        set_config('memory_feature_enabled', '0', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        learner_memory_manager::record_sticking_point($user->id, $course->id, 'Anything');
        $this->assertSame('', learner_memory_manager::build_prompt_section($user->id, $course->id));
    }

    public function test_build_prompt_section_includes_dont_quote_directive(): void {
        $this->resetAfterTest();
        set_config('memory_feature_enabled', '1', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        learner_memory_manager::record_sticking_point($user->id, $course->id, 'Photosynthesis');

        $block = learner_memory_manager::build_prompt_section($user->id, $course->id);

        $this->assertStringContainsString('Photosynthesis', $block);
        // Critical privacy guarantee: SOLA must not narrate the carryover.
        $this->assertStringContainsString('never quote them back', $block);
        $this->assertStringContainsString('never bring them up uninvited', $block);
        $this->assertStringContainsString('Do not narrate it', $block);
    }

    public function test_field_cap_truncates_long_topic(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $longtopic = str_repeat('a', 500);
        learner_memory_manager::record_sticking_point($user->id, $course->id, $longtopic);
        $notes = learner_memory_manager::get_notes($user->id, $course->id);
        $this->assertEquals(learner_memory_manager::FIELD_CAP_CHARS,
            mb_strlen($notes['sticking'][0]['topic']));
    }
}
