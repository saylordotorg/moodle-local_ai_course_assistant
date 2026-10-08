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
 * The provider answered HTTP 404: the configured model does not exist (or is retired).
 *
 * The same moodle_exception as before (errorcode chat:error and the same
 * advice in the debug info), now a type of its own so a caller can tell "no such
 * model" from every other failure without matching message text. Deliberately
 * not a provider_http_exception: the request healer only reads those, and a
 * 404 has nothing to heal.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_not_found_exception extends \moodle_exception {
    /**
     * Constructor.
     *
     * @param string $detail Admin-facing explanation, shown as the debug info.
     */
    public function __construct(string $detail) {
        parent::__construct('chat:error', 'local_ai_course_assistant', '', null, $detail);
    }
}
