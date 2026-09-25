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

use core_external\external_api;

/**
 * The study plan, driven end to end by a learner who holds only a learner's capabilities.
 *
 * WHY THIS EXISTS. Four defects reached a release in one day. Three were the same
 * shape: a learner-facing panel read its data through an API needing a capability
 * students do not hold, so the feature was invisible to every learner and worked
 * perfectly for every member of staff who checked it. The suite stayed green
 * throughout, because every test either asserted a refusal ("a learner cannot read
 * somebody else's row") or asserted a shape ("the return structure matches"), and
 * not one of them drove the feature with real data AS the person it was built for.
 *
 * The study plan was, until this file, the largest completely untested surface of
 * that exact shape in the plugin: two registered ajax web services over one
 * personal-data table, reachable by any enrolled learner, with zero test coverage.
 * Nothing had ever executed get_study_plan::execute(), update_study_plan::execute(),
 * study_planner::get_plan() or study_planner::save_plan().
 *
 * Three deliberate choices make this file hard to fool:
 *
 *   1. Every test asserts FIRST that the learner holds none of the staff
 *      capabilities. Without those assertions the whole file would pass while
 *      running as somebody who can see everything, which is precisely how the three
 *      defects hid.
 *   2. Every read and write goes through external_api::call_external_function()
 *      under the REGISTERED service name, not through execute(). Only the
 *      dispatcher runs the capability check and clean_returnvalue(), so this file
 *      catches a declared return key that is only ever populated for a learner who
 *      actually has a plan, which is a defect nobody testing with an empty planner
 *      would ever meet.
 *
 *      It should also fail outright if either service is dropped from
 *      db/services.php, but that is REASONED AND NOT MEASURED. Moodle caches the
 *      external-function registry in the external_functions table of the test
 *      database, so editing db/services.php changes nothing until that database is
 *      reinitialised, and it could not be proven here without disrupting work
 *      running against the same Moodle. Treat it as an expectation rather than a
 *      guarantee until somebody demonstrates it on a fresh test database.
 *   3. Plans are created by driving the real write endpoint as their owner, never
 *      by $DB->insert_record(), with the seed's own success asserted. A fixture
 *      that silently seeds nothing is the other way a file like this goes green
 *      while proving nothing.
 *
 * Note for anyone extending it: the dispatcher demands a sesskey under PHPUnit.
 * Without $_POST['sesskey'] every call returns error=true with errorcode
 * 'missingparam', which a lazy assertion ("the call was refused") would happily
 * accept. That is why nothing here asserts merely that error is true; every
 * expected refusal names its errorcode.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\get_study_plan
 * @covers     \local_ai_course_assistant\external\update_study_plan
 * @covers     \local_ai_course_assistant\study_planner
 */
final class study_plan_learner_journey_test extends \advanced_testcase {
    /** @var string The plan_data JSON a learner saves, and must read back unchanged. */
    private const PLAN_JSON = '{"schedule":{"mon":"Unit 1"},"goal":"pass the final"}';

    /**
     * Become this learner for the rest of the test, dispatcher included.
     *
     * @param \stdClass $user The learner to act as.
     * @return void
     */
    private function act_as(\stdClass $user): void {
        $this->setUser($user);
        $_POST['sesskey'] = sesskey();
    }

    /**
     * The guard that makes every other assertion in this file mean something.
     *
     * A learner holds :use and nothing else. If any of these were true, "the
     * endpoint works for a student" would be a statement about a member of staff.
     *
     * @param int $courseid The course whose context is checked.
     * @return void
     */
    private function assert_acting_user_is_only_a_learner(int $courseid): void {
        $coursecontext = \context_course::instance($courseid);

        $this->assertTrue(
            has_capability('local/ai_course_assistant:use', $coursecontext),
            'The learner must hold :use, or a failure to read is just a fixture that forgot to enrol.'
        );
        $this->assertFalse(
            has_capability('local/ai_course_assistant:viewanalytics', $coursecontext),
            'A learner must not hold the analytics capability, or this test proves nothing.'
        );
        $this->assertFalse(
            has_capability('local/ai_course_assistant:manage', $coursecontext),
            'A learner must not hold the course management capability, or this test proves nothing.'
        );
        $this->assertFalse(
            has_capability('moodle/site:config', \context_system::instance()),
            'An admin passes every require_capability() and skips enrolment checks, so a file run '
                . 'as one would go green on capabilities and course scoping alike.'
        );
    }

