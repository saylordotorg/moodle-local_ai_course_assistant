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

use local_ai_course_assistant\external\get_history;

/**
 * Replayed history must never print an internal identifier to a learner.
 *
 * v7.2.4 fixed the write side (conversation_manager::failed_turn_text), but
 * rows written before it still hold "[no response:
 * core\exception\moodle_exception]" verbatim, and a tester saw three of them
 * come back from a dev conversation. No forward fix can rewrite those rows, so
 * the read side has to be safe on its own -- which also covers the failures
 * that never reach the writer at all (provider timeout, 5xx, exhausted
 * failover chain), any of which can leave the message column empty.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\conversation_manager::display_turn_text
 * @covers     \local_ai_course_assistant\external\get_history
 */
final class history_placeholder_render_test extends \advanced_testcase {

    /** @var string The exact text a pre-7.2.4 row holds on dev. */
    private const STORED_PLACEHOLDER = '[no response: core\\exception\\moodle_exception]';

    /**
     * Enrolled student plus their conversation, the shape every test needs.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} Course, user, conversation.
     */
    private function seeded_conversation(): array {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);
        return [$course, $user, $conv];
    }

    /**
     * Fetch one message out of the external function's return value.
     *
     * @param int $courseid Course to fetch history for.
     * @param int $messageid Row id returned by conversation_manager::add_message.
     * @return array The matching entry from the returned messages list.
     */
    private function returned_message(int $courseid, int $messageid): array {
        $result = get_history::execute($courseid);
        // Run the real return-value cleaner so the assertion covers what the
        // browser actually receives, not just the handler's array.
        $clean = \core_external\external_api::clean_returnvalue(
            get_history::execute_returns(),
            $result
        );
        foreach ($clean['messages'] as $entry) {
            if ((int) $entry['id'] === $messageid) {
                return $entry;
            }
        }
        $this->fail('Message ' . $messageid . ' was not returned by get_history.');
    }

    /**
     * The reported bug: a stored exception class must not reach the learner.
     *
     * @return void
     */
    public function test_a_stored_exception_class_never_reaches_the_learner(): void {
        $this->resetAfterTest();
        [$course, $user, $conv] = $this->seeded_conversation();

        $id = conversation_manager::add_message(
            $conv->id,
            $user->id,
            $course->id,
            'assistant',
            self::STORED_PLACEHOLDER
        );

        $entry = $this->returned_message((int) $course->id, $id);

        $this->assertSame(branding::str('chat:turn_failed'), $entry['message']);
        foreach (['core\\', 'exception', 'moodle_', 'no response', 'Throwable', '::'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase(
                $leak,
                $entry['message'],
                'History replayed an internal identifier to the learner.'
            );
        }
    }

    /**
     * An empty reply row is the same class of failure and gets the same notice.
     *
     * This is the timeout / 5xx / exhausted-failover path: nothing was written
     * by the v7.2.4 writer at all, so only the renderer can catch it.
     *
     * @return void
     */
    public function test_an_empty_reply_row_renders_the_notice(): void {
        $this->resetAfterTest();
        [$course, $user, $conv] = $this->seeded_conversation();

        $id = conversation_manager::add_message($conv->id, $user->id, $course->id, 'assistant', '   ');

        $entry = $this->returned_message((int) $course->id, $id);

        $this->assertSame(branding::str('chat:turn_failed'), $entry['message']);
    }

    /**
     * A real reply must survive untouched, whitespace and markdown included.
     *
     * @return void
     */
    public function test_a_real_reply_is_returned_verbatim(): void {
        $this->resetAfterTest();
        [$course, $user, $conv] = $this->seeded_conversation();

        $reply = "The four functions are planning, organizing, leading and controlling.\n\n- Planning sets the goal.";
        $id = conversation_manager::add_message($conv->id, $user->id, $course->id, 'assistant', $reply);

        $entry = $this->returned_message((int) $course->id, $id);

        $this->assertSame($reply, $entry['message']);
    }

    /**
     * The learner's own words come back as typed, class names and all.
     *
     * The laundering is assistant-only on purpose: a learner asking "what is
     * core\exception\moodle_exception?" must see their question, not a notice.
     *
     * @return void
     */
    public function test_a_learner_question_about_an_exception_is_not_rewritten(): void {
        $this->resetAfterTest();
        [$course, $user, $conv] = $this->seeded_conversation();

        $id = conversation_manager::add_message(
            $conv->id,
            $user->id,
            $course->id,
            'user',
            'core\\exception\\moodle_exception'
        );

        $entry = $this->returned_message((int) $course->id, $id);

        $this->assertSame('core\\exception\\moodle_exception', $entry['message']);
    }

    /**
     * A reply that merely explains a class name is prose, not a placeholder.
     *
     * @return void
     */
    public function test_a_reply_that_mentions_a_class_name_is_kept(): void {
        $this->resetAfterTest();
        $reply = 'In Moodle, core\\exception\\moodle_exception is the base class you catch.';
        $this->assertSame($reply, conversation_manager::display_turn_text($reply));
    }

    /**
     * Every shape of internal identifier we have seen or can foresee.
     *
     * @return void
     */
    public function test_bare_and_bracketed_identifiers_all_hit_the_notice(): void {
        $this->resetAfterTest();
        $notice = branding::str('chat:turn_failed');

        $shapes = [
            self::STORED_PLACEHOLDER,
            '[no response]',
            '[no response: TypeError]',
            'core\\exception\\moodle_exception',
            'dml_write_exception',
            'TypeError',
            '',
            "\n\t ",
        ];
        foreach ($shapes as $stored) {
            $this->assertSame(
                $notice,
                conversation_manager::display_turn_text($stored),
                'Stored value "' . $stored . '" was shown to the learner as-is.'
            );
        }
    }

    /**
     * The model never sees a stored internal identifier either.
     *
     * A learner-facing scrub alone would still feed the placeholder back as
     * prior assistant output, and an example of the assistant emitting an
     * exception class is the last thing the model should have in context.
     */
    public function test_api_history_is_scrubbed_before_it_reaches_the_model(): void {
        global $DB;
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $conv = conversation_manager::get_or_create_conversation((int) $user->id, (int) $course->id);

        conversation_manager::add_message(
            (int) $conv->id,
            (int) $user->id,
            (int) $course->id,
            'user',
            'What is a balance sheet?'
        );
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid' => $conv->id,
            'userid' => $user->id,
            'courseid' => $course->id,
            'role' => 'assistant',
            'message' => '[no response: core\\exception\\moodle_exception]',
            'timecreated' => time(),
        ]);

        $history = conversation_manager::get_history_for_api((int) $conv->id);
        $blob = json_encode($history);

        $this->assertStringNotContainsString('no response:', $blob);
        $this->assertStringNotContainsString('moodle_exception', $blob);
        $this->assertStringNotContainsString('core\\exception', $blob);

        // The learner's own question is untouched -- only assistant rows are
        // rewritten, so a learner asking about an exception keeps their words.
        $this->assertStringContainsString('balance sheet', $blob);
    }

    /**
     * Real prose that merely contains "error" is never rewritten.
     *
     * The first version of the matcher tested for the substrings exception,
     * throwable and error anywhere in the text. "error" is a substring of
     * "terrorism", so a one-word reply of "Terrorism." was replaced with the
     * failure notice -- and because get_history_for_api() applies the same
     * scrub, the model was then told it had failed to answer when it had
     * answered correctly. Deleting a real answer is much worse than leaving an
     * ugly one, so these cases are the ones that matter.
     *
     * @dataProvider prose_provider
     * @param string $text An assistant reply that must survive untouched.
     */
    public function test_legitimate_prose_survives(string $text): void {
        $this->assertSame($text, conversation_manager::display_turn_text($text));
    }

    /**
     * Assistant replies that must not be treated as internal identifiers.
     *
     * @return array
     */
    public static function prose_provider(): array {
        return [
            'one-word topic containing "error"' => ['Terrorism.'],
            'same without the full stop' => ['Terrorism'],
            'shorter, still prose' => ['Terror'],
            'bare link whose path contains Error' => ['https://en.wikipedia.org/wiki/Error_analysis'],
            'bracketed prose containing error' => ['[Terrorism in the 20th century]'],
            'sentence about error analysis' => ['Error analysis is the study of mistakes.'],
            'sentence using the word exception' => ['An exception to that rule is depreciation.'],
            'the bare word' => ['error'],
        ];
    }

    /**
     * Moodle's own lowercase class names are still caught.
     *
     * Tightening the matcher must not swing so far that it stops catching the
     * thing it was written for: core uses snake_case exception names, so a
     * suffix rule keyed only on CamelCase would have missed every one of them.
     *
     * @dataProvider typename_provider
     * @param string $text A stored internal identifier that must be replaced.
     */
    public function test_type_names_are_still_caught(string $text): void {
        $this->assertNotSame($text, conversation_manager::display_turn_text($text));
    }

    /**
     * Stored values that are internal identifiers.
     *
     * @return array
     */
    public static function typename_provider(): array {
        return [
            'the reported v7.1.1 form' => ['[no response: core\\exception\\moodle_exception]'],
            'its bare cousin' => ['[no response]'],
            'namespaced, unbracketed' => ['core\\exception\\moodle_exception'],
            'leading separator' => ['\\core\\exception\\moodle_exception'],
            'moodle snake_case' => ['moodle_exception'],
            'moodle snake_case, bracketed' => ['[moodle_exception]'],
            'php CamelCase' => ['TypeError'],
            'spl CamelCase' => ['RuntimeException'],
        ];
    }
}
