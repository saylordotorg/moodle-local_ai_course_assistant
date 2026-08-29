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
 * The prompt budget is derived from the model's context window.
 *
 * A fixed character budget has been wrong twice: 12,000 could not hold the
 * prompt at all, and 24,000 cleared a real turn by 46 characters. Both numbers
 * were set by measuring a single configuration. These tests pin the properties
 * that make the derived budget safe rather than pinning another number.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\context_builder::resolve_budget_chars
 * @covers     \local_ai_course_assistant\context_builder::resolve_window_tokens
 */
final class prompt_budget_window_test extends \advanced_testcase {

    /**
     * Reset config between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        set_config('backend_context_tokens', 0, 'local_ai_course_assistant');
        set_config('prompt_budget_mode', 'auto', 'local_ai_course_assistant');
    }

    /**
     * A model in the table yields its window; anything else yields nothing.
     */
    public function test_known_models_resolve_a_window(): void {
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');
        $this->assertSame(1048576, context_builder::resolve_window_tokens(0));

        set_config('model', 'gpt-4o-mini', 'local_ai_course_assistant');
        $this->assertSame(128000, context_builder::resolve_window_tokens(0));

        // Dated and regional variants must resolve through their family prefix.
        set_config('model', 'gemini-2.5-flash-002', 'local_ai_course_assistant');
        $this->assertSame(1048576, context_builder::resolve_window_tokens(0));

        // The longest prefix wins, so sonnet-5 is not read as a shorter entry.
        set_config('model', 'claude-sonnet-5', 'local_ai_course_assistant');
        $this->assertSame(1000000, context_builder::resolve_window_tokens(0));

        set_config('model', 'llama-3-70b-instruct', 'local_ai_course_assistant');
        $this->assertSame(0, context_builder::resolve_window_tokens(0));
    }

    /**
     * An operator statement about a self-hosted backend outranks the table.
     */
    public function test_explicit_backend_window_wins(): void {
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');
        set_config('backend_context_tokens', 8192, 'local_ai_course_assistant');
        $this->assertSame(8192, context_builder::resolve_window_tokens(0));
    }

    /**
     * An unknown model must never move the budget in either direction.
     *
     * This is the property that makes the feature safe to ship on by default:
     * a site running a model we have never heard of behaves exactly as it did
     * before the derivation existed.
     */
    public function test_unknown_model_leaves_the_configured_budget_alone(): void {
        set_config('model', 'some-model-we-have-never-seen', 'local_ai_course_assistant');
        $this->assertSame(36000, context_builder::resolve_budget_chars(36000, 0, 'en'));
        $this->assertSame(12000, context_builder::resolve_budget_chars(12000, 0, 'en'));
    }

    /**
     * The window table must not overstate a model's context.
     *
     * Claiming a million tokens for a 200k model is the direction that breaks
     * things, and the table reads as a reference someone will copy.
     */
    public function test_base_claude_4_models_are_200k_not_1m(): void {
        set_config('model', 'claude-sonnet-4', 'local_ai_course_assistant');
        $this->assertSame(200000, context_builder::resolve_window_tokens(0));

        set_config('model', 'claude-opus-4', 'local_ai_course_assistant');
        $this->assertSame(200000, context_builder::resolve_window_tokens(0));

        // The 4.6+ variants really are 1M, and longest-prefix must reach them.
        set_config('model', 'claude-sonnet-4-6', 'local_ai_course_assistant');
        $this->assertSame(1000000, context_builder::resolve_window_tokens(0));

        set_config('model', 'claude-opus-4-8', 'local_ai_course_assistant');
        $this->assertSame(1000000, context_builder::resolve_window_tokens(0));
    }

    /**
     * A large window lifts the budget well past the character count that a real
     * four-chunk BUS 101 turn assembled to on dev (27,991 characters).
     */
    public function test_large_window_lifts_the_budget_above_the_measured_turn(): void {
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');
        $budget = context_builder::resolve_budget_chars(36000, 0, 'en');
        $this->assertGreaterThan(36000, $budget);
        $this->assertGreaterThan(27991 * 2, $budget);
    }

    /**
     * The derived budget is capped: a million-token window does not license a
     * million-character prompt, which is billed on every single message.
     */
    public function test_derived_budget_is_capped(): void {
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');
        $this->assertLessThanOrEqual(120000, context_builder::resolve_budget_chars(36000, 0, 'en'));
    }

    /**
     * A number an administrator typed wins, in both directions.
     *
     * Treating the setting only as a floor would silently ignore the site that
     * lowered it to hold per-turn cost down -- the setting's own description
     * promises that lowering it reduces cost, and a derive step that only ever
     * raised would make that promise false with nothing on screen to say so.
     */
    public function test_an_explicit_setting_is_authoritative_both_ways(): void {
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');

        // Raised deliberately: kept.
        $this->assertSame(200000, context_builder::resolve_budget_chars(200000, 0, 'en'));

        // Lowered deliberately, to control cost: also kept.
        $this->assertSame(12000, context_builder::resolve_budget_chars(12000, 0, 'en'));

        // Left at the shipped default: the model's window decides.
        $this->assertGreaterThan(
            context_builder::DEFAULT_BUDGET_CHARS,
            context_builder::resolve_budget_chars(context_builder::DEFAULT_BUDGET_CHARS, 0, 'en')
        );
    }

    /**
     * A small self-hosted window clamps the derived value.
     */
    public function test_small_window_clamps_the_derived_budget(): void {
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');
        set_config('backend_context_tokens', 4096, 'local_ai_course_assistant');
        $this->assertSame(
            context_builder::DEFAULT_BUDGET_CHARS,
            context_builder::resolve_budget_chars(context_builder::DEFAULT_BUDGET_CHARS, 0, 'en')
        );
    }

    /**
     * Fixed mode opts out entirely, for a site that wants the old behaviour.
     */
    public function test_fixed_mode_uses_the_setting_verbatim(): void {
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');
        set_config('prompt_budget_mode', 'fixed', 'local_ai_course_assistant');
        $this->assertSame(36000, context_builder::resolve_budget_chars(36000, 0, 'en'));
    }
}
