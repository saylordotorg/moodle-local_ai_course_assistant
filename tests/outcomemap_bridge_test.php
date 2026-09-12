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

/**
 * Tests for the optional local_outcomemap objective source.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_course_assistant;

/**
 * @covers \local_ai_course_assistant\outcomemap_bridge
 */
final class outcomemap_bridge_test extends \advanced_testcase {

    /**
     * The whole point of the bridge is that it is inert without the plugin.
     *
     * CI does not install local_outcomemap, so this asserts the condition that
     * every site without the plugin actually runs under. It is deliberately
     * written to be correct either way: if a future CI image DOES ship the
     * plugin, the assertion follows class_exists() rather than hard-coding false,
     * so this test reports the truth instead of failing spuriously.
     */
    public function test_is_available_tracks_the_plugin_being_installed(): void {
        $this->resetAfterTest();
        $expected = class_exists('\\local_outcomemap\\api\\outcome_search')
            && method_exists('\\local_outcomemap\\api\\outcome_search', 'search');
        $this->assertSame($expected, outcomemap_bridge::is_available());
    }

    /**
     * With the plugin absent, fetch() must return an empty array and must not throw.
     *
     * A throw here would propagate into detect_best_source(), which is called on
     * every load of objectives_admin.php for a course with no objectives.
     */
    public function test_fetch_is_empty_and_silent_without_the_plugin(): void {
        $this->resetAfterTest();
        if (outcomemap_bridge::is_available()) {
            $this->markTestSkipped('local_outcomemap is installed; this asserts the absent case.');
        }
        $course = $this->getDataGenerator()->create_course();
        $this->assertSame([], outcomemap_bridge::fetch((int) $course->id));
    }

    /**
     * Guard inputs are rejected before any work, including the site course.
     *
     * SITEID matters: objective discovery is per real course, and the site course
     * would otherwise resolve to a valid context and reach the API.
     */
    public function test_fetch_rejects_guard_inputs(): void {
        $this->resetAfterTest();
        $this->assertSame([], outcomemap_bridge::fetch(0));
        $this->assertSame([], outcomemap_bridge::fetch(-1));
        $this->assertSame([], outcomemap_bridge::fetch(SITEID));
    }

    /**
     * A course id that does not exist must be handled, not fatal.
     */
    public function test_fetch_handles_a_missing_course(): void {
        $this->resetAfterTest();
        global $DB;
        $maxid = (int) $DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {course}');
        $this->assertSame([], outcomemap_bridge::fetch($maxid + 1000));
    }

    /**
     * detect_best_source() must offer outcomemap first, and must still fall
     * through to the existing candidates when it yields nothing.
     *
     * The ordering is the whole value of the change: returning on the first
     * candidate with three or more objectives is what stops a mapped course ever
     * reaching extract_from_section_content().
     */
    public function test_detect_best_source_still_falls_through_when_outcomemap_is_absent(): void {
        $this->resetAfterTest();
        if (outcomemap_bridge::is_available()) {
            $this->markTestSkipped('local_outcomemap is installed; this asserts the absent case.');
        }
        $course = $this->getDataGenerator()->create_course();
        $result = objective_manager::detect_best_source((int) $course->id);
        $this->assertArrayHasKey('source', $result);
        $this->assertArrayHasKey('objectives', $result);
        // An empty course yields nothing from any candidate.
        $this->assertNotSame('outcomemap', $result['source']);
    }

    /**
     * Every source the detector can report must have a display string, because
     * objectives_admin.php calls get_string('objectives:source_' . $source) with
     * no fallback and would otherwise render a missing-string placeholder.
     */
    public function test_every_detector_source_has_a_lang_string(): void {
        $this->resetAfterTest();
        foreach (['outcomemap', 'competency', 'summary', 'section', 'llm', 'manual', 'none'] as $source) {
            $this->assertTrue(
                get_string_manager()->string_exists(
                    'objectives:source_' . $source,
                    'local_ai_course_assistant'
                ),
                'Missing lang string for objective source: ' . $source
            );
        }
    }

    /**
     * The provenance reference must fit objs.external_ref, which is char(64).
     *
     * The prefix plus a 36-character UUID is 47, so this has headroom; the test
     * exists so that lengthening the prefix cannot silently truncate a UUID and
     * make two different outcome versions collide on the same reference.
     */
    public function test_reference_prefix_leaves_room_for_a_uuid(): void {
        $this->assertLessThanOrEqual(64, strlen(outcomemap_bridge::REF_PREFIX) + 36);
    }
}
