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
