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
 * Tests for the v5.5.0 per-call failover chain decorator.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\failover_chain
 */
final class failover_chain_test extends \advanced_testcase {
    /**
     * Primary succeeds: fallback is never invoked, returned text comes from primary.
     */
    public function test_primary_success_short_circuits_chain(): void {
        $this->resetAfterTest();
        $primary = self::fake_provider('PRIMARY-OUT', false);
        $fallback = self::fake_provider('FALLBACK-OUT', false);
        $chain = new failover_chain($primary, 'primary-label', [
            ['provider' => $fallback, 'label' => 'fallback-label'],
        ]);
        $result = $chain->chat_completion('sys', [['role' => 'user', 'content' => 'hi']]);
        $this->assertEquals('PRIMARY-OUT', $result);
        $this->assertEquals(0, $fallback->callcount);
    }

    /**
     * Primary throws: fallback is invoked, returned text comes from fallback,
     * and an audit row is written.
     */
    public function test_primary_failure_falls_over_to_fallback_and_audits(): void {
        global $DB;
        $this->resetAfterTest();
        $primary = self::fake_provider('PRIMARY-OUT', true);
        $fallback = self::fake_provider('FALLBACK-OUT', false);
        $chain = new failover_chain($primary, 'primary-label', [
            ['provider' => $fallback, 'label' => 'fallback-label'],
        ], ['audit' => true, 'courseid' => 0, 'userid' => 0]);

        $beforecount = $DB->count_records(
            'local_ai_course_assistant_audit',
            ['action' => failover_chain::AUDIT_EVENT_FALLTHROUGH]
        );

        $result = $chain->chat_completion('sys', [['role' => 'user', 'content' => 'hi']]);

        $this->assertEquals('FALLBACK-OUT', $result);
        $this->assertEquals(1, $primary->callcount);
        $this->assertEquals(1, $fallback->callcount);

        $aftercount = $DB->count_records(
            'local_ai_course_assistant_audit',
            ['action' => failover_chain::AUDIT_EVENT_FALLTHROUGH]
        );
        $this->assertEquals(
            $beforecount + 1,
            $aftercount,
            'Expected exactly one failover_fallthrough audit row to be written.'
        );
    }

    /**
     * v7.8.0: a turn a fallback answered after the primary failed writes one
     * rescued row naming the primary's model; a turn the whole chain failed
     * writes none (the failed-turn row already counts it).
     */
    public function test_a_rescued_turn_is_audited_once_and_a_lost_one_not_at_all(): void {
        global $DB;
        $this->resetAfterTest();
        $named = function (provider_interface $inner, string $model) {
            return new class ($inner, $model) implements provider_interface {
                public function __construct(private provider_interface $inner, private string $model) {
                }
                public function model_id(): string {
                    return $this->model;
                }
                public function chat_completion(string $systemprompt, array $messages, array $options = []): string {
                    return $this->inner->chat_completion($systemprompt, $messages, $options);
                }
                public function chat_completion_stream(string $s, array $m, callable $cb, array $options = []): void {
                    $this->inner->chat_completion_stream($s, $m, $cb, $options);
                }
                public function get_last_token_usage(): ?array {
                    return null;
                }
                public function get_last_finish_reason(): ?string {
                    return null;
                }
            };
        };
        $chain = new failover_chain($named(self::failing_provider('404'), 'new-model'), 'rescue-primary', [
            ['provider' => self::fake_provider('FALLBACK-OUT', false), 'label' => 'rescue-fallback'],
        ], ['audit' => true, 'courseid' => 0, 'userid' => 0]);
        $chain->chat_completion_stream('sys', [['role' => 'user', 'content' => 'hi']], function () {
        });
        $rows = $DB->get_records('local_ai_course_assistant_audit', ['action' => failover_chain::AUDIT_EVENT_RESCUED]);
        $this->assertCount(1, $rows);
        $this->assertSame('new-model', json_decode(reset($rows)->details, true)['failed_model']);

        $lost = new failover_chain($named(self::failing_provider('404'), 'new-model'), 'lost-primary', [
            ['provider' => self::failing_provider('also down'), 'label' => 'lost-fallback'],
        ], ['audit' => true, 'courseid' => 0, 'userid' => 0]);
        try {
            $lost->chat_completion('sys', [['role' => 'user', 'content' => 'hi']]);
        } catch (\moodle_exception $e) {
            unset($e);
        }
        $rescued = ['action' => failover_chain::AUDIT_EVENT_RESCUED];
        $this->assertSame(1, $DB->count_records('local_ai_course_assistant_audit', $rescued));

        $fine = new failover_chain($named(self::fake_provider('PRIMARY-OUT', false), 'new-model'), 'fine-primary', [
            ['provider' => self::fake_provider('FALLBACK-OUT', false), 'label' => 'fine-fallback'],
        ], ['audit' => true, 'courseid' => 0, 'userid' => 0]);
        $this->assertSame('PRIMARY-OUT', $fine->chat_completion('sys', [['role' => 'user', 'content' => 'hi']]));
        $this->assertSame(
            1,
            $DB->count_records('local_ai_course_assistant_audit', $rescued),
            'A turn the primary answered is not a rescue.'
        );
    }

