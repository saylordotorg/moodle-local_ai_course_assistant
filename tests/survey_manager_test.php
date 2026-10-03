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
 * Survey manager: rating-scale bounds (#288) and the create-time active flag (#289).
 *
 * Both defects were found during the #273 rendering migration and reported
 * rather than fixed there.
 *
 * #288 is a data problem as much as an input problem. The save path took any
 * integers it was posted, so a scale could be stored backwards, which renders
 * a question with no buttons at all because the learner-side loop is
 * `for (r = min; r <= max; r++)`, or absurdly wide, which builds one button
 * per step for every learner who opens it. Fixing only the save path would
 * leave rows already in the database rendering exactly as badly as before, so
 * the read path is what these tests pin.
 *
 * #289 has no visible effect today, which is the reason it needs a test: the
 * form posts a hardcoded active=1, so the discarded flag is invisible until
 * someone adds a real toggle, at which point it works on edit and silently
 * fails on create.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\survey_manager
 */
final class survey_manager_test extends \advanced_testcase {

    /** @var string Table name for surveys. */
    private const TABLE = 'local_ai_course_assistant_surveys';

    /**
     * Write a survey row straight to the database, bypassing every validator.
     *
     * The point of the read-path tests is rows that the current save path
     * would now refuse, so they cannot be created through create_survey().
     *
     * @param array $questions Question definitions to store.
     * @param int $courseid Scope, 0 for the global default.
     * @return int The new survey ID.
     */
    private function store_raw_survey(array $questions, int $courseid = 0): int {
        global $DB;

        return $DB->insert_record(self::TABLE, (object) [
            'courseid' => $courseid,
            'title' => 'Raw fixture',
            'questions' => json_encode($questions),
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * A rating question, optionally with bounds.
     *
     * @param array $extra Fields merged over the base question.
     * @return array
     */
    private function rating_question(array $extra): array {
        return array_merge(['type' => 'rating', 'text' => 'How did it go?'], $extra);
    }

    /**
     * Bounds stored the wrong way round come back the way the admin meant them.
     *
     * Stored as min=5 max=1 the render loop never runs even once, so the
     * learner is shown a required question with nothing to click.
     */
    public function test_reversed_rating_bounds_are_swapped_on_read(): void {
        $this->resetAfterTest();

        $this->store_raw_survey([$this->rating_question(['min' => 5, 'max' => 1])]);

        $survey = survey_manager::get_active_survey(0);

        $this->assertSame(1, $survey->questions[0]['min']);
        $this->assertSame(5, $survey->questions[0]['max']);
    }

    /**
     * A scale wide enough to be a denial of service is clamped to the maximum.
     *
     * ui.js builds a full button with a click handler per step, so a stored
     * max of 500000 is half a million DOM nodes per learner.
     */
    public function test_an_oversized_rating_max_is_clamped_on_read(): void {
        $this->resetAfterTest();

        $this->store_raw_survey([$this->rating_question(['min' => 1, 'max' => 500000])]);

        $survey = survey_manager::get_active_survey(0);

        $this->assertSame(1, $survey->questions[0]['min']);
        $this->assertSame(survey_manager::RATING_SCALE_MAX, $survey->questions[0]['max']);
    }

    /**
     * A scale starting below 1 is lifted to the floor rather than left negative.
     */
    public function test_a_rating_min_below_the_floor_is_clamped_on_read(): void {
        $this->resetAfterTest();

        $this->store_raw_survey([$this->rating_question(['min' => -20, 'max' => 4])]);

        $survey = survey_manager::get_active_survey(0);

        $this->assertSame(survey_manager::RATING_SCALE_MIN, $survey->questions[0]['min']);
        $this->assertSame(4, $survey->questions[0]['max']);
    }

    /**
     * Whatever the stored bounds, what comes back can always be answered.
     *
     * This is the property the learner actually depends on: at least one
     * button, and never more than the ceiling.
     */
    public function test_every_rating_scale_read_back_is_answerable(): void {
        $this->resetAfterTest();

        $cases = [
            ['min' => 5, 'max' => 1],
            ['min' => 0, 'max' => 0],
            ['min' => 500000, 'max' => 1],
            ['min' => -3, 'max' => -1],
            ['min' => 11, 'max' => 12],
            [],
        ];

        foreach ($cases as $i => $bounds) {
            $this->store_raw_survey([$this->rating_question($bounds)], $i + 1);

            $survey = survey_manager::get_active_survey($i + 1);
            $min = $survey->questions[0]['min'];
            $max = $survey->questions[0]['max'];
            $label = json_encode($bounds);

            $this->assertGreaterThanOrEqual(survey_manager::RATING_SCALE_MIN, $min,
                "min below the floor for {$label}");
            $this->assertLessThanOrEqual($max, $min, "scale renders no buttons for {$label}");
            $this->assertLessThanOrEqual(survey_manager::RATING_SCALE_MAX, $max,
                "max above the ceiling for {$label}");
        }
    }

    /**
     * Questions that are not ratings are returned untouched.
     *
     * The normaliser walks every question, so a multiple choice question
     * growing stray min/max keys would be a regression of its own.
     */
    public function test_non_rating_questions_are_left_alone(): void {
        $this->resetAfterTest();

        $this->store_raw_survey([[
            'type' => 'multiple_choice',
            'text' => 'How often?',
            'options' => ['Daily', 'Never'],
        ]]);

        $survey = survey_manager::get_active_survey(0);

        $this->assertSame(['Daily', 'Never'], $survey->questions[0]['options']);
        $this->assertArrayNotHasKey('min', $survey->questions[0]);
        $this->assertArrayNotHasKey('max', $survey->questions[0]);
    }

    /**
     * The results aggregate never builds an unbounded distribution either.
     *
     * get_survey_results() seeds one bucket per step with the same shape the
     * browser uses, `for ($i = $min; $i <= $max; $i++)`, reading the bounds
     * straight off the stored question. A stored max of 500000 is therefore
     * half a million PHP array entries built on the server every time an
     * instructor opens the results, which is a worse version of the DOM
     * problem and was not called out in the issue. It is fixed by the same
     * normalisation, so it is worth pinning that it stays fixed.
     */
    public function test_results_distribution_is_bounded_by_the_scale_ceiling(): void {
        $this->resetAfterTest();

        $this->store_raw_survey([$this->rating_question(['min' => 1, 'max' => 500000])]);

        $results = survey_manager::get_survey_results(0);

        $this->assertCount(survey_manager::RATING_SCALE_MAX,
            $results['questions'][0]['distribution']);
    }

    /**
     * The histogram still accounts for every response after a scale repair.
     *
     * This is the invariant the first version of this fix broke. Repairing the
     * scale on read changed the buckets the aggregate seeds, and answers
     * recorded under the OLD scale fell outside them and were dropped, while
     * response_count and average still counted them. An instructor saw bars
     * that did not add up to the badge beside them and nothing saying why.
     *
     * Asserting the bucket count alone could not see that, because the bug is
     * about where the answers went, not how many buckets there are.
     */
    public function test_a_repaired_scale_still_counts_every_recorded_answer(): void {
        global $DB;
        $this->resetAfterTest();

        // Stored 1..100, which the repair slides to 1..10.
        $surveyid = $this->store_raw_survey([$this->rating_question(['min' => 1, 'max' => 100])]);
        foreach ([73, 88, 5, 100] as $i => $answer) {
            $DB->insert_record('local_ai_course_assistant_survey_resp', (object) [
                'surveyid' => $surveyid,
                'userid' => $i + 100,
                'courseid' => 0,
                'question_index' => 0,
                'answer' => (string) $answer,
                'timecreated' => time(),
            ]);
        }

        $q = survey_manager::get_survey_results(0)['questions'][0];

        $this->assertSame(4, $q['response_count']);
        $this->assertSame(4, array_sum($q['distribution']),
            'the bars must add up to the response count beside them');
        foreach ([73, 88, 5, 100] as $answer) {
            $this->assertArrayHasKey($answer, $q['distribution'],
                "answer {$answer} vanished from the histogram");
        }
    }

    /**
     * Buckets come back in order, so the chart reads left to right.
     *
     * The out-of-scale values are appended as they are encountered, so without
     * an explicit sort the bars render in insertion order.
     */
    public function test_the_histogram_is_ordered(): void {
        global $DB;
        $this->resetAfterTest();

        $surveyid = $this->store_raw_survey([$this->rating_question(['min' => 1, 'max' => 100])]);
        foreach ([88, 5, 73] as $i => $answer) {
            $DB->insert_record('local_ai_course_assistant_survey_resp', (object) [
                'surveyid' => $surveyid,
                'userid' => $i + 200,
                'courseid' => 0,
                'question_index' => 0,
                'answer' => (string) $answer,
                'timecreated' => time(),
            ]);
        }

        $keys = array_keys(survey_manager::get_survey_results(0)['questions'][0]['distribution']);
        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys);
    }

    /**
     * A non-numeric answer neither invents a bar nor drags the average down.
     *
     * floatval('') is 0.0, so a blank row used to add a spurious "0" bucket
     * and pull the mean toward zero.
     */
    public function test_a_blank_answer_is_not_counted_as_zero(): void {
        global $DB;
        $this->resetAfterTest();

        $surveyid = $this->store_raw_survey([$this->rating_question(['min' => 1, 'max' => 5])]);
        foreach (['4', '', 'n/a'] as $i => $answer) {
            $DB->insert_record('local_ai_course_assistant_survey_resp', (object) [
                'surveyid' => $surveyid,
                'userid' => $i + 300,
                'courseid' => 0,
                'question_index' => 0,
                'answer' => $answer,
                'timecreated' => time(),
            ]);
        }

        $q = survey_manager::get_survey_results(0)['questions'][0];

        $this->assertSame(4.0, (float) $q['average']);
        $this->assertSame(0, $q['distribution'][1] ?? 0);
        $this->assertArrayNotHasKey(0, $q['distribution']);
    }

    /**
     * A range stored wholly outside the window keeps its number of steps.
     *
     * Clamping each end independently converged them: 11..12 became 10..10 and
     * 20..30 became 10..10, i.e. a rating question with exactly one button.
     * Every respondent is then forced to the same value, the average is that
     * value by construction, and the question yields nothing. That is worse
     * than the zero-button render this set out to fix, and the old property
     * assertion (min <= max) passed it happily.
     */
    public function test_an_out_of_window_scale_keeps_its_span(): void {
        $this->resetAfterTest();

        $cases = [
            [['min' => 11, 'max' => 12], 9, 10],
            [['min' => 20, 'max' => 30], 1, 10],
            [['min' => -3, 'max' => -1], 1, 3],
        ];

        foreach ($cases as $i => [$stored, $wantmin, $wantmax]) {
            $this->store_raw_survey([$this->rating_question($stored)], $i + 50);

            $survey = survey_manager::get_active_survey($i + 50);
            $label = json_encode($stored);

            $this->assertSame($wantmin, $survey->questions[0]['min'], "min for {$label}");
            $this->assertSame($wantmax, $survey->questions[0]['max'], "max for {$label}");
        }
    }

    /**
     * A scale that is already valid is returned untouched.
     *
     * Including a deliberate single-value scale, which the save path allows.
     */
    public function test_a_valid_scale_is_not_rewritten(): void {
        $this->resetAfterTest();

        foreach ([[1, 5], [1, 10], [3, 3], [2, 7]] as $i => [$min, $max]) {
            $this->store_raw_survey(
                [$this->rating_question(['min' => $min, 'max' => $max])], $i + 70);

            $survey = survey_manager::get_active_survey($i + 70);

            $this->assertSame($min, $survey->questions[0]['min']);
            $this->assertSame($max, $survey->questions[0]['max']);
        }
    }

    /**
     * The raw loader hands back exactly what is stored.
     *
     * The admin editor seeds its form from this and posts it straight back, so
     * if the raw read repaired anything, opening the page to fix an unrelated
     * typo would silently and permanently overwrite the stored scale. The
     * repair belongs on the learner path; the admin has to choose.
     */
    public function test_the_raw_loader_does_not_repair_the_scale(): void {
        $this->resetAfterTest();

        $this->store_raw_survey([$this->rating_question(['min' => 5, 'max' => 1])]);

        $raw = survey_manager::get_active_survey_raw(0);

        $this->assertSame(5, $raw->questions[0]['min']);
        $this->assertSame(1, $raw->questions[0]['max']);
    }

    /**
     * Issue #289: an inactive create stores active=0 instead of forcing 1.
     */
    public function test_create_survey_honours_an_inactive_flag(): void {
        global $DB;
        $this->resetAfterTest();

        $id = survey_manager::create_survey(0, 'Draft', survey_manager::DEFAULT_QUESTIONS, false);

        $this->assertSame(0, (int) $DB->get_field(self::TABLE, 'active', ['id' => $id]));
    }

    /**
     * An inactive create must not stand down the survey that is already live.
     *
     * The old code deactivated the scope unconditionally and then wrote
     * active=1, so this path would have left the scope with a live draft and
     * a retired survey.
     */
    public function test_an_inactive_create_leaves_the_live_survey_alone(): void {
        global $DB;
        $this->resetAfterTest();

        $liveid = survey_manager::create_survey(0, 'Live', survey_manager::DEFAULT_QUESTIONS);
        survey_manager::create_survey(0, 'Draft', survey_manager::DEFAULT_QUESTIONS, false);

        $this->assertSame(1, (int) $DB->get_field(self::TABLE, 'active', ['id' => $liveid]));
        $this->assertSame('Live', survey_manager::get_active_survey(0)->title);
    }

    /**
     * The default is still active, so existing callers are unchanged.
     *
     * ensure_default_survey() and the admin save path both rely on this.
     */
    public function test_create_survey_still_defaults_to_active(): void {
        global $DB;
        $this->resetAfterTest();

        $id = survey_manager::create_survey(0, 'Default', survey_manager::DEFAULT_QUESTIONS);

        $this->assertSame(1, (int) $DB->get_field(self::TABLE, 'active', ['id' => $id]));
    }

    /**
     * An activating create still retires the previous survey for the scope.
     */
    public function test_an_active_create_retires_the_previous_survey(): void {
        global $DB;
        $this->resetAfterTest();

        $oldid = survey_manager::create_survey(0, 'Old', survey_manager::DEFAULT_QUESTIONS);
        survey_manager::create_survey(0, 'New', survey_manager::DEFAULT_QUESTIONS, true);

        $this->assertSame(0, (int) $DB->get_field(self::TABLE, 'active', ['id' => $oldid]));
        $this->assertSame('New', survey_manager::get_active_survey(0)->title);
    }
}
