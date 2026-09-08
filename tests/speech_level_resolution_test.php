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
 * A course's ESL speaking level must survive an auto-seeded global rubric
 * (v7.3.2, finding F19).
 *
 * ensure_default_rubrics() seeds a global (courseid = 0) speech rubric holding
 * the GENERAL criteria, and it is reachable from get_rubric -- which any learner
 * starting conversation or pronunciation practice, in any course on the site,
 * can trigger. get_active_rubric() falls back to courseid = 0, so from that
 * moment every Soapbox score in every course was graded against the general
 * criteria instead of the configured ESL ones. Nothing surfaced it: the level
 * select still displayed "ESL (beginner)" and the coaching prose still read as
 * ESL-aware, because only the criteria were swapped.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\rubric_manager::resolve_speech_criteria
 */
final class speech_level_resolution_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Criterion names for a resolution, for readable assertions.
     *
     * @param int $courseid
     * @param string $level
     * @return string[]
     */
    private function names(int $courseid, string $level): array {
        $r = rubric_manager::resolve_speech_criteria($courseid, $level);
        return array_map(static fn($c) => (string) ($c['name'] ?? ''), $r['criteria']);
    }

    /**
     * The headline case: a seeded global rubric must not override an ESL level.
     */
    public function test_auto_seeded_global_rubric_does_not_override_an_esl_level(): void {
        $course = $this->getDataGenerator()->create_course();

        // Exactly what a learner clicking conversation practice causes.
        rubric_manager::ensure_default_rubrics();

        $esl = $this->names((int) $course->id, rubric_manager::SPEECH_LEVEL_ESL_BEGINNER);
        $general = rubric_manager::speech_preset(rubric_manager::SPEECH_LEVEL_GENERAL)['criteria'];
        $generalnames = array_map(static fn($c) => (string) ($c['name'] ?? ''), $general);

        $this->assertNotSame($generalnames, $esl,
            'an ESL course must not be scored against the general criteria');
        $this->assertSame(
            array_map(
                static fn($c) => (string) ($c['name'] ?? ''),
                rubric_manager::speech_preset(rubric_manager::SPEECH_LEVEL_ESL_BEGINNER)['criteria']
            ),
            $esl,
            'the ESL beginner preset must be what is used'
        );
    }

    /**
     * A rubric authored ON the course is a deliberate act and still wins.
     */
    public function test_course_scoped_rubric_beats_the_preset(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();

        $DB->insert_record('local_ai_course_assistant_rubrics', (object) [
            'courseid' => $course->id,
            'type' => rubric_manager::TYPE_SPEECH,
            'title' => 'Custom',
            'criteria' => json_encode([['name' => 'Bespoke criterion', 'description' => 'd']]),
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->assertSame(['Bespoke criterion'],
            $this->names((int) $course->id, rubric_manager::SPEECH_LEVEL_ESL_BEGINNER),
            'an explicitly authored course rubric must always win');
    }

    /**
     * With the general level, a global rubric is the intended source.
     */
    public function test_global_rubric_applies_at_the_general_level(): void {
        $course = $this->getDataGenerator()->create_course();
        rubric_manager::ensure_default_rubrics();

        $r = rubric_manager::resolve_speech_criteria((int) $course->id, rubric_manager::SPEECH_LEVEL_GENERAL);

        $this->assertGreaterThan(0, $r['rubricid'],
            'at the general level the seeded global rubric is the right source');
    }

    /**
     * When the preset is used, no rubric id may be recorded against the score --
     * a score row must not point at a rubric that did not produce it.
     */
    public function test_preset_resolution_reports_no_rubric_id(): void {
        $course = $this->getDataGenerator()->create_course();
        rubric_manager::ensure_default_rubrics();

        $r = rubric_manager::resolve_speech_criteria((int) $course->id, rubric_manager::SPEECH_LEVEL_ESL_BEGINNER);

        $this->assertSame(0, $r['rubricid']);
    }
}
