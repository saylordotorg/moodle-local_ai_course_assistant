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

use local_ai_course_assistant\rate_card_refresher;

/**
 * Weekly cron task that refreshes SOLA's LLM rate card from a configurable
 * upstream pricing JSON (default LiteLLM). Runs Mondays 02:30 server time.
 *
 * v4.7.0 default: on. Admins flip `rate_card_auto_refresh` off in plugin
 * settings to pin to whatever was last fetched / manually pasted.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_rate_card extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task:refresh_rate_card', 'local_ai_course_assistant');
    }

    public function execute(): void {
        if (!get_config('local_ai_course_assistant', 'rate_card_auto_refresh')) {
            mtrace('  Rate card auto-refresh: disabled, skipping.');
            return;
        }

        mtrace('  Rate card auto-refresh: fetching upstream...');
        $result = rate_card_refresher::refresh();
        if ($result['ok']) {
            mtrace('  Rate card auto-refresh: success — ' . $result['count'] . ' entries written.');
        } else {
            mtrace('  Rate card auto-refresh ERROR: ' . $result['error']);
        }
    }
}
