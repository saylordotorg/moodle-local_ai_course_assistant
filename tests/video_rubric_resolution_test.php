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
 * resolve_video_criteria() decides what a Soapbox video attempt is scored on.
 *
 * It is reached in production from score_speech, and until now had no coverage
 * of any branch. The rubric_admin video tab that would let an administrator
 * author a type='video' rubric was specced and cut, so the course-scoped branch
 * is currently unreachable through the UI -- which is exactly why it is worth
 * pinning here. Whoever wires that tab up inherits these branches, and the last
 * case below is the trap they will fall into.
 *
 * @package    local_ai_course_assistant
 * @covers     \local_ai_course_assistant\rubric_manager::resolve_video_criteria
 */
final class video_rubric_resolution_test extends \advanced_testcase {

    /**
     * Insert a video rubric directly, the way the cut admin tab would have.
     *
     * @param int $courseid 0 for a global rubric.
     * @param array $criteria Criteria rows.
     * @return int The inserted rubric id.
     */
    private function insert_video_rubric(int $courseid, array $criteria): int {
        global $DB;
        return (int) $DB->insert_record('local_ai_course_assistant_rubrics', (object) [
            'courseid' => $courseid,
            'type' => rubric_manager::TYPE_VIDEO,
            'title' => 'Video',
            'criteria' => json_encode($criteria),
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Criterion names from a resolve_video_criteria() result.
     *
     * @param array $res The result array.
     * @return string[]
     */
    private function names(array $res): array {
        return array_map(static fn($c) => (string) ($c['name'] ?? ''), $res['criteria']);
    }

    /**
     * A rubric authored ON the course is a deliberate act and wins outright.
     *
     * @return void
     */
    public function test_a_course_scoped_video_rubric_wins_outright(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $id = $this->insert_video_rubric((int) $course->id, [
            ['name' => 'Bespoke one', 'description' => 'd', 'max_score' => 5],
            ['name' => 'Bespoke two', 'description' => 'd', 'max_score' => 5, 'visual' => true],
        ]);

        $res = rubric_manager::resolve_video_criteria(
            (int) $course->id,
            rubric_manager::SPEECH_LEVEL_ESL_BEGINNER,
            true
        );

        $this->assertSame(['Bespoke one', 'Bespoke two'], $this->names($res),
            'a course-scoped video rubric replaces the speech base entirely; it is not merged');
        $this->assertSame($id, $res['rubricid']);
    }

    /**
     * A GLOBAL video rubric contributes only its visual rows.
     *
     * The course keeps its configured speaking level. A global row that
     * replaced the whole set would silently shadow every course's level, which
     * is the bug class this branch exists to avoid.
     *
     * @return void
     */
    public function test_a_global_video_rubric_contributes_only_its_visual_rows(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $this->insert_video_rubric(0, [
            ['name' => 'Global spoken row', 'description' => 'd', 'max_score' => 5],
            ['name' => 'Global visual row', 'description' => 'd', 'max_score' => 5, 'visual' => true],
        ]);

        $res = rubric_manager::resolve_video_criteria(
            (int) $course->id,
            rubric_manager::SPEECH_LEVEL_ESL_BEGINNER,
            true
        );
        $names = $this->names($res);

        $this->assertNotContains('Global spoken row', $names,
            'a global video rubric must not override the course speaking level');
        $this->assertContains('Global visual row', $names,
            'its visual rows DO replace the shipped VISUAL_CRITERIA');
        $this->assertNotContains('Body Language & Gestures', $names,
            'the shipped visual criteria are replaced, not appended to');
        // rubricid comes from the speech base: video is not seeded by
        // ensure_default_rubrics(), so a failure here means the seeding
        // changed, not that this expectation is wrong.
        $this->assertSame(0, $res['rubricid']);
    }

    /**
     * With no usable video, visual criteria leave the criteria list but stay in
     * visualnames.
     *
     * score_speech relies on exactly that asymmetry: the names build the
     * allowlist that keeps a body-language score out of an audio-only attempt,
     * while the criteria list is what reaches the prompt.
     *
     * @return void
     */
    public function test_without_visual_evidence_the_visual_rows_are_stripped_but_still_named(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $res = rubric_manager::resolve_video_criteria(
            (int) $course->id,
            rubric_manager::SPEECH_LEVEL_ESL_BEGINNER,
            false
        );

        foreach ($res['criteria'] as $c) {
            $this->assertEmpty($c['visual'] ?? null,
                'no visual criterion may reach the prompt when there is no usable video');
        }
        $this->assertNotEmpty($res['visualnames'],
            'the names must survive the strip, or score_speech cannot build its allowlist');
        $this->assertNotContains('Body Language & Gestures', $this->names($res));
    }

    /**
     * Characterisation: a saved rubric that lost its `visual` flags.
     *
     * rubric_admin's criteria-cleaning loop rebuilds each entry from name,
     * description and max_score only, so the first Save through that page drops
     * the flag. This pins what happens next: nothing is recognised as visual, so
     * nothing is stripped for an audio-only attempt and visualnames is empty --
     * meaning the model would be asked to score body language from a transcript.
     *
     * If someone wires up the video tab and this test fails, the fix is in
     * rubric_admin's cleaning loop, not here.
     *
     * @return void
     */
    public function test_a_course_rubric_whose_visual_flags_were_stripped_scores_everything(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $this->insert_video_rubric((int) $course->id, [
            ['name' => 'Delivery', 'description' => 'd', 'max_score' => 5],
            ['name' => 'Body Language & Gestures', 'description' => 'd', 'max_score' => 5],
        ]);

        $res = rubric_manager::resolve_video_criteria(
            (int) $course->id,
            rubric_manager::SPEECH_LEVEL_ESL_BEGINNER,
            false
        );

        $this->assertSame([], $res['visualnames'],
            'with the flag gone nothing is recognised as visual');
        $this->assertSame(['Delivery', 'Body Language & Gestures'], $this->names($res),
            'so nothing is stripped, and a body-language criterion reaches an audio-only prompt');
    }
}
