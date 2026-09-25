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
use local_ai_course_assistant\external\score_speech;
use local_ai_course_assistant\external\soapbox_finalize_recording;
use local_ai_course_assistant\external\soapbox_get_playback;
use local_ai_course_assistant\external\soapbox_get_upload_url;
use local_ai_course_assistant\provider\stub_provider;

/**
 * Soapbox, driven end to end as the enrolled student it was written for.
 *
 * WHY THIS FILE EXISTS. Soapbox has four ajax entry points, all declared with
 * `capabilities => local/ai_course_assistant:use`, which is to say the learner
 * path IS the production path. Before this file, three of the four had no test
 * of any kind, and not one Soapbox test anywhere set a non-admin user: every
 * one of them called setAdminUser() or was a \basic_testcase with no session at
 * all. So the whole surface was verified exclusively by somebody who can see
 * everything.
 *
 * That is not a stylistic complaint. It is the exact shape of three defects
 * that shipped in one day: a learner-facing panel read its data through an API
 * requiring a capability no student holds, so the feature was invisible to
 * learners and perfectly visible to staff, and every test and every manual
 * check agreed it worked. Tests that assert refusals ("a learner cannot reach
 * someone else's data") and tests that assert shapes ("the return structure
 * matches") both stay green through that defect. Only driving the feature with
 * real data, as the person it is for, catches it.
 *
 * So every test here does four things: it seeds through the plugin's own
 * writers, it sets an enrolled student, it asserts FIRST that the student holds
 * neither :manage nor :viewanalytics, and then it asserts on what that student
 * actually receives. The capability assertions are load-bearing. Without them
 * this file would pass while running as somebody who can see everything, which
 * is how the three defects hid.
 *
 * The refusal tests are each paired with an over-correction guard proving the
 * owner still gets their content, because "require :manage for everyone" passes
 * a refusal test and breaks the feature for every learner on the site.
 *
 * Nothing here touches the network. Presigning is pure local SigV4, the
 * provider is stub_provider at the documented seam, no recording carries a
 * deck_key (so the deck-render curl branch is never entered) and none carries a
 * frames_key (so the vision provider is never reached). The one deliberate
 * omission is soapbox_finalize_recording's happy path: it calls
 * soapbox_storage::object_size(), which is a live S3 HEAD, so it is left
 * untested here rather than faked. The ownership refusal below throws before
 * object_size() is reached, which is why that assertion is network-free.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\external\soapbox_get_upload_url
 * @covers     \local_ai_course_assistant\external\soapbox_get_playback
 * @covers     \local_ai_course_assistant\external\soapbox_finalize_recording
 * @covers     \local_ai_course_assistant\external\score_speech
 */
final class soapbox_learner_journey_test extends \advanced_testcase {
    /** @var \stdClass The enrolled student every test acts as. */
    private $learner;

    /** @var \stdClass A second enrolled student, whose things the learner must not reach. */
    private $peer;

    /** @var int The course both learners are enrolled in. */
    private $courseid;

    /** @var int A visible, video-mode Soapbox assignment created by staff. */
    private $assignid;

