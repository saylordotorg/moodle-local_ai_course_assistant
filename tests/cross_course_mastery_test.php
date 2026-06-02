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
 * Cross-course mastery rollup unit tests (v5.7.0).
 *
 * Covers objective-identity matching across courses (exact competency
 * ref + normalized/fuzzy title), the precomputed link table rebuild, and
 * the read-side transfer-evidence resolver that feeds prompt injection
 * and the mastery-aware starter.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cross_course_mastery_test extends \advanced_testcase {

    // ───────────────────────────────────────────────────────────
    // normalize_title — pure string normalization
    // ───────────────────────────────────────────────────────────

    public function test_normalize_title_lowercases_and_collapses_whitespace(): void {
        $this->assertEquals(
            'interpret a balance sheet',
            cross_course_mastery::normalize_title("  Interpret  a   Balance Sheet  ")
        );
    }

    public function test_normalize_title_strips_leading_code_prefix(): void {
        $this->assertEquals(
            'interpret a balance sheet',
            cross_course_mastery::normalize_title('LO1: Interpret a balance sheet')
        );
        $this->assertEquals(
            'interpret a balance sheet',
            cross_course_mastery::normalize_title('Unit 3 — Interpret a balance sheet')
        );
        $this->assertEquals(
            'interpret a balance sheet',
            cross_course_mastery::normalize_title('1. Interpret a balance sheet')
        );
    }

    public function test_normalize_title_strips_trailing_punctuation(): void {
        $this->assertEquals(
            'interpret a balance sheet',
            cross_course_mastery::normalize_title('Interpret a balance sheet.')
        );
    }

    // ───────────────────────────────────────────────────────────
    // title_similarity — 0.0 .. 1.0
    // ───────────────────────────────────────────────────────────

    public function test_title_similarity_is_one_for_identical_normalized(): void {
        $s = cross_course_mastery::title_similarity('Interpret a balance sheet', 'interpret a BALANCE sheet.');
        $this->assertEqualsWithDelta(1.0, $s, 0.0001);
    }

    public function test_title_similarity_is_low_for_unrelated(): void {
        $s = cross_course_mastery::title_similarity('Interpret a balance sheet', 'Photosynthesis in plants');
        $this->assertLessThan(0.5, $s);
    }

    // ───────────────────────────────────────────────────────────
    // rebuild_links + linked_objectives — cross-course pairing
    // ───────────────────────────────────────────────────────────

    public function test_rebuild_links_pairs_identical_titles_across_courses(): void {
        $this->resetAfterTest();
        $c1 = $this->getDataGenerator()->create_course();
        $c2 = $this->getDataGenerator()->create_course();
        $a = objective_manager::create((int)$c1->id, 'Interpret a balance sheet');
        $b = objective_manager::create((int)$c2->id, 'interpret a BALANCE sheet.');

        cross_course_mastery::rebuild_links();

        $links = cross_course_mastery::linked_objectives($a);
        $this->assertCount(1, $links);
        $this->assertEquals($b, $links[0]['objectiveid']);
        $this->assertEquals((int)$c2->id, $links[0]['courseid']);
        $this->assertEquals('title_exact', $links[0]['method']);
    }

    public function test_rebuild_links_pairs_by_external_ref(): void {
        $this->resetAfterTest();
        $c1 = $this->getDataGenerator()->create_course();
        $c2 = $this->getDataGenerator()->create_course();
        // Different titles, same competency ref => must still link.
        $a = objective_manager::create((int)$c1->id, 'Balance sheets', '', '', 'competency', 'COMP-7');
        $b = objective_manager::create((int)$c2->id, 'Statement of financial position', '', '', 'competency', 'COMP-7');

        cross_course_mastery::rebuild_links();

        $links = cross_course_mastery::linked_objectives($a);
        $this->assertCount(1, $links);
        $this->assertEquals($b, $links[0]['objectiveid']);
        $this->assertEquals('ref', $links[0]['method']);
    }

    public function test_rebuild_links_does_not_pair_within_same_course(): void {
        $this->resetAfterTest();
        $c1 = $this->getDataGenerator()->create_course();
        $a = objective_manager::create((int)$c1->id, 'Interpret a balance sheet');
        objective_manager::create((int)$c1->id, 'Interpret a balance sheet');

        cross_course_mastery::rebuild_links();

        $this->assertCount(0, cross_course_mastery::linked_objectives($a),
            'Two objectives in the SAME course must never be linked.');
    }

    public function test_rebuild_links_fuzzy_matches_close_titles(): void {
        $this->resetAfterTest();
        $c1 = $this->getDataGenerator()->create_course();
        $c2 = $this->getDataGenerator()->create_course();
        // Confirm the pair qualifies as fuzzy (not exact) at the threshold.
        $sim = cross_course_mastery::title_similarity('Solve linear equations', 'Solving linear equations');
        $this->assertGreaterThanOrEqual(0.88, $sim);

        $a = objective_manager::create((int)$c1->id, 'Solve linear equations');
        $b = objective_manager::create((int)$c2->id, 'Solving linear equations');
        cross_course_mastery::rebuild_links();

        $links = cross_course_mastery::linked_objectives($a);
        $this->assertCount(1, $links);
        $this->assertEquals($b, $links[0]['objectiveid']);
        $this->assertEquals('title_fuzzy', $links[0]['method']);
    }

    public function test_rebuild_links_ignores_unrelated_titles(): void {
        $this->resetAfterTest();
        $c1 = $this->getDataGenerator()->create_course();
        $c2 = $this->getDataGenerator()->create_course();
        $a = objective_manager::create((int)$c1->id, 'Interpret a balance sheet');
        objective_manager::create((int)$c2->id, 'Photosynthesis in plants');

        cross_course_mastery::rebuild_links();
        $this->assertCount(0, cross_course_mastery::linked_objectives($a));
    }

    public function test_rebuild_links_is_idempotent(): void {
        $this->resetAfterTest();
        global $DB;
        $c1 = $this->getDataGenerator()->create_course();
        $c2 = $this->getDataGenerator()->create_course();
        objective_manager::create((int)$c1->id, 'Interpret a balance sheet');
        objective_manager::create((int)$c2->id, 'Interpret a balance sheet');

        cross_course_mastery::rebuild_links();
        $after1 = $DB->count_records('local_ai_course_assistant_obj_links');
        cross_course_mastery::rebuild_links();
        $after2 = $DB->count_records('local_ai_course_assistant_obj_links');

        $this->assertEquals(1, $after1);
        $this->assertEquals($after1, $after2, 'Rebuild must be idempotent — no duplicate pair rows.');
    }

    // ───────────────────────────────────────────────────────────
    // get_transfer_evidence — read-side resolver
    // ───────────────────────────────────────────────────────────

    /**
     * Master an objective for a user with enough correct attempts.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $objid
     * @return void
     */
    private function master(int $userid, int $courseid, int $objid): void {
        for ($i = 0; $i < 6; $i++) {
            objective_manager::record_attempt($userid, $courseid, $objid, true, 'quiz', 1.0, null, null);
        }
    }

    /**
     * Enable cross-course mastery (and objectives) for a course.
     *
     * @param int $courseid
     * @return void
     */
    private function enable_crossmastery(int $courseid): void {
        objective_manager::set_enabled_for_course($courseid, true);
        set_config('crossmastery_enabled_course_' . $courseid, 1, 'local_ai_course_assistant');
    }

    public function test_transfer_evidence_surfaces_mastery_from_another_course(): void {
        $this->resetAfterTest();
        $c1 = $this->getDataGenerator()->create_course();
        $c2 = $this->getDataGenerator()->create_course(['fullname' => 'Accounting 201']);
        $user = $this->getDataGenerator()->create_user();

        $a = objective_manager::create((int)$c2->id, 'Interpret a balance sheet'); // source course
        $b = objective_manager::create((int)$c1->id, 'Interpret a balance sheet'); // current course
        cross_course_mastery::rebuild_links();
        $this->master((int)$user->id, (int)$c2->id, $a);

        // Enable on the CURRENT course (c1) where the learner is studying.
        $this->enable_crossmastery((int)$c1->id);
        $evidence = cross_course_mastery::get_transfer_evidence((int)$user->id, (int)$c1->id);

        $this->assertCount(1, $evidence);
        $this->assertEquals($b, (int)$evidence[0]['objective']->id);
        $this->assertEquals((int)$c2->id, $evidence[0]['source_courseid']);
        $this->assertEquals('mastered', $evidence[0]['source_status']);
    }

    public function test_transfer_evidence_empty_when_crossmastery_off(): void {
        $this->resetAfterTest();
        $c1 = $this->getDataGenerator()->create_course();
        $c2 = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $a = objective_manager::create((int)$c2->id, 'Interpret a balance sheet');
        objective_manager::create((int)$c1->id, 'Interpret a balance sheet');
        cross_course_mastery::rebuild_links();
        $this->master((int)$user->id, (int)$c2->id, $a);

        // crossmastery NOT enabled on c1.
        $this->assertCount(0, cross_course_mastery::get_transfer_evidence((int)$user->id, (int)$c1->id));
    }

    public function test_transfer_evidence_skips_objectives_already_mastered_locally(): void {
        $this->resetAfterTest();
        $c1 = $this->getDataGenerator()->create_course();
        $c2 = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $a = objective_manager::create((int)$c2->id, 'Interpret a balance sheet');
        $b = objective_manager::create((int)$c1->id, 'Interpret a balance sheet');
        cross_course_mastery::rebuild_links();
        $this->master((int)$user->id, (int)$c2->id, $a);
        $this->master((int)$user->id, (int)$c1->id, $b); // already mastered locally too.

        $this->enable_crossmastery((int)$c1->id);
        $this->assertCount(0, cross_course_mastery::get_transfer_evidence((int)$user->id, (int)$c1->id),
            'No need to surface transfer for an objective the learner already mastered here.');
    }

    // ───────────────────────────────────────────────────────────
    // Prompt-injection integration (objective_manager)
    // ───────────────────────────────────────────────────────────

    public function test_prompt_injection_includes_transfer_block_when_enabled(): void {
        $this->resetAfterTest();
        $c1 = $this->getDataGenerator()->create_course();
        $c2 = $this->getDataGenerator()->create_course(['fullname' => 'Accounting 201']);
        $user = $this->getDataGenerator()->create_user();
        $this->enable_crossmastery((int)$c1->id);

        $a = objective_manager::create((int)$c2->id, 'Interpret a balance sheet');
        objective_manager::create((int)$c1->id, 'Interpret a balance sheet');
        cross_course_mastery::rebuild_links();
        $this->master((int)$user->id, (int)$c2->id, $a);

        $block = objective_manager::build_prompt_injection((int)$user->id, (int)$c1->id);
        $this->assertStringContainsStringIgnoringCase('demonstrated elsewhere', $block);
        $this->assertStringContainsString('Accounting 201', $block);
    }

    public function test_prompt_injection_omits_transfer_block_when_crossmastery_off(): void {
        $this->resetAfterTest();
        $c1 = $this->getDataGenerator()->create_course();
        $c2 = $this->getDataGenerator()->create_course(['fullname' => 'Accounting 201']);
        $user = $this->getDataGenerator()->create_user();
        // Mastery on (so the base block renders) but crossmastery OFF.
        objective_manager::set_enabled_for_course((int)$c1->id, true);

        $a = objective_manager::create((int)$c2->id, 'Interpret a balance sheet');
        objective_manager::create((int)$c1->id, 'Interpret a balance sheet');
        cross_course_mastery::rebuild_links();
        $this->master((int)$user->id, (int)$c2->id, $a);

        $block = objective_manager::build_prompt_injection((int)$user->id, (int)$c1->id);
        $this->assertStringNotContainsStringIgnoringCase('demonstrated elsewhere', $block);
    }

    // ───────────────────────────────────────────────────────────
    // Mastery-aware starter (next_best_action::starter_label) — Feature C
    // ───────────────────────────────────────────────────────────

    public function test_starter_label_names_weakest_objective(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        objective_manager::set_enabled_for_course((int)$course->id, true);
        objective_manager::create((int)$course->id, 'Interpret a balance sheet');

        $r = next_best_action::starter_label((int)$user->id, (int)$course->id);
        $this->assertEquals('weak', $r['kind']);
        $this->assertNotNull($r['objectiveid']);
        $this->assertStringContainsString('Interpret a balance sheet', $r['label']);
    }

    public function test_starter_label_generic_when_mastery_off(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        objective_manager::create((int)$course->id, 'Interpret a balance sheet'); // mastery OFF.

        $r = next_best_action::starter_label((int)$user->id, (int)$course->id);
        $this->assertEquals('generic', $r['kind']);
        $this->assertEquals('', $r['label']);
    }

    public function test_starter_label_generic_when_all_mastered(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        objective_manager::set_enabled_for_course((int)$course->id, true);
        $o = objective_manager::create((int)$course->id, 'Interpret a balance sheet');
        $this->master((int)$user->id, (int)$course->id, $o);

        $r = next_best_action::starter_label((int)$user->id, (int)$course->id);
        $this->assertEquals('generic', $r['kind']);
    }
}
