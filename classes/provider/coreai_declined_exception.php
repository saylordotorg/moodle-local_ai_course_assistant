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
 * The Moodle AI provider declined a turn for this learner or this course, before core_ai was asked.
 *
 * Thrown by coreai_provider when Moodle's own AI rules say this request must not go through core_ai:
 * the learner hasn't accepted the site's AI usage policy, AI is turned off for the course, or core_ai's
 * per-user hourly limit is used up. None of these say anything about the health of the core_ai provider,
 * so the failover chain passes the turn to the next provider without counting it against the core_ai
 * circuit (one learner who never accepted the policy must not divert every course for 15 minutes), and
 * the chat records it as 'blocked', like the other deliberate refusals.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coreai_declined_exception extends chat_refused_exception {
    /** @var string The learner hasn't accepted Moodle's AI usage policy. */
    public const POLICY = 'policy';

    /** @var string Moodle's AI tools, or the generate_text action, are off for this course or activity. */
    public const CONTEXT = 'context';

    /** @var string core_ai's per-user rate limit is used up for this hour. */
    public const USER_LIMIT = 'userlimit';

    /** @var string One of the constants above. */
    public string $reason;

    /**
     * Constructor.
     *
     * @param string $reason One of POLICY, CONTEXT, USER_LIMIT.
     * @param string $message What the learner is shown.
     */
    public function __construct(string $reason, string $message) {
        $this->reason = $reason;
        parent::__construct($message);
    }
}
