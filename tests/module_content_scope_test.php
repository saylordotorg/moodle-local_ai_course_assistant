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
 * get_module_content() must not read a module outside the authorised course,
 * and must not read one the user cannot see.
 *
 * The callers (sse.php `pageid`, generate_quiz / generate_flashcards `cmid`)
 * take the module id straight from request input while their capability check
 * is against a separate, caller-supplied course. Before this constraint, any
 * student holding :use anywhere could read any Page or Book on the site --
 * including hidden ones in their own course -- by passing its cmid.
 *
 * @covers \local_ai_course_assistant\context_builder::get_module_content
 */
final class module_content_scope_test extends \advanced_testcase {

    /** Content from another course must never come back. */
    public function test_foreign_course_module_returns_empty(): void {
        $this->resetAfterTest();

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $coursea->id, 'student');
        $this->setUser($student);

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $courseb->id,
            'content' => 'SECRET ANSWER KEY: the answer to question one is 42.',
            'contentformat' => FORMAT_HTML,
        ]);

        // The student is authorised for course A only.
        $text = context_builder::get_module_content((int) $page->cmid, (int) $coursea->id);
        $this->assertSame('', $text, 'A module from another course must not be readable.');
        $this->assertStringNotContainsString('SECRET', $text);
    }

    /** A hidden activity in the user's OWN course must not be readable. */
    public function test_hidden_module_in_own_course_returns_empty(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => 'SECRET ANSWER KEY: the answer to question one is 42.',
            'contentformat' => FORMAT_HTML,
        ]);
        // Hide it, the way a teacher stages an exam key before release.
        $DB->set_field('course_modules', 'visible', 0, ['id' => $page->cmid]);
        rebuild_course_cache($course->id, true);

        $this->setUser($student);
        $text = context_builder::get_module_content((int) $page->cmid, (int) $course->id);
        $this->assertSame('', $text, 'A hidden module must not be readable by a student.');
    }

    /** A teacher who may view hidden activities still can. */
    public function test_teacher_can_read_a_hidden_module(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => 'Draft notes for the upcoming unit.',
            'contentformat' => FORMAT_HTML,
        ]);
        $DB->set_field('course_modules', 'visible', 0, ['id' => $page->cmid]);
        rebuild_course_cache($course->id, true);

        $this->setUser($teacher);
        $text = context_builder::get_module_content((int) $page->cmid, (int) $course->id);
        $this->assertStringContainsString('Draft notes', $text,
            'viewhiddenactivities must still allow a teacher to read it.');
    }

    /** The legitimate path still works. */
    public function test_visible_module_in_the_authorised_course_is_readable(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => 'Photosynthesis converts light energy into chemical energy.',
            'contentformat' => FORMAT_HTML,
        ]);

        $text = context_builder::get_module_content((int) $page->cmid, (int) $course->id);
        $this->assertStringContainsString('Photosynthesis', $text);
    }

    /** Nonsense ids fail closed rather than throwing. */
    public function test_invalid_ids_return_empty(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        $this->assertSame('', context_builder::get_module_content(0, (int) $course->id));
        $this->assertSame('', context_builder::get_module_content(-1, (int) $course->id));
        $this->assertSame('', context_builder::get_module_content(999999, (int) $course->id));
        $this->assertSame('', context_builder::get_module_content(999999, 0));
    }
}
