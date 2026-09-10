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
 * A logged ancillary row must actually be COUNTED, not merely written.
 *
 * Writing a usage row whose interaction_type the predicate does not admit
 * produces a row that is counted by nothing and priced at zero. That has
 * happened three times in this codebase -- RAG, quiz, and the v7.4.2 ancillary
 * trio -- so the structural lint next door checks the names line up. This file
 * checks the behaviour instead: write a real row through the real writer and
 * assert the money-truth consumers see it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\conversation_manager::log_ancillary_usage
 */
final class ancillary_writers_counted_test extends \advanced_testcase {

    /** @var string[] The five types added in v7.4.4. */
    private const NEW_TYPES = [
        'mastery_signal', 'student_profile', 'speech_score',
        'objective_extract', 'slide_vision',
    ];

    /**
     * A stub provider that reports a fixed usage array, including the provider
     * id -- so this also exercises the off-tier attribution path.
     *
     * @param string $providerid Provider id to report.
     * @param string $model Model to report.
     * @return object
     */
    private function stub_provider(string $providerid, string $model): object {
        return new class ($providerid, $model) {
            /** @var string */
            private $pid;
            /** @var string */
            private $model;

            /**
             * @param string $pid
             * @param string $model
             */
            public function __construct(string $pid, string $model) {
                $this->pid = $pid;
                $this->model = $model;
            }

            /**
             * @return array
             */
            public function get_last_token_usage(): array {
                return [
                    'prompt_tokens' => 1000,
                    'completion_tokens' => 500,
                    'model' => $this->model,
                    'provider' => $this->pid,
                    'reasoning_tokens' => null,
                ];
            }
        };
    }

    /**
     * Each new type, written through the real writer, must be visible to the
     * predicate and carry its tokens.
     */
    public function test_each_new_type_is_written_and_counted(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $provider = $this->stub_provider('openai', 'gpt-4o-mini');

        $before = analytics::get_total_tokens();

        foreach (self::NEW_TYPES as $type) {
            conversation_manager::log_ancillary_usage(
                $provider, (int) $user->id, (int) $course->id, $type, "[test] {$type}"
            );
        }

        $predicate = analytics::spend_rows_predicate('m');
        foreach (self::NEW_TYPES as $type) {
            $counted = $DB->count_records_sql(
                "SELECT COUNT(1) FROM {local_ai_course_assistant_msgs} m
                  WHERE {$predicate} AND m.interaction_type = :t",
                ['t' => $type]
            );
            $this->assertSame(
                1,
                $counted,
                "A '{$type}' row was written but the billable predicate does not admit it, "
                . 'so it is counted by nothing and priced at zero. Add it to '
                . 'analytics::spend_rows_predicate().'
            );
        }

        $this->assertSame(
            $before + (5 * 1500),
            analytics::get_total_tokens(),
            'get_total_tokens() must include the new rows; if it does not, the site-wide '
            . 'spend total still under-reports by exactly this work.'
        );
    }

    /**
     * The row must record the provider that SERVED the call, not the course's
     * configured chat provider. The mastery classifier and slide vision both
     * route off-tier deliberately, so this is the common case for them.
     */
    public function test_off_tier_provider_is_recorded_not_the_course_config(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        // Course says gemini; the call was actually served by openai.
        course_config_manager::save((int) $course->id, ['provider' => 'gemini']);
        $provider = $this->stub_provider('openai', 'gpt-4o-mini');

        conversation_manager::log_ancillary_usage(
            $provider, (int) $user->id, (int) $course->id, 'mastery_signal', '[test] off-tier'
        );

        $row = $DB->get_record('local_ai_course_assistant_msgs',
            ['interaction_type' => 'mastery_signal'], '*', MUST_EXIST);

        $this->assertSame(
            'openai',
            $row->provider,
            'The row recorded the course-configured provider instead of the one that served '
            . 'the call. by_provider is what the external spend dashboard reads, so this '
            . 'moves real money onto a vendor that never saw the request.'
        );
        $this->assertSame('gpt-4o-mini', $row->model_name);
    }

    /**
     * These rows must stay out of the learner's history and the model's context.
     */
    public function test_ancillary_rows_never_reach_the_learner_or_the_model(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $conv = conversation_manager::get_or_create_conversation((int) $user->id, (int) $course->id);
        conversation_manager::add_message((int) $conv->id, (int) $user->id, (int) $course->id,
            'user', 'a real question');

        conversation_manager::log_ancillary_usage(
            $this->stub_provider('openai', 'gpt-4o-mini'),
            (int) $user->id, (int) $course->id, 'mastery_signal', '[test] telemetry marker'
        );

        $history = conversation_manager::get_history_for_api((int) $conv->id);
        foreach ($history as $turn) {
            $this->assertNotSame(
                '[test] telemetry marker',
                $turn['content'],
                'A telemetry row reached the LLM context. These are role=system precisely so '
                . 'they cannot, and an internal marker is the last thing a model should learn '
                . 'to imitate.'
            );
        }
        $this->assertCount(1, $history, 'only the learner turn belongs in history');
    }

    /**
     * Every billable system type must carry an underscore or be grandfathered.
     *
     * sse.php reads a CLIENT-supplied interaction_type with PARAM_ALPHA, which
     * strips underscores. That is what stops a learner forging a privileged
     * type onto their own row, so it is a property worth pinning.
     */
    public function test_new_billable_types_cannot_be_forged_through_param_alpha(): void {
        $this->resetAfterTest();

        foreach (self::NEW_TYPES as $type) {
            $this->assertStringContainsString(
                '_',
                $type,
                "'{$type}' has no underscore, so PARAM_ALPHA would preserve it and a learner "
                . 'could submit it as their own interaction_type.'
            );
            $this->assertSame(
                str_replace('_', '', $type),
                clean_param($type, PARAM_ALPHA),
                'PARAM_ALPHA is expected to strip the underscore; if Moodle ever stops doing '
                . 'that, these names are forgeable and need an explicit allowlist.'
            );
        }
    }
}
