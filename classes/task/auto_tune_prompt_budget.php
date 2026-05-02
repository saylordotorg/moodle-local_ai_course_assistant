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

use local_ai_course_assistant\prompt_metrics_logger;

/**
 * Daily task that applies the prompt-budget recommendation when the
 * `prompt_budget_auto_tune` admin flag is on.
 *
 * Default off — the recommendation always shows on the metrics admin
 * page; auto-apply only fires when the institution has explicitly
 * opted in. Manual "Apply recommendation" button on the admin page
 * is unaffected by this toggle.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auto_tune_prompt_budget extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task:auto_tune_prompt_budget', 'local_ai_course_assistant');
    }

    public function execute(): void {
        if (!get_config('local_ai_course_assistant', 'prompt_budget_auto_tune')) {
            mtrace('  Prompt budget auto-tune: disabled, skipping.');
            return;
        }
        $result = prompt_metrics_logger::apply_recommendation();
        if ($result['applied']) {
            mtrace(sprintf('  Prompt budget auto-tune: %d → %d chars. %s',
                $result['old'], $result['new'], $result['reason']));
        } else {
            mtrace('  Prompt budget auto-tune: no change. ' . $result['reason']);
        }
    }
}
