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
 * A criterion nobody could judge must not become a permanent not-met outcome.
 *
 * Soapbox scores one array of criteria and hands it to two consumers that used
 * to apply opposite rules. rubric_manager::compute_overall() drops a criterion
 * marked assessed=false from the numerator AND the denominator, so the learner
 * correctly reads "4 of 5 assessed". The outcome loop in score_speech did not
 * look at `assessed` at all, so the same criterion still reached
 * objective_manager::record_attempt() carrying the score 0 the prompt tells the
 * model to emit for it -- writing iscorrect=0, score=0.0 against the objective.
 * compute_mastery() then counts that attempt's weight in the denominator and
 * nothing in the numerator, so it drags mastery down permanently.
 *
 * The learner cannot see it: outcome attempts surface only in the staff
 * outcomes report, behind :viewanalytics. These are self-paced online courses
 * with no instructor to appeal to, so an invisible not-met is final.
 *
 * Narrower than it sounds, which is why it survived: objectiveid is only ever
 * set by the rubric editor, and no shipped preset carries one, so default
 * rubrics never entered the loop at all. It fires on outcome-mapped custom
 * rubrics, which is precisely the accreditation-reporting path.
 *
 * @package    local_ai_course_assistant
 * @covers     \local_ai_course_assistant\external\score_speech
 */
final class soapbox_outcome_attempt_gating_test extends \advanced_testcase {

    /**
     * Build a course with one outcome-mapped speech rubric and run a scoring
     * pass whose single criterion comes back with the given assessed/score.
     *
     * @param bool $assessed What the model reports for the criterion.
     * @param int $score The score the model reports alongside it.
     * @return array [objective id, course id]
     */
    private function score_one(bool $assessed, int $score): array {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        set_config('soapbox_enabled', 1, 'local_ai_course_assistant');
        set_config('provider', 'stub', 'local_ai_course_assistant');
        provider\stub_provider::reset();

        $oid = objective_manager::create((int) $course->id, 'Uses evidence', '', 'CLO-A');

        $DB->insert_record('local_ai_course_assistant_rubrics', (object) [
            'courseid' => $course->id,
            'type' => rubric_manager::TYPE_SPEECH,
            'title' => 'Outcome-mapped',
            'criteria' => json_encode([[
                'name' => 'Evidence',
                'description' => 'Claims are supported.',
                'max_score' => 5,
                'objectiveid' => $oid,
            ]]),
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        provider\stub_provider::program_response('chat', json_encode([
            'criteria' => [[
                'name' => 'Evidence',
                'score' => $score,
                'feedback' => $assessed ? 'Well supported.' : 'Could not be judged.',
                'assessed' => $assessed,
            ]],
            'overall' => 'ok',
            'tips' => ['a', 'b', 'c'],
        ]));

        score_speech::execute((int) $course->id, str_repeat('word ', 40));

        return [$oid, (int) $course->id];
    }

    /**
     * The defect: an unassessed criterion wrote a not-met attempt.
     *
     * @return void
     */
    public function test_an_unassessed_criterion_records_no_mastery_attempt(): void {
        global $DB;
        [$oid] = $this->score_one(false, 0);

        $this->assertSame(
            0,
            $DB->count_records(objective_manager::TABLE_ATTS, ['objectiveid' => $oid]),
            'A criterion the model reported it could not judge must not be recorded as an '
                . 'outcome attempt. It is excluded from the learner-facing score by '
                . 'compute_overall(), so recording it here marks a learner not-met on evidence '
                . 'nobody ever looked at, in a report they cannot see and cannot appeal.'
        );
    }

    /**
     * The over-correction guard: a real score must still be recorded.
     *
     * Without this, deleting the whole loop would pass the test above.
     *
     * @return void
     */
    public function test_an_assessed_criterion_still_records_its_partial_credit(): void {
        global $DB;
        [$oid] = $this->score_one(true, 4);

        $recs = $DB->get_records(objective_manager::TABLE_ATTS, ['objectiveid' => $oid]);
        $this->assertCount(1, $recs, 'an assessed criterion must still reach the outcome record');

        $rec = reset($recs);
        $this->assertSame(1, (int) $rec->iscorrect, '4 of 5 is above the 0.5 threshold');
        $this->assertEqualsWithDelta(0.8, (float) $rec->score, 0.001, '4 of 5 must normalise to 0.8');
    }
}
