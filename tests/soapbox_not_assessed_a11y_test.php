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
 * The reason a criterion was not assessed reaches a screen reader.
 *
 * All three renderers of the badge put the explanation in a title attribute on
 * a span that already had visible text. An element with its own text content
 * takes its accessible name from that text, so the title is decorative on hover
 * and most screen readers never announce it. The string is named
 * soapbox:not_assessed_aria, so the intent was clearly that assistive
 * technology would read it. It did not.
 *
 * That matters here more than it would elsewhere: these are self-paced courses
 * with no instructor, so the explanation of why a criterion was dropped is the
 * only thing standing between the learner and the belief that they were marked
 * down for it.
 *
 * @package    local_ai_course_assistant
 * @covers     \local_ai_course_assistant\rubric_manager::is_assessed
 */
final class soapbox_not_assessed_a11y_test extends \basic_testcase {

    /**
     * Sources that render the badge, and must all render it the same way.
     *
     * @return array<string, array{string}>
     */
    public static function badge_renderers(): array {
        return [
            'soapbox.php stored-score table and live table' => ['soapbox.php'],
            'the learner attempt list template' => ['templates/soapbox_present.mustache'],
        ];
    }

    /**
     * The aria string is never rendered into a title attribute.
     *
     * @dataProvider badge_renderers
     * @param string $relpath Source file, relative to the plugin root.
     * @return void
     */
    public function test_the_explanation_is_not_hidden_in_a_title($relpath): void {
        $src = file_get_contents(dirname(__DIR__) . '/' . $relpath);
        $this->assertNotFalse($src, "{$relpath} is missing");

        $this->assertDoesNotMatchRegularExpression(
            '/title\s*=\s*["\']?[^>]*not_?assessed_?aria/i',
            $src,
            "{$relpath} puts the not-assessed explanation in a title attribute. The badge has its "
                . "own visible text, so the title is never announced and the explanation is "
                . "mouse-only. Put it in a visually-hidden sibling span instead."
        );
    }

    /**
     * And it is rendered, in a visually-hidden sibling.
     *
     * Without this, deleting the string entirely would pass the test above.
     *
     * @dataProvider badge_renderers
     * @param string $relpath Source file, relative to the plugin root.
     * @return void
     */
    public function test_the_explanation_is_rendered_visually_hidden($relpath): void {
        $src = file_get_contents(dirname(__DIR__) . '/' . $relpath);

        $this->assertMatchesRegularExpression(
            '/accesshide/',
            $src,
            "{$relpath} no longer renders the not-assessed explanation in a visually-hidden span, "
                . "so a screen-reader user is told a criterion was not assessed and never told why."
        );
    }

    /**
     * accesshide is Moodle core's class, and this plugin already uses it.
     *
     * sr-only is Bootstrap 4 and was renamed visually-hidden in Bootstrap 5.
     * version.php declares support for Moodle 4.5 through 5.2, which spans that
     * rename, so neither Bootstrap name is safe across the supported range.
     *
     * @return void
     */
    public function test_the_hidden_class_is_the_one_core_provides(): void {
        $src = file_get_contents(dirname(__DIR__) . '/soapbox.php');

        // Matched as a CLASS, not as a phrase. An earlier version of this test
        // searched for the bare words and failed on the comment three lines
        // above the markup, which is the same false positive shape as the
        // benchmark-exclusion guard.
        $this->assertDoesNotMatchRegularExpression(
            '/class\s*=\s*["\'][^"\']*\bsr-only\b/',
            $src,
            'sr-only is Bootstrap 4 only, and this plugin supports Moodle 4.5 through 5.2'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class\s*=\s*["\'][^"\']*\bvisually-hidden\b/',
            $src,
            'visually-hidden is Bootstrap 5 only, and this plugin supports Moodle 4.5 through 5.2'
        );
        $this->assertMatchesRegularExpression(
            '/class\s*=\s*["\'][^"\']*\baccesshide\b/',
            $src,
            'accesshide is the class Moodle core provides across the whole supported range'
        );
    }
}
