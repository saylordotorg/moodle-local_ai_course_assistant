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

use local_ai_course_assistant\program\program_path;
use local_ai_course_assistant\program\stub_program_source;

/**
 * Unit tests for forward learning-path awareness, driven by an in-memory
 * stub program source (no Programs plugin tables required).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\program\program_path
 */
final class program_path_test extends \advanced_testcase {

    /**
     * Build a program_path over a stub source.
     *
     * @param array $cfg Stub fixture.
     * @return program_path
     */
    private function path(array $cfg): program_path {
        return new program_path(new stub_program_source($cfg));
    }

    /**
     * A 3-course any-order program; learner allocated; sitting on the middle course.
     *
     * @param int $userid
     * @param array $courseids [c1, c2, c3]
     * @return array
     */
    private function threecourse_cfg(int $userid, array $courseids): array {
        [$c1, $c2, $c3] = $courseids;
        return [
            'available' => true,
            'user_programs' => [$userid => [['programid' => 10, 'name' => 'Business Path']]],
            'program_courses' => [10 => [
                ['itemid' => 101, 'courseid' => $c1, 'coursename' => 'Intro', 'visible' => true, 'ordered' => false, 'position' => 1],
                ['itemid' => 102, 'courseid' => $c2, 'coursename' => 'Mid', 'visible' => true, 'ordered' => false, 'position' => 2],
                ['itemid' => 103, 'courseid' => $c3, 'coursename' => 'Adv', 'visible' => true, 'ordered' => false, 'position' => 3],
            ]],
        ];
    }

    // ── Gating ──────────────────────────────────────────────────────────

    public function test_disabled_when_source_unavailable(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $this->assertFalse($this->path(['available' => false])->is_enabled_for_course((int) $course->id));
    }

    public function test_disabled_when_flag_off(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->assertFalse($this->path(['available' => true])->is_enabled_for_course((int) $course->id));
    }

    public function test_enabled_when_flag_on_and_available(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $this->assertTrue($this->path(['available' => true])->is_enabled_for_course((int) $course->id));
    }

    // ── Program selection + position ────────────────────────────────────

    public function test_position_index_and_total(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $ctx = $this->path($this->threecourse_cfg(7, [201, 202, 203]))->forward_context(7, 202);
        $this->assertNotNull($ctx);
        $this->assertSame('Business Path', $ctx['program']['name']);
        $this->assertSame(2, $ctx['position']['index']);
        $this->assertSame(3, $ctx['position']['total']);
    }

    public function test_hidden_course_not_counted_in_total(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = $this->threecourse_cfg(7, [201, 202, 203]);
        $cfg['program_courses'][10][2]['visible'] = false;
        $ctx = $this->path($cfg)->forward_context(7, 202);
        $this->assertSame(2, $ctx['position']['total']);
    }

    public function test_no_allocation_returns_null_by_default(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = [
            'available' => true,
            'course_programs' => [202 => [['programid' => 10, 'name' => 'Business Path']]],
            'program_courses' => [10 => [
                ['itemid' => 102, 'courseid' => 202, 'coursename' => 'Mid', 'visible' => true, 'ordered' => false, 'position' => 1],
            ]],
        ];
        $this->assertNull($this->path($cfg)->forward_context(7, 202));
    }

    public function test_single_course_program_returns_null(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = [
            'available' => true,
            'user_programs' => [7 => [['programid' => 10, 'name' => 'Solo']]],
            'program_courses' => [10 => [
                ['itemid' => 102, 'courseid' => 202, 'coursename' => 'Only', 'visible' => true, 'ordered' => false, 'position' => 1],
            ]],
        ];
        $this->assertNull($this->path($cfg)->forward_context(7, 202));
    }

    // ── Next courses ────────────────────────────────────────────────────

    public function test_next_course_via_prerequisite(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = $this->threecourse_cfg(7, [201, 202, 203]);
        $cfg['prerequisites'] = [10 => [103 => [102]]];
        $ctx = $this->path($cfg)->forward_context(7, 202);
        $this->assertCount(1, $ctx['next_courses']);
        $this->assertSame(203, $ctx['next_courses'][0]['courseid']);
        $this->assertSame('prerequisite', $ctx['next_courses'][0]['reason']);
    }

    public function test_next_course_via_ordered_sequence(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = $this->threecourse_cfg(7, [201, 202, 203]);
        foreach ($cfg['program_courses'][10] as &$c) {
            $c['ordered'] = true;
        }
        unset($c);
        $ctx = $this->path($cfg)->forward_context(7, 202);
        $this->assertCount(1, $ctx['next_courses']);
        $this->assertSame(203, $ctx['next_courses'][0]['courseid']);
        $this->assertSame('sequence', $ctx['next_courses'][0]['reason']);
    }

