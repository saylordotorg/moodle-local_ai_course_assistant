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

/**
 * Ad-hoc task: run one queued model evaluation (v7.8.0).
 *
 * Queued by the daily discovery task or by an admin's Evaluate now. Custom
 * data: evalid. A run takes many minutes (two models, two sets of answers,
 * three jailbreak runs each and a judge), which is why it is a task and not a
 * page request.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evaluate_model_candidate extends \core\task\adhoc_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:evaluate_model_candidate', 'local_ai_course_assistant');
    }

    /**
     * Run the evaluation.
     *
     * @return void
     */
    public function execute() {
        \core_php_time_limit::raise(7200);
        $data = (array) $this->get_custom_data();
        $evalid = (int) ($data['evalid'] ?? 0);
        if ($evalid <= 0) {
            mtrace('evaluate_model_candidate: no evalid in custom data.');
            return;
        }
        $eval = (new \local_ai_course_assistant\autoupgrade\evaluator())->run($evalid);
        mtrace("evaluate_model_candidate: evaluation {$evalid} {$eval->status}"
            . ($eval->status === 'complete' ? ', gate ' . ($eval->gate_passed ? 'passed' : 'failed') : '')
            . ($eval->message ? ': ' . $eval->message : '') . sprintf(', spent $%.4f', (float) $eval->actual_cost_usd));
    }
}
