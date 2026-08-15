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
 * Tests for the pseudonym function behind every PII gate in the plugin.
 *
 * 39 lines and one function, previously untested, and now load-bearing for all
 * four sections of the Redash export plus the Learning Radar. The properties that
 * matter are that it is stable (dashboards group by it) and that it does not leak
 * the id it is derived from.
 *
 * The collision test documents a real limit rather than asserting a bug: the
 * construction is crc32 modulo 9999, so pseudonyms are not unique above a few
 * thousand learners. That is fine for "Student 4217 asked X then Y" within one
 * report and not fine as a join key across a large cohort.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\anonymizer
 */
final class anonymizer_test extends \basic_testcase {
    public function test_same_user_always_gets_the_same_pseudonym(): void {
        $this->assertSame(anonymizer::name(4217), anonymizer::name(4217));
    }

    public function test_pseudonym_does_not_contain_the_real_id(): void {
        // The whole point: the label must not be a re-encoding of the input.
        foreach ([1, 42, 4217, 100000, 999999] as $userid) {
            $this->assertStringNotContainsString(
                (string) $userid,
                anonymizer::name($userid),
                "pseudonym for {$userid} leaks the id"
            );
        }
    }

    public function test_different_users_usually_differ(): void {
        $seen = [];
        for ($id = 1; $id <= 200; $id++) {
            $seen[] = anonymizer::name($id);
        }
        // Not asserting uniqueness (see the collision test), but 200 ids should
        // not collapse onto a handful of labels.
        $this->assertGreaterThan(190, count(array_unique($seen)));
    }

    public function test_output_shape_is_a_student_label(): void {
        $this->assertMatchesRegularExpression('/^Student \d{1,4}$/', anonymizer::name(4217));
    }

    public function test_pseudonym_space_is_bounded_at_9999(): void {
        // Documents the limit that matters if user_ref is used as a join key:
        // the label space is 9999 wide, so a cohort larger than that must
        // collide. 20k ids exercised; unique labels cannot exceed 9999.
        $labels = [];
        for ($id = 1; $id <= 20000; $id++) {
            $labels[anonymizer::name($id)] = true;
        }
        $this->assertLessThanOrEqual(9999, count($labels));
        // And prove a collision genuinely occurs in that range, so the limit is
        // real rather than theoretical.
        $this->assertLessThan(20000, count($labels));
    }

    public function test_handles_edge_case_ids(): void {
        foreach ([0, 1, PHP_INT_MAX] as $userid) {
            $this->assertMatchesRegularExpression('/^Student \d{1,4}$/', anonymizer::name($userid));
        }
    }
}