    /**
     * A course, two enrolled students, and a staff-created video assignment.
     *
     * Seeded through the real writers wherever one exists: the core generator
     * for the course and the enrolments, and soapbox_assignment_manager for the
     * assignment (which calls require_manage(), so it is created as an admin and
     * could not be created as the learner). The plugin ships no test generator
     * of its own -- tests/generator/ is empty -- so its own manager APIs are the
     * closest equivalent.
     *
     * The enrolments use the student archetype on purpose. That is what makes
     * the assertFalse() capability checks below real rather than decorative: the
     * learner genuinely holds :use and genuinely does not hold :manage.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->courseid = (int) $course->id;

        $this->learner = $generator->create_user();
        $this->peer = $generator->create_user();
        $generator->enrol_user($this->learner->id, $this->courseid, 'student');
        $generator->enrol_user($this->peer->id, $this->courseid, 'student');

        set_config('soapbox_enabled', 1, 'local_ai_course_assistant');
        set_config('provider', 'stub', 'local_ai_course_assistant');
        set_config('apikey', 'test-key-not-used-by-the-stub', 'local_ai_course_assistant');
        // AWS's own published example credentials. soapbox_storage::is_configured()
        // only checks both are non-empty and presign_put/presign_get are pure
        // local SigV4, so this needs no bucket, no credentials and no network.
        set_config('soapbox_storage_key', 'AKIAIOSFODNN7EXAMPLE', 'local_ai_course_assistant');
        set_config(
            'soapbox_storage_secret',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'local_ai_course_assistant'
        );
        stub_provider::reset();

        $this->setAdminUser();
        $this->assignid = soapbox_assignment_manager::create_assignment(
            $this->courseid,
            ['name' => 'Intro talk', 'mode' => 'video']
        );

        $this->setUser($this->learner->id);
    }

    /**
     * Prove the session is a learner's, not a staff member's.
     *
     * Called at the top of every test. If either of these ever starts passing,
     * every other assertion in this file becomes a statement about what an
     * administrator can do, which is the thing that already failed to catch
     * three shipped defects.
     *
     * @return void
     */
    private function assert_running_as_a_learner(): void {
        $context = \context_course::instance($this->courseid);
        $this->assertTrue(
            has_capability('local/ai_course_assistant:use', $context),
            'The test user must be able to use the assistant, or this is not the learner journey.'
        );
        $this->assertFalse(
            has_capability('local/ai_course_assistant:manage', $context),
            'A learner must not hold :manage, or every assertion in this file proves nothing.'
        );
        $this->assertFalse(
            has_capability('local/ai_course_assistant:viewanalytics', $context),
            'A learner must not hold :viewanalytics, or every assertion in this file proves nothing.'
        );
    }

    /**
     * Insert a recording owned by the given user.
     *
     * Direct insert, which this file otherwise avoids, because the real writer
     * -- soapbox_finalize_recording -- calls soapbox_storage::object_size(), a
     * live S3 HEAD. The storage key is nevertheless built for the OWNER through
     * soapbox_storage::make_object_key(), so the ownership checks under test run
     * against a realistic key rather than a sentinel string. deck_key and
     * frames_key stay null, which keeps the deck-render and vision branches out
     * of reach without any stubbing.
     *
     * @param int $ownerid The learner the recording belongs to.
     * @param string $mode 'video' or 'audio'.
     * @return int The new recording id.
     */
    private function seed_recording(int $ownerid, string $mode = 'video'): int {
        global $DB;

        $now = time();
        return (int) $DB->insert_record('local_ai_course_assistant_sbx_rec', (object) [
            'assignid'         => $this->assignid,
            'userid'           => $ownerid,
            'topicid'          => null,
            'mode'             => $mode,
            'storage_key'      => soapbox_storage::make_object_key($this->courseid, $ownerid, 'mp4'),
            'deck_key'         => null,
            'slide_timeline'   => null,
            'frames_key'       => null,
            'duration_seconds' => 95,
            'size_bytes'       => 4096,
            'status'           => 'scored',
            'transcript'       => null,
            'scoreid'          => null,
            'expires_at'       => $now + DAYSECS,
            'timecreated'      => $now,
        ]);
    }

    /**
     * A transcript long enough to clear score_speech's 40-character floor.
     *
     * @return string
     */
    private function transcript(): string {
        return 'Good morning everyone. Today I want to explain how a tide works, why it happens '
            . 'twice a day in most places, and what that means for anyone who fishes here.';
    }

    /**
     * Program the coach's reply with the five shipped criteria, four of them
     * judged, plus one visual criterion the model was never shown.
     *
     * Four assessed scores summing to 16 over a maximum of 20, and Time
     * Management explicitly not assessed. Those numbers are chosen so the two
     * possible arithmetics give visibly different answers: 16/4 = 4 if the
     * unjudged row leaves the denominator, 16/5 = 3 if it is counted as a zero.
     *
     * @return void
     */
    private function program_coach_reply(): void {
        stub_provider::program_response('chat', json_encode([
            'criteria' => [
                [
                    'name' => 'Delivery & Fluency',
                    'score' => 4,
                    'feedback' => 'Steady pace throughout the middle section.',
                    'assessed' => true,
                ],
                [
                    'name' => 'Structure & Organization',
                    'score' => 3,
                    'feedback' => 'The close arrived before the third point landed.',
                    'assessed' => true,
                ],
                [
                    'name' => 'Content & Relevance',
                    'score' => 5,
                    'feedback' => 'Every example served the claim about tides.',
                    'assessed' => true,
                ],
                [
                    'name' => 'Language & Vocabulary',
                    'score' => 4,
                    'feedback' => 'Precise words for the technical part.',
                    'assessed' => true,
                ],
                [
                    'name' => 'Time Management',
                    'score' => 0,
                    'feedback' => 'No target length was given, so I could not judge this.',
                    'assessed' => false,
                ],
                [
                    'name' => 'Body Language & Gestures',
                    'score' => 5,
                    'feedback' => 'Open hands marked each transition.',
                    'assessed' => true,
                ],
            ],
            'overall' => 'A clear, well-organised first attempt.',
            'tips' => ['Slow the opening.', 'Signpost point three.', 'Land the close.'],
        ]));
    }

