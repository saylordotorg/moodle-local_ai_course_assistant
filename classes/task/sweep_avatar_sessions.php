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

use local_ai_course_assistant\talking_avatar_session_manager;

/**
 * Hourly task — closes any avatar session row left open longer than
 * {@see talking_avatar_session_manager::MAX_OPEN_SECONDS} (default 1h).
 *
 * Without this, a tab-close mid-session would leave `ended_at` null
 * forever and the row would never count toward the analytics dashboard
 * or contribute to the per-course cost view.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sweep_avatar_sessions extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task:sweep_avatar_sessions', 'local_ai_course_assistant');
    }

    public function execute(): void {
        $closed = talking_avatar_session_manager::sweep_stale();
        mtrace('  Avatar session sweep: closed ' . $closed . ' stale row(s).');
    }
}
