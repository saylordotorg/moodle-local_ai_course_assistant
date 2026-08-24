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

use local_ai_course_assistant\external\get_realtime_token;

defined('MOODLE_INTERNAL') || die();

/**
 * The voice endpoint must obey the kill switches, and must not hand the system
 * prompt to the browser.
 *
 * Three separate v7.0.5 security fixes, all in the same request path:
 *
 *  1. `realtime_enabled` was read in exactly one non-language file — the code
 *     that decides whether to draw the button. The endpoint never checked it, so
 *     switching voice off in admin settings hid the control and left the
 *     endpoint live. Any learner holding :use could POST to it and mint a real
 *     Realtime credential, billed at roughly $18/hour for a held session.
 *
 *  2. `emergency_control --voice` blanks `voice_active_realtime`. But a blank
 *     active label is also the ordinary "admin never chose a row" state, and
 *     voice_registry fell back to the first configured row — so on any site with
 *     a provider row the kill switch moved voice to row 0 rather than stopping
 *     it. Now driven by a dedicated `emergency_voice_disabled` flag, the same way
 *     `emergency_chat_disabled` fixed the chat switch in v5.13.
 *
 *  3. The response returned the full system prompt as PARAM_RAW. sse.php runs an
 *     output scrubber over model text specifically to keep that prompt away from
 *     students; a learner who wanted it could skip the jailbreak and read the
 *     JSON. The grounded prompt now goes to the provider server-side and only
 *     generic spoken-style guidance is returned.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\get_realtime_token::execute
 */
final class realtime_token_gating_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * An xAI voice provider, which mints without an upstream network call.
     */
    private function configure_voice(): void {
        set_config('voice_providers', 'xai|sk-xai-test|MyXai|alloy|alloy', 'local_ai_course_assistant');
        set_config('voice_active_realtime', 'MyXai', 'local_ai_course_assistant');
        set_config('realtime_enabled', 1, 'local_ai_course_assistant');
        set_config('xai_proxy_url', 'wss://proxy.example.com/realtime', 'local_ai_course_assistant');
        set_config('xai_proxy_jwt_secret', str_repeat('a', 64), 'local_ai_course_assistant');
        set_config('ssrf_trusted_endpoints', 'https://proxy.example.com', 'local_ai_course_assistant');
    }

    /**
     * @return \stdClass the course the student is enrolled in
     */
    private function enrolled_student(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        return $course;
    }

    // ---------- 1. the site switch ----------

    public function test_endpoint_refuses_when_voice_is_switched_off(): void {
        $this->configure_voice();
        set_config('realtime_enabled', 0, 'local_ai_course_assistant');
        $course = $this->enrolled_student();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/disabled on this site/');
        get_realtime_token::execute((int) $course->id);
    }

    public function test_endpoint_works_when_voice_is_switched_on(): void {
        $this->configure_voice();
        $course = $this->enrolled_student();

        $result = get_realtime_token::execute((int) $course->id);
        $this->assertSame('xai', $result['provider']);
        $this->assertNotSame('', $result['endpoint']);
    }

    // ---------- 2. the emergency switch ----------

    public function test_emergency_switch_stops_voice_even_with_a_provider_row(): void {
        // The regression. A configured provider row plus a blanked active label
        // used to resolve to row 0, so the kill switch did nothing.
        $this->configure_voice();
        emergency_control::disable([emergency_control::FLAG_VOICE], 'test', 'phpunit');
        $course = $this->enrolled_student();

        $this->assertNull(
            voice_registry::resolve(voice_registry::CAPABILITY_REALTIME),
            'the emergency switch must stop realtime resolving, not fall back to the first row'
        );
        $this->assertDebuggingCalled();

        // Catch rather than expectException: execute() resolves again, so the
        // kill-switch notice fires a second time and must also be asserted,
        // which is impossible after an uncaught expected exception.
        try {
            get_realtime_token::execute((int) $course->id);
            $this->fail('the endpoint must refuse while the kill switch is engaged');
        } catch (\moodle_exception $e) {
            $this->assertDebuggingCalled();
        }
    }

    public function test_emergency_switch_engages_even_if_no_label_was_selected(): void {
        // The second half of the same bug: with the active label already blank,
        // disable() wrote no backup, so there was nothing to indicate the
        // switch had been thrown.
        $this->configure_voice();
        unset_config('voice_active_realtime', 'local_ai_course_assistant');
        emergency_control::disable([emergency_control::FLAG_VOICE], 'test', 'phpunit');

        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_REALTIME));
        $this->assertDebuggingCalled();
    }

    public function test_restore_brings_voice_back(): void {
        $this->configure_voice();
        emergency_control::disable([emergency_control::FLAG_VOICE], 'test', 'phpunit');
        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_REALTIME));
        $this->assertDebuggingCalled();

        emergency_control::restore([emergency_control::FLAG_VOICE], 'test', 'phpunit');
        $this->assertNotNull(
            voice_registry::resolve(voice_registry::CAPABILITY_REALTIME),
            'restore must clear the emergency flag, not just put the label back'
        );
    }

    // ---------- 3. the system prompt must not reach the browser ----------

    public function test_response_does_not_carry_the_system_prompt(): void {
        $this->configure_voice();
        $course = $this->enrolled_student();

        $result = get_realtime_token::execute((int) $course->id);
        $returned = (string) $result['instructions'];

        // The course's own name is the cleanest fingerprint of the grounded
        // prompt: build_system_prompt names the course, and the voice tail never
        // does. Note the tail DOES mention the literal string
        // "## Current Page Content" — it instructs the model to look for that
        // section — so heading text is not a usable needle here.
        $this->assertStringNotContainsString(
            (string) $course->fullname,
            $returned,
            'the system prompt must not be returned to the client'
        );
        $this->assertStringNotContainsStringIgnoringCase('Course topics:', $returned);
    }

    public function test_response_still_carries_the_voice_style_tail(): void {
        // The fix withholds the prompt without silently disabling voice styling,
        // which is what the client legitimately needs.
        $this->configure_voice();
        $course = $this->enrolled_student();

        $result = get_realtime_token::execute((int) $course->id);
        $this->assertStringContainsString('Voice mode', (string) $result['instructions']);
    }

    public function test_returned_instructions_are_much_shorter_than_the_full_prompt(): void {
        $this->configure_voice();
        $course = $this->enrolled_student();
        $user = $GLOBALS['USER'];

        $full = context_builder::build_system_prompt((int) $course->id, (int) $user->id, '', [], 0, '', '');
        $returned = (string) get_realtime_token::execute((int) $course->id)['instructions'];

        // Not an exact figure — the point is that the grounded body is absent,
        // not merely trimmed.
        $this->assertLessThan(
            strlen($full),
            strlen($returned),
            'the returned instructions must not include the grounded prompt body'
        );
    }
}
