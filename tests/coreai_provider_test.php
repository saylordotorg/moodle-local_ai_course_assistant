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

use local_ai_course_assistant\provider\coreai_declined_exception;
use local_ai_course_assistant\provider\coreai_provider;
use local_ai_course_assistant\provider\base_provider;
use local_ai_course_assistant\provider\provider_http_exception;

/**
 * Tests for the Moodle core_ai provider adapter and the 'auto' provider default.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\coreai_provider
 */
final class coreai_provider_test extends \advanced_testcase {
    /**
     * extract_text reads 'generatedcontent', the only text key core_ai's
     * response_generate_text has had from Moodle 4.5 to 5.3. The old guesses
     * (content, response, completion, text) are not read any more.
     */
    public function test_extract_text_reads_generatedcontent_only(): void {
        $m = new \ReflectionMethod(coreai_provider::class, 'extract_text');
        $m->setAccessible(true);
        $this->assertSame('hi', $m->invoke(null, ['generatedcontent' => 'hi']));
        $this->assertSame('', $m->invoke(null, ['generatedcontent' => null]));
        $this->assertSame('', $m->invoke(null, []));
        foreach (['content', 'response', 'completion', 'text'] as $key) {
            $this->assertSame('', $m->invoke(null, [$key => 'hi']), $key);
        }
    }

