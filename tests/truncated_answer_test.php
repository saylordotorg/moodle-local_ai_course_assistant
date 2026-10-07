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

use local_ai_course_assistant\external\send_message;
use local_ai_course_assistant\provider\base_provider;
use local_ai_course_assistant\provider\claude_provider;
use local_ai_course_assistant\provider\failover_chain;
use local_ai_course_assistant\provider\gemini_provider;
use local_ai_course_assistant\provider\stub_provider;

/**
 * v7.7.6: an answer cut off by the output-token limit is detected and said so.
 *
 * Nothing read the finish reason, so a reply that ran out of tokens
 * mid-sentence was stored with stream_outcome 'complete', shown to the learner
 * as a finished answer, and lost its trailing [SOLA_NEXT] chips. Pinned here:
 * the finish reason is captured on the streaming and non-streaming
 * OpenAI-compatible paths and on Claude's stream, it is reset between calls,
 * the failover chain reports the serving member's, and both chat transports
 * record 'truncated'. sse.php cannot be run from PHPUnit (see
 * chat_learner_journey_test), so its wiring is checked as text and its
 * decision is the tested conversation_manager::turn_outcome().
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\openai_compatible_provider::chat_completion_stream
 * @covers     \local_ai_course_assistant\provider\openai_compatible_provider::chat_completion
 * @covers     \local_ai_course_assistant\provider\claude_provider::chat_completion_stream
 * @covers     \local_ai_course_assistant\provider\base_provider::is_truncation
 * @covers     \local_ai_course_assistant\conversation_manager::turn_outcome
 */
