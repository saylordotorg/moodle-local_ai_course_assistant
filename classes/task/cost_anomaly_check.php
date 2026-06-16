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

use local_ai_course_assistant\cost_anomaly_detector;

defined('MOODLE_INTERNAL') || die();

/**
 * v6.0.0 daily scheduled task: evaluate today's SOLA spend against the
 * rolling 7-day median and email the spend-notify recipients if the
 * ratio exceeds the configured threshold.
 *
 * Default schedule: 09:05 every day (set in db/tasks.php). Admins can
 * adjust via the standard Moodle scheduled-tasks UI.
 *
 * Off by default; enable via the `cost_anomaly_enabled` admin setting.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cost_anomaly_check extends \core\task\scheduled_task {

    public function get_name(): string {
        return \local_ai_course_assistant\branding::apply(get_string('task:cost_anomaly_check', 'local_ai_course_assistant'));
    }

    public function execute(): void {
        $eval = cost_anomaly_detector::evaluate();
        $status = $eval['status'] ?? 'unknown';
        mtrace(sprintf(
            'cost_anomaly_check: status=%s today=$%.4f median=$%.4f ratio=%.2fx threshold=%.2fx',
            $status,
            $eval['today_usd'] ?? 0,
            $eval['median_usd'] ?? 0,
            $eval['ratio'] ?? 0,
            $eval['multiplier'] ?? 0
        ));
        if ($status === 'anomaly') {
            $sent = cost_anomaly_detector::maybe_send_alert($eval);
            mtrace('cost_anomaly_check: alert email ' . ($sent ? 'sent' : 'skipped (already-notified flag set or no recipients)'));
        }
    }
}
