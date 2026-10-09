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

use local_ai_course_assistant\external\get_history;

/**
 * A reloaded answer shows the source pill it showed live (v7.8.4, #310).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\protocol_markers
 * @covers     \local_ai_course_assistant\external\get_history
 */
final class source_pill_history_test extends \advanced_testcase {
    public function test_the_first_closed_vocabulary_marker_wins(): void {
        $this->assertSame('page', protocol_markers::derive_source("Answer.\n[SOURCE:page]"));
        $this->assertSame('course', protocol_markers::derive_source('Answer. [[SOURCE:course]]'));
        $this->assertSame('general', protocol_markers::derive_source('[SOURCE:general] Answer. [SOURCE:page]'));
        $this->assertSame('activity:86467', protocol_markers::derive_source('Answer. [[SOURCE:activity:86467]]'));
        $this->assertSame('course', protocol_markers::derive_source('Answer. [SOURCE:activity]'), 'An activity with no id links to the course.');
    }

    public function test_a_free_form_label_is_not_a_source_type(): void {
        $this->assertNull(protocol_markers::derive_source('Answer. [SOURCE:Unit 1: Computer Programming]'));
    }

    public function test_citations_give_the_activity_when_no_marker_is_present(): void {
        $citations = [['index' => 0, 'cmid' => 0], ['index' => 1, 'cmid' => 77], ['index' => 2, 'cmid' => 88]];
        $modules = ['77' => ['url' => 'u', 'title' => 't']];
        $this->assertSame('activity:77', protocol_markers::derive_source('Claim [[c:1]]. Another [[c:2]].', $citations, $modules));
        $this->assertSame('course', protocol_markers::derive_source('Claim [[c:2]].', $citations, $modules), 'Not a visible activity.');
        $this->assertSame('course', protocol_markers::derive_source('No citations in the text.', $citations, $modules));
        $this->assertNull(protocol_markers::derive_source('No citations in the text.'), 'Nothing retrieved, nothing to show.');
    }

    public function test_the_stored_copy_still_has_no_markers(): void {
        $raw = "Answer [[c:1]].\n[SOURCE:activity:5]";
        $this->assertSame('activity:5', protocol_markers::derive_source($raw));
        $this->assertStringNotContainsString('SOURCE', protocol_markers::strip($raw));
    }

    public function test_add_message_stores_the_source_on_assistant_rows_only(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);
        $a = conversation_manager::add_message($conv->id, $user->id, $course->id, 'assistant', 'hi', 0, '', null, null, null, 'chat', null, null, null, null, null, null, null, 'activity:12');
        $u = conversation_manager::add_message($conv->id, $user->id, $course->id, 'user', 'q', 0, '', null, null, null, 'chat', null, null, null, null, null, null, null, 'activity:12');
        $this->assertSame('activity:12', $DB->get_field('local_ai_course_assistant_msgs', 'source', ['id' => $a]));
        $this->assertNull($DB->get_field('local_ai_course_assistant_msgs', 'source', ['id' => $u]));
    }

    public function test_history_returns_the_pill_with_this_learners_link(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Understanding Economic Systems']);
        $pill = get_history::source_pill((int) $course->id, 'activity:' . $page->cmid, 0);
        $this->assertSame('activity', $pill['source_type']);
        $this->assertSame((int) $page->cmid, $pill['source_cmid']);
        $this->assertSame('Understanding Economic Systems', $pill['source_title']);
        $this->assertStringContainsString('/mod/page/view.php?id=' . $page->cmid, $pill['source_url']);
    }

    public function test_a_page_pill_links_to_the_page_the_learner_was_on(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $pill = get_history::source_pill((int) $course->id, 'page', (int) $page->cmid);
        $this->assertSame('page', $pill['source_type']);
        $this->assertStringContainsString('/mod/page/view.php?id=' . $page->cmid, $pill['source_url']);
    }

    public function test_a_hidden_or_deleted_activity_falls_back_to_the_course_without_its_name(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Secret Exam Answers', 'visible' => 0]);
        $this->setUser($student);
        $hidden = get_history::source_pill((int) $course->id, 'activity:' . $page->cmid, 0);
        $this->assertSame('activity', $hidden['source_type']);
        $this->assertSame(0, $hidden['source_cmid']);
        $this->assertSame('', $hidden['source_title'], 'The name of an activity the learner cannot open is never returned.');
        $this->assertStringContainsString('/course/view.php?id=' . $course->id, $hidden['source_url']);
        $gone = get_history::source_pill((int) $course->id, 'activity:999999', 0);
        $this->assertSame('', $gone['source_title']);
        $this->assertStringContainsString('/course/view.php', $gone['source_url']);
    }

    public function test_nothing_stored_or_garbage_gives_no_pill(): void {
        foreach (['', 'activity:abc', 'bogus', 'page;drop'] as $stored) {
            $this->assertSame('', get_history::source_pill(2, $stored, 0)['source_type'], $stored);
        }
        $this->assertSame('general', get_history::source_pill(2, 'general', 0)['source_type']);
    }

    public function test_the_web_service_returns_the_pill_and_passes_return_validation(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Understanding Economic Systems']);
        $this->setUser($student);
        $conv = conversation_manager::get_or_create_conversation($student->id, $course->id);
        conversation_manager::add_message($conv->id, $student->id, $course->id, 'user', 'What is an economic system?');
        conversation_manager::add_message(
            $conv->id, $student->id, $course->id, 'assistant', 'It is how a society organizes production.',
            0, '', null, null, null, 'chat', null, null, null, null, null, null, null, 'activity:' . $page->cmid
        );
        $result = get_history::execute((int) $course->id);
        $clean = \core_external\external_api::clean_returnvalue(get_history::execute_returns(), $result);
        $this->assertCount(2, $clean['messages']);
        $this->assertSame('', $clean['messages'][0]['source_type']);
        $this->assertSame('activity', $clean['messages'][1]['source_type']);
        $this->assertSame('Understanding Economic Systems', $clean['messages'][1]['source_title']);
        $this->assertSame((int) $page->cmid, $clean['messages'][1]['source_cmid']);
    }

    public function test_the_built_bundle_carries_the_history_pill_code(): void {
        global $CFG;
        $built = file_get_contents($CFG->dirroot . '/local/ai_course_assistant/amd/build/chat.min.js');
        $this->assertStringContainsString('source_url', $built, 'Moodle serves amd/build; rebuild chat.min.js with terser.');
        $this->assertStringContainsString('source_title', $built);
    }
}
