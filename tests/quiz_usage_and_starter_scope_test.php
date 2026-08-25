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
 * v7.0.6: quiz calls are accounted for, and page-scoped starters stay on pages.
 *
 * Two fixes, both of the same shape as bugs this plugin has shipped before: a
 * code path that costs money and reports nothing, and a check that exists but
 * never fires.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\starter_manager::get_effective_starters
 */
final class quiz_usage_and_starter_scope_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    // ---------- starter scoping ----------

    /**
     * The whole point: on the course home page there is no page for
     * "Explain This Page" to explain.
     */
    public function test_activity_scoped_starter_is_hidden_off_an_activity_page(): void {
        $course = $this->getDataGenerator()->create_course();

        $onactivity = starter_manager::get_effective_starters($course->id, false, false, true);
        $oncoursehome = starter_manager::get_effective_starters($course->id, false, false, false);

        $keys = fn(array $s): array => array_column($s, 'key');

        $this->assertContains('help-page', $keys($onactivity),
            'the page-explainer chip must still appear on a real activity page');
        $this->assertNotContains('help-page', $keys($oncoursehome),
            'it must NOT appear on the course home page, where {page} is the course name');
    }

    public function test_unconditional_starters_appear_in_both_contexts(): void {
        $course = $this->getDataGenerator()->create_course();
        foreach ([true, false] as $hasactivity) {
            $keys = array_column(
                starter_manager::get_effective_starters($course->id, false, false, $hasactivity),
                'key'
            );
            $this->assertContains('study-plan', $keys);
            $this->assertContains('quiz', $keys);
        }
    }

    /**
     * The parameter defaults to true so that any caller which cannot determine
     * page context keeps the pre-7.0.6 behaviour rather than silently losing a
     * chip. A default of false would have hidden the chip everywhere.
     */
    public function test_page_context_defaults_to_permissive(): void {
        $course = $this->getDataGenerator()->create_course();
        $keys = array_column(starter_manager::get_effective_starters($course->id), 'key');
        $this->assertContains('help-page', $keys);
    }

    // ---------- the study-plan copy ----------

    /**
     * The measured failure was that the chip opened by asking the learner two
     * questions. Assert the shape of the fix, not the exact wording.
     */
    public function test_study_plan_prompt_no_longer_opens_with_questions(): void {
        $defaults = starter_manager::get_defaults();
        $studyplan = null;
        foreach ($defaults as $d) {
            if ($d['key'] === 'study-plan') {
                $studyplan = $d;
            }
        }
        $this->assertNotNull($studyplan);

        $this->assertStringNotContainsString('Please ask me:', $studyplan['prompt'],
            'the superseded copy instructed the assistant to interrogate the learner first');
        $this->assertMatchesRegularExpression('/\b(suggest|propose)\b/i', $studyplan['prompt'],
            'the chip must lead by proposing something');
    }

    // ---------- spend accounting ----------

    /**
     * Regression guard for the actual defect, exercised end to end.
     *
     * The first version of this test asserted only that the string 'quiz'
     * appeared in capability_sql('chat'). That passed while the fix was inert:
     * rows are gated by analytics::spend_rows_predicate() BEFORE the capability
     * clause is ANDed on, and that predicate matched only role='assistant' plus
     * embedding/rerank -- so a role='system' quiz row was rejected before the
     * capability clause could ever see it. Testing the pieces proved nothing
     * about the assembled query, again. This writes a row and asserts the total
     * moves.
     */
    public function test_a_recorded_quiz_call_actually_increases_measured_spend(): void {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $before = analytics::get_total_tokens();

        conversation_manager::record_quiz_usage(
            $user->id, $course->id, '[Quiz] 4 question(s) on Ratios',
            'openai', 'gpt-4o-mini', 3000, 700
        );

        $after = analytics::get_total_tokens();

        $this->assertSame($before + 3700, $after,
            'a quiz call that cost 3700 tokens must move the billable total by 3700');
    }

    /**
     * The predicate is the gate that made the first fix inert, so pin it
     * directly as well -- a future edit that drops 'quiz' from it would
     * silently stop counting quiz spend again.
     */
    public function test_spend_predicate_admits_quiz_rows(): void {
        $sql = analytics::spend_rows_predicate('m');
        $this->assertStringContainsString("'quiz'", $sql,
            'quiz telemetry is role=system, so it only counts if the predicate names it');
        // And the capability bucket it lands in, so the cap applies to it.
        $this->assertStringContainsString("'quiz'", spend_guard::capability_sql('chat'));
    }

    /**
     * A quiz telemetry row must not reach the learner's visible history or the
     * model's context. role='system' is what keeps it out; this pins that,
     * because writing it as user/assistant would inject quiz JSON into chat.
     */
    public function test_system_rows_are_excluded_from_conversation_history(): void {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $conv = conversation_manager::get_or_create_conversation($user->id, $course->id);

        conversation_manager::add_message(
            $conv->id, $user->id, $course->id, 'user', 'What is a balance sheet?'
        );
        conversation_manager::record_quiz_usage(
            $user->id, $course->id, '[Quiz] 3 question(s) on Unit 1',
            'openai', 'gpt-4o-mini', 1200, 340
        );

        $history = conversation_manager::get_history_for_api($conv->id);
        $contents = array_column($history, 'content');

        $this->assertContains('What is a balance sheet?', $contents);
        foreach ($contents as $c) {
            $this->assertStringNotContainsString('[Quiz]', $c,
                'quiz telemetry must never be replayed to the learner or the model');
        }
    }

    /**
     * And the row must still be countable for spend even though it is hidden
     * from history -- the two requirements pull in opposite directions, so both
     * are asserted together.
     */
    public function test_quiz_row_is_stored_with_its_token_counts(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        conversation_manager::record_quiz_usage(
            $user->id, $course->id, '[Quiz] 5 question(s) on Debits',
            'openai', 'gpt-4o-mini', 2048, 512
        );

        $row = $DB->get_record('local_ai_course_assistant_msgs',
            ['courseid' => $course->id, 'interaction_type' => 'quiz']);

        $this->assertNotFalse($row, 'the quiz row must be persisted');
        $this->assertEquals(2048, (int) $row->prompt_tokens);
        $this->assertEquals(512, (int) $row->completion_tokens);
        // The point of the direct insert: without a model the row is counted
        // but cannot be priced, so spend_guard would total it as zero.
        $this->assertEquals('gpt-4o-mini', $row->model_name,
            'a telemetry row without a model name is uncostable');
        $this->assertEquals('openai', $row->provider);
        $this->assertEquals('system', $row->role,
            'role must stay system so the row never reaches the learner');
    }
}
