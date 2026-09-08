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

use local_ai_course_assistant\task\auto_tune_prompt_budget;

/**
 * The deprecated budget tuner must not fight the derived budget.
 *
 * The two features collided in a way that was invisible from either one. The
 * tuner writes prompt_budget_chars; resolve_budget_chars() reads any stored
 * value other than the default as a deliberate administrator decision and stops
 * deriving. So the tuner's first write switched the derived budget off, and the
 * only symptom was a site quietly running on an inferred number instead of the
 * model's context window. Both Saylor production sites were in that state.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\task\auto_tune_prompt_budget
 */
final class budget_tuner_standdown_test extends \advanced_testcase {

    /**
     * Opt the tuner in and give it a budget to move.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        set_config('prompt_budget_auto_tune', 1, 'local_ai_course_assistant');
        set_config('prompt_budget_chars', 26000, 'local_ai_course_assistant');
    }

    /**
     * Seed enough prompt metrics that the tuner would want to raise the budget.
     *
     * Without this the tuner declines for want of data, and every assertion
     * that it left the budget alone passes for the wrong reason. These records
     * put truncation well above the 1% trigger, so a tuner that reaches its
     * recommendation logic WILL write -- which is what makes the stand-down
     * assertions mean something.
     *
     * @return void
     */
    private function seed_truncating_metrics(): void {
        global $CFG;
        $dir = $CFG->dataroot . '/sola_prompt_metrics';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $lines = '';
        for ($i = 0; $i < 40; $i++) {
            $lines .= json_encode([
                't' => time(),
                'course' => 2,
                'user' => 3,
                'total' => 25800 + $i,
                'budget' => 26000,
                'cats' => ['identity' => 4000, 'context' => 18000, 'learner' => 1200,
                           'behavior' => 1600, 'markers' => 600, 'safety' => 400],
                'dropped' => 0,
                // Every turn truncating: far above the 1% raise trigger.
                'truncated' => 1,
            ]) . "\n";
        }
        file_put_contents($dir . '/' . date('Y-m-d') . '.log', $lines);
    }

    /**
     * Run the task and return whatever it wrote to the trace.
     *
     * @return string
     */
    private function run_task(): string {
        ob_start();
        (new auto_tune_prompt_budget())->execute();
        return (string) ob_get_clean();
    }

    /**
     * In auto mode the task must not touch the budget.
     */
    public function test_stands_down_in_auto_mode(): void {
        set_config('prompt_budget_mode', 'auto', 'local_ai_course_assistant');
        $this->seed_truncating_metrics();

        $out = $this->run_task();

        $this->assertSame(
            '26000',
            get_config('local_ai_course_assistant', 'prompt_budget_chars'),
            'The tuner wrote the budget while the derived budget was in force.'
        );
        $this->assertStringContainsString('deprecated', $out);
    }

    /**
     * An unset mode is auto, and must behave the same way.
     *
     * A site upgrading from before the mode setting existed has no stored
     * value. Treating that as "fixed" would put exactly the sites that predate
     * the derived budget back into the collision.
     */
    public function test_unset_mode_is_treated_as_auto(): void {
        unset_config('prompt_budget_mode', 'local_ai_course_assistant');
        $this->seed_truncating_metrics();

        $this->run_task();

        $this->assertSame(
            '26000',
            get_config('local_ai_course_assistant', 'prompt_budget_chars')
        );
    }

    /**
     * The opt-in flag still wins, and is reported as the reason.
     */
    public function test_disabled_flag_still_short_circuits_first(): void {
        set_config('prompt_budget_auto_tune', 0, 'local_ai_course_assistant');
        set_config('prompt_budget_mode', 'fixed', 'local_ai_course_assistant');

        $out = $this->run_task();

        $this->assertStringContainsString('disabled', $out);
        $this->assertStringNotContainsString('deprecated', $out);
    }

    /**
     * In fixed mode the task still runs, because there is nothing to collide
     * with. It finds no metrics here, which is the correct no-op, but it must
     * reach that decision rather than standing down before looking.
     */
    public function test_still_runs_in_fixed_mode(): void {
        set_config('prompt_budget_mode', 'fixed', 'local_ai_course_assistant');
        $this->seed_truncating_metrics();

        $out = $this->run_task();

        $this->assertStringNotContainsString('deprecated', $out);
        // The positive control for every other test in this class: with the
        // same metrics and the same flag, fixed mode DOES write. So when auto
        // mode leaves the budget at 26000, that is the guard working and not
        // the tuner having nothing to say.
        $this->assertNotSame(
            '26000',
            get_config('local_ai_course_assistant', 'prompt_budget_chars'),
            'In fixed mode the tuner should still apply its recommendation.'
        );
    }
}
