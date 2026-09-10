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

use local_ai_course_assistant\provider\batch_capable_interface;
use local_ai_course_assistant\provider\stub_provider;
use local_ai_course_assistant\task\collect_meta_ai_batches;
use local_ai_course_assistant\task\run_meta_ai_query;

/**
 * The offline-batch tier for scheduled Learning Radar reports (v7.4.4).
 *
 * Two things are being pinned here, and they fail in different ways.
 *
 * THE LIFECYCLE. Batch is submit / poll / collect across three cron runs, so
 * "it works" is not one assertion but four: a submit writes a job and does NOT
 * deliver, a poll on an unfinished batch changes nothing, a poll on a finished
 * one delivers and records, and a batch that dies leaves the schedule marked
 * error rather than quietly successful. The failure mode this guards against is
 * a report that is submitted, billed, and then never collected by anything --
 * which looks exactly like success from the submitting task's point of view.
 *
 * THE PRICE. Batch is 50% off. If the discount does not reach
 * token_cost_manager, the AI Spend dashboard overstates this line by exactly
 * 2x, and it does so silently because every number involved is still plausible.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\radar_batch_manager
 * @covers     \local_ai_course_assistant\radar_runner
 * @covers     \local_ai_course_assistant\task\collect_meta_ai_batches
 */
