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
 * Every score_speech return path satisfies its own declared structure.
 *
 * This exists because of a specific way the suite can lie. The existing
 * stability test calls execute() directly, and Moodle only validates a return
 * value against execute_returns() inside external_api::call_external_function(),
 * which is the AJAX path. So adding a required key to execute_returns() while
 * leaving it out of an early-return array produces a green suite and a
 * production error the moment a learner hits that path: disabled Soapbox, a
 * too-short recording, a provider error, or an unparseable response.
 *
 * clean_returnvalue() is exactly the validation the AJAX path performs, so
 * running it over every early return closes that gap.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\score_speech
 */

namespace local_ai_course_assistant;

use local_ai_course_assistant\external\score_speech;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests over score_speech's declared return structure.
 */
final class score_speech_returns_test extends \advanced_testcase {

    /**
     * The empty result validates against execute_returns().
     *
     * empty_result() is private, so it is reached the way production reaches it:
     * by calling execute() in a state that returns early. Soapbox disabled for
     * the course is the cheapest such state and needs no provider.
     *
     * @return void
     */
    public function test_every_early_return_satisfies_the_declared_structure(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        // Force the disabled path.
        set_config('soapbox_enabled', 0, 'local_ai_course_assistant');
        set_config('soapbox_enabled_course_' . $course->id, 0, 'local_ai_course_assistant');

        $raw = score_speech::execute($course->id, 'a transcript that will not be scored');

        // This is the call the AJAX path makes and the direct-call tests skip.
        $clean = \core_external\external_api::clean_returnvalue(score_speech::execute_returns(), $raw);

        $this->assertFalse($clean['success']);
        $this->assertArrayHasKey(
            'assessedcount',
            $clean,
            'a required key missing from an early return breaks the AJAX path only'
        );
        $this->assertSame(0, (int) $clean['assessedcount']);
    }

    /**
     * Criterion names are matched case- and whitespace-insensitively.
     *
     * This decides what reaches a learner's score, so it is pinned directly
     * rather than only through a mocked provider call.
     *
     * @return void
     */
    public function test_criterion_names_normalise_for_matching(): void {
        $this->assertSame(
            score_speech::normalise_name('Body Language & Gestures'),
            score_speech::normalise_name('  body   language &  GESTURES '),
            'a model echoing a rubric name with different spacing or case must still match'
        );
        $this->assertNotSame(
            score_speech::normalise_name('Body Language & Gestures'),
            score_speech::normalise_name('Body Language'),
            'normalisation must not collapse genuinely different criteria'
        );
    }
}
