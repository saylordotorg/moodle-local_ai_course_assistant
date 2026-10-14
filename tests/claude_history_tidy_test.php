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

namespace local_ai_course_assistant\provider;

/**
 * Claude's request repairs a malformed history instead of failing on it (v7.8.8).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\claude_provider
 */
final class claude_history_tidy_test extends \advanced_testcase {
    /**
     * The messages array Claude's request body would carry.
     *
     * @param array $history
     * @return array
     */
    private function sent(array $history): array {
        $this->resetAfterTest();
        $provider = new claude_provider(['apikey' => 'sk-ant-test-key', 'model' => 'claude-haiku-4-5']);
        $build = new \ReflectionMethod($provider, 'build_body');
        $build->setAccessible(true);
        return json_decode($build->invoke($provider, 'system prompt', $history, false, []), true)['messages'];
    }

    public function test_a_well_formed_history_is_sent_exactly_as_it_is(): void {
        $history = [
            ['role' => 'user', 'content' => 'one'],
            ['role' => 'assistant', 'content' => 'a'],
            ['role' => 'user', 'content' => 'two'],
        ];
        $this->assertSame($history, $this->sent($history));
    }

    public function test_an_empty_turn_a_repeated_role_and_a_leading_assistant_turn_are_repaired(): void {
        $sent = $this->sent([
            ['role' => 'assistant', 'content' => 'orphan'],
            ['role' => 'user', 'content' => 'one'],
            ['role' => 'assistant', 'content' => '   '],
            ['role' => 'user', 'content' => 'two'],
        ]);
        $this->assertSame([['role' => 'user', 'content' => "one\n\ntwo"]], $sent);
    }

    public function test_a_history_with_nothing_usable_in_it_is_sent_as_it_was(): void {
        $history = [['role' => 'assistant', 'content' => 'only an assistant turn']];
        $this->assertSame($history, $this->sent($history));
    }
}
