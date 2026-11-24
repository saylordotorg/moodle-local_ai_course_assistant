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
 * A rebranded site never shows the default name (v8.0.0).
 *
 * Reported on a Moodle 5.3 site: after Essentials > Branding was changed, the
 * floating button's tooltip, the default survey, alert emails and several
 * prompts still said "SOLA". These tests set the four brand settings to
 * something else and check the surfaces that used to hardcode the name.
 * The browser half (the tooltip the JS rewrites, the JS-built panels) is
 * pinned by tests/js/branding-literal-check.js.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\hook_callbacks
 * @covers     \local_ai_course_assistant\survey_manager
 * @covers     \local_ai_course_assistant\usertesting_manager
 * @covers     \local_ai_course_assistant\cost_anomaly_detector
 * @covers     \local_ai_course_assistant\language_support
 */
final class branding_hardcoded_test extends \advanced_testcase {
    /**
     * Rebrand the site.
     */
    private function rebrand(): void {
        set_config('short_name', 'Atlas', 'local_ai_course_assistant');
        set_config('display_name', 'Atlas Learning Assistant', 'local_ai_course_assistant');
        set_config('institution_name', 'Example University', 'local_ai_course_assistant');
        set_config('institution_short_name', 'EXU', 'local_ai_course_assistant');
    }

    public function test_the_floating_button_is_labelled_with_the_configured_name(): void {
        global $OUTPUT, $PAGE;
        $this->resetAfterTest(true);
        $this->rebrand();
        set_config('enabled', '1', 'local_ai_course_assistant');

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Rebranded course']);
        set_config('sola_enabled_course_' . $course->id, '1', 'local_ai_course_assistant');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $PAGE = new \moodle_page();
        $PAGE->set_course($course);
        $PAGE->set_context(\context_course::instance($course->id));
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $PAGE->set_pagetype('course-view-topics');
        $PAGE->set_title($course->fullname);
        $PAGE->set_heading($course->fullname);
        $PAGE->set_state(\moodle_page::STATE_PRINTING_HEADER);
        $PAGE->set_state(\moodle_page::STATE_IN_BODY);
        $OUTPUT = new \core_renderer($PAGE, RENDERER_TARGET_GENERAL);

        $hook = new \core\hook\output\before_footer_html_generation($OUTPUT);
        hook_callbacks::inject_chat_widget($hook);
        $html = $hook->get_output();

        $this->assertMatchesRegularExpression(
            '/<button id="local-ai-course-assistant-toggle"[^>]*title="Open Atlas"[^>]*aria-label="Open Atlas"/s',
            $html
        );
        $this->assertStringNotContainsString('Open SOLA', $html);
        // The browser rewrites the label from the root's data attributes, so they
        // must carry the configured name too.
        $this->assertStringContainsString('data-shortname="Atlas"', $html);
    }

    public function test_the_default_survey_and_usability_test_use_the_configured_name(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->rebrand();

        survey_manager::ensure_default_survey();
        $title = $DB->get_field('local_ai_course_assistant_surveys', 'title', ['courseid' => 0, 'active' => 1]);
        $this->assertSame('Atlas End-of-Course Survey', $title);

        $tasks = usertesting_manager::default_tasks();
        $text = json_encode($tasks);
        $this->assertStringContainsString('Ask Atlas to explain', $text);
        $this->assertStringNotContainsString('SOLA', $text);
        $this->assertStringNotContainsString('[[tutorshort]]', $text);
    }

    public function test_a_stored_task_set_with_tokens_reaches_learners_resolved(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->rebrand();

        $DB->delete_records('local_ai_course_assistant_ut_tasks');
        $now = time();
        $DB->insert_record('local_ai_course_assistant_ut_tasks', (object) [
            'courseid' => 0,
            'title' => 'Test',
            'tasks' => json_encode(usertesting_manager::DEFAULT_TASKS),
            'external_url' => '',
            'active' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $text = json_encode(usertesting_manager::get_active_taskset(0)->tasks);
        $this->assertStringContainsString('Ask Atlas to explain', $text);
        $this->assertStringNotContainsString('[[tutorshort]]', $text);
    }

    public function test_the_cost_anomaly_email_is_named_for_the_configured_assistant(): void {
        $this->resetAfterTest(true);
        $this->rebrand();
        set_config('spend_notify_emails', 'alerts@example.com', 'local_ai_course_assistant');
        $sink = $this->redirectEmails();

        $sent = cost_anomaly_detector::maybe_send_alert(['status' => 'anomaly', 'today_usd' => 40.0,
            'median_usd' => 10.0, 'ratio' => 4.0, 'multiplier' => 2.0, 'window_days' => 7, 'top_courses' => []]);

        $this->assertTrue($sent);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('[Atlas cost anomaly]', $messages[0]->subject);
        $this->assertStringNotContainsString('SOLA', $messages[0]->subject);
        $this->assertStringContainsString('Atlas daily cost anomaly detected.', quoted_printable_decode($messages[0]->body));
        $sink->close();
    }

    public function test_the_reply_language_prompt_names_the_configured_assistant(): void {
        $this->resetAfterTest(true);
        $this->rebrand();

        $saved = language_support::prompt_section('fr', 'saved');
        $default = language_support::prompt_section('', 'default');

        $this->assertStringContainsString('as their Atlas language', $saved);
        $this->assertStringContainsString('switch Atlas to it', $saved);
        $this->assertStringContainsString('has not chosen a Atlas language', $default);
        $this->assertStringNotContainsString('SOLA language', $saved . $default);
    }

    public function test_the_prompt_leak_scrub_catches_a_rebranded_identity_line(): void {
        // The scrub lives in classes/response_safety.php (moved out of sse.php in 8.4.0); read the
        // pattern from its source and run it.
        $src = file_get_contents(__DIR__ . '/../classes/response_safety.php');
        $this->assertSame(1, preg_match("~'(/You are [^']*Online Learning Assistant[^']*)',~", $src, $m),
            'the identity-line scrub pattern is missing from response_safety.php');
        $pattern = $m[1];
        foreach (['SOLA', 'Atlas', 'Example Tutor'] as $name) {
            $this->assertSame(1, preg_match($pattern, "You are {$name} (Online Learning Assistant), an AI coach"),
                "the scrub misses a prompt that names {$name}");
        }
        $this->assertSame(0, preg_match($pattern, 'You are doing well (keep going).'));
    }
}