final class truncated_answer_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * A Gemini provider whose transport replays canned bytes instead of calling Google.
     *
     * @param string $stream SSE bytes for chat_completion_stream().
     * @param string $json Response body for chat_completion().
     * @return gemini_provider
     */
    private function canned_gemini(string $stream, string $json = ''): gemini_provider {
        return new class(['apikey' => 'x', 'model' => 'gemini-2.5-flash'], $stream, $json) extends gemini_provider {
            /** @var string */
            public string $stream;
            /** @var string */
            public string $json;

            /**
             * Constructor.
             *
             * @param array $overrides
             * @param string $stream
             * @param string $json
             */
            public function __construct(array $overrides, string $stream, string $json) {
                parent::__construct($overrides);
                $this->stream = $stream;
                $this->json = $json;
            }

            protected function http_post_stream(string $url, array $headers, string $body, callable $writecallback): void {
                // Split mid-line on purpose: the parser must reassemble chunks.
                foreach (str_split($this->stream, 37) as $piece) {
                    $writecallback($piece);
                }
            }

            protected function http_post(string $url, array $headers, string $body): string {
                return $this->json;
            }
        };
    }

    /**
     * SSE bytes the way Google's endpoint sends a Gemini answer.
     *
     * @param string $finish finish_reason on the last content chunk.
     * @param array $usage The trailing usage object.
     * @return string
     */
    private static function gemini_stream(string $finish, array $usage): string {
        $events = [
            ['choices' => [['delta' => ['content' => 'Ivy Lee wrote the 1906 '], 'index' => 0]]],
            ['choices' => [['delta' => ['content' => 'Declaration of'], 'finish_reason' => $finish, 'index' => 0]]],
            ['choices' => [], 'usage' => $usage, 'model' => 'gemini-2.5-flash'],
        ];
        $out = '';
        foreach ($events as $e) {
            $out .= 'data: ' . json_encode($e) . "\n\n";
        }
        return $out . "data: [DONE]\n\n";
    }

    /**
     * The live failure: 'length' on the stream is captured and reads as truncated.
     */
    public function test_a_stream_that_ran_out_of_tokens_reports_length(): void {
        // Numbers from the live reproduction: 979 thinking, 41 answer.
        $p = $this->canned_gemini(self::gemini_stream('length', [
            'prompt_tokens' => 3716, 'completion_tokens' => 41, 'total_tokens' => 3716 + 41 + 979,
        ]));
        $text = '';
        $p->chat_completion_stream('s', [['role' => 'user', 'content' => 'q']], function ($c) use (&$text) {
            $text .= $c;
        }, ['max_tokens' => 1024]);

        $this->assertSame('Ivy Lee wrote the 1906 Declaration of', $text);
        $this->assertSame('length', $p->get_last_finish_reason(), 'The finish reason was dropped.');
        $this->assertTrue(base_provider::is_truncation($p->get_last_finish_reason()));
        $this->assertSame(979, $p->get_last_token_usage()['reasoning_tokens'], 'Thinking went unlogged.');
    }

    /**
     * A finished answer is not reported as truncated, and a reused instance never reports the previous reason.
     */
    public function test_a_finished_stream_is_not_truncation_and_the_reason_resets(): void {
        $p = $this->canned_gemini(self::gemini_stream('stop', [
            'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15,
        ]));
        $p->chat_completion_stream('s', [], function () {
        });
        $this->assertSame('stop', $p->get_last_finish_reason());
        $this->assertFalse(base_provider::is_truncation('stop'));

        // Same instance, a stream that reports no finish reason at all.
        $p->stream = "data: " . json_encode(['choices' => [['delta' => ['content' => 'x']]]]) . "\n\ndata: [DONE]\n\n";
        $p->chat_completion_stream('s', [], function () {
        });
        $this->assertNull($p->get_last_finish_reason(), 'The previous call\'s finish reason leaked into this one.');
    }

    /**
     * The non-streaming path (classifier, profile, quiz generation) captures it too.
     */
    public function test_the_non_streaming_path_captures_the_finish_reason(): void {
        $p = $this->canned_gemini('', json_encode([
            'model' => 'gemini-2.5-flash',
            'choices' => [['message' => ['content' => '{"lab'], 'finish_reason' => 'length']],
            'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 6, 'total_tokens' => 506],
        ]));
        $p->chat_completion('s', [['role' => 'user', 'content' => 'q']], ['max_tokens' => 200]);

        $this->assertSame('length', $p->get_last_finish_reason());
        $this->assertSame(200, $p->get_last_token_usage()['reasoning_tokens']);
    }

    /**
     * Claude streams the same fact as message_delta.stop_reason 'max_tokens'.
     */
    public function test_claude_stream_reports_max_tokens(): void {
        $events = [
            ['type' => 'message_start', 'message' => ['model' => 'claude-sonnet-5', 'usage' => ['input_tokens' => 50]]],
            ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Half an ans']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'max_tokens'], 'usage' => ['output_tokens' => 1024]],
            ['type' => 'message_stop'],
        ];
        $bytes = '';
        foreach ($events as $e) {
            $bytes .= 'event: ' . $e['type'] . "\ndata: " . json_encode($e) . "\n\n";
        }
        $p = new class(['apikey' => 'x', 'model' => 'claude-sonnet-5'], $bytes) extends claude_provider {
            /** @var string */
            private string $bytes;

            /**
             * Constructor.
             *
             * @param array $overrides
             * @param string $bytes
             */
            public function __construct(array $overrides, string $bytes) {
                parent::__construct($overrides);
                $this->bytes = $bytes;
            }

            protected function http_post_stream(string $url, array $headers, string $body, callable $writecallback): void {
                $writecallback($this->bytes);
            }
        };
        $p->chat_completion_stream('s', [['role' => 'user', 'content' => 'q']], function () {
        });

        $this->assertSame('max_tokens', $p->get_last_finish_reason());
        $this->assertTrue(base_provider::is_truncation($p->get_last_finish_reason()));
        $this->assertFalse(base_provider::is_truncation('end_turn'));
        $this->assertFalse(base_provider::is_truncation(null), 'Unknown must not read as truncated.');
    }

    /**
     * The failover chain reports the finish reason of the member that served the call.
     */
    public function test_the_failover_chain_reports_the_serving_providers_reason(): void {
        $served = $this->canned_gemini(self::gemini_stream('length', [
            'prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2,
        ]));
        $chain = new failover_chain($served, 'gemini', []);
        $chain->chat_completion_stream('s', [], function () {
        });
        $this->assertSame('length', $chain->get_last_finish_reason());
    }

    /**
     * The outcome both chat transports store.
     */
    public function test_turn_outcome(): void {
        $this->assertSame('truncated', conversation_manager::turn_outcome(false, 'length'));
        $this->assertSame('truncated', conversation_manager::turn_outcome(false, 'max_tokens'));
        $this->assertSame('complete', conversation_manager::turn_outcome(false, 'stop'));
        $this->assertSame('complete', conversation_manager::turn_outcome(false, null));
        // The learner left before the end; that is what happened to them, whatever the provider said.
        $this->assertSame('client_aborted', conversation_manager::turn_outcome(true, 'length'));
    }

    /**
     * End to end through the web-service chat turn: a truncated reply is stored as 'truncated'.
     */
    public function test_a_truncated_reply_is_stored_as_truncated(): void {
        global $DB;
        stub_provider::reset();
        set_config('provider', 'stub', 'local_ai_course_assistant');
        set_config('apikey', 'stub-key', 'local_ai_course_assistant');
        set_config('rag_enabled', 0, 'local_ai_course_assistant');
        set_config('history_mode', 'recency', 'local_ai_course_assistant');

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id, 'student');
        $this->setUser($learner);

        stub_provider::$finish_reason = 'length';
        $this->assertTrue(send_message::execute((int) $course->id, 'Explain the whole history of PR.', 0)['success']);
        stub_provider::$finish_reason = 'stop';
        $this->assertTrue(send_message::execute((int) $course->id, 'And briefly?', 0)['success']);

        $outcomes = $DB->get_fieldset_sql(
            "SELECT stream_outcome FROM {local_ai_course_assistant_msgs}
              WHERE userid = :u AND role = 'assistant' ORDER BY id",
            ['u' => $learner->id]
        );
        $this->assertSame(['truncated', 'complete'], $outcomes);
    }

    /**
     * sse.php stores the decided outcome and tells the learner.
     *
     * Read as text because sse.php cannot run under PHPUnit.
     */
    public function test_sse_records_the_outcome_and_sends_the_note(): void {
        global $CFG;
        $src = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/sse.php');

        $this->assertMatchesRegularExpression(
            '/\$streamoutcome = conversation_manager::turn_outcome\(\(bool\) connection_aborted\(\), '
                . '\$provider->get_last_finish_reason\(\)\);/',
            $src,
            'sse.php no longer derives the stored outcome from the finish reason.'
        );
        // The outcome is the argument after cached_tokens in the assistant add_message call.
        $this->assertMatchesRegularExpression(
            '/\$cachedtokens !== null \? \(int\) \$cachedtokens : null,\s*\$streamoutcome,/',
            $src,
            'sse.php stores a hard-coded outcome instead of the decided one.'
        );
        $this->assertMatchesRegularExpression(
            "/\\\$doneevent\['truncated'\] = true;\s*\\\$doneevent\['truncatednote'\] = get_string\('chat:truncated'/",
            $src,
            'The learner is not told the answer was cut short.'
        );
    }
}
