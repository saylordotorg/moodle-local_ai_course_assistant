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

namespace local_ai_course_assistant\external;

/**
 * Tests for the realtime token endpoint, focused on the v6.7.2 fix: the
 * session instructions sent to OpenAI Realtime must not contain a reserved
 * special token (e.g. <|im_start|>, which the system prompt's jailbreak
 * defence cites as an example). Realtime rejects the whole session otherwise.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\get_realtime_token
 */
final class get_realtime_token_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        // A configured realtime provider (legacy single-key path resolves to
        // OpenAI) plus a mocked ephemeral-token HTTP response, so execute()
        // reaches the return without a real network call.
        set_config('realtime_apikey', 'sk-test-key', 'local_ai_course_assistant');
        get_realtime_token::$test_http_response = [
            'http_code' => 200,
            'body' => '{"client_secret":{"value":"ek_test_secret"}}',
        ];
    }

    protected function tearDown(): void {
        get_realtime_token::$test_http_response = null;
        parent::tearDown();
    }

    public function test_instructions_strip_reserved_special_tokens(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $result = get_realtime_token::execute((int) $course->id, 0, '', 'en');

        $this->assertArrayHasKey('instructions', $result);
        $instructions = $result['instructions'];
        $this->assertNotEmpty($instructions);
        // The core guarantee: no OpenAI reserved special token survives.
        $this->assertDoesNotMatchRegularExpression('/<\|[a-zA-Z0-9_\-]+\|>/', $instructions,
            'Realtime instructions must not contain a reserved special token.');
        // The jailbreak-defence line cited <|im_start|>; it is now neutralized.
        $this->assertStringContainsString('[special token]', $instructions);
        $this->assertStringNotContainsString('<|im_start|>', $instructions);
    }

    public function test_instructions_carry_the_voice_mode_tail(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $result = get_realtime_token::execute((int) $course->id, 0, '', 'en');

        // Voice-mode tail is appended (spoken-style guidance) and the SOLA_NEXT
        // protocol marker instruction is preserved (it is not a reserved token).
        $this->assertStringContainsString('Voice mode', $result['instructions']);
        $this->assertStringContainsString('SOLA_NEXT', $result['instructions']);
    }
}
