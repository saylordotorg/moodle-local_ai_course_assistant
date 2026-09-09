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
 * The Stop button must re-pin the scroll after it steals height from the messages area.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stop_button_scroll_test extends \advanced_testcase {

    /**
     * Return the body of a named `const <name> = function(...) {...};` in ui.js.
     *
     * @param string $name Function name to extract.
     * @return string
     */
    private function ui_function_body(string $name): string {
        global $CFG;
        $src = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/amd/src/ui.js');
        $this->assertNotFalse($src, 'amd/src/ui.js is unreadable');

        $start = strpos($src, 'const ' . $name . ' = function');
        $this->assertNotFalse($start, "ui.js no longer defines {$name}");

        // The functions we care about are indented four spaces, so the first
        // line reading exactly "    };" closes them.
        $end = strpos($src, "\n    };", $start);
        $this->assertNotFalse($end, "could not find the end of {$name}");

        return substr($src, $start, $end - $start);
    }

    /**
     * The stop-slot lives outside the scrollable messages area. Revealing it shrinks
     * that area, so the scroll must be re-pinned AFTER the reveal or the learner's
     * own just-sent question is left clipped below the fold -- reported from staging
     * on 2026-09-09, where it read as the Stop button covering the question.
     */
    public function test_stop_button_repins_scroll_after_revealing_the_slot(): void {
        $body = $this->ui_function_body('showStopButton');

        $reveal = strpos($body, 'slot.hidden = false');
        $this->assertNotFalse($reveal, 'showStopButton no longer reveals the slot');

        $scroll = strpos($body, 'scrollToBottom', $reveal);
        $this->assertNotFalse(
            $scroll,
            'showStopButton reveals the stop slot but never re-pins the scroll afterwards. '
            . 'Revealing the slot shrinks the messages area, so the last message ends up '
            . 'clipped by the height of the Stop button.'
        );

        // Reading the scroll position before the layout change is what keeps this from
        // yanking a learner who has deliberately scrolled up to re-read history.
        $this->assertMatchesRegularExpression(
            '/isNearBottom\(\)/',
            substr($body, 0, $reveal),
            'showStopButton must capture whether the view was at the bottom BEFORE '
            . 'revealing the slot, so it only re-pins when the learner was following along.'
        );
    }

    /**
     * The typing indicator shrinks the same container and already scrolls after
     * toggling. Pin that, so a refactor that moves the scroll above the toggle
     * reintroduces the same class of clipping.
     */
    public function test_typing_indicator_scrolls_after_it_is_revealed(): void {
        $body = $this->ui_function_body('showTyping');

        $toggle = strpos($body, "setAttribute('aria-hidden'");
        $this->assertNotFalse($toggle, 'showTyping no longer toggles aria-hidden');

        $scroll = strpos($body, 'scrollToBottom', $toggle);
        $this->assertNotFalse(
            $scroll,
            'showTyping must scroll AFTER revealing the indicator; scrolling first '
            . 'leaves the last message clipped by the indicator height.'
        );
    }
}
