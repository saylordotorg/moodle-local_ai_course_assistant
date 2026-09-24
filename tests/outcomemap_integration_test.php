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

/**
 * The program outcomes panel, driven against a real local_outcomemap install.
 *
 * WHY THIS EXISTS, given that it skips on every CI job. Three defects shipped into
 * this feature and survived a code review, two clean static analysis runs and a
 * green suite, because every test asserted a refusal or a shape and none drove the
 * thing with data as the person it was written for. In order:
 *
 *   1. The panel read attainment through an API needing a system capability no
 *      learner holds.
 *   2. The fix for that read the outcome definitions, needing an author capability
 *      no learner holds either.
 *   3. The pooling underneath the learner-safe API still required the first
 *      capability, one layer below where anyone had looked.
 *
 * Each one made the panel invisible to learners and visible to staff, so every
 * manual check confirmed it worked. A test that skips where the optional plugin is
 * absent and runs where it is present is the only thing that catches that class,
 * and it caught all three in one afternoon once it existed.
 *
 * Run it on a machine with local_outcomemap installed, which is the dev fleet and a
 * local tree, and read a skip here as "not tested", never as "passed".
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\outcomemap_bridge
 */
final class outcomemap_integration_test extends \advanced_testcase {
    /**
     * Seed a program with attainment, or skip with a reason worth reading.
     *
     * @param int $learners How many learners to seed.
     * @return array The upstream fixture description.
     */
    private function seed(int $learners = 2): array {
        if (!outcomemap_bridge::own_attainment_available()) {
            $this->markTestSkipped(
                'local_outcomemap 0.9.4 or later is not installed, so the learner path cannot be '
                    . 'exercised here. This is NOT a pass.'
            );
        }

        $generator = $this->getDataGenerator()->get_plugin_generator('local_outcomemap');
        if (!method_exists($generator, 'create_program_attainment')) {
            $this->markTestSkipped(
                'The installed local_outcomemap has no create_program_attainment() generator, so '
                    . 'there is no supported way to seed attainment. This is NOT a pass.'
            );
        }

        set_config('outcomes_panel_enabled', 1, 'local_ai_course_assistant');
        return $generator->create_program_attainment($learners);
    }

    /**
     * A learner sees their own program outcomes, holding no capability a learner lacks.
     *
     * The two assertFalse calls are the test. Without them this would pass while
     * being run as somebody who could see everything, which is exactly how the
     * three defects above stayed hidden.
     *
     * @return void
     */
    public function test_a_learner_sees_the_panel_without_staff_capabilities(): void {
        $this->resetAfterTest();

        $fixture = $this->seed();
        $learnerid = $fixture['learnerids'][0];
        $courseid = (int) $fixture['courseid'];
        $this->setUser($learnerid);

        $this->assertFalse(
            has_capability('local/outcomemap:exportattainment', \context_system::instance()),
            'A learner must not hold the SIS export capability, or this test proves nothing.'
        );
        $this->assertFalse(
            has_capability('local/outcomemap:viewdefinitions', \context_course::instance($courseid)),
            'A learner must not hold the definitions capability, or this test proves nothing.'
        );

        $panel = outcomemap_bridge::course_panel($learnerid, $courseid);

        $this->assertNotNull($panel, 'The panel is null for a learner who has attainment.');
        $this->assertSame($fixture['programcode'], $panel[0]['code']);
        $this->assertNotEmpty($panel[0]['outcomes']);
    }

    /**
     * A row with no percentage arrives as null, with a sentence, never as zero.
     *
     * @return void
     */
    public function test_a_row_without_a_figure_explains_itself(): void {
        $this->resetAfterTest();

        $fixture = $this->seed(1);
        $learnerid = $fixture['learnerids'][0];
        $this->setUser($learnerid);

        $panel = outcomemap_bridge::course_panel($learnerid, (int) $fixture['courseid']);
        $this->assertNotNull($panel);

        foreach ($panel[0]['outcomes'] as $outcome) {
            if ($outcome['state'] === 'calculated') {
                continue;
            }
            $this->assertNull(
                $outcome['percent'],
                'A state that carries no figure must arrive as null. Zero is a claim about the '
                    . 'learner that nobody made.'
            );
            $this->assertNotSame(
                '',
                $outcome['explanation'],
                'Every row without a figure says why, in words. A blank cell reads as a failure.'
            );
        }
    }

    /**
     * The panel does not follow the learner onto a course that uses no outcomes.
     *
     * @return void
     */
    public function test_the_panel_is_absent_on_an_unmapped_course(): void {
        $this->resetAfterTest();

        $fixture = $this->seed(1);
        $learnerid = $fixture['learnerids'][0];
        $this->setUser($learnerid);

        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($learnerid, $other->id);

        $this->assertNull(
            outcomemap_bridge::course_panel($learnerid, (int) $other->id),
            'The learner still has program results; they are not relevant to this course.'
        );
    }

    /**
     * The panel survives the external function's return structure, with data in it.
     *
     * external_single_structure is strict both ways and throws AFTER the call has
     * been billed, so a shape that only holds for the empty case is a bug that
     * appears the first time a real learner has real data.
     *
     * @return void
     */
    public function test_a_populated_panel_survives_clean_returnvalue(): void {
        $this->resetAfterTest();

        $fixture = $this->seed(1);
        $this->setUser($fixture['learnerids'][0]);

        $result = get_mastery_summary::execute((int) $fixture['courseid']);
        $clean = \core_external\external_api::clean_returnvalue(
            get_mastery_summary::execute_returns(),
            $result
        );

        $this->assertTrue(
            $clean['showprograms'],
            'The panel must be reported even though SOLA course mastery is off for this course. '
                . 'They are different questions.'
        );
        $this->assertNotSame([], $clean['programs']);
    }

    /**
     * One learner cannot be shown another's, with data on the table.
     *
     * @return void
     */
    public function test_a_learner_cannot_read_the_other_learners_attainment(): void {
        $this->resetAfterTest();

        $fixture = $this->seed(2);
        $this->setUser($fixture['learnerids'][0]);

        $this->assertSame(
            [],
            outcomemap_bridge::attainment($fixture['learnerids'][1], '', (int) $fixture['courseid']),
            'Asking about the other learner must return nothing, not their row.'
        );
    }

    /**
     * The kill switch still silences a panel that would otherwise render.
     *
     * Tested with data present, because a switch that is only ever exercised on a
     * site with nothing to show has not been exercised.
     *
     * @return void
     */
    public function test_the_kill_switch_silences_a_panel_that_has_data(): void {
        $this->resetAfterTest();

        $fixture = $this->seed(1);
        $learnerid = $fixture['learnerids'][0];
        $courseid = (int) $fixture['courseid'];
        $this->setUser($learnerid);

        $this->assertNotNull(outcomemap_bridge::course_panel($learnerid, $courseid));

        set_config('outcomes_panel_enabled', 0, 'local_ai_course_assistant');

        $this->assertNull(
            outcomemap_bridge::course_panel($learnerid, $courseid),
            'Turning the setting off must stop the panel on the next request, with no deploy.'
        );
    }
}
