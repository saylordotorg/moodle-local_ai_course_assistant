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
 * Tests for the single protocol-marker stripper.
 *
 * The fixtures that matter are the ones taken verbatim from the 2026-09-12
 * production comparison run, where markers reached learners on learn.saylor.org.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_course_assistant;

/**
 * @covers \local_ai_course_assistant\protocol_markers
 */
final class protocol_markers_test extends \basic_testcase {

    /**
     * No marker of any shape may survive.
     *
     * @return void
     */
    public function test_every_marker_shape_is_removed(): void {
        $cases = [
            // Verbatim from production: BUS101 Q5, Gemini. Unterminated opener.
            "Compound interest matters.\n[SOLA_NEXT]Tell me more"
                => 'Compound interest matters.',
            // Verbatim from production: the cmid citation form.
            'See the reading. [[SOURCE:activity:86467]] It covers this.'
                => 'See the reading. It covers this.',
            // Well-formed block.
            "Answer here.\n[SOLA_NEXT]a||b||c[/SOLA_NEXT]" => 'Answer here.',
            // Legacy single-bracket citation.
            'Answer. [SOURCE:page] More.' => 'Answer. More.',
            // Closer with no opener.
            'Answer.[/SOLA_NEXT]' => 'Answer.',
            // Whitespace and case variants.
            "Answer.\n[ SOLA_NEXT ]Tell me more" => 'Answer.',
            "Answer.\n[SOLA_NEXT]x[/sola_next ]" => 'Answer.',
            // Unterminated score block: raw JSON must never reach a learner.
            "Nice work.\n[SOLA_SCORE]{\"criteria\":[{\"name\":" => 'Nice work.',
            // Standalone markers.
            'Text [OFF_TOPIC] here' => 'Text here',
            'Text [NEEDS_ESCALATION] here' => 'Text here',
            // Internal chunk reference.
            'Answer [[c:12]] text' => 'Answer text',
        ];
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, protocol_markers::strip($input));
        }
    }

    /**
     * Ordinary prose must come back untouched.
     *
     * @return void
     */
    public function test_text_without_markers_is_unchanged(): void {
        $text = "Recursion is when a function calls itself.\n\nIt needs a base case.";
        $this->assertSame($text, protocol_markers::strip($text));
        $this->assertFalse(protocol_markers::has_marker($text));
    }

    /**
     * A stray marker before the real block must not eat the answer.
     *
     * This is the regression that the first round of stripping introduced: a
     * lazy unanchored match spanned from a stray marker all the way to the
     * terminal block, deleting the answer body and rendering it as a chip.
     *
     * @return void
     */
    public function test_a_stray_marker_does_not_consume_the_answer(): void {
        $input = "Recursion is X. [SOLA_NEXT] wait.\n\nHere is the rest.\n"
            . '[SOLA_NEXT]Quiz me||Example[/SOLA_NEXT]';
        $out = protocol_markers::strip($input);
        $this->assertStringContainsString('Here is the rest.', $out);
        $this->assertStringNotContainsString('SOLA_NEXT', $out);
    }

    /**
     * has_marker() reports a leak without the caller having to diff.
     *
     * @return void
     */
    public function test_has_marker_detects_a_leak(): void {
        $this->assertTrue(protocol_markers::has_marker("hi\n[SOLA_NEXT]more"));
        $this->assertFalse(protocol_markers::has_marker('hi there'));
    }

    /**
     * Invalid UTF-8 must not blank the text.
     *
     * preg_replace() returns null on a bad subject and a (string) cast would
     * turn the learner's whole answer into an empty string.
     *
     * @return void
     */
    public function test_invalid_utf8_does_not_destroy_the_text(): void {
        $text = "Time: 26 hours \x92 self-paced";
        $this->assertNotSame('', protocol_markers::strip($text));
    }
}
