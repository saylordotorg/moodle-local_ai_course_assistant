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

/**
 * Scheduled task definitions for local_ai_course_assistant.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025-2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => \local_ai_course_assistant\task\send_reminders::class,
        'blocking' => 0,
        'minute' => '0',
        'hour' => '8',       // Run at 8 AM server time.
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => \local_ai_course_assistant\task\index_course_content::class,
        'blocking' => 0,
        'minute' => '0',
        'hour' => '2',       // Run at 2 AM server time.
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => \local_ai_course_assistant\task\send_inactivity_reminders::class,
        'blocking' => 0,
        'minute' => '0',
        'hour' => '9',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '1',
    ],
    [
        'classname' => \local_ai_course_assistant\task\run_integrity_checks::class,
        'blocking' => 0,
        'minute' => '0',
        'hour' => '3',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => \local_ai_course_assistant\task\run_meta_ai_query::class,
        'blocking' => 0,
        'minute' => '0',
        'hour' => '6',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => \local_ai_course_assistant\task\audit_cleanup::class,
        'blocking' => 0,
        'minute' => '30',
        'hour' => '4',       // Daily at 4:30 AM server time.
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => \local_ai_course_assistant\task\conversation_retention::class,
        'blocking' => 0,
        'minute' => '45',
        'hour' => '4',       // Daily at 4:45 AM server time.
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // v6.8.18 Soapbox: delete recording objects past their retention window
        // and prune to the per-assignment stored-attempts cap. Transcript and
        // score are retained; only the heavy video/audio object is removed.
        'classname' => \local_ai_course_assistant\task\soapbox_cleanup::class,
        'blocking' => 0,
        'minute' => '50',
        'hour' => '4',       // Daily at 4:50 AM server time.
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // Weekly digest email to course authors / instructional designers
        // for every course with the per-course digest toggle on.
        'classname' => \local_ai_course_assistant\task\instructor_weekly_digest::class,
        'blocking' => 0,
        'minute' => '0',
        'hour' => '9',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '1',  // Mondays at 09:00 server time.
    ],
    [
        // v4.0 / M3 — Per-learner weekly digest. Opt-in only; only fires for
        // (userid, courseid) pairs where mastery is enabled and the learner
        // has the digest_optin user preference set to '1' on that course.
        'classname' => \local_ai_course_assistant\task\learner_weekly_digest::class,
        'blocking' => 0,
        'minute' => '15',
        'hour' => '9',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '1',  // Mondays at 09:15 server time.
    ],
    [
        // v4.2 — Daily anomaly digest. Quiet by default; only fires when the
        // configured metrics exceed the threshold. Runs at 07:00 server time
        // so the digest lands before most admins start work.
        'classname' => \local_ai_course_assistant\task\run_anomaly_digest::class,
        'blocking' => 0,
        'minute' => '0',
        'hour' => '7',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // v4.7.0 — Weekly rate-card auto-refresh from upstream pricing JSON
        // (default LiteLLM). Default ON. Admins disable in plugin settings.
        // Runs Mondays at 02:30 server time, before the rest of the cron
        // tasks compete for the LLM scheduler window.
        'classname' => \local_ai_course_assistant\task\refresh_rate_card::class,
        'blocking' => 0,
        'minute' => '30',
        'hour' => '2',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '1',
    ],
    [
        // v4.8.0 — Daily RAG drift auto-reindex. Re-indexes modules whose
        // source content was edited since the last indexed-at time. Runs
        // at 02:45 (just after the weekly rate-card refresh window) so
        // they do not compete for the LLM scheduler.
        'classname' => \local_ai_course_assistant\task\auto_reindex_rag_drifted::class,
        'blocking' => 0,
        'minute' => '45',
        'hour' => '2',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // v4.10.0 — Hourly sweep that closes avatar sessions left open
        // past MAX_OPEN_SECONDS (default 1h). Without this, a tab-close
        // mid-session would leave the row open and skew the analytics.
        'classname' => \local_ai_course_assistant\task\sweep_avatar_sessions::class,
        'blocking' => 0,
        'minute' => '15',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // v5.0.0 patch 3 — Daily prompt-budget auto-tune. Default off
        // (admin opts in via `prompt_budget_auto_tune`); when on, applies
        // the recommendation surfaced on the metrics admin page.
        'classname' => \local_ai_course_assistant\task\auto_tune_prompt_budget::class,
        'blocking' => 0,
        'minute' => '20',
        'hour' => '3',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // v5.3.0 — Daily milestone reflections (7-day, 30-day, completion).
        // Sends only when per-feature kill switch + per-learner consent +
        // 7-day cooldown all clear. Default off at the outreach_master
        // level so a fresh install never emails until an admin enables it.
        'classname' => \local_ai_course_assistant\task\milestone_check::class,
        'blocking' => 0,
        'minute' => '30',
        'hour' => '8',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // v5.3.0 — Hourly aggregation of stage-1 struggle signals into
        // per-session labels. Output is private learner-memory notes,
        // never an email. Default disabled via struggle_classifier_enabled.
        'classname' => \local_ai_course_assistant\task\struggle_signal_review::class,
        'blocking' => 0,
        'minute' => '40',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // v5.7.0 — Daily rebuild of the cross-course objective link table
        // for the mastery rollup. Runs at 03:10 server time, after objective
        // seeding and before the morning digest tasks.
        'classname' => \local_ai_course_assistant\task\rebuild_objective_links::class,
        'blocking' => 0,
        'minute' => '10',
        'hour' => '3',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // v6.0.0 — Daily cost anomaly check. Compares today's site-wide
        // SOLA spend against the rolling 7-day median. Emails the
        // spend_notify_emails recipient list (falls back to site admins)
        // when today > cost_anomaly_multiplier × median. Off by default;
        // enable via cost_anomaly_enabled. Idempotent: per-day flag in
        // config_plugins prevents duplicate emails if cron runs multiple
        // times on the same day. Runs at 09:05 so morning admin triage
        // can act on alerts that landed overnight.
        'classname' => \local_ai_course_assistant\task\cost_anomaly_check::class,
        'blocking' => 0,
        'minute' => '5',
        'hour' => '9',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // v6.4.0 — Daily signed policy bundle sync. Fetches the JSON
        // envelope from policy_bundle_url, verifies the Ed25519 signature
        // against policy_bundle_pubkey, enforces the settings allowlist
        // and monotonic version, and applies the contained behavior
        // settings with an audit row. Off by default; enable via
        // policy_bundle_enabled. Runs at 06:20 so bundles published the
        // previous day are live before the teaching morning.
        'classname' => \local_ai_course_assistant\task\policy_bundle_sync::class,
        'blocking' => 0,
        'minute' => '20',
        'hour' => '6',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
