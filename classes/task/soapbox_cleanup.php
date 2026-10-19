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

namespace local_ai_course_assistant\task;

use local_ai_course_assistant\audit_logger;
use local_ai_course_assistant\presenterai_interlock;
use local_ai_course_assistant\soapbox_storage;

/**
 * Daily Soapbox recording cleanup (v6.8.18):
 *  1. Retention: delete the stored object for any recording past its
 *     expires_at, and mark the row 'deleted' (transcript + score are kept).
 *  2. Stored-attempts pruning: for each learner/assignment, keep only the
 *     newest N recordings (the assignment's stored_attempts), deleting older
 *     objects. A bucket lifecycle rule on the prefix is the backstop.
 *
 * Neither step deletes the object of, or retires the row of, a recording that
 * PresenterAI's importer holds (v7.13.0): the importer re-points its own row at
 * the same S3 key, so doing either here would break the migrated attempt. See
 * presenterai_interlock for how a recording comes to be held. The one thing a
 * held recording still gets is the body-language prose forgotten, on the same
 * clock as ever (see drop_unheld()), because that lives in the score row, not
 * in the object the importer now uses.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_cleanup extends \core\task\scheduled_task {
    /**
     * @var bool Declares that this cleanup skips recordings PresenterAI's importer
     * holds. PresenterAI 0.5.0's Soapbox importer checks for this constant with
     * defined() and refuses to run without it, so an older SOLA, whose cleanup
     * would delete a migrated recording's object, is never paired with the
     * importer. Do not remove it or turn it false while the interlock stands.
     */
    public const PRESENTERAI_INTERLOCK = true;

    /**
     * @return string
     */
    public function get_name() {
        return get_string('task:soapbox_cleanup', 'local_ai_course_assistant');
    }

    /**
     * Run retention deletion + stored-attempts pruning.
     */
    public function execute() {
        $storage = $this->open_storage();
        if ($storage === null) {
            return;
        }

        // Recordings the importer holds, from either step. A recording that is
        // both expired and over quota meets both steps, so count ids, not hits.
        $held = $this->retire_expired($storage, time());
        $held += $this->prune_over_quota($storage);

        if ($held) {
            audit_logger::log('soapbox_cleanup_interlock', 0, 0, ['held' => count($held)]);
        }
    }

    /**
     * The object store to clean, or null when storage is not configured.
     *
     * Protected so a test can supply a recording double and skip the credential
     * check; production behavior is unchanged.
     *
     * @return soapbox_storage|null
     */
    protected function open_storage(): ?soapbox_storage {
        if (!soapbox_storage::is_configured()) {
            return null;
        }
        return new soapbox_storage();
    }

    /**
     * Which of the given recording ids PresenterAI's importer holds.
     *
     * Protected so a test can name the held ids without PresenterAI's table.
     *
     * @param array $ids Soapbox recording ids.
     * @return array The held ids, as ints.
     */
    protected function held_ids(array $ids): array {
        return presenterai_interlock::held_ids($ids);
    }

    /**
     * Step 1, retention: retire every recording past its expiry, except held ones.
     *
     * @param soapbox_storage $storage
     * @param int $now Unix time.
     * @return array The held recordings left alone, keyed by id (value = id).
     */
    private function retire_expired(soapbox_storage $storage, int $now): array {
        global $DB;

        $expired = $DB->get_records_select(
            'local_ai_course_assistant_sbx_rec',
            'status <> :deleted AND expires_at > 0 AND expires_at <= :now',
            ['deleted' => 'deleted', 'now' => $now]
        );
        return $this->drop_unheld($storage, $expired);
    }

    /**
     * Step 2, stored-attempts pruning: keep newest N per (assignid, userid).
     *
     * @param soapbox_storage $storage
     * @return array The held recordings left alone, keyed by id (value = id).
     */
    private function prune_over_quota(soapbox_storage $storage): array {
        global $DB;

        // get_records_sql keys the result by the FIRST column. Two learners on
        // the same assignment share assignid, so later rows silently overwrote
        // earlier ones and pruning ran for exactly one learner per assignment --
        // everyone else's over-quota recordings (and their storage objects)
        // survived until retention expiry. A synthetic unique key fixes the
        // collapse; sql_concat handles the int casts across DB drivers, which
        // matters because the local suite is MySQL-only.
        $pairs = $DB->get_records_sql(
            "SELECT " . $DB->sql_concat('assignid', "'-'", 'userid') . " AS pairkey,
                    assignid, userid
               FROM {local_ai_course_assistant_sbx_rec}
              WHERE status <> :deleted
           GROUP BY assignid, userid",
            ['deleted' => 'deleted']
        );
        // Known per-pair read on this nightly cleanup: one assignment row and
        // one attempt list per (assignment, learner) pair with stored
        // recordings. Batching the assignment lookup is straightforward, but is
        // left alone until there is seeded Soapbox recording data to prove the
        // rewrite prunes exactly the same rows.
        $overquota = [];
        foreach ($pairs as $p) {
            $assign = $DB->get_record(
                'local_ai_course_assistant_sbx_assign',
                ['id' => $p->assignid],
                'id, stored_attempts'
            );
            if (!$assign) {
                continue;
            }
            $keep = max(1, (int) $assign->stored_attempts);
            $recs = $DB->get_records_select(
                'local_ai_course_assistant_sbx_rec',
                'assignid = :a AND userid = :u AND status <> :deleted',
                ['a' => $p->assignid, 'u' => $p->userid, 'deleted' => 'deleted'],
                'timecreated DESC, id DESC'
            );
            foreach (array_slice(array_values($recs), $keep) as $rec) {
                $overquota[$rec->id] = $rec;
            }
        }
        return $this->drop_unheld($storage, $overquota);
    }

    /**
     * Retire the given recordings except the ones PresenterAI's importer holds.
     *
     * The held lookup is one call for the whole list, not one per recording. A
     * held recording keeps its row and every object key exactly as they were and
     * its object is never deleted. It still has its body-language observation
     * forgotten, because v7.5.1 promises that prose goes on the same clock as the
     * video, and the recordings in $recs are exactly the ones that clock has
     * reached (expired, or beyond the assignment's keep-newest-N window).
     * forget_visual_observation() edits only the score row's session_meta.
     *
     * @param soapbox_storage $storage
     * @param array $recs Recording rows keyed by id.
     * @return array The held recordings left alone, keyed by id (value = id).
     */
    private function drop_unheld(soapbox_storage $storage, array $recs): array {
        if (!$recs) {
            return [];
        }
        $held = array_flip($this->lookup_held(array_keys($recs)));
        $skipped = [];
        foreach ($recs as $rec) {
            if (isset($held[(int) $rec->id])) {
                $skipped[(int) $rec->id] = (int) $rec->id;
                $this->forget_visual_observation($rec);
                continue;
            }
            $this->drop_object($storage, $rec);
        }
        return $skipped;
    }

    /**
     * Ask which of these recordings are held, failing closed but visibly.
     *
     * If the lookup throws, the task must not guess and delete: the exception
     * goes on up, so the task fails and nothing in this loop is retired. Before
     * it does, one audit row names the exception class (never its message, which
     * can carry SQL) so the failure shows up in the audit log and not only in the
     * scheduled-task log.
     *
     * @param array $ids Soapbox recording ids.
     * @return array The held ids, as ints.
     * @throws \Throwable Whatever the lookup threw, after the audit row.
     */
    private function lookup_held(array $ids): array {
        try {
            return $this->held_ids($ids);
        } catch (\Throwable $e) {
            audit_logger::log('soapbox_cleanup_interlock_failed', 0, 0, ['error_class' => get_class($e)]);
            throw $e;
        }
    }

    /**
     * Delete one recording's object and mark the row deleted. Leaves the row
     * untouched (for retry next run) if the object delete fails.
     *
     * @param soapbox_storage $storage
     * @param \stdClass $rec
     */
    private function drop_object(soapbox_storage $storage, \stdClass $rec): void {
        global $DB;
        if (!empty($rec->storage_key)) {
            if (!$storage->delete_object($rec->storage_key)) {
                return;
            }
        }
        // Also drop the slide deck, if any (best-effort; the bucket lifecycle
        // rule on the prefix is the backstop for a failed delete).
        if (!empty($rec->deck_key)) {
            $storage->delete_object($rec->deck_key);
        }
        // v7.5.1: and the still-frame sheet. These are frames of the learner's
        // face, so they must not outlive the recording they came from -- the
        // privacy notice promises they go on the same clock.
        if (!empty($rec->frames_key)) {
            $storage->delete_object($rec->frames_key);
        }
        $DB->update_record('local_ai_course_assistant_sbx_rec', (object) [
            'id' => $rec->id,
            'status' => 'deleted',
            'storage_key' => null,
            'deck_key' => null,
            'frames_key' => null,
        ]);
        $this->forget_visual_observation($rec);
    }

    /**
     * Drop the body-language observation when the video it describes is deleted.
     *
     * The observation is prose a vision model wrote about a named learner's
     * body: where their hands were, whether they looked at the lens, how they
     * were standing. It lived in the score row's session_meta, and the score row
     * deliberately outlives the recording, so it survived the video, the frames
     * it was derived from, and the retention window, indefinitely.
     *
     * Nothing read it. v7.5.1 decided not to render it to learners, because it
     * is unreviewed model output about a person's appearance and nothing
     * downstream enforces the "do not describe appearance" instruction the
     * prompt asks for. Data nobody reads, describing a body, kept forever, is
     * the weakest possible position to be in.
     *
     * It also made the privacy notice untrue. That notice promises the recording
     * goes "together with the still frames used for body-language feedback", so
     * a learner reasonably concludes nothing visual survives. Now nothing does.
     *
     * The rest of the score is untouched on purpose: the per-criterion comments,
     * the tips and the totals ARE the learner's feedback, the notice says they
     * are kept, and they are what the learner is told to download before the
     * video goes.
     *
     * @param \stdClass $rec The recording row being retired.
     * @return void
     */
    private function forget_visual_observation(\stdClass $rec): void {
        global $DB;

        $scoreid = (int) ($rec->scoreid ?? 0);
        if ($scoreid <= 0) {
            return;
        }
        try {
            $score = $DB->get_record(
                'local_ai_course_assistant_practice_scores',
                ['id' => $scoreid],
                'id, session_meta'
            );
            if (!$score || empty($score->session_meta)) {
                return;
            }
            $meta = json_decode((string) $score->session_meta, true);
            if (!is_array($meta) || !array_key_exists('visual_observation', $meta)) {
                return;
            }
            unset($meta['visual_observation']);
            $DB->update_record('local_ai_course_assistant_practice_scores', (object) [
                'id' => $score->id,
                'session_meta' => json_encode($meta),
            ]);
        } catch (\Throwable $e) {
            // Best effort, consistent with the rest of this task: a score row
            // that cannot be rewritten must not stop the object deletion that
            // has already happened above.
            return;
        }
    }
}
