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
 * The body-language observation must not outlive the video it describes.
 *
 * The observation is prose a vision model wrote about a named learner's body.
 * It lived in the score row's session_meta, and the score row deliberately
 * survives retention so the learner keeps their feedback. So the description
 * outlived the video, outlived the still frames it was derived from, and was
 * kept indefinitely, while being read by nothing.
 *
 * It also made the privacy notice untrue: that notice promises the recording is
 * deleted "together with the still frames used for body-language feedback", so
 * a learner reasonably concludes no visual material survives.
 *
 * @package    local_ai_course_assistant
 * @covers     \local_ai_course_assistant\task\soapbox_cleanup
 */
final class soapbox_observation_retention_test extends \advanced_testcase {

    /**
     * An expired recording whose score carries a body-language observation.
     *
     * @return array [recording id, score id]
     */
    private function expired_attempt_with_observation(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $scoreid = (int) $DB->insert_record('local_ai_course_assistant_practice_scores', (object) [
            'userid' => $user->id,
            'courseid' => $course->id,
            'rubricid' => 0,
            'session_type' => rubric_manager::TYPE_SPEECH,
            'overall_score' => 4,
            'scores' => json_encode([['name' => 'Delivery', 'score' => 4, 'feedback' => 'Clear.']]),
            'ai_feedback' => 'Good pace.',
            'session_meta' => json_encode([
                'tips' => ['Slow down', 'Look up', 'Pause more'],
                'visual_observation' => 'The speaker keeps both hands below the desk edge '
                    . 'and looks down and to the left for most of the recording.',
            ]),
            'timecreated' => time(),
        ]);

        $assignid = (int) $DB->insert_record('local_ai_course_assistant_sbx_assign', (object) [
            'courseid' => $course->id,
            'name' => 'Presentation',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $recid = (int) $DB->insert_record('local_ai_course_assistant_sbx_rec', (object) [
            'assignid' => $assignid,
            'userid' => $user->id,
            'mode' => 'video',
            'storage_key' => 'sbx/rec/expired.webm',
            'frames_key' => 'sbx/frames/expired.jpg',
            'status' => 'scored',
            'scoreid' => $scoreid,
            'expires_at' => time() - DAYSECS,
            'timecreated' => time() - (8 * DAYSECS),
        ]);

        return [$recid, $scoreid];
    }

    /**
     * Retention deletion takes the observation with the video.
     *
     * @return void
     */
    public function test_the_observation_does_not_outlive_the_recording(): void {
        global $DB;
        $this->resetAfterTest();

        [$recid, $scoreid] = $this->expired_attempt_with_observation();

        // Drive the private retirement path the scheduled task uses, without
        // requiring configured object storage: the storage delete is best
        // effort and already covered by soapbox_storage_test.
        $task = new task\soapbox_cleanup();
        $method = (new \ReflectionClass($task))->getMethod('forget_visual_observation');
        $method->setAccessible(true);
        $method->invoke($task, $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $recid]));

        $meta = json_decode(
            (string) $DB->get_field('local_ai_course_assistant_practice_scores', 'session_meta', ['id' => $scoreid]),
            true
        );

        $this->assertArrayNotHasKey(
            'visual_observation',
            $meta,
            'A vision model\'s description of a learner\'s body must not survive the video it '
                . 'describes. The still frames are deleted on the retention clock and the privacy '
                . 'notice promises they are, so prose derived from them cannot be kept indefinitely '
                . 'in a field nothing reads.'
        );
    }

    /**
     * The rest of the feedback is kept, because the learner is told it is.
     *
     * Without this, deleting session_meta wholesale would pass the test above
     * and silently destroy the tips the privacy notice says are retained.
     *
     * @return void
     */
    public function test_the_feedback_the_learner_was_promised_is_kept(): void {
        global $DB;
        $this->resetAfterTest();

        [$recid, $scoreid] = $this->expired_attempt_with_observation();

        $task = new task\soapbox_cleanup();
        $method = (new \ReflectionClass($task))->getMethod('forget_visual_observation');
        $method->setAccessible(true);
        $method->invoke($task, $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $recid]));

        $score = $DB->get_record('local_ai_course_assistant_practice_scores', ['id' => $scoreid]);
        $meta = json_decode((string) $score->session_meta, true);

        $this->assertSame(
            ['Slow down', 'Look up', 'Pause more'],
            $meta['tips'] ?? [],
            'the next-time tips are feedback the notice says is kept after the video goes'
        );
        $this->assertSame(4, (int) $score->overall_score, 'the score itself is untouched');
        $this->assertSame('Good pace.', $score->ai_feedback, 'the overall comment is untouched');
    }
}
