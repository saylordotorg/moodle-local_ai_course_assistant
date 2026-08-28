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
 * Retrieved course content must actually reach the assembled prompt.
 *
 * Regression cover for the v7.2.0 staging finding: retrieval worked, returned
 * real passages with scores, and none of that text reached the model. The
 * shipped default template carries {{coursecontent}}, so the passages were
 * substituted into base_template -- which is budgeted from the safety_identity
 * bucket, 10% of the prompt budget -- while the course_content bucket (40%)
 * went unspent because no section was ever named for it. The passages were
 * truncated away, the output-marker section still instructed the model to cite
 * them inline with [[c:N]], and it cited passages it had never been shown.
 *
 * The tell was that the assembled prompt did not change length when retrieval
 * returned more chunks, which is asserted directly below.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\context_builder
 */
final class rag_context_reaches_prompt_test extends \advanced_testcase {

    /** @var string Distinctive text that must survive assembly. */
    private const NEEDLE = 'Bunsen burners must be extinguished before the mercury cabinet is opened';

    /**
     * Build $count chunks, each carrying a findable needle.
     *
     * Padded past the point where a chunk is cheap to keep, so the test
     * exercises the budget rather than a trivially small payload.
     *
     * @param int $count
     * @return array
     */
    private function chunks(int $count): array {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'content' => self::NEEDLE . " (passage {$i}). "
                    . str_repeat("Supporting detail for passage {$i}. ", 8),
                'score'   => 0.9 - ($i / 100),
                'cmid'    => 0,
            ];
        }
        return $out;
    }

    public function test_retrieved_passages_reach_the_prompt_at_the_default_budget(): void {
        $this->resetAfterTest();
        // Pin the budget: a site that stored the pre-v7.2.1 default of 12,000
        // would otherwise cap the content section and mask the assertion.
        set_config('prompt_budget_chars', 24000, 'local_ai_course_assistant');
        $course  = $this->getDataGenerator()->create_course(['fullname' => 'Chemistry 101']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Deliberately NOT setting a custom systemprompt: the shipped default is
        // the configuration that broke, because it is the one carrying the
        // {{coursecontent}} placeholder.
        $prompt = context_builder::build_system_prompt($course->id, $student->id, '', $this->chunks(3));

        $this->assertStringContainsString(
            self::NEEDLE,
            $prompt,
            'Retrieved passage text is missing from the assembled prompt.'
        );
        $this->assertStringContainsString('Relevant course content', $prompt);
    }

    public function test_prompt_grows_when_more_passages_are_retrieved(): void {
        $this->resetAfterTest();
        // Pin the budget: a site that stored the pre-v7.2.1 default of 12,000
        // would otherwise cap the content section and mask the assertion.
        set_config('prompt_budget_chars', 24000, 'local_ai_course_assistant');
        $course  = $this->getDataGenerator()->create_course(['fullname' => 'Chemistry 101']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $none = context_builder::build_system_prompt($course->id, $student->id, '', []);
        $one  = context_builder::build_system_prompt($course->id, $student->id, '', $this->chunks(1));
        $four = context_builder::build_system_prompt($course->id, $student->id, '', $this->chunks(4));

        $this->assertGreaterThan(
            strlen($none),
            strlen($one),
            'Prompt did not grow when retrieval returned a passage.'
        );
        $this->assertGreaterThan(
            strlen($one),
            strlen($four),
            'Prompt length is insensitive to how many passages were retrieved, '
                . 'which is the signature of the content being truncated away.'
        );
    }

    public function test_course_content_is_budgeted_as_its_own_section(): void {
        $this->resetAfterTest();
        // Pin the budget: a site that stored the pre-v7.2.1 default of 12,000
        // would otherwise cap the content section and mask the assertion.
        set_config('prompt_budget_chars', 24000, 'local_ai_course_assistant');
        $course  = $this->getDataGenerator()->create_course(['fullname' => 'Chemistry 101']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        context_builder::build_system_prompt($course->id, $student->id, '', $this->chunks(3));

        $breakdown = context_builder::$last_breakdown;
        $this->assertArrayHasKey(
            'course_content',
            $breakdown,
            'course_content must appear in the assembly breakdown: the truncation '
                . 'hint and the prompt-debug view both key off it, and both were '
                . 'silently inert while the content lived inside base_template.'
        );
        $this->assertNotEmpty($breakdown['course_content']['used'] ?? false);
    }

    public function test_identity_template_is_not_clipped_by_a_bucket(): void {
        $this->resetAfterTest();
        // Pin the budget: a site that stored the pre-v7.2.1 default of 12,000
        // would otherwise cap the content section and mask the assertion.
        set_config('prompt_budget_chars', 24000, 'local_ai_course_assistant');
        $course  = $this->getDataGenerator()->create_course(['fullname' => 'Chemistry 101']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        context_builder::build_system_prompt($course->id, $student->id, '', $this->chunks(3));

        $breakdown = context_builder::$last_breakdown;
        $this->assertEmpty(
            $breakdown['base_template']['truncated'] ?? false,
            'The operator system-prompt template was truncated. It carries the '
                . 'persona and precedence rules and must not be clipped to a '
                . 'percentage of the budget.'
        );
    }

    public function test_assembled_prompt_stays_within_the_budget(): void {
        $this->resetAfterTest();
        set_config('prompt_budget_chars', 24000, 'local_ai_course_assistant');
        $course  = $this->getDataGenerator()->create_course(['fullname' => 'Chemistry 101']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // The four bucket weights sum to 100% of the budget, so anything the
        // assembler cannot reclaim -- the CAT_SAFETY block especially, which is
        // exempt from both the cap and the drop loop -- has to be reserved before
        // the buckets are sized, or the prompt is over-subscribed by exactly its
        // length. Assert the outcome rather than the arithmetic.
        $prompt = context_builder::build_system_prompt($course->id, $student->id, '', $this->chunks(12));

        $this->assertLessThanOrEqual(
            24000,
            strlen($prompt),
            'The assembled prompt exceeded prompt_budget_chars on a stock install.'
        );
    }

    public function test_a_pathological_template_does_not_crowd_out_everything_else(): void {
        $this->resetAfterTest();
        set_config('prompt_budget_chars', 24000, 'local_ai_course_assistant');
        // base_template is CAT_IDENTITY at priority 100, so it is the LAST thing
        // the drop loop touches. Unbucketed, a pasted template would destroy every
        // other section before losing a character itself.
        set_config('systemprompt', str_repeat('Persona line for the tutor. ', 4000), 'local_ai_course_assistant');
        $course  = $this->getDataGenerator()->create_course(['fullname' => 'Chemistry 101']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        context_builder::build_system_prompt($course->id, $student->id, '', $this->chunks(3));
        $breakdown = context_builder::$last_breakdown;

        $this->assertNotEmpty(
            $breakdown['base_template']['truncated'] ?? false,
            'An oversized operator template must still be capped.'
        );
    }

    public function test_a_normal_template_is_never_truncated(): void {
        $this->resetAfterTest();
        set_config('prompt_budget_chars', 24000, 'local_ai_course_assistant');
        $course  = $this->getDataGenerator()->create_course(['fullname' => 'Chemistry 101']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        context_builder::build_system_prompt($course->id, $student->id, '', $this->chunks(3));
        $breakdown = context_builder::$last_breakdown;

        $this->assertEmpty(
            $breakdown['base_template']['truncated'] ?? false,
            'The shipped template must clear the identity cap; it was arriving cut '
                . 'at 1,235 of its 1,640 characters.'
        );
    }

    public function test_a_bucket_with_no_section_does_not_hold_its_share(): void {
        $this->resetAfterTest();

        // In RAG mode current_page_content is never built, but page_focus hands
        // the current_page bucket 55% of the pool whenever a pageid is in scope --
        // the normal case for a learner reading a page. That share was spent by
        // nobody while course_content was squeezed, costing real passages with
        // thousands of characters of budget sitting unused.
        $withpage = context_builder::section_budgets(24000, 4082, '', 6000);
        $nopagesection = context_builder::section_budgets(
            24000,
            4082,
            '',
            6000,
            ['safety_identity', 'course_structure', 'course_content']
        );

        $this->assertSame(0, $nopagesection['current_page']);
        $this->assertGreaterThan(
            $withpage['course_content'],
            $nopagesection['course_content'],
            'The share of a bucket with no section must be redistributed, not left unspent.'
        );
    }

    public function test_the_model_is_never_told_to_cite_passages_it_was_not_given(): void {
        $this->resetAfterTest();
        $course  = $this->getDataGenerator()->create_course(['fullname' => 'Chemistry 101']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // output_markers (CAT_MARKERS) and course_content (CAT_CONTEXT) are
        // dropped independently, so there is a budget band where the citation
        // instruction survives and the passages do not. That is an instruction to
        // invent citations. Reachable without an odd setting: a self-hosted
        // backend_context_tokens of 8192 clamps straight into it.
        foreach ([5000, 6000, 6400, 7200, 8000, 12000, 24000] as $budget) {
            set_config('prompt_budget_chars', $budget, 'local_ai_course_assistant');
            $prompt = context_builder::build_system_prompt(
                $course->id,
                $student->id,
                '',
                $this->chunks(6)
            );
            $breakdown = context_builder::$last_breakdown;
            $hascontent = !empty($breakdown['course_content']['used']);
            $hascite = str_contains($prompt, 'cite a retrieved passage');

            if ($hascite) {
                $this->assertTrue(
                    $hascontent,
                    "At budget {$budget} the prompt asks the model to cite retrieved "
                        . 'passages, but every passage was dropped from assembly.'
                );
            }
        }
    }

    public function test_an_empty_passage_is_not_labelled_or_offered_for_citation(): void {
        $this->resetAfterTest();
        $course  = $this->getDataGenerator()->create_course(['fullname' => 'Chemistry 101']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $prompt = context_builder::build_system_prompt($course->id, $student->id, '', [
            ['content' => '', 'score' => 0.9, 'cmid' => 0],
            ['content' => '   ', 'score' => 0.8, 'cmid' => 0],
        ]);

        $this->assertStringNotContainsString('[c:0]', $prompt);
        $this->assertStringNotContainsString('cite a retrieved passage', $prompt);
    }

    public function test_section_budgets_allocate_from_what_the_fixed_sections_leave(): void {
        $this->resetAfterTest();

        $whole = context_builder::section_budgets(12000, 0, '', 0);
        $after = context_builder::section_budgets(12000, 0, '', 8000);

        $this->assertLessThan(
            $whole['course_content'],
            $after['course_content'],
            'Reserving space for the fixed sections must shrink the elastic buckets; '
                . 'charging all four buckets against the whole budget over-subscribed it.'
        );
        $this->assertLessThanOrEqual(4000, array_sum($after));
    }
}
