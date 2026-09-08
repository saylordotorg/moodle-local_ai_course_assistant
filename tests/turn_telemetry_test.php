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

/**
 * v7.1.1: turn telemetry — why a turn ended, and what retrieval found.
 *
 * The study behind v7.1.0 found 9.5% of conversations containing a question with
 * no stored answer, and could not say whether that was a bug or a bounce: a
 * provider error and a learner closing the tab produced byte-identical evidence,
 * namely no row at all. These columns exist to end that ambiguity, so the tests
 * are mostly about the three states being distinguishable.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\conversation_manager::add_message
 */
final class turn_telemetry_test extends \advanced_testcase {

    /** @var \stdClass */
    private $user;
    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $conv;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->user = $this->getDataGenerator()->create_user();
        $this->course = $this->getDataGenerator()->create_course();
        $this->conv = conversation_manager::get_or_create_conversation(
            $this->user->id, $this->course->id);
    }

    /**
     * Write an assistant row with the given telemetry and return it.
     *
     * @param string|null $outcome
     * @param int|null $chunks
     * @param float|null $top
     * @return \stdClass
     */
    private function write(?string $outcome, ?int $chunks = null, ?float $top = null): \stdClass {
        global $DB;
        $id = conversation_manager::add_message(
            $this->conv->id, $this->user->id, $this->course->id, 'assistant',
            'reply', 0, 'openai', 100, 50, 'gpt-4o-mini', 'chat', null, null, null,
            $outcome, $chunks, $top
        );
        return $DB->get_record('local_ai_course_assistant_msgs', ['id' => $id]);
    }

    public function test_the_three_outcomes_are_distinguishable(): void {
        $this->assertSame('complete', $this->write('complete')->stream_outcome);
        $this->assertSame('client_aborted', $this->write('client_aborted')->stream_outcome);
        $this->assertSame('provider_error', $this->write('provider_error')->stream_outcome);
    }

    /**
     * The whole point: before this, "learner left" and "it broke" were the same
     * observation. They must now be separable by query.
     */
    public function test_abandonment_and_failure_can_be_counted_apart(): void {
        global $DB;
        $this->write('complete');
        $this->write('complete');
        $this->write('client_aborted');
        $this->write('provider_error');

        $counts = $DB->get_records_sql(
            "SELECT stream_outcome, COUNT(*) AS n
               FROM {local_ai_course_assistant_msgs}
              WHERE courseid = :cid AND stream_outcome IS NOT NULL
           GROUP BY stream_outcome",
            ['cid' => $this->course->id]
        );
        $this->assertSame(2, (int) $counts['complete']->n);
        $this->assertSame(1, (int) $counts['client_aborted']->n);
        $this->assertSame(1, (int) $counts['provider_error']->n);
    }

    /**
     * Zero is a finding, null is an absence of one. Retrieval that ran and found
     * nothing must not look the same as retrieval that never ran.
     */
    public function test_zero_chunks_is_distinct_from_no_retrieval(): void {
        $ran = $this->write('complete', 0, null);
        $never = $this->write('complete', null, null);

        $this->assertSame(0, (int) $ran->chunk_count);
        $this->assertNotNull($ran->chunk_count);
        $this->assertNull($never->chunk_count,
            'null means retrieval did not run; 0 means it ran and found nothing');
    }

    public function test_the_top_score_is_stored_with_useful_precision(): void {
        $row = $this->write('complete', 3, 0.847213);
        $this->assertEqualsWithDelta(0.847213, (float) $row->top_score, 0.0000005,
            'six decimals, or near-tie margins are not measurable');
    }

    /**
     * These describe the reply, so like the token counters they are meaningless
     * on the learner's own row and must not be written there.
     */
    public function test_telemetry_is_not_written_to_the_learner_row(): void {
        global $DB;
        $id = conversation_manager::add_message(
            $this->conv->id, $this->user->id, $this->course->id, 'user',
            'What is a balance sheet?', 0, '', null, null, null, 'chat', null, null, null,
            'complete', 5, 0.9
        );
        $row = $DB->get_record('local_ai_course_assistant_msgs', ['id' => $id]);
        $this->assertNull($row->stream_outcome);
        $this->assertNull($row->chunk_count);
        $this->assertNull($row->top_score);
    }

    /**
     * Rows written before v7.1.1 carry null, and must stay honestly unknown
     * rather than being counted as successes.
     */
    public function test_legacy_rows_stay_null_rather_than_assumed_complete(): void {
        $legacy = $this->write(null);
        $this->assertNull($legacy->stream_outcome,
            'a turn from before this release is unknown, not complete');
    }

    /**
     * Telemetry rows must not become spend. quiz_open carries no model, and
     * spend_rows_predicate() prices by model.
     */
    public function test_quiz_open_rows_are_not_billable(): void {
        global $DB;
        $before = analytics::get_total_tokens();
        $row = new \stdClass();
        $row->conversationid = $this->conv->id;
        $row->userid = $this->user->id;
        $row->courseid = $this->course->id;
        $row->role = 'system';
        $row->message = '[Quiz panel opened]';
        $row->tokens_used = 0;
        $row->interaction_type = 'quiz_open';
        $row->timecreated = time();
        $DB->insert_record('local_ai_course_assistant_msgs', $row);

        $this->assertSame($before, analytics::get_total_tokens(),
            'a panel-open marker is not spend');
    }

    /**
     * And it must stay out of the learner's history and the model's context,
     * for the same reason every other system row does.
     */
    public function test_quiz_open_rows_never_reach_the_model(): void {
        global $DB;
        $row = new \stdClass();
        $row->conversationid = $this->conv->id;
        $row->userid = $this->user->id;
        $row->courseid = $this->course->id;
        $row->role = 'system';
        $row->message = '[Quiz panel opened]';
        $row->tokens_used = 0;
        $row->interaction_type = 'quiz_open';
        $row->timecreated = time();
        $DB->insert_record('local_ai_course_assistant_msgs', $row);

        foreach (conversation_manager::get_history_for_api($this->conv->id) as $m) {
            $this->assertStringNotContainsString('[Quiz panel opened]', $m['content']);
        }
    }
}
