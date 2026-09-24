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
 * This is the test the max_score episode should have produced. During v7.5.1's
 * development a scored criterion gained max_score without a matching declaration
 * in execute_returns(). The defect was invisible to a direct call and appeared
 * only through clean_returnvalue(), which is the reason for this file.
 *
 * What it would actually have done is worth stating precisely, because the
 * commit message and three comments said otherwise for a week. An undeclared key
 * is silently DROPPED, not rejected, so the call would have succeeded and the
 * browser would simply never have received max_score. Nothing would have failed
 * and nothing would have been logged. It also never reached a release: the
 * defect and its fix both landed before the v7.5.1 tag.
 *
 * The expensive direction is the other one. A DECLARED key that is absent throws
 * invalid_response_exception, on an ajax endpoint, after the provider has been
 * billed and the row written. Adding the program-outcomes panel widened this
 * structure and made that mistake available again through the early return that
 * fires on every course with mastery switched off.
 *
 * Both rules are proven rather than asserted, in external_return_semantics_test.
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

    /**
     * The program outcomes panel is decided before the course-mastery gate.
     *
     * They are different questions, and for one release the code said so in a
     * comment while doing the opposite. execute() returned early when SOLA's own
     * objectives were disabled for the course, with showprograms hard-coded false,
     * so a course that had program outcomes in Outcome Map but no SOLA objectives
     * showed a learner nothing. The browser had a branch for exactly that state and
     * the server could not produce it.
     *
     * Source-level because the two states cannot be told apart behaviourally
     * without the optional third-party plugin installed and seeded: with it absent,
     * which is every CI job, showprograms is false either way and a behavioural
     * test would pass whichever order the code is in.
     *
     * @return void
     */
    public function test_the_panel_is_resolved_before_the_mastery_gate(): void {
        $source = file_get_contents(__DIR__ . '/../classes/external/get_mastery_summary.php');
        $this->assertIsString($source);

        $panel = strpos($source, 'outcomemap_bridge::course_panel(');
        $gate = strpos($source, 'objective_manager::is_enabled_for_course(');

        $this->assertNotFalse($panel, 'course_panel() is no longer called; update this guard.');
        $this->assertNotFalse($gate, 'the mastery gate has moved; update this guard.');
        $this->assertLessThan(
            $gate,
            $panel,
            'Resolve the program outcomes panel BEFORE returning early on disabled course '
                . 'mastery. A learner\'s degree progress must not be hidden by an unrelated '
                . 'setting on one course.'
        );
    }
}
