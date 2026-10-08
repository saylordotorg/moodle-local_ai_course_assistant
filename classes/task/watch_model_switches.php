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

/**
 * Hourly: watch recent model switches and roll back the ones that got worse (v7.8.0).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class watch_model_switches extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:watch_model_switches', 'local_ai_course_assistant');
    }

    /**
     * Check every watched switch. Runs whatever the mode, so turning upgrades
     * off never leaves a fresh switch unwatched.
     *
     * @return void
     */
    public function execute() {
        foreach (\local_ai_course_assistant\autoupgrade\watcher::check_all() as $id => $outcome) {
            mtrace("watch_model_switches: switch {$id} {$outcome}");
        }
    }
}
