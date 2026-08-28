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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * v7.2.0: course backup and restore actually round-trips the plugin's data.
 *
 * Reported on CONTRIB-10574 as HIGH: the plugin stored per-course configuration
 * and per-learner activity against a courseid with no backup/moodle2
 * implementation, so duplicating or restoring a course silently dropped all of
 * it. A unit test asserting the classes exist would not have caught that -- the
 * only convincing evidence is a real backup followed by a real restore, so this
 * drives the actual backup and restore controllers.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_local_ai_course_assistant_plugin
 * @covers     \restore_local_ai_course_assistant_plugin
 */
final class backup_restore_test extends \advanced_testcase {

    /**
     * Back up a course and restore it into a new one, returning the new course id.
     *
     * @param int $courseid Source course.
     * @param bool $withusers Include user data.
     * @return int New course id.
     */
    private function duplicate_course(int $courseid, bool $withusers): int {
        global $USER, $CFG;

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value($withusers);
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $bc->destroy();

        $tmp = 'aica_test_restore';
        $path = $CFG->tempdir . '/backup/' . $tmp;
        $fp = get_file_packer('application/vnd.moodle.backup');
        $file->extract_to_pathname($fp, $path);

        $newid = \restore_dbops::create_new_course('Restored', 'RESTORED-' . $courseid, 1);
        $rc = new \restore_controller(
            $tmp,
            $newid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value($withusers);
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        return $newid;
    }

    /**
     * The headline case: course configuration survives a duplicate.
     */
    public function test_per_course_pedagogy_overrides_survive_a_duplicate(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cid = (int) $course->id;

        // These live in config_plugins as "<setting>_course_<id>", not in the
        // course_cfg table, and none of them travelled before v7.2.1. A teacher
        // duplicating a course kept the model and system prompt -- so the copy
        // looked right -- while every pedagogy decision reverted to site default.
        set_config('socratic_mode_course_' . $cid, '1', 'local_ai_course_assistant');
        set_config('flashcards_enabled_course_' . $cid, '0', 'local_ai_course_assistant');
        set_config('english_lock_course_' . $cid, '1', 'local_ai_course_assistant');
        set_config('rag_enabled_course_' . $cid, '0', 'local_ai_course_assistant');

        $newid = $this->duplicate_course($cid, false);

        $this->assertSame(
            '1',
            get_config('local_ai_course_assistant', 'socratic_mode_course_' . $newid),
            'Socratic mode was silently reset to the site default on the copy.'
        );
        $this->assertSame(
            '0',
            get_config('local_ai_course_assistant', 'flashcards_enabled_course_' . $newid),
            'A deliberate "force off" must survive; falling back to the site '
                . 'default silently turns the feature back on.'
        );
        $this->assertSame('1', get_config('local_ai_course_assistant', 'english_lock_course_' . $newid));
        $this->assertSame('0', get_config('local_ai_course_assistant', 'rag_enabled_course_' . $newid));
    }

    public function test_a_per_course_credential_is_never_written_into_the_backup(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cid = (int) $course->id;
        set_config('rerank_apikey_course_' . $cid, 'sk-live-must-not-travel', 'local_ai_course_assistant');
        set_config('socratic_mode_course_' . $cid, '1', 'local_ai_course_assistant');

        $newid = $this->duplicate_course($cid, false);

        $this->assertFalse(
            get_config('local_ai_course_assistant', 'rerank_apikey_course_' . $newid),
            'A per-course credential must not be carried across by the settings backup.'
        );
        $this->assertSame(
            '1',
            get_config('local_ai_course_assistant', 'socratic_mode_course_' . $newid),
            'Excluding credentials must not also exclude ordinary overrides.'
        );
    }

    public function test_course_configuration_survives_a_duplicate(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('local_ai_course_assistant_course_cfg', (object) [
            'courseid' => $course->id,
            'enabled' => 1,
            'provider' => 'claude',
            'model' => 'claude-haiku-4-5',
            'systemprompt' => 'Be exceptionally concise.',
            'temperature' => '0.30000',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $newid = $this->duplicate_course((int) $course->id, false);

        $cfg = $DB->get_record('local_ai_course_assistant_course_cfg', ['courseid' => $newid]);
        $this->assertNotFalse($cfg, 'the course AI configuration must come across');
        $this->assertSame('claude', $cfg->provider);
        $this->assertSame('Be exceptionally concise.', $cfg->systemprompt);
    }

    /**
     * A per-course API key must NOT travel inside a backup file.
     */
    public function test_a_per_course_api_key_is_not_carried_into_the_backup(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('local_ai_course_assistant_course_cfg', (object) [
            'courseid' => $course->id,
            'enabled' => 1,
            'provider' => 'openai',
            'apikey' => 'sk-secret-value-that-must-not-travel',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $newid = $this->duplicate_course((int) $course->id, false);

        $cfg = $DB->get_record('local_ai_course_assistant_course_cfg', ['courseid' => $newid]);
        $this->assertNotFalse($cfg);
        $this->assertEmpty($cfg->apikey,
            'a credential must never be copied into a course backup file');
    }

    /**
     * Objectives are course structure and must survive without user data.
     */
    public function test_objectives_survive_and_are_remapped(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $objid = $DB->insert_record('local_ai_course_assistant_objs', (object) [
            'courseid' => $course->id,
            'sortorder' => 1,
            'code' => 'OBJ-1',
            'title' => 'Explain opportunity cost',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $newid = $this->duplicate_course((int) $course->id, false);

        $objs = $DB->get_records('local_ai_course_assistant_objs', ['courseid' => $newid]);
        $this->assertCount(1, $objs);
        $new = reset($objs);
        $this->assertSame('Explain opportunity cost', $new->title);
        $this->assertNotEquals($objid, $new->id, 'the restored row is a new row');
    }

    /**
     * Learner conversations come across when users are included.
     */
    public function test_conversations_and_messages_survive_with_users(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);
        conversation_manager::add_message(
            $conv->id, $user->id, $course->id, 'user', 'What is a balance sheet?');
        conversation_manager::add_message(
            $conv->id, $user->id, $course->id, 'assistant', 'A statement of position.',
            0, 'openai', 100, 20, 'gpt-4o-mini', 'chat', null, null, null, 'complete', 3, 0.81);

        $newid = $this->duplicate_course((int) $course->id, true);

        $convs = $DB->get_records('local_ai_course_assistant_convs', ['courseid' => $newid]);
        $this->assertCount(1, $convs, 'the conversation must come across');
        $newconv = reset($convs);
        $msgs = $DB->get_records('local_ai_course_assistant_msgs',
            ['conversationid' => $newconv->id], 'timecreated ASC, id ASC');
        $this->assertCount(2, $msgs);
        $texts = array_values(array_map(fn($m) => $m->message, $msgs));
        $this->assertContains('What is a balance sheet?', $texts);
        // The telemetry columns added in 7.1.1 must travel too.
        $assistant = array_values(array_filter($msgs, fn($m) => $m->role === 'assistant'))[0];
        $this->assertSame('complete', $assistant->stream_outcome);
        $this->assertSame(3, (int) $assistant->chunk_count);
    }

    /**
     * Without users, learner activity must NOT be restored -- that is what the
     * backup setting means, and copying it would be a privacy failure.
     */
    public function test_learner_activity_is_omitted_without_users(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);
        conversation_manager::add_message(
            $conv->id, $user->id, $course->id, 'user', 'Private question');

        $newid = $this->duplicate_course((int) $course->id, false);

        $this->assertSame(0,
            $DB->count_records('local_ai_course_assistant_convs', ['courseid' => $newid]),
            'a backup without users must not carry conversations');
    }

    /**
     * A practice score written against a GLOBAL rubric must not abort the restore.
     *
     * practice_scores.rubricid is NOT NULL, and the default rubrics are global
     * (courseid = 0), so a course backup never carries the rubric those scores
     * point at. Nulling the column on a missed mapping threw
     * dml_write_exception and took the whole restore down with it -- and because
     * global rubrics are the default state rather than an edge case, this was the
     * common path, not a rare one.
     */
    public function test_a_score_against_a_global_rubric_does_not_abort_the_restore(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // A global rubric, as ensure_default_rubrics() creates them.
        $globalrubric = $DB->insert_record('local_ai_course_assistant_rubrics', (object) [
            'courseid' => 0,
            'type' => 'speech',
            'title' => 'Default speech rubric',
            'criteria' => '[]',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        // overall_score is int(3) and session_duration is NOT NULL with no
        // default. MySQL coerced a decimal string and defaulted the missing
        // column; Postgres rejected both, which is how CI caught this fixture.
        $DB->insert_record('local_ai_course_assistant_practice_scores', (object) [
            'rubricid' => $globalrubric,
            'userid' => $user->id,
            'courseid' => $course->id,
            'session_type' => 'speech',
            'scores' => '{}',
            'overall_score' => 4,
            'session_duration' => 120,
            'timecreated' => time(),
        ]);

        // Must not throw.
        $newid = $this->duplicate_course((int) $course->id, true);

        $scores = $DB->get_records('local_ai_course_assistant_practice_scores', ['courseid' => $newid]);
        $this->assertCount(1, $scores, 'the score should survive, resolved to the global rubric');
        $this->assertEquals($globalrubric, (int) reset($scores)->rubricid);
    }

    /**
     * Restoring into a course that already holds the learner's rows must not
     * abort on a duplicate key. convs, plans, learner_goals and streak all carry
     * a unique constraint on (userid, courseid).
     */
    public function test_restoring_over_existing_learner_rows_does_not_abort(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);
        conversation_manager::add_message($conv->id, $user->id, $course->id, 'user', 'First question');
        $DB->insert_record('local_ai_course_assistant_streak', (object) [
            'userid' => $user->id, 'courseid' => $course->id,
            'current_streak_days' => 3, 'longest_streak_days' => 5,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        // Restore the course's own backup back into itself: the target already
        // holds a conversation and a streak row for this learner.
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE, (int) $course->id, \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, get_admin()->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        global $CFG;
        $tmp = 'aica_merge_restore';
        $file->extract_to_pathname(
            get_file_packer('application/vnd.moodle.backup'),
            $CFG->tempdir . '/backup/' . $tmp
        );
        $rc = new \restore_controller(
            $tmp, (int) $course->id, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL,
            get_admin()->id, \backup::TARGET_EXISTING_ADDING
        );
        $rc->get_plan()->get_setting('users')->set_value(true);
        $rc->execute_precheck();
        // The assertion is that this does not throw a duplicate-key exception.
        $rc->execute_plan();
        $rc->destroy();

        $this->assertSame(1,
            $DB->count_records('local_ai_course_assistant_streak',
                ['userid' => $user->id, 'courseid' => $course->id]),
            'the unique row must not be duplicated');
    }

    /**
     * The RAG index is deliberately excluded: derived, regenerable, and by far
     * the largest table the plugin owns.
     */
    public function test_the_rag_index_is_deliberately_not_backed_up(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('local_ai_course_assistant_chunks', (object) [
            'courseid' => $course->id,
            'cmid' => 0,
            'modtype' => 'page',
            'chunkindex' => 0,
            'content' => 'chunk text',
            'contenthash' => sha1('chunk text'),
            'timecreated' => time(),
            'timeindexed' => time(),
        ]);

        $newid = $this->duplicate_course((int) $course->id, true);

        $this->assertSame(0,
            $DB->count_records('local_ai_course_assistant_chunks', ['courseid' => $newid]),
            'the index is rebuilt by reindexing, not carried in every backup');
    }
}
