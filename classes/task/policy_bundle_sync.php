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

use local_ai_course_assistant\policy_bundle;

defined('MOODLE_INTERNAL') || die();

/**
 * v6.4.0 daily scheduled task: fetch the signed policy bundle from the
 * admin-configured URL and apply it when valid and newer (Ed25519
 * signature, settings allowlist, monotonic version — see
 * {@see \local_ai_course_assistant\policy_bundle}).
 *
 * Default schedule: 06:20 every day (set in db/tasks.php), so behavior
 * changes published the previous day are live before the teaching morning.
 *
 * Off by default; enable via the `policy_bundle_enabled` admin setting.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class policy_bundle_sync extends \core\task\scheduled_task {

    /**
     * Task name shown in the scheduled tasks admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:policy_bundle_sync', 'local_ai_course_assistant');
    }

    /**
     * Run the sync and log the outcome to cron output.
     */
    public function execute(): void {
        $result = policy_bundle::sync();
        mtrace('policy_bundle_sync: ' . $result['status'] . ' — ' . $result['detail']);
    }
}