    /**
     * All entries fail: the FIRST exception propagates, with a trail naming
     * every attempt.
     *
     * Rethrowing the last error meant the surfaced message came from the end of
     * the chain: a gemini primary that failed was reported as an openai "model
     * not found", which points the administrator at a setting that is correct
     * and hides the provider that actually broke.
     */
    public function test_all_paths_fail_propagates_first_error_with_trail(): void {
        $this->resetAfterTest();
        $primary = self::failing_provider('primary boom');
        $fallback = self::failing_provider('fallback boom');
        $chain = new failover_chain($primary, 'primary-label', [
            ['provider' => $fallback, 'label' => 'fallback-label'],
        ]);
        try {
            $chain->chat_completion('sys', [['role' => 'user', 'content' => 'hi']]);
            $this->fail('expected the exhausted chain to throw');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('primary boom', (string) $e->debuginfo);
            $this->assertStringContainsString('primary-label', (string) $e->debuginfo);
            $this->assertStringContainsString('fallback-label', (string) $e->debuginfo);
            $this->assertStringContainsString('Failover chain exhausted', (string) $e->debuginfo);
        }
    }

    /**
     * Provider whose chat_completion always throws a distinguishable error.
     *
     * @param string $marker Text carried in the exception debuginfo.
     * @return object
     */
    private static function failing_provider(string $marker): object {
        return new class ($marker) implements provider_interface {
            public int $callcount = 0;
            public function __construct(private string $marker) {
            }
            public function chat_completion(string $systemprompt, array $messages, array $options = []): string {
                $this->callcount++;
                throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null, $this->marker);
            }
            public function chat_completion_stream(string $systemprompt, array $messages, callable $cb, array $options = []): void {
                $this->callcount++;
                throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null, $this->marker);
            }
            public function get_last_token_usage(): ?array {
                return null;
            }
            public function get_last_finish_reason(): ?string {
                return null;
            }
        };
    }

    /**
     * v7.8.8: a provider failing in a way that comes and goes (here a generic
     * error) opens its circuit on the SECOND failure in a row, not the first;
     * after that the failing provider is skipped.
     */
    public function test_circuit_open_skips_failing_primary_on_next_call(): void {
        $this->resetAfterTest();
        $primary = self::fake_provider('PRIMARY-OUT', true);
        $fallback = self::fake_provider('FALLBACK-OUT', false);
        $chain = new failover_chain($primary, 'circuit-test-primary', [
            ['provider' => $fallback, 'label' => 'circuit-test-fallback'],
        ]);
        // First failure is counted; the circuit stays closed.
        $chain->chat_completion('sys', [['role' => 'user', 'content' => 'hi']]);
        $this->assertEquals(1, $primary->callcount);
        // Second failure in a row opens it.
        $chain->chat_completion('sys', [['role' => 'user', 'content' => 'hi again']]);
        $this->assertEquals(2, $primary->callcount);
        // Third call skips the primary and goes straight to the fallback.
        $result = $chain->chat_completion('sys', [['role' => 'user', 'content' => 'and again']]);
        $this->assertEquals('FALLBACK-OUT', $result);
        $this->assertEquals(
            2,
            $primary->callcount,
            'Primary should NOT have been called a third time while its circuit is open.'
        );
        $this->assertEquals(3, $fallback->callcount);
    }

    /**
     * Streaming: primary emits first token, then errors mid-stream.
     * The error must propagate (no fall-through) so the user's partial
     * response stays coherent.
     */
    public function test_stream_post_first_token_error_does_not_fall_over(): void {
        $this->resetAfterTest();
        $primary = self::fake_streaming_provider(['hello'], true);
        $fallback = self::fake_streaming_provider(['fallback-text'], false);
        $chain = new failover_chain($primary, 'stream-primary', [
            ['provider' => $fallback, 'label' => 'stream-fallback'],
        ]);
        $captured = [];
        try {
            $chain->chat_completion_stream(
                'sys',
                [['role' => 'user', 'content' => 'hi']],
                function (string $chunk) use (&$captured) {
                    $captured[] = $chunk;
                }
            );
            $this->fail('Expected an exception to propagate after mid-stream failure.');
        } catch (\Throwable $e) {
            $this->assertEquals(
                ['hello'],
                $captured,
                'Expected the first chunk from the primary to have been delivered before the failure.'
            );
            $this->assertEquals(
                0,
                $fallback->callcount,
                'Fallback must NOT be invoked when streaming has already started.'
            );
        }
    }

    /**
     * A provider that refuses any request holding more than $maxmessages turns
     * with an HTTP rejection, as a strict API does for a history it dislikes.
     *
     * @param string $output Returned when the request is small enough.
     * @param int $maxmessages Most turns it accepts.
     * @param int $status HTTP status it rejects with.
     * @param int $slowretryus Microseconds a short (retry-sized) request takes before it answers or fails.
     * @param string $body Body of the rejection.
     * @return object Anonymous provider_interface with public $callcount and $sizes.
     */
    private static function picky_provider(
        string $output,
        int $maxmessages,
        int $status = 400,
        int $slowretryus = 0,
        string $body = 'invalid_request_error: messages are too many'
    ): object {
        return new class ($output, $maxmessages, $status, $slowretryus, $body) implements provider_interface {
            public int $callcount = 0;
            /** @var int[] Turns in each request received. */
            public array $sizes = [];
            public function __construct(
                private string $output,
                private int $max,
                private int $status,
                private int $slowretryus,
                private string $body
            ) {
            }
            public function model_id(): string {
                return 'picky-model';
            }
            private function check(array $messages): void {
                $this->callcount++;
                $this->sizes[] = count($messages);
                if ($this->slowretryus > 0 && count($messages) <= 4) {
                    usleep($this->slowretryus);
                }
                if (count($messages) > $this->max) {
                    throw new provider_http_exception($this->status, $this->body);
                }
            }
            public function chat_completion(string $systemprompt, array $messages, array $options = []): string {
                $this->check($messages);
                return $this->output;
            }
            public function chat_completion_stream(string $systemprompt, array $messages, callable $cb, array $options = []): void {
                $this->check($messages);
                $cb($this->output);
            }
            public function get_last_token_usage(): ?array {
                return null;
            }
            public function get_last_finish_reason(): ?string {
                return null;
            }
        };
    }

    /**
     * Alternating turns ending on a user turn.
     *
     * @param int $pairs Finished exchanges before the final question.
     * @return array
     */
    private static function long_history(int $pairs = 6): array {
        $messages = [];
        for ($i = 1; $i <= $pairs; $i++) {
            $messages[] = ['role' => 'user', 'content' => "question $i"];
            $messages[] = ['role' => 'assistant', 'content' => "answer $i"];
        }
        $messages[] = ['role' => 'user', 'content' => 'the current question'];
        return $messages;
    }

    /**
     * Rows of one audit action, details decoded.
     *
     * @param string $action
     * @return array[]
     */
    private static function audit_details(string $action): array {
        global $DB;
        return array_values(array_map(
            fn($row) => json_decode($row->details, true),
            $DB->get_records('local_ai_course_assistant_audit', ['action' => $action], 'id ASC')
        ));
    }

    /**
     * v7.8.8: a provider that rejects a long history with a 400 is asked once
     * more with a short one. When that works the provider answered, no fallback
     * runs, and its circuit stays closed, so the next long conversation is not
     * diverted for fifteen minutes because of this one.
     */
    public function test_a_long_history_refused_with_a_400_is_retried_short_and_the_circuit_stays_closed(): void {
        $this->resetAfterTest();
        $primary = self::picky_provider('PRIMARY-OUT', 4);
        $fallback = self::fake_provider('FALLBACK-OUT', false);
        $chain = new failover_chain($primary, 'trim-primary', [
            ['provider' => $fallback, 'label' => 'trim-fallback'],
        ], ['audit' => true, 'courseid' => 0, 'userid' => 0]);

        $history = self::long_history(6);
        $this->assertSame('PRIMARY-OUT', $chain->chat_completion('sys', $history));
        $this->assertSame([count($history), 3], $primary->sizes);
        $this->assertSame(0, $fallback->callcount);

        $rows = self::audit_details(failover_chain::AUDIT_EVENT_TRIMMED);
        $this->assertCount(1, $rows);
        $this->assertSame('rescued', $rows[0]['outcome']);
        $this->assertSame('trim-primary', $rows[0]['label']);
        $this->assertSame(count($history), $rows[0]['messages_before']);
        $this->assertSame(3, $rows[0]['messages_kept']);
        $this->assertSame('picky-model', $rows[0]['failed_model'], 'the row names the model that needed the shorter history');
        $this->assertStringContainsString('HTTP 400', $rows[0]['detail']);
        $this->assertSame([], self::audit_details(failover_chain::AUDIT_EVENT_CIRCUIT_OPEN));
        $this->assertSame([], self::audit_details(failover_chain::AUDIT_EVENT_FALLTHROUGH));

        // The circuit is closed: the primary is asked again, not skipped.
        $this->assertSame('PRIMARY-OUT', $chain->chat_completion('sys', $history));
        $this->assertSame(4, $primary->callcount);
        $this->assertSame(0, $fallback->callcount);
    }

    /**
     * The same rescue on the streaming path, which is the one a learner uses.
     */
    public function test_a_long_history_refused_before_the_first_token_is_retried_short_when_streaming(): void {
        $this->resetAfterTest();
        $primary = self::picky_provider('PRIMARY-STREAM', 4, 422);
        $fallback = self::fake_streaming_provider(['fallback-text'], false);
        $chain = new failover_chain($primary, 'trim-stream-primary', [
            ['provider' => $fallback, 'label' => 'trim-stream-fallback'],
        ], ['audit' => true, 'courseid' => 0, 'userid' => 0]);

        $captured = [];
        $chain->chat_completion_stream('sys', self::long_history(6), function (string $chunk) use (&$captured) {
            $captured[] = $chunk;
        });
        $this->assertSame(['PRIMARY-STREAM'], $captured);
        $this->assertSame(2, $primary->callcount);
        $this->assertSame(0, $fallback->callcount);
        $this->assertSame('rescued', self::audit_details(failover_chain::AUDIT_EVENT_TRIMMED)[0]['outcome']);
        $this->assertSame([], self::audit_details(failover_chain::AUDIT_EVENT_CIRCUIT_OPEN));
    }

    /**
     * When the short request is refused too the long history was not the
     * problem: the chain falls over as before and the audit says the retry failed
     * and why. A 400 about the request is not a sick provider, so the circuit
     * stays closed and the next learner's request still reaches it.
     */
    public function test_a_retry_that_is_refused_too_falls_over_without_blocking_the_provider(): void {
        $this->resetAfterTest();
        $primary = self::picky_provider('never', 0);
        $fallback = self::fake_provider('FALLBACK-OUT', false);
        $chain = new failover_chain($primary, 'trim2-primary', [
            ['provider' => $fallback, 'label' => 'trim2-fallback'],
        ], ['audit' => true, 'courseid' => 0, 'userid' => 0]);

        $this->assertSame('FALLBACK-OUT', $chain->chat_completion('sys', self::long_history(6)));
        $this->assertSame(2, $primary->callcount, 'one try, one shorter retry, no third');
        $this->assertSame(1, $fallback->callcount);

        $trimmed = self::audit_details(failover_chain::AUDIT_EVENT_TRIMMED);
        $this->assertCount(1, $trimmed);
        $this->assertSame('failed', $trimmed[0]['outcome']);
        $this->assertStringContainsString('HTTP 400', $trimmed[0]['retry_detail']);
        $this->assertSame([], self::audit_details(failover_chain::AUDIT_EVENT_CIRCUIT_OPEN));
        $fell = self::audit_details(failover_chain::AUDIT_EVENT_FALLTHROUGH);
        $this->assertCount(1, $fell);
        $this->assertSame('unaffected', $fell[0]['circuit']);

        // Not blocked: the next request reaches the primary again.
        $chain->chat_completion('sys', self::long_history(6));
        $this->assertSame(4, $primary->callcount);
    }

    /**
     * Only a request the provider called malformed is retried short. A rate
     * limit, an outage, a bad key or a model that does not exist says nothing
     * about the history, and a short history does not cure them.
     */
    public function test_only_a_400_413_or_422_rejection_of_a_long_history_is_retried(): void {
        $this->resetAfterTest();
        foreach ([429, 500, 401, 404] as $status) {
            $primary = self::picky_provider('never', 0, $status);
            $chain = new failover_chain($primary, 'status-' . $status, [
                ['provider' => self::fake_provider('FALLBACK-OUT', false), 'label' => 'status-fb-' . $status],
            ], ['audit' => false]);
            $this->assertSame('FALLBACK-OUT', $chain->chat_completion('sys', self::long_history(6)));
            $this->assertSame(1, $primary->callcount, "HTTP $status must not be retried with a short history");
        }
        foreach ([400, 413, 422] as $status) {
            $primary = self::picky_provider('PRIMARY-OUT', 4, $status);
            $chain = new failover_chain($primary, 'retried-' . $status, [
                ['provider' => self::fake_provider('FALLBACK-OUT', false), 'label' => 'retried-fb-' . $status],
            ], ['audit' => false]);
            $this->assertSame('PRIMARY-OUT', $chain->chat_completion('sys', self::long_history(6)));
            $this->assertSame(2, $primary->callcount, "HTTP $status is retried once with a short history");
        }
    }

    /**
     * An ordinary provider failure that is not an HTTP rejection (a network
     * error, say) is not retried either, and a history already as short as the
     * retry would send is not worth a second call.
     */
    public function test_other_failures_and_short_histories_are_not_retried(): void {
        $this->resetAfterTest();
        $plain = self::failing_provider('curl error 28');
        $chain = new failover_chain($plain, 'plain-primary', [
            ['provider' => self::fake_provider('FALLBACK-OUT', false), 'label' => 'plain-fallback'],
        ], ['audit' => false]);
        $chain->chat_completion('sys', self::long_history(6));
        $this->assertSame(1, $plain->callcount);

        $primary = self::picky_provider('never', 0);
        $chain = new failover_chain($primary, 'short-primary', [
            ['provider' => self::fake_provider('FALLBACK-OUT', false), 'label' => 'short-fallback'],
        ], ['audit' => false]);
        $chain->chat_completion('sys', self::long_history(1));
        $this->assertSame(1, $primary->callcount, 'three turns are already shorter than the retry');
    }

    /**
     * v7.8.8: the circuit-open and fall-through rows carry what the provider
     * said (its debuginfo, not the generic exception message), with credentials
     * replaced and the length bounded, so an admin can finally tell why a
     * circuit opened.
     */
    public function test_the_audit_rows_carry_the_providers_words_redacted_and_bounded(): void {
        $this->resetAfterTest();
        // An auth failure opens its circuit at once, so both rows are written.
        $key = 'sk-ant-api03-' . str_repeat('A1b2', 12);
        $debuginfo = "HTTP 400: {\n  \"error\": \"invalid_request_error\",\n  \"detail\": \"bad key $key rejected\"\n} "
            . str_repeat('padding ', 100);
        $failing = new class ($debuginfo) implements provider_interface {
            public function __construct(private string $debuginfo) {
            }
            public function chat_completion(string $systemprompt, array $messages, array $options = []): string {
                throw new \moodle_exception('chat:error_auth', 'local_ai_course_assistant', '', null, $this->debuginfo);
            }
            public function chat_completion_stream(string $systemprompt, array $messages, callable $cb, array $options = []): void {
                throw new \moodle_exception('chat:error_auth', 'local_ai_course_assistant', '', null, $this->debuginfo);
            }
            public function get_last_token_usage(): ?array {
                return null;
            }
            public function get_last_finish_reason(): ?string {
                return null;
            }
        };
        $chain = new failover_chain($failing, 'detail-primary', [
            ['provider' => self::fake_provider('FALLBACK-OUT', false), 'label' => 'detail-fallback'],
        ], ['audit' => true, 'courseid' => 0, 'userid' => 0]);
        $chain->chat_completion('sys', [['role' => 'user', 'content' => 'hi']]);

        foreach ([failover_chain::AUDIT_EVENT_CIRCUIT_OPEN, failover_chain::AUDIT_EVENT_FALLTHROUGH] as $action) {
            $rows = self::audit_details($action);
            $this->assertCount(1, $rows, $action);
            $detail = $rows[0]['detail'];
            $this->assertStringStartsWith('HTTP 400: { "error": "invalid_request_error"', $detail, $action);
            $this->assertStringNotContainsString('A1b2A1b2', $detail, "$action must not keep the key");
            $this->assertStringContainsString('[redacted-key]', $detail, $action);
            $this->assertLessThanOrEqual(failover_chain::DETAIL_LIMIT, \core_text::strlen($detail), $action);
            $this->assertStringNotContainsString("\n", $detail, $action);
        }
        // The fall-through row still carries the exception message as 'reason'.
        $this->assertArrayHasKey('reason', self::audit_details(failover_chain::AUDIT_EVENT_FALLTHROUGH)[0]);
    }

    /**
     * Without debuginfo the message is what there is to keep.
     */
    public function test_describe_failure_falls_back_to_the_message(): void {
        $this->assertSame('plain failure', failover_chain::describe_failure(new \RuntimeException('plain failure')));
        $this->assertSame('', failover_chain::describe_failure(new \RuntimeException('')));
    }

    /**
     * A streaming provider that refuses a long history with a 400, then behaves
     * oddly on the shorter retry.
     *
     * @param string $retry 'partial' streams one chunk then throws; 'silent' returns without a token.
     * @return object Anonymous provider_interface with public $callcount.
     */
    private static function odd_retry_provider(string $retry): object {
        return new class ($retry) implements provider_interface {
            public int $callcount = 0;
            public function __construct(private string $retry) {
            }
            public function chat_completion(string $systemprompt, array $messages, array $options = []): string {
                throw new \coding_exception('streaming only');
            }
            public function chat_completion_stream(string $systemprompt, array $messages, callable $cb, array $options = []): void {
                $this->callcount++;
                if (count($messages) > 4) {
                    throw new provider_http_exception(400, 'invalid_request_error: too long');
                }
                if ($this->retry === 'partial') {
                    $cb('half an answer');
                    throw new \moodle_exception('chat:error', 'local_ai_course_assistant', '', null, 'connection reset');
                }
            }
            public function get_last_token_usage(): ?array {
                return null;
            }
            public function get_last_finish_reason(): ?string {
                return null;
            }
        };
    }

    /**
     * If the shorter request starts to answer and then fails, the learner has
     * already seen text: the error propagates and no fallback runs, exactly as
     * for any other mid-stream failure.
     */
    public function test_a_retry_that_fails_after_its_first_token_does_not_fall_over(): void {
        $this->resetAfterTest();
        $primary = self::odd_retry_provider('partial');
        $fallback = self::fake_streaming_provider(['fallback-text'], false);
        $chain = new failover_chain($primary, 'odd1-primary', [
            ['provider' => $fallback, 'label' => 'odd1-fallback'],
        ], ['audit' => true, 'courseid' => 0, 'userid' => 0]);
        $captured = [];
        try {
            $chain->chat_completion_stream('sys', self::long_history(6), function (string $chunk) use (&$captured) {
                $captured[] = $chunk;
            });
            $this->fail('The mid-stream failure of the retry must propagate.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('connection reset', (string) $e->debuginfo);
        }
        $this->assertSame(['half an answer'], $captured);
        $this->assertSame(2, $primary->callcount);
        $this->assertSame(0, $fallback->callcount);
        $this->assertSame([], self::audit_details(failover_chain::AUDIT_EVENT_TRIMMED), 'no outcome row for a turn already on screen');
    }

    /**
     * A retry that returns without producing a token is a failure: the chain
     * records it and falls over.
     */
    public function test_a_retry_that_produces_no_token_is_a_failure(): void {
        $this->resetAfterTest();
        $primary = self::odd_retry_provider('silent');
        $fallback = self::fake_streaming_provider(['fallback-text'], false);
        $chain = new failover_chain($primary, 'odd2-primary', [
            ['provider' => $fallback, 'label' => 'odd2-fallback'],
        ], ['audit' => true, 'courseid' => 0, 'userid' => 0]);
        $captured = [];
        $chain->chat_completion_stream('sys', self::long_history(6), function (string $chunk) use (&$captured) {
            $captured[] = $chunk;
        });
        $this->assertSame(['fallback-text'], $captured);
        $this->assertSame('failed', self::audit_details(failover_chain::AUDIT_EVENT_TRIMMED)[0]['outcome']);
        // The first refusal was about the request, so the provider's circuit stays closed.
        $this->assertSame([], self::audit_details(failover_chain::AUDIT_EVENT_CIRCUIT_OPEN));
        $this->assertSame('unaffected', self::audit_details(failover_chain::AUDIT_EVENT_FALLTHROUGH)[0]['circuit']);
    }

    /**
     * A provider that follows a script of outcomes, one per call.
     *
     * @param string[] $script 'ok' or 'fail', repeated from the start when it runs out.
     * @param string $identity What it reports as circuit_identity() ('' for none).
     * @param string $errorcode Error code its failures carry.
     * @return object Anonymous provider_interface with public $callcount.
     */
    private static function scripted_provider(array $script, string $identity = '', string $errorcode = 'error'): object {
        return new class ($script, $identity, $errorcode) implements provider_interface {
            public int $callcount = 0;
            public function __construct(private array $script, private string $identity, private string $errorcode) {
            }
            public function circuit_identity(): string {
                return $this->identity;
            }
            private function step(): void {
                $outcome = $this->script[$this->callcount % count($this->script)];
                $this->callcount++;
                if ($outcome === 'fail') {
                    throw new \moodle_exception($this->errorcode, 'local_ai_course_assistant', '', null, 'scripted failure');
                }
            }
            public function chat_completion(string $systemprompt, array $messages, array $options = []): string {
                $this->step();
                return 'SCRIPTED-OUT';
            }
            public function chat_completion_stream(string $systemprompt, array $messages, callable $cb, array $options = []): void {
                $this->step();
                $cb('SCRIPTED-OUT');
            }
            public function get_last_token_usage(): ?array {
                return null;
            }
            public function get_last_finish_reason(): ?string {
                return null;
            }
        };
    }

    /**
     * What each kind of failure does to a circuit.
     */
    public function test_circuit_effect_classifies_failures(): void {
        $request = new provider_http_exception(400, 'invalid_request_error: messages.5.content.0.text must be non-empty');
        $this->assertSame('none', failover_chain::circuit_effect($request));
        $this->assertSame('none', failover_chain::circuit_effect(new provider_http_exception(413, 'too large')));
        $this->assertSame('none', failover_chain::circuit_effect(new provider_http_exception(422, 'unprocessable')));

        foreach (
            [
                'Please pass a valid API key',
                'Your credit balance is too low to access the Anthropic API',
                'You have reached your specified API usage limits.',
            ] as $body
        ) {
            $this->assertSame('immediate', failover_chain::circuit_effect(new provider_http_exception(400, $body)), $body);
        }
        $this->assertSame('immediate', failover_chain::circuit_effect(
            new \moodle_exception('chat:error_auth', 'local_ai_course_assistant')
        ));
        $this->assertSame('immediate', failover_chain::circuit_effect(new model_not_found_exception('no such model')));

        $this->assertSame('counted', failover_chain::circuit_effect(
            new \moodle_exception('chat:error_ratelimit', 'local_ai_course_assistant')
        ));
        $this->assertSame('counted', failover_chain::circuit_effect(
            new \moodle_exception('chat:error_unavailable', 'local_ai_course_assistant')
        ));
        $this->assertSame('counted', failover_chain::circuit_effect(new \RuntimeException('curl error 28')));
        $this->assertSame('counted', failover_chain::circuit_effect(new provider_http_exception(402, 'payment required')));
        $this->assertSame('none', failover_chain::circuit_effect(
            new provider_http_exception(400, 'I want to buy credit please'),
        ), 'a common word quoted back is not an account fault');
    }

    /**
     * A request the provider calls malformed never opens a circuit, however often
     * it happens: the provider is fine, and one learner's request must not block
     * everyone behind the same label.
     */
    public function test_a_request_the_provider_calls_malformed_never_opens_the_circuit(): void {
        $this->resetAfterTest();
        $primary = self::picky_provider('never', 0);
        $chain = new failover_chain($primary, 'malformed-primary', [
            ['provider' => self::fake_provider('FALLBACK-OUT', false), 'label' => 'malformed-fallback'],
        ], ['audit' => true, 'courseid' => 0, 'userid' => 0]);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('FALLBACK-OUT', $chain->chat_completion('sys', [['role' => 'user', 'content' => 'hi']]));
        }
        $this->assertSame(5, $primary->callcount, 'the primary is asked every time');
        $this->assertSame([], self::audit_details(failover_chain::AUDIT_EVENT_CIRCUIT_OPEN));
        $this->assertSame(
            array_fill(0, 5, 'unaffected'),
            array_column(self::audit_details(failover_chain::AUDIT_EVENT_FALLTHROUGH), 'circuit')
        );
    }

    /**
     * A 400 that is about the key or the account (BUS101's stale Google key, an
     * exhausted Anthropic balance) will fail every request on that key and model:
     * the circuit opens at once, and no shorter-history retry is wasted on it.
     */
    public function test_an_account_level_400_opens_the_circuit_at_once_and_is_not_retried_short(): void {
        $this->resetAfterTest();
        $primary = self::picky_provider('never', 0, 400, 0, 'Please pass a valid API key');
        $chain = new failover_chain($primary, 'account-primary', [
            ['provider' => self::fake_provider('FALLBACK-OUT', false), 'label' => 'account-fallback'],
        ], ['audit' => true, 'courseid' => 0, 'userid' => 0]);
        $this->assertSame('FALLBACK-OUT', $chain->chat_completion('sys', self::long_history(6)));
        $this->assertSame(1, $primary->callcount, 'no shorter retry for a fault that is not about the history');
        $this->assertSame([], self::audit_details(failover_chain::AUDIT_EVENT_TRIMMED));
        $this->assertCount(1, self::audit_details(failover_chain::AUDIT_EVENT_CIRCUIT_OPEN));
        $chain->chat_completion('sys', self::long_history(6));
        $this->assertSame(1, $primary->callcount, 'the circuit is open, so the primary is skipped');
    }

    /**
     * The fix for the BUS101 incident: two chain members that share a label but
     * use different keys (or models) do not share a circuit.
     */
    public function test_a_rejected_key_opens_only_its_own_circuit(): void {
        $this->resetAfterTest();
        $stale = self::scripted_provider(['fail'], 'key-stale', 'chat:error_auth');
        $good = self::scripted_provider(['ok'], 'key-good');
        $fallback = self::fake_provider('FALLBACK-OUT', false);
        $staleChain = new failover_chain($stale, 'shared-label', [
            ['provider' => $fallback, 'label' => 'scope-fallback'],
        ]);
        $goodChain = new failover_chain($good, 'shared-label', [
            ['provider' => $fallback, 'label' => 'scope-fallback'],
        ]);

        $staleChain->chat_completion('sys', [['role' => 'user', 'content' => 'hi']]);
        $staleChain->chat_completion('sys', [['role' => 'user', 'content' => 'hi']]);
        $this->assertSame(1, $stale->callcount, 'the stale key is skipped once its circuit is open');

        $this->assertSame('SCRIPTED-OUT', $goodChain->chat_completion('sys', [['role' => 'user', 'content' => 'hi']]));
        $this->assertSame(1, $good->callcount, 'a healthy key under the same label is not blocked');
    }

    /**
     * Failures that come and go open the circuit on the second in a row, and the
     * audit says where each stood.
     */
    public function test_transient_failures_open_the_circuit_on_the_second_in_a_row(): void {
        $this->resetAfterTest();
        $primary = self::scripted_provider(['fail']);
        $chain = new failover_chain($primary, 'count-primary', [
            ['provider' => self::fake_provider('FALLBACK-OUT', false), 'label' => 'count-fallback'],
        ], ['audit' => true, 'courseid' => 0, 'userid' => 0]);
        $chain->chat_completion('sys', [['role' => 'user', 'content' => 'one']]);
        $this->assertSame([], self::audit_details(failover_chain::AUDIT_EVENT_CIRCUIT_OPEN), 'one failure does not open it');
        $chain->chat_completion('sys', [['role' => 'user', 'content' => 'two']]);
        $this->assertCount(1, self::audit_details(failover_chain::AUDIT_EVENT_CIRCUIT_OPEN));
        $this->assertSame(
            ['counting', 'opened'],
            array_column(self::audit_details(failover_chain::AUDIT_EVENT_FALLTHROUGH), 'circuit')
        );
        $chain->chat_completion('sys', [['role' => 'user', 'content' => 'three']]);
        $this->assertSame(2, $primary->callcount);
    }

    /**
     * A success between failures ends the run, so only failures in a row count.
     */
    public function test_a_success_between_failures_resets_the_count(): void {
        $this->resetAfterTest();
        $primary = self::scripted_provider(['fail', 'ok', 'fail', 'ok']);
        $chain = new failover_chain($primary, 'reset-primary', [
            ['provider' => self::fake_provider('FALLBACK-OUT', false), 'label' => 'reset-fallback'],
        ], ['audit' => true, 'courseid' => 0, 'userid' => 0]);
        for ($i = 0; $i < 4; $i++) {
            $chain->chat_completion('sys', [['role' => 'user', 'content' => 'hi']]);
        }
        $this->assertSame(4, $primary->callcount, 'never two failures in a row, so never skipped');
        $this->assertSame([], self::audit_details(failover_chain::AUDIT_EVENT_CIRCUIT_OPEN));
    }

    /**
     * Two failures further apart than the window are two separate incidents.
     */
    public function test_failures_further_apart_than_the_window_do_not_add_up(): void {
        $this->resetAfterTest();
        $primary = self::scripted_provider(['fail']);
        $chain = new failover_chain($primary, 'window-primary', [
            ['provider' => self::fake_provider('FALLBACK-OUT', false), 'label' => 'window-fallback'],
        ], ['audit' => true, 'courseid' => 0, 'userid' => 0]);
        $chain->chat_completion('sys', [['role' => 'user', 'content' => 'one']]);
        // Age the recorded failure past the window.
        $cache = \cache::make('local_ai_course_assistant', 'failover_circuit');
        $scope = failover_chain::circuit_scope('window-primary', $primary);
        $cache->set('fails_' . $scope, ['n' => 1, 'first' => time() - failover_chain::FAILURE_WINDOW - 30]);
        $chain->chat_completion('sys', [['role' => 'user', 'content' => 'two']]);
        $this->assertSame([], self::audit_details(failover_chain::AUDIT_EVENT_CIRCUIT_OPEN));
        $this->assertSame(
            ['counting', 'counting'],
            array_column(self::audit_details(failover_chain::AUDIT_EVENT_FALLTHROUGH), 'circuit')
        );
    }

    /**
     * Build an in-memory provider_interface implementation for tests.
     * If $shouldfail is true, every chat_completion call throws.
     *
     * @param string $output Returned by chat_completion on success.
     * @param bool $shouldfail
     * @return object Anonymous class implementing provider_interface with a public $callcount.
     */
    private static function fake_provider(string $output, bool $shouldfail): object {
        return new class ($output, $shouldfail) implements provider_interface {
            public int $callcount = 0;
            public function __construct(private string $output, private bool $shouldfail) {
            }
            public function chat_completion(string $systemprompt, array $messages, array $options = []): string {
                $this->callcount++;
                if ($this->shouldfail) {
                    throw new \moodle_exception('error', 'local_ai_course_assistant', '', 'fake failure');
                }
                return $this->output;
            }
            public function chat_completion_stream(string $systemprompt, array $messages, callable $cb, array $options = []): void {
                $this->callcount++;
                if ($this->shouldfail) {
                    throw new \moodle_exception('error', 'local_ai_course_assistant', '', 'fake stream failure');
                }
                $cb($this->output);
            }
            public function get_last_token_usage(): ?array {
                return null;
            }
            public function get_last_finish_reason(): ?string {
                return null;
            }
        };
    }

    /**
     * Build an in-memory streaming provider. Emits each chunk in $chunks
     * in order. If $failafterfirst is true, throws after the first chunk.
     *
     * @param array $chunks
     * @param bool $failafterfirst
     * @return object
     */
    private static function fake_streaming_provider(array $chunks, bool $failafterfirst): object {
        return new class ($chunks, $failafterfirst) implements provider_interface {
            public int $callcount = 0;
            public function __construct(private array $chunks, private bool $failafterfirst) {
            }
            public function chat_completion(string $systemprompt, array $messages, array $options = []): string {
                $this->callcount++;
                return implode('', $this->chunks);
            }
            public function chat_completion_stream(string $systemprompt, array $messages, callable $cb, array $options = []): void {
                $this->callcount++;
                $first = true;
                foreach ($this->chunks as $chunk) {
                    $cb($chunk);
                    if ($first && $this->failafterfirst) {
                        throw new \moodle_exception('error', 'local_ai_course_assistant', '', 'stream error post-first-token');
                    }
                    $first = false;
                }
            }
            public function get_last_token_usage(): ?array {
                return null;
            }
            public function get_last_finish_reason(): ?string {
                return null;
            }
        };
    }
}