    /**
     * Save a plan the way the browser would: the registered write service.
     *
     * @param int $courseid The course to save against.
     * @param float $hours Hours per week.
     * @param string $days Comma-separated preferred days.
     * @param string $time Preferred time of day.
     * @param string $plandata Raw JSON plan data.
     * @return array The dispatcher's response.
     */
    private function save(
        int $courseid,
        float $hours,
        string $days = 'mon,wed',
        string $time = 'evening',
        string $plandata = self::PLAN_JSON
    ): array {
        return external_api::call_external_function(
            'local_ai_course_assistant_update_study_plan',
            [
                'courseid' => $courseid,
                'hours_per_week' => $hours,
                'preferred_days' => $days,
                'preferred_time' => $time,
                'plan_data' => $plandata,
            ],
            true
        );
    }

    /**
     * Read a plan the way the browser would: the registered read service.
     *
     * @param int $courseid The course to read.
     * @return array The dispatcher's response.
     */
    private function read(int $courseid): array {
        return external_api::call_external_function(
            'local_ai_course_assistant_get_study_plan',
            ['courseid' => $courseid],
            true
        );
    }

    /**
     * Save a plan and insist the seed itself worked.
     *
     * A fixture that fails quietly produces a file full of green assertions about
     * an empty table.
     *
     * @param int $courseid The course to seed against.
     * @param float $hours Hours per week.
     * @return void
     */
    private function seed_plan(int $courseid, float $hours = 7.5): void {
        $result = $this->save($courseid, $hours);
        $this->assertFalse(
            $result['error'],
            'The fixture could not write a plan through the real endpoint: '
                . ($result['exception']->message ?? '')
        );
    }

    /**
     * The five fields the read endpoint hands a learner who has no plan.
     *
     * @param array $data The dispatcher's data payload.
     * @param string $why What a learner would wrongly see if this failed.
     * @return void
     */
    private function assert_is_the_empty_default(array $data, string $why): void {
        $this->assertFalse($data['hasplan'], $why);
        $this->assertSame(0.0, $data['hours_per_week'], $why);
        $this->assertSame('', $data['preferred_days'], $why);
        $this->assertSame('', $data['preferred_time'], $why);
        $this->assertSame('{}', $data['plan_data'], $why);
    }

    /**
     * A learner writes a study plan and gets exactly that plan back.
     *
     * If this breaks, a learner opens the planner, types their hours in, and the
     * panel comes back blank or errors — while every member of staff who checks it
     * sees a working feature, because staff hold capabilities learners do not.
     *
     * @return void
     */
    public function test_a_learner_saves_a_plan_and_reads_back_every_field(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $alice = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($alice->id, $course->id, 'student');
        $this->act_as($alice);
        $this->assert_acting_user_is_only_a_learner((int) $course->id);

        $saved = $this->save((int) $course->id, 7.5);
        $this->assertFalse($saved['error'], 'A learner must be able to save their own study plan.');
        $this->assertSame(['success' => true], $saved['data']);

        $read = $this->read((int) $course->id);
        $this->assertFalse($read['error'], 'A learner must be able to read their own study plan.');

        $this->assertTrue($read['data']['hasplan']);
        $this->assertSame(7.5, $read['data']['hours_per_week']);
        $this->assertSame('mon,wed', $read['data']['preferred_days']);
        $this->assertSame('evening', $read['data']['preferred_time']);
        $this->assertSame(
            self::PLAN_JSON,
            $read['data']['plan_data'],
            'The plan comes back byte for byte, or the learner loses the schedule they built.'
        );
    }