    /**
     * A learner who records gets an upload slot filed under their own name.
     *
     * If this breaks, a learner's recording is written into somebody else's
     * folder in storage. They then cannot finalize it -- finalize checks the key
     * is under the caller's own path -- so the attempt is lost after the upload
     * has already completed, and the bytes sit in a stranger's retention window
     * waiting to be deleted on that stranger's schedule.
     *
     * @return void
     */
    public function test_the_upload_slot_a_learner_is_given_is_filed_under_their_own_name(): void {
        $this->assert_running_as_a_learner();

        $result = soapbox_get_upload_url::execute($this->assignid, 'mp4');

        $ownprefix = soapbox_storage::prefix() . $this->courseid . '/' . (int) $this->learner->id . '/';
        $this->assertStringStartsWith(
            $ownprefix,
            $result['objectkey'],
            'The object key must sit under this learner\'s own path for this course. Any other '
                . 'path is a recording the learner can upload and then never finalize.'
        );
        $peerprefix = soapbox_storage::prefix() . $this->courseid . '/' . (int) $this->peer->id . '/';
        $this->assertStringStartsNotWith($peerprefix, $result['objectkey']);

        $this->assertSame('PUT', $result['method']);
        $this->assertSame(soapbox_storage::DEFAULT_EXPIRY, $result['expiresin']);
        $this->assertStringContainsString(
            soapbox_storage::encode_key_path($result['objectkey']),
            $result['uploadurl'],
            'The presigned URL must address the key the learner was just told to use.'
        );
        $this->assertStringContainsString('X-Amz-Signature=', $result['uploadurl']);

        $clean = external_api::clean_returnvalue(soapbox_get_upload_url::execute_returns(), $result);
        $this->assertSame($result['objectkey'], $clean['objectkey']);
        $this->assertSame(soapbox_storage::DEFAULT_EXPIRY, $clean['expiresin']);
    }

    /**
     * A learner cannot watch a classmate's recording.
     *
     * If this breaks, anyone enrolled on the course can play back any other
     * learner's video by guessing a small integer, and the presigned URL they
     * are handed keeps working for an hour after they leave the page.
     *
     * @return void
     */
    public function test_a_learner_cannot_watch_a_classmates_recording(): void {
        $this->assert_running_as_a_learner();

        $peerrecid = $this->seed_recording((int) $this->peer->id);

        $this->expectException(\core\exception\required_capability_exception::class);
        soapbox_get_playback::execute($peerrecid);
    }

    /**
     * The same learner CAN watch their own recording, and gets a real one back.
     *
     * The guard against over-correcting the test above. Requiring :manage from
     * everybody refuses the classmate exactly as wanted, and also makes playback
     * silently unavailable to every learner on the site while staff continue to
     * see it working -- the precise shape of the three defects this file exists
     * for. So the owner's happy path is asserted on content, not on the absence
     * of an exception.
     *
     * @return void
     */
    public function test_a_learner_can_watch_their_own_recording(): void {
        global $DB;

        $this->assert_running_as_a_learner();

        $ownrecid = $this->seed_recording((int) $this->learner->id);
        $ownkey = $DB->get_field('local_ai_course_assistant_sbx_rec', 'storage_key', ['id' => $ownrecid]);

        $result = soapbox_get_playback::execute($ownrecid);

        $this->assertSame('video', $result['mode'], 'The seeded recording was made in video mode.');
        $this->assertStringContainsString(
            soapbox_storage::encode_key_path($ownkey),
            $result['videourl'],
            'The learner must be handed a URL for their OWN recording, not some other object.'
        );
        $this->assertStringContainsString('X-Amz-Signature=', $result['videourl']);
        $this->assertSame([], $result['pages'], 'No deck was uploaded, so there are no slide images.');
        $this->assertSame('[]', $result['timeline'], 'No deck means no slide-advance timeline.');

        $clean = external_api::clean_returnvalue(soapbox_get_playback::execute_returns(), $result);
        $this->assertSame('video', $clean['mode']);
        $this->assertSame([], $clean['pages']);
    }

