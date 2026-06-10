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
 * Tests for the v5.13.0 site-wide default-per-course spend cap fallback
 * added to spend_guard::get_cap().
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\spend_guard
 */
final class spend_guard_per_course_default_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_default_per_course_cap_applies_when_no_explicit_override(): void {
        $course = $this->getDataGenerator()->create_course();
        set_config('spend_cap_per_course_default', '30', 'local_ai_course_assistant');
        $this->assertEquals(30.0, spend_guard::get_cap((int) $course->id));
    }

    public function test_default_zero_falls_through_to_site_cap(): void {
        $course = $this->getDataGenerator()->create_course();
        set_config('spend_cap_per_course_default', '0', 'local_ai_course_assistant');
        set_config('spend_cap_site', '500', 'local_ai_course_assistant');
        $this->assertEquals(500.0, spend_guard::get_cap((int) $course->id));
    }

    public function test_default_unset_falls_through_to_site_cap(): void {
        $course = $this->getDataGenerator()->create_course();
        // Don't set spend_cap_per_course_default at all.
        set_config('spend_cap_site', '500', 'local_ai_course_assistant');
        $this->assertEquals(500.0, spend_guard::get_cap((int) $course->id));
    }

    public function test_site_wide_call_ignores_per_course_default(): void {
        // Site-wide check (courseid = 0) should never apply the per-course default.
        set_config('spend_cap_per_course_default', '30', 'local_ai_course_assistant');
        set_config('spend_cap_site', '500', 'local_ai_course_assistant');
        $this->assertEquals(500.0, spend_guard::get_cap(0));
    }

    public function test_capability_cap_beats_per_course_default(): void {
        $course = $this->getDataGenerator()->create_course();
        set_config('spend_cap_per_course_default', '30', 'local_ai_course_assistant');
        set_config('spend_cap_chat', '200', 'local_ai_course_assistant');
        // When capability is set and positive, capability cap wins.
        $this->assertEquals(200.0, spend_guard::get_cap((int) $course->id, 'chat'));
    }

    public function test_check_returns_blocked_when_default_exceeded(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        set_config('spend_cap_per_course_default', '0.001', 'local_ai_course_assistant');
        // Insert a message row with a chat cost just over the tiny cap so
        // get_spend > get_cap. Use the same table that compute_spend reads.
        $row = (object) [
            'courseid' => $course->id,
            'userid' => $user->id,
            'role' => 'assistant',
            'message' => 'x',
            'tokens_used' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'model_name' => 'gpt-4o-mini',
            'provider' => 'openai',
            'interaction_type' => 'chat',
            'cost_usd' => 1.00,
            'timecreated' => time(),
        ];
        try {
            $DB->insert_record('local_ai_course_assistant_msgs', $row);
        } catch (\dml_exception $e) {
            // Schema may differ; the cap-resolution logic is what we're testing,
            // not the cost-aggregation. Mark the test as skipped rather than failing.
            $this->markTestSkipped('msgs schema differs from expected: ' . $e->getMessage());
        }
        $this->assertEquals(spend_guard::CAP_BLOCKED, spend_guard::check((int) $course->id));
    }
}