    /**
     * flatten_conversation folds the system prompt and turns into one prompt,
     * labelled by role and ending with an open "Assistant:" cue.
     */
    public function test_flatten_conversation(): void {
        $m = new \ReflectionMethod(coreai_provider::class, 'flatten_conversation');
        $m->setAccessible(true);
        $out = $m->invoke(null, 'SYS', [
            ['role' => 'user', 'content' => 'q1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => 'q2'],
        ]);
        $this->assertStringContainsString('[System instructions]', $out);
        $this->assertStringContainsString('SYS', $out);
        $this->assertStringContainsString('User: q1', $out);
        $this->assertStringContainsString('Assistant: a1', $out);
        $this->assertStringContainsString('User: q2', $out);
        $this->assertStringEndsWith('Assistant:', $out);
        // Empty-content turns are skipped.
        $out2 = $m->invoke(null, '', [['role' => 'user', 'content' => '']]);
        $this->assertSame('Assistant:', trim($out2));
    }

    /**
     * With no core_ai provider able to generate text (the test site has none),
     * is_available must return false, so 'auto' will not pick coreai.
     */
    public function test_is_available_false_without_a_generate_text_provider(): void {
        $this->resetAfterTest();
        $this->assertFalse(coreai_provider::is_available());
    }

    /**
     * Availability is core's own is_action_available() for generate_text, not a
     * count of enabled provider rows.
     */
    public function test_generate_text_available_asks_core(): void {
        $manager = new class {
            /** @var array */
            public array $asked = [];
            /** @var bool */
            public bool $answer = true;
            public function is_action_available(string $actionclass): bool {
                $this->asked[] = $actionclass;
                return $this->answer;
            }
        };
        $this->assertTrue(coreai_provider::generate_text_available($manager));
        $this->assertSame(['core_ai\\aiactions\\generate_text'], $manager->asked);
        $manager->answer = false;
        $this->assertFalse(coreai_provider::generate_text_available($manager));
        $this->assertFalse(coreai_provider::generate_text_available(new \stdClass()));
    }

    /**
     * The gate: AI off in the context always declines; an unaccepted policy
     * declines only a person at a browser; "can't tell" never declines.
     */
    public function test_gate(): void {
        $this->assertNull(coreai_provider::gate(true, true, true));
        $this->assertNull(coreai_provider::gate(true, null, null), 'a Moodle that cannot tell never declines');
        $this->assertSame(coreai_declined_exception::POLICY, coreai_provider::gate(true, false, true));
        $this->assertSame(coreai_declined_exception::POLICY, coreai_provider::gate(true, false, null));
        $this->assertNull(coreai_provider::gate(false, false, true), 'cron and CLI are not held to the policy');
        $this->assertSame(coreai_declined_exception::CONTEXT, coreai_provider::gate(true, true, false));
        $this->assertSame(coreai_declined_exception::CONTEXT, coreai_provider::gate(false, null, false));
        $this->assertSame(coreai_declined_exception::CONTEXT, coreai_provider::gate(true, false, false));
    }

    /**
     * enabled_in_context is null on a Moodle without the check (4.5) or when it
     * throws, and core's answer otherwise.
     */
    public function test_enabled_in_context(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $this->assertNull(coreai_provider::enabled_in_context(new \stdClass(), $context));
        $on = new class {
            public function is_action_enabled_in_context(\context $c, string $a): bool {
                return true;
            }
        };
        $off = new class {
            public function is_action_enabled_in_context(\context $c, string $a): bool {
                return false;
            }
        };
        $broken = new class {
            public function is_action_enabled_in_context(\context $c, string $a): bool {
                throw new \coding_exception('boom');
            }
        };
        $this->assertTrue(coreai_provider::enabled_in_context($on, $context));
        $this->assertFalse(coreai_provider::enabled_in_context($off, $context));
        $this->assertNull(coreai_provider::enabled_in_context($broken, $context));
    }

    /**
     * The recorded model is the one core_ai names (5.3), else 'moodle_core_ai'.
     */
    public function test_model_used(): void {
        $this->assertSame('gpt-4o', coreai_provider::model_used('gpt-4o', []));
        $this->assertSame('gpt-4o', coreai_provider::model_used(null, ['model' => 'gpt-4o']));
        $this->assertSame('claude-sonnet-5', coreai_provider::model_used(' claude-sonnet-5 ', ['model' => 'other']));
        $this->assertSame('other', coreai_provider::model_used('', ['model' => 'other']));
        $this->assertSame(coreai_provider::FALLBACK_MODEL, coreai_provider::model_used(null, []));
        $this->assertSame(coreai_provider::FALLBACK_MODEL, coreai_provider::model_used(null, ['model' => null]));
        $this->assertSame(coreai_provider::FALLBACK_MODEL, coreai_provider::model_used(null, ['model' => "\n\t"]));
        $this->assertSame('bad', coreai_provider::model_used("b\x00a\x7Fd", []));
        $this->assertSame(100, strlen(coreai_provider::model_used(str_repeat('m', 300), [])));
    }

    /**
     * A model core_ai names is priced when the rate card knows it; the fallback
     * name and an unknown model stay unpriced (null), never free.
     */
    public function test_recorded_models_are_priced_or_unpriced_never_free(): void {
        $this->resetAfterTest();
        $this->assertNotNull(token_cost_manager::get_rates(coreai_provider::model_used('gpt-4o-mini', [])));
        $this->assertNull(token_cost_manager::get_rates(coreai_provider::FALLBACK_MODEL));
        $this->assertNull(token_cost_manager::estimate_cost(coreai_provider::FALLBACK_MODEL, 1000, 1000));
        $this->assertNull(token_cost_manager::estimate_cost('my-azure-deployment', 1000, 1000));
    }

    /**
     * The finish reason core_ai passes on is kept, so a cut-off answer is marked truncated.
     */
    public function test_finish_reason(): void {
        $this->assertSame('length', coreai_provider::finish_reason(['finishreason' => 'length']));
        $this->assertTrue(base_provider::is_truncation(coreai_provider::finish_reason(['finishreason' => 'length'])));
        $this->assertNull(coreai_provider::finish_reason(['finishreason' => null]));
        $this->assertNull(coreai_provider::finish_reason(['finishreason' => ' ']));
        $this->assertNull(coreai_provider::finish_reason([]));
    }

    /**
     * core_ai failures map onto the exceptions the failover chain understands.
     */
    public function test_failure_mapping(): void {
        $this->resetAfterTest();
        $e = coreai_provider::failure(503, 'Service unavailable', 'Try later. Key sk-FAKEFAKEFAKEFAKE');
        $this->assertInstanceOf(provider_http_exception::class, $e);
        $this->assertSame(503, $e->status);
        $this->assertStringContainsString('Service unavailable', $e->body);
        $this->assertStringNotContainsString('sk-FAKEFAKEFAKEFAKE', $e->body);

        $e = coreai_provider::failure(400, '', 'bad request');
        $this->assertInstanceOf(provider_http_exception::class, $e);
        $this->assertSame('none', provider\failover_chain::circuit_effect($e));

        $e = coreai_provider::failure(-1, 'No providers available to process the action.', '');
        $this->assertNotInstanceOf(provider_http_exception::class, $e);
        $this->assertSame('chat:error_notconfigured', $e->errorcode);

        $e = coreai_provider::failure(0, '', '');
        $this->assertSame('chat:error', $e->errorcode);

        // core's SITE-WIDE limit is the provider's state: an ordinary 429.
        $e = coreai_provider::failure(429, 'Too many requests', 'site-wide limit');
        $this->assertInstanceOf(provider_http_exception::class, $e);
    }

    /**
     * core's PER-USER limit is one learner's quota: declined, so it never counts
     * toward the site-wide core_ai circuit.
     */
    public function test_the_per_user_limit_is_declined_not_counted(): void {
        $this->resetAfterTest();
        if (!get_string_manager()->string_exists('error:429:internaluser', 'core_ai')) {
            $this->markTestSkipped('This Moodle has no core_ai per-user limit string.');
        }
        $limit = get_string('error:429:internaluser', 'core_ai');
        foreach ([['Rate limit exceeded', $limit], [$limit, '']] as [$error, $message]) {
            $e = coreai_provider::failure(429, $error, $message);
            $this->assertInstanceOf(coreai_declined_exception::class, $e);
            $this->assertSame(coreai_declined_exception::USER_LIMIT, $e->reason);
            $this->assertSame('skip', provider\failover_chain::circuit_effect($e));
        }
    }

    /**
     * The learner notices reach the learner as written: the chat widget swaps any
     * error containing 'auth', 'rate', 401, 429, 500, 502 or 503 for a generic
     * notice, and shows only plain text under 400 characters.
     */
    public function test_declined_notices_survive_the_widget_error_filter(): void {
        $this->resetAfterTest();
        foreach ([coreai_declined_exception::POLICY, coreai_declined_exception::CONTEXT,
                coreai_declined_exception::USER_LIMIT] as $reason) {
            $e = coreai_provider::declined($reason, 42);
            $this->assertInstanceOf(provider\chat_refused_exception::class, $e);
            $this->assertSame($reason, $e->reason);
            $text = $e->getMessage();
            foreach (['auth', 'rate', '401', '429', '500', '502', '503'] as $trap) {
                $this->assertStringNotContainsStringIgnoringCase($trap, $text, "{$reason} notice contains '{$trap}'");
            }
            $this->assertLessThan(400, strlen($text));
            $this->assertStringNotContainsString('[[', $text, 'brand tokens resolved');
            $this->assertStringStartsNotWith('<', trim($text));
        }
        $policy = coreai_provider::declined(coreai_declined_exception::POLICY, 42)->getMessage();
        $this->assertStringContainsString('/local/ai_course_assistant/coreai_policy.php?courseid=42', $policy);
    }

    /**
     * The action runs in the course context when the provider knows its course,
     * and in the system context otherwise.
     */
    public function test_action_context(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $m = new \ReflectionMethod(coreai_provider::class, 'action_context');
        $m->setAccessible(true);
        $incourse = new coreai_provider(['courseid' => $course->id]);
        $this->assertSame(\context_course::instance($course->id)->id, $m->invoke($incourse, [])->id);
        $nocourse = new coreai_provider([]);
        $this->assertSame(\context_system::instance()->id, $m->invoke($nocourse, [])->id);
        $front = new coreai_provider(['courseid' => SITEID]);
        $this->assertSame(\context_system::instance()->id, $m->invoke($front, [])->id);
        $gone = new coreai_provider(['courseid' => $course->id + 1000]);
        $this->assertSame(\context_system::instance()->id, $m->invoke($gone, [])->id);
        // An explicit contextid wins.
        $sys = \context_system::instance()->id;
        $this->assertSame($sys, $m->invoke($incourse, ['contextid' => $sys])->id);
    }

    /**
     * create_from_config hands the course to the provider, so a chat turn runs in
     * the course context rather than the system context.
     */
    public function test_create_from_config_gives_the_provider_its_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        set_config('provider', 'coreai', 'local_ai_course_assistant');
        set_config('failover_per_call_enabled', 0, 'local_ai_course_assistant');
        $provider = base_provider::create_from_config((int) $course->id, true);
        $this->assertInstanceOf(coreai_provider::class, $provider);
        $m = new \ReflectionMethod(coreai_provider::class, 'action_context');
        $m->setAccessible(true);
        $this->assertSame(\context_course::instance($course->id)->id, $m->invoke($provider, [])->id);
    }

