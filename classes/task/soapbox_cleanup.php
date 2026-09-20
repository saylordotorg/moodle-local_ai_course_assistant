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

use local_ai_course_assistant\soapbox_storage;

/**
 * Daily Soapbox recording cleanup (v6.8.18):
 *  1. Retention: delete the stored object for any recording past its
 *     expires_at, and mark the row 'deleted' (transcript + score are kept).
 *  2. Stored-attempts pruning: for each learner/assignment, keep only the
 *     newest N recordings (the assignment's stored_attempts), deleting older
 *     objects. A bucket lifecycle rule on the prefix is the backstop.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soapbox_cleanup extends \core\task\scheduled_task {
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
        global $DB;

        if (!soapbox_storage::is_configured()) {
            return;
        }
        $storage = new soapbox_storage();
        $now = time();

        // 1. Retention: objects past expiry (not already deleted).
        $expired = $DB->get_records_select(
            'local_ai_course_assistant_sbx_rec',
            'status <> :deleted AND expires_at > 0 AND expires_at <= :now',
            ['deleted' => 'deleted', 'now' => $now]
        );
        foreach ($expired as $rec) {
            $this->drop_object($storage, $rec);
        }

        // 2. Stored-attempts pruning: keep newest N per (assignid, userid).
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
            $extra = array_slice(array_values($recs), $keep);
            foreach ($extra as $rec) {
                $this->drop_object($storage, $rec);
            }
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
