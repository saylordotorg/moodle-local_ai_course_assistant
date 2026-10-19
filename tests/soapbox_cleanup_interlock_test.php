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
 * The nightly Soapbox cleanup leaves alone what PresenterAI's importer holds.
 *
 * A held recording is neither deleted from storage nor changed in the database,
 * in BOTH loops: the retention loop (expired recordings) and the stored-attempts
 * pruning loop (recordings past the assignment's quota). Everything else is
 * retired as it always was, and a site with no PresenterAI behaves identically.
 *
 * The task is driven through two small seams. open_storage() replaces the
 * configured-credentials gate and the S3 client with a spy that records every
 * delete; held_ids() replaces the lookup in PresenterAI's table so these tests
 * run on a Moodle that has none. One test group goes through the real lookup and
 * skips itself where the map table does not exist.
 *
 * @package    local_ai_course_assistant
 * @covers     \local_ai_course_assistant\task\soapbox_cleanup
 */
final class soapbox_cleanup_interlock_test extends \advanced_testcase {
    /**
     * A storage spy: records every key it is asked to delete and says yes.
     *
     * @return soapbox_storage
     */
    private function spy_storage(): soapbox_storage {
        return new class extends soapbox_storage {
            /** @var string[] Keys that delete_object() was asked to delete. */
            public array $deleted = [];

            /**
             * Record the delete instead of calling S3.
             *
             * @param string $key
             * @return bool
             */
            public function delete_object(string $key): bool {
                $this->deleted[] = $key;
                return true;
            }
        };
    }

    /**
     * The task with its storage swapped for a spy and, optionally, a fixed hold.
     *
     * @param soapbox_storage|null $storage Null makes the task see storage as unconfigured.
     * @param int[]|null $held Ids the lookup reports as held; null uses the real lookup.
     * @param \Throwable|null $failure When given, the lookup throws it instead of answering.
     * @return task\soapbox_cleanup
     */
    private function task(?soapbox_storage $storage, ?array $held = [], ?\Throwable $failure = null): task\soapbox_cleanup {
        return new class ($storage, $held, $failure) extends task\soapbox_cleanup {
            /** @var soapbox_storage|null */
            private ?soapbox_storage $spy;
            /** @var int[]|null */
            private ?array $fixedheld;
            /** @var \Throwable|null */
            private ?\Throwable $failure;
            /** @var array[] Every list the task asked held_ids() about. */
            public array $asked = [];

            /**
             * Set the doubles.
             *
             * @param soapbox_storage|null $spy
             * @param int[]|null $fixedheld
             * @param \Throwable|null $failure
             */
            public function __construct(?soapbox_storage $spy, ?array $fixedheld, ?\Throwable $failure) {
                $this->spy = $spy;
                $this->fixedheld = $fixedheld;
                $this->failure = $failure;
            }

            /**
             * The spy, or null for "not configured".
             *
             * @return soapbox_storage|null
             */
            protected function open_storage(): ?soapbox_storage {
                return $this->spy;
            }

            /**
             * The fixed hold, or the real lookup when none was given.
             *
             * @param array $ids
             * @return array
             */
            protected function held_ids(array $ids): array {
                $this->asked[] = array_values($ids);
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                if ($this->fixedheld === null) {
                    return parent::held_ids($ids);
                }
                return array_values(array_intersect($ids, $this->fixedheld));
            }
        };
    }

    /**
     * One assignment (stored attempts as given) with a learner who owns it.
     *
     * @param int $storedattempts
     * @return array [assignment id, user id]
     */
    private function assignment(int $storedattempts = 2): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $assignid = (int) $DB->insert_record('local_ai_course_assistant_sbx_assign', (object) [
            'courseid' => $course->id,
            'name' => 'Presentation',
            'stored_attempts' => $storedattempts,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        return [$assignid, (int) $user->id];
    }

    /**
     * One recording row with all three object keys.
     *
     * @param int $assignid
     * @param int $userid
     * @param string $name Short unique label used in the three keys.
     * @param int $age Seconds old; also orders the recordings for pruning.
     * @param bool $expired Whether retention has already passed.
     * @param int $scoreid The score row this recording was scored into, 0 for none.
     * @return int The new recording id.
     */
    private function recording(
        int $assignid,
        int $userid,
        string $name,
        int $age,
        bool $expired,
        int $scoreid = 0
    ): int {
        global $DB;
        return (int) $DB->insert_record('local_ai_course_assistant_sbx_rec', (object) [
            'assignid' => $assignid,
            'userid' => $userid,
            'mode' => 'video',
            'storage_key' => "sbx/rec/$name.webm",
            'deck_key' => "sbx/deck/$name.pdf",
            'frames_key' => "sbx/frames/$name.jpg",
            'status' => 'scored',
            'scoreid' => $scoreid > 0 ? $scoreid : null,
            'expires_at' => $expired ? time() - DAYSECS : time() + (30 * DAYSECS),
            'timecreated' => time() - $age,
        ]);
    }

    /**
     * The audit rows the interlock wrote.
     *
     * @param string $action Audit action code.
     * @return \stdClass[]
     */
    private function interlock_audit_rows(string $action = 'soapbox_cleanup_interlock'): array {
        global $DB;
        return array_values($DB->get_records('local_ai_course_assistant_audit', ['action' => $action]));
    }

    /**
     * A score row whose session_meta carries a body-language observation.
     *
     * The meta also holds feedback the learner is told is kept (tips, pct and a
     * topic), so a test can tell "only the observation went" from "all of it".
     *
     * @param int $assignid The assignment the recording belongs to (for its course).
     * @param int $userid
     * @return int The score id.
     */
    private function score_with_observation(int $assignid, int $userid): int {
        global $DB;
        $courseid = (int) $DB->get_field('local_ai_course_assistant_sbx_assign', 'courseid', ['id' => $assignid]);
        return (int) $DB->insert_record('local_ai_course_assistant_practice_scores', (object) [
            'userid' => $userid,
            'courseid' => $courseid,
            'rubricid' => 0,
            'session_type' => rubric_manager::TYPE_SPEECH,
            'overall_score' => 4,
            'scores' => json_encode([['name' => 'Delivery', 'score' => 4, 'feedback' => 'Clear.']]),
            'ai_feedback' => 'Good pace.',
            'session_meta' => json_encode([
                'tips' => ['Slow down', 'Look up', 'Pause more'],
                'pct' => 80,
                'topic' => 'Climate',
                'visual_observation' => 'The speaker keeps both hands below the desk edge.',
            ]),
            'timecreated' => time(),
        ]);
    }

    /**
     * A score row's session_meta, decoded.
     *
     * @param int $scoreid
     * @return array
     */
    private function meta_of(int $scoreid): array {
        global $DB;
        return json_decode(
            (string) $DB->get_field('local_ai_course_assistant_practice_scores', 'session_meta', ['id' => $scoreid]),
            true
        );
    }

    /**
     * An expired recording the importer holds is not touched at all.
     *
     * @return void
     */
    public function test_an_expired_held_recording_is_left_untouched(): void {
        global $DB;
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment();
        $recid = $this->recording($assignid, $userid, 'held', 8 * DAYSECS, true);
        $before = $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $recid]);

        $storage = $this->spy_storage();
        $this->task($storage, [$recid])->execute();

        $this->assertEquals($before, $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $recid]));
        $after = $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $recid]);
        $this->assertSame('scored', $after->status);
        $this->assertSame('sbx/rec/held.webm', $after->storage_key);
        $this->assertSame('sbx/deck/held.pdf', $after->deck_key);
        $this->assertSame('sbx/frames/held.jpg', $after->frames_key);
        $this->assertSame([], $storage->deleted, 'no object of a held recording may be deleted');
    }

    /**
     * An expired recording nobody holds is retired exactly as before.
     *
     * @return void
     */
    public function test_an_expired_unmapped_recording_is_retired_as_before(): void {
        global $DB;
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment();
        $recid = $this->recording($assignid, $userid, 'free', 8 * DAYSECS, true);

        $storage = $this->spy_storage();
        $this->task($storage, [])->execute();

        $after = $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $recid]);
        $this->assertSame('deleted', $after->status);
        $this->assertNull($after->storage_key);
        $this->assertNull($after->deck_key);
        $this->assertNull($after->frames_key);
        $this->assertEqualsCanonicalizing(
            ['sbx/rec/free.webm', 'sbx/deck/free.pdf', 'sbx/frames/free.jpg'],
            $storage->deleted
        );
        $this->assertSame([], $this->interlock_audit_rows(), 'nothing held, so no interlock row');
    }

    /**
     * Held and free recordings in one run: only the free one goes.
     *
     * @return void
     */
    public function test_only_the_held_recording_among_several_is_spared(): void {
        global $DB;
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment(5);
        $held = $this->recording($assignid, $userid, 'a', 9 * DAYSECS, true);
        $freea = $this->recording($assignid, $userid, 'b', 8 * DAYSECS, true);
        $freeb = $this->recording($assignid, $userid, 'c', 7 * DAYSECS, true);

        $storage = $this->spy_storage();
        $this->task($storage, [$held])->execute();

        $this->assertSame('scored', $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $held]));
        $this->assertSame('deleted', $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $freea]));
        $this->assertSame('deleted', $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $freeb]));
        $this->assertNotContains('sbx/rec/a.webm', $storage->deleted);
        $this->assertContains('sbx/rec/b.webm', $storage->deleted);
        $this->assertContains('sbx/rec/c.webm', $storage->deleted);
    }

    /**
     * A held recording past the assignment's quota is not pruned.
     *
     * @return void
     */
    public function test_an_over_quota_held_recording_is_not_pruned(): void {
        global $DB;
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment(1);
        $newest = $this->recording($assignid, $userid, 'newest', 1 * DAYSECS, false);
        $heldold = $this->recording($assignid, $userid, 'heldold', 3 * DAYSECS, false);
        $freeold = $this->recording($assignid, $userid, 'freeold', 2 * DAYSECS, false);
        $beforeheld = $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $heldold]);

        $storage = $this->spy_storage();
        $this->task($storage, [$heldold])->execute();

        $this->assertEquals($beforeheld, $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $heldold]));
        $this->assertSame('scored', $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $newest]));
        $this->assertSame('deleted', $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $freeold]));
        $this->assertNotContains('sbx/rec/heldold.webm', $storage->deleted);
        $this->assertNotContains('sbx/frames/heldold.jpg', $storage->deleted);
        $this->assertContains('sbx/rec/freeold.webm', $storage->deleted);
    }

    /**
     * Pruning still retires the unmapped over-quota recordings as before.
     *
     * @return void
     */
    public function test_an_over_quota_unmapped_recording_is_pruned_as_before(): void {
        global $DB;
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment(1);
        $newest = $this->recording($assignid, $userid, 'keep', 1 * DAYSECS, false);
        $old = $this->recording($assignid, $userid, 'old', 2 * DAYSECS, false);

        $storage = $this->spy_storage();
        $this->task($storage, [])->execute();

        $this->assertSame('scored', $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $newest]));
        $this->assertSame('deleted', $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $old]));
        $this->assertEqualsCanonicalizing(
            ['sbx/rec/old.webm', 'sbx/deck/old.pdf', 'sbx/frames/old.jpg'],
            $storage->deleted
        );
    }

    /**
     * Each loop asks about all of its candidates at once, never one by one.
     *
     * @return void
     */
    public function test_each_loop_asks_about_every_candidate_in_one_call(): void {
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment(1);
        $e1 = $this->recording($assignid, $userid, 'e1', 20 * DAYSECS, true);
        $e2 = $this->recording($assignid, $userid, 'e2', 19 * DAYSECS, true);
        $e3 = $this->recording($assignid, $userid, 'e3', 18 * DAYSECS, true);
        [$assign2, $user2] = $this->assignment(1);
        $this->recording($assign2, $user2, 'k', 1 * DAYSECS, false);
        $x1 = $this->recording($assign2, $user2, 'x1', 3 * DAYSECS, false);
        $x2 = $this->recording($assign2, $user2, 'x2', 2 * DAYSECS, false);

        $task = $this->task($this->spy_storage(), []);
        $task->execute();

        $this->assertCount(2, $task->asked, 'one question for the retention loop, one for pruning');
        $retention = $task->asked[0];
        sort($retention);
        $this->assertSame([$e1, $e2, $e3], $retention);
        $pruning = $task->asked[1];
        sort($pruning);
        $this->assertSame([$x1, $x2], $pruning);
    }

    /**
     * One audit row per run, counting each held recording once.
     *
     * This recording is both expired and over quota, so both loops meet it. It
     * is one held recording, not two.
     *
     * @return void
     */
    public function test_one_audit_row_counts_each_held_recording_once_across_both_loops(): void {
        global $DB;
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment(1);
        $newest = $this->recording($assignid, $userid, 'new', 1 * DAYSECS, false);
        $both = $this->recording($assignid, $userid, 'both', 9 * DAYSECS, true);
        [$assign2, $user2] = $this->assignment(5);
        $expiredonly = $this->recording($assign2, $user2, 'exp', 8 * DAYSECS, true);

        $this->task($this->spy_storage(), [$both, $expiredonly])->execute();

        $rows = $this->interlock_audit_rows();
        $this->assertCount(1, $rows);
        $this->assertSame(['held' => 2], json_decode($rows[0]->details, true));
        $this->assertSame(0, (int) $rows[0]->userid);
        $this->assertSame(0, (int) $rows[0]->courseid);
        $this->assertSame('scored', $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $newest]));
    }

    /**
     * No audit row when nothing was held.
     *
     * @return void
     */
    public function test_no_audit_row_when_nothing_is_held(): void {
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment();
        $this->recording($assignid, $userid, 'free', 8 * DAYSECS, true);

        $this->task($this->spy_storage(), [])->execute();

        $this->assertSame([], $this->interlock_audit_rows());
    }

    /**
     * A held recording keeps its slot in the keep-newest-N window.
     *
     * Quota 1, the HELD recording is the newest, one older recording is not held.
     * The held one occupies the single slot, so the older one is over quota and
     * goes. A defect that filtered held rows out before slicing would see the
     * older one as the newest and wrongly keep it.
     *
     * @return void
     */
    public function test_a_held_recording_keeps_its_slot_in_the_keep_newest_window(): void {
        global $DB;
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment(1);
        $heldnewest = $this->recording($assignid, $userid, 'heldnewest', 1 * DAYSECS, false);
        $older = $this->recording($assignid, $userid, 'older', 2 * DAYSECS, false);

        $storage = $this->spy_storage();
        $this->task($storage, [$heldnewest])->execute();

        $this->assertSame('scored', $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $heldnewest]));
        $this->assertSame(
            'deleted',
            $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $older]),
            'the held recording still counts as the newest, so the older one is beyond the window'
        );
        $this->assertEqualsCanonicalizing(
            ['sbx/rec/older.webm', 'sbx/deck/older.pdf', 'sbx/frames/older.jpg'],
            $storage->deleted
        );
        $this->assertSame([], $this->interlock_audit_rows(), 'the held recording was never a candidate, so nothing was held');
    }

    /**
     * A held expired recording still loses its body-language prose, and only that.
     *
     * The object and the row are the importer's now and stay exactly as they
     * were; the observation lives in the score row, and v7.5.1 promises it goes
     * on the video's clock.
     *
     * @return void
     */
    public function test_a_held_expired_recording_still_forgets_the_visual_observation_and_only_that(): void {
        global $DB;
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment();
        $scoreid = $this->score_with_observation($assignid, $userid);
        $recid = $this->recording($assignid, $userid, 'held', 8 * DAYSECS, true, $scoreid);
        $before = $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $recid]);
        $scorebefore = $DB->get_record('local_ai_course_assistant_practice_scores', ['id' => $scoreid]);

        $storage = $this->spy_storage();
        $this->task($storage, [$recid])->execute();

        $meta = $this->meta_of($scoreid);
        $this->assertArrayNotHasKey('visual_observation', $meta);
        $this->assertSame(['Slow down', 'Look up', 'Pause more'], $meta['tips']);
        $this->assertSame(80, $meta['pct']);
        $this->assertSame('Climate', $meta['topic']);
        $scoreafter = $DB->get_record('local_ai_course_assistant_practice_scores', ['id' => $scoreid]);
        $this->assertSame($scorebefore->overall_score, $scoreafter->overall_score);
        $this->assertSame($scorebefore->ai_feedback, $scoreafter->ai_feedback);
        $this->assertSame($scorebefore->scores, $scoreafter->scores);
        $this->assertEquals($before, $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $recid]));
        $this->assertSame([], $storage->deleted, 'the held recording\'s objects are never deleted');
    }

    /**
     * The same for a held recording beyond the keep-newest-N window.
     *
     * @return void
     */
    public function test_a_held_over_quota_recording_still_forgets_the_visual_observation(): void {
        global $DB;
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment(1);
        $this->recording($assignid, $userid, 'newest', 1 * DAYSECS, false);
        $scoreid = $this->score_with_observation($assignid, $userid);
        $heldold = $this->recording($assignid, $userid, 'heldold', 3 * DAYSECS, false, $scoreid);
        $before = $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $heldold]);

        $storage = $this->spy_storage();
        $this->task($storage, [$heldold])->execute();

        $meta = $this->meta_of($scoreid);
        $this->assertArrayNotHasKey('visual_observation', $meta);
        $this->assertSame(['Slow down', 'Look up', 'Pause more'], $meta['tips']);
        $this->assertSame(80, $meta['pct']);
        $this->assertEquals($before, $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $heldold]));
        $this->assertSame([], $storage->deleted);
    }

    /**
     * A held recording the clock has not reached keeps its observation.
     *
     * Not expired and inside the window: it is not a candidate in either loop,
     * so nothing about it changes, held or not.
     *
     * @return void
     */
    public function test_a_held_recording_not_yet_due_keeps_its_visual_observation(): void {
        global $DB;
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment(2);
        $scoreid = $this->score_with_observation($assignid, $userid);
        $recid = $this->recording($assignid, $userid, 'held', 1 * DAYSECS, false, $scoreid);
        $before = $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $recid]);
        $metabefore = $this->meta_of($scoreid);

        $storage = $this->spy_storage();
        $this->task($storage, [$recid])->execute();

        $this->assertSame($metabefore, $this->meta_of($scoreid));
        $this->assertArrayHasKey('visual_observation', $this->meta_of($scoreid));
        $this->assertEquals($before, $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $recid]));
        $this->assertSame([], $storage->deleted);
    }

    /**
     * A recording nobody holds still loses its observation with its object.
     *
     * @return void
     */
    public function test_an_unheld_expired_recording_still_forgets_the_visual_observation(): void {
        global $DB;
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment();
        $scoreid = $this->score_with_observation($assignid, $userid);
        $recid = $this->recording($assignid, $userid, 'free', 8 * DAYSECS, true, $scoreid);

        $this->task($this->spy_storage(), [])->execute();

        $this->assertSame('deleted', $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $recid]));
        $this->assertArrayNotHasKey('visual_observation', $this->meta_of($scoreid));
        $this->assertSame(80, $this->meta_of($scoreid)['pct']);
    }

    /**
     * A failing lookup fails the task, visibly, and nothing is deleted.
     *
     * The exception must still propagate, so the scheduled task fails. One audit
     * row names only the exception class: the message here carries a fake SQL
     * fragment and must not reach the audit log.
     *
     * @return void
     */
    public function test_a_failing_lookup_fails_the_task_with_one_audit_row_and_deletes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment();
        $recid = $this->recording($assignid, $userid, 'free', 8 * DAYSECS, true);
        $before = $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $recid]);

        $storage = $this->spy_storage();
        $task = $this->task($storage, [], new \RuntimeException('SELECT secret FROM {map} WHERE token = abc123'));
        try {
            $task->execute();
            $this->fail('the lookup failure must propagate so the scheduled task fails');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('abc123', $e->getMessage(), 'the original exception is rethrown intact');
        }

        $rows = $this->interlock_audit_rows('soapbox_cleanup_interlock_failed');
        $this->assertCount(1, $rows);
        $this->assertSame(['error_class' => 'RuntimeException'], json_decode($rows[0]->details, true));
        $this->assertStringNotContainsString('abc123', $rows[0]->details);
        $this->assertStringNotContainsString('SELECT', $rows[0]->details);
        $this->assertEquals($before, $DB->get_record('local_ai_course_assistant_sbx_rec', ['id' => $recid]));
        $this->assertSame([], $storage->deleted, 'when the importer\'s ownership cannot be read, nothing is deleted');
        $this->assertSame([], $this->interlock_audit_rows(), 'no held-count row on a failed run');
    }

    /**
     * The same when the failure is in the pruning loop's lookup.
     *
     * @return void
     */
    public function test_a_failing_lookup_in_the_pruning_loop_also_fails_the_task_visibly(): void {
        global $DB;
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment(1);
        $this->recording($assignid, $userid, 'newest', 1 * DAYSECS, false);
        $old = $this->recording($assignid, $userid, 'old', 2 * DAYSECS, false);

        $storage = $this->spy_storage();
        $task = $this->task($storage, [], new \RuntimeException('boom'));
        try {
            $task->execute();
            $this->fail('the lookup failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertCount(1, $this->interlock_audit_rows('soapbox_cleanup_interlock_failed'));
        $this->assertSame('scored', $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $old]));
        $this->assertSame([], $storage->deleted);
    }

    /**
     * Unconfigured storage still means the task does nothing at all.
     *
     * @return void
     */
    public function test_unconfigured_storage_does_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment();
        $recid = $this->recording($assignid, $userid, 'free', 8 * DAYSECS, true);

        $task = $this->task(null, []);
        $task->execute();

        $this->assertSame('scored', $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $recid]));
        $this->assertSame([], $task->asked);
        $this->assertSame([], $this->interlock_audit_rows());
    }

    /**
     * With the production lookup, an unmapped recording is retired as before.
     *
     * This runs whether or not PresenterAI's table exists, so on a Moodle
     * without it, it is the proof that a site without PresenterAI is unchanged.
     *
     * @return void
     */
    public function test_the_real_lookup_leaves_an_unmapped_recording_to_be_retired(): void {
        global $DB;
        $this->resetAfterTest();
        [$assignid, $userid] = $this->assignment();
        $recid = $this->recording($assignid, $userid, 'free', 8 * DAYSECS, true);

        $storage = $this->spy_storage();
        $this->task($storage, null)->execute();

        $this->assertSame('deleted', $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $recid]));
        $this->assertCount(3, $storage->deleted);
        $this->assertSame([], $this->interlock_audit_rows());
        $this->assertSame([], $this->interlock_audit_rows('soapbox_cleanup_interlock_failed'), 'no failure row either');
    }

    /**
     * With the production lookup and a real map row, the recording is held.
     *
     * @return void
     */
    public function test_the_real_lookup_holds_a_recording_the_map_names(): void {
        global $DB;
        $this->resetAfterTest();
        if (!$DB->get_manager()->table_exists('presenterai_migration_map')) {
            $this->markTestSkipped('PresenterAI is not installed here, so there is no map table to read.');
        }
        [$assignid, $userid] = $this->assignment();
        $held = $this->recording($assignid, $userid, 'held', 8 * DAYSECS, true);
        $foreign = $this->recording($assignid, $userid, 'foreign', 7 * DAYSECS, true);
        foreach ([[$held, presenterai_interlock::site_id()], [$foreign, sha1('https://other.example|elsewhere')]] as $m) {
            $DB->insert_record('presenterai_migration_map', (object) [
                'runid' => 'testrun',
                'sourcetable' => presenterai_interlock::SOURCE_TABLE,
                'sourceid' => $m[0],
                'sourcesiteid' => $m[1],
                'state' => 'planned',
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        $storage = $this->spy_storage();
        $this->task($storage, null)->execute();

        $this->assertSame('scored', $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $held]));
        $this->assertSame(
            'deleted',
            $DB->get_field('local_ai_course_assistant_sbx_rec', 'status', ['id' => $foreign]),
            'a mapping made for another site holds nothing here'
        );
        $this->assertNotContains('sbx/rec/held.webm', $storage->deleted);
        $this->assertContains('sbx/rec/foreign.webm', $storage->deleted);
        $rows = $this->interlock_audit_rows();
        $this->assertCount(1, $rows);
        $this->assertSame(['held' => 1], json_decode($rows[0]->details, true));
    }
}