    /**
     * A full call through a fake core_ai manager: the course context is sent,
     * the real model and finish reason are recorded, and usage names coreai.
     */
    public function test_chat_completion_through_a_fake_manager(): void {
        $this->resetAfterTest();
        if (!class_exists('\\core_ai\\aiactions\\generate_text')) {
            $this->markTestSkipped('core_ai is not present.');
        }
        $course = $this->getDataGenerator()->create_course();
        $manager = self::fake_manager(true, [
            'generatedcontent' => 'Hello there',
            'finishreason' => 'length',
            'prompttokens' => '12',
            'completiontokens' => '5',
            'model' => 'gpt-4o-mini',
        ]);
        \core\di::set(\core_ai\manager::class, $manager);

        $provider = new coreai_provider(['courseid' => $course->id]);
        $out = $provider->chat_completion('SYS', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Hello there', $out);
        $this->assertSame(\context_course::instance($course->id)->id, $manager->contexts[0]);
        $this->assertSame('length', $provider->get_last_finish_reason());
        $usage = $provider->get_last_token_usage();
        $this->assertSame(12, $usage['prompt_tokens']);
        $this->assertSame(5, $usage['completion_tokens']);
        $this->assertSame('gpt-4o-mini', $usage['model']);
        $this->assertSame('coreai', $usage['provider']);
    }

    /**
     * Without a model from core_ai (Moodle 4.5 to 5.2) the fallback name is recorded.
     */
    public function test_chat_completion_without_a_model_records_the_fallback(): void {
        $this->resetAfterTest();
        if (!class_exists('\\core_ai\\aiactions\\generate_text')) {
            $this->markTestSkipped('core_ai is not present.');
        }
        \core\di::set(\core_ai\manager::class, self::fake_manager(true, [
            'generatedcontent' => 'ok', 'prompttokens' => '1', 'completiontokens' => '1',
        ]));
        $provider = new coreai_provider([]);
        $provider->chat_completion('', [['role' => 'user', 'content' => 'hi']]);
        $this->assertSame(coreai_provider::FALLBACK_MODEL, $provider->get_last_token_usage()['model']);
        $this->assertNull($provider->get_last_finish_reason());
    }

    /**
     * When core says generate_text is off in the course, nothing is sent to core_ai.
     */
    public function test_ai_off_in_the_course_declines_before_core_ai_is_asked(): void {
        $this->resetAfterTest();
        if (!class_exists('\\core_ai\\aiactions\\generate_text')) {
            $this->markTestSkipped('core_ai is not present.');
        }
        $course = $this->getDataGenerator()->create_course();
        $manager = self::fake_manager(false, ['generatedcontent' => 'should not be asked']);
        \core\di::set(\core_ai\manager::class, $manager);
        $provider = new coreai_provider(['courseid' => $course->id]);
        try {
            $provider->chat_completion('', [['role' => 'user', 'content' => 'hi']]);
            $this->fail('expected the turn to be declined');
        } catch (coreai_declined_exception $e) {
            $this->assertSame(coreai_declined_exception::CONTEXT, $e->reason);
        }
        $this->assertSame(0, $manager->processed);
    }

    /**
     * A "successful" response with no text is a failure, not an empty answer.
     */
    public function test_success_with_no_text_is_a_failure(): void {
        $this->resetAfterTest();
        if (!class_exists('\\core_ai\\aiactions\\generate_text')) {
            $this->markTestSkipped('core_ai is not present.');
        }
        \core\di::set(\core_ai\manager::class, self::fake_manager(true, ['generatedcontent' => null]));
        $provider = new coreai_provider([]);
        $this->expectException(\moodle_exception::class);
        $provider->chat_completion('', [['role' => 'user', 'content' => 'hi']]);
    }

    /**
     * The 'auto' provider resolves to a direct provider when there is no usable
     * SOLA key and no core_ai. A configured key keeps it on the direct path.
     */
    public function test_auto_resolves_to_direct_provider(): void {
        $this->resetAfterTest();
        $m = new \ReflectionMethod(base_provider::class, 'resolve_auto_provider');
        $m->setAccessible(true);
        // No key, no core_ai available -> historical direct default.
        $this->assertSame('openai', $m->invoke(null, []));
        // A per-course/site key means the admin wants a direct provider.
        $this->assertSame('openai', $m->invoke(null, ['apikey' => 'sk-test']));
    }

    /**
     * A stand-in for \core_ai\manager.
     *
     * @param bool $enabledincontext What is_action_enabled_in_context() answers.
     * @param array $data The successful response's data.
     * @return object
     */
    private static function fake_manager(bool $enabledincontext, array $data): object {
        return new class ($enabledincontext, $data) {
            /** @var int[] Context ids of the actions processed. */
            public array $contexts = [];
            /** @var int */
            public int $processed = 0;
            public function __construct(private bool $enabled, private array $data) {
            }
            public function is_action_enabled_in_context(\context $context, string $actionclass): bool {
                return $this->enabled;
            }
            public function process_action(\core_ai\aiactions\base $action): object {
                $this->processed++;
                $this->contexts[] = (int) $action->get_configuration('contextid');
                $data = $this->data;
                return new class ($data) {
                    public function __construct(private array $data) {
                    }
                    public function get_success(): bool {
                        return true;
                    }
                    public function get_response_data(): array {
                        return $this->data;
                    }
                    public function get_model_used(): ?string {
                        return $this->data['model'] ?? null;
                    }
                };
            }
        };
    }
}
