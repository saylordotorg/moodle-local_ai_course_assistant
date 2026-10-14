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

namespace local_ai_course_assistant\check;

use core\check\check;
use core\check\result;
use local_ai_course_assistant\course_key_guard;

/**
 * Status check: does any course hold a key that belongs to another provider? (v7.8.8)
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_keys extends check {
    /** @var int Most course names listed in the result. */
    public const LIST_LIMIT = 10;

    /**
     * Name shown in the status list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('check:coursekeys_name', 'local_ai_course_assistant');
    }

    /**
     * List the mismatched courses, or say there are none.
     *
     * @return result
     */
    public function get_result(): result {
        $bad = course_key_guard::mismatched_courses();
        if (!$bad) {
            return new result(result::OK, get_string('check:coursekeys_ok', 'local_ai_course_assistant'));
        }
        $names = array_map(
            // 'uses &larr; key belongs to': provider names only, so the line needs no translation.
            fn($b) => s($b['shortname'] ?: '?') . ' (' . s($b['provider']) . ' &larr; ' . s($b['owner']) . ')',
            array_slice($bad, 0, self::LIST_LIMIT)
        );
        $more = count($bad) > self::LIST_LIMIT ? ' ...' : '';
        return new result(result::WARNING, get_string('check:coursekeys_bad', 'local_ai_course_assistant', (object) [
            'count' => count($bad),
            'list' => implode(', ', $names) . $more,
        ]));
    }
}
