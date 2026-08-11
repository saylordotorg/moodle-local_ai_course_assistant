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

use local_ai_course_assistant\conversation_classifier;

/**
 * Adhoc task: classify one conversation turn for mastery tracking.
 *
 * Queued from sse.php after each assistant response lands so the
 * classifier call runs out of the request lifecycle (no added latency
 * for the student).
 *
 * Custom data: {userid, courseid, usermsgid, assistantmsgid}.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class classify_conversation_turn extends \core\task\adhoc_task {
    public function execute() {
        $data = $this->get_custom_data();
        if (!is_object($data)) {
            return;
        }
        $userid = (int) ($data->userid ?? 0);
        $courseid = (int) ($data->courseid ?? 0);
        $usermsgid = (int) ($data->usermsgid ?? 0);
        $assistantmsgid = (int) ($data->assistantmsgid ?? 0);
        if ($userid <= 0 || $courseid <= 0 || $usermsgid <= 0 || $assistantmsgid <= 0) {
            return;
        }
        try {
            conversation_classifier::classify_and_record(
                $userid,
                $courseid,
                $usermsgid,
                $assistantmsgid
            );
        } catch (\Throwable $e) {
            mtrace('classify_conversation_turn: ' . $e->getMessage());
        }
    }
}
