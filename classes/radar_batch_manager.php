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

namespace local_ai_course_assistant;

/**
 * The in-flight ledger for offline Learning Radar batches.
 *
 * Batch is submit / poll / collect with a completion window of up to 24 hours,
 * so the three steps happen in three different cron runs and three different
 * PHP processes. This table is the only thing that survives between them: it
 * holds the provider's batch id plus everything the collector needs to deliver
 * and record a report whose schedule may have been edited, disabled or deleted
 * in the meantime.
 *
 * WHY THE CONTEXT IS COPIED rather than re-read from the schedule row at
 * collect time: the report that comes back answers the question that was
 * ASKED, over the window that was pinned. An admin who edits the schedule's
 * query at 09:00 must not have their edit retro-labelled onto an answer
 * produced from yesterday's question at 06:00, and a "last 7 days" window
 * recomputed a day late silently covers days 2-8.
 *
 * WHY ONE BATCH PER SCHEDULE rather than one batch for all due schedules: a
 * batch targets a single endpoint, and schedules pin their own provider and
 * model, so bundling would first require bucketing by provider+model. It would
 * also couple delivery -- one schedule's report could not be sent until every
 * other schedule in the same batch finished -- and would break the 1:1
 * relationship between a schedule and its recorded run status, which is the
 * thing radar_schedule_manager::record_run() exists to keep honest.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class radar_batch_manager {
    /** @var string Table holding in-flight batch jobs. */
    public const TABLE = 'local_ai_course_assistant_radar_batch';

    /** Submitted upstream, not yet collected. */
    public const STATUS_SUBMITTED = 'submitted';

    /**
     * A completed batch whose answer we are in the middle of delivering.
     *
     * The idempotency marker, and the reason it is a state rather than a flag.
     * Collection has three side effects that cannot be undone -- delivery to
     * every configured email/Slack/Teams destination, a `meta_scheduled` spend
     * row, and the schedule's run status -- and the only record that they
     * happened used to be written AFTER all three. Any failure in between
     * (a lock timeout on the update, a moodle_exception out of
     * make_temp_directory() while building a csv attachment) left the job in
     * 'submitted', and the vendor keeps a completed batch's output file for
     * days, so the next 30-minute poll re-fetched the SAME answer and did it all
     * again: two reports in the recipients' inboxes and two spend rows for one
     * invoiced call, double-counted by the dashboard, the spend cap and the
     * anomaly detector alike.
     *
     * Moving out of 'submitted' BEFORE the side effects makes a replay
     * impossible. A job left stranded here is the deliberate trade: we cannot
     * tell how far the delivery got, so it is better read as an incomplete
     * collection than repeated as a second one.
     */
    public const STATUS_COLLECTING = 'collecting';

    /** Collected, delivered and recorded. Terminal. */
    public const STATUS_COMPLETED = 'completed';

    /** Gave up: upstream failure, expiry, or our own age guard. Terminal. */
    public const STATUS_FAILED = 'failed';

    /** Cancelled before it produced output. Terminal. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The custom_id every Radar request is submitted under.
     *
     * One request per batch today, so a constant is enough; the interface is
     * keyed by custom_id anyway so bundling later needs no format change here.
     */
    public const CUSTOM_ID = 'radar';

    /**
     * How long a submitted job may stay in flight before the poller gives up.
     *
     * The vendor completion window is 24h and the vendor marks an overrunning
     * batch 'expired' itself, which the collector handles directly. This guard
     * is for the case where the batch never reaches a terminal state we can
     * see at all -- the id was rejected, the key was rotated, the row outlived
     * its provider configuration. 36h leaves a full 12h of slack past the
     * vendor's own window before we stop polling, so a merely-slow batch is
     * never abandoned while it could still deliver.
     */
    public const MAX_AGE_SECONDS = 36 * HOURSECS;

    /**
     * Is offline batch submission turned on for scheduled Radar reports?
     *
     * Defaults to OFF, and the default is the point. Batch trades a same-morning
     * report for a report that arrives within 24 hours, and that is a decision
     * about an institution's reporting rhythm, not a performance tuning knob.
     * A site that upgrades into this release keeps the delivery timing it
     * already has until an administrator chooses otherwise, and turning the
     * setting back off returns every schedule to the synchronous path on the
     * next cron run.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (bool) get_config('local_ai_course_assistant', 'radar_batch_enabled');
    }

    /**
     * Persist a freshly submitted batch.
     *
     * THE PROVIDER RECORDED IS THE ONE THAT RAN, not the one the schedule asked
     * for. Those differ more often than they look: a schedule with no explicit
     * provider inherits the site primary (which 'auto' may resolve to anything),
     * and create_for_comparison() defers to create_from_config() whenever the
     * chat cap is blocked, which can substitute a completely different vendor.
     * Storing the REQUESTED ids meant the collector rebuilt a client for a
     * vendor that had never heard of the batch id: provider_can_batch() then
     * failed, the job was marked FAILED permanently, and a report that had
     * already been paid for was destroyed while the error told the admin to look
     * for it in the wrong provider's console.
     *
     * @param \stdClass $sched      The schedule row.
     * @param string    $batchid    Provider batch id.
     * @param array     $scope      Output of {@see radar_runner::scope_for()}.
     * @param string    $providerid Provider id of the client that actually submitted.
     * @param string    $modelid    Model the client actually asked for.
     * @return int New job row id.
     */
    public static function record_submission(
        \stdClass $sched,
        string $batchid,
        array $scope,
        string $providerid = '',
        string $modelid = ''
    ): int {
        global $DB;

        $now = time();
        $row = new \stdClass();
        $row->scheduleid     = (int) $sched->id;
        $row->batchid        = $batchid;
        $row->provider       = $providerid !== '' ? $providerid : (string) ($sched->provider ?? '');
        $row->model          = $modelid !== '' ? $modelid : (string) ($sched->model ?? '');
        $row->query          = (string) $sched->query;
        $row->format         = (string) ($sched->format ?: 'text');
        $row->frequency      = (string) ($sched->frequency ?? '');
        $row->range_days     = (int) $scope['rangedays'];
        $row->since_time     = (int) $scope['since'];
        $row->courseids      = (string) ($sched->courseids ?? '');
        $row->filterprovider = (string) $scope['filterprovider'];
        $row->status         = self::STATUS_SUBMITTED;
        $row->lasterror      = '';
        $row->timesubmitted  = $now;
        $row->timemodified   = $now;
        $row->timecompleted  = null;

        return (int) $DB->insert_record(self::TABLE, $row);
    }

    /**
     * Every job still waiting to be collected, oldest first.
     *
     * @return \stdClass[]
     */
    public static function pending(): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['status' => self::STATUS_SUBMITTED], 'timesubmitted ASC');
    }

    /**
     * Does this schedule already have a batch in flight?
     *
     * The submit task is date-gated only (should_run_today() answers 'daily'
     * with an unconditional true and never looks at last_run), so without this
     * ANY second execution on the same day submits a second batch for every due
     * schedule: an admin pressing "Run now" in Site administration > Server >
     * Scheduled tasks after editing a schedule, a cron process that dies
     * mid-loop so nextruntime is never advanced, or simply the ordinary case
     * where the submit task runs daily at 06:00 and the vendor's completion
     * window is also 24 hours. Two invoices, two identical reports delivered to
     * every destination, two spend rows -- the same outcome batch_http()
     * deliberately bypasses the retry wrapper to avoid, reached through the
     * cron loop instead of through a retried POST.
     *
     * "In flight" deliberately includes STATUS_COLLECTING: a job whose answer is
     * being delivered right now is not a schedule that needs asking again.
     *
     * @param int $scheduleid
     * @return bool
     */
    public static function pending_for(int $scheduleid): bool {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(
            [self::STATUS_SUBMITTED, self::STATUS_COLLECTING],
            SQL_PARAMS_NAMED,
            'st'
        );
        $params['scheduleid'] = $scheduleid;
        return $DB->record_exists_select(self::TABLE, "scheduleid = :scheduleid AND status {$insql}", $params);
    }

    /**
     * Move a job to another state.
     *
     * @param int    $id
     * @param string $status One of the STATUS_* constants.
     * @param string $error  Operator-facing reason; empty on success.
     * @return void
     */
    public static function mark(int $id, string $status, string $error = ''): void {
        global $DB;

        $allowed = [
            self::STATUS_SUBMITTED, self::STATUS_COLLECTING,
            self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED,
        ];
        $row = new \stdClass();
        $row->id = $id;
        $row->status = in_array($status, $allowed, true) ? $status : self::STATUS_FAILED;
        $row->lasterror = \core_text::substr($error, 0, 1024);
        $row->timemodified = time();
        // timecompleted means "this job is finished with", so only a TERMINAL
        // state sets it. 'collecting' is in flight, exactly as 'submitted' is.
        if (!in_array($row->status, [self::STATUS_SUBMITTED, self::STATUS_COLLECTING], true)) {
            $row->timecompleted = time();
        }
        $DB->update_record(self::TABLE, $row);
    }

    /**
     * Has this job been in flight past the point where polling is useful?
     *
     * @param \stdClass $job
     * @param int|null  $now Injectable for tests.
     * @return bool
     */
    public static function is_stale(\stdClass $job, ?int $now = null): bool {
        $now = $now ?? time();
        return ($now - (int) $job->timesubmitted) > self::MAX_AGE_SECONDS;
    }

    /**
     * Does this provider instance support batch submission right now?
     *
     * Both halves are required: implementing the interface says the class
     * speaks the wire format, supports_batch() says the configured endpoint
     * actually serves it. See batch_capable_interface for why that is two
     * questions and not one.
     *
     * @param mixed $llm
     * @return bool
     */
    public static function provider_can_batch($llm): bool {
        return $llm instanceof \local_ai_course_assistant\provider\batch_capable_interface
            && $llm->supports_batch();
    }
}
