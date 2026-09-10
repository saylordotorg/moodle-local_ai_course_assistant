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

use local_ai_course_assistant\meta_ai_data_builder;
use local_ai_course_assistant\radar_batch_manager;
use local_ai_course_assistant\radar_runner;
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
 * TWO PATHS SINCE v7.4.4.
 *
 * SYNCHRONOUS (default, and the fallback for everything): call the provider,
 * deliver the answer, record the outcome, all inside this cron run.
 *
 * OFFLINE BATCH (opt-in via radar_batch_enabled): submit the request to the
 * provider's batch tier for half price on input and output, record the batch id
 * in local_ai_course_assistant_radar_batch, and STOP. The answer is collected up
 * to 24 hours later by {@see collect_meta_ai_batches}, which does the delivery
 * and the persistence. Nothing is waiting on this slot -- the output is emailed
 * or webhooked, not rendered to a browser -- which is the whole reason it is
 * eligible and the ad-hoc Radar in meta_ai_sse.php is not.
 *
 * Batch is skipped, per schedule and without failing it, whenever the setting
 * is off or the resolved provider's endpoint has no batch tier. That fallback
 * runs the synchronous path, because a report that arrives beats a discount.
 *
 * RECORDING A SUBMIT. record_run() gains a third state, 'submitted'. Recording
 * 'success' at submit time would be the exact defect the comment in execute()
 * describes from the other direction: a run whose answer has not been produced,
 * let alone delivered, wearing a green badge -- and a failure 24 hours later
 * would then be invisible.
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
        //
        // v7.4.4: this also stops new BATCH submissions during an incident,
        // which matters more than stopping a synchronous call: a batch submitted
        // now is billed now and cannot be un-billed by lifting the stop.
        if (\local_ai_course_assistant\spend_guard::emergency_chat_stopped()) {
            mtrace('  Learning Radar cron: emergency chat stop is engaged; skipping this run.');
            return;
        }

        $schedules = radar_schedule_manager::all(true);
        if (empty($schedules)) {
            mtrace('  Learning Radar cron: no schedules configured.');
            return;
        }

        $batchmode = radar_batch_manager::enabled();

        foreach ($schedules as $sched) {
            if (!radar_schedule_manager::should_run_today($sched->frequency)) {
                continue;
            }
            // should_run_today() is calendar-only -- 'daily' is an unconditional
            // true and last_run is never consulted -- so this is the only thing
            // standing between a second execution of the task and a second
            // invoice. It is not an edge case: the submit task runs at 06:00 and
            // the vendor completion window is also 24h, so a batch that has not
            // finished by the next 06:00 is routine, and "Run now" in Site
            // administration > Server > Scheduled tasks is a normal thing for an
            // admin to press after editing a schedule. Skipping leaves
            // last_status as 'submitted', which is the truth: the report is
            // still in flight.
            if ($batchmode && radar_batch_manager::pending_for((int) $sched->id)) {
                mtrace("  Learning Radar cron #{$sched->id}: a batch is already in flight; "
                    . 'not submitting another.');
                continue;
            }
            try {
                if ($batchmode && $this->submit_schedule($sched)) {
                    radar_schedule_manager::record_run(
                        (int) $sched->id,
                        radar_schedule_manager::STATUS_SUBMITTED,
                        ''
                    );
                    continue;
                }

                // record_run used to say 'success' on the mere absence of a
                // thrown exception, while every delivery failure is signalled by
                // a false RETURN from radar_delivery -- so a dead Slack webhook
                // wore a green badge indefinitely.
                $ok = $this->run_schedule($sched);
                radar_schedule_manager::record_run(
                    (int) $sched->id,
                    $ok ? 'success' : 'error',
                    $ok ? '' : 'No destination accepted the delivery (webhook or email returned failure).'
                );
            } catch (\Throwable $e) {
                mtrace('  Learning Radar cron ERROR (#' . $sched->id . '): ' . $e->getMessage());
                radar_schedule_manager::record_run((int) $sched->id, 'error', $e->getMessage());
            }
        }
        mtrace('  Learning Radar cron: done.');
    }

    /**
     * Try to submit one schedule to the provider's offline batch tier.
     *
     * Returns FALSE for "not eligible, run it synchronously" and only throws
     * when a submission that should have worked did not. The distinction is
     * load-bearing: an ineligible schedule must still produce its report, so
     * "no batch tier here" cannot be allowed to look like a failure.
     *
     * A thrown submission is deliberately NOT retried synchronously. Once the
     * POST has left, we cannot tell a batch that was never created from one
     * that was created and whose response we lost, and falling back would bill
     * the second case twice and deliver two copies of the same report. The
     * schedule records the error, so the failure is visible rather than silent,
     * and the next cron run tries again.
     *
     * @param \stdClass $sched
     * @return bool True when a batch was submitted and this run is now waiting on it.
     */
    private function submit_schedule(\stdClass $sched): bool {
        global $CFG;
        require_once($CFG->dirroot . '/lib/filelib.php');

        $query = (string) $sched->query;
        if ($query === '') {
            // Nothing to ask. Let run_schedule() handle the empty-query case so
            // there is one definition of what an empty schedule does.
            return false;
        }

        $providerid = (string) ($sched->provider ?? '');
        $modelid = (string) ($sched->model ?? '');
        $llm = radar_runner::provider_for($providerid, $modelid);

        if (!radar_batch_manager::provider_can_batch($llm)) {
            mtrace("  Learning Radar cron #{$sched->id}: provider has no batch tier; running synchronously.");
            return false;
        }

        $scope = radar_runner::scope_for($sched);
        mtrace("  Learning Radar cron #{$sched->id}: building context for BATCH submit (last "
            . "{$scope['rangedays']}d, "
            . (empty($scope['courseids']) ? 'all courses' : 'courses ' . implode(',', $scope['courseids']))
            . ($scope['filterprovider'] ? ", provider={$scope['filterprovider']}" : '') . ').');

        $systemprompt = meta_ai_data_builder::build_system_prompt(
            $scope['courseids'],
            $scope['since'],
            $scope['filterprovider']
        );

        $batchid = $llm->submit_batch([
            radar_batch_manager::CUSTOM_ID => [
                'systemprompt' => $systemprompt,
                'messages'     => [['role' => 'user', 'content' => $query]],
                'options'      => [],
            ],
        ]);

        // Record the client that ACTUALLY submitted, not the one the schedule
        // asked for. provider_for() resolves 'auto', inherits the site primary
        // when the schedule pins nothing, and -- when the chat cap is blocked --
        // create_for_comparison() defers to create_from_config(), which can
        // substitute the failover vendor outright. The collector rebuilds from
        // this row up to 24 hours later, so a requested id here sends it to a
        // vendor that has never heard of $batchid: provider_can_batch() fails,
        // the job is marked FAILED, and a report that was already billed is
        // destroyed with an error naming the wrong provider's console.
        $jobid = radar_batch_manager::record_submission(
            $sched,
            $batchid,
            $scope,
            method_exists($llm, 'provider_id') ? (string) $llm->provider_id() : $providerid,
            method_exists($llm, 'model_id') ? (string) $llm->model_id() : $modelid
        );
        mtrace("  Learning Radar cron #{$sched->id}: submitted batch {$batchid} (job #{$jobid}); "
            . 'the collector task will deliver it when it completes.');

        return true;
    }

    /**
     * Execute one schedule end-to-end: build context, call LLM, deliver,
     * persist for export.
     *
     * @param \stdClass $sched Row from local_ai_course_assistant_radar_sched.
     * @return bool true when at least one destination accepted the delivery.
     */
    private function run_schedule(\stdClass $sched): bool {
        global $CFG;
        require_once($CFG->dirroot . '/lib/filelib.php');

        $query = (string) $sched->query;
        if ($query === '') {
            mtrace('  Learning Radar cron: schedule #' . $sched->id . ' has no query, skipping.');
            return true;
        }

        $scope = radar_runner::scope_for($sched);
        $rangedays = $scope['rangedays'];

        mtrace("  Learning Radar cron #{$sched->id}: building context (last {$rangedays}d, "
            . (empty($scope['courseids']) ? 'all courses' : 'courses ' . implode(',', $scope['courseids']))
            . ($scope['filterprovider'] ? ", provider={$scope['filterprovider']}" : '') . ').');
        $systemprompt = meta_ai_data_builder::build_system_prompt(
            $scope['courseids'],
            $scope['since'],
            $scope['filterprovider']
        );

        $providerid = (string) ($sched->provider ?? '');
        $modelid = (string) ($sched->model ?? '');
        $llm = radar_runner::provider_for($providerid, $modelid);

        $messages = [['role' => 'user', 'content' => $query]];
        mtrace("  Learning Radar cron #{$sched->id}: calling LLM (provider={$providerid}, model={$modelid})...");
        $response = $llm->chat_completion($systemprompt, $messages);

        // The PII-echo, credential-leak and hallucination validators run on the
        // scheduled answer here, exactly as collect_meta_ai_batches does on the
        // batch answer and meta_ai_sse does on the ad-hoc one. This path had
        // none: radar_batch_enabled defaults to OFF, so on every site that
        // upgrades into this release the SYNCHRONOUS path is the only path a
        // scheduled report takes -- and it is the higher-exposure of the two,
        // because the Radar prompt is built over anonymized-but-real course data
        // and the delivered copy leaves the Moodle session entirely for an email
        // recipient list, Slack and Teams.
        //
        // Applied before deliver() AND before persist(), so the stored history
        // and the delivered report are the same text; a guard inside deliver()
        // alone would leave persist() recording the unvalidated answer.
        $response = \local_ai_course_assistant\runtime_guard::apply($response, [
            'input'  => $query,
            'userid' => (int) get_admin()->id,
        ]);

        $meta = radar_runner::meta_for(
            (string) $sched->frequency,
            $rangedays,
            (string) ($sched->courseids ?? ''),
            $scope['filterprovider'],
            $providerid,
            $modelid,
            false
        );

        $delivered = radar_runner::deliver(
            $sched,
            $query,
            $response,
            $meta,
            static function (string $msg) use ($sched): void {
                mtrace("  Learning Radar cron #{$sched->id}: {$msg}");
            }
        );

        // Persist for export. v7.4.4 reads the provider's real usage block when
        // it reported one and falls back to the character-count approximation
        // only when it did not -- chat_completion() has populated
        // get_last_token_usage() since v7.0.6, so the approximation had been
        // mis-measuring Learning Radar spend for four releases.
        try {
            $admin = get_admin();
            radar_runner::persist(
                (int) $admin->id,
                $query,
                $response,
                $providerid,
                $modelid,
                method_exists($llm, 'get_last_token_usage') ? $llm->get_last_token_usage() : null,
                $systemprompt,
                false
            );
        } catch (\Throwable $persisterr) {
            mtrace('  Learning Radar cron #' . $sched->id . ': persistence failed (non-fatal): '
                . $persisterr->getMessage());
        }

        return $delivered;
    }
}
