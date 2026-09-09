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
 * v7.4.2 tests for reasoning-token capture and persistence.
 *
 * SOLA's spend pipeline reads the msgs table, so anything a provider reports
 * and SOLA never writes is spend the AI Spend dashboard cannot see. Reasoning
 * ("thinking") tokens were the largest such hole: nothing in the codebase read
 * usage.completion_tokens_details.reasoning_tokens, and Gemini -- the
 * production chat tier, reached through Google's OpenAI-compatibility endpoint
 * -- bills thinking as output. Reconciling the logs against the Gemini invoice
 * measured 0.35M completion tokens logged against 1.78M billed.
 *
 * The contract these tests pin:
 *  - reasoning_tokens is read from completion_tokens_details.reasoning_tokens;
 *  - it is recorded EXACTLY as reported and never added into
 *    completion_tokens, because whether it is already counted there is
 *    vendor-specific (OpenAI: yes; Gemini's shim: not reliably) so folding it
 *    in would double-count OpenAI while dropping it under-counts Gemini;
 *  - it is null, not 0, when the provider reported none, so "does not report
 *    thinking" stays distinguishable from "thought zero tokens";
 *  - both the streaming and non-streaming paths shape usage through the same
 *    method, so they cannot drift apart the way they did before v7.0.6;
 *  - it round-trips through add_message() on assistant and system rows;
 *  - log_ancillary_usage() is best-effort and never throws.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\openai_compatible_provider::shape_usage
 * @covers     \local_ai_course_assistant\conversation_manager::add_message
 * @covers     \local_ai_course_assistant\conversation_manager::log_ancillary_usage
 */
final class reasoning_tokens_persistence_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Invoke the provider's protected usage shaper.
     *
     * @param object $provider Provider instance.
     * @param array|null $usage Raw provider `usage` object.
     * @param string|null $model Model id the response reported.
     * @return array|null
     */
    private function shape(object $provider, ?array $usage, ?string $model = null): ?array {
        $m = new \ReflectionMethod($provider, 'shape_usage');
        $m->setAccessible(true);
        return $m->invoke($provider, $usage, $model);
    }

    /**
     * A provider double whose get_last_token_usage() returns whatever we hand it.
     *
     * log_ancillary_usage() only requires an object exposing that method, so
     * this avoids standing up a real HTTP-capable provider.
     *
     * @param array|null $usage
     * @return object
     */
    private function fake_provider(?array $usage): object {
        return new class($usage) {
            /** @var array|null */
            private $usage;

            /**
             * @param array|null $usage
             */
            public function __construct(?array $usage) {
                $this->usage = $usage;
            }

            /**
             * @return array|null
             */
            public function get_last_token_usage(): ?array {
                return $this->usage;
            }
        };
    }

    // ---------------------------------------------------------------- capture.

    /**
     * The counter is read from completion_tokens_details.reasoning_tokens.
     */
    public function test_reasoning_tokens_captured_from_usage_payload(): void {
        $p = new \local_ai_course_assistant\provider\openai_provider(['apikey' => 'x', 'model' => 'gpt-4o-mini']);

        $shaped = $this->shape($p, [
            'prompt_tokens'     => 1200,
            'completion_tokens' => 400,
            'completion_tokens_details' => ['reasoning_tokens' => 512],
        ], 'o4-mini');

        $this->assertSame(512, $shaped['reasoning_tokens']);
    }

    /**
     * Reasoning is NOT summed into completion_tokens.
     *
     * This is the whole point of a separate column. OpenAI already counts
     * reasoning inside completion_tokens, so adding it here would bill those
     * tokens twice; Gemini's OpenAI-compat shim has not reliably counted it
     * there, so dropping it keeps under-counting. Storing it raw lets the
     * consumer, which knows the provider, decide.
     */
    public function test_reasoning_tokens_are_not_folded_into_completion_tokens(): void {
        $p = new \local_ai_course_assistant\provider\openai_provider(['apikey' => 'x', 'model' => 'gpt-4o-mini']);

        $shaped = $this->shape($p, [
            'prompt_tokens'     => 100,
            'completion_tokens' => 400,
            'completion_tokens_details' => ['reasoning_tokens' => 512],
        ], null);

        $this->assertSame(400, $shaped['completion_tokens'], 'completion_tokens must be reported verbatim');
        $this->assertSame(512, $shaped['reasoning_tokens']);
        $this->assertSame(100, $shaped['prompt_tokens']);
    }

    /**
     * Null -- not 0 -- when the provider reports no thinking at all, so
     * "this model does not report reasoning" stays distinguishable from
     * "it reasoned for zero tokens this call".
     */
    public function test_reasoning_tokens_null_when_provider_reports_none(): void {
        $p = new \local_ai_course_assistant\provider\openai_provider(['apikey' => 'x', 'model' => 'gpt-4o-mini']);

        $shaped = $this->shape($p, [
            'prompt_tokens'     => 10,
            'completion_tokens' => 5,
        ], null);

        $this->assertArrayHasKey('reasoning_tokens', $shaped);
        $this->assertNull($shaped['reasoning_tokens']);
    }

    /**
     * An explicit zero is preserved as zero, not collapsed to null.
     */
    public function test_zero_reasoning_tokens_stored_as_zero(): void {
        $p = new \local_ai_course_assistant\provider\openai_provider(['apikey' => 'x', 'model' => 'gpt-4o-mini']);

        $shaped = $this->shape($p, [
            'prompt_tokens'     => 10,
            'completion_tokens' => 5,
            'completion_tokens_details' => ['reasoning_tokens' => 0],
        ], null);

        $this->assertSame(0, $shaped['reasoning_tokens']);
        $this->assertNotNull($shaped['reasoning_tokens']);
    }

    /**
     * No usage object at all means no usage record.
     */
    public function test_shape_usage_returns_null_without_a_usage_object(): void {
        $p = new \local_ai_course_assistant\provider\openai_provider(['apikey' => 'x', 'model' => 'gpt-4o-mini']);

        $this->assertNull($this->shape($p, null, 'gpt-4o-mini'));
        $this->assertNull($this->shape($p, [], 'gpt-4o-mini'));
    }

    /**
     * The previously-captured counters must survive the refactor into one
     * shared shaper -- this is a spend pipeline, so a silent regression in
     * prompt/cached tokens would be as costly as the gap being closed.
     */
    public function test_shape_usage_still_carries_the_pre_existing_counters(): void {
        $p = new \local_ai_course_assistant\provider\openai_provider(['apikey' => 'x', 'model' => 'gpt-4o-mini']);

        $shaped = $this->shape($p, [
            'prompt_tokens'         => 2048,
            'completion_tokens'     => 256,
            'prompt_tokens_details' => ['cached_tokens' => 1024],
        ], 'gpt-4o-mini');

        $this->assertSame(2048, $shaped['prompt_tokens']);
        $this->assertSame(256, $shaped['completion_tokens']);
        $this->assertSame(1024, $shaped['cached_tokens']);
        $this->assertSame('gpt-4o-mini', $shaped['model']);
    }

    /**
     * Falls back to the configured model when the response names none.
     */
    public function test_shape_usage_falls_back_to_the_configured_model(): void {
        $p = new \local_ai_course_assistant\provider\openai_provider(['apikey' => 'x', 'model' => 'gpt-4o-mini']);

        $shaped = $this->shape($p, ['prompt_tokens' => 1, 'completion_tokens' => 1], '');

        $this->assertSame('gpt-4o-mini', $shaped['model']);
    }

    /**
     * Gemini is the provider this change exists for: it reaches SOLA through
     * gemini_provider, which extends openai_compatible_provider, so it must
     * inherit the same shaper rather than needing its own.
     */
    public function test_gemini_inherits_the_shared_shaper(): void {
        $p = new \local_ai_course_assistant\provider\gemini_provider(
            ['apikey' => 'x', 'model' => 'gemini-2.5-flash']
        );

        $shaped = $this->shape($p, [
            'prompt_tokens'     => 900,
            'completion_tokens' => 350,
            'completion_tokens_details' => ['reasoning_tokens' => 1430],
        ], 'gemini-2.5-flash');

        $this->assertSame(1430, $shaped['reasoning_tokens']);
        $this->assertSame(350, $shaped['completion_tokens']);
    }

    /**
     * Both call paths must read usage through the one shaper. Usage capture
     * lived only on the streaming path until v7.0.6, which is exactly how the
     * two drifted; a single reader is what stops it recurring.
     */
    public function test_both_call_paths_use_the_shared_shaper(): void {
        $src = file_get_contents(
            __DIR__ . '/../classes/provider/openai_compatible_provider.php'
        );

        $this->assertSame(
            1,
            substr_count($src, 'protected function shape_usage('),
            'there must be exactly one usage shaper'
        );
        $this->assertSame(
            2,
            substr_count($src, '$this->shape_usage('),
            'both chat_completion() and chat_completion_stream() must go through it'
        );
        $this->assertStringNotContainsString(
            "'cached_tokens'     => (int) (\$data[",
            $src,
            'the non-streaming path must not re-inline its own usage shaping'
        );
    }

    // ------------------------------------------------------------ persistence.

    /**
     * The column round-trips through add_message() on an assistant row.
     */
    public function test_reasoning_tokens_round_trip_on_assistant_row(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $id = conversation_manager::add_message(
            42,
            (int) $user->id,
            (int) $course->id,
            'assistant',
            'answer',
            0,
            'gemini',
            900,
            350,
            'gemini-2.5-flash',
            'chat',
            null,
            null,
            null,
            'complete',
            null,
            null,
            1430
        );

        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $id]);
        $this->assertEquals(1430, (int) $row->reasoning_tokens);
        // Still separate from, never added into, completion_tokens.
        $this->assertEquals(350, (int) $row->completion_tokens);
    }

    /**
     * Null when the caller passes nothing, which is every existing call site.
     */
    public function test_reasoning_tokens_null_when_not_supplied(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $id = conversation_manager::add_message(
            42,
            (int) $user->id,
            (int) $course->id,
            'assistant',
            'answer',
            0,
            'openai',
            10,
            5,
            'gpt-4o-mini'
        );

        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $id]);
        $this->assertNull($row->reasoning_tokens);
    }

    /**
     * A learner's own message pre-dates the provider call, so it can never
     * carry a reasoning count even if one is passed.
     */
    public function test_reasoning_tokens_never_stored_on_user_row(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $id = conversation_manager::add_message(
            42,
            (int) $user->id,
            (int) $course->id,
            'user',
            'question',
            0,
            '',
            10,
            0,
            null,
            'chat',
            null,
            null,
            null,
            null,
            null,
            null,
            999
        );

        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $id]);
        $this->assertNull($row->reasoning_tokens);
    }

    /**
     * System cost-log rows -- the ones the ancillary endpoints write -- MUST
     * keep the counter. Nulling it there would re-create the same undercount
     * one layer down.
     */
    public function test_reasoning_tokens_stored_on_system_cost_row(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $id = conversation_manager::add_message(
            42,
            (int) $user->id,
            (int) $course->id,
            'system',
            '[Flashcards] 5 card(s)',
            0,
            'gemini',
            800,
            200,
            'gemini-2.5-flash',
            'flashcards',
            null,
            null,
            64,
            null,
            null,
            null,
            777
        );

        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $id]);
        $this->assertEquals(777, (int) $row->reasoning_tokens);
        $this->assertEquals(64, (int) $row->cached_tokens);
        $this->assertSame('gemini', $row->provider);
        $this->assertSame('gemini-2.5-flash', $row->model_name);
    }

    // ------------------------------------------------------- ancillary logger.

    /**
     * A user with no conversation yet must not blow up the feature being
     * measured -- and the spend row must still land, because insights is run
     * by teachers who may never have opened the chat drawer.
     */
    public function test_log_ancillary_usage_survives_a_missing_conversation(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $this->assertSame(
            0,
            $DB->count_records('local_ai_course_assistant_convs', ['userid' => $user->id])
        );

        conversation_manager::log_ancillary_usage(
            $this->fake_provider([
                'prompt_tokens'     => 5000,
                'completion_tokens' => 600,
                'model'             => 'gemini-2.5-flash',
                'cached_tokens'     => 0,
                'reasoning_tokens'  => 2400,
            ]),
            (int) $user->id,
            (int) $course->id,
            'insights',
            '[Insights] course report'
        );

        $row = $DB->get_record('local_ai_course_assistant_msgs', [
            'userid'   => $user->id,
            'courseid' => $course->id,
            'role'     => 'system',
        ]);
        $this->assertNotFalse($row, 'the cost row must be written even with no prior conversation');
        $this->assertSame('insights', $row->interaction_type);
        $this->assertEquals(5000, (int) $row->prompt_tokens);
        $this->assertEquals(600, (int) $row->completion_tokens);
        $this->assertEquals(2400, (int) $row->reasoning_tokens);
        $this->assertSame('gemini-2.5-flash', $row->model_name);
    }

    /**
     * Telemetry must never be able to fail the learner-facing call: a provider
     * that reports nothing, or is not a provider at all, is a no-op.
     */
    public function test_log_ancillary_usage_is_silent_when_there_is_nothing_to_log(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        conversation_manager::log_ancillary_usage(
            $this->fake_provider(null),
            (int) $user->id,
            (int) $course->id,
            'essay',
            '[Essay] feedback'
        );
        conversation_manager::log_ancillary_usage(
            null,
            (int) $user->id,
            (int) $course->id,
            'essay',
            '[Essay] feedback'
        );
        conversation_manager::log_ancillary_usage(
            new \stdClass(),
            (int) $user->id,
            (int) $course->id,
            'essay',
            '[Essay] feedback'
        );

        $this->assertSame(
            0,
            $DB->count_records('local_ai_course_assistant_msgs', ['courseid' => $course->id]),
            'nothing billable was reported, so nothing may be written'
        );
    }

    /**
     * Anthropic-shaped usage reports cache_read_tokens rather than
     * cached_tokens; the logger coalesces both, as sse.php and
     * generate_quiz.php already do.
     */
    public function test_log_ancillary_usage_coalesces_the_two_cache_counters(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        conversation_manager::log_ancillary_usage(
            $this->fake_provider([
                'prompt_tokens'      => 100,
                'completion_tokens'  => 20,
                'model'              => 'claude-sonnet-4-20250514',
                'cache_read_tokens'  => 88,
            ]),
            (int) $user->id,
            (int) $course->id,
            'flashcards',
            '[Flashcards] 5 card(s)'
        );

        $row = $DB->get_record('local_ai_course_assistant_msgs', [
            'userid'   => $user->id,
            'courseid' => $course->id,
            'role'     => 'system',
        ]);
        $this->assertNotFalse($row);
        $this->assertEquals(88, (int) $row->cached_tokens);
        $this->assertNull($row->reasoning_tokens, 'this provider reported no thinking');
    }

    /**
     * The three previously-silent endpoints must actually call the logger.
     */
    public function test_the_three_ancillary_endpoints_log_their_usage(): void {
        foreach (['generate_flashcards', 'score_essay', 'generate_insights'] as $endpoint) {
            $src = file_get_contents(__DIR__ . '/../classes/external/' . $endpoint . '.php');
            $this->assertStringContainsString(
                'log_ancillary_usage(',
                $src,
                $endpoint . ' must persist the usage of the provider call it makes'
            );
        }
    }
}
