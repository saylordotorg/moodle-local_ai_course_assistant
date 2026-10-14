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

namespace local_ai_course_assistant\check;

use core\check\check;
use core\check\result;
use local_ai_course_assistant\failover_verifier;

/**
 * Status check: do the failover backups work? (v7.8.8)
 *
 * Reads the settings for problems that need no network (a chain line with no
 * row or key, no backup while per-call failover is on, a model written as a
 * display name) and the latest `failover_probe` audit rows written by the daily
 * check. A backup that failed its last two checks is an ERROR: the safety net
 * is down. One failure is a WARNING, because a provider can have a bad hour.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class failover_providers extends check {
    /** @var int Consecutive failed probes of one backup that make this an error. */
    public const ERROR_AFTER = 2;

    /**
     * Name shown in the status list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('check:failover_name', 'local_ai_course_assistant');
    }

    /**
     * Summarise the backups' health as a status result.
     *
     * @return result
     */
    public function get_result(): result {
        $problems = failover_verifier::static_problems();
        $labels = failover_verifier::chat_labels();
        $results = failover_verifier::latest_results();

        $failing = array_filter($results, fn($r) => !$r['ok']);
        $lines = array_map('s', $problems);
        $status = $problems ? result::WARNING : result::OK;
        foreach ($failing as $r) {
            $lines[] = get_string('check:failover_failing', 'local_ai_course_assistant', (object) [
                'label' => s($r['label']),
                'model' => s($r['model']),
                'detail' => s($r['detail']),
            ]);
            $status = ($r['failures'] >= self::ERROR_AFTER) ? result::ERROR
                : ($status === result::ERROR ? result::ERROR : result::WARNING);
        }
        if ($lines) {
            return new result($status, implode(' ', $lines));
        }
        if (!$labels) {
            return new result(result::INFO, get_string('check:failover_none', 'local_ai_course_assistant'));
        }
        if (!$results) {
            return new result(result::INFO, get_string('check:failover_unverified', 'local_ai_course_assistant'));
        }
        return new result(result::OK, get_string('check:failover_ok', 'local_ai_course_assistant', count($results)));
    }
}
