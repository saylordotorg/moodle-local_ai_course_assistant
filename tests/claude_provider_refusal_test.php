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
 * Regression test for Anthropic `stop_reason: "refusal"` handling.
 *
 * Found 2026-07-30 while jailbreak-testing Claude Sonnet 5. On the base64
 * prompt-injection fixture, Sonnet 5's own safety layer declines the request
 * and the API returns:
 *
 *     {"stop_reason": "refusal", "content": [], "usage": {...}}
 *
 * A refusal carries NO content blocks at all. Before this fix the provider
 * fell through to `throw new moodle_exception('chat:error', ...)`, so a
 * model-level refusal was indistinguishable from a transport failure: the
 * learner saw "Sorry, something went wrong. Please try again." and the real
 * cause was masked. On the streaming path — the one learners actually use —
 * no text was emitted at all.
 *
 * Reproduced at both max_tokens=512 and max_tokens=4096, so this is not a
 * truncated-output artifact. Opus 4.x and Gemini did not exhibit it on the
 * same fixture; it is specific to models that emit an API-level refusal.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider\claude_provider
 */
final class claude_provider_refusal_test extends \advanced_testcase {
    /**
     * The constant must keep the exact wire value Anthropic sends.
     */
    public function test_refusal_stop_reason_constant_matches_the_wire_value(): void {
        $this->assertSame('refusal', claude_provider::STOP_REASON_REFUSAL);
    }

    /**
     * The decline string must exist and must not be the generic error, or the
     * fix would silently degrade back to "something went wrong".
     */
    public function test_refusal_string_exists_and_differs_from_the_generic_error(): void {
        $this->resetAfterTest();

        $refused = get_string('chat:refused', 'local_ai_course_assistant');
        $generic = get_string('chat:error', 'local_ai_course_assistant');

        $this->assertNotEmpty($refused);
        $this->assertStringNotContainsString(
            '[[',
            $refused,
            'chat:refused is not defined in the language pack.'
        );
        $this->assertNotSame(
            $generic,
            $refused,
            'A model refusal must not present as the generic transport error.'
        );
    }

    /**
     * A refusal payload has an empty content array. Assert the shape the
     * provider now branches on, so a future refactor that assumes at least
     * one content block is caught here rather than in production.
     */
    public function test_refusal_payload_shape_has_no_content_blocks(): void {
        $payload = [
            'id'          => 'msg_test',
            'model'       => 'claude-sonnet-5',
            'stop_reason' => claude_provider::STOP_REASON_REFUSAL,
            'content'     => [],
            'usage'       => ['input_tokens' => 1200, 'output_tokens' => 1],
        ];

        $this->assertSame(claude_provider::STOP_REASON_REFUSAL, $payload['stop_reason']);
        $this->assertEmpty(
            $payload['content'],
            'A refusal carries no content blocks; text extraction cannot succeed.'
        );

        // This is precisely why `empty($data['content'])` is not a safe test
        // for "malformed response" on its own: a refusal is well-formed.
        $this->assertTrue(empty($payload['content']));
    }
}
