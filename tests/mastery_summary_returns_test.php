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

use local_ai_course_assistant\external\get_mastery_summary;

defined('MOODLE_INTERNAL') || die();

/**
 * The mastery summary's declared return shape matches what it actually returns.
 *
 * This is the test v7.5.1 did not have. That release added max_score to a scored
 * criterion without declaring it in execute_returns(), and because
 * external_single_structure throws on an undeclared key, on an ajax endpoint,
 * after the provider had been billed and the row written, it broke every
 * successful scoring call in production. The defect was invisible to a direct
 * call and only appeared through clean_returnvalue().
 *
 * Adding the program-outcomes panel widened this same structure, so the same
 * mistake was available again in both directions: a key returned but not
 * declared, and a key declared but missing from the early return that fires on
 * every course with mastery switched off.
 *
 * @package    local_ai_course_assistant
 * @covers     \local_ai_course_assistant\external\get_mastery_summary
 */
final class mastery_summary_returns_test extends \advanced_testcase {

    /**
     * The disabled-course path survives clean_returnvalue().
     *
     * This is the COMMON path, not the rare one: most courses have mastery off.
     * A key declared in execute_returns() but absent from the early return would
     * break here and nowhere else.
     *
     * @return void
     */
    public function test_the_disabled_course_path_matches_the_declared_shape(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($user);

        $raw = get_mastery_summary::execute((int) $course->id);
        $clean = \core_external\external_api::clean_returnvalue(
            get_mastery_summary::execute_returns(),
            $raw
        );

        $this->assertFalse($clean['enabled'], 'mastery is off for a bare course');
        $this->assertArrayHasKey(
            'showprograms',
            $clean,
            'The program-outcomes keys must survive the early return. external_single_structure '
                . 'throws on a declared key that is missing, and this path fires on every course '
                . 'with mastery disabled, which is most of them.'
        );
        $this->assertFalse($clean['showprograms']);
        $this->assertSame([], $clean['programs']);
    }

    /**
     * Without local_outcomemap installed, the panel is off and the call still cleans.
     *
     * The plugin is optional and absent from most installs, so this is the shape
     * almost every site sees.
     *
     * @return void
     */
    public function test_the_panel_is_silent_when_the_outcomes_plugin_is_absent(): void {
        $this->resetAfterTest();

        if (outcomemap_bridge::attainment_available()) {
            $this->markTestSkipped('local_outcomemap is installed on this site.');
        }

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($user);

        $clean = \core_external\external_api::clean_returnvalue(
            get_mastery_summary::execute_returns(),
            get_mastery_summary::execute((int) $course->id)
        );

        $this->assertFalse(
            $clean['showprograms'],
            'A site without the outcomes plugin must render no panel at all, rather than an '
                . 'empty one asking a learner to care about outcomes their site does not track.'
        );
    }
}
