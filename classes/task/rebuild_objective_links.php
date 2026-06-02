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

use local_ai_course_assistant\cross_course_mastery;

/**
 * Scheduled task: rebuild the cross-course objective link table (v5.7.0).
 *
 * Recomputes which learning objectives across different courses represent
 * the same competency (by competency ref or title similarity). The link
 * table feeds the cross-course mastery rollup that the system prompt and
 * the mastery-aware starter consume. Cheap to run; objective counts are
 * small. Runs daily so newly-seeded objectives get linked overnight.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rebuild_objective_links extends \core\task\scheduled_task {

    /**
     * Return the task's human-readable name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:rebuild_objective_links', 'local_ai_course_assistant');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        $counts = cross_course_mastery::rebuild_links();
        mtrace('local_ai_course_assistant: rebuilt cross-course objective links — '
            . "ref={$counts['ref']} title_exact={$counts['title_exact']} "
            . "title_fuzzy={$counts['title_fuzzy']} total={$counts['total']}");
    }
}
