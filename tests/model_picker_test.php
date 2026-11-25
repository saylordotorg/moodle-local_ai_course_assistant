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

use local_ai_course_assistant\tests\settings_tree;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/fixtures/settings_tree.php');

/**
 * The chat's LLM provider and model pickers are hidden unless an admin turns them on (8.4.0).
 *
 * Setting chat_show_model_picker, default off. Off: the widget carries no picker markup and no provider or model
 * list, and a request that names a provider or model is answered by the configured model. On: as before 8.4.0,
 * for holders of the manage capability only.
 *
 * The widget is rendered through the real hook (hook_callbacks::inject_chat_widget). sse.php is a script a test
 * can't load, so its decision lives in model_picker::request_override() and the call site is pinned by source.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\model_picker
 */
final class model_picker_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        set_config('enabled', '1', 'local_ai_course_assistant');
        set_config('provider', 'openai', 'local_ai_course_assistant');
        set_config('model', 'gpt-picker-sentinel', 'local_ai_course_assistant');
        $this->course = $this->getDataGenerator()->create_course();
        set_config('sola_enabled_course_' . $this->course->id, '1', 'local_ai_course_assistant');
    }

    /**
     * Render the widget on the course page through the real hook, as the current user.
     *
     * @return string
     */
    private function render_widget(): string {
        global $OUTPUT, $PAGE;
        $PAGE = new \moodle_page();
        $PAGE->set_course($this->course);
        $PAGE->set_context(\context_course::instance($this->course->id));
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $this->course->id]));
        $PAGE->set_pagetype('course-view-topics');
        $PAGE->set_title($this->course->fullname);
        $PAGE->set_heading($this->course->fullname);
        $PAGE->set_state(\moodle_page::STATE_PRINTING_HEADER);
        $PAGE->set_state(\moodle_page::STATE_IN_BODY);
        $OUTPUT = new \core_renderer($PAGE, RENDERER_TARGET_GENERAL);
        $hook = new \core\hook\output\before_footer_html_generation($OUTPUT);
        hook_callbacks::inject_chat_widget($hook);
        $html = $hook->get_output();
        $this->assertStringContainsString('id="local-ai-course-assistant"', $html, 'The widget did not render.');
        return $html;
    }

    /**
     * The data-llm-options attribute of the rendered widget, decoded.
     *
     * @param string $html
     * @return mixed
     */
    private function llm_options(string $html) {
        $this->assertSame(1, preg_match('/data-llm-options="([^"]*)"/', $html, $m));
        return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
    }

    public function test_the_setting_is_a_checkbox_that_is_off_by_default(): void {
        $this->setAdminUser();
        $found = null;
        foreach (settings_tree::settings(settings_tree::category()) as $setting) {
            if ($setting->name === model_picker::SETTING) {
                $found = $setting;
            }
        }
        $this->assertInstanceOf(\admin_setting_configcheckbox::class, $found);
        $this->assertSame('0', (string) $found->get_defaultsetting());
        // An upgraded site has no stored value at all: that reads as off too.
        unset_config(model_picker::SETTING, 'local_ai_course_assistant');
        $this->assertFalse(model_picker::enabled());
    }

    public function test_an_admin_gets_no_pickers_and_no_model_list_by_default(): void {
        $this->setAdminUser();
        $html = $this->render_widget();
        $this->assertStringNotContainsString('composer-select', $html);
        $this->assertStringNotContainsString('composer-left', $html);
        $this->assertStringNotContainsString('gpt-picker-sentinel', $html);
        $this->assertSame([], $this->llm_options($html));
    }

    public function test_an_admin_gets_the_pickers_and_the_model_list_when_the_setting_is_on(): void {
        set_config(model_picker::SETTING, '1', 'local_ai_course_assistant');
        $this->setAdminUser();
        $html = $this->render_widget();
        $this->assertStringContainsString('local-ai-course-assistant__composer-select--provider', $html);
        $this->assertStringContainsString('local-ai-course-assistant__composer-select--model', $html);
        $options = $this->llm_options($html);
        $this->assertTrue($options['enabled']);
        $this->assertSame('openai', $options['providers'][0]['id']);
        $this->assertSame('gpt-picker-sentinel', $options['providers'][0]['models'][0]['id']);
    }

    public function test_a_learner_never_gets_the_pickers_even_when_the_setting_is_on(): void {
        set_config(model_picker::SETTING, '1', 'local_ai_course_assistant');
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $html = $this->render_widget();
        $this->assertStringNotContainsString('composer-select', $html);
        $this->assertStringNotContainsString('gpt-picker-sentinel', $html);
        $this->assertSame([], $this->llm_options($html));
    }

    public function test_a_request_cannot_pick_a_model_while_the_setting_is_off(): void {
        $this->setAdminUser();
        $this->assertSame(['', ''],
            model_picker::request_override('claude', 'claude-opus-5-5', (int) $this->course->id));
    }

    public function test_an_admin_request_picks_the_model_when_the_setting_is_on(): void {
        set_config(model_picker::SETTING, '1', 'local_ai_course_assistant');
        $this->setAdminUser();
        $this->assertSame(['claude', 'claude-opus-5-5'],
            model_picker::request_override('claude', 'claude-opus-5-5', (int) $this->course->id));
        // No provider named: nothing to override, and a model alone is dropped.
        $this->assertSame(['', ''], model_picker::request_override('', 'claude-opus-5-5', (int) $this->course->id));
    }

    public function test_a_learner_request_never_picks_a_model(): void {
        set_config(model_picker::SETTING, '1', 'local_ai_course_assistant');
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $this->assertSame(['', ''],
            model_picker::request_override('claude', 'claude-opus-5-5', (int) $this->course->id));
    }

    /**
     * sse.php must take the override only from request_override(): the raw request values reach nothing else.
     */
    public function test_sse_takes_the_override_only_through_the_gate(): void {
        global $CFG;
        $src = preg_replace('/\s+/', ' ', file_get_contents($CFG->dirroot . '/local/ai_course_assistant/sse.php'));
        $gate = '[$clientprovider, $clientmodel] = \local_ai_course_assistant\model_picker::request_override( '
            . '$clientprovider, $clientmodel, $courseid );';
        $gatepos = strpos($src, $gate);
        $this->assertNotFalse($gatepos, 'sse.php no longer passes the request override through the gate.');
        // Every use of the two request values other than reading them comes after the gate.
        foreach (['$clientprovider', '$clientmodel'] as $var) {
            $read = strpos($src, $var . ' = ');
            $this->assertNotFalse($read);
            $next = strpos($src, $var, $read + strlen($var));
            $this->assertGreaterThanOrEqual($gatepos, $next, $var . ' is used before the gate.');
        }
        $this->assertSame(1, substr_count($src, 'create_for_comparison($clientprovider, $clientmodel, $courseid)'));
        $this->assertStringContainsString("if (\$clientprovider !== '') { \$provider = base_provider::"
            . 'create_for_comparison($clientprovider, $clientmodel, $courseid);', $src);
    }

    /**
     * The other chat transport (the send_message web service) takes no provider or model at all.
     */
    public function test_the_send_message_service_accepts_no_provider_or_model(): void {
        $params = \local_ai_course_assistant\external\send_message::execute_parameters();
        $this->assertArrayNotHasKey('provider', $params->keys);
        $this->assertArrayNotHasKey('model', $params->keys);
    }
}