    /**
     * A learner cannot claim a classmate's upload as their own attempt.
     *
     * If this breaks, a learner can register somebody else's video as their own
     * submission: it is scored under their name, it burns a paid transcription
     * and scoring pass, and the row is permanent. The count assertion is the
     * point -- the call failing is not enough, the attempt must not exist.
     *
     * @return void
     */
    public function test_a_learner_cannot_claim_a_classmates_upload_as_their_own(): void {
        global $DB;

        $this->assert_running_as_a_learner();

        $peerkey = soapbox_storage::make_object_key($this->courseid, (int) $this->peer->id, 'mp4');
        $before = $DB->count_records('local_ai_course_assistant_sbx_rec');

        $thrown = null;
        try {
            soapbox_finalize_recording::execute($this->assignid, $peerkey);
        } catch (\moodle_exception $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Finalizing a key under another learner\'s path must be refused.');
        $this->assertSame(
            get_string('soapbox:bad_key', 'local_ai_course_assistant'),
            $thrown->getMessage(),
            'The learner must be told the upload is not theirs, not shown a generic failure.'
        );
        $this->assertSame(
            $before,
            $DB->count_records('local_ai_course_assistant_sbx_rec'),
            'No recording row may exist afterwards. A refused call that still writes the row is '
                . 'the same defect with a worse error message.'
        );
    }

    /**
     * A learner's score is written against the learner who spoke.
     *
     * If this breaks, the score lands on somebody else's history: the learner
     * who recorded sees an empty practice history and cannot tell whether their
     * attempt was scored at all, while an unrelated learner accumulates results
     * for speeches they never gave. The recording id here belongs to the peer on
     * purpose -- it is a learner-supplied parameter on an ajax endpoint, so the
     * caller, not the parameter, must decide whose score this is.
     *
     * @return void
     */
    public function test_a_score_is_written_against_the_learner_who_spoke(): void {
        global $DB;

        $this->assert_running_as_a_learner();
        $this->program_coach_reply();

        $peerrecid = $this->seed_recording((int) $this->peer->id);

        $result = score_speech::execute(
            $this->courseid,
            $this->transcript(),
            'Tides',
            'How tides work',
            0,
            95,
            'informative',
            '',
            0,
            '',
            $peerrecid
        );
        $this->assertTrue($result['success']);

        $rows = $DB->get_records('local_ai_course_assistant_practice_scores');
        $this->assertCount(1, $rows, 'One speech was scored, so exactly one row was written.');
        $row = reset($rows);
        $this->assertSame((int) $this->learner->id, (int) $row->userid);
        $this->assertSame($this->courseid, (int) $row->courseid);
        $this->assertSame(
            0,
            $DB->count_records('local_ai_course_assistant_practice_scores', ['userid' => $this->peer->id]),
            'Passing a classmate\'s recording id must not write anything to the classmate.'
        );

        $mine = rubric_manager::get_user_scores(
            (int) $this->learner->id,
            $this->courseid,
            rubric_manager::TYPE_SPEECH,
            5
        );
        $this->assertCount(1, $mine, 'The learner\'s own practice history must show the attempt.');
        $this->assertSame((int) $row->id, (int) $mine[0]->id);
        $this->assertSame(
            [],
            rubric_manager::get_user_scores((int) $this->peer->id, $this->courseid, rubric_manager::TYPE_SPEECH, 5),
            'The classmate\'s history must be untouched.'
        );
    }

