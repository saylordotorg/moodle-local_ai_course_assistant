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
 * Every colour the consent notice states inline is readable on the surface it
 * actually sits on.
 *
 * The notice carries its colours as inline styles in the template, and inline
 * styles beat a media query on specificity. So a dark-mode rule on the banner
 * could not recolour its own contents, and a learner in OS dark mode was shown
 * the privacy notice at 1.24:1 against its background. WCAG AA wants 4.5:1.
 * It cleared the moment they accepted, which is the worst time for a privacy
 * notice to be unreadable.
 *
 * This pins the arithmetic rather than the fix, so it stays true if someone
 * later gives the widget real dark-mode support: whatever background the banner
 * has, its stated colours must be legible against it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\hook_callbacks
 */
final class consent_banner_contrast_test extends \advanced_testcase {

    /** @var float WCAG 2.1 AA minimum for body text. */
    private const AA_BODY = 4.5;

    /**
     * Relative luminance of a hex colour, per WCAG 2.1.
     *
     * @param string $hex Colour as #rgb or #rrggbb.
     * @return float
     */
    private function luminance(string $hex): float {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $channel = function (string $pair): float {
            $v = hexdec($pair) / 255;
            return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        };

        return 0.2126 * $channel(substr($hex, 0, 2))
             + 0.7152 * $channel(substr($hex, 2, 2))
             + 0.0722 * $channel(substr($hex, 4, 2));
    }

    /**
     * Contrast ratio between two hex colours, per WCAG 2.1.
     *
     * @param string $a First colour.
     * @param string $b Second colour.
     * @return float Between 1 and 21.
     */
    private function contrast(string $a, string $b): float {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * The arithmetic itself, against values with known answers.
     *
     * A contrast function nobody has checked is how you end up confident about
     * a number that is wrong.
     *
     * @return void
     */
    public function test_the_contrast_arithmetic_is_right(): void {
        $this->assertEqualsWithDelta(21.0, $this->contrast('#000', '#fff'), 0.01);
        $this->assertEqualsWithDelta(1.0, $this->contrast('#777', '#777'), 0.01);
        // The value that prompted this test.
        $this->assertEqualsWithDelta(1.24, $this->contrast('#333', '#1b2430'), 0.01);
    }

    /**
     * The banner has no background rule that its inline contents cannot follow.
     *
     * @return void
     */
    public function test_the_banner_has_no_unreachable_dark_mode(): void {
        $css = (string) file_get_contents(__DIR__ . '/../styles.css');

        // Strip comments: the explanation of why this rule was removed names
        // the very thing being searched for.
        $code = preg_replace('!/\*.*?\*/!s', '', $css);

        $this->assertSame(
            0,
            preg_match('/@media[^{]*prefers-color-scheme[^{]*\{[^}]*aica-consent-banner/s', $code),
            'The consent notice has a dark-mode background again. Its contents carry inline'
                . ' colours from the template, which beat a media query, so the text will not'
                . ' follow the background. If the widget is getting real dark-mode support,'
                . ' move those colours out of the template first and update this test.'
        );
    }

    /**
     * Every colour the template states inline is legible on the banner.
     *
     * @return void
     */
    public function test_every_stated_colour_is_legible_on_the_banner(): void {
        $css = (string) file_get_contents(__DIR__ . '/../styles.css');
        $template = (string) file_get_contents(__DIR__ . '/../templates/chat_widget.mustache');

        $this->assertSame(
            1,
            preg_match('/\.aica-consent-banner\s*\{[^}]*background:\s*(#[0-9a-fA-F]{3,6})/s', $css, $bg),
            'The consent notice has no background colour of its own, so what its text sits'
                . ' on cannot be checked.'
        );
        $background = $bg[1];

        // The banner's markup, from its opening div to the close of the block.
        $start = strpos($template, 'class="aica-consent-banner"');
        $this->assertNotFalse($start, 'The consent notice markup has moved.');
        $end = strpos($template, '{{/consentgiven}}', $start);
        $this->assertNotFalse($end, 'The consent notice block is not closed.');
        $markup = substr($template, $start, $end - $start);

        preg_match_all('/color:\s*(#[0-9a-fA-F]{3,6})/', $markup, $found);
        $this->assertNotEmpty($found[1], 'No inline colours found; this test is checking nothing.');

        foreach (array_unique($found[1]) as $colour) {
            $ratio = $this->contrast($colour, $background);
            $this->assertGreaterThanOrEqual(
                self::AA_BODY,
                $ratio,
                sprintf(
                    'The consent notice states %s on %s, which is %.2f:1. WCAG AA needs %.1f:1'
                        . ' for body text. A learner reading the privacy notice cannot see it.',
                    $colour,
                    $background,
                    $ratio,
                    self::AA_BODY
                )
            );
        }
    }
}
