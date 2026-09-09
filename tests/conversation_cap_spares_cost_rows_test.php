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
 * v7.4.2: the 50-pair conversation cap must not destroy the cost ledger.
 *
 * add_message() bounds a conversation at 100 rows. It used to count and delete
 * role-blind, so role='system' cost-log rows (TTS, STT, quiz, flashcards,
 * essay, insights) both consumed the learner's history budget and were
 * themselves deleted oldest-first.
 *
 * That is a data-loss bug in the one table the AI Spend dashboard reads, and it
 * scales with usage: a teacher opening the course Insights report 101 times
 * loses the first spend row on the 101st run and one more every run after; a
 * learner mixing chat with voice and flashcards evicts real assistant rows
 * carrying prompt/completion/cached/reasoning tokens before the monthly pull
 * ever sees them. Anything pruned between two exports is billed spend that is
 * never reported anywhere -- the same undercount v7.4.2 exists to close.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\conversation_manager::add_message
 */
final class conversation_cap_spares_cost_rows_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * A system cost row survives an unbounded number of later chat turns.
     */
    public function test_cost_rows_are_never_evicted_by_chat_volume(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $convid = 4242;

        // The billed row lands first, so it is the oldest and therefore the
        // first thing an oldest-first prune would take.
        $costid = conversation_manager::add_message(
            $convid, (int) $user->id, (int) $course->id, 'system', '[insights]',
            0, 'google', 50_000, 2_000, 'gemini-2.5-flash', 'insights',
            null, null, null, null, null, null, 900
        );

        // Now push the conversation well past the 100-row cap with real chat.
        for ($i = 0; $i < 120; $i++) {
            conversation_manager::add_message(
                $convid, (int) $user->id, (int) $course->id, 'user', "q{$i}");
            conversation_manager::add_message(
                $convid, (int) $user->id, (int) $course->id, 'assistant', "a{$i}",
                0, 'google', 10, 5, 'gemini-2.5-flash');
        }

        $this->assertTrue($DB->record_exists('local_ai_course_assistant_msgs', ['id' => $costid]),
            'the conversation cap deleted a billed spend row; that usage is now '
            . 'unreportable, which is the undercount this release exists to close');

        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $costid]);
        $this->assertEquals(2_000, (int) $row->completion_tokens);
        $this->assertEquals(900, (int) $row->reasoning_tokens);
    }

    /**
     * Telemetry rows do not consume the learner's 50 pairs.
     *
     * The cap exists to bound visible history. If system rows count toward it,
     * a learner who uses voice and flashcards gets a shorter transcript than
     * one who does not, for no reason they can see.
     */
    public function test_system_rows_do_not_shorten_learner_history(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $convid = 777;

        for ($i = 0; $i < 40; $i++) {
            conversation_manager::add_message(
                $convid, (int) $user->id, (int) $course->id, 'system', '[openai_tts]',
                0, 'openai', 0, 0, 'tts-1', 'openai_tts');
        }
        for ($i = 0; $i < 50; $i++) {
            conversation_manager::add_message(
                $convid, (int) $user->id, (int) $course->id, 'user', "q{$i}");
            conversation_manager::add_message(
                $convid, (int) $user->id, (int) $course->id, 'assistant', "a{$i}");
        }

        $visible = $DB->count_records_select(
            'local_ai_course_assistant_msgs',
            "conversationid = ? AND role IN ('user','assistant')",
            [$convid]
        );
        $this->assertSame(100, $visible,
            'the full 50 pairs must survive regardless of how much telemetry shares the conversation');

        $system = $DB->count_records('local_ai_course_assistant_msgs',
            ['conversationid' => $convid, 'role' => 'system']);
        $this->assertSame(40, $system, 'telemetry rows were pruned');
    }

    /**
     * The cap still works on the rows it is meant to bound.
     */
    public function test_cap_still_bounds_visible_history(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $convid = 999;

        for ($i = 0; $i < 130; $i++) {
            conversation_manager::add_message(
                $convid, (int) $user->id, (int) $course->id, 'user', "q{$i}");
        }

        $visible = $DB->count_records_select(
            'local_ai_course_assistant_msgs',
            "conversationid = ? AND role IN ('user','assistant')",
            [$convid]
        );
        $this->assertSame(100, $visible, 'the 100-row cap no longer bounds the conversation');
    }
}
