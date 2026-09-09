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
 * v7.4.2: reasoning tokens must be PRICED, not merely stored.
 *
 * Capturing thinking tokens into msgs.reasoning_tokens closes the write side of
 * the undercount. It closes nothing at all unless a consumer reads the column:
 * the reconciliation that prompted this work measured 0.35M completion tokens
 * logged against 1.78M billed, and a build that stores the missing ~1.4M but
 * still prices output as SUM(completion_tokens) reports the identical floor it
 * reported before, now with unpriced_rows=0 asserting the figure is sound.
 *
 * The rule being pinned is vendor-specific and is the whole subtlety:
 *   - Google's Gemini OpenAI-compat shim reports thinking OUTSIDE
 *     completion_tokens while billing it at the output rate, so pricing it
 *     requires completion + reasoning.
 *   - OpenAI already counts reasoning INSIDE completion_tokens, so adding it
 *     would double-charge every call.
 * Both directions are asserted, because either one alone permits a "fix" that
 * breaks the other.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\token_cost_manager::estimate_cost
 */
final class reasoning_token_pricing_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Gemini: thinking is billed on top of completion_tokens.
     */
    public function test_gemini_prices_reasoning_as_extra_output(): void {
        $without = token_cost_manager::estimate_cost('gemini-2.5-flash', 0, 1_000_000, 0);
        $with    = token_cost_manager::estimate_cost('gemini-2.5-flash', 0, 1_000_000, 1_000_000);

        $this->assertNotNull($without, 'gemini-2.5-flash must be in the rate card');
        // Output rate is 2.50/1M, so 1M completion = $2.50 and +1M thinking = $5.00.
        $this->assertEqualsWithDelta(2.50, $without, 0.000001);
        $this->assertEqualsWithDelta(5.00, $with, 0.000001,
            'Gemini thinking tokens are billed at the output rate and are NOT inside '
            . 'the reported completion_tokens, so they must be added');
    }

    /**
     * OpenAI: reasoning is already inside completion_tokens. Adding it again
     * would double-charge, so the figure must not move.
     */
    public function test_openai_does_not_double_charge_reasoning(): void {
        $without = token_cost_manager::estimate_cost('gpt-4o-mini', 0, 100_000, 0);
        $with    = token_cost_manager::estimate_cost('gpt-4o-mini', 0, 100_000, 100_000);

        $this->assertNotNull($without);
        $this->assertEqualsWithDelta((float) $without, (float) $with, 0.000001,
            'OpenAI counts reasoning inside completion_tokens; adding it again double-charges');
    }

    /**
     * The vendor rule itself.
     */
    public function test_reasoning_rule_is_per_model(): void {
        $this->assertTrue(token_cost_manager::reasoning_billed_as_extra_output('gemini-2.5-flash'));
        $this->assertTrue(token_cost_manager::reasoning_billed_as_extra_output('GEMINI-2.5-FLASH'));
        $this->assertFalse(token_cost_manager::reasoning_billed_as_extra_output('gpt-4o-mini'));
        $this->assertFalse(token_cost_manager::reasoning_billed_as_extra_output('claude-sonnet-5'));
        // An unknown model defaults to "already counted", the safe direction:
        // it under-reports rather than inventing spend for a vendor whose
        // behaviour nobody has confirmed.
        $this->assertFalse(token_cost_manager::reasoning_billed_as_extra_output('some-future-model'));
    }

    /**
     * The reconciliation shape, end to end through the dashboard contract.
     *
     * 0.35M completion + 1.43M thinking is the measured month. Pricing
     * completion alone is the floor the Cloud Run shim reported; the endpoint
     * that replaces it must report the full figure.
     */
    public function test_monthly_provider_spend_includes_thinking(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $this->write_assistant_row((int) $user->id, (int) $course->id, 'gemini-2.5-flash', 'google',
            100_000, 350_000, 1_430_000);

        $spend = analytics::get_monthly_provider_spend(date('Y-m'));
        $this->assertArrayHasKey('google', $spend['by_provider']);

        $floor = token_cost_manager::estimate_cost('gemini-2.5-flash', 100_000, 350_000, 0);
        $truth = token_cost_manager::estimate_cost('gemini-2.5-flash', 100_000, 350_000, 1_430_000);

        $this->assertEqualsWithDelta((float) $truth, $spend['by_provider']['google'], 0.000001,
            'the dashboard figure still prices completion_tokens alone; thinking is unbilled');
        $this->assertGreaterThan((float) $floor, $spend['by_provider']['google'],
            'thinking tokens made no difference, so the column is written but unread');
    }

    /**
     * The spend cap must see the same money the dashboard sees, or a site on a
     * thinking model burns its budget without ever tripping a warning.
     */
    public function test_spend_guard_counts_thinking(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $this->write_assistant_row((int) $user->id, (int) $course->id, 'gemini-2.5-flash', 'google',
            100_000, 350_000, 1_430_000);

        // get_spend() is the public entry point; compute_spend() is its private core.
        $guarded = spend_guard::get_spend();
        $floor = (float) token_cost_manager::estimate_cost('gemini-2.5-flash', 100_000, 350_000, 0);

        $this->assertGreaterThan($floor, $guarded,
            'spend_guard prices completion alone, so a Gemini site spends multiples of its cap silently');
    }

    /**
     * Insert one priced assistant row.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $model
     * @param string $provider
     * @param int $prompt
     * @param int $completion
     * @param int $reasoning
     * @return void
     */
    private function write_assistant_row(
        int $userid,
        int $courseid,
        string $model,
        string $provider,
        int $prompt,
        int $completion,
        int $reasoning
    ): void {
        global $DB;
        $row = new \stdClass();
        $row->conversationid    = 1;
        $row->userid            = $userid;
        $row->courseid          = $courseid;
        $row->role              = 'assistant';
        $row->message           = 'answer';
        $row->tokens_used       = $prompt + $completion;
        $row->prompt_tokens     = $prompt;
        $row->completion_tokens = $completion;
        $row->reasoning_tokens  = $reasoning;
        $row->model_name        = $model;
        $row->provider          = $provider;
        $row->interaction_type  = 'chat';
        $row->timecreated       = time();
        $DB->insert_record('local_ai_course_assistant_msgs', $row);
    }
}
