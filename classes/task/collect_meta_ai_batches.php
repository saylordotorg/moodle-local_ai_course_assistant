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

use local_ai_course_assistant\provider\batch_capable_interface;
use local_ai_course_assistant\radar_batch_manager;
use local_ai_course_assistant\radar_runner;
use local_ai_course_assistant\radar_schedule_manager;
use local_ai_course_assistant\runtime_guard;

/**
 * Collect finished Learning Radar batches and deliver them.
 *
 * The second half of the offline-batch lifecycle. {@see run_meta_ai_query}
 * submits a batch and stops; this task polls every 30 minutes, and on the run
 * where a batch has finished it applies the runtime validators, delivers to
 * every channel the schedule configured, records the spend, and closes the
 * schedule's run status. A batch may take up to 24 hours, so a job is normally
 * polled dozens of times before it yields anything -- polling is a cheap
 * metadata GET and costs nothing.
 *
 * WHY THIS TASK EXISTS AT ALL, i.e. the caller analysis. Batch is only usable
 * where nobody is waiting on the answer. Of everything that reaches a provider
 * in this plugin, exactly one caller qualifies: the SCHEDULED Learning Radar,
 * whose output is emailed or webhooked. The ad-hoc Learning Radar in
 * meta_ai_sse.php shares the same 'meta' spend bucket and is NOT eligible --
 * an administrator is sitting on an open SSE stream watching tokens arrive.
 * Every learner-facing call (chat, quiz, flashcards, essay and speech scoring,
 * insights, the mastery classifier, slide vision, profile refresh) has a person
 * or a spinner attached and stays synchronous. None of those were converted.
 *
 * RUNTIME GUARD. The PII-echo, credential-leak and hallucination validators run
 * on every path a Radar answer can take: the ad-hoc one in meta_ai_sse.php, the
 * synchronous scheduled one in run_meta_ai_query::run_schedule(), and this one.
 * Collection is where a BATCHED answer first becomes text, so the guard is
 * applied here rather than at submit. Adding it here alone was not enough --
 * radar_batch_enabled is off by default, so the synchronous path is the only
 * path a scheduled report takes on a site that has just upgraded, and that is
 * the copy that leaves the Moodle session for an email list, Slack and Teams.
 *
 * IDEMPOTENCY. A collection has three irreversible side effects -- delivery, a
 * meta_scheduled spend row, and the schedule's run status -- and execute()
 * treats every Throwable as transient and leaves the job pending. The job is
 * therefore moved to STATUS_COLLECTING BEFORE any of them, so a failure in the
 * middle can never be replayed as a second report and a second invoice against
 * one billed call. See collect().
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class collect_meta_ai_batches extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task:collect_meta_ai_batches', 'local_ai_course_assistant');
    }

    public function execute(): void {
        global $CFG;
        require_once($CFG->dirroot . '/lib/filelib.php');

        $jobs = radar_batch_manager::pending();
        if (empty($jobs)) {
            return;
        }

        // The age sweep is pure DB and runs even during an emergency stop, so a
        // job cannot sit in 'submitted' forever because an incident happened to
        // outlast it.
        $jobs = $this->expire_stale($jobs);
        if (empty($jobs)) {
            return;
        }

        // A batch already submitted is already billed; there is nothing left to
        // save by touching it, and base_provider's factory refuses to build
        // anything at all while the kill switch is engaged (it is checked ahead
        // of every exemption, deliberately). So the jobs stay pending and are
        // collected on the first poll after the incident clears -- the report is
        // late rather than lost, and no new batch can be submitted meanwhile
        // because run_meta_ai_query returns before its loop.
        if (\local_ai_course_assistant\spend_guard::emergency_chat_stopped()) {
            mtrace('  Learning Radar batch collector: emergency chat stop engaged; '
                . count($jobs) . ' job(s) left pending.');
            return;
        }

        foreach ($jobs as $job) {
            try {
                $this->collect($job);
            } catch (\Throwable $e) {
                // A transport failure is transient by nature: leave the job
                // pending so the next poll retries, and let the age guard close
                // it out if it never recovers. Marking it failed here would
                // discard a report that has already been paid for because one
                // HTTP call timed out.
                mtrace('  Learning Radar batch collector: job #' . $job->id . ' poll failed (will retry): '
                    . $e->getMessage());
            }
        }
    }

    /**
     * Close out jobs that have been in flight past the point of usefulness.
     *
     * @param \stdClass[] $jobs
     * @return \stdClass[] The jobs still worth polling.
     */
    private function expire_stale(array $jobs): array {
        $live = [];
        foreach ($jobs as $job) {
            if (!radar_batch_manager::is_stale($job)) {
                $live[] = $job;
                continue;
            }
            $msg = 'Batch ' . $job->batchid . ' never reached a terminal state within '
                . (radar_batch_manager::MAX_AGE_SECONDS / HOURSECS) . ' hours; abandoned.';
            mtrace('  Learning Radar batch collector: job #' . $job->id . ' expired. ' . $msg);
            radar_batch_manager::mark((int) $job->id, radar_batch_manager::STATUS_FAILED, $msg);
            radar_schedule_manager::record_run((int) $job->scheduleid, 'error', $msg);
        }
        return $live;
    }

    /**
     * Poll one job and, when it has finished, deliver and record it.
     *
     * @param \stdClass $job Row from local_ai_course_assistant_radar_batch.
     * @return void
     */
    private function collect(\stdClass $job): void {
        global $DB;

        // enforcespend=false: the call this batch represents was billed at
        // submit time. Letting a spend cap swap the provider out here would
        // point the fetch at a vendor that has never heard of our batch id, and
        // would lose an already-paid-for report to save nothing.
        $llm = radar_runner::provider_for((string) $job->provider, (string) $job->model, false);
        if (!radar_batch_manager::provider_can_batch($llm)) {
            $msg = 'Provider configuration no longer supports batch, so batch ' . $job->batchid
                . ' cannot be collected. Cancel or download it in the provider console.';
            mtrace('  Learning Radar batch collector: job #' . $job->id . '. ' . $msg);
            radar_batch_manager::mark((int) $job->id, radar_batch_manager::STATUS_FAILED, $msg);
            radar_schedule_manager::record_run((int) $job->scheduleid, 'error', $msg);
            return;
        }

        $result = $llm->fetch_batch((string) $job->batchid);
        $status = (string) ($result['status'] ?? batch_capable_interface::BATCH_PENDING);

        if ($status === batch_capable_interface::BATCH_PENDING) {
            return;
        }

        if ($status !== batch_capable_interface::BATCH_COMPLETED) {
            $msg = 'Batch ' . $job->batchid . ' ended as ' . $status
                . ($result['error'] ? ': ' . $result['error'] : '.');
            mtrace('  Learning Radar batch collector: job #' . $job->id . ' ' . $msg);
            radar_batch_manager::mark(
                (int) $job->id,
                $status === batch_capable_interface::BATCH_CANCELLED
                    ? radar_batch_manager::STATUS_CANCELLED
                    : radar_batch_manager::STATUS_FAILED,
                $msg
            );
            radar_schedule_manager::record_run((int) $job->scheduleid, 'error', $msg);
            return;
        }

        $row = $result['results'][radar_batch_manager::CUSTOM_ID] ?? null;
        if (!is_array($row) || empty($row['content'])) {
            $msg = 'Batch ' . $job->batchid . ' completed but carried no usable answer'
                . (!empty($row['error']) ? ': ' . $row['error'] : ($result['error'] ? ': ' . $result['error'] : '.'));
            mtrace('  Learning Radar batch collector: job #' . $job->id . ' ' . $msg);
            radar_batch_manager::mark((int) $job->id, radar_batch_manager::STATUS_FAILED, $msg);
            radar_schedule_manager::record_run((int) $job->scheduleid, 'error', $msg);
            return;
        }

        $query = (string) $job->query;
        $admin = get_admin();

        // THE JOB LEAVES 'submitted' BEFORE ANY SIDE EFFECT, and this line is
        // the whole of the idempotency guarantee. Everything below -- delivery
        // to every configured destination, the meta_scheduled spend row, the
        // schedule's run status -- is irreversible, and execute()'s catch treats
        // every Throwable as transient and leaves the job pending. So while
        // STATUS_COMPLETED was the only marker and it was written last, a lock
        // timeout or a moodle_exception out of make_temp_directory() anywhere in
        // between meant the next 30-minute poll re-fetched the same completed
        // batch (the vendor keeps its output file for days) and delivered and
        // billed it a second time. Nothing links a meta_scheduled row back to a
        // batch id, so the duplicate could not be detected afterwards either.
        radar_batch_manager::mark((int) $job->id, radar_batch_manager::STATUS_COLLECTING, '');

        // Validators run on the batched answer here; see the class docblock.
        $response = runtime_guard::apply((string) $row['content'], [
            'input'  => $query,
            'userid' => (int) $admin->id,
        ]);

        // Delivery targets come from the LIVE schedule row: a webhook rotated
        // or an address corrected since submit should be honoured, and those are
        // the fields an admin edits between runs. The QUESTION and the WINDOW
        // come from the job row, because they describe what was actually asked.
        $sched = $DB->get_record('local_ai_course_assistant_radar_sched', ['id' => (int) $job->scheduleid]);
        if (!$sched) {
            $msg = 'Schedule #' . $job->scheduleid . ' was deleted while its batch was in flight; '
                . 'delivering the finished report to the site administrator instead.';
            mtrace('  Learning Radar batch collector: job #' . $job->id . '. ' . $msg);
            $sched = (object) [
                'id' => (int) $job->scheduleid,
                'recipient_email' => '',
                'slack_webhook' => '',
                'teams_webhook' => '',
                'format' => (string) $job->format,
                'frequency' => (string) $job->frequency,
            ];
        }

        $meta = radar_runner::meta_for(
            (string) $job->frequency,
            (int) $job->range_days,
            (string) ($job->courseids ?? ''),
            (string) ($job->filterprovider ?? ''),
            (string) $job->provider,
            (string) $job->model,
            true
        );

        // Past the STATUS_COLLECTING line a throw must NOT fall through to
        // execute()'s "transient, will retry" handler: the job is no longer
        // pending, so a retry would never come, and it would be the wrong answer
        // anyway -- we cannot tell how much of the delivery already happened, and
        // repeating it is the double-send this guard exists to prevent. Give the
        // job a terminal state and say where the answer went instead.
        try {
            $delivered = radar_runner::deliver(
                $sched,
                $query,
                $response,
                $meta,
                static function (string $msg) use ($job): void {
                    mtrace("  Learning Radar batch collector: job #{$job->id}: {$msg}");
                }
            );
        } catch (\Throwable $delivererr) {
            $msg = 'Batch ' . $job->batchid . ' was collected but delivery failed: '
                . $delivererr->getMessage() . ' The answer will NOT be re-delivered, because '
                . 'it cannot be known which destinations already received it.';
            mtrace('  Learning Radar batch collector: job #' . $job->id . '. ' . $msg);
            radar_batch_manager::mark((int) $job->id, radar_batch_manager::STATUS_FAILED, $msg);
            radar_schedule_manager::record_run((int) $job->scheduleid, 'error', $msg);
            return;
        }

        try {
            radar_runner::persist(
                (int) $admin->id,
                $query,
                $response,
                (string) $job->provider,
                (string) $job->model,
                $row['usage'] ?? null,
                '',
                true
            );
        } catch (\Throwable $persisterr) {
            mtrace('  Learning Radar batch collector: job #' . $job->id
                . ': persistence failed (non-fatal): ' . $persisterr->getMessage());
        }

        radar_batch_manager::mark((int) $job->id, radar_batch_manager::STATUS_COMPLETED, '');
        radar_schedule_manager::record_run(
            (int) $job->scheduleid,
            $delivered ? 'success' : 'error',
            $delivered ? '' : 'No destination accepted the delivery (webhook or email returned failure).'
        );
    }
}
