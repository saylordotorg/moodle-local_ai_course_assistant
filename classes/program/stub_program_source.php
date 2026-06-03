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

namespace local_ai_course_assistant\program;

defined('MOODLE_INTERNAL') || die();

/**
 * Array-backed program source for unit tests (v5.8.0).
 *
 * Lets a test express a program shape ("a 5-course ordered program where the
 * learner is on course 2, and course 3 lists course 2 as a prerequisite")
 * without any program plugin or database tables. The real reads happen in
 * {@see db_program_source}, which is verified against live tables on dev.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stub_program_source implements program_source_interface {

    /** @var bool */
    private bool $available;

    /** @var array<int, array<int, array{programid: int, name: string}>> Programs per userid. */
    private array $userprograms;

    /** @var array<int, array<int, array{programid: int, name: string}>> Programs per courseid. */
    private array $courseprograms;

    /** @var array<int, array> Course-item lists keyed by programid. */
    private array $programcourses;

    /** @var array<int, array<int, int[]>> Prerequisite maps keyed by programid. */
    private array $prerequisites;

    /** @var array<string, bool> Completion flags keyed by "userid:programid:itemid". */
    private array $completed;

    /**
     * @param array $fixture {
     *   available?: bool,
     *   user_programs?: array<int, array<int, array{programid:int,name:string}>>,  // by userid
     *   course_programs?: array<int, array<int, array{programid:int,name:string}>>, // by courseid
     *   program_courses?: array<int, array>,            // by programid
     *   prerequisites?: array<int, array<int, int[]>>,  // by programid
     *   completed?: array<string, bool>                 // "userid:programid:itemid" => true
     * }
     */
    public function __construct(array $fixture = []) {
        $this->available = $fixture['available'] ?? true;
        $this->userprograms = $fixture['user_programs'] ?? [];
        $this->courseprograms = $fixture['course_programs'] ?? [];
        $this->programcourses = $fixture['program_courses'] ?? [];
        $this->prerequisites = $fixture['prerequisites'] ?? [];
        $this->completed = $fixture['completed'] ?? [];
    }

    public function is_available(): bool {
        return $this->available;
    }

    public function get_user_programs(int $userid): array {
        return $this->userprograms[$userid] ?? [];
    }

    public function get_programs_for_course(int $courseid): array {
        return $this->courseprograms[$courseid] ?? [];
    }

    public function get_program_courses(int $programid): array {
        return $this->programcourses[$programid] ?? [];
    }

    public function get_prerequisites(int $programid): array {
        return $this->prerequisites[$programid] ?? [];
    }

    public function is_item_completed(int $userid, int $programid, int $itemid): bool {
        return !empty($this->completed["$userid:$programid:$itemid"]);
    }
}
