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

use local_ai_course_assistant\tests\prompt_metrics_seed;
use local_ai_course_assistant\tests\settings_tree;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/settings_tree.php');
require_once(__DIR__ . '/fixtures/prompt_metrics_seed.php');

/**
 * The prompt-truncation warning on the settings page appears only when cuts are frequent.
 *
 * Seen on Learn and Degrees, Oct 2026: the warning showed for a week after ONE cut in 5,129 turns, and told the
 * administrator to raise a number that, in automatic mode, would have replaced a 120,000-character window-derived
 * budget with a smaller fixed one.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\prompt_metrics_logger
 */
final class prompt_truncation_warning_test extends \advanced_testcase {

    /**
     * The text of the truncation notice on the settings tree, or null when it is not built.
     *
     * @return string|null
     */
    private function warning_text(): ?string {
        $this->setAdminUser();
        $category = settings_tree::category();
        foreach (settings_tree::pages($category) as $page) {
            foreach ($page->settings as $name => $setting) {
                if ($name === 'local_ai_course_assistantprompt_truncation_warning') {
                    return (string) $setting->description;
                }
            }
        }
        return null;
    }

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        set_config('prompt_truncation_seen', time() - HOURSECS, 'local_ai_course_assistant');
        set_config('prompt_budget_mode', 'auto', 'local_ai_course_assistant');
        set_config('model', 'gemini-2.5-flash', 'local_ai_course_assistant');
        set_config('backend_context_tokens', 0, 'local_ai_course_assistant');
    }

    public function test_aggregate_counts_a_turn_once_when_course_content_was_cut(): void {
        prompt_metrics_seed::turns(40, 10);
        $agg = prompt_metrics_logger::aggregate();
        $this->assertSame(40, $agg['samples']);
        $this->assertSame(10, $agg['content_cut_turns']);
        $this->assertEqualsWithDelta(25.0, $agg['pct_content_cut'], 0.01);
    }

    public function test_one_cut_in_thousands_of_turns_is_not_frequent(): void {
        $this->assertFalse(prompt_metrics_logger::content_cuts_are_frequent(
            ['samples' => 5129, 'pct_content_cut' => 0.0]));
    }

    public function test_the_share_must_be_strictly_over_two_percent(): void {
        $this->assertFalse(prompt_metrics_logger::content_cuts_are_frequent(
            ['samples' => 100, 'pct_content_cut' => 2.0]));
        $this->assertTrue(prompt_metrics_logger::content_cuts_are_frequent(
            ['samples' => 100, 'pct_content_cut' => 3.0]));
    }

    public function test_a_handful_of_turns_proves_nothing(): void {
        $this->assertFalse(prompt_metrics_logger::content_cuts_are_frequent(
            ['samples' => 10, 'pct_content_cut' => 50.0]), '5 of 10 turns is too few to call a trend');
        $this->assertTrue(prompt_metrics_logger::content_cuts_are_frequent(
            ['samples' => 20, 'pct_content_cut' => 50.0]));
    }

    public function test_no_warning_for_a_single_cut(): void {
        prompt_metrics_seed::turns(100, 1);
        $this->assertNull($this->warning_text());
    }

    public function test_no_warning_when_no_metrics_were_kept(): void {
        $this->assertNull($this->warning_text());
    }

    public function test_frequent_cuts_in_auto_mode_tell_the_admin_not_to_type_a_number(): void {
        prompt_metrics_seed::turns(100, 10);
        $text = $this->warning_text();
        $this->assertNotNull($text);
        $this->assertStringContainsString('10 of 100', $text);
        $this->assertStringContainsString('Do not type a number', $text);
        $this->assertStringNotContainsString('Raise the system prompt character budget', $text);
    }

    public function test_a_typed_number_gets_the_raise_it_advice_because_there_it_is_right(): void {
        prompt_metrics_seed::turns(100, 10);
        set_config('prompt_budget_chars', '40000', 'local_ai_course_assistant');
        $text = $this->warning_text();
        $this->assertNotNull($text);
        $this->assertStringContainsString('Raise the system prompt character budget', $text);
        $this->assertStringNotContainsString('Do not type a number', $text);
    }

    public function test_fixed_mode_gets_the_raise_it_advice(): void {
        prompt_metrics_seed::turns(100, 10);
        set_config('prompt_budget_mode', 'fixed', 'local_ai_course_assistant');
        $text = $this->warning_text();
        $this->assertStringContainsString('Raise the system prompt character budget', (string) $text);
    }

    public function test_an_unknown_model_window_gets_the_raise_it_advice(): void {
        prompt_metrics_seed::turns(100, 10);
        set_config('model', 'some-model-with-no-known-window', 'local_ai_course_assistant');
        $text = $this->warning_text();
        $this->assertStringContainsString('Raise the system prompt character budget', (string) $text,
            'with no window to derive from, the number is the only lever');
    }

    public function test_an_old_cut_does_not_warn_even_when_the_share_is_high(): void {
        prompt_metrics_seed::turns(100, 10);
        set_config('prompt_truncation_seen', time() - 8 * DAYSECS, 'local_ai_course_assistant');
        $this->assertNull($this->warning_text());
    }
}
