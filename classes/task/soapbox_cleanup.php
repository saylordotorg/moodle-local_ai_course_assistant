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
            ['deleted' => 'deleted', 'now' => $now]);
        foreach ($expired as $rec) {
            $this->drop_object($storage, $rec);
        }

        // 2. Stored-attempts pruning: keep newest N per (assignid, userid).
        $pairs = $DB->get_records_sql(
            "SELECT DISTINCT assignid, userid
               FROM {local_ai_course_assistant_sbx_rec}
              WHERE status <> :deleted",
            ['deleted' => 'deleted']);
        foreach ($pairs as $p) {
            $assign = $DB->get_record('local_ai_course_assistant_sbx_assign',
                ['id' => $p->assignid], 'id, stored_attempts');
            if (!$assign) {
                continue;
            }
            $keep = max(1, (int) $assign->stored_attempts);
            $recs = $DB->get_records_select(
                'local_ai_course_assistant_sbx_rec',
                'assignid = :a AND userid = :u AND status <> :deleted',
                ['a' => $p->assignid, 'u' => $p->userid, 'deleted' => 'deleted'],
                'timecreated DESC');
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
        $DB->update_record('local_ai_course_assistant_sbx_rec', (object) [
            'id' => $rec->id,
            'status' => 'deleted',
            'storage_key' => null,
            'deck_key' => null,
        ]);
    }
}
