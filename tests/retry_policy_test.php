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

use local_ai_course_assistant\provider\base_provider;

/**
 * Issue #295: retry settings bounded at both ends, with the total wait capped.
 *
 * backend_retry_attempts had a floor and no ceiling. Mistyped as 20000, with a
 * provider returning 429 or 503, one request sat in usleep() for about 17 hours,
 * which max_execution_time never ends because usleep accrues no CPU time. A few
 * learners under a rate limit would exhaust the PHP-FPM pool.
 *
 * Three layers, each tested here, because each covers a case the others cannot:
 *  - the admin setting refuses bad input, so the page never shows a value the
 *    code will not honour;
 *  - read-time clamping, for values that never passed through that form;
 *  - a total-wait budget, because even the bounded maximum of ten attempts at
 *    thirty seconds each would still hold a worker for five minutes.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\retry_policy
 * @covers     \local_ai_course_assistant\admin_setting_bounded_int
 */
final class retry_policy_test extends \advanced_testcase {

    /**
     * Values that never went through the admin form are clamped when read.
     *
     * A validator cannot reach a value set by forced_plugin_settings, an upgrade
     * or CLI set_config, or saved before the validator existed.
     */
    public function test_out_of_range_config_is_clamped_at_read_time(): void {
        $this->resetAfterTest();

        set_config('backend_retry_attempts', 20000, 'local_ai_course_assistant');
        set_config('backend_retry_max_wait', 99999, 'local_ai_course_assistant');
        $this->assertSame(['attempts' => retry_policy::MAX_ATTEMPTS, 'maxwait' => retry_policy::MAX_WAIT],
            retry_policy::from_config());

        set_config('backend_retry_attempts', -5, 'local_ai_course_assistant');
        set_config('backend_retry_max_wait', -1, 'local_ai_course_assistant');
        $this->assertSame(['attempts' => retry_policy::MIN_ATTEMPTS, 'maxwait' => retry_policy::MIN_WAIT],
            retry_policy::from_config());
    }

    /**
     * In-range values pass through untouched, and unset keeps its old meaning.
     */
    public function test_in_range_and_unset_values_are_unchanged(): void {
        $this->resetAfterTest();

        set_config('backend_retry_attempts', 3, 'local_ai_course_assistant');
        set_config('backend_retry_max_wait', 12, 'local_ai_course_assistant');
        $this->assertSame(['attempts' => 3, 'maxwait' => 12], retry_policy::from_config());

        unset_config('backend_retry_attempts', 'local_ai_course_assistant');
        unset_config('backend_retry_max_wait', 'local_ai_course_assistant');
        $this->assertSame(['attempts' => 0, 'maxwait' => 5], retry_policy::from_config());
    }

    /**
     * A mistyped attempts count no longer retries without end.
     */
    public function test_a_huge_attempts_value_retries_at_most_the_ceiling(): void {
        $this->resetAfterTest();
        set_config('backend_retry_attempts', 20000, 'local_ai_course_assistant');
        $calls = 0;
        try {
            base_provider::with_transient_retry(function () use (&$calls) {
                $calls++;
                throw base_provider::transient_http_exception(503, null);
            }, 0);
            $this->fail('expected the final transient error to be rethrown');
        } catch (\moodle_exception $e) {
            // 1 initial + at most MAX_ATTEMPTS retries.
            $this->assertLessThanOrEqual(1 + retry_policy::MAX_ATTEMPTS, $calls);
        }
    }

    /**
     * The total wait across a call is capped, even with every setting in range.
     *
     * Ten attempts with a provider asking for 25 seconds each would wait 250
     * seconds. The budget stops it after the first wait, since a second would
     * pass MAX_TOTAL_WAIT.
     */
    public function test_total_wait_is_capped_even_within_the_per_setting_bounds(): void {
        $this->resetAfterTest();
        set_config('backend_retry_attempts', retry_policy::MAX_ATTEMPTS, 'local_ai_course_assistant');
        set_config('backend_retry_max_wait', 25, 'local_ai_course_assistant');
        $calls = 0;
        try {
            base_provider::with_transient_retry(function () use (&$calls) {
                $calls++;
                throw base_provider::transient_http_exception(429, 25);
            }, 0);
            $this->fail('expected the final transient error to be rethrown');
        } catch (\moodle_exception $e) {
            // First call, one 25s wait, retry; a second 25s wait would make 50s.
            $this->assertSame(2, $calls, 'the total-wait budget did not stop the retries');
        }
    }

    /**
     * Every per-wait ceiling the admin may set must fit inside the total budget.
     *
     * The first version of this fix allowed 60s per wait against a 30s total.
     * Both loops check the upcoming wait against the budget before sleeping, so
     * any accepted value above the budget could never be honoured and turned a
     * large Retry-After into zero retries. Pinned as an invariant so the two
     * constants cannot drift apart again.
     */
    public function test_the_largest_allowed_wait_fits_in_the_total_budget(): void {
        $this->assertLessThanOrEqual(retry_policy::MAX_TOTAL_WAIT, (float) retry_policy::MAX_WAIT);
    }

    /**
     * At the highest allowed ceiling, a long Retry-After still gets a retry.
     *
     * This is the case the review found: with the ceiling set as high as it
     * goes and the provider asking for longer than that, the request must wait
     * the ceiling and retry, not give up before trying.
     */
    public function test_a_long_retry_after_at_the_highest_ceiling_still_retries(): void {
        $this->resetAfterTest();
        set_config('backend_retry_attempts', retry_policy::MAX_ATTEMPTS, 'local_ai_course_assistant');
        set_config('backend_retry_max_wait', retry_policy::MAX_WAIT, 'local_ai_course_assistant');
        $calls = 0;
        try {
            $result = base_provider::with_transient_retry(function () use (&$calls) {
                $calls++;
                if ($calls === 1) {
                    throw base_provider::transient_http_exception(429, 45);
                }
                return 'ok';
            }, 0);
        } catch (\moodle_exception $e) {
            $this->fail("a long Retry-After at the highest ceiling was rethrown after {$calls} call(s), "
                . 'i.e. zero retries: the per-wait ceiling exceeds the total budget');
        }

        $this->assertSame('ok', $result);
        $this->assertSame(2, $calls);
    }

    /**
     * The admin setting refuses an out-of-range value and names the range.
     */
    public function test_the_admin_setting_refuses_out_of_range_input(): void {
        $this->resetAfterTest();
        $setting = new admin_setting_bounded_int('local_ai_course_assistant/backend_retry_attempts',
            'label', 'desc', '2', retry_policy::MIN_ATTEMPTS, retry_policy::MAX_ATTEMPTS);

        foreach (['0', '2', '10'] as $ok) {
            $this->assertTrue($setting->validate($ok), "{$ok} should be accepted");
        }
        foreach (['11', '20000', '-1'] as $bad) {
            $message = $setting->validate($bad);
            $this->assertIsString($message, "{$bad} should be refused");
            $this->assertStringContainsString('10', $message, 'the refusal should state the range');
        }
    }
}
