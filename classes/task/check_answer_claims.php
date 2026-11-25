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

use local_ai_course_assistant\claim_checker;

/**
 * Adhoc task (8.4.0): check the factual claims of one delivered answer and append its verdict.
 *
 * Queued by claim_checker::queue_for() after answer_audit::record_answer(), from sse.php and send_message.
 * Custom data: {auditid}. Nothing else: the text is read from the audit row, the passages from the chunk table.
 *
 * Never throws: a failure inside the check is caught and the task finishes normally, so Moodle does not retry it
 * (a retry would bill the judge again). What the learner saw is never changed here.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_answer_claims extends \core\task\adhoc_task {

    /**
     * The task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:check_answer_claims', 'local_ai_course_assistant');
    }

    /**
     * Check the answer.
     *
     * @return void
     */
    public function execute() {
        $data = $this->get_custom_data();
        $auditid = is_object($data) ? (int) ($data->auditid ?? 0) : 0;
        if ($auditid <= 0) {
            return;
        }
        try {
            claim_checker::check($auditid);
        } catch (\Throwable $e) {
            // The exception class only: a message could quote the question or the answer.
            mtrace('check_answer_claims: check failed for audit row ' . $auditid . ': ' . get_class($e));
        }
    }
}
