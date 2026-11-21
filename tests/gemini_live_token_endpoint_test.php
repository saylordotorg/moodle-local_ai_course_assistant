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
use local_ai_course_assistant\external\record_live_usage;
use local_ai_course_assistant\voice\gemini_live_token;

/**
 * The Gemini Live branch of get_realtime_token and the usage report, with Google's answer faked (8.2.0).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\get_realtime_token
 * @covers     \local_ai_course_assistant\external\record_live_usage
 */
final class gemini_live_token_endpoint_test extends \advanced_testcase {

    /** @var string The site key in the fake row. Must never reach the browser. */
    private const KEY = 'AIzaSyLIVEKEY0123456789';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        gemini_live_token::$test_http_response = ['body' => '{"name":"auth_tokens/eph-TOKEN-1234"}', 'http_code' => 200];
        gemini_live_token::$last_request = null;
    }

    protected function tearDown(): void {
        gemini_live_token::$test_http_response = null;
        gemini_live_token::$last_request = null;
        parent::tearDown();
    }

    private function configure(): void {
        set_config('voice_providers', 'gemini|' . secrets::conceal(self::KEY) . '|google|Puck|', 'local_ai_course_assistant');
        set_config('voice_active_realtime', 'google', 'local_ai_course_assistant');
        set_config('realtime_enabled', 1, 'local_ai_course_assistant');
        set_config('gemini_live_enabled', 1, 'local_ai_course_assistant');
    }

    private function student(): \stdClass {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Biology Zeta Course']);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        return $course;
    }

    public function test_a_mint_returns_only_the_ephemeral_token_and_locks_the_prompt_server_side(): void {
        global $DB, $USER;
        $this->configure();
        $course = $this->student();

        $result = get_realtime_token::execute((int) $course->id);
        $result = \core_external\external_api::clean_returnvalue(get_realtime_token::execute_returns(), $result);

        $this->assertSame('gemini', $result['provider']);
        $this->assertSame('auth_tokens/eph-TOKEN-1234', $result['token']);
        $this->assertSame(gemini_live_token::WS_URL, $result['endpoint']);
        $this->assertSame('gemini-3.8-live', $result['model']);
        $this->assertSame('Puck', $result['voice']);
        $this->assertSame(600, $result['maxseconds']);
        $this->assertSame(90, $result['idleseconds']);
        $this->assertStringNotContainsString(self::KEY, json_encode($result), 'the site key never reaches the browser');
        $this->assertStringNotContainsString('Biology Zeta Course', $result['instructions'],
            'the grounded prompt is not handed to the browser');

        // The grounded prompt went to Google inside the locked token request instead.
        $req = gemini_live_token::$last_request;
        $setup = $req['bidiGenerateContentSetup']['setup'];
        $this->assertStringContainsString('Biology Zeta Course', $setup['systemInstruction']['parts'][0]['text']);
        $this->assertStringContainsString('## Voice mode', $setup['systemInstruction']['parts'][0]['text']);
        $this->assertStringNotContainsString('<|im_start|>', $setup['systemInstruction']['parts'][0]['text'],
            'special tokens are neutralised as for OpenAI');
        $this->assertSame(['AUDIO'], $setup['generationConfig']['responseModalities']);
        $this->assertSame('models/gemini-3.8-live', $setup['model']);

        // One spend row, in the voice bucket, owned by the learner.
        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $result['sessionid']]);
        $this->assertSame('gemini_live', $row->interaction_type);
        $this->assertEquals($USER->id, $row->userid);
        $this->assertSame('[Gemini Live session: open, cap 600s]', $row->message);
    }

    public function test_voice_mode_off_refuses_before_google_is_called(): void {
        $this->configure();
        set_config('realtime_enabled', 0, 'local_ai_course_assistant');
        $course = $this->student();
        try {
            get_realtime_token::execute((int) $course->id);
            $this->fail('voice mode off must refuse');
        } catch (\moodle_exception $e) {
            $this->assertSame('realtime:err_disabled', $e->errorcode);
        }
        $this->assertNull(gemini_live_token::$last_request);
    }

    public function test_gemini_live_off_means_no_realtime_provider(): void {
        $this->configure();
        set_config('gemini_live_enabled', 0, 'local_ai_course_assistant');
        $course = $this->student();
        try {
            get_realtime_token::execute((int) $course->id);
            $this->fail('a gemini row must not serve Realtime while Gemini Live is off');
        } catch (\moodle_exception $e) {
            $this->assertSame('realtime:err_no_provider', $e->errorcode);
        }
        $this->assertNull(gemini_live_token::$last_request);
    }

    public function test_the_kill_switch_refuses(): void {
        $this->configure();
        $course = $this->student();
        emergency_control::disable([emergency_control::FLAG_VOICE], 'test', 'phpunit');
        try {
            get_realtime_token::execute((int) $course->id);
            $this->fail('the emergency switch must refuse');
        } catch (\moodle_exception $e) {
            $this->assertSame('realtime:err_no_provider', $e->errorcode);
            $this->assertDebuggingCalled();
        }
        $this->assertNull(gemini_live_token::$last_request);
    }

    public function test_a_row_with_no_key_refuses(): void {
        $this->configure();
        set_config('voice_providers', 'gemini||google|Puck|', 'local_ai_course_assistant');
        $course = $this->student();
        try {
            get_realtime_token::execute((int) $course->id);
            $this->fail('no key must refuse');
        } catch (\moodle_exception $e) {
            $this->assertSame('realtime:err_no_provider', $e->errorcode);
        }
        $this->assertNull(gemini_live_token::$last_request);
    }

    public function test_the_daily_allowance_shortens_then_refuses(): void {
        global $USER;
        $this->configure();
        set_config('gemini_live_daily_minutes', 15, 'local_ai_course_assistant');
        $course = $this->student();

        $first = get_realtime_token::execute((int) $course->id);
        $this->assertSame(600, $first['maxseconds']);
        // Never reported, so the whole 600 s counts; 300 s are left.
        $second = get_realtime_token::execute((int) $course->id);
        $this->assertSame(300, $second['maxseconds'], 'cut to what is left of the day');
        $req = gemini_live_token::$last_request;
        $this->assertSame(300 + gemini_live_token::EXPIRY_GRACE - gemini_live_token::NEW_SESSION_WINDOW,
            strtotime($req['expireTime']) - strtotime($req['newSessionExpireTime']), 'the token dies with the shorter cap');
        try {
            get_realtime_token::execute((int) $course->id);
            $this->fail('no time left must refuse');
        } catch (\moodle_exception $e) {
            $this->assertSame('realtime:err_gemini_daily_cap', $e->errorcode);
        }
        $this->assertSame(900, voice\gemini_live::seconds_used_today((int) $USER->id));
    }

    public function test_the_mint_throttle_applies_to_gemini_too(): void {
        $this->configure();
        set_config('gemini_live_daily_minutes', 0, 'local_ai_course_assistant');
        $course = $this->student();
        for ($i = 0; $i < 6; $i++) {
            get_realtime_token::execute((int) $course->id);
        }
        $this->expectException(\moodle_exception::class);
        get_realtime_token::execute((int) $course->id);
    }

    public function test_an_upstream_error_is_refused_without_the_key_and_writes_no_row(): void {
        global $DB;
        $this->configure();
        $course = $this->student();
        gemini_live_token::$test_http_response = [
            'body' => '{"error":{"message":"API key ' . self::KEY . ' not valid"}}',
            'http_code' => 400,
        ];
        try {
            get_realtime_token::execute((int) $course->id);
            $this->fail('a 400 must refuse');
        } catch (\moodle_exception $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage() . ' ' . $e->debuginfo);
        }
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_msgs', ['interaction_type' => 'gemini_live']));
    }

    public function test_a_200_without_a_token_is_refused(): void {
        $this->configure();
        $course = $this->student();
        gemini_live_token::$test_http_response = ['body' => '{"unexpected":true}', 'http_code' => 200];
        $this->expectException(\moodle_exception::class);
        get_realtime_token::execute((int) $course->id);
    }

    public function test_a_bad_model_setting_never_reaches_google(): void {
        $this->configure();
        set_config('gemini_live_model', 'gemini live?', 'local_ai_course_assistant');
        $course = $this->student();
        try {
            get_realtime_token::execute((int) $course->id);
            $this->fail('a model id that is not plain must refuse');
        } catch (\moodle_exception $e) {
            $this->assertSame('realtime:err_gemini_model', $e->errorcode);
        }
        $this->assertNull(gemini_live_token::$last_request);
    }

    public function test_the_usage_report_fills_the_row_once_for_its_owner(): void {
        global $DB;
        $this->configure();
        $course = $this->student();
        $result = get_realtime_token::execute((int) $course->id);

        $out = record_live_usage::execute((int) $course->id, (int) $result['sessionid'], 3, 1200, 3400);
        $this->assertTrue($out['recorded']);
        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $result['sessionid']]);
        $this->assertEquals(1200, $row->prompt_tokens);
        $this->assertEquals(3400, $row->completion_tokens);
        $this->assertSame('[Gemini Live session: 3s]', $row->message);
        $this->assertFalse(record_live_usage::execute((int) $course->id, (int) $result['sessionid'], 3, 1, 1)['recorded']);

        // Another learner in the same course can't touch it.
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'student');
        $this->setUser($other);
        $second = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $result['sessionid']]);
        $this->assertFalse(record_live_usage::execute((int) $course->id, (int) $second->id, 1, 9, 9)['recorded']);
    }
}
