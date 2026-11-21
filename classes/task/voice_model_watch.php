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
 * Daily voice model watch (8.1.0): warns about voice models in use that retire within 45 days, and about
 * catalog models a provider no longer lists. Reads model lists only; never calls a model, never changes a setting.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class voice_model_watch extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:voice_model_watch', 'local_ai_course_assistant');
    }

    /**
     * Run the watch.
     *
     * @return void
     */
    public function execute() {
        $summary = \local_ai_course_assistant\voice_model_watch::run();
        if ($summary['inuse'] === []) {
            mtrace('Voice model watch: no voice provider is configured.');
            return;
        }
        foreach ($summary['inuse'] as [$provider, $capability, $model]) {
            mtrace("Voice model watch: in use {$provider} {$capability} {$model}.");
        }
        foreach ($summary['warnings'] as $w) {
            mtrace("Voice model watch: {$w['provider']} {$w['model']} shuts down {$w['shutdown']}"
                . ($w['retired'] ? ' (already past), calls go to ' : ', then calls go to ') . $w['resolves_to'] . '.');
        }
        foreach ($summary['missing'] as $m) {
            mtrace("Voice model watch: {$m['provider']} no longer lists {$m['model']}.");
        }
        foreach ($summary['errors'] as $provider => $error) {
            mtrace("Voice model watch: {$provider} model list not read: {$error}");
        }
        mtrace('Voice model watch: ' . $summary['reported'] . ' new finding(s) emailed.');
    }
}
