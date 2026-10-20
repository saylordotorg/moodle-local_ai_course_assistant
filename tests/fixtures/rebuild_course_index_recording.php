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

namespace local_ai_course_assistant\fixtures;

/**
 * The task with its reindex replaced by a recorder, so no embedding provider is needed.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rebuild_course_index_recording extends \local_ai_course_assistant\task\rebuild_course_index {
    /** @var int[] The courses reindexed. */
    public array $reindexed = [];

    /** @var \Throwable|null Thrown instead of reindexing. */
    public ?\Throwable $failure = null;

    /**
     * Record the course, or throw the configured failure.
     *
     * @param int $courseid
     * @return array
     */
    protected function reindex(int $courseid): array {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->reindexed[] = $courseid;
        return ['indexed' => 1, 'skipped' => 2, 'errors' => 0];
    }
}
