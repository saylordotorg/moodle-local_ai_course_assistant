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

/**
 * A learner is never scored zero for a criterion nobody looked at.
 *
 * Soapbox learners are fully online and self-paced. There is no teacher, no
 * moderation and no appeal: whatever number this arithmetic produces is final
 * and is the entire product. That is why the exclusion path is pinned here
 * rather than left to the scoring integration test.
 *
 * The regression being guarded is concrete. A learner records with the camera
 * pointing at the ceiling, so neither visual criterion can be judged. Counting
 * those as zeros gives overall 3 and 54%. Excluding them gives overall 4 and
 * 76%. That is a whole point of overall score and 22 percentage points taken
 * off someone for a camera angle.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\rubric_manager::compute_overall
 */

namespace local_ai_course_assistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the omit-when-unassessed scoring arithmetic.
 */
final class score_total_exclusion_test extends \basic_testcase {

    /**
     * Five spoken criteria scored, two visual ones unassessed.
     *
     * The numbers in this test are the worked example from the v7.5.1 build
     * plan. If they change, the learner-facing sentence in soapbox:scored_on
     * changes with them.
     *
     * @return void
     */
    public function test_unassessed_criteria_leave_the_score_alone(): void {
        $criteria = [
            ['name' => 'Delivery & Fluency', 'score' => 4, 'max_score' => 5],
            ['name' => 'Structure & Organization', 'score' => 3, 'max_score' => 5],
            ['name' => 'Content & Relevance', 'score' => 5, 'max_score' => 5],
            ['name' => 'Language & Vocabulary', 'score' => 4, 'max_score' => 5],
            ['name' => 'Time Management', 'score' => 3, 'max_score' => 5],
            ['name' => 'Body Language & Gestures', 'score' => 0, 'max_score' => 5, 'assessed' => false],
            ['name' => 'Eye Contact & Camera Presence', 'score' => 0, 'max_score' => 5, 'assessed' => false],
        ];

        $out = rubric_manager::compute_overall($criteria);

        $this->assertSame(5, $out['assessed'], 'only the assessed criteria are counted');
        $this->assertSame(25, $out['maxtotal'], 'an excluded criterion must not inflate the denominator');
        $this->assertSame(4, $out['overall'], 'round(19/5), not round(19/7)');
        $this->assertSame(76, $out['pct'], '19 of 25, not 19 of 35');
    }

    /**
     * What v7.5.0 would have produced from the same response.
     *
     * Kept as an explicit contrast so that anyone who "simplifies"
     * compute_overall back into a flat average sees exactly what it costs.
     *
     * @return void
     */
    public function test_counting_unassessed_as_zero_is_what_we_are_avoiding(): void {
        $scores = [4, 3, 5, 4, 3, 0, 0];
        $naive = (int) round(array_sum($scores) / count($scores));

        $this->assertSame(3, $naive);
        $this->assertNotSame($naive, rubric_manager::compute_overall([
            ['name' => 'a', 'score' => 4], ['name' => 'b', 'score' => 3],
            ['name' => 'c', 'score' => 5], ['name' => 'd', 'score' => 4],
            ['name' => 'e', 'score' => 3],
            ['name' => 'f', 'score' => 0, 'assessed' => false],
            ['name' => 'g', 'score' => 0, 'assessed' => false],
        ])['overall']);
    }

    /**
     * A row with no `assessed` key counts as assessed.
     *
     * Every score written before v7.5.1 is that shape, as is anything from a
     * provider that ignores the field. Treating a missing key as "not assessed"
     * would retroactively empty the denominator of historic rows.
     *
     * @return void
     */
    public function test_a_missing_assessed_key_counts_as_assessed(): void {
        $out = rubric_manager::compute_overall([
            ['name' => 'a', 'score' => 4],
            ['name' => 'b', 'score' => 2],
        ]);

        $this->assertSame(2, $out['assessed']);
        $this->assertSame(3, $out['overall']);
        $this->assertSame(60, $out['pct']);
    }

    /**
     * Only an explicit false excludes. Truthy and null do not.
     *
     * @return void
     */
    public function test_only_an_explicit_false_excludes(): void {
        $this->assertSame(2, rubric_manager::compute_overall([
            ['name' => 'a', 'score' => 4, 'assessed' => true],
            ['name' => 'b', 'score' => 2, 'assessed' => null],
        ])['assessed'], 'null is not an explicit false and must not silently drop a criterion');
    }

    /**
     * Nothing assessed yields zero, by an explicit branch.
     *
     * The tempting max(1, $n) would turn "nothing could be judged" into a
     * real-looking low score against a fabricated denominator.
     *
     * @return void
     */
    public function test_nothing_assessed_yields_zero_not_a_division_by_zero(): void {
        $out = rubric_manager::compute_overall([
            ['name' => 'a', 'score' => 0, 'assessed' => false],
            ['name' => 'b', 'score' => 0, 'assessed' => false],
        ]);

        $this->assertSame(0, $out['assessed']);
        $this->assertSame(0, $out['overall']);
        $this->assertSame(0, $out['maxtotal']);
        $this->assertSame(0, $out['pct']);
    }

    /**
     * An empty rubric does not divide by zero either.
     *
     * @return void
     */
    public function test_an_empty_criteria_list_is_safe(): void {
        $out = rubric_manager::compute_overall([]);
        $this->assertSame(['overall' => 0, 'assessed' => 0, 'maxtotal' => 0, 'pct' => 0], $out);
    }

    /**
     * A missing max_score defaults to 5, matching score_speech.
     *
     * @return void
     */
    public function test_missing_max_score_defaults_to_five(): void {
        $this->assertSame(10, rubric_manager::compute_overall([
            ['name' => 'a', 'score' => 5],
            ['name' => 'b', 'score' => 5],
        ])['maxtotal']);
    }

    /**
     * The default video rubric is the five spoken criteria then the two visual
     * ones, and the spoken five are untouched.
     *
     * Three existing tests constrain DEFAULT_SPEECH_CRITERIA; this pins that
     * the video rubric builds on them rather than forking them.
     *
     * @return void
     */
    public function test_default_video_rubric_extends_rather_than_forks_the_speech_one(): void {
        $video = rubric_manager::default_video_criteria();

        $this->assertCount(7, $video);
        $this->assertSame(
            rubric_manager::DEFAULT_SPEECH_CRITERIA,
            array_slice($video, 0, 5),
            'the spoken criteria must be byte-identical and in order'
        );
        $this->assertSame('Body Language & Gestures', $video[5]['name']);
        $this->assertSame('Eye Contact & Camera Presence', $video[6]['name']);
        $this->assertTrue($video[5]['visual']);
        $this->assertTrue($video[6]['visual']);
    }

    /**
     * The body-language criterion is written in terms of support and distraction.
     *
     * This is the wording the feature was asked for, and it is what the model is
     * actually shown, so it is worth pinning against a well-meaning reword.
     *
     * @return void
     */
    public function test_the_body_language_criterion_names_support_and_distraction(): void {
        $desc = rubric_manager::VISUAL_CRITERIA[0]['description'];
        $this->assertStringContainsString('SUPPORT', $desc);
        $this->assertStringContainsString('DISTRACT', $desc);
    }
}
