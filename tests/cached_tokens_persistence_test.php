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
 * v6.1.0 tests for cached-token persistence.
 *
 * v5.11.0 captured OpenAI's prompt_tokens_details.cached_tokens (and
 * Anthropic's cache_read_input_tokens) in the provider layer but never
 * persisted them, so the prompt-cache hit rate was unobservable in token
 * analytics. v6.1.0 adds the `cached_tokens` column plus an optional
 * add_message() parameter. These tests pin the write-path contract:
 * stored on assistant rows, never on user rows, null when not reported.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\conversation_manager::add_message
 */
final class cached_tokens_persistence_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_cached_tokens_stored_on_assistant_row(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $id = conversation_manager::add_message(
            42, (int) $user->id, (int) $course->id, 'assistant', 'answer',
            0, 'openai', 1500, 300, 'gpt-4o-mini', 'chat', null, null, 1024
        );
        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $id]);
        $this->assertEquals(1024, (int) $row->cached_tokens);
    }

    public function test_cached_tokens_null_when_not_reported(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $id = conversation_manager::add_message(
            42, (int) $user->id, (int) $course->id, 'assistant', 'answer',
            0, 'gemini', 1500, 300, 'gemini-2.5-flash', 'chat', null, null, null
        );
        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $id]);
        // Null (provider reported nothing) must stay distinguishable from 0
        // (cache supported but missed) for honest hit-rate percentages.
        $this->assertNull($row->cached_tokens);
    }

    public function test_cached_tokens_never_stored_on_user_row(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $id = conversation_manager::add_message(
            42, (int) $user->id, (int) $course->id, 'user', 'question',
            0, '', null, null, null, 'chat', null, null, 9999
        );
        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $id]);
        $this->assertNull($row->cached_tokens);
    }

    public function test_zero_cached_tokens_stored_as_zero_not_null(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $id = conversation_manager::add_message(
            42, (int) $user->id, (int) $course->id, 'assistant', 'answer',
            0, 'openai', 1500, 300, 'gpt-4o-mini', 'chat', null, null, 0
        );
        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $id]);
        $this->assertSame(0, (int) $row->cached_tokens);
        $this->assertNotNull($row->cached_tokens);
    }
}
