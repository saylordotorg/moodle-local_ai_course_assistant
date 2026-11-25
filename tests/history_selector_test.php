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
 * Unit tests for history_selector pure selection logic (v6.2.0).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\history_selector
 */
final class history_selector_test extends \advanced_testcase {
    /**
     * Build a scored row whose pair is identifiable by a label.
     *
     * @param string $label Identifier used for the user/assistant content.
     * @param float $score Relevance score for the row.
     * @return array Scored row shaped like the selector input.
     */
    private function row(string $label, float $score): array {
        return [
            'pair' => [
                'user' => ['role' => 'user', 'content' => $label . '-q'],
                'assistant' => ['role' => 'assistant', 'content' => $label . '-a'],
            ],
            'score' => $score,
        ];
    }

    /**
     * Extract the user-content labels (minus the -q suffix) from flattened output.
     *
     * @param array $pairs Flattened API-shaped messages.
     * @return array Labels in order.
     */
    private function labels(array $pairs): array {
        $out = [];
        foreach ($pairs as $p) {
            $out[] = str_replace('-q', '', $p['user']['content']);
        }
        return $out;
    }

    public function test_keeps_all_when_relevant_and_within_cap(): void {
        $scored = [$this->row('a', 0.6), $this->row('b', 0.5), $this->row('c', 0.7)];
        $kept = history_selector::pick($scored, 20, 0.20);
        $this->assertSame(['a', 'b', 'c'], $this->labels($kept));
    }

    public function test_drops_irrelevant_middle_turn(): void {
        // 'b' is below the floor and is not the most recent → dropped.
        $scored = [$this->row('a', 0.6), $this->row('b', 0.05), $this->row('c', 0.7)];
        $kept = history_selector::pick($scored, 20, 0.20);
        $this->assertSame(['a', 'c'], $this->labels($kept));
    }

    public function test_always_keeps_most_recent_even_if_below_floor(): void {
        // 'c' (latest) scores below the floor but must be kept (immediate
        // follow-up like "explain that more").
        $scored = [$this->row('a', 0.6), $this->row('b', 0.5), $this->row('c', 0.01)];
        $kept = history_selector::pick($scored, 20, 0.20);
        $this->assertSame(['a', 'b', 'c'], $this->labels($kept));
    }

    public function test_cap_drops_lowest_scoring_but_keeps_latest(): void {
        // Cap of 2: keep the single highest-scoring plus the always-kept latest.
        $scored = [$this->row('a', 0.9), $this->row('b', 0.4), $this->row('c', 0.3)];
        $kept = history_selector::pick($scored, 2, 0.20);
        // 'a' (0.9) is highest; 'c' is latest (forced). 'b' is evicted by cap.
        $this->assertSame(['a', 'c'], $this->labels($kept));
    }

    public function test_pair_and_flatten_roundtrip(): void {
        $messages = [
            (object) ['role' => 'user', 'message' => 'q1'],
            (object) ['role' => 'assistant', 'message' => 'a1'],
            (object) ['role' => 'user', 'message' => 'q2'],
            (object) ['role' => 'assistant', 'message' => 'a2'],
        ];
        $pairs = history_selector::pair_messages($messages);
        $this->assertCount(2, $pairs);
        $flat = history_selector::flatten($pairs);
        $this->assertSame(['q1', 'a1', 'q2', 'a2'], array_column($flat, 'content'));
    }

    public function test_pair_messages_handles_trailing_user_turn(): void {
        // A user turn with no assistant reply yet (the live message in flight).
        $messages = [
            (object) ['role' => 'user', 'message' => 'q1'],
            (object) ['role' => 'assistant', 'message' => 'a1'],
            (object) ['role' => 'user', 'message' => 'q2'],
        ];
        $pairs = history_selector::pair_messages($messages);
        $this->assertCount(2, $pairs);
        $this->assertNull($pairs[1]['assistant']);
        $flat = history_selector::flatten($pairs);
        $this->assertSame(['q1', 'a1', 'q2'], array_column($flat, 'content'));
    }

    public function test_a_correction_stored_between_a_question_and_its_answer_is_not_the_answer(): void {
        // The question is stored before streaming, the answer after it; a correction of the previous answer can land
        // between them (order q2, c, a2). q2 keeps a2 as its answer and the correction keeps its own entry and flag.
        $c = correction_notice::INTERACTION_TYPE;
        $messages = [
            (object) ['role' => 'user', 'message' => 'q1'],
            (object) ['role' => 'assistant', 'message' => 'a1'],
            (object) ['role' => 'user', 'message' => 'q2'],
            (object) ['role' => 'assistant', 'message' => 'c', 'interaction_type' => $c],
            (object) ['role' => 'assistant', 'message' => 'a2'],
        ];
        $pairs = history_selector::pair_messages($messages);
        $this->assertCount(3, $pairs);
        $this->assertSame(['q1', 'a1'], [$pairs[0]['user']['content'], $pairs[0]['assistant']['content']]);
        $this->assertNull($pairs[1]['user']);
        $this->assertSame('c', $pairs[1]['assistant']['content']);
        $this->assertTrue($pairs[1]['correction'] ?? false);
        $this->assertSame(['q2', 'a2'], [$pairs[2]['user']['content'], $pairs[2]['assistant']['content']]);
        $this->assertArrayNotHasKey('correction', $pairs[2]);
        $this->assertSame(['q1', 'a1', 'c', 'q2', 'a2'], array_column(history_selector::flatten($pairs), 'content'));
        // A correction after an unanswered question stays after it, as its own entry.
        $pairs = history_selector::pair_messages(array_slice($messages, 0, 4));
        $this->assertCount(3, $pairs);
        $this->assertNull($pairs[1]['assistant']);
        $this->assertTrue($pairs[2]['correction'] ?? false);
        // A correction in its usual place (after the answer it corrects) is unchanged.
        $pairs = history_selector::pair_messages([$messages[0], $messages[1], $messages[3]]);
        $this->assertCount(2, $pairs);
        $this->assertTrue($pairs[1]['correction'] ?? false);
    }
}
