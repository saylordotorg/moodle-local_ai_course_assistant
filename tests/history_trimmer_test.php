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
 * The history trim used for the one retry after a provider rejects a request (v7.8.8).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\history_trimmer
 */
final class history_trimmer_test extends \advanced_testcase {
    /**
     * Build alternating user/assistant turns ending on a user turn.
     *
     * @param int $pairs Number of finished exchanges before the final question.
     * @return array
     */
    private static function conversation(int $pairs): array {
        $messages = [];
        for ($i = 1; $i <= $pairs; $i++) {
            $messages[] = ['role' => 'user', 'content' => "question $i"];
            $messages[] = ['role' => 'assistant', 'content' => "answer $i"];
        }
        $messages[] = ['role' => 'user', 'content' => 'the current question'];
        return $messages;
    }

    public function test_it_keeps_the_newest_turns_and_starts_on_a_user_turn(): void {
        $trimmed = history_trimmer::trim(self::conversation(8));
        $this->assertSame(['assistant', 'user', 'assistant', 'user'], array_column(array_slice(self::conversation(8), -4), 'role'));
        // The last four would start on an assistant turn, so that one is dropped.
        $this->assertSame(['user', 'assistant', 'user'], array_column($trimmed, 'role'));
        $this->assertSame('the current question', end($trimmed)['content']);
    }

    public function test_the_final_turn_is_never_dropped(): void {
        foreach ([1, 2, 5, 20] as $pairs) {
            $trimmed = history_trimmer::trim(self::conversation($pairs), 2);
            $this->assertNotEmpty($trimmed);
            $this->assertSame('the current question', end($trimmed)['content']);
            $this->assertSame('user', $trimmed[0]['role']);
        }
    }

    public function test_empty_turns_are_removed_and_same_role_turns_joined(): void {
        $messages = [
            ['role' => 'user', 'content' => 'first'],
            ['role' => 'assistant', 'content' => "   \n"],
            ['role' => 'user', 'content' => 'second'],
            ['role' => 'assistant', 'content' => 'reply'],
            ['role' => 'user', 'content' => ''],
            ['role' => 'user', 'content' => 'third'],
        ];
        $trimmed = history_trimmer::trim($messages, 10);
        $this->assertSame(
            [
                ['role' => 'user', 'content' => "first\n\nsecond"],
                ['role' => 'assistant', 'content' => 'reply'],
                ['role' => 'user', 'content' => 'third'],
            ],
            $trimmed
        );
    }

    public function test_anything_that_is_not_a_user_or_assistant_turn_is_ignored(): void {
        $trimmed = history_trimmer::trim([
            ['role' => 'system', 'content' => 'internal'],
            'not a message',
            ['role' => 'user', 'content' => 'hello'],
        ], 4);
        $this->assertSame([['role' => 'user', 'content' => 'hello']], $trimmed);
    }

    public function test_structured_content_is_kept_as_it_is(): void {
        $blocks = [['type' => 'text', 'text' => 'look'], ['type' => 'image', 'source' => ['data' => 'x']]];
        $trimmed = history_trimmer::trim([
            ['role' => 'user', 'content' => 'earlier'],
            ['role' => 'assistant', 'content' => 'ok'],
            ['role' => 'user', 'content' => $blocks],
        ], 2);
        $this->assertSame([['role' => 'user', 'content' => $blocks]], $trimmed);
    }

    public function test_nothing_usable_gives_an_empty_history(): void {
        $this->assertSame([], history_trimmer::trim([]));
        $this->assertSame([], history_trimmer::trim([['role' => 'assistant', 'content' => 'orphan']]));
    }

    public function test_a_retry_is_offered_only_when_it_would_send_less(): void {
        $this->assertSame([], history_trimmer::retry_candidate(self::conversation(1)), 'three turns: nothing to cut');
        $this->assertSame([], history_trimmer::retry_candidate([]));
        $long = self::conversation(12);
        $candidate = history_trimmer::retry_candidate($long);
        $this->assertNotEmpty($candidate);
        $this->assertLessThan(count($long), count($candidate));
    }

    public function test_a_long_history_that_is_already_clean_still_shrinks(): void {
        $candidate = history_trimmer::retry_candidate(self::conversation(30));
        $this->assertLessThanOrEqual(history_trimmer::KEEP_MESSAGES, count($candidate));
    }
}