    public function test_anyorder_no_prereq_yields_no_next(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $ctx = $this->path($this->threecourse_cfg(7, [201, 202, 203]))->forward_context(7, 202);
        $this->assertSame([], $ctx['next_courses']);
    }

    public function test_completed_next_course_filtered_out(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = $this->threecourse_cfg(7, [201, 202, 203]);
        $cfg['prerequisites'] = [10 => [103 => [102]]];
        $cfg['completed'] = ['7:10:103' => true];
        $ctx = $this->path($cfg)->forward_context(7, 202);
        $this->assertSame([], $ctx['next_courses']);
    }

    // ── Concept links ───────────────────────────────────────────────────

    public function test_concept_link_when_objective_equates_into_next_course(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cur = $this->getDataGenerator()->create_course();
        $nextc = $this->getDataGenerator()->create_course();
        objective_manager::set_enabled_for_course((int) $cur->id, true);
        objective_manager::set_enabled_for_course((int) $nextc->id, true);
        objective_manager::create((int) $cur->id, 'Interpret a balance sheet');
        objective_manager::create((int) $nextc->id, 'Interpret a balance sheet');
        cross_course_mastery::rebuild_links();

        $cfg = [
            'available' => true,
            'user_programs' => [7 => [['programid' => 10, 'name' => 'Acct Path']]],
            'program_courses' => [10 => [
                ['itemid' => 101, 'courseid' => (int) $cur->id, 'coursename' => 'Acct I', 'visible' => true, 'ordered' => false, 'position' => 1],
                ['itemid' => 102, 'courseid' => (int) $nextc->id, 'coursename' => 'Acct II', 'visible' => true, 'ordered' => false, 'position' => 2],
            ]],
            'prerequisites' => [10 => [102 => [101]]],
        ];
        $ctx = $this->path($cfg)->forward_context(7, (int) $cur->id);
        $this->assertCount(1, $ctx['next_courses']);
        $this->assertCount(1, $ctx['concept_links']);
        $this->assertSame('Interpret a balance sheet', $ctx['concept_links'][0]['objective']);
        $this->assertSame('Acct II', $ctx['concept_links'][0]['next_course']);
    }

    public function test_no_concept_link_when_no_equivalence(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = $this->threecourse_cfg(7, [201, 202, 203]);
        $cfg['prerequisites'] = [10 => [103 => [102]]];
        $ctx = $this->path($cfg)->forward_context(7, 202);
        $this->assertSame([], $ctx['concept_links']);
    }

    // ── Prompt block ────────────────────────────────────────────────────

    public function test_block_with_next_and_concept(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cur = $this->getDataGenerator()->create_course();
        $nextc = $this->getDataGenerator()->create_course();
        objective_manager::set_enabled_for_course((int) $cur->id, true);
        objective_manager::set_enabled_for_course((int) $nextc->id, true);
        objective_manager::create((int) $cur->id, 'Interpret a balance sheet');
        objective_manager::create((int) $nextc->id, 'Interpret a balance sheet');
        cross_course_mastery::rebuild_links();
        $cfg = [
            'available' => true,
            'user_programs' => [7 => [['programid' => 10, 'name' => 'Acct Path']]],
            'program_courses' => [10 => [
                ['itemid' => 101, 'courseid' => (int) $cur->id, 'coursename' => 'Acct I', 'visible' => true, 'ordered' => false, 'position' => 1],
                ['itemid' => 102, 'courseid' => (int) $nextc->id, 'coursename' => 'Acct II', 'visible' => true, 'ordered' => false, 'position' => 2],
            ]],
            'prerequisites' => [10 => [102 => [101]]],
        ];
        $block = $this->path($cfg)->build_prompt_injection(7, (int) $cur->id);
        $this->assertStringContainsString('### Where this course leads', $block);
        $this->assertStringContainsString('Acct Path', $block);
        $this->assertStringContainsString('Acct II', $block);
        $this->assertStringContainsString('Interpret a balance sheet', $block);
    }

    public function test_block_membership_only_when_no_next(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $block = $this->path($this->threecourse_cfg(7, [201, 202, 203]))->build_prompt_injection(7, 202);
        $this->assertStringContainsString('### Where this course leads', $block);
        $this->assertStringContainsString('course 2 of 3', $block);
        $this->assertStringNotContainsString('leads into', $block);
    }

    public function test_block_empty_when_no_context(): void {
        $this->resetAfterTest();
        $this->assertSame('', $this->path(['available' => true])->build_prompt_injection(7, 202));
    }
}
