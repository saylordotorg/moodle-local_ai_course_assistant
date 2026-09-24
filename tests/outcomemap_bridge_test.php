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
     * Every source the detector can return must be in objective_manager::SOURCES.
     *
     * objectives_admin.php validates the posted source against that constant
     * before importing, so a candidate added to detect_best_source() without a
     * matching entry would make its own import action fail. This reads the
     * candidate list out of the method body rather than restating it, so the
     * two cannot drift apart.
     */
    public function test_every_detector_source_is_an_allowed_source(): void {
        $this->resetAfterTest();
        $method = new \ReflectionMethod(objective_manager::class, 'detect_best_source');
        $file = file($method->getFileName());
        $body = implode('', array_slice(
            $file,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
        preg_match_all("/\[\s*'([a-z]+)'\s*,\s*\[/", $body, $m);
        $this->assertNotEmpty($m[1], 'could not read the candidate list; the parse is broken');
        $this->assertContains('outcomemap', $m[1], 'outcomemap should be a detector candidate');
        foreach ($m[1] as $source) {
            $this->assertContains(
                $source,
                objective_manager::SOURCES,
                "detect_best_source() can return '$source' but objective_manager::SOURCES "
                . "omits it, so objectives_admin.php would reject its own import"
            );
        }
    }

    /**
     * objs.source is char(20), so create() must clamp like its neighbours.
     *
     * Every other field in the insert gets a substr(); source did not, which
     * made an over-long value a DB insert error rather than a truncation.
     */
    public function test_create_clamps_an_overlong_source(): void {
        $this->resetAfterTest();
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $id = objective_manager::create(
            (int) $course->id,
            'Clamp check',
            '',
            '',
            str_repeat('x', 40)
        );
        $row = $DB->get_record('local_ai_course_assistant_objs', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(20, strlen($row->source));
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

    /**
     * A state that carries no figure must never become a percentage.
     *
     * This is the assertion that matters most in this file. On the production
     * degrees site 525 of 546 result rows are insufficient_evidence and 21 are
     * calculated. A reader that cast those to 0.0 would tell almost every learner
     * they scored zero on an outcome nobody has measured, which is the same defect
     * as scoring a Soapbox criterion zero because the camera was off, and it would
     * be far more visible because it is the normal case rather than the edge.
     *
     * @return void
     */
    public function test_a_state_without_evidence_yields_null_not_zero(): void {
        $method = new \ReflectionMethod(outcomemap_bridge::class, 'percent_or_null');
        $method->setAccessible(true);

        foreach (['insufficient_evidence', 'calculation_pending', 'stale', 'not_released', 'not_assessed'] as $state) {
            $this->assertNull(
                $method->invoke(null, $state, '87.5'),
                "State {$state} carries no usable figure, so it must return null even when the "
                    . 'upstream payload contains a number. Returning a float here would render a '
                    . 'percentage nobody calculated and nobody released.'
            );
        }
    }

    /**
     * A calculated state keeps its number, cast from the canonical decimal string.
     *
     * Without this, returning null unconditionally would satisfy the test above
     * and delete the only figures that are real.
     *
     * @return void
     */
    public function test_a_calculated_state_keeps_its_percentage(): void {
        $method = new \ReflectionMethod(outcomemap_bridge::class, 'percent_or_null');
        $method->setAccessible(true);

        $this->assertSame(87.5, $method->invoke(null, 'calculated', '87.5'));
        $this->assertSame(0.0, $method->invoke(null, 'calculated', '0.0000000000'));
        $this->assertNull(
            $method->invoke(null, 'calculated', null),
            'A calculated row with a null percentage is still null. The upstream contract '
                . 'allows it, and (float) null is 0.0, which is the value this class exists to '
                . 'avoid inventing.'
        );
    }

    /**
     * One learner cannot read another learner's attainment.
     *
     * The underlying external function requires a system capability no learner
     * holds, so the only paths in are "asking about yourself" and "holding the
     * capability". Neither is true here.
     *
     * @return void
     */
    public function test_a_learner_cannot_read_someone_elses_attainment(): void {
        $this->resetAfterTest();

        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();
        $this->setUser($alice);

        $this->assertSame(
            [],
            outcomemap_bridge::attainment((int) $bob->id),
            'Attainment is evidence about a named person across their whole programme. A learner '
                . 'asking for another learner id must get nothing, whether or not the plugin is '
                . 'installed on this site.'
        );
    }

    /**
     * An absent plugin is an empty answer, not an error.
     *
     * @return void
     */
    public function test_attainment_is_empty_when_the_plugin_is_absent(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        if (!outcomemap_bridge::attainment_available()) {
            $this->assertSame([], outcomemap_bridge::attainment((int) $user->id));
        } else {
            $this->markTestSkipped('local_outcomemap is installed on this site.');
        }
    }

    /**
     * The kill switch is off by default, and off means nothing renders.
     *
     * This is the assertion that makes shipping the feature safe. It goes to
     * production in the last release of the year, on a code path that cannot
     * currently work for learners, so the default has to be silence and the
     * switch has to be the only thing that breaks it.
     *
     * @return void
     */
    public function test_the_panel_is_off_by_default(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($user);

        // Falsy rather than a specific value. get_config returns '' when the
        // setting has never been written and '0' once install has applied the
        // declared default, and both are the state this test is about; asserting
        // one of the two spellings fails on whichever install order it did not
        // expect, which is a broken test rather than a caught regression.
        $this->assertEmpty(
            get_config('local_ai_course_assistant', 'outcomes_panel_enabled'),
            'The setting must be off on a fresh install. A default of on would put a panel in '
                . 'front of every site running the outcomes plugin, the day their Outcome Map is '
                . 'upgraded, with nobody expecting it.'
        );
        $this->assertNull(
            outcomemap_bridge::course_panel((int) $user->id, (int) $course->id),
            'With the switch off, course_panel must return null before it looks at anything else, '
                . 'so an administrator can silence the feature without a deploy.'
        );
    }

    /**
     * Switching it on does not by itself make a panel appear.
     *
     * The switch is permission to try, not a guarantee of output. The course must
     * still participate in outcomes and the learner must still have attainment,
     * so a site that enables it on a course with no outcome mapping still gets
     * nothing rather than an empty box.
     *
     * @return void
     */
    public function test_enabling_it_still_respects_the_other_gates(): void {
        $this->resetAfterTest();

        set_config('outcomes_panel_enabled', 1, 'local_ai_course_assistant');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($user);

        $this->assertNull(
            outcomemap_bridge::course_panel((int) $user->id, (int) $course->id),
            'A bare course has no outcome mapping, so even with the switch on there is nothing to '
                . 'show and null is the right answer.'
        );
    }

    /**
     * Without the learner-safe API the panel is hidden from everyone, staff included.
     *
     * This is the finding that nearly shipped. Attainment can be read two ways: the
     * SIS export function, which needs a system capability administrators have and
     * students do not, and the own-attainment function, which needs neither because
     * it cannot be asked about anybody but its caller. If the panel gated only on
     * "can attainment be read at all", then on a site running an older Outcome Map
     * it would render for administrators and for nobody else, so the people
     * checking whether the feature worked would be the only people it worked for.
     *
     * A feature that is invisible to its audience and visible to its reviewers is
     * worse than one that is absent, because absence is noticed.
     *
     * @return void
     */
    public function test_without_the_learner_safe_api_nobody_sees_the_panel(): void {
        $this->resetAfterTest();

        if (outcomemap_bridge::own_attainment_available()) {
            $this->markTestSkipped('This site has the learner-safe attainment API installed.');
        }

        set_config('outcomes_panel_enabled', 1, 'local_ai_course_assistant');

        $course = $this->getDataGenerator()->create_course();
        $admin = get_admin();
        $this->setUser($admin);

        $this->assertNull(
            outcomemap_bridge::course_panel((int) $admin->id, (int) $course->id),
            'An administrator holds the export capability and could be answered through the '
                . 'privileged path, so this is exactly the caller who would see a panel that no '
                . 'learner on the site can see. The gate must refuse them too.'
        );
    }

    /**
     * A learner is never answered through the privileged path.
     *
     * The tempting shortcut, when the learner-safe function is missing, is to call
     * the export function on the learner's behalf because we have already checked
     * that they are asking about themselves. That would make
     * local/outcomemap:exportattainment mean nothing: the site would be granting
     * through SOLA exactly what it withheld in Outcome Map. Empty is the answer.
     *
     * @return void
     */
    public function test_a_learner_is_not_answered_through_the_privileged_path(): void {
        $this->resetAfterTest();

        if (outcomemap_bridge::own_attainment_available()) {
            $this->markTestSkipped('This site has the learner-safe attainment API installed.');
        }

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($user);

        $this->assertSame(
            [],
            outcomemap_bridge::attainment((int) $user->id),
            'Asking about yourself is permission to be answered, not permission to be answered by '
                . 'any means available.'
        );
    }

    /**
     * The learner-safe API is a strict subset of attainment being readable at all.
     *
     * Pins the relationship between the two availability checks rather than each
     * one separately, so a later edit cannot leave course_panel gating on the
     * narrower condition while attainment() routes on the wider one.
     *
     * @return void
     */
    public function test_the_learner_safe_api_implies_attainment_is_available(): void {
        $this->resetAfterTest();

        if (!outcomemap_bridge::own_attainment_available()) {
            $this->assertTrue(true, 'Nothing to imply on a site without the learner-safe API.');
            return;
        }

        $this->assertTrue(
            outcomemap_bridge::attainment_available(),
            'attainment_available() answers "can attainment be read by somebody", so it cannot be '
                . 'false while the learner-safe reader is installed.'
        );
    }

    /**
     * Attainment is read by calling the function, not by calling the web service layer.
     *
     * external_api::call_external_function() is the HTTP and AJAX entry path, and
     * for any login-required function outside a web service server it calls
     * require_sesskey(). Whether that succeeds depends on whether the surrounding
     * request happens to carry a sesskey parameter: it does when SOLA's mastery
     * summary is fetched over AJAX, and it does not from cron, from CLI, or from a
     * plain page render.
     *
     * So the wrapper turns an optional data source into one that works or returns
     * nothing depending on how the page was reached, with the failure swallowed
     * into debugging() either way. It cost an afternoon to find once, because in
     * the only path anyone tested by hand the sesskey was there.
     *
     * A source-level assertion because the condition cannot be reproduced in a test:
     * PHPUnit has no sesskey, so the wrapper fails here unconditionally and a
     * behavioural test would pass for the wrong reason on the day somebody added
     * one. Nothing is given up by going direct, since the capability and context
     * checks live inside the function's own execute().
     *
     * @return void
     */
    public function test_attainment_does_not_go_through_the_web_service_wrapper(): void {
        $source = file_get_contents(__DIR__ . '/../classes/outcomemap_bridge.php');
        $this->assertIsString($source);

        // Every line that names the wrapper must be a comment explaining why it is
        // not used. Asserting on lines rather than on the file lets the explanation
        // name the thing it is about, which a plain "must not appear" assertion
        // makes impossible.
        $offenders = [];
        foreach (explode("\n", $source) as $number => $line) {
            if (strpos($line, 'call_external_function') !== false && strpos(ltrim($line), '//') !== 0) {
                $offenders[] = ($number + 1) . ': ' . trim($line);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Resolve the function through external_function_info() and call its classname and '
                . 'methodname directly. See this test\'s docblock for the sesskey trap.'
        );
    }
}
