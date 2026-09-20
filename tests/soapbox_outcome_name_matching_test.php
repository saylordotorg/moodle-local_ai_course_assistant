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

use local_ai_course_assistant\external\score_speech;

defined('MOODLE_INTERNAL') || die();

/**
 * One criterion name, matched the same way everywhere it is matched.
 *
 * score_speech decides three things from a criterion name: whether the model is
 * allowed to score it, what maximum the score is normalised over, and which
 * course outcome the attempt is written against. The first two normalised the
 * name; the third did not. So a model echoing a case or spacing variant of a
 * rubric row passed the allowlist, was scored, was shown to the learner, and
 * then silently missed its objective mapping.
 *
 * Closing that raises a second question, which these tests pin the answer to.
 * Normalising the objective map promotes an existing last-wins collision from
 * choosing between two max scores to choosing which outcome a permanent mastery
 * attempt lands on. An outcome attempt is staff-visible only, so a learner can
 * neither see nor appeal one written against the wrong outcome. A missing
 * attempt is a reporting gap an admin closes by renaming two rubric rows. So
 * where the colliding definitions disagree, nothing is recorded.
 *
 * @package    local_ai_course_assistant
 * @covers     \local_ai_course_assistant\external\score_speech::ambiguous_objective_keys
 * @covers     \local_ai_course_assistant\external\score_speech::normalise_name
 */
final class soapbox_outcome_name_matching_test extends \basic_testcase {

    /**
     * Distinct names are never ambiguous, however many there are.
     *
     * @return void
     */
    public function test_distinct_names_are_not_ambiguous(): void {
        $this->assertSame([], score_speech::ambiguous_objective_keys([
            ['name' => 'Delivery', 'objectiveid' => 1, 'max_score' => 5],
            ['name' => 'Evidence', 'objectiveid' => 2, 'max_score' => 5],
            ['name' => 'Structure', 'objectiveid' => 3, 'max_score' => 4],
        ]));
    }

    /**
     * Names that collide but agree are interchangeable, so they still record.
     *
     * This is the over-correction guard: refusing every collision would throw
     * away attempts that were never in doubt.
     *
     * @return void
     */
    public function test_a_collision_that_agrees_is_not_ambiguous(): void {
        $this->assertSame([], score_speech::ambiguous_objective_keys([
            ['name' => 'Eye Contact', 'objectiveid' => 7, 'max_score' => 5],
            ['name' => 'eye  contact', 'objectiveid' => 7, 'max_score' => 5],
        ]));
    }

    /**
     * Disagreeing on the objective makes the key ambiguous.
     *
     * @return void
     */
    public function test_disagreement_on_the_objective_is_ambiguous(): void {
        $this->assertSame(
            ['eye contact' => true],
            score_speech::ambiguous_objective_keys([
                ['name' => 'Eye Contact', 'objectiveid' => 7, 'max_score' => 5],
                ['name' => 'EYE CONTACT', 'objectiveid' => 9, 'max_score' => 5],
            ])
        );
    }

    /**
     * So does disagreeing on the maximum, which the issue did not ask for.
     *
     * The record written is the pair (objective, normalised score), and the
     * normalisation divides by max_score. Two definitions that agree on the
     * outcome but disagree on the maximum would record against the right
     * outcome with a magnitude chosen by array order, which is still a wrong
     * permanent record.
     *
     * @return void
     */
    public function test_disagreement_on_the_maximum_is_also_ambiguous(): void {
        $this->assertSame(
            ['delivery' => true],
            score_speech::ambiguous_objective_keys([
                ['name' => 'Delivery', 'objectiveid' => 3, 'max_score' => 5],
                ['name' => 'delivery', 'objectiveid' => 3, 'max_score' => 10],
            ])
        );
    }

    /**
     * A third definition agreeing with the first does not clear the flag.
     *
     * With A(oid 5), B(oid 7), C(oid 5) the key is ambiguous and must stay so.
     * Comparing only against the previously seen signature would unflag it at C
     * and record an attempt chosen by array order after all.
     *
     * @return void
     */
    public function test_ambiguity_is_sticky_once_seen(): void {
        $this->assertSame(
            ['delivery' => true],
            score_speech::ambiguous_objective_keys([
                ['name' => 'Delivery', 'objectiveid' => 5, 'max_score' => 5],
                ['name' => 'delivery', 'objectiveid' => 7, 'max_score' => 5],
                ['name' => 'DELIVERY', 'objectiveid' => 5, 'max_score' => 5],
            ])
        );
    }

    /**
     * Definitions with no name are skipped rather than collapsing together.
     *
     * Two unnamed rows both normalise to the empty string, which would look
     * like a collision and suppress an unrelated key.
     *
     * @return void
     */
    public function test_unnamed_definitions_do_not_collide(): void {
        $this->assertSame([], score_speech::ambiguous_objective_keys([
            ['name' => '', 'objectiveid' => 1, 'max_score' => 5],
            ['name' => '   ', 'objectiveid' => 2, 'max_score' => 5],
            ['objectiveid' => 3, 'max_score' => 5],
        ]));
    }

    /**
     * The lookup key and the allowlist key are produced by the same function.
     *
     * A regression here is what the whole issue was: two call sites deciding
     * what one name means, by different rules.
     *
     * @return void
     */
    public function test_normalisation_folds_case_and_collapses_whitespace(): void {
        $this->assertSame('eye contact', score_speech::normalise_name('  Eye   CONTACT '));
        $this->assertSame('body language & gestures', score_speech::normalise_name("Body Language &\tGestures"));
    }
}
