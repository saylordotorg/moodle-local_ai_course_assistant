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
 * Learning-path aggregator (v5.9.0).
 *
 * One source of truth composing v5.8.0 {@see program_path} (program, ordering,
 * next course) with {@see objective_manager} (per-course objectives + mastery)
 * and {@see cross_course_mastery} (demonstrated-elsewhere). {@see readiness()}
 * is the cheap, current-course-only computation that feeds the page-load banner
 * and the conversational line; {@see full_path()} is the complete multi-course
 * model the path panel renders lazily.
 *
 * Advisory only; reads the learner's own allocation; every public method is
 * \Throwable-guarded so a missing program plugin / schema difference degrades to
 * an empty result rather than an error. Default off.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class learning_path {

    /** @var int Default mastery-readiness threshold, percent of tracked objectives mastered. */
    const DEFAULT_MASTERY_THRESHOLD = 80;

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
        if (!feature_flags::resolve('learning_path', $courseid)) {
            return false;
        }
        return $this->source->is_available();
    }

    /**
     * Whether the learner has met the bar for the current course, and what comes
     * next. Ready when the course is Moodle-complete OR at least the configured
     * percentage of tracked objectives are mastered, whichever first.
     *
     * @param int $userid
     * @param int $courseid
     * @return array{ready:bool, reason:?string, next_course:?array{courseid:int, name:string}}
     */
    public function readiness(int $userid, int $courseid): array {
        $none = ['ready' => false, 'reason' => null, 'next_course' => null];
        try {
            if (!$this->is_enabled_for_course($courseid)) {
                return $none;
            }
            $next = (new program_path($this->source))->next_courses_for($userid, $courseid)[0] ?? null;
            $nextcourse = $next ? ['courseid' => (int) $next['courseid'], 'name' => (string) $next['name']] : null;

            if ($this->course_complete($userid, $courseid)) {
                return ['ready' => true, 'reason' => 'completion', 'next_course' => $nextcourse];
            }
            if ($this->mastery_ready($userid, $courseid)) {
                return ['ready' => true, 'reason' => 'mastery', 'next_course' => $nextcourse];
            }
            return $none;
        } catch (\Throwable $e) {
            return $none;
        }
    }

    /**
     * The complete multi-course path model for the panel: the program, the
     * learner's position, every visible course (status + objectives + mastery),
     * the next course(s), and readiness. `null` when no usable program applies.
     *
     * @param int $userid
     * @param int $courseid
     * @return array|null
     */
    public function full_path(int $userid, int $courseid): ?array {
        try {
            if (!$this->is_enabled_for_course($courseid)) {
                return null;
            }
            $pp = new program_path($this->source);
            $membership = $pp->program_memberships($userid, $courseid)[0] ?? null;
            if ($membership === null) {
                return null;
            }
            $programid = (int) $membership['programid'];
            $courses = [];
            foreach ($membership['visible'] as $i => $c) {
                $cid = (int) $c['courseid'];
                $iscurrent = $cid === $courseid;
                $courses[] = [
                    'courseid' => $cid,
                    'name' => (string) $c['coursename'],
                    'position' => $i + 1,
                    'status' => $this->status_for($userid, $programid, $c, $iscurrent),
                    'ordered' => !empty($c['ordered']),
                    'is_current' => $iscurrent,
                    'objectives' => $this->objectives_for($userid, $cid),
                ];
            }
            return [
                'program' => ['programid' => $programid, 'name' => (string) $membership['name']],
                'position' => ['index' => (int) $membership['index'], 'total' => (int) $membership['total']],
                'courses' => $courses,
                'next_courses' => $pp->next_courses_for($userid, $courseid),
                'readiness' => $this->readiness($userid, $courseid),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Status of a course node: 'current' for the course the learner is on,
     * 'done' when the program item is completed, else 'upcoming'.
     *
     * @param int $userid
     * @param int $programid
     * @param array $c Program course item.
     * @param bool $iscurrent
     * @return string
     */
    private function status_for(int $userid, int $programid, array $c, bool $iscurrent): string {
        if ($iscurrent) {
            return 'current';
        }
        if (!empty($c['itemid']) && $this->source->is_item_completed($userid, $programid, (int) $c['itemid'])) {
            return 'done';
        }
        return 'upcoming';
    }

    /**
     * The course's objectives with the learner's mastery state. Empty where
     * objectives are not enabled/defined. Mastery vocabulary:
     * mastered | in_progress | not_started | demonstrated_elsewhere.
     *
     * @param int $userid
     * @param int $courseid
     * @return array<int, array{title:string, mastery:string}>
     */
    private function objectives_for(int $userid, int $courseid): array {
        if (!objective_manager::is_enabled_for_course($courseid)) {
            return [];
        }
        $summary = objective_manager::compute_course_summary($userid, $courseid);
        $out = [];
        foreach ($summary['objectives'] as $row) {
            $status = (string) $row['mastery']['status']; // not_started | learning | mastered.
            $mastery = $status === 'learning' ? 'in_progress' : $status;
            if ($mastery === 'not_started'
                    && $this->demonstrated_elsewhere($userid, (int) $row['objective']->id)) {
                $mastery = 'demonstrated_elsewhere';
            }
            $out[] = ['title' => (string) $row['objective']->title, 'mastery' => $mastery];
        }
        return $out;
    }

    /**
     * Whether the learner mastered a cross-course equivalent of this objective
     * elsewhere (v5.7.0 obj_links equivalency).
     *
     * @param int $userid
     * @param int $objectiveid
     * @return bool
     */
    private function demonstrated_elsewhere(int $userid, int $objectiveid): bool {
        foreach (cross_course_mastery::linked_objectives($objectiveid) as $link) {
            $m = objective_manager::compute_mastery($userid, (int) $link['objectiveid']);
            if (($m['status'] ?? '') === 'mastered') {
                return true;
            }
        }
        return false;
    }

    /**
     * At least the configured percentage of the course's tracked objectives are
     * mastered (and objectives are enabled with at least one defined).
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    private function mastery_ready(int $userid, int $courseid): bool {
        if (!objective_manager::is_enabled_for_course($courseid)) {
            return false;
        }
        $summary = objective_manager::compute_course_summary($userid, $courseid);
        $total = (int) ($summary['total'] ?? 0);
        if ($total < 1) {
            return false;
        }
        $raw = get_config('local_ai_course_assistant', 'learning_path_mastery_threshold');
        $threshold = ($raw === false || $raw === '') ? self::DEFAULT_MASTERY_THRESHOLD : (int) $raw;
        return ((int) $summary['mastered'] / $total) * 100 >= $threshold;
    }

    /**
     * Whether Moodle completion is enabled for the course and the learner has
     * completed it.
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    private function course_complete(int $userid, int $courseid): bool {
        $course = get_course($courseid);
        $info = new \completion_info($course);
        if (!$info->is_enabled()) {
            return false;
        }
        return $info->is_course_complete($userid);
    }
}
