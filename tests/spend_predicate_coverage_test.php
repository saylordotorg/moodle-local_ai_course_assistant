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
 * Guard: a spend row that is WRITTEN must also be COUNTED.
 *
 * analytics::spend_rows_predicate() is an allow-list for role='system' rows,
 * and every spend consumer in the plugin selects through it. So adding a new
 * telemetry writer without adding its interaction_type to that list is not a
 * partial fix, it is a no-op: the row lands in the table with correct tokens,
 * and the dashboard, the spend guard and the anomaly detector all skip it.
 *
 * This has now happened three times. RAG spend read $0.00 for exactly this
 * reason. Quiz telemetry did too, until v7.0.6. And v7.4.2 initially shipped
 * the flashcards/essay/insights writers with the predicate untouched, which
 * 1487 passing tests did not catch, because every test asserted that the ROW
 * EXISTED and none asserted that anything counted it.
 *
 * The first test is the structural lint that closes the class of bug. The
 * others are the end-to-end assertion that the three v7.4.2 types actually
 * reach the two functions the AI Spend dashboard is built on.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\analytics::spend_rows_predicate
 */
final class spend_predicate_coverage_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Every billable interaction_type conversation_manager writes is listed in
     * the predicate.
     *
     * Deliberately structural: it reads the writer's source for the types it
     * hands to add_message()/record_quiz_usage() and checks each against the
     * predicate SQL. A behavioural test can only cover the types someone
     * remembered to write a case for, which is precisely the thing that failed.
     */
    public function test_every_written_interaction_type_is_counted(): void {
        $predicate = analytics::spend_rows_predicate('m');

        // The types the ancillary/telemetry writers emit. Kept explicit rather
        // than parsed loosely, so a rename breaks this test rather than
        // silently matching nothing.
        $written = [
            'quiz'       => 'conversation_manager::record_quiz_usage',
            'flashcards' => 'generate_flashcards via log_ancillary_usage',
            'essay'      => 'score_essay via log_ancillary_usage',
            'insights'   => 'generate_insights via log_ancillary_usage',
            'embedding'  => 'base_embedding_provider::log_embedding_cost',
            'rerank'     => 'voyage_reranker::log_rerank_cost',
            // v7.4.4: five calls that were billed and counted by nothing.
            'mastery_signal'    => 'conversation_classifier::classify_and_record',
            'student_profile'   => 'student_profile_manager::generate_profile',
            'speech_score'      => 'external\\score_speech::execute',
            'objective_extract' => 'objective_manager::extract_via_llm',
            'slide_vision'      => 'soapbox_slide_vision::design_note',
            // Pre-existing gap closed while here: seven voice types have been in
            // the predicate since v7.3.3 and none were listed, so this lint's
            // coverage was narrower than its docblock claimed.
            'voice'           => 'sse.php voice-mode rows',
            'openai_tts'      => 'tts.php via voice_registry::interaction_type',
            'xai_tts'         => 'tts.php via voice_registry::interaction_type',
            'openai_whisper'  => 'transcribe.php via voice_registry::interaction_type',
            'openai_stt'      => 'transcribe.php via voice_registry::interaction_type',
            'xai_stt'         => 'transcribe.php via voice_registry::interaction_type',
            'selfhosted_stt'  => 'transcribe.php via voice_registry::interaction_type',
        ];

        $missing = [];
        foreach ($written as $type => $writer) {
            if (strpos($predicate, "'{$type}'") === false) {
                $missing[] = "'{$type}' (written by {$writer})";
            }
        }

        $this->assertSame([], $missing,
            "A telemetry writer emits an interaction_type that spend_rows_predicate() does not admit.\n"
            . "The predicate is an ALLOW-LIST for role='system' rows, so those rows are written,\n"
            . "counted by nothing, and priced at zero -- the row exists, and the dashboard reads \$0.00.\n"
            . "Add the type to analytics::spend_rows_predicate():\n  "
            . implode("\n  ", $missing));
    }

    /**
     * The v7.4.2 ancillary rows reach the dashboard pull contract.
     */
    public function test_ancillary_rows_are_priced_by_monthly_provider_spend(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $month = date('Y-m');
        $baseline = analytics::get_monthly_provider_spend($month);
        $before = $baseline['by_provider']['google'] ?? 0.0;

        foreach (['flashcards', 'essay', 'insights'] as $type) {
            $this->write_row($user->id, $course->id, $type, 100000, 50000);
        }

        $after = analytics::get_monthly_provider_spend($month);
        $this->assertArrayHasKey('google', $after['by_provider'],
            'the ancillary rows did not reach get_monthly_provider_spend at all');
        $this->assertGreaterThan($before, $after['by_provider']['google'],
            'flashcards/essay/insights spend did not move the dashboard figure; '
            . 'the rows are being filtered out by spend_rows_predicate()');
        $this->assertSame(0, $after['unpriced_rows'],
            'gemini-2.5-flash must be priceable, or the caveat masks the miss');
    }

    /**
     * ...and the site-wide token total.
     */
    public function test_ancillary_rows_are_counted_by_total_tokens(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $before = analytics::get_total_tokens(0, 0);
        $this->write_row($user->id, $course->id, 'flashcards', 1000, 500);
        $this->write_row($user->id, $course->id, 'essay', 1000, 500);
        $this->write_row($user->id, $course->id, 'insights', 1000, 500);
        $after = analytics::get_total_tokens(0, 0);

        // 3 rows x (1000 prompt + 500 completion) = 4500, plus the reasoning
        // written by write_row(), which is a gemini model and so counts.
        $this->assertGreaterThanOrEqual($before + 4500, $after,
            'ancillary spend rows are not in the site-wide token total');
    }

    /**
     * Insert one priced system row of the given interaction type.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $type
     * @param int $prompt
     * @param int $completion
     * @return void
     */
    private function write_row(int $userid, int $courseid, string $type, int $prompt, int $completion): void {
        global $DB;
        $row = new \stdClass();
        $row->conversationid   = 1;
        $row->userid           = $userid;
        $row->courseid         = $courseid;
        $row->role             = 'system';
        $row->message          = "[{$type}]";
        $row->tokens_used      = $prompt + $completion;
        $row->prompt_tokens    = $prompt;
        $row->completion_tokens = $completion;
        $row->reasoning_tokens = 250;
        $row->model_name       = 'gemini-2.5-flash';
        $row->provider         = 'google';
        $row->interaction_type = $type;
        $row->timecreated      = time();
        $DB->insert_record('local_ai_course_assistant_msgs', $row);
    }
}
