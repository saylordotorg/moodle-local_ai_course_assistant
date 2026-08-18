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

use local_ai_course_assistant\provider\claude_provider;

/**
 * An Anthropic error payload must reach the exception's debuginfo, so the SSE
 * handler can record it in the audit log.
 *
 * Regression cover for 2026-08: an organisation spend cap returned
 * "You have reached your specified API usage limits" on every call for ten
 * courses. The provider discarded the payload and threw the fixed string
 * "Invalid API response", so the audit recorded only the learner-facing
 * "Sorry, something went wrong". The outage took nine days to diagnose and
 * needed a manual curl, because nothing in the system was reading a message
 * the API had been returning all along.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\claude_provider
 */
final class claude_provider_api_error_test extends \advanced_testcase {

    /**
     * Call the private describe_api_error() helper.
     *
     * @param mixed $data Decoded body.
     * @param string $raw Raw body.
     * @return string
     */
    private function describe($data, string $raw = ''): string {
        $m = new \ReflectionMethod(claude_provider::class, 'describe_api_error');
        $m->setAccessible(true);
        return $m->invoke(null, $data, $raw);
    }

    public function test_spend_cap_message_is_preserved(): void {
        // The exact payload that took prod down on 2026-08-10.
        $payload = [
            'type' => 'error',
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'You have reached your specified API usage limits. '
                    . 'You will regain access on 2026-09-01 at 00:00 UTC.',
            ],
        ];

        $out = $this->describe($payload, json_encode($payload));

        $this->assertStringContainsString('invalid_request_error', $out,
            'The vendor error type must survive, so a cap is distinguishable from a bad key.');
        $this->assertStringContainsString('usage limits', $out,
            'The actual cause must survive; this is the string that was missing for nine days.');
        $this->assertStringNotContainsString('Invalid API response', $out,
            'The old fixed placeholder must not come back.');
    }

    public function test_auth_error_is_distinguishable_from_a_cap(): void {
        // Both are 4xx with no content block. If the audit cannot tell them
        // apart, the operator cannot either -- which is the whole bug.
        $cap = $this->describe(['error' => ['type' => 'invalid_request_error', 'message' => 'usage limits']]);
        $auth = $this->describe(['error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]);

        $this->assertNotSame($cap, $auth);
        $this->assertStringContainsString('authentication_error', $auth);
    }

    public function test_non_json_body_still_yields_something_useful(): void {
        // A proxy or WAF error page is not JSON. The shape of the failure
        // should still be visible rather than collapsing to a fixed string.
        $out = $this->describe(null, '<html><title>502 Bad Gateway</title></html>');

        $this->assertStringContainsString('502', $out);
        $this->assertStringContainsString('Unrecognised API response', $out);
    }

    public function test_empty_body_is_labelled(): void {
        $this->assertSame('Empty API response', $this->describe(null, ''));
    }

    public function test_output_is_length_capped(): void {
        // debuginfo lands in an audit row; an unbounded provider body must not
        // be written there verbatim.
        $out = $this->describe(['error' => ['type' => 'x', 'message' => str_repeat('A', 5000)]]);

        $this->assertLessThanOrEqual(400, \core_text::strlen($out));
    }
}
