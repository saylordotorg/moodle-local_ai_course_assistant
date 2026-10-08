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

namespace local_ai_course_assistant\provider;

/**
 * Claude thinking tokens are recorded, and never priced twice (v7.8.2).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\claude_provider
 */
final class claude_thinking_tokens_test extends \advanced_testcase {
    public function test_reads_the_itemised_thinking_tokens(): void {
        $this->assertSame(
            812,
            claude_provider::thinking_tokens(['output_tokens' => 1500, 'output_tokens_details' => ['thinking_tokens' => 812]])
        );
        $this->assertSame(0, claude_provider::thinking_tokens(['output_tokens' => 10]));
        $this->assertSame(0, claude_provider::thinking_tokens(['output_tokens_details' => ['thinking_tokens' => -5]]));
    }

    public function test_a_response_carries_them_in_the_usage_array(): void {
        $p = new claude_provider(['apikey' => 'k-test', 'model' => 'claude-sonnet-5-5']);
        $m = new \ReflectionMethod($p, 'add_token_usage');
        $m->setAccessible(true);
        $m->invoke($p, ['model' => 'claude-sonnet-5-5', 'usage' => [
            'input_tokens' => 100, 'output_tokens' => 1500, 'output_tokens_details' => ['thinking_tokens' => 812],
        ]]);
        $usage = $p->get_last_token_usage();
        $this->assertSame(812, $usage['reasoning_tokens']);
        $this->assertSame(1500, $usage['completion_tokens']);
        // A second request in the same call adds to it.
        $m->invoke($p, ['usage' => ['input_tokens' => 1, 'output_tokens' => 10, 'output_tokens_details' => ['thinking_tokens' => 8]]]);
        $this->assertSame(820, $p->get_last_token_usage()['reasoning_tokens']);
    }

    public function test_recording_them_does_not_change_the_price(): void {
        $with = \local_ai_course_assistant\token_cost_manager::estimate_cost('claude-sonnet-5-5', 100, 1500, 812);
        $without = \local_ai_course_assistant\token_cost_manager::estimate_cost('claude-sonnet-5-5', 100, 1500, 0);
        $this->assertSame($without, $with, 'Claude output_tokens already include thinking.');
    }
}
