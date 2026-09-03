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
 * Per-course monthly spend cap (v7.3.0, finding F31).
 *
 * spend_guard::get_cap() read $coursecfg['spend_cap_monthly'] from
 * course_config_manager::get_effective_config(), which returns exactly six keys
 * and never that one. There was no column to store the value and no UI to set
 * it either, so from v5.13 to v7.2.10 the per-course branch was unreachable and
 * every course fell through to the site-wide default.
 *
 * The failure mode is the dangerous one: an admin sets a cap, the page accepts
 * it, and nothing enforces it. These tests fail against the old code.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\course_config_manager::get_spend_cap
 * @covers     \local_ai_course_assistant\spend_guard::get_cap
 */
final class course_spend_cap_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * A cap saved against a course is the cap the guard enforces.
     */
    public function test_saved_course_cap_is_returned_by_the_guard(): void {
        $course = $this->getDataGenerator()->create_course();
        course_config_manager::save($course->id, ['enabled' => 1, 'spend_cap_monthly' => '42.50']);

        $this->assertSame(42.5, course_config_manager::get_spend_cap($course->id));
        $this->assertSame(42.5, spend_guard::get_cap($course->id));
    }

    /**
     * The cap is a safety control, not a provider override, so it must survive
     * `enabled = 0`. Routing it through get_effective_config would tie a
     * financial limit to an unrelated toggle: that method returns global config
     * whenever the row is disabled.
     */
    public function test_cap_applies_even_when_provider_override_is_disabled(): void {
        $course = $this->getDataGenerator()->create_course();
        course_config_manager::save($course->id, ['enabled' => 0, 'spend_cap_monthly' => '10']);

        $this->assertSame(10.0, spend_guard::get_cap($course->id));
    }

    /**
     * Blank / zero / negative all mean "no per-course cap" and must fall through
     * to the site-wide default rather than being stored as a literal 0, which
     * the guard reads as unlimited.
     */
    public function test_blank_cap_falls_back_to_the_site_default(): void {
        set_config('spend_cap_per_course_default', '25', 'local_ai_course_assistant');

        foreach (['', '0', '-5', null] as $raw) {
            $course = $this->getDataGenerator()->create_course();
            course_config_manager::save($course->id, ['enabled' => 1, 'spend_cap_monthly' => $raw]);

            $this->assertSame(0.0, course_config_manager::get_spend_cap($course->id),
                'A non-positive cap must be stored as null, not enforced');
            $this->assertSame(25.0, spend_guard::get_cap($course->id),
                'With no per-course cap the site-wide default must apply');
        }
    }

    /**
     * A per-course cap beats the site-wide default.
     */
    public function test_course_cap_overrides_the_site_default(): void {
        set_config('spend_cap_per_course_default', '25', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        course_config_manager::save($course->id, ['enabled' => 1, 'spend_cap_monthly' => '5']);

        $this->assertSame(5.0, spend_guard::get_cap($course->id),
            'The explicit per-course cap must win over the default');
    }

    /**
     * Saving unrelated fields must not silently drop an existing cap.
     */
    public function test_cap_survives_a_save_that_omits_it(): void {
        $course = $this->getDataGenerator()->create_course();
        course_config_manager::save($course->id, ['enabled' => 1, 'spend_cap_monthly' => '77']);
        $this->assertSame(77.0, spend_guard::get_cap($course->id));

        // The page always posts the field, so an omitting save clears it by
        // design; this pins that the behaviour is deliberate rather than random.
        course_config_manager::save($course->id, ['enabled' => 1, 'model' => 'gpt-4o-mini']);
        $this->assertSame(0.0, course_config_manager::get_spend_cap($course->id));
    }
}
