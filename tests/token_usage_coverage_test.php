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

use local_ai_course_assistant\provider\openai_compatible_provider;

/**
 * Token usage must be recorded on BOTH provider paths, and must include
 * thinking tokens.
 *
 * Two gaps made SOLA's reported spend a floor rather than a measurement:
 * usage was captured only in the streaming branch, so thirteen non-streaming
 * callers recorded nothing; and completion_tokens_details.reasoning_tokens
 * was never read, so thinking output was invisible on the one path that did
 * record. A July reconciliation showed 0.35M completion tokens logged against
 * 1.78M billed on Gemini.
 *
 * @covers \local_ai_course_assistant\provider\openai_compatible_provider::extract_usage
 */
final class token_usage_coverage_test extends \advanced_testcase {

    /** Reach the protected extractor. */
    private function extract(array $usage, ?string $model = null): ?array {
        $provider = new class(['apikey' => 'k', 'model' => 'configured-model'])
            extends openai_compatible_provider {
            public function call_extract(array $usage, ?string $model): ?array {
                return $this->extract_usage($usage, $model);
            }
        };
        return $provider->call_extract($usage, $model);
    }

    public function test_reasoning_tokens_are_captured(): void {
        $this->resetAfterTest();
        $out = $this->extract([
            'prompt_tokens' => 1000,
            'completion_tokens' => 200,
            'completion_tokens_details' => ['reasoning_tokens' => 1500],
        ]);
        $this->assertSame(1500, $out['reasoning_tokens'],
            'thinking tokens must be recorded; on Gemini they are billed as output');
        $this->assertSame(200, $out['completion_tokens'],
            'completion_tokens must be stored exactly as reported, not adjusted');
    }

    public function test_missing_reasoning_details_report_zero_not_null(): void {
        $this->resetAfterTest();
        $out = $this->extract(['prompt_tokens' => 10, 'completion_tokens' => 5]);
        $this->assertSame(0, $out['reasoning_tokens']);
    }

    public function test_cached_tokens_still_captured(): void {
        $this->resetAfterTest();
        $out = $this->extract([
            'prompt_tokens' => 100,
            'completion_tokens' => 10,
            'prompt_tokens_details' => ['cached_tokens' => 80],
        ]);
        $this->assertSame(80, $out['cached_tokens'], 'the v6.1.0 field must not regress');
    }

    public function test_model_comes_from_the_response_not_the_default(): void {
        $this->resetAfterTest();
        // Quiz generation resolves its own model; recording the configured
        // default instead is why model_name only ever showed the chat model.
        $out = $this->extract(['prompt_tokens' => 1, 'completion_tokens' => 1], 'gemini-3.6-flash');
        $this->assertSame('gemini-3.6-flash', $out['model']);
    }

    public function test_model_falls_back_to_the_configured_one(): void {
        $this->resetAfterTest();
        $out = $this->extract(['prompt_tokens' => 1, 'completion_tokens' => 1], null);
        $this->assertSame('configured-model', $out['model']);
    }

    public function test_empty_usage_returns_null_not_a_zero_row(): void {
        $this->resetAfterTest();
        // A zero row would read as "this call was free" rather than
        // "this call was not measured" — the distinction the whole fix is about.
        $this->assertNull($this->extract([]));
    }

    /** The column exists and round-trips through add_message. */
    public function test_reasoning_tokens_persist_on_the_message_row(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $convid = conversation_manager::get_or_create_conversation($user->id, $course->id);
        $msgid = conversation_manager::add_message(
            $convid, $user->id, $course->id, 'assistant', 'hi',
            0, 'gemini', 1000, 200, 'gemini-2.5-flash', 'chat', null, null, 80, 1500
        );

        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $msgid]);
        $this->assertEquals(1500, (int) $row->reasoning_tokens);
        $this->assertEquals(80, (int) $row->cached_tokens);
        $this->assertEquals(200, (int) $row->completion_tokens);
    }

    /** A user row must never carry usage, mirroring cached_tokens. */
    public function test_user_rows_carry_no_usage(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $convid = conversation_manager::get_or_create_conversation($user->id, $course->id);
        $msgid = conversation_manager::add_message(
            $convid, $user->id, $course->id, 'user', 'question',
            0, '', 10, 0, 'm', 'chat', null, null, 5, 99
        );
        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $msgid]);
        $this->assertNull($row->reasoning_tokens);
    }
}
