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
 * A failover entry must not inherit the primary provider's model.
 *
 * Found on production configuration, 2026-09-01. Both Saylor sites run
 * provider=gemini, model=gemini-2.5-flash, spend_failover_chain=chat:openai.
 * The chain built its openai fallback from the same overrides as the primary,
 * so the fallback asked OpenAI for "gemini-2.5-flash" and got HTTP 404 "model
 * not found". The failover could therefore never succeed -- the safety net was
 * decorative -- and the error blamed a model name that was entirely correct.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\spend_guard::resolve_failover_chain
 */
final class failover_model_remap_test extends \advanced_testcase {

    /**
     * The third column of a comparison_providers row is its model list, and it
     * must reach the failover entry so the fallback has a model of its own.
     */
    public function test_chain_entry_carries_its_own_model(): void {
        $this->resetAfterTest();
        set_config('comparison_providers', "openai|sk-test-key|gpt-4o-mini,gpt-4o|0.5", 'local_ai_course_assistant');
        set_config('spend_failover_chain', 'chat:openai', 'local_ai_course_assistant');

        $chain = spend_guard::resolve_failover_chain('chat');

        $this->assertCount(1, $chain, 'the chat failover chain did not resolve');
        $this->assertSame('openai', $chain[0]['provider']);
        $this->assertArrayHasKey('model', $chain[0], 'chain entry has no model of its own');
        $this->assertSame(
            'gpt-4o-mini',
            $chain[0]['model'],
            'the first model of the row should become the fallback model'
        );
    }

    /**
     * A row with no model column still resolves; the caller then falls back to
     * the provider's own default rather than the primary's model.
     */
    public function test_chain_entry_without_model_column_is_empty_not_inherited(): void {
        $this->resetAfterTest();
        set_config('comparison_providers', "openai|sk-test-key", 'local_ai_course_assistant');
        set_config('spend_failover_chain', 'chat:openai', 'local_ai_course_assistant');
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');

        $chain = spend_guard::resolve_failover_chain('chat');

        $this->assertCount(1, $chain);
        $this->assertSame('', $chain[0]['model'] ?? null,
            'an absent model column must not silently inherit the primary model');
        $this->assertNotSame('gemini-2.5-flash', $chain[0]['model'] ?? null);
    }
}
