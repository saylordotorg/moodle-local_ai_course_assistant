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

use local_ai_course_assistant\feature_flags;
use local_ai_course_assistant\objective_manager;
use local_ai_course_assistant\cross_course_mastery;

defined('MOODLE_INTERNAL') || die();

/**
 * Forward learning-path awareness (v5.8.0).
 *
 * Pure logic over a {@see program_source_interface}: given a learner and the
 * course they are in, work out where the course leads next within the
 * learner's program (Saylor Degree or Learn certificate) and emit an advisory
 * prompt block. Asserts a "next course" only where the program data encodes
 * one (explicit prerequisite or an ordered set); any-order programs degrade to
 * membership + position. Advisory only — never changes enrolment or mastery,
 * and reads only the current learner's own allocation.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class program_path {

    /** @var program_source_interface */
    private $source;

    /**
     * @param program_source_interface|null $source Defaults to the live adapter.
     */
    public function __construct(?program_source_interface $source = null) {
        $this->source = $source ?? new db_program_source();
    }

    /**
     * Feature flag on for this course AND a program plugin is available.
     *
     * @param int $courseid
     * @return bool
     */
    public function is_enabled_for_course(int $courseid): bool {
        if (!feature_flags::resolve('program_path', $courseid)) {
            return false;
        }
        return $this->source->is_available();
    }

    /**
     * Forward-path context for this learner + course, or null if none applies.
     *
     * @param int $userid
     * @param int $courseid
     * @return null|array{
     *   program: array{programid:int, name:string},
     *   position: array{index:int, total:int},
     *   next_courses: array<int, array{courseid:int, name:string, reason:string}>,
     *   concept_links: array<int, array{objective:string, next_course:string, next_objective:string}>
     * }
     */
    public function forward_context(int $userid, int $courseid): ?array {
        if (!$this->is_enabled_for_course($courseid)) {
            return null;
        }
        // The learner's own allocations drive "your path". The course-membership
        // fallback is intentionally OFF by default (honesty rule).
        $candidates = $this->source->get_user_programs($userid);

        foreach ($candidates as $prog) {
            $programid = (int) $prog['programid'];
            $courses = $this->source->get_program_courses($programid);
            $visible = array_values(array_filter($courses, static fn($c) => !empty($c['visible'])));

            $index = null;
            foreach ($visible as $i => $c) {
                if ((int) $c['courseid'] === $courseid) {
                    $index = $i + 1;
                    break;
                }
            }
            if ($index === null) {
                continue;
            }
            $total = count($visible);
            $next = $this->compute_next_courses($userid, $programid, $courseid, $courses);
            if ($total < 2 && empty($next)) {
                continue;
            }
            return [
                'program' => ['programid' => $programid, 'name' => (string) $prog['name']],
                'position' => ['index' => $index, 'total' => $total],
                'next_courses' => $next,
                'concept_links' => $this->compute_concept_links($courseid, $next),
            ];
        }
        return null;
    }

    /**
     * Forward courses for the current course, prerequisite edges before ordered
     * sequence. Excludes hidden, current, and already-completed courses; cap 2.
     *
     * @param int $userid
     * @param int $programid
     * @param int $courseid
     * @param array $courses Program course items.
     * @return array<int, array{courseid:int, name:string, reason:string}>
     */
    private function compute_next_courses(int $userid, int $programid, int $courseid, array $courses): array {
        $byid = [];
        $myitemid = null;
        foreach ($courses as $c) {
            if (empty($c['visible'])) {
                continue;
            }
            $byid[(int) $c['itemid']] = $c;
            if ((int) $c['courseid'] === $courseid) {
                $myitemid = (int) $c['itemid'];
            }
        }
        if ($myitemid === null) {
            return [];
        }

        $out = [];

        // 1. Prerequisite edges: items that list my item as a prerequisite.
        $prereqs = $this->source->get_prerequisites($programid);
        foreach ($prereqs as $itemid => $deps) {
            if (in_array($myitemid, array_map('intval', $deps), true) && isset($byid[(int) $itemid])) {
                $out[(int) $itemid] = 'prerequisite';
            }
        }

        // 2. Ordered sequence: next visible item after mine, only if both are ordered.
        if (empty($out)) {
            $ordered = array_values(array_filter($courses, static fn($c) => !empty($c['visible'])));
            foreach ($ordered as $i => $c) {
                if ((int) $c['itemid'] === $myitemid && !empty($c['ordered']) && isset($ordered[$i + 1])) {
                    $nx = $ordered[$i + 1];
                    if (!empty($nx['ordered'])) {
                        $out[(int) $nx['itemid']] = 'sequence';
                    }
                }
            }
        }

        $result = [];
        foreach ($out as $itemid => $reason) {
            $row = $byid[(int) $itemid];
            if ((int) $row['courseid'] === $courseid) {
                continue;
            }
            if ($this->source->is_item_completed($userid, $programid, (int) $itemid)) {
                continue;
            }
            $result[] = [
                'courseid' => (int) $row['courseid'],
                'name' => (string) $row['coursename'],
                'reason' => $reason,
            ];
            if (count($result) >= 2) {
                break;
            }
        }
        return $result;
    }

    /**
     * Concept bridges: a current-course objective whose cross-course equivalent
     * (v5.7.0 obj_links) lands in one of the forward courses. Cap 2.
     *
     * @param int $courseid
     * @param array $next Forward course rows.
     * @return array<int, array{objective:string, next_course:string, next_objective:string}>
     */
    private function compute_concept_links(int $courseid, array $next): array {
        if (empty($next) || !objective_manager::is_enabled_for_course($courseid)) {
            return [];
        }
        $nextcourseids = [];
        foreach ($next as $n) {
            $nextcourseids[(int) $n['courseid']] = (string) $n['name'];
        }

        $links = [];
        foreach (objective_manager::list_for_course($courseid) as $obj) {
            foreach (cross_course_mastery::linked_objectives((int) $obj->id) as $lk) {
                $lcourseid = (int) $lk['courseid'];
                if (!isset($nextcourseids[$lcourseid])) {
                    continue;
                }
                $target = objective_manager::get((int) $lk['objectiveid']);
                if (!$target) {
                    continue;
                }
                $links[] = [
                    'objective' => (string) $obj->title,
                    'next_course' => $nextcourseids[$lcourseid],
                    'next_objective' => (string) $target->title,
                ];
                if (count($links) >= 2) {
                    return $links;
                }
            }
        }
        return $links;
    }

    /**
     * Render the advisory prompt block, or '' when no forward context applies.
     *
     * @param int $userid
     * @param int $courseid
     * @return string
     */
    public function build_prompt_injection(int $userid, int $courseid): string {
        $ctx = $this->forward_context($userid, $courseid);
        if ($ctx === null) {
            return '';
        }

        $program = $ctx['program']['name'];
        $idx = $ctx['position']['index'];
        $total = $ctx['position']['total'];

        $block = "\n\n### Where this course leads\n"
            . "This course is part of the \"{$program}\" path (course {$idx} of {$total}). ";

        if (!empty($ctx['next_courses'])) {
            $names = array_map(static fn($n) => '"' . $n['name'] . '"', $ctx['next_courses']);
            $verb = count($names) === 1 ? 'builds' : 'build';
            $block .= "What the learner builds here leads into " . implode(' and ', $names)
                . ", which {$verb} on it directly. ";
            if (!empty($ctx['concept_links'])) {
                $cl = $ctx['concept_links'][0];
                $block .= "Specifically, the \"{$cl['objective']}\" work here is the foundation for "
                    . "\"{$cl['next_objective']}\" in \"{$cl['next_course']}\". ";
            }
        }

        $block .= "When it fits naturally, help the learner see how today's work connects forward; "
            . "motivate with the path, but never sound like you're reading a database, and never "
            . "push them ahead before they're ready.";
        return $block;
    }
}
