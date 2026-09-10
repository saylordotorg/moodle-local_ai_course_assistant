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

use local_ai_course_assistant\provider\base_provider;

/**
 * The parts of a Learning Radar run that are the same whether the answer
 * arrived synchronously or came back from an offline batch a day later.
 *
 * v7.4.4 split the scheduled Radar into a submit task and a collect task. That
 * split created a second copy of "work out the window, deliver to every
 * channel, fall back to the admin, then persist for spend" -- and the LAST time
 * this file had two copies of an outcome rule, a dead Slack webhook wore a
 * green badge indefinitely because one of them recorded 'success' on the mere
 * absence of an exception. So the shared steps live here once and both tasks
 * call them.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class radar_runner {
    /**
     * Resolve a schedule's reporting window and scope filters.
     *
     * `since` is computed ONCE here and then carried, never recomputed. On the
     * batch path it is pinned into the job row at submit time: recomputing it
     * on collection would slide the reported period by the batch turnaround, so
     * a "last 7 days" report would quietly cover days 2-8.
     *
     * @param \stdClass $sched
     * @return array{rangedays: int, since: int, courseids: int[], filterprovider: string}
     */
    public static function scope_for(\stdClass $sched): array {
        $rangedays = !empty($sched->range_days)
            ? (int) $sched->range_days
            : radar_schedule_manager::frequency_to_days((string) $sched->frequency);

        $courseids = [];
        if (!empty($sched->courseids)) {
            $courseids = array_filter(array_map('intval', explode(',', (string) $sched->courseids)));
        }

        return [
            'rangedays'      => $rangedays,
            'since'          => time() - ($rangedays * DAYSECS),
            'courseids'      => $courseids,
            'filterprovider' => (string) ($sched->filterprovider ?? ''),
        ];
    }

    /**
     * The footer metadata block attached to every delivered report.
     *
     * @param string $frequency
     * @param int    $rangedays
     * @param string $courseids  Raw comma-separated list as configured, or ''.
     * @param string $filterprovider
     * @param string $providerid Schedule provider as configured; '' means site primary.
     * @param string $modelid    Schedule model as configured; '' means provider default.
     * @param bool   $batched    True when the answer came from the offline batch tier.
     * @return array
     */
    public static function meta_for(
        string $frequency,
        int $rangedays,
        string $courseids,
        string $filterprovider,
        string $providerid,
        string $modelid,
        bool $batched = false
    ): array {
        $meta = [
            'frequency' => $frequency,
            'range_days' => $rangedays,
            'provider' => $providerid !== '' ? $providerid : 'primary',
            'model' => $modelid !== '' ? $modelid : 'default',
        ];
        if ($courseids !== '') {
            $meta['courses'] = $courseids;
        }
        if ($filterprovider !== '') {
            $meta['provider_filter'] = $filterprovider;
        }
        if ($batched) {
            // Visible in the report footer on purpose. A recipient who notices
            // the report arrived later than usual should be able to see why
            // from the report itself rather than from an admin setting they
            // cannot reach.
            $meta['tier'] = 'batch (offline, 50% rate)';
        }
        return $meta;
    }

    /**
     * Deliver one report to every configured channel, falling back to the site
     * admin so a report is never silently dropped.
     *
     * @param \stdClass $sched  Schedule row, or the batch job row: only
     *                          recipient_email / slack_webhook / teams_webhook /
     *                          format / frequency are read, and the job row
     *                          carries the first three by joining its schedule.
     * @param string    $query
     * @param string    $response
     * @param array     $meta
     * @param callable|null $trace Optional mtrace-alike for cron output.
     * @return bool True when at least one destination accepted the delivery.
     */
    public static function deliver(
        \stdClass $sched,
        string $query,
        string $response,
        array $meta,
        ?callable $trace = null
    ): bool {
        $trace = $trace ?? static function (string $unused): void {
        };
        $format = (string) ($sched->format ?: 'text');
        $prefix = 'Scheduled (' . (string) ($sched->frequency ?? '') . ')';

        $delivered = false;
        if (!empty($sched->recipient_email)) {
            $trace("emailing {$sched->recipient_email}...");
            $delivered = radar_delivery::send_email(
                (string) $sched->recipient_email,
                $query,
                $response,
                $format,
                $prefix,
                $meta
            ) || $delivered;
        }
        if (!empty($sched->slack_webhook)) {
            $trace('posting to Slack webhook...');
            $delivered = radar_delivery::send_slack((string) $sched->slack_webhook, $query, $response, $meta)
                || $delivered;
        }
        if (!empty($sched->teams_webhook)) {
            $trace('posting to Teams webhook...');
            $delivered = radar_delivery::send_teams((string) $sched->teams_webhook, $query, $response, $meta)
                || $delivered;
        }

        if (!$delivered) {
            // Fall back to the site admin so the report does not vanish -- and
            // count that fallback's own outcome, which used to be discarded.
            $admin = get_admin();
            $trace('no destination delivered; falling back to admin email.');
            $delivered = radar_delivery::send_email(
                (string) $admin->email,
                $query,
                $response,
                $format,
                $prefix,
                $meta
            );
        }

        return $delivered;
    }

    /**
     * Persist the query/response pair for export, spend and the Radar history.
     *
     * TOKENS. When the provider reported a usage block it is used verbatim,
     * including cached and reasoning counts. Before v7.4.4 both Radar paths
     * approximated every count as strlen/4 even though chat_completion() has
     * populated get_last_token_usage() since v7.0.6, so Learning Radar spend was
     * recorded but systematically mis-measured. The approximation survives only
     * as the fallback for a provider that genuinely reports nothing, and it is
     * the reason $usage is nullable rather than required.
     *
     * BATCH PRICING. A batched call records its model as `batch/<model>`, which
     * is the marker model_registry::rate_for() reads to halve the rate. Nothing
     * else in the spend pipeline needs to know batch exists.
     *
     * @param int         $userid
     * @param string      $query
     * @param string      $response
     * @param string      $providerid    Provider id to attribute the row to.
     * @param string      $modelid       Model id, without any batch marker.
     * @param array|null  $usage         Provider usage block, or null.
     * @param string      $systemprompt  Used only for the fallback approximation.
     * @param bool        $batched
     * @return void
     */
    public static function persist(
        int $userid,
        string $query,
        string $response,
        string $providerid,
        string $modelid,
        ?array $usage,
        string $systemprompt = '',
        bool $batched = false
    ): void {
        if (!empty($usage) && is_array($usage)) {
            $prompttokens = (int) ($usage['prompt_tokens'] ?? 0);
            $completiontokens = (int) ($usage['completion_tokens'] ?? 0);
            $cached = isset($usage['cached_tokens']) ? (int) $usage['cached_tokens'] : null;
            $reasoning = isset($usage['reasoning_tokens']) && $usage['reasoning_tokens'] !== null
                ? (int) $usage['reasoning_tokens']
                : null;
            // The provider that ACTUALLY served the call outranks the configured
            // one, for the reason shape_usage() records it: config says who
            // should have served the turn, and 'auto' resolution, a spend-cap
            // failover or the failover chain can all make that wrong.
            if (!empty($usage['provider'])) {
                $providerid = (string) $usage['provider'];
            }
            if (!empty($usage['model'])) {
                $modelid = (string) $usage['model'];
            }
        } else {
            $prompttokens = (int) ceil((strlen($systemprompt) + strlen($query)) / 4);
            $completiontokens = (int) ceil(strlen($response) / 4);
            $cached = null;
            $reasoning = null;
        }

        $persistedmodel = $modelid !== '' ? $modelid : 'unknown';
        if ($batched && $persistedmodel !== 'unknown') {
            $persistedmodel = token_cost_manager::batch_model_name($persistedmodel);
        }
        $persistedprovider = $providerid !== '' ? $providerid
            : (get_config('local_ai_course_assistant', 'provider') ?: 'unknown');

        conversation_manager::record_meta_query(
            $userid,
            $query,
            $response,
            $persistedprovider,
            $persistedmodel,
            $prompttokens,
            $completiontokens,
            true,
            $cached,
            $reasoning
        );
    }

    /**
     * Build the provider a schedule asks for.
     *
     * @param string $providerid Schedule provider as configured; '' = site primary.
     * @param string $modelid    Schedule model as configured; '' = provider default.
     * @param bool   $enforcespend Pass false on the COLLECT path: the money is
     *               already committed by then, and letting a spend cap swap the
     *               provider out would point the fetch at a vendor that has
     *               never heard of our batch id.
     * @return \local_ai_course_assistant\provider\provider_interface
     */
    public static function provider_for(string $providerid, string $modelid, bool $enforcespend = true) {
        if ($providerid !== '') {
            return base_provider::create_for_comparison($providerid, $modelid, 0, $enforcespend);
        }
        // The site-primary branch USED TO DROP $enforcespend on the floor:
        // create_from_config() had no such parameter, so it ran spend_guard
        // unconditionally and could swap the provider out or wrap it in a
        // failover_chain. A schedule with no explicit provider is the default
        // shape, so the one configuration the parameter was added to protect was
        // the one it did not reach.
        return base_provider::create_from_config(0, false, $enforcespend);
    }
}
