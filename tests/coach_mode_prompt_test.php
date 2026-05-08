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
 * Integration test for the v5.2.0 quiz coach-mode prompt block (v5.3.18).
 *
 * Quiz coach mode is a SAFETY-priority block in the system prompt that
 * forbids SOLA from giving direct answers during a graded quiz attempt
 * but keeps the chat available for Socratic guidance. The block must
 * land at CAT_SAFETY priority 95 so it survives prompt budget pressure
 * and any heavy custom persona template.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\context_builder
 */
final class coach_mode_prompt_test extends \advanced_testcase {

    public function test_coach_mode_block_lands_in_prompt(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        $prompt = context_builder::build_system_prompt(
            (int)$course->id, $user->id, '', [], 0, '', 'coach'
        );

        // The coach-mode block carries a distinctive heading.
        $this->assertStringContainsString('Coach mode active (graded quiz attempt)', $prompt,
            'Coach-mode SAFETY block must appear when quizmode=coach is supplied.');
        // And the load-bearing rules.
        $this->assertStringContainsString('Do NOT state, hint at, or rephrase the correct answer', $prompt);
        $this->assertStringContainsString('Do NOT confirm or deny whether a specific answer choice is correct', $prompt);
        $this->assertStringContainsString('Socratic questions', $prompt);
    }

    public function test_no_coach_block_without_quizmode(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        $prompt = context_builder::build_system_prompt(
            (int)$course->id, $user->id, '', [], 0, '', ''
        );

        $this->assertStringNotContainsString('Coach mode active', $prompt,
            'Coach-mode block must NOT appear when quizmode is empty.');
    }

    public function test_coach_mode_block_persists_with_full_prompt(): void {
        // The whole point of coach-mode being CAT_SAFETY priority 95 is
        // that it survives budget pressure. Confirm it appears even with
        // a current page anchor that adds substantial context.
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_page');
        $page = $generator->create_instance([
            'course' => $course->id, 'name' => 'Quiz prep page',
            'content' => '<p>' . str_repeat('Background reading on cellular respiration. ', 30)
                . '</p>',
            'contentformat' => FORMAT_HTML,
        ]);
        $cm = get_coursemodule_from_instance('page', $page->id);

        $prompt = context_builder::build_system_prompt(
            (int)$course->id, $user->id, '', [], (int)$cm->id, 'Quiz prep page', 'coach'
        );

        $this->assertStringContainsString('Coach mode active', $prompt,
            'Coach-mode block must coexist with a Current Page Content section.');
        $this->assertStringContainsString('## Current Page Content', $prompt);
    }
}
