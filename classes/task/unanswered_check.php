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

use local_ai_course_assistant\unanswered_monitor;

defined('MOODLE_INTERNAL') || die();

/**
 * v6.9.7 scheduled task: alert when learners are asking SOLA questions and
 * not getting answers.
 *
 * Runs every two hours (set in db/tasks.php) rather than daily, because the
 * failure it catches is a live outage rather than a slow trend — a day's
 * latency would have made little difference to the August 2026 incident this
 * was written for, but an operator who finds out at 09:05 tomorrow has still
 * lost a day of learner traffic.
 *
 * Off by default; enable via the `unanswered_check_enabled` admin setting.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unanswered_check extends \core\task\scheduled_task {

    /**
     * Name shown in the scheduled-tasks UI.
     *
     * @return string
     */
    public function get_name(): string {
        return \local_ai_course_assistant\branding::apply(
            get_string('task:unanswered_check', 'local_ai_course_assistant'));
    }

    /**
     * Evaluate the window and alert if any course is failing.
     *
     * @return void
     */
    public function execute(): void {
        if (!get_config('local_ai_course_assistant', 'unanswered_check_enabled')) {
            mtrace('unanswered_check: disabled (unanswered_check_enabled is off)');
            return;
        }

        $eval = unanswered_monitor::evaluate();
        mtrace(sprintf(
            'unanswered_check: status=%s window=%dh checked=%d failing=%d threshold=%.0f%% min_questions=%d',
            $eval['status'] ?? 'unknown',
            $eval['window_hours'] ?? 0,
            $eval['checked'] ?? 0,
            count($eval['failing'] ?? []),
            ($eval['min_rate'] ?? 0) * 100,
            $eval['min_questions'] ?? 0
        ));

        foreach (($eval['failing'] ?? []) as $c) {
            mtrace(sprintf(
                '  %-24s asked=%d answered=%d rate=%.0f%% (courseid=%d)',
                $c['shortname'] ?: '<unknown>',
                $c['asked'],
                $c['answered'],
                $c['rate'] * 100,
                $c['courseid']
            ));
        }

        if (($eval['status'] ?? '') === 'unanswered') {
            $sent = unanswered_monitor::maybe_send_alert($eval);
            mtrace('unanswered_check: alert email '
                . ($sent ? 'sent' : 'skipped (already notified today, or no recipients)'));
        }
    }
}
