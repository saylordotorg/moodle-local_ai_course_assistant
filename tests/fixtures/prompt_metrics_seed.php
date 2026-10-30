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

namespace local_ai_course_assistant\tests;

use local_ai_course_assistant\prompt_metrics_logger;

/**
 * Writes prompt-metrics turns for tests of the settings-page truncation warning.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class prompt_metrics_seed {
    /**
     * Record turns through the real logger, some of which lost their retrieved course content.
     *
     * @param int $total Turns to record.
     * @param int $cut How many of them had course_content truncated.
     * @return void
     */
    public static function turns(int $total, int $cut): void {
        for ($i = 0; $i < $total; $i++) {
            $breakdown = [
                'identity' => ['category' => 'identity', 'chars' => 1600, 'used' => true, 'truncated' => false],
                'course_content' => ['category' => 'context', 'chars' => 5000, 'used' => true, 'truncated' => $i < $cut],
            ];
            prompt_metrics_logger::record(2, 3, 30000, 36000, $breakdown);
        }
    }
}
