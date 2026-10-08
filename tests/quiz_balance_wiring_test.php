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

use local_ai_course_assistant\external\generate_quiz;

/**
 * generate_quiz repairs long keys and shuffles (v7.8.2): the wiring around quiz_choice_balancer.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\generate_quiz
 */
final class quiz_balance_wiring_test extends \advanced_testcase {
    /**
     * A stand-in provider that answers the repair call.
     *
     * @param string|\Throwable $reply
     * @return object
     */
    private function fake($reply) {
        return new class($reply) {
            /** @var int Calls received. */
            public int $calls = 0;
            /** @var string|\Throwable */
            private $reply;

            public function __construct($reply) {
                $this->reply = $reply;
            }

            public function chat_completion(string $system, array $messages, array $options = []): string {
                $this->calls++;
                if ($this->reply instanceof \Throwable) {
                    throw $this->reply;
                }
                return $this->reply;
            }

            public function get_last_token_usage(): ?array {
                return ['model' => 'm', 'prompt_tokens' => 10, 'completion_tokens' => 5];
            }
        };
    }

    /**
     * Run the private balancing step.
     *
     * @param object $provider
     * @param array $questions
     * @return array
     */
    private function balance($provider, array $questions): array {
        $course = $this->getDataGenerator()->create_course();
        $m = new \ReflectionMethod(generate_quiz::class, 'balance_questions');
        $m->setAccessible(true);
        return $m->invoke(null, $provider, $questions, (int) $course->id, 'T', 0);
    }

    /**
     * One question whose key (A) is far longer than its distractors.
     *
     * @return array
     */
    private function longkey(): array {
        return [
            'id' => 1, 'question' => 'Q?', 'correct' => 'A', 'explanation' => 'Because.',
            'choices' => ['A) a much longer and more specific correct answer', 'B) short one', 'C) short two', 'D) short 3'],
        ];
    }

    public function test_repair_replaces_distractors_and_the_key_survives_the_shuffle(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $reply = json_encode(['items' => [['id' => 0, 'distractors' => [
            'a similar sized but plainly wrong claim made here', 'another plausible yet wrong answer of this size', 'yet one more wrong answer of equal length too',
        ]]]]);
        $provider = $this->fake($reply);
        $out = $this->balance($provider, [$this->longkey()]);
        $this->assertSame(1, $provider->calls);
        $q = $out[0];
        $key = $q['choices'][strpos('ABCD', $q['correct'])];
        $this->assertSame($q['correct'] . ') a much longer and more specific correct answer', $key);
        $this->assertSame([], quiz_choice_balancer::flag_long_keys([$q]));
    }

    public function test_rejected_replacements_keep_the_originals_but_still_shuffle(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $provider = $this->fake(json_encode(['items' => [['id' => 0, 'distractors' => ['no', 'no', 'no']]]]));
        $out = $this->balance($provider, [$this->longkey()]);
        $texts = array_map([quiz_choice_balancer::class, 'strip_label'], $out[0]['choices']);
        $this->assertContains('short one', $texts);
        $this->assertSame('a much longer and more specific correct answer', $texts[strpos('ABCD', $out[0]['correct'])]);
    }

    public function test_a_failing_repair_call_never_fails_the_quiz(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $out = $this->balance($this->fake(new \RuntimeException('boom')), [$this->longkey()]);
        $this->assertDebuggingCalled('generate_quiz: distractor repair skipped: boom');
        $this->assertCount(1, $out);
        $this->assertCount(4, $out[0]['choices']);
    }

    public function test_a_fenced_reply_with_words_around_it_is_still_read(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $json = json_encode(['items' => [['id' => 0, 'distractors' => [
            'a similar sized but plainly wrong claim made here', 'another plausible yet wrong answer of this size',
            'yet one more wrong answer of equal length too',
        ]]]]);
        $out = $this->balance($this->fake("Here you go:\n```json\n" . $json . "\n```"), [$this->longkey()]);
        $this->assertSame([], quiz_choice_balancer::flag_long_keys([$out[0]]));
    }

    public function test_the_repair_request_carries_the_json_shape_and_the_reason(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $seen = (object) ['system' => '', 'user' => ''];
        $provider = new class($seen) {
            /** @var object */
            private $seen;

            public function __construct($seen) {
                $this->seen = $seen;
            }

            public function chat_completion(string $system, array $messages, array $options = []): string {
                $this->seen->system = $system;
                $this->seen->user = $messages[0]['content'];
                return '{}';
            }

            public function get_last_token_usage(): ?array {
                return null;
            }
        };
        $this->balance($provider, [$this->longkey()]);
        $this->assertStringContainsString('{"items":[{"id":0,"distractors"', $seen->system);
        $this->assertStringContainsString('"why_correct":"Because."', $seen->user);
    }

    public function test_a_failed_repair_call_does_not_bill_the_previous_calls_usage_again(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $provider = new \local_ai_course_assistant\provider\openai_provider([
            'apikey' => 'k-test', 'model' => 'gpt-4o-mini', 'apibaseurl' => 'http://127.0.0.1:9/v1',
        ]);
        // A previous successful call left usage behind.
        $prop = new \ReflectionProperty($provider, 'last_token_usage');
        $prop->setAccessible(true);
        $prop->setValue($provider, ['model' => 'gpt-4o-mini', 'prompt_tokens' => 999, 'completion_tokens' => 999]);
        // The next call fails before any response: nothing listens on that port.
        try {
            $provider->chat_completion('s', [['role' => 'user', 'content' => 'u']], []);
        } catch (\Throwable $e) {
            unset($e);
        }
        $this->assertNull($provider->get_last_token_usage(), 'A failed call must not leave the previous usage behind.');
    }

    public function test_garbage_reply_is_ignored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $out = $this->balance($this->fake('not json'), [$this->longkey()]);
        $this->assertCount(4, $out[0]['choices']);
    }

    public function test_balanced_quiz_costs_no_extra_call(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $q = [
            'id' => 1, 'question' => 'Q?', 'correct' => 'A', 'explanation' => '',
            'choices' => ['A) alpha text', 'B) bravo text', 'C) charlie tx', 'D) delta text'],
        ];
        $provider = $this->fake('{}');
        $this->balance($provider, [$q]);
        $this->assertSame(0, $provider->calls);
    }

    public function test_repair_call_is_recorded_as_usage(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $before = $DB->count_records('local_ai_course_assistant_msgs');
        $this->balance($this->fake('{"items":[]}'), [$this->longkey()]);
        $this->assertSame($before + 1, $DB->count_records('local_ai_course_assistant_msgs'));
        $this->assertTrue($DB->record_exists_select(
            'local_ai_course_assistant_msgs',
            $DB->sql_like('message', ':m'),
            ['m' => '%distractor rewrite%']
        ));
    }
}
