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
        return \local_ai_course_assistant\branding::apply(get_string('task:auto_tune_prompt_budget', 'local_ai_course_assistant'));
    }

    public function execute(): void {
        if (!get_config('local_ai_course_assistant', 'prompt_budget_auto_tune')) {
            mtrace('  Prompt budget auto-tune: disabled, skipping.');
            return;
        }

        // v7.2.6: stand down unless the budget is explicitly fixed.
        //
        // In auto mode the budget is derived from the model's context window,
        // and resolve_budget_chars() reads any stored value other than the
        // default as a deliberate administrator decision -- so a single write
        // from this task switches the derived budget off and leaves the site on
        // whatever number the tuner last inferred. Both sites in Saylor
        // production were in exactly that state: tuner on, neither budget at the
        // default, derivation silently inert.
        //
        // Checked here rather than in apply_recommendation() so the metrics page
        // can still SHOW a recommendation for an administrator to consider. The
        // objection is to writing it unattended, not to computing it.
        $mode = (string) get_config('local_ai_course_assistant', 'prompt_budget_mode');
        if ($mode !== 'fixed') {
            mtrace('  Prompt budget auto-tune: budget mode is "' . ($mode ?: 'auto')
                . '", which derives the budget from the model context window. '
                . 'This task is deprecated and does nothing unless the mode is "fixed".');
            return;
        }
        $result = prompt_metrics_logger::apply_recommendation();
        if ($result['applied']) {
            mtrace(sprintf(
                '  Prompt budget auto-tune: %d → %d chars. %s',
                $result['old'],
                $result['new'],
                $result['reason']
            ));
        } else {
            mtrace('  Prompt budget auto-tune: no change. ' . $result['reason']);
        }
    }
}
