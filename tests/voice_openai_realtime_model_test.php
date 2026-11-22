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

/**
 * OpenAI Realtime models through the voice model catalog, and the Realtime model column (8.2.0).
 *
 * OpenAI retires gpt-realtime-mini and gpt-realtime on 2027-01-20 (successors gpt-realtime-2.1-mini and
 * gpt-realtime-2.1). Before 8.2.0 the model was a literal in the WebSocket URL and in realtime.js.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\voice_model_catalog
 * @covers     \local_ai_course_assistant\voice_registry
 * @covers     \local_ai_course_assistant\admin_setting_voice_providers
 * @covers     \local_ai_course_assistant\external\get_realtime_token
 */
final class voice_openai_realtime_model_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        // 8.3.0: one test here resolves OpenAI text to speech (tts-1), which shuts down on 2027-01-06, so the class
        // runs on a fixed day before it (voice_registry reads Moodle's clock).
        $this->mock_clock_with_frozen(gmmktime(12, 0, 0, 11, 21, 2026));
    }

    protected function tearDown(): void {
        get_realtime_token::$test_http_response = null;
        parent::tearDown();
    }

    public function test_the_catalog_lists_the_openai_realtime_retirements(): void {
        $this->assertSame('gpt-realtime-2.1-mini', voice_model_catalog::default_model('openai', 'realtime'));
        foreach (['gpt-realtime-mini' => 'gpt-realtime-2.1-mini', 'gpt-realtime' => 'gpt-realtime-2.1'] as $old => $new) {
            $entry = voice_model_catalog::entry('openai', 'realtime', $old);
            $this->assertSame(voice_model_catalog::DEPRECATED, $entry['status'], $old);
            $this->assertSame('2027-01-20', $entry['shutdown'], $old);
            $this->assertSame($new, $entry['replaced_by'], $old);
            $this->assertTrue(voice_model_catalog::is_vetted('openai', 'realtime', $new), $new);
        }
    }

    public function test_a_retired_realtime_model_resolves_to_its_successor_from_the_shutdown_date(): void {
        $before = gmmktime(23, 59, 59, 1, 19, 2027);
        $after = gmmktime(0, 0, 0, 1, 20, 2027);
        $this->assertSame('gpt-realtime-mini', voice_registry::model_for('openai', 'realtime', 'gpt-realtime-mini', $before));
        $this->assertSame('gpt-realtime-2.1-mini', voice_registry::model_for('openai', 'realtime', 'gpt-realtime-mini', $after));
        $this->assertSame('gpt-realtime', voice_registry::model_for('openai', 'realtime', 'gpt-realtime', $before));
        $this->assertSame('gpt-realtime-2.1', voice_registry::model_for('openai', 'realtime', 'GPT-Realtime', $after));
        $this->assertSame('wss://api.openai.com/v1/realtime?model=gpt-realtime-2.1',
            voice_registry::endpoint_for('openai', 'realtime',
                voice_registry::model_for('openai', 'realtime', 'gpt-realtime', $after)));
    }

    public function test_the_row_realtime_model_reaches_the_url_and_never_crosses_into_tts(): void {
        set_config('voice_providers', 'openai|sk-proj-RT-1111|oa|marin|nova||gpt-realtime-2.1', 'local_ai_course_assistant');
        $row = voice_registry::parse_rows()[0];
        $this->assertSame('gpt-realtime-2.1', $row['realtime_model']);
        $this->assertSame('', $row['tts_model']);
        $rt = voice_registry::resolve(voice_registry::CAPABILITY_REALTIME);
        $this->assertSame('gpt-realtime-2.1', $rt['model']);
        $this->assertSame('wss://api.openai.com/v1/realtime?model=gpt-realtime-2.1', $rt['endpoint']);
        $this->assertSame('tts-1', voice_registry::resolve(voice_registry::CAPABILITY_TTS)['model']);
    }

    public function test_a_gemini_row_realtime_model_beats_the_setting(): void {
        set_config('gemini_live_enabled', 1, 'local_ai_course_assistant');
        set_config('gemini_live_model', 'gemini-3.8-live', 'local_ai_course_assistant');
        // Columns: provider|key|label|realtime_voice|tts_voice|tts_model|realtime_model.
        set_config('voice_providers', 'gemini|AIzaRT-2222|g|||gemini-3.8-flash-tts|gemini-9-live',
            'local_ai_course_assistant');
        $this->assertSame('gemini-9-live', voice_registry::resolve(voice_registry::CAPABILITY_REALTIME)['model']);
        set_config('voice_providers', 'gemini|AIzaRT-2222|g', 'local_ai_course_assistant');
        $this->assertSame('gemini-3.8-live', voice_registry::resolve(voice_registry::CAPABILITY_REALTIME)['model'],
            'blank column: the gemini_live_model setting');
    }

    public function test_the_editor_stores_the_seventh_column_only_when_set_and_refuses_a_bad_one(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $setting = new admin_setting_voice_providers('local_ai_course_assistant/voice_providers', 'Voice', 'desc');
        $row = static fn(string $rtmodel) => ['provider' => 'openai', 'newkey' => 'sk-proj-ED-3333', 'keepid' => '',
            'label' => 'oa', 'realtime_voice' => '', 'tts_voice' => '', 'tts_model' => '', 'realtime_model' => $rtmodel];
        $this->assertSame('', $setting->write_setting(['rows' => [$row('gpt-realtime-2.1')]]));
        $line = (string) get_config('local_ai_course_assistant', 'voice_providers');
        $this->assertCount(7, explode('|', $line));
        $this->assertStringEndsWith('|oa||||gpt-realtime-2.1', $line);
        $this->assertSame('', $setting->write_setting(['rows' => [$row('')]]));
        $this->assertCount(3, explode('|', (string) get_config('local_ai_course_assistant', 'voice_providers')),
            'no model, no trailing empty columns');
        foreach (['gpt?x=1', 'GPT-Realtime', 'a b', '../x'] as $bad) {
            $err = $setting->write_setting(['rows' => [$row($bad)]]);
            $this->assertNotSame('', $err, $bad);
            $this->assertStringNotContainsString('sk-proj-ED', $err);
        }
        $this->assertStringContainsString('aria-label="Realtime model"', $setting->output_html(''));
    }

    public function test_the_token_endpoint_returns_the_catalog_url(): void {
        set_config('voice_providers', 'openai|sk-proj-TOK-4444|oa', 'local_ai_course_assistant');
        set_config('realtime_enabled', 1, 'local_ai_course_assistant');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        get_realtime_token::$test_http_response = ['body' => '{"value":"ek_test"}', 'http_code' => 200];
        $result = get_realtime_token::execute((int) $course->id);
        $this->assertSame('openai', $result['provider']);
        $this->assertSame(voice_registry::endpoint_for('openai', 'realtime', voice_registry::model_for('openai', 'realtime', '')),
            $result['endpoint']);
        $this->assertStringContainsString('model=gpt-realtime-2.1-mini', $result['endpoint']);
    }

    public function test_realtime_js_no_longer_names_a_model(): void {
        global $CFG;
        foreach (['amd/src/realtime.js', 'amd/build/realtime.min.js'] as $file) {
            $src = (string) file_get_contents($CFG->dirroot . '/local/ai_course_assistant/' . $file);
            $this->assertNotSame('', $src, $file);
            $this->assertStringNotContainsString('gpt-realtime', $src, $file . ' must use the URL the server sends');
        }
    }
}