    /**
     * A criterion nobody could judge is left out of the score, not scored zero.
     *
     * If this breaks, a learner whose four judged criteria averaged 4 out of 5
     * is shown a 3, and told "5 of 5 assessed (64%)" for a rubric row the coach
     * explicitly said it could not evaluate. These learners are self-paced with
     * nobody to appeal to, so a silently invented zero is final. The exact
     * numbers are asserted because "not empty" would pass either arithmetic.
     *
     * @return void
     */
    public function test_a_criterion_nobody_could_judge_leaves_the_denominator(): void {
        global $DB;

        $this->assert_running_as_a_learner();
        $this->program_coach_reply();

        $result = score_speech::execute($this->courseid, $this->transcript());

        $this->assertTrue($result['success']);
        $this->assertSame(
            4,
            $result['assessedcount'],
            'Four criteria were judged. Reporting five claims the coach evaluated something it said it could not.'
        );

        $byname = [];
        foreach ($result['criteria'] as $criterion) {
            $byname[$criterion['name']] = $criterion;
        }
        $this->assertArrayHasKey('Time Management', $byname, 'The unjudged criterion is still shown, with its reason.');
        $this->assertFalse($byname['Time Management']['assessed']);
        $this->assertSame(5, $byname['Time Management']['max_score']);
        $this->assertSame(
            'No target length was given, so I could not judge this.',
            $byname['Time Management']['feedback'],
            'The learner is told why the row is blank, in the coach\'s own words.'
        );

        $stored = $DB->get_records('local_ai_course_assistant_practice_scores');
        $this->assertCount(1, $stored);
        $row = reset($stored);
        $this->assertSame(
            4,
            (int) $row->overall_score,
            'round(16/4) = 4. Counting the unjudged row as a zero would store round(16/5) = 3, '
                . 'permanently, in a history the learner reads as their record.'
        );
        $meta = json_decode((string) $row->session_meta, true);
        $this->assertSame(4, $meta['assessed_count']);
        $this->assertSame(80, $meta['pct'], '16 of 20 is 80%. Counting the unjudged row gives 64%.');

        $clean = external_api::clean_returnvalue(score_speech::execute_returns(), $result);
        $cleanbyname = [];
        foreach ($clean['criteria'] as $criterion) {
            $cleanbyname[$criterion['name']] = $criterion;
        }
        $this->assertSame(4, $clean['assessedcount']);
        $this->assertFalse(
            $cleanbyname['Time Management']['assessed'],
            'assessed must survive the ajax return filter. An undeclared key is dropped in silence, '
                . 'and the drop only happens on the path a learner actually uses.'
        );
        $this->assertSame(5, $cleanbyname['Time Management']['max_score']);
    }

    /**
     * A learner whose camera produced nothing is told so, in words.
     *
     * If this breaks, the body-language section of the feedback is simply
     * absent. With no instructor to ask, silence where feedback should be reads
     * as a mark the learner cannot find or argue with. The second half matters
     * as much: the sentence must NOT appear for a recording that is not theirs,
     * or the learner is given an explanation about a camera that was never
     * involved in the attempt.
     *
     * @return void
     */
    public function test_a_learner_whose_camera_produced_nothing_is_told_why(): void {
        $this->assert_running_as_a_learner();
        $this->program_coach_reply();
        set_config('soapbox_gesture_vision', 1, 'local_ai_course_assistant');

        $sentence = get_string('soapbox:visual_not_assessed', 'local_ai_course_assistant');

        $ownrecid = $this->seed_recording((int) $this->learner->id);
        $own = score_speech::execute(
            $this->courseid,
            $this->transcript(),
            '',
            '',
            0,
            95,
            'informative',
            '',
            0,
            '',
            $ownrecid
        );
        $this->assertStringContainsString(
            $sentence,
            $own['overall'],
            'A video attempt with no usable frames must say why body language was left out.'
        );

        $peerrecid = $this->seed_recording((int) $this->peer->id);
        $peer = score_speech::execute(
            $this->courseid,
            $this->transcript(),
            '',
            '',
            0,
            95,
            'informative',
            '',
            0,
            '',
            $peerrecid
        );
        $this->assertStringNotContainsString(
            $sentence,
            $peer['overall'],
            'A recording the learner does not own tells us nothing about their camera, so it must '
                . 'not produce a sentence explaining their camera.'
        );
    }

    /**
     * A learner is never marked on body language the coach could not see.
     *
     * The rubric sent to the model holds only the five spoken criteria when
     * there is no visual evidence, but the model volunteered a body-language
     * score anyway. If this breaks, a learner who recorded audio, or whose
     * camera produced nothing, is scored on gestures invented from a transcript
     * -- and it is then written into their permanent practice history.
     *
     * @return void
     */
    public function test_a_learner_is_never_marked_on_body_language_nobody_saw(): void {
        $this->assert_running_as_a_learner();
        $this->program_coach_reply();

        $result = score_speech::execute($this->courseid, $this->transcript());

        $names = array_column($result['criteria'], 'name');
        $this->assertSame(
            array_column(rubric_manager::DEFAULT_SPEECH_CRITERIA, 'name'),
            $names,
            'Six criteria came back from the model and exactly the five that were in the rubric '
                . 'may reach the learner, in the rubric\'s own order.'
        );
        $this->assertNotContains('Body Language & Gestures', $names);
    }
}
