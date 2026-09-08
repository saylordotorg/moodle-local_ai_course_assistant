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
 * A refusal by the quiz lock must be visible in the audit log.
 *
 * The lock is an academic-integrity control, so the event a reviewer most needs
 * is the one that was missing: a learner tried to use the assistant during an
 * open attempt and was turned away. A staging census of the whole audit log
 * found four action types -- message_sent, emergency_disable, emergency_restore
 * and consent_given -- and none of them recorded a refusal, so a blocked turn
 * was indistinguishable from an ordinary one after the fact.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\quiz_lock::record_refusal
 */
final class quiz_lock_audit_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $student;

    /** @var \stdClass */
    private $quiz;

    /**
     * A learner with an attempt open on a quiz in their course.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->student = $gen->create_user();
        $gen->enrol_user($this->student->id, $this->course->id);
        $this->setUser($this->student);
        set_config('quiz_lock_enabled', 1, 'local_ai_course_assistant');
        set_config('quiz_lock_scope', quiz_lock::SCOPE_COURSE, 'local_ai_course_assistant');
        $this->quiz = $gen->create_module('quiz', ['course' => $this->course->id, 'timelimit' => 0]);
    }

    /**
     * Open an attempt for the learner.
     *
     * @return void
     */
    private function open_attempt(): void {
        global $DB;
        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $this->quiz->id,
            'userid' => $this->student->id,
            'attempt' => 1,
            'uniqueid' => random_int(100000, 999999),
            'layout' => '',
            'state' => 'inprogress',
            'timestart' => time(),
            'preview' => 0,
            'timemodified' => time(),
            'timemodifiedoffline' => 0,
            'sumgrades' => null,
        ]);
    }

    /**
     * The refusal writes a row that names the attempt.
     *
     * The attempt id is the point: an integrity review queries on the specific
     * assessment, not on "somebody was blocked at some time".
     */
    public function test_refusal_is_recorded_with_the_attempt(): void {
        global $DB;
        $this->open_attempt();

        $attempt = quiz_lock::active_attempt((int) $this->student->id, (int) $this->course->id);
        $this->assertNotNull($attempt);

        quiz_lock::record_refusal(
            (int) $this->student->id,
            (int) $this->course->id,
            $attempt,
            'chat'
        );

        $row = $DB->get_record('local_ai_course_assistant_audit', [
            'action' => 'quiz_lock_refused',
            'userid' => $this->student->id,
        ]);
        $this->assertNotFalse($row, 'A quiz-lock refusal must be auditable.');
        $this->assertSame((int) $this->course->id, (int) $row->courseid);

        $details = json_decode($row->details, true);
        $this->assertSame((int) $attempt->id, $details['attempt_id']);
        $this->assertSame((int) $this->quiz->id, $details['quiz_id']);
        $this->assertSame('chat', $details['surface']);
        $this->assertSame(quiz_lock::SCOPE_COURSE, $details['scope']);
    }

    /**
     * The surface that refused is distinguishable.
     *
     * Chat, the practice-quiz generator and the provider chokepoint all refuse.
     * A review that cannot tell them apart cannot tell which control worked.
     */
    public function test_each_surface_is_named(): void {
        global $DB;
        $this->open_attempt();
        $attempt = quiz_lock::active_attempt((int) $this->student->id, (int) $this->course->id);

        foreach (['chat', 'quiz', 'provider'] as $surface) {
            quiz_lock::record_refusal(
                (int) $this->student->id,
                (int) $this->course->id,
                $attempt,
                $surface
            );
        }

        $rows = $DB->get_records('local_ai_course_assistant_audit',
            ['action' => 'quiz_lock_refused']);
        $surfaces = [];
        foreach ($rows as $r) {
            $surfaces[] = json_decode($r->details, true)['surface'];
        }
        sort($surfaces);
        $this->assertSame(['chat', 'provider', 'quiz'], $surfaces);
    }

    /**
     * Merely rendering a page must not write a refusal row.
     *
     * is_locked_for() is called on every page load to decide whether to grey
     * the composer. Logging there would fill the log with rows for opening a
     * course page, which is the opposite of making the real event findable.
     */
    public function test_checking_the_lock_does_not_log(): void {
        global $DB;
        $this->open_attempt();

        quiz_lock::is_locked_for((int) $this->student->id, (int) $this->course->id);
        quiz_lock::active_attempt((int) $this->student->id, (int) $this->course->id);

        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_audit',
            ['action' => 'quiz_lock_refused']));
    }
}
