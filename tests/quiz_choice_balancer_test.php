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
 * Quiz answer-key balancing (v7.8.2).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\quiz_choice_balancer
 */
final class quiz_choice_balancer_test extends \basic_testcase {
    /**
     * A clean question whose key is A.
     *
     * @param string $explanation
     * @return array
     */
    private function q(string $explanation = 'Because it is right.'): array {
        return [
            'id' => 1, 'question' => 'Which?',
            'choices' => ['A) alpha text', 'B) bravo text', 'C) charlie tx', 'D) delta text'],
            'correct' => 'A', 'explanation' => $explanation,
        ];
    }

    public function test_strip_label_forms(): void {
        $this->assertSame('text', quiz_choice_balancer::strip_label('A) text'));
        $this->assertSame('text', quiz_choice_balancer::strip_label('(b) text'));
        $this->assertSame('text', quiz_choice_balancer::strip_label('C. text'));
        $this->assertSame('Apple pie', quiz_choice_balancer::strip_label('Apple pie'));
    }

    public function test_shuffle_keeps_the_key_pointing_at_the_same_text(): void {
        for ($run = 0; $run < 50; $run++) {
            $out = quiz_choice_balancer::shuffle($this->q());
            $pos = strpos('ABCD', $out['correct']);
            $this->assertSame($out['correct'] . ') alpha text', $out['choices'][$pos]);
            $this->assertCount(4, $out['choices']);
            foreach ($out['choices'] as $i => $c) {
                $this->assertStringStartsWith('ABCD'[$i] . ') ', $c);
            }
        }
    }

    public function test_shuffle_reaches_every_letter(): void {
        $seen = [];
        for ($run = 0; $run < 300; $run++) {
            $seen[quiz_choice_balancer::shuffle($this->q())['correct']] = true;
        }
        $this->assertCount(4, $seen);
    }

    public function test_shuffle_is_deterministic_with_an_injected_source(): void {
        // A source that always returns 0 gives the order B, C, D, A.
        $out = quiz_choice_balancer::shuffle($this->q(), static fn(int $max): int => 0);
        $this->assertSame('D', $out['correct']);
        $this->assertSame('D) alpha text', $out['choices'][3]);
        $this->assertSame('A) bravo text', $out['choices'][0]);
    }

    public function test_explanation_letters_follow_the_choices(): void {
        $out = quiz_choice_balancer::shuffle(
            $this->q('Option A is right, (B) is a trap, and C is incorrect. A company is not an answer a student gives.'),
            static fn(int $max): int => 0
        );
        // That order maps A to D, B to A, C to B and D to C.
        $this->assertStringContainsString('Option D is right', $out['explanation']);
        $this->assertStringContainsString('(A) is a trap', $out['explanation']);
        $this->assertStringContainsString('B is incorrect', $out['explanation']);
        $this->assertStringContainsString('A company', $out['explanation']);
        $this->assertStringContainsString('answer a student', $out['explanation']);
    }

    public function test_a_letter_is_never_remapped_twice(): void {
        $text = quiz_choice_balancer::remap_letters('Option A is correct while option B is wrong.', ['A' => 'B', 'B' => 'A']);
        $this->assertSame('Option B is correct while option A is wrong.', $text);
        $this->assertSame('B is correct', quiz_choice_balancer::remap_letters('A is correct', ['A' => 'B']));
    }

    public function test_unclean_questions_are_left_alone(): void {
        $q = $this->q();
        $q['correct'] = 'E';
        $this->assertSame($q, quiz_choice_balancer::shuffle($q));
        $q = $this->q();
        $q['choices'][] = 'E) extra';
        $this->assertSame($q, quiz_choice_balancer::shuffle($q));
    }

    public function test_two_choice_questions_shuffle_within_their_size(): void {
        $q = ['choices' => ['A) true', 'B) false'], 'correct' => 'A', 'explanation' => ''];
        $out = quiz_choice_balancer::shuffle($q);
        $this->assertCount(2, $out['choices']);
        $this->assertSame($out['correct'] . ') true', $out['choices'][strpos('AB', $out['correct'])]);
    }

    public function test_flags_a_key_that_stands_out_by_length(): void {
        $long = [
            'choices' => ['A) a much longer and more specific correct answer', 'B) short one', 'C) short two', 'D) short 3'],
            'correct' => 'A',
        ];
        $this->assertSame([1], quiz_choice_balancer::flag_long_keys([0 => $this->q(), 1 => $long]));
    }

    public function test_a_longest_key_by_a_hair_is_not_flagged(): void {
        $q = ['choices' => ['A) alpha textx', 'B) bravo text', 'C) charlie tx', 'D) delta text'], 'correct' => 'A'];
        $this->assertSame([], quiz_choice_balancer::flag_long_keys([$q]));
    }

    public function test_short_keys_are_not_flagged(): void {
        $q = [
            'choices' => ['A) x', 'B) a much longer distractor here', 'C) another long distractor', 'D) one more long one'],
            'correct' => 'A',
        ];
        $this->assertSame([], quiz_choice_balancer::flag_long_keys([$q]));
    }

    public function test_distractor_acceptance_rules(): void {
        $key = 'a correct answer of some length';
        $good = ['another answer of some size', 'one more answer of length', 'a third plausible choice here'];
        $this->assertTrue(quiz_choice_balancer::distractors_acceptable($key, $good));
        $this->assertFalse(quiz_choice_balancer::distractors_acceptable($key, ['short', 'tiny', 'small']));
        $this->assertFalse(quiz_choice_balancer::distractors_acceptable($key, [$key, $good[1], $good[2]]));
        $this->assertFalse(quiz_choice_balancer::distractors_acceptable(
            $key,
            ['dup answer of some size here', 'DUP answer of some  size here', 'x']
        ));
        $this->assertFalse(quiz_choice_balancer::distractors_acceptable($key, ['', 'a', 'b']));
    }

    public function test_with_distractors_keeps_the_key_in_place(): void {
        $q = $this->q();
        $q['correct'] = 'C';
        $out = quiz_choice_balancer::with_distractors($q, ['one', 'two', 'three']);
        $this->assertSame(['A) one', 'B) two', 'C) charlie tx', 'D) three'], $out['choices']);
        $this->assertSame('C', $out['correct']);
    }
}
