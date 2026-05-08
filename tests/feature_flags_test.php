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
 * Tests for feature_flags (v5.3.22).
 *
 * Three-way resolution (force on / force off / inherit) is the rule on
 * every per-course pedagogy toggle (mastery, socratic_mode, flashcards,
 * code_sandbox, essay_feedback, worked_examples, talking_avatar). One
 * regression here flips state on potentially every course on the site,
 * which is why the v4.5.0 cleanup migration ran at all.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\feature_flags
 */
final class feature_flags_test extends \advanced_testcase {

    public function test_resolve_returns_global_when_no_override(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        set_config('mastery_enabled', '1', 'local_ai_course_assistant');

        $this->assertTrue(feature_flags::resolve('mastery', (int)$course->id));
    }

    public function test_resolve_returns_false_when_global_off_and_no_override(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        set_config('mastery_enabled', '0', 'local_ai_course_assistant');

        $this->assertFalse(feature_flags::resolve('mastery', (int)$course->id));
    }

    public function test_resolve_per_course_force_on_overrides_global_off(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        set_config('mastery_enabled', '0', 'local_ai_course_assistant');
        set_config('mastery_enabled_course_' . $course->id, '1', 'local_ai_course_assistant');

        $this->assertTrue(feature_flags::resolve('mastery', (int)$course->id),
            'Per-course force-on must beat the global default-off.');
    }

    public function test_resolve_per_course_force_off_overrides_global_on(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        set_config('mastery_enabled', '1', 'local_ai_course_assistant');
        set_config('mastery_enabled_course_' . $course->id, '0', 'local_ai_course_assistant');

        $this->assertFalse(feature_flags::resolve('mastery', (int)$course->id),
            'Per-course force-off must beat the global default-on.');
    }

    public function test_resolve_per_course_unset_inherits_global(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        // Explicitly unset any per-course override.
        unset_config('mastery_enabled_course_' . $course->id, 'local_ai_course_assistant');
        set_config('mastery_enabled', '1', 'local_ai_course_assistant');

        $this->assertTrue(feature_flags::resolve('mastery', (int)$course->id));

        // Flip global; per-course still inherits.
        set_config('mastery_enabled', '0', 'local_ai_course_assistant');
        $this->assertFalse(feature_flags::resolve('mastery', (int)$course->id));
    }

    public function test_resolve_with_source_reports_override_source(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        set_config('mastery_enabled_course_' . $course->id, '1', 'local_ai_course_assistant');

        $result = feature_flags::resolve_with_source('mastery', (int)$course->id);

        $this->assertTrue($result['enabled']);
        $this->assertEquals('override', $result['source']);
    }

    public function test_resolve_with_source_reports_global_source(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        unset_config('mastery_enabled_course_' . $course->id, 'local_ai_course_assistant');
        set_config('mastery_enabled', '1', 'local_ai_course_assistant');

        $result = feature_flags::resolve_with_source('mastery', (int)$course->id);

        $this->assertTrue($result['enabled']);
        $this->assertEquals('global', $result['source']);
    }

    public function test_resolve_with_source_reports_default_source_when_off(): void {
        // When neither per-course nor global is set, result is enabled=false
        // with source='default' (the unset state).
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        unset_config('mastery_enabled_course_' . $course->id, 'local_ai_course_assistant');
        unset_config('mastery_enabled', 'local_ai_course_assistant');

        $result = feature_flags::resolve_with_source('mastery', (int)$course->id);

        $this->assertFalse($result['enabled']);
        $this->assertEquals('default', $result['source']);
    }

    public function test_courses_are_independent(): void {
        // Two courses; setting an override on one must not leak to the other.
        $this->resetAfterTest();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        set_config('mastery_enabled', '0', 'local_ai_course_assistant');
        set_config('mastery_enabled_course_' . $course1->id, '1', 'local_ai_course_assistant');

        $this->assertTrue(feature_flags::resolve('mastery', (int)$course1->id));
        $this->assertFalse(feature_flags::resolve('mastery', (int)$course2->id));
    }
}
