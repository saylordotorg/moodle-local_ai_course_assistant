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

namespace local_ai_course_assistant\external;

/**
 * Tests for the Soapbox score_speech endpoint (v6.7.0): the feature gate and
 * input-validation paths that run before any LLM call. The scoring path itself
 * needs a live provider and is exercised by the manual/functional smoke.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\score_speech
 */
final class score_speech_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    public function test_returns_disabled_when_soapbox_off_for_course(): void {
        $course = $this->getDataGenerator()->create_course();
        // Soapbox is off by default (site + course).
        $result = score_speech::execute((int) $course->id,
            str_repeat('word ', 30), 'Name', 'Topic', 180, 120);

        $this->assertFalse($result['success']);
        $this->assertSame('disabled', $result['message']);
        $this->assertSame([], $result['criteria']);
        $this->assertSame(0, $result['scoreid']);
    }

    public function test_returns_too_short_for_a_tiny_transcript(): void {
        $course = $this->getDataGenerator()->create_course();
        set_config('soapbox_enabled', 1, 'local_ai_course_assistant');

        $result = score_speech::execute((int) $course->id, 'too short', '', '', 0, 0);

        $this->assertFalse($result['success']);
        $this->assertSame('too_short', $result['message']);
    }

    public function test_too_short_boundary_is_40_characters(): void {
        $course = $this->getDataGenerator()->create_course();
        set_config('soapbox_enabled', 1, 'local_ai_course_assistant');

        // 39 chars -> too_short; the gate is mb_strlen < 40.
        $thirtynine = str_pad('a', 39, 'a');
        $this->assertSame(39, mb_strlen($thirtynine));
        $result = score_speech::execute((int) $course->id, $thirtynine, '', '', 0, 0);
        $this->assertSame('too_short', $result['message']);
    }

    public function test_execute_returns_structure_is_stable(): void {
        $course = $this->getDataGenerator()->create_course();
        $result = score_speech::execute((int) $course->id, 'x', '', '', 0, 0);
        // Even on an early return, the documented shape is present.
        foreach (['success', 'message', 'criteria', 'overall', 'tips', 'scoreid'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_mode_hint_informative_and_persuasive_differ(): void {
        $inf = score_speech::mode_hint('informative');
        $per = score_speech::mode_hint('persuasive');
        $this->assertStringContainsStringIgnoringCase('informative', $inf);
        $this->assertStringContainsStringIgnoringCase('persuasive', $per);
        $this->assertStringContainsStringIgnoringCase('call to action', $per);
        $this->assertNotSame($inf, $per);
    }

    public function test_mode_hint_is_case_insensitive(): void {
        $this->assertSame(score_speech::mode_hint('persuasive'), score_speech::mode_hint('Persuasive'));
    }

    public function test_mode_hint_unknown_returns_empty(): void {
        $this->assertSame('', score_speech::mode_hint('interpretive-dance'));
        $this->assertSame('', score_speech::mode_hint(''));
    }
}
