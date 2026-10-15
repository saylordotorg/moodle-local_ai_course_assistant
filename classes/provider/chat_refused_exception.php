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

namespace local_ai_course_assistant\provider;

/**
 * A chat turn was refused on purpose, before any provider was asked (v7.8.9).
 *
 * Thrown by the guards that stop a turn deliberately: the emergency pause, the quiz lock, the request rate
 * limit, and a spend cap with no failover provider. It is the same moodle_exception as before (error code
 * 'error', the learner-facing message unchanged), so every existing catch reads it exactly as it did. What
 * it adds is a way to tell it from a failure: the chat records such a turn as 'blocked' and audits it as
 * chat_blocked, while ANY other exception (a provider error, a database or coding error in building the
 * prompt) is a failed reply.
 *
 * v7.8.8 told the two apart by guessing from the error code and whether a provider object existed yet, and
 * a server fault before the provider was built (a database error while assembling the context) was filed
 * as a refusal, so the failed-reply alert could not see a site-wide outage of that kind.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_refused_exception extends \moodle_exception {
    /**
     * Constructor.
     *
     * @param string $message What the learner is shown.
     */
    public function __construct(string $message) {
        parent::__construct('error', 'local_ai_course_assistant', '', $message);
    }
}
