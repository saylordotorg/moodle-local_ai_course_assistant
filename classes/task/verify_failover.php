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

use local_ai_course_assistant\failover_verifier;

defined('MOODLE_INTERNAL') || die();

/**
 * v7.8.8 daily check that each chat backup provider answers a tiny real request.
 *
 * Skipped while an emergency control is active (a stop means stop, including
 * calls nobody asked for). Each backup costs a few tokens a day (a few hundred for a
 * thinking model), outside token analytics and spend caps.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class verify_failover extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return \local_ai_course_assistant\branding::apply(get_string('task:verify_failover', 'local_ai_course_assistant'));
    }

    /**
     * Probe every chat backup and report what came back.
     */
    public function execute(): void {
        if (\local_ai_course_assistant\emergency_control::any_active()) {
            mtrace('verify_failover: skipped, an emergency control is active.');
            return;
        }
        foreach (failover_verifier::probe_all() as $r) {
            mtrace(sprintf(
                'verify_failover: %s (%s) %s in %d ms%s',
                $r['label'],
                $r['model'],
                $r['ok'] ? 'ok' : 'FAILED',
                $r['ms'],
                $r['ok'] ? '' : ': ' . $r['detail']
            ));
        }
    }
}
