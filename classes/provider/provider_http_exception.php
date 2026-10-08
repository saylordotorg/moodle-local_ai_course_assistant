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
 * A provider rejected a request with an HTTP error that is not handled elsewhere.
 *
 * The same moodle_exception as before (errorcode chat:error, debuginfo
 * "HTTP {status}: {body}"), so every existing catch and every audit line reads
 * exactly as it did. What it adds is the status and the body as fields, so the
 * request healer can read WHY a 400 happened without parsing a debug string.
 * The body is already redacted by the time it gets here.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_http_exception extends \moodle_exception {
    /** @var int HTTP status the provider returned. */
    public int $status;

    /** @var string Provider error body, redacted and bounded. */
    public string $body;

    /**
     * Constructor.
     *
     * @param int $status
     * @param string $body
     */
    public function __construct(int $status, string $body) {
        $this->status = $status;
        $this->body = $body;
        parent::__construct('chat:error', 'local_ai_course_assistant', '', null, "HTTP {$status}: {$body}");
    }
}
