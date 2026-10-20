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

use local_ai_course_assistant\content_indexer;

/**
 * Ad-hoc task: reindex one course after upgrade step 2026101903 deleted its course-level chunks (#341).
 *
 * The nightly index visits only visible courses with an active enrolment, so a shared supplemental course, or one
 * whose enrolments have lapsed, would otherwise stay without its course and section summaries until an
 * administrator pressed Reindex. Custom data: {courseid}.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rebuild_course_index extends \core\task\adhoc_task {
    /**
     * Task name for the queue admin screens.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:rebuild_course_index', 'local_ai_course_assistant');
    }

    /**
     * Reindex one course; a seam so the guards and the retry rule can be tested without an embedding provider.
     *
     * @param int $courseid
     * @return array ['indexed' => int, 'skipped' => int, 'errors' => int]
     */
    protected function reindex(int $courseid): array {
        return content_indexer::index_course($courseid);
    }

    /**
     * Reindex the course named in the custom data.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $data = (array) $this->get_custom_data();
        $courseid = (int) ($data['courseid'] ?? 0);
        if ($courseid <= 1) {
            mtrace('  course index rebuild: no course in the custom data; nothing to do.');
            return;
        }
        if (!get_config('local_ai_course_assistant', 'rag_enabled')) {
            mtrace("  course index rebuild: RAG is disabled; skipping course {$courseid}.");
            return;
        }
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            // A deleted course is not a failure; throwing would make Moodle retry it forever.
            mtrace("  course index rebuild: course {$courseid} no longer exists; skipping.");
            return;
        }

        \core_php_time_limit::raise(1800);
        try {
            $stats = $this->reindex($courseid);
        } catch (\Throwable $e) {
            // Retry while the backoff is young (it doubles from 60 s, so 960 s is the fifth run), then stop: a
            // permanently unconfigured embedding provider must not loop forever, and the next Reindex or the
            // nightly index rebuilds the course anyway.
            if ($this->get_fail_delay() < 960) {
                throw $e;
            }
            mtrace("  course index rebuild: course {$courseid} failed again and was dropped: " . $e->getMessage());
            return;
        }
        mtrace("  course index rebuild: course {$courseid} indexed={$stats['indexed']}, skipped={$stats['skipped']}, "
            . "errors={$stats['errors']}");
    }
}
