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
use local_ai_course_assistant\external\generate_flashcards;
use local_ai_course_assistant\external\score_essay;
use local_ai_course_assistant\external\generate_insights;
use local_ai_course_assistant\provider\stub_provider;

/**
 * Contract tests for the LLM-calling external services (v5.3.26).
 *
 * Wires the stub provider via set_config('provider', 'stub') so the four
 * services that call base_provider::create_from_config()->chat_completion()
 * exercise their full pipeline (parameter validation, capability checks,
 * prompt construction, response parsing, contract round-trip) without an
 * upstream API call. The stub returns canned JSON shaped for each detected
 * prompt category, and tests can override per-call via
 * stub_provider::program_response() to exercise parse-error and
 * provider-throw branches.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class external_llm_calls_test extends \advanced_testcase {

    /**
     * Common setup: every test resets stub state, enables the stub provider,
     * and creates a fresh enrolled student. Tests that need admin override
     * with setAdminUser().
     *
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function setup_with_stub(): array {
        $this->resetAfterTest();
        stub_provider::reset();
        set_config('provider', 'stub', 'local_ai_course_assistant');
        set_config('apikey', 'stub-key', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        return [$course, $user];
    }

    // ───────────────────────────────────────────────────────────
    // generate_quiz
    // ───────────────────────────────────────────────────────────

    public function test_generate_quiz_default_returns_three_questions(): void {
        [$course, $user] = $this->setup_with_stub();

        $result = generate_quiz::execute((int)$course->id);

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['questions']);
        $this->assertEquals('A', $result['questions'][0]['correct']);
        $this->assertNotEmpty($result['questions'][0]['question']);
        // Verify provider was actually called and saw a quiz-shaped prompt.
        $this->assertCount(1, stub_provider::$calls);
        $this->assertEquals('quiz', stub_provider::$calls[0]['kind']);
    }

    public function test_generate_quiz_clean_returnvalue_round_trip(): void {
        [$course, $user] = $this->setup_with_stub();

        $result = generate_quiz::execute((int)$course->id);
        $clean = \core_external\external_api::clean_returnvalue(
            generate_quiz::execute_returns(), $result);
        $this->assertEquals($result, $clean);
    }

    public function test_generate_quiz_parse_error_when_response_is_garbage(): void {
        [$course, $user] = $this->setup_with_stub();
        // Program a non-JSON response. The service must report parse failure
        // rather than crash or return half-built questions.
        stub_provider::program_response('quiz', 'just a plain sentence with no json');

        $result = generate_quiz::execute((int)$course->id);

        $this->assertFalse($result['success']);
        $this->assertEquals([], $result['questions']);
        $this->assertNotEmpty($result['error']);
    }

    public function test_generate_quiz_provider_error_returns_failure(): void {
        [$course, $user] = $this->setup_with_stub();
        stub_provider::$throw_next = new \moodle_exception('stub-network-down');

        $result = generate_quiz::execute((int)$course->id);

        $this->assertFalse($result['success']);
        $this->assertEquals([], $result['questions']);
        $this->assertStringContainsString('stub-network-down', $result['error']);
    }

    public function test_generate_quiz_count_is_clamped(): void {
        [$course, $user] = $this->setup_with_stub();
        // Out-of-range count must be clamped 1-10 before reaching the prompt.
        $result = generate_quiz::execute((int)$course->id, 99);

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['questions'], 'Stub returns 3; clamping is observed in the prompt construction.');
        // The system prompt should reflect the clamped count of 10, not 99.
        $this->assertStringContainsString('10', stub_provider::$calls[0]['systemprompt']);
    }

    public function test_generate_quiz_extracts_from_quiz_tag_wrapper(): void {
        [$course, $user] = $this->setup_with_stub();
        // Some providers honour the "wrap in <quiz>...</quiz>" instruction.
        // The parser falls back to extracting that block when the outer
        // content isn't pure JSON.
        $payload = json_encode([
            'topic' => 'Wrapped',
            'questions' => [[
                'id' => 1, 'question' => 'Wrapped Q?',
                'choices' => ['A) a', 'B) b', 'C) c', 'D) d'],
                'correct' => 'D', 'explanation' => 'D wins.',
            ]],
        ]);
        stub_provider::program_response('quiz', "Sure, here you go:\n<quiz>{$payload}</quiz>");

        $result = generate_quiz::execute((int)$course->id, 1);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['questions']);
        $this->assertEquals('Wrapped', $result['topic']);
    }

    public function test_generate_quiz_strips_invalid_question_rows(): void {
        [$course, $user] = $this->setup_with_stub();
        // Question 2 is missing 'correct' — it must be silently dropped.
        $payload = json_encode([
            'topic' => 'Mixed',
            'questions' => [
                ['id' => 1, 'question' => 'Q1?', 'choices' => ['A','B','C','D'],
                 'correct' => 'A', 'explanation' => 'e1'],
                ['id' => 2, 'question' => 'Q2?', 'choices' => ['A','B']],
                ['id' => 3, 'question' => 'Q3?', 'choices' => ['A','B','C','D'],
                 'correct' => 'C', 'explanation' => 'e3'],
            ],
        ]);
        stub_provider::program_response('quiz', $payload);

        $result = generate_quiz::execute((int)$course->id);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['questions']);
    }

    public function test_generate_quiz_rejects_unenrolled_user(): void {
        $this->resetAfterTest();
        stub_provider::reset();
        set_config('provider', 'stub', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        // Not enrolled — validate_context throws require_login before
        // reaching the capability gate. Either rejection is acceptable;
        // assert against the moodle_exception parent so this test is
        // robust to whichever guard fires first.
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        generate_quiz::execute((int)$course->id);
    }

    // ───────────────────────────────────────────────────────────
    // generate_flashcards
    // ───────────────────────────────────────────────────────────

    public function test_generate_flashcards_disabled_returns_early(): void {
        [$course, $user] = $this->setup_with_stub();
        set_config('flashcards_enabled', 0, 'local_ai_course_assistant');

        $result = generate_flashcards::execute((int)$course->id, 0, 5);

        $this->assertFalse($result['success']);
        $this->assertEquals('flashcards_disabled', $result['message']);
        $this->assertEmpty($result['cards']);
        // Provider must NOT have been called.
        $this->assertCount(0, stub_provider::$calls);
    }

    public function test_generate_flashcards_no_page_content_returns_early(): void {
        [$course, $user] = $this->setup_with_stub();
        set_config('flashcards_enabled', 1, 'local_ai_course_assistant');

        // cmid=0 means no current page. The service must short-circuit with
        // 'no_page_content' rather than send an empty SOURCE block to the LLM.
        $result = generate_flashcards::execute((int)$course->id, 0, 5);

        $this->assertFalse($result['success']);
        $this->assertEquals('no_page_content', $result['message']);
        $this->assertCount(0, stub_provider::$calls);
    }

    public function test_generate_flashcards_happy_path_with_real_page(): void {
        [$course, $user] = $this->setup_with_stub();
        set_config('flashcards_enabled', 1, 'local_ai_course_assistant');

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Stub page',
            'content' => str_repeat('Photosynthesis turns light into sugar. ', 20),
        ]);

        $result = generate_flashcards::execute((int)$course->id, (int)$page->cmid, 3);

        $this->assertTrue($result['success']);
        $this->assertEquals('ok', $result['message']);
        $this->assertCount(3, $result['cards']);
        $this->assertEquals('Stub front 1', $result['cards'][0]['question']);
        $this->assertEquals('flashcards', stub_provider::$calls[0]['kind']);
    }

    public function test_generate_flashcards_provider_error_returns_failure(): void {
        [$course, $user] = $this->setup_with_stub();
        set_config('flashcards_enabled', 1, 'local_ai_course_assistant');
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => str_repeat('Lorem ipsum dolor sit amet. ', 30),
        ]);
        stub_provider::$throw_next = new \moodle_exception('stub-503');

        $result = generate_flashcards::execute((int)$course->id, (int)$page->cmid, 3);

        $this->assertFalse($result['success']);
        $this->assertEquals('provider_error', $result['message']);
        $this->assertEmpty($result['cards']);
    }

    public function test_generate_flashcards_parse_error_returns_failure(): void {
        [$course, $user] = $this->setup_with_stub();
        set_config('flashcards_enabled', 1, 'local_ai_course_assistant');
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => str_repeat('Photosynthesis content here. ', 30),
        ]);
        stub_provider::program_response('flashcards', 'totally not json');

        $result = generate_flashcards::execute((int)$course->id, (int)$page->cmid, 3);

        $this->assertFalse($result['success']);
        $this->assertEquals('parse_error', $result['message']);
    }

    // ───────────────────────────────────────────────────────────
    // score_essay
    // ───────────────────────────────────────────────────────────

    public function test_score_essay_disabled_when_feature_off(): void {
        [$course, $user] = $this->setup_with_stub();
        set_config('essay_feedback_enabled', 0, 'local_ai_course_assistant');
        $essay = str_repeat('This is my essay paragraph with words. ', 5);

        $result = score_essay::execute((int)$course->id, $essay);

        $this->assertFalse($result['success']);
        $this->assertEquals('disabled', $result['message']);
        $this->assertCount(0, stub_provider::$calls);
    }

    public function test_score_essay_too_short_returns_early(): void {
        [$course, $user] = $this->setup_with_stub();
        set_config('essay_feedback_enabled', 1, 'local_ai_course_assistant');

        $result = score_essay::execute((int)$course->id, 'tiny.');

        $this->assertFalse($result['success']);
        $this->assertEquals('too_short', $result['message']);
        $this->assertCount(0, stub_provider::$calls);
    }

    public function test_score_essay_happy_path_returns_criteria(): void {
        [$course, $user] = $this->setup_with_stub();
        set_config('essay_feedback_enabled', 1, 'local_ai_course_assistant');
        $essay = str_repeat('I argue this thesis with three supporting points. ', 6);

        $result = score_essay::execute((int)$course->id, $essay);

        $this->assertTrue($result['success']);
        $this->assertEquals('ok', $result['message']);
        $this->assertCount(2, $result['criteria']);
        $this->assertEquals('Thesis', $result['criteria'][0]['name']);
        $this->assertCount(3, $result['revisions']);
    }

    public function test_score_essay_provider_error_returns_failure(): void {
        [$course, $user] = $this->setup_with_stub();
        set_config('essay_feedback_enabled', 1, 'local_ai_course_assistant');
        $essay = str_repeat('This is the long enough essay text. ', 5);
        stub_provider::$throw_next = new \moodle_exception('stub-essay-down');

        $result = score_essay::execute((int)$course->id, $essay);

        $this->assertFalse($result['success']);
        $this->assertEquals('provider_error', $result['message']);
    }

    public function test_score_essay_parse_error_returns_failure(): void {
        [$course, $user] = $this->setup_with_stub();
        set_config('essay_feedback_enabled', 1, 'local_ai_course_assistant');
        $essay = str_repeat('Long enough essay content here please. ', 5);
        stub_provider::program_response('essay', 'no json at all');

        $result = score_essay::execute((int)$course->id, $essay);

        $this->assertFalse($result['success']);
        $this->assertEquals('parse_error', $result['message']);
    }

    public function test_score_essay_clean_returnvalue_round_trip(): void {
        [$course, $user] = $this->setup_with_stub();
        set_config('essay_feedback_enabled', 1, 'local_ai_course_assistant');
        $essay = str_repeat('Round trip essay text content here for testing. ', 5);

        $result = score_essay::execute((int)$course->id, $essay);
        $clean = \core_external\external_api::clean_returnvalue(
            score_essay::execute_returns(), $result);
        $this->assertEquals($result, $clean);
    }

    // ───────────────────────────────────────────────────────────
    // generate_insights (admin-only)
    // ───────────────────────────────────────────────────────────

    public function test_generate_insights_requires_admin(): void {
        [$course, $user] = $this->setup_with_stub();

        $this->expectException(\required_capability_exception::class);
        generate_insights::execute((int)$course->id);
    }

    public function test_generate_insights_no_data_returns_friendly_message(): void {
        $this->resetAfterTest();
        stub_provider::reset();
        set_config('provider', 'stub', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        // No feedback / surveys / UT rows yet — the service must NOT call the
        // provider, since there's nothing to analyse.
        $result = generate_insights::execute((int)$course->id);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['insights']);
        $this->assertCount(0, stub_provider::$calls,
            'Provider must not be called when there is no data.');
    }

    public function test_generate_insights_with_feedback_calls_provider(): void {
        global $DB;
        $this->resetAfterTest();
        stub_provider::reset();
        set_config('provider', 'stub', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        // Seed a feedback row so the no-data short-circuit is bypassed.
        $DB->insert_record('local_ai_course_assistant_feedback', (object)[
            'userid' => 0, 'courseid' => $course->id,
            'rating' => 4, 'comment' => 'Helpful chat.',
            'browser' => 'Chrome', 'os' => 'macOS', 'device' => 'desktop',
            'screen_resolution' => '', 'user_agent' => '', 'page_url' => '',
            'timecreated' => time(),
        ]);

        $result = generate_insights::execute((int)$course->id);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Stub Insights', $result['insights']);
        $this->assertCount(1, stub_provider::$calls);
        $this->assertEquals('insights', stub_provider::$calls[0]['kind']);
    }

    public function test_generate_insights_provider_error_returns_failure(): void {
        global $DB;
        $this->resetAfterTest();
        stub_provider::reset();
        set_config('provider', 'stub', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $DB->insert_record('local_ai_course_assistant_feedback', (object)[
            'userid' => 0, 'courseid' => $course->id,
            'rating' => 4, 'comment' => 'Helpful chat.',
            'browser' => '', 'os' => '', 'device' => '',
            'screen_resolution' => '', 'user_agent' => '', 'page_url' => '',
            'timecreated' => time(),
        ]);
        stub_provider::$throw_next = new \moodle_exception('insights-network');

        $result = generate_insights::execute((int)$course->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('insights-network', $result['insights']);
    }

    // ───────────────────────────────────────────────────────────
    // stub provider self-test
    // ───────────────────────────────────────────────────────────

    public function test_stub_provider_streaming_emits_chunks(): void {
        [$course, $user] = $this->setup_with_stub();
        // Direct stub-provider exercise via the provider abstraction —
        // confirms streaming wraps the same canned response and chunks it.
        $provider = \local_ai_course_assistant\provider\base_provider::create_from_config((int)$course->id);
        $chunks = [];
        $provider->chat_completion_stream(
            'Generic chat system prompt',
            [['role' => 'user', 'content' => 'Hi']],
            function (string $c) use (&$chunks) { $chunks[] = $c; }
        );

        $this->assertNotEmpty($chunks);
        $this->assertEquals('Stub assistant reply.', implode('', $chunks));
    }

    public function test_stub_provider_token_usage_returns_fixed_values(): void {
        [$course, $user] = $this->setup_with_stub();
        $provider = \local_ai_course_assistant\provider\base_provider::create_from_config((int)$course->id);
        $provider->chat_completion_stream('p', [['role'=>'user','content'=>'q']], function () {});

        $usage = $provider->get_last_token_usage();
        $this->assertEquals(100, $usage['prompt_tokens']);
        $this->assertEquals(50, $usage['completion_tokens']);
        $this->assertEquals('stub-model', $usage['model']);
    }
}