    /**
     * A learner with no plan is never handed somebody else's.
     *
     * If this breaks, a learner opens a planner they have never used and finds
     * another student's hours, study days and goals already filled in.
     *
     * @return void
     */
    public function test_a_learner_without_a_plan_is_not_shown_another_learners(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($alice->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($bob->id, $course->id, 'student');

        $this->act_as($bob);
        $this->seed_plan((int) $course->id);

        $this->act_as($alice);
        $this->assert_acting_user_is_only_a_learner((int) $course->id);

        $read = $this->read((int) $course->id);
        $this->assertFalse($read['error']);
        $this->assert_is_the_empty_default(
            $read['data'],
            'Alice has no plan, so she must receive the documented empty default and never Bob\'s row.'
        );
    }

    /**
     * Nothing a learner puts in the plan JSON can make the save land on someone else.
     *
     * plan_data is the only free-form field the endpoint accepts. If this breaks, a
     * learner editing the request their own browser sends can overwrite a
     * classmate's study plan, and the classmate's reminders start describing a
     * schedule they never set.
     *
     * @return void
     */
    public function test_plan_data_json_cannot_retarget_the_save_at_another_learner(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($alice->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($bob->id, $course->id, 'student');

        $this->act_as($bob);
        $this->seed_plan((int) $course->id);

        $this->act_as($alice);
        $this->assert_acting_user_is_only_a_learner((int) $course->id);

        $hostile = json_encode(['userid' => (int) $bob->id, 'schedule' => ['mon' => 'Unit 1']]);
        $saved = $this->save((int) $course->id, 3.0, 'tue', 'morning', $hostile);
        $this->assertFalse($saved['error'], 'The save itself is legitimate; only its payload is hostile.');

        $bobrows = $DB->get_records(
            'local_ai_course_assistant_plans',
            ['userid' => $bob->id, 'courseid' => $course->id]
        );
        $this->assertCount(1, $bobrows, 'Alice\'s save must not have created or moved a row onto Bob.');
        $this->assertEquals(7.5, (float) reset($bobrows)->hours_per_week, 'Bob\'s hours were overwritten.');

        $alicerows = $DB->get_records(
            'local_ai_course_assistant_plans',
            ['userid' => $alice->id, 'courseid' => $course->id]
        );
        $this->assertCount(1, $alicerows, 'Alice\'s own plan must exist, owned by Alice.');
        $this->assertEquals(3.0, (float) reset($alicerows)->hours_per_week);

        $this->act_as($bob);
        $read = $this->read((int) $course->id);
        $this->assertFalse($read['error']);
        $this->assertSame(7.5, $read['data']['hours_per_week'], 'Bob still sees his own plan.');
        $this->assertSame('mon,wed', $read['data']['preferred_days']);
        $this->assertSame(self::PLAN_JSON, $read['data']['plan_data']);
    }

    /**
     * Neither endpoint has a knob for choosing whose plan to touch.
     *
     * If this breaks, the study plan grows an impersonation parameter: any enrolled
     * learner could name a classmate and read or rewrite that classmate's plan.
     *
     * @return void
     */
    public function test_neither_endpoint_accepts_a_userid_parameter(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($alice->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($bob->id, $course->id, 'student');

        $this->act_as($bob);
        $this->seed_plan((int) $course->id);

        $this->act_as($alice);
        $this->assert_acting_user_is_only_a_learner((int) $course->id);

        $write = external_api::call_external_function(
            'local_ai_course_assistant_update_study_plan',
            [
                'courseid' => (int) $course->id,
                'hours_per_week' => 1.0,
                'preferred_days' => 'fri',
                'preferred_time' => 'morning',
                'plan_data' => '{}',
                'userid' => (int) $bob->id,
            ],
            true
        );
        $this->assertTrue($write['error'], 'A userid knob on the write endpoint must not exist.');
        $this->assertSame('invalidparameter', $write['exception']->errorcode);

        $get = external_api::call_external_function(
            'local_ai_course_assistant_get_study_plan',
            ['courseid' => (int) $course->id, 'userid' => (int) $bob->id],
            true
        );
        $this->assertTrue($get['error'], 'A userid knob on the read endpoint must not exist.');
        $this->assertSame('invalidparameter', $get['exception']->errorcode);

        $bobrow = $DB->get_record(
            'local_ai_course_assistant_plans',
            ['userid' => $bob->id, 'courseid' => $course->id]
        );
        $this->assertEquals(7.5, (float) $bobrow->hours_per_week, 'Bob\'s plan must be untouched.');
        $this->assertFalse(
            $DB->record_exists(
                'local_ai_course_assistant_plans',
                ['userid' => $alice->id, 'courseid' => $course->id]
            ),
            'The refused call must not have written anything for the caller either.'
        );
    }

    /**
     * The branch that only fires for a learner who HAS a plan survives the ajax path.
     *
     * A declared return key missing from the populated branch throws only for
     * learners with data, after the row is written. Everyone testing with an empty
     * planner sees it work; the first learner to save a plan gets an error and their
     * panel never renders again.
     *
     * @return void
     */
    public function test_the_populated_return_branch_survives_the_ajax_path(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $alice = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($alice->id, $course->id, 'student');
        $this->act_as($alice);
        $this->assert_acting_user_is_only_a_learner((int) $course->id);

        $expectedkeys = ['hasplan', 'hours_per_week', 'preferred_days', 'preferred_time', 'plan_data'];

        $empty = $this->read((int) $course->id);
        $this->assertFalse($empty['error'], 'The empty branch must pass clean_returnvalue().');
        $this->assertSame($expectedkeys, array_keys($empty['data']));

        $this->seed_plan((int) $course->id);

        $populated = $this->read((int) $course->id);
        $this->assertFalse(
            $populated['error'],
            'The populated branch must pass clean_returnvalue() too: '
                . ($populated['exception']->message ?? '')
        );
        $this->assertSame(
            $expectedkeys,
            array_keys($populated['data']),
            'Every declared key must reach the browser on the branch that has real data.'
        );
    }

    /**
     * A row with NULL columns still reaches the learner as something usable.
     *
     * The schema allows NULL in plan_data, preferred_days and preferred_time, and a
     * restore or a row written by an older release can hold them. If this breaks, an
     * affected learner's planner dies on the ajax call, or the browser is handed a
     * plan_data that JSON.parse cannot read. The second half matters just as much:
     * a real plan must never report 0.0 hours, because hasplan is the only signal of
     * absence a client has.
     *
     * @return void
     */
    public function test_null_columns_read_back_as_usable_defaults_and_real_hours_survive(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $alice = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($alice->id, $course->id, 'student');
        $this->act_as($alice);
        $this->assert_acting_user_is_only_a_learner((int) $course->id);

        $this->seed_plan((int) $course->id);

        $where = ['userid' => $alice->id, 'courseid' => $course->id];
        foreach (['plan_data', 'preferred_days', 'preferred_time'] as $field) {
            $DB->set_field('local_ai_course_assistant_plans', $field, null, $where);
        }

        $read = $this->read((int) $course->id);
        $this->assertFalse(
            $read['error'],
            'A NULL column must not blow up the learner\'s planner: '
                . ($read['exception']->message ?? '')
        );

        $this->assertTrue($read['data']['hasplan']);
        $this->assertSame('{}', $read['data']['plan_data']);
        $this->assertSame('', $read['data']['preferred_days']);
        $this->assertSame('', $read['data']['preferred_time']);
        $this->assertIsArray(
            json_decode($read['data']['plan_data'], true),
            'plan_data must always be JSON the browser can parse.'
        );

        $this->assertSame(
            7.5,
            $read['data']['hours_per_week'],
            'A real plan reports its saved hours. 0.0 is the empty default and would read as '
                . 'a plan of zero hours per week.'
        );
    }

    /**
     * Impossible hours are refused, with a useful message, and nothing is written.
     *
     * If the floor or the ceiling stops working, a learner who fat-fingers 0 or 168
     * saves it and their reminder emails are built around nonsense. If the refusal
     * ever moves after the save, a learner who is told "that number is not allowed"
     * has already lost the plan they had.
     *
     * @return void
     */
    public function test_impossible_hours_are_refused_and_the_existing_plan_survives(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $alice = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($alice->id, $course->id, 'student');
        $this->act_as($alice);
        $this->assert_acting_user_is_only_a_learner((int) $course->id);

        $this->seed_plan((int) $course->id);

        foreach ([0.0, 0.4, -1.0, 61.0, 168.0] as $bad) {
            $refused = $this->save((int) $course->id, $bad);
            $this->assertTrue($refused['error'], "{$bad} hours per week must be refused.");
            $this->assertSame(
                'studyplan:hours_out_of_range',
                $refused['exception']->errorcode,
                "{$bad} hours per week must be refused for being out of range, not for some other reason."
            );

            $after = $this->read((int) $course->id);
            $this->assertFalse($after['error']);
            $this->assertSame(
                7.5,
                $after['data']['hours_per_week'],
                "A refused update of {$bad} must leave the learner's existing plan alone."
            );
        }

        $last = $this->save((int) $course->id, 168.0);
        foreach (['0.5', '60', '168'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $last['exception']->message,
                'The refusal tells the learner the limits and what they typed.'
            );
        }

        $this->assertFalse($this->save((int) $course->id, 0.5)['error'], '0.5 is the inclusive floor.');
        $this->assertSame(0.5, $this->read((int) $course->id)['data']['hours_per_week']);

        $this->assertFalse($this->save((int) $course->id, 60.0)['error'], '60 is the inclusive ceiling.');
        $this->assertSame(60.0, $this->read((int) $course->id)['data']['hours_per_week']);
    }

    /**
     * A plan belongs to one course and does not follow the learner around.
     *
     * If this breaks, a learner's goal, hours and deadline for one course show up in
     * the planner of every other course they are taking, and in the tutor's prompt
     * there too.
     *
     * @return void
     */
    public function test_a_plan_does_not_follow_the_learner_into_another_course(): void {
        $this->resetAfterTest();

        $courseone = $this->getDataGenerator()->create_course();
        $coursetwo = $this->getDataGenerator()->create_course();
        $alice = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($alice->id, $courseone->id, 'student');
        $this->getDataGenerator()->enrol_user($alice->id, $coursetwo->id, 'student');
        $this->act_as($alice);
        $this->assert_acting_user_is_only_a_learner((int) $courseone->id);
        $this->assert_acting_user_is_only_a_learner((int) $coursetwo->id);

        $this->seed_plan((int) $courseone->id);

        $read = $this->read((int) $coursetwo->id);
        $this->assertFalse($read['error']);
        $this->assert_is_the_empty_default(
            $read['data'],
            'The plan was made for the other course and must not appear in this one.'
        );

        $stillthere = $this->read((int) $courseone->id);
        $this->assertFalse($stillthere['error']);
        $this->assertSame(7.5, $stillthere['data']['hours_per_week']);
    }
}
