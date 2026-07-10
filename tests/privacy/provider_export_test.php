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

namespace local_ai_course_assistant\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider export-coverage tests (v6.8.11).
 *
 * The mastery-attempt (obj_att) and struggle-signal tables were declared in
 * the privacy metadata and purged on erasure, but were missing from
 * export_user_data(), so a subject-access request (Article 15) omitted them.
 * This test locks in their inclusion in the export.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\privacy\provider::export_user_data
 */
final class provider_export_test extends \advanced_testcase {

    public function test_export_includes_mastery_attempts_and_struggle_signals(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $now = time();

        $objectiveid = $DB->insert_record('local_ai_course_assistant_objs', (object) [
            'courseid' => $course->id, 'title' => 'Solve linear equations',
            'code' => 'ALG-1', 'description' => '', 'source' => 'seed',
            'prereq_ids' => '', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $attid = $DB->insert_record('local_ai_course_assistant_obj_att', (object) [
            'userid' => $user->id, 'courseid' => $course->id,
            'objectiveid' => $objectiveid, 'source' => 'conversation',
            'msgid' => 0, 'iscorrect' => 1, 'weight' => 0.3,
            'confidence' => 0.9, 'timecreated' => $now,
        ]);
        $sigid = $DB->insert_record('local_ai_course_assistant_struggle_signal', (object) [
            'userid' => $user->id, 'courseid' => $course->id,
            'session_id' => 'sess-1', 'topic_hint' => 'algebra',
            'stage1_score' => 2, 'stage2_label' => 'frustrated',
            'followup_sent_at' => 0, 'timecreated' => $now,
        ]);

        $context = \context_course::instance($course->id);
        $this->setUser($user);

        $writer = writer::with_context($context);
        $this->assertFalse($writer->has_any_data());

        $contextlist = new approved_contextlist($user, 'local_ai_course_assistant', [$context->id]);
        provider::export_user_data($contextlist);

        $this->assertTrue($writer->has_any_data());
        $plugin = get_string('pluginname', 'local_ai_course_assistant');

        $mastery = $writer->get_data([$plugin, 'mastery_attempts', $attid]);
        $this->assertNotEmpty($mastery);
        $this->assertEquals('conversation', $mastery->source);
        $this->assertEquals($objectiveid, $mastery->objectiveid);

        $struggle = $writer->get_data([$plugin, 'struggle_signals', $sigid]);
        $this->assertNotEmpty($struggle);
        $this->assertEquals('frustrated', $struggle->stage2_label);
        $this->assertEquals('algebra', $struggle->topic_hint);
    }
}
