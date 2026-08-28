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

use local_ai_course_assistant\conversation_manager;
use local_ai_course_assistant\meta_ai_data_builder;
use local_ai_course_assistant\provider\base_provider;
use local_ai_course_assistant\radar_delivery;
use local_ai_course_assistant\radar_schedule_manager;

/**
 * Scheduled task that runs every Learning Radar saved schedule whose
 * frequency matches today (daily / weekly / monthly).
 *
 * Each schedule produces an anonymized analytics answer, which is delivered
 * to whichever channels the schedule has configured: email recipient,
 * Slack incoming webhook, Microsoft Teams incoming webhook. The query and
 * response are persisted with interaction_type='meta_scheduled' so they
 * surface in Redash exports and the Learning Radar history.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_meta_ai_query extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task:run_meta_ai_query', 'local_ai_course_assistant');
    }

    public function execute(): void {
        // An emergency chat stop pauses this task rather than failing it: without
        // it the provider factory throws on every cron run for the length of the
        // incident. Checked here rather than per-schedule because a schedule with
        // no explicit provider takes a different branch to the primary factory,
        // so a guard further down covered only half the schedules -- and because
        // returning before the loop means no schedule records a run at all. A
        // skipped period previously recorded 'success' and advanced last_run,
        // making a paused stretch look like a stretch of completed reports.
        if (\local_ai_course_assistant\spend_guard::emergency_chat_stopped()) {
            mtrace('  Learning Radar cron: emergency chat stop is engaged; skipping this run.');
            return;
        }

        $schedules = radar_schedule_manager::all(true);
        if (empty($schedules)) {
            mtrace('  Learning Radar cron: no schedules configured.');
            return;
        }

        foreach ($schedules as $sched) {
            if (!radar_schedule_manager::should_run_today($sched->frequency)) {
                continue;
            }
            try {
                $this->run_schedule($sched);
                radar_schedule_manager::record_run((int) $sched->id, 'success');
            } catch (\Throwable $e) {
                mtrace('  Learning Radar cron ERROR (#' . $sched->id . '): ' . $e->getMessage());
                radar_schedule_manager::record_run((int) $sched->id, 'error', $e->getMessage());
            }
        }
        mtrace('  Learning Radar cron: done.');
    }

    /**
     * Execute one schedule end-to-end: build context, call LLM, deliver,
     * persist for export.
     *
     * @param \stdClass $sched Row from local_ai_course_assistant_radar_sched.
     * @return void
     */
    private function run_schedule(\stdClass $sched): void {
        global $CFG;
        require_once($CFG->dirroot . '/lib/filelib.php');

        $query = (string) $sched->query;
        if ($query === '') {
            mtrace('  Learning Radar cron: schedule #' . $sched->id . ' has no query, skipping.');
            return;
        }

        $rangedays = !empty($sched->range_days)
            ? (int) $sched->range_days
            : radar_schedule_manager::frequency_to_days($sched->frequency);
        $since = time() - ($rangedays * 86400);

        $courseids = [];
        if (!empty($sched->courseids)) {
            $courseids = array_filter(array_map('intval', explode(',', (string) $sched->courseids)));
        }
        $filterprovider = (string) ($sched->filterprovider ?? '');

        mtrace("  Learning Radar cron #{$sched->id}: building context (last {$rangedays}d, "
            . (empty($courseids) ? 'all courses' : 'courses ' . implode(',', $courseids))
            . ($filterprovider ? ", provider={$filterprovider}" : '') . ').');
        $systemprompt = meta_ai_data_builder::build_system_prompt($courseids, $since, $filterprovider);

        $providerid = (string) ($sched->provider ?? '');
        $modelid = (string) ($sched->model ?? '');
        if ($providerid !== '') {
            $llm = base_provider::create_for_comparison($providerid, $modelid);
        } else {
            $llm = base_provider::create_from_config(0);
        }

        $messages = [['role' => 'user', 'content' => $query]];
        mtrace("  Learning Radar cron #{$sched->id}: calling LLM (provider={$providerid}, model={$modelid})...");
        $response = $llm->chat_completion($systemprompt, $messages);

        $meta = [
            'frequency' => $sched->frequency,
            'range_days' => $rangedays,
            'provider' => $providerid !== '' ? $providerid : 'primary',
            'model' => $modelid !== '' ? $modelid : 'default',
        ];
        if (!empty($sched->courseids)) {
            $meta['courses'] = $sched->courseids;
        }
        if ($filterprovider !== '') {
            $meta['provider_filter'] = $filterprovider;
        }

        $delivered = false;
        if (!empty($sched->recipient_email)) {
            mtrace("  Learning Radar cron #{$sched->id}: emailing {$sched->recipient_email}...");
            $delivered = radar_delivery::send_email(
                (string) $sched->recipient_email,
                $query,
                $response,
                (string) ($sched->format ?: 'text'),
                'Scheduled (' . $sched->frequency . ')',
                $meta
            ) || $delivered;
        }
        if (!empty($sched->slack_webhook)) {
            mtrace("  Learning Radar cron #{$sched->id}: posting to Slack webhook...");
            $delivered = radar_delivery::send_slack((string) $sched->slack_webhook, $query, $response, $meta) || $delivered;
        }
        if (!empty($sched->teams_webhook)) {
            mtrace("  Learning Radar cron #{$sched->id}: posting to Teams webhook...");
            $delivered = radar_delivery::send_teams((string) $sched->teams_webhook, $query, $response, $meta) || $delivered;
        }

        if (!$delivered) {
            // Fall back to the site admin so the report does not vanish.
            $admin = get_admin();
            mtrace("  Learning Radar cron #{$sched->id}: no destination delivered; falling back to admin email.");
            radar_delivery::send_email(
                (string) $admin->email,
                $query,
                $response,
                (string) ($sched->format ?: 'text'),
                'Scheduled (' . $sched->frequency . ')',
                $meta
            );
        }

        // Persist for export. Approximate token counts from char length since
        // not every provider surfaces a usage block on chat_completion.
        try {
            $admin = get_admin();
            $approxprompt = (int) ceil((strlen($systemprompt) + strlen($query)) / 4);
            $approxcompletion = (int) ceil(strlen($response) / 4);
            $persistedmodel = $modelid !== '' ? $modelid : 'unknown';
            $persistedprovider = $providerid !== '' ? $providerid
                : (get_config('local_ai_course_assistant', 'provider') ?: 'unknown');
            conversation_manager::record_meta_query(
                (int) $admin->id,
                $query,
                $response,
                $persistedprovider,
                $persistedmodel,
                $approxprompt,
                $approxcompletion,
                true
            );
        } catch (\Throwable $persisterr) {
            mtrace('  Learning Radar cron #' . $sched->id . ': persistence failed (non-fatal): '
                . $persisterr->getMessage());
        }
    }
}
