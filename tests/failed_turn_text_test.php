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
 * A failed turn must never replay an internal identifier to the learner.
 *
 * A paused turn showed the friendly notice live, then came back after a reload
 * as "[no response: core\exception\moodle_exception]" -- the notice was never
 * stored, so the history renderer printed the exception class instead.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\conversation_manager
 */
final class failed_turn_text_test extends \advanced_testcase {

    public function test_a_partial_reply_is_kept(): void {
        $this->resetAfterTest();
        $this->assertSame(
            'The four functions are planning,',
            conversation_manager::failed_turn_text('The four functions are planning,', 'ignored')
        );
    }

    public function test_the_notice_the_learner_saw_is_what_replays(): void {
        $this->resetAfterTest();
        $notice = branding::str('emergency:chat_stopped');
        $this->assertSame($notice, conversation_manager::failed_turn_text('', $notice));
    }

    public function test_the_floor_is_a_sentence_not_an_identifier(): void {
        $this->resetAfterTest();
        $text = conversation_manager::failed_turn_text('', '');

        $this->assertNotSame('', trim($text));
        foreach (['core\\', 'exception', 'moodle_', 'no response:', 'Throwable', '::'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase(
                $leak,
                $text,
                'The fallback shown to a learner contains an internal identifier.'
            );
        }
    }

    public function test_whitespace_only_input_still_reaches_the_floor(): void {
        $this->resetAfterTest();
        $floor = conversation_manager::failed_turn_text('', '');
        $this->assertSame($floor, conversation_manager::failed_turn_text('   ', "\n\t"));
    }
}