final class radar_batch_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        stub_provider::reset();
        model_registry::reset_cache();
        set_config('provider', 'stub', 'local_ai_course_assistant');
    }

    // ───────────────────────────────────────────────────────────
    // Pricing: the discount has to reach token_cost_manager.
    // ───────────────────────────────────────────────────────────

    public function test_batch_marker_halves_the_resolved_rate(): void {
        $list = model_registry::rate_for('gpt-4o-mini');
        $batch = model_registry::rate_for(token_cost_manager::batch_model_name('gpt-4o-mini'));

        $this->assertNotNull($list);
        $this->assertNotNull($batch, 'a batch-marked model must still resolve to a rate; '
            . 'returning null would price the whole batch line at $0.00');
        $this->assertEqualsWithDelta($list['input'] / 2, $batch['input'], 0.000001);
        $this->assertEqualsWithDelta($list['output'] / 2, $batch['output'], 0.000001);
    }

    public function test_estimate_cost_halves_for_a_batched_call(): void {
        $sync = token_cost_manager::estimate_cost('gpt-4o-mini', 1000000, 1000000);
        $batch = token_cost_manager::estimate_cost('batch/gpt-4o-mini', 1000000, 1000000);

        $this->assertNotNull($sync);
        $this->assertNotNull($batch);
        $this->assertEqualsWithDelta($sync / 2, $batch, 0.000001,
            'the batch tier is 50% off input AND output; anything else overstates the line');
    }

    public function test_the_batch_marker_cannot_be_applied_twice(): void {
        $once = token_cost_manager::batch_model_name('gpt-4o-mini');
        $twice = token_cost_manager::batch_model_name($once);

        $this->assertSame($once, $twice, 'double-marking would halve the rate twice');
        $this->assertSame('gpt-4o-mini', token_cost_manager::strip_batch_prefix($twice));
    }

    public function test_an_unknown_model_is_still_unknown_when_batched(): void {
        $this->assertNull(model_registry::rate_for('who-even-is-this'));
        $this->assertNull(model_registry::rate_for('batch/who-even-is-this'),
            'a batched unknown model must report unknown, not a discounted guess');
    }

    public function test_reasoning_is_still_billed_as_extra_output_when_batched(): void {
        // batch/gemini-2.5-flash is still Gemini. If the marker hid that, the
        // batched Radar rows would be the one place the v7.4.2 reasoning fix
        // silently stopped applying.
        $this->assertTrue(token_cost_manager::reasoning_billed_as_extra_output('gemini-2.5-flash'));
        $this->assertTrue(token_cost_manager::reasoning_billed_as_extra_output('batch/gemini-2.5-flash'));
        $this->assertFalse(token_cost_manager::reasoning_billed_as_extra_output('batch/gpt-4o-mini'));

        // ...and the SQL half must agree with the PHP half, or get_total_tokens()
        // and estimate_cost() disagree about the same row.
        $this->assertStringContainsString(
            "LIKE 'batch/gemini-%'",
            token_cost_manager::extra_output_tokens_sql('m')
        );
    }

    public function test_the_dashboard_prices_a_batch_row_at_the_discount(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $month = date('Y-m');

        $before = analytics::get_monthly_provider_spend($month)['by_provider']['google'] ?? 0.0;
        $this->write_meta_row($user->id, $course->id, 'batch/gemini-2.5-flash', 1000000, 0);
        $after = analytics::get_monthly_provider_spend($month);

        // gemini-2.5-flash input is $0.30/1M, so 1M batched input is $0.15.
        $this->assertEqualsWithDelta(0.15, $after['by_provider']['google'] - $before, 0.000001,
            'the AI Spend dashboard is reading list price for a batched call');
        $this->assertSame(0, $after['unpriced_rows'],
            'a batch-marked model must be priceable, or the discount is untested and the row reads $0.00');
    }

    // ───────────────────────────────────────────────────────────
    // Submit side: eligibility, and never losing a report to it.
    // ───────────────────────────────────────────────────────────

    public function test_batch_is_off_by_default_so_the_run_stays_synchronous(): void {
        global $DB;
        // Provider CAN batch; the setting is simply unset, which is the state a
        // site upgrades into. Delivery timing must not change on upgrade.
        stub_provider::$batch_supported = true;
        $this->seed_schedule();

        $this->run_task_silently(new run_meta_ai_query());

        $this->assertCount(1, stub_provider::$calls, 'batch defaults off, so the call runs inline');
        $this->assertSame(0, $DB->count_records(radar_batch_manager::TABLE));
    }

    public function test_a_provider_without_a_batch_tier_falls_back_to_synchronous(): void {
        global $DB;
        set_config('radar_batch_enabled', 1, 'local_ai_course_assistant');
        stub_provider::$batch_supported = false;
        $sched = $this->seed_schedule();

        $this->run_task_silently(new run_meta_ai_query());

        $this->assertCount(1, stub_provider::$calls,
            'a site whose provider has no batch tier must still get its report');
        $this->assertSame(0, $DB->count_records(radar_batch_manager::TABLE));
        $this->assertSame('success',
            $DB->get_field('local_ai_course_assistant_radar_sched', 'last_status', ['id' => $sched->id]));
    }

    public function test_an_eligible_schedule_is_submitted_and_not_answered_inline(): void {
        global $DB;
        set_config('radar_batch_enabled', 1, 'local_ai_course_assistant');
        stub_provider::$batch_supported = true;
        $sched = $this->seed_schedule();

        $this->run_task_silently(new run_meta_ai_query());

        $this->assertCount(0, stub_provider::$calls,
            'a submitted schedule must NOT also make a synchronous call -- that would bill twice');
        $jobs = $DB->get_records(radar_batch_manager::TABLE);
        $this->assertCount(1, $jobs);

        $job = reset($jobs);
        $this->assertSame(radar_batch_manager::STATUS_SUBMITTED, $job->status);
        $this->assertSame('How many sessions yesterday?', $job->query);
        $this->assertGreaterThan(0, (int) $job->since_time,
            'the analytics window must be PINNED at submit; recomputing it a day later slides the period');

        $this->assertSame(radar_schedule_manager::STATUS_SUBMITTED,
            $DB->get_field('local_ai_course_assistant_radar_sched', 'last_status', ['id' => $sched->id]),
            'a submitted run must not wear a success badge for an answer that does not exist yet');
    }

    public function test_an_emergency_stop_submits_nothing(): void {
        global $DB;
        set_config('radar_batch_enabled', 1, 'local_ai_course_assistant');
        set_config('emergency_chat_disabled', 1, 'local_ai_course_assistant');
        stub_provider::$batch_supported = true;
        $this->seed_schedule();

        $this->run_task_silently(new run_meta_ai_query());

        $this->assertSame(0, $DB->count_records(radar_batch_manager::TABLE),
            'a batch submitted during an incident is billed immediately and cannot be un-billed by lifting the stop');
    }

    // ───────────────────────────────────────────────────────────
    // Collect side.
    // ───────────────────────────────────────────────────────────

    public function test_polling_an_unfinished_batch_changes_nothing(): void {
        global $DB;
        [$job] = $this->submit_one();

        $this->run_task_silently(new collect_meta_ai_batches());

        $this->assertSame(radar_batch_manager::STATUS_SUBMITTED,
            $DB->get_field(radar_batch_manager::TABLE, 'status', ['id' => $job->id]));
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_msgs',
            ['interaction_type' => 'meta_scheduled']),
            'nothing may be recorded before the answer exists');
    }

    public function test_a_finished_batch_is_delivered_recorded_and_priced_as_batch(): void {
        global $DB;
        [$job, $sched] = $this->submit_one();

        stub_provider::complete_batch(
            (string) $job->batchid,
            radar_batch_manager::CUSTOM_ID,
            'Sessions were up 12% week over week.'
        );

        $sink = $this->redirectEmails();
        $this->run_task_silently(new collect_meta_ai_batches());
        $sink->close();

        $this->assertSame(radar_batch_manager::STATUS_COMPLETED,
            $DB->get_field(radar_batch_manager::TABLE, 'status', ['id' => $job->id]));
        $this->assertSame('success',
            $DB->get_field('local_ai_course_assistant_radar_sched', 'last_status', ['id' => $sched->id]));

        $rows = $DB->get_records('local_ai_course_assistant_msgs',
            ['interaction_type' => 'meta_scheduled', 'role' => 'assistant']);
        $this->assertCount(1, $rows);
        $row = reset($rows);

        $this->assertSame('batch/gpt-4o-mini', $row->model_name,
            'without the batch marker the spend pipeline prices this call at list, i.e. 2x');
        $this->assertSame(1000, (int) $row->prompt_tokens,
            'the provider REPORTED usage must be recorded, not a strlen/4 approximation');
        $this->assertSame(400, (int) $row->completion_tokens);
        $this->assertSame('openai', $row->provider,
            'attribution follows the provider that actually served the call');
    }

    public function test_a_failed_batch_marks_the_schedule_error_not_success(): void {
        global $DB;
        [$job, $sched] = $this->submit_one();

        stub_provider::fail_batch((string) $job->batchid, batch_capable_interface::BATCH_EXPIRED, 'window elapsed');

        $this->run_task_silently(new collect_meta_ai_batches());

        $this->assertSame(radar_batch_manager::STATUS_FAILED,
            $DB->get_field(radar_batch_manager::TABLE, 'status', ['id' => $job->id]));
        $this->assertSame('error',
            $DB->get_field('local_ai_course_assistant_radar_sched', 'last_status', ['id' => $sched->id]),
            'a run that produced no report must not stay on the submitted badge forever');
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_msgs',
            ['interaction_type' => 'meta_scheduled']));
    }

    public function test_a_job_that_never_terminates_is_abandoned_by_the_age_guard(): void {
        global $DB;
        [$job, $sched] = $this->submit_one();

        // Older than MAX_AGE_SECONDS, and still pending upstream.
        $DB->set_field(radar_batch_manager::TABLE, 'timesubmitted',
            time() - radar_batch_manager::MAX_AGE_SECONDS - 60, ['id' => $job->id]);

        $this->run_task_silently(new collect_meta_ai_batches());

        $this->assertSame(radar_batch_manager::STATUS_FAILED,
            $DB->get_field(radar_batch_manager::TABLE, 'status', ['id' => $job->id]));
        $this->assertSame('error',
            $DB->get_field('local_ai_course_assistant_radar_sched', 'last_status', ['id' => $sched->id]));
    }

    public function test_an_emergency_stop_leaves_jobs_pending_rather_than_failing_them(): void {
        global $DB;
        [$job] = $this->submit_one();
        stub_provider::complete_batch((string) $job->batchid, radar_batch_manager::CUSTOM_ID, 'done');
        set_config('emergency_chat_disabled', 1, 'local_ai_course_assistant');

        $this->run_task_silently(new collect_meta_ai_batches());

        $this->assertSame(radar_batch_manager::STATUS_SUBMITTED,
            $DB->get_field(radar_batch_manager::TABLE, 'status', ['id' => $job->id]),
            'the work is paid for; the report should be late, not lost');
    }

    // ───────────────────────────────────────────────────────────
    // The synchronous path keeps working, and got more accurate.
    // ───────────────────────────────────────────────────────────

    public function test_the_synchronous_path_records_reported_usage_not_an_approximation(): void {
        global $DB;
        $this->seed_schedule();

        $this->run_task_silently(new run_meta_ai_query());

        $rows = $DB->get_records('local_ai_course_assistant_msgs',
            ['interaction_type' => 'meta_scheduled', 'role' => 'assistant']);
        $this->assertCount(1, $rows);
        $row = reset($rows);

        // stub_provider::get_last_token_usage() reports 100/50. The old code
        // ignored it and derived both counts from string length.
        $this->assertSame(100, (int) $row->prompt_tokens);
        $this->assertSame(50, (int) $row->completion_tokens);
        $this->assertSame('stub-model', $row->model_name);
        $this->assertStringNotContainsString('batch/', (string) $row->model_name,
            'a synchronous call must never be priced at the batch discount');
    }

    /**
     * The synchronous scheduled answer goes through the runtime validators.
     *
     * radar_batch_enabled defaults to OFF, so this is the path every site takes
     * on upgrade -- and it is the higher-exposure of the two, because the
     * delivered copy leaves the Moodle session for an email list, Slack and
     * Teams while the SSE one only ever reaches an admin's own screen. The
     * v7.4.4 handover claimed the guard "now runs on scheduled Radar answers";
     * it ran only on the batch collector, i.e. only in the configuration that
     * is off by default.
     */
    public function test_the_synchronous_scheduled_answer_is_validated(): void {
        global $DB;
        set_config('validators_runtime_mode', 'annotate', 'local_ai_course_assistant');
        // AKIA + 16 uppercase alphanumerics: an AWS access key id, which
        // credential_leak_validator blocks.
        stub_provider::program_response('chat', 'Sessions rose. Key AKIAJKLMNOPQ1234567X for reference.');
        $this->seed_schedule();

        $this->run_task_silently(new run_meta_ai_query());

        $rows = $DB->get_records('local_ai_course_assistant_msgs',
            ['interaction_type' => 'meta_scheduled', 'role' => 'assistant']);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString(
            'Response review',
            (string) reset($rows)->message,
            'runtime_guard::apply() did not run on the synchronous scheduled answer, so the '
            . 'PII-echo and credential-leak validators cover the Radar report an admin reads '
            . 'on screen and not the one that is emailed out'
        );
    }

    // ───────────────────────────────────────────────────────────
    // The marker is a PRICING key, never a model identity.
    // ───────────────────────────────────────────────────────────

    /**
     * The recommender still sees the traffic after batch is switched on.
     *
     * model_recommender::volume_for() matches the configured model id against
     * msgs.model_name. Once radar_batch_enabled is on, EVERY scheduled Radar row
     * carries `batch/<model>`, so a bare equality matches nothing and the
     * Analytics card reports calls=0 and observed=false -- reading zero for
     * precisely the one function this release moved to batch, and falling back
     * to the no-volume default for its savings estimate.
     */
    public function test_the_recommender_counts_batched_rows_as_the_model_they_are(): void {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        set_config('model', 'gpt-4o-mini', 'local_ai_course_assistant');

        for ($i = 0; $i < 5; $i++) {
            $this->write_meta_row((int) $user->id, (int) $course->id, 'batch/gpt-4o-mini', 1000, 400);
        }

        $volume = model_recommender::volume_for('analytics', 'gpt-4o-mini');

        $this->assertSame(5, $volume['calls'],
            'batched Radar rows are the analytics traffic; a model-identity match that does '
            . 'not strip the pricing marker goes blind exactly where the traffic is');
        $this->assertTrue($volume['observed']);
    }

    /**
     * The optimizer never offers `batch/<model>` as something to adopt.
     *
     * rank_providers() consumes the model STRING and hands it to an admin as a
     * recommendation. Grouped raw, `batch/gpt-4o-mini` becomes a separate
     * candidate priced at exactly half of `gpt-4o-mini` for the same capability
     * -- a permanently unbeatable recommendation naming a model id no vendor
     * serves -- and it splits one model's history into two populations, either
     * of which can fall under MIN_COST_SAMPLE and produce no recommendation at
     * all.
     */
    public function test_the_optimizer_merges_batch_into_the_model_it_discounts(): void {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        // 20 batched + 20 synchronous: neither half reaches MIN_COST_SAMPLE (30)
        // on its own, so a split population produces nothing at all.
        for ($i = 0; $i < 20; $i++) {
            $this->write_meta_row((int) $user->id, (int) $course->id, 'batch/gpt-4o-mini', 1000, 400);
            $this->write_meta_row((int) $user->id, (int) $course->id, 'gpt-4o-mini', 1000, 400);
        }

        $rankings = [];
        foreach (llm_optimizer::recommend()['capabilities'] as $cap) {
            if ($cap['capability'] === 'analytics') {
                $rankings = $cap['rankings'];
            }
        }

        $this->assertNotEmpty($rankings,
            'splitting one model into a batch and a sync candidate left both under '
            . 'MIN_COST_SAMPLE, so the analytics capability produced no recommendation');
        $models = array_column($rankings, 'model');
        $this->assertContains('gpt-4o-mini', $models);
        foreach ($models as $model) {
            $this->assertStringNotContainsString('batch/', $model,
                'an admin who pastes a batch/-prefixed id into a schedule configures a model '
                . 'no vendor accepts, and every run then fails with HTTP 400');
        }
        $this->assertCount(1, $models, 'batch and sync of one model are one option, not two');
    }

    // ───────────────────────────────────────────────────────────
    // Nothing may be submitted, delivered or billed twice.
    // ───────────────────────────────────────────────────────────

    /**
     * A schedule with a batch already in flight does not submit another.
     *
     * should_run_today() is calendar-only, so ANY second execution of the task
     * on the same day duplicates every due schedule: "Run now" in the scheduled
     * tasks admin screen, a cron process that dies mid-loop before
     * nextruntime advances, or simply a batch that has not finished by the next
     * 06:00 -- the submit interval and the vendor's completion window are both
     * 24 hours, so that is the ordinary case. Each duplicate is a second
     * invoice and a second copy of the report in every recipient's inbox.
     */
    public function test_a_schedule_with_a_batch_in_flight_does_not_submit_another(): void {
        global $DB;
        [$job, $sched] = $this->submit_one();

        // Second run of the same task, same day, nothing else changed.
        $this->run_task_silently(new run_meta_ai_query());

        $this->assertCount(1, $DB->get_records(radar_batch_manager::TABLE),
            'a second cron pass submitted a second batch for a schedule already in flight: '
            . 'two invoices and two copies of one report');
        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_msgs',
            ['interaction_type' => 'meta_scheduled']),
            'nor may it fall back to the synchronous path, which bills the same report again');
        $this->assertSame(radar_schedule_manager::STATUS_SUBMITTED,
            $DB->get_field('local_ai_course_assistant_radar_sched', 'last_status', ['id' => $sched->id]),
            'the in-flight state must stay visible on the schedule');
        $this->assertTrue(radar_batch_manager::pending_for((int) $job->scheduleid));
    }

    /**
     * The job records the client that actually submitted, not the request.
     *
     * The schedule pins no provider, so it inherits the site primary. Storing
     * the schedule's empty string meant the collector rebuilt "whatever the
     * site primary is NOW" -- and if a spend cap or an 'auto' resolution had
     * moved that in the meantime, the fetch went to a vendor that had never
     * heard of the batch id, the job was marked FAILED, and an already-billed
     * report was destroyed.
     */
    public function test_the_job_records_the_client_that_actually_submitted(): void {
        [$job] = $this->submit_one();

        $this->assertSame('stub', (string) $job->provider,
            'the job row must name the provider that really submitted the batch, not the '
            . "schedule's blank 'use the site primary' placeholder");
        $this->assertNotSame('', (string) $job->model,
            'the resolved model must be recorded too, or the collector asks a different one');
    }

    /**
     * A collection that has begun can never be replayed.
     *
     * The only marker used to be STATUS_COMPLETED, written AFTER delivery and
     * after the spend row, while execute() treats every Throwable as transient
     * and leaves the job pending. So a failure in between meant the next poll
     * re-fetched the same completed batch -- the vendor keeps its output file
     * for days -- and delivered and billed it a second time.
     */
    public function test_a_collection_in_progress_is_not_polled_again(): void {
        global $DB;
        [$job] = $this->submit_one();
        stub_provider::complete_batch((string) $job->batchid, radar_batch_manager::CUSTOM_ID, 'done');

        radar_batch_manager::mark((int) $job->id, radar_batch_manager::STATUS_COLLECTING, '');
        $this->run_task_silently(new collect_meta_ai_batches());

        $this->assertSame(0, $DB->count_records('local_ai_course_assistant_msgs',
            ['interaction_type' => 'meta_scheduled']),
            'a job already being collected must not be picked up and billed a second time');
        $this->assertNull(
            $DB->get_field(radar_batch_manager::TABLE, 'timecompleted', ['id' => $job->id]),
            'collecting is an in-flight state, not a terminal one'
        );
    }

    /**
     * The job leaves 'submitted' BEFORE the irreversible side effects.
     *
     * Ordering is the entire guarantee, and it is not observable from the
     * outside once the happy path has run -- so it is asserted where it lives.
     */
    public function test_the_collector_marks_the_job_before_it_delivers(): void {
        global $CFG;
        $src = file_get_contents(
            $CFG->dirroot . '/local/ai_course_assistant/classes/task/collect_meta_ai_batches.php');
        $this->assertNotFalse($src);

        // The CALL, not the constant: the comment above it names STATUS_COLLECTING
        // too, and a guard that matches its own explanation guards nothing.
        $mark = strpos($src, 'radar_batch_manager::mark((int) $job->id, radar_batch_manager::STATUS_COLLECTING');
        $deliver = strpos($src, 'radar_runner::deliver(');
        $persist = strpos($src, 'radar_runner::persist(');

        $this->assertNotFalse($mark, 'the collector no longer moves the job out of submitted');
        $this->assertNotFalse($deliver);
        $this->assertNotFalse($persist);
        $this->assertLessThan($deliver, $mark,
            'the job must leave the pending state BEFORE delivery, or a failure between the '
            . 'two replays the whole collection on the next poll');
        $this->assertLessThan($persist, $mark,
            'and before the spend row, or one billed call is counted twice');
    }

    /**
     * The collect path never lets a spend cap repoint the fetch.
     *
     * A schedule with no explicit provider takes the site-primary branch of
     * provider_for(), which passed its $enforcespend argument to a factory that
     * had no such parameter -- so the one configuration the argument existed to
     * protect was the only one it did not reach. With the cap blocked, the
     * primary would be swapped for the failover vendor (which has never heard of
     * our batch id) or wrapped in a failover_chain (which is not batch-capable,
     * so the job is marked FAILED outright).
     */
    public function test_a_blocked_spend_cap_does_not_repoint_the_collect(): void {
        global $DB;
        [$job, $sched] = $this->submit_one();
        stub_provider::complete_batch(
            (string) $job->batchid, radar_batch_manager::CUSTOM_ID, 'Collected anyway.');

        // Exhaust the chat cap the way a real month does. infer_capability_for_primary()
        // reports 'chat' for the site primary, so that is the cap create_from_config()
        // consults -- and the test asserts its own precondition, because a cap that is
        // not actually blocked would let this pass against the defect.
        set_config('spend_cap_chat', 0.01, 'local_ai_course_assistant');
        set_config('failover_per_call_enabled', 1, 'local_ai_course_assistant');
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->write_chat_row((int) $user->id, (int) $course->id, 'gpt-4o', 1000000, 1000000);
        $this->assertSame(
            spend_guard::CAP_BLOCKED,
            spend_guard::check(0, 'chat', false),
            'the cap is not actually blocked, so this test would pass against the defect'
        );

        $sink = $this->redirectEmails();
        $this->run_task_silently(new collect_meta_ai_batches());
        $sink->close();

        $this->assertSame(radar_batch_manager::STATUS_COMPLETED,
            $DB->get_field(radar_batch_manager::TABLE, 'status', ['id' => $job->id]),
            'the money was committed at submit; a cap reached afterwards must not throw the '
            . 'report away to save nothing');
        $this->assertSame(1, $DB->count_records('local_ai_course_assistant_msgs',
            ['interaction_type' => 'meta_scheduled', 'role' => 'assistant']));
        $this->assertSame('success',
            $DB->get_field('local_ai_course_assistant_radar_sched', 'last_status', ['id' => $sched->id]));
    }

    // ───────────────────────────────────────────────────────────
    // Helpers.
    // ───────────────────────────────────────────────────────────

    /**
     * Run a cron task with mtrace output suppressed.
     *
     * @param \core\task\scheduled_task $task
     */
    private function run_task_silently(\core\task\scheduled_task $task): void {
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * One enabled daily schedule (should_run_today() is always true for daily).
     *
     * @return \stdClass
     */
    private function seed_schedule(): \stdClass {
        global $DB;
        $id = $DB->insert_record('local_ai_course_assistant_radar_sched', (object) [
            'name' => 'test', 'query' => 'How many sessions yesterday?',
            'frequency' => 'daily', 'range_days' => 7,
            'courseids' => '', 'filterprovider' => '',
            'provider' => '', 'model' => '', 'format' => 'text',
            'recipient_email' => '', 'slack_webhook' => '',
            'teams_webhook' => '', 'enabled' => 1,
            'last_run' => 0, 'last_status' => '',
            'last_error' => '', 'creator' => 2,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        return $DB->get_record('local_ai_course_assistant_radar_sched', ['id' => $id]);
    }

    /**
     * Seed a schedule, enable batch, and run the submit task once.
     *
     * @return array{0: \stdClass, 1: \stdClass} [job row, schedule row]
     */
    private function submit_one(): array {
        global $DB;
        set_config('radar_batch_enabled', 1, 'local_ai_course_assistant');
        stub_provider::$batch_supported = true;
        $sched = $this->seed_schedule();

        $this->run_task_silently(new run_meta_ai_query());

        $jobs = $DB->get_records(radar_batch_manager::TABLE);
        $this->assertCount(1, $jobs, 'submit did not create a job row');
        return [reset($jobs), $sched];
    }

    /**
     * Insert one billable CHAT assistant row, to move the chat spend cap.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $model
     * @param int $prompt
     * @param int $completion
     * @return void
     */
    private function write_chat_row(int $userid, int $courseid, string $model, int $prompt, int $completion): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_msgs', (object) [
            'conversationid'    => 1,
            'userid'            => $userid,
            'courseid'          => $courseid,
            'role'              => 'assistant',
            'message'           => '[chat]',
            'tokens_used'       => $prompt + $completion,
            'prompt_tokens'     => $prompt,
            'completion_tokens' => $completion,
            'model_name'        => $model,
            'provider'          => 'openai',
            'interaction_type'  => 'chat',
            'timecreated'       => time(),
        ]);
    }

    /**
     * Insert one billable scheduled-Radar assistant row.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $model
     * @param int $prompt
     * @param int $completion
     * @return void
     */
    private function write_meta_row(int $userid, int $courseid, string $model, int $prompt, int $completion): void {
        global $DB;
        $row = new \stdClass();
        $row->conversationid    = 1;
        $row->userid            = $userid;
        $row->courseid          = $courseid;
        $row->role              = 'assistant';
        $row->message           = '[radar]';
        $row->tokens_used       = $prompt + $completion;
        $row->prompt_tokens     = $prompt;
        $row->completion_tokens = $completion;
        $row->model_name        = $model;
        $row->provider          = 'google';
        $row->interaction_type  = 'meta_scheduled';
        $row->timecreated       = time();
        $DB->insert_record('local_ai_course_assistant_msgs', $row);
    }
}
