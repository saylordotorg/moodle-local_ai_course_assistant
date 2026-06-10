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
 * Tests for premium_router (v5.12.0 A.10 follow-on).
 *
 * Exercises the trigger-regex matcher and the course-allowlist resolver in
 * isolation. The decide() integration is covered by setting plugin config
 * and asserting the resolved (escalate, reason, provider, model) tuple.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\premium_router
 */
final class premium_router_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_disabled_by_default(): void {
        $d = premium_router::decide('derive the integral of x*ln(x) from 0 to 1', 0);
        $this->assertFalse($d['escalate']);
        $this->assertEquals('disabled', $d['reason']);
    }

    public function test_enabled_but_unconfigured_does_not_escalate(): void {
        set_config('premium_escalation_enabled', 1, 'local_ai_course_assistant');
        set_config('premium_escalation_provider', '', 'local_ai_course_assistant');
        set_config('premium_escalation_model', '', 'local_ai_course_assistant');
        $d = premium_router::decide('derive the integral', 0);
        $this->assertFalse($d['escalate']);
        $this->assertEquals('unconfigured', $d['reason']);
    }

    public function test_default_triggers_match_stem_markers(): void {
        $hits = [
            'Can you derive the volume of a sphere?',
            'Prove that the harmonic series diverges',
            'Walk me through this step by step',
            'Compute the integral of x^2 dx',
            'What is the complexity proof for merge sort?',
            'Optimization problem: maximize the volume',
            'Some inline math: $E = mc^2$ explained',
            "Here is my code:\n```python\nprint('hi')\n```\nWhy does it fail?",
        ];
        foreach ($hits as $msg) {
            $matched = premium_router::first_matching_trigger($msg);
            $this->assertNotNull($matched, "Expected default triggers to match: $msg");
        }
    }

    public function test_default_triggers_do_not_match_neutral_messages(): void {
        $misses = [
            'Hello, can you help me with chapter 3?',
            'What is the capital of France?',
            'I am confused about the syllabus.',
            'When is the next assignment due?',
        ];
        foreach ($misses as $msg) {
            $matched = premium_router::first_matching_trigger($msg);
            $this->assertNull($matched, "Did not expect default triggers to match: $msg");
        }
    }

    public function test_admin_override_replaces_default_triggers(): void {
        // Custom trigger list: ONLY match the word "purple".
        set_config('premium_escalation_triggers', '\bpurple\b', 'local_ai_course_assistant');
        $this->assertNotNull(premium_router::first_matching_trigger('I like the purple one'));
        // STEM markers no longer match because admin overrode the list.
        $this->assertNull(premium_router::first_matching_trigger('derive the integral'));
    }

    public function test_blank_admin_triggers_falls_back_to_defaults(): void {
        set_config('premium_escalation_triggers', '', 'local_ai_course_assistant');
        $this->assertNotNull(premium_router::first_matching_trigger('Prove that 2+2=4'));
    }

    public function test_malformed_admin_regex_does_not_throw(): void {
        // Invalid regex (unclosed group). Should be silently skipped, not crash.
        set_config('premium_escalation_triggers', "(unbalanced\n\\bderive\\b", 'local_ai_course_assistant');
        // The good line still matches.
        $this->assertNotNull(premium_router::first_matching_trigger('Please derive the area'));
        // A message that hits neither returns null without error.
        $this->assertNull(premium_router::first_matching_trigger('Hello there'));
    }

    public function test_comment_lines_are_ignored(): void {
        set_config('premium_escalation_triggers', "# this is a comment\n\\bcat\\b", 'local_ai_course_assistant');
        $this->assertNotNull(premium_router::first_matching_trigger('I see a cat'));
        $this->assertNull(premium_router::first_matching_trigger('I see a comment'));
    }

    public function test_course_allowlist_match_by_shortname_prefix(): void {
        $course = $this->getDataGenerator()->create_course(['shortname' => 'MATH121', 'idnumber' => 'm121-2026']);
        set_config('premium_escalation_course_tags', "MATH\nphys", 'local_ai_course_assistant');
        $this->assertTrue(premium_router::course_matches_allowlist((int) $course->id));
    }

    public function test_course_allowlist_match_by_idnumber_prefix(): void {
        $course = $this->getDataGenerator()->create_course(['shortname' => 'biol-200', 'idnumber' => 'BIOL200']);
        set_config('premium_escalation_course_tags', 'BIOL', 'local_ai_course_assistant');
        $this->assertTrue(premium_router::course_matches_allowlist((int) $course->id));
    }

    public function test_course_allowlist_miss(): void {
        $course = $this->getDataGenerator()->create_course(['shortname' => 'ENGL101', 'idnumber' => 'e101']);
        set_config('premium_escalation_course_tags', "MATH\nCS\nPHYS", 'local_ai_course_assistant');
        $this->assertFalse(premium_router::course_matches_allowlist((int) $course->id));
    }

    public function test_course_allowlist_blank_means_no_match(): void {
        $course = $this->getDataGenerator()->create_course(['shortname' => 'MATH200', 'idnumber' => 'm200']);
        set_config('premium_escalation_course_tags', '', 'local_ai_course_assistant');
        $this->assertFalse(premium_router::course_matches_allowlist((int) $course->id));
    }

    public function test_decide_returns_resolved_provider_when_trigger_matches(): void {
        set_config('premium_escalation_enabled', 1, 'local_ai_course_assistant');
        set_config('premium_escalation_provider', 'claude', 'local_ai_course_assistant');
        set_config('premium_escalation_model', 'claude-opus-4-8', 'local_ai_course_assistant');
        $d = premium_router::decide('please derive the area under the curve', 0);
        $this->assertTrue($d['escalate']);
        $this->assertStringStartsWith('trigger:', $d['reason']);
        $this->assertEquals('claude', $d['provider']);
        $this->assertEquals('claude-opus-4-8', $d['model']);
    }

    public function test_decide_course_tag_takes_precedence_over_message_match(): void {
        set_config('premium_escalation_enabled', 1, 'local_ai_course_assistant');
        set_config('premium_escalation_provider', 'claude', 'local_ai_course_assistant');
        set_config('premium_escalation_model', 'claude-opus-4-8', 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course(['shortname' => 'CS101']);
        set_config('premium_escalation_course_tags', 'CS', 'local_ai_course_assistant');
        // Neutral message (no STEM trigger), but the course is in the allowlist.
        $d = premium_router::decide('Hello, hi, what is this?', (int) $course->id);
        $this->assertTrue($d['escalate']);
        $this->assertEquals('course_tag', $d['reason']);
    }

    public function test_decide_no_match_does_not_escalate(): void {
        set_config('premium_escalation_enabled', 1, 'local_ai_course_assistant');
        set_config('premium_escalation_provider', 'claude', 'local_ai_course_assistant');
        set_config('premium_escalation_model', 'claude-opus-4-8', 'local_ai_course_assistant');
        $d = premium_router::decide('Hello, can you help me with chapter 3?', 0);
        $this->assertFalse($d['escalate']);
        $this->assertEquals('none', $d['reason']);
    }

    public function test_empty_message_does_not_escalate(): void {
        set_config('premium_escalation_enabled', 1, 'local_ai_course_assistant');
        set_config('premium_escalation_provider', 'claude', 'local_ai_course_assistant');
        set_config('premium_escalation_model', 'claude-opus-4-8', 'local_ai_course_assistant');
        $d = premium_router::decide('', 0);
        $this->assertFalse($d['escalate']);
    }
}
