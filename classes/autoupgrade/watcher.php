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

namespace local_ai_course_assistant\autoupgrade;

use local_ai_course_assistant\analytics;
use local_ai_course_assistant\token_cost_manager;

/**
 * Watches a switched role for WATCH_HOURS and rolls it back if live traffic
 * gets worse (v7.8.0).
 *
 * The evaluation measured the new model on fixed prompts; this measures it on
 * the real ones. Both windows come from the msgs table, as aggregates only:
 * the baseline is the old model's last BASELINE_DAYS of answers before the
 * switch, the live window is the new model's answers since. Four signals, each
 * a rate or a mean per answer, compared old against new:
 *
 *   errors     turns that failed at the provider (stream_outcome provider_error)
 *   truncated  answers cut off at the answer budget
 *   refused    answers the model's own safety layer declined
 *   cost       cost per answer at registry prices, thinking included
 *
 * A rate rolls back when ALL of: the live rate is at least the floor above
 * the baseline in absolute terms (errors and refusals 2 points, truncation 3),
 * the rise is significant at one-sided 1% (z > 2.33, two-proportion test), and
 * at least 3 such turns happened. One of those alone is noise on the volumes a
 * single site sees in 48 hours: at 200 answers a 1% error rate produces 0 to 5
 * errors by chance, and a rollback should mean the model is worse, not that
 * the dice landed badly. Cost rolls back above 1.25 x the baseline per answer:
 * the evaluation already showed same-or-cheaper on fixed prompts, so a 25%
 * excess on real ones is a real difference in how the model answers, while
 * smaller swings follow the mix of courses and questions in the window.
 *
 * Nothing is judged before MIN_TURNS live answers. With no usable baseline
 * (under MIN_TURNS answers in the week before), only an absolute error floor
 * applies: 10% of answers failing with at least 5 failures.
 *
 * A switch whose settings were changed by someone else is marked superseded
 * and left alone: rolling back over a human's later decision is not this
 * class's call.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class watcher {
    /** @var int Hours a switch is watched. */
    public const WATCH_HOURS = 48;

    /** @var int Days of pre-switch traffic in the baseline. */
    public const BASELINE_DAYS = 7;

    /** @var int Live answers needed before any verdict. */
    public const MIN_TURNS = 30;

    /** @var float One-sided 1% critical value. */
    public const Z_CRITICAL = 2.33;

    /** @var int Fewest events of a kind that can trigger a rollback. */
    public const MIN_EVENTS = 3;

    /** @var array<string, float> Absolute rise needed per rate, as a fraction. */
    public const FLOORS = ['error' => 0.02, 'truncated' => 0.03, 'refused' => 0.02];

    /** @var float Cost per answer may rise to this multiple of the baseline. */
    public const COST_RATIO = 1.25;

    /** @var float Error rate that rolls back even with no baseline. */
    public const ABSOLUTE_ERROR_RATE = 0.10;

    /** @var int Errors needed for that. */
    public const ABSOLUTE_MIN_ERRORS = 5;

    /**
     * Aggregate live metrics for a model over a window.
     *
     * A row belongs to the model when its model_name is the configured id or a
     * dated snapshot of it (providers answer "gpt-5-mini-2025-08-07" to a
     * request for "gpt-5-mini"). Benchmark and evaluation rows are excluded.
     * Failures the failover chain rescued count as this model's turns and
     * errors, from the chain's audit rows: a switched model that fails on
     * every call would otherwise look like a model with no traffic.
     *
     * @param string $provider
     * @param string $model
     * @param int $from
     * @param int $to
     * @return array{turns: int, error: int, truncated: int, refused: int, cost_cents: ?float}
     */
    public static function metrics(string $provider, string $model, int $from, int $to): array {
        global $DB;
        $params = [
            'role' => 'assistant',
            'from' => $from,
            'to' => $to,
            'model' => $model,
            'dated' => $DB->sql_like_escape($model) . '-20%',
            'provider' => $provider,
        ];
        $where = "m.role = :role AND m.timecreated >= :from AND m.timecreated < :to
                  AND (m.model_name = :model OR " . $DB->sql_like('m.model_name', ':dated', false) . ")
                  AND (m.provider = :provider OR m.provider IS NULL OR m.provider = '')
                  AND " . analytics::benchmark_rows_excluded('m');
        $counts = $DB->get_record_sql(
            "SELECT COUNT(1) AS turns,
                    SUM(CASE WHEN m.stream_outcome = 'provider_error' THEN 1 ELSE 0 END) AS errors,
                    SUM(CASE WHEN m.stream_outcome = 'truncated' THEN 1 ELSE 0 END) AS truncated,
                    SUM(CASE WHEN m.stream_outcome = 'refused' THEN 1 ELSE 0 END) AS refused
               FROM {local_ai_course_assistant_msgs} m
              WHERE {$where}",
            $params
        );
        $cents = 0.0;
        $answered = 0;
        $unknown = false;
        $rs = $DB->get_recordset_sql(
            "SELECT m.model_name, COUNT(1) AS n, SUM(COALESCE(m.prompt_tokens, 0)) AS p,
                    SUM(COALESCE(m.completion_tokens, 0)) AS c, SUM(COALESCE(m.reasoning_tokens, 0)) AS r
               FROM {local_ai_course_assistant_msgs} m
              WHERE {$where} AND COALESCE(m.prompt_tokens, 0) > 0
           GROUP BY m.model_name",
            $params
        );
        foreach ($rs as $row) {
            $usd = token_cost_manager::estimate_cost((string) $row->model_name, (int) $row->p, (int) $row->c, (int) $row->r);
            if ($usd === null) {
                $unknown = true;
                continue;
            }
            $cents += $usd * 100;
            $answered += (int) $row->n;
        }
        $rs->close();
        // A turn the failover chain rescued is a failure of this model even
        // though the learner was answered: the answer row names the fallback.
        // The chain writes one row per rescued turn, naming the primary that
        // failed; a turn the whole chain failed is a failed-turn row instead.
        $rescued = (int) $DB->count_records_select(
            'local_ai_course_assistant_audit',
            'action = :action AND timecreated >= :from AND timecreated < :to AND '
                . $DB->sql_like('details', ':needle', false),
            ['action' => \local_ai_course_assistant\provider\failover_chain::AUDIT_EVENT_RESCUED, 'from' => $from,
            'to' => $to,
            'needle' => '%' . $DB->sql_like_escape('"failed_model":' . json_encode($model)) . '%']
        );
        return [
            'turns' => (int) ($counts->turns ?? 0) + $rescued,
            'error' => (int) ($counts->errors ?? 0) + $rescued,
            'truncated' => (int) ($counts->truncated ?? 0),
            'refused' => (int) ($counts->refused ?? 0),
            'cost_cents' => (!$unknown && $answered > 0) ? $cents / $answered : null,
        ];
    }

    /**
     * Should live traffic roll the switch back? Pure.
     *
     * @param array $base Baseline metrics.
     * @param array $live Live metrics.
     * @return string|null Reason, or null to keep.
     */
    public static function decide(array $base, array $live): ?string {
        $n2 = (int) $live['turns'];
        if ($n2 < self::MIN_TURNS) {
            return null;
        }
        $n1 = (int) $base['turns'];
        if ($n1 < self::MIN_TURNS) {
            $rate = $live['error'] / $n2;
            if ($live['error'] >= self::ABSOLUTE_MIN_ERRORS && $rate >= self::ABSOLUTE_ERROR_RATE) {
                return sprintf(
                    'error rate %.1f%% (%d of %d answers) with no usable baseline',
                    100 * $rate,
                    $live['error'],
                    $n2
                );
            }
            return null;
        }
        foreach (self::FLOORS as $kind => $floor) {
            $x1 = (int) $base[$kind];
            $x2 = (int) $live[$kind];
            $p1 = $x1 / $n1;
            $p2 = $x2 / $n2;
            if ($x2 < self::MIN_EVENTS || $p2 - $p1 < $floor) {
                continue;
            }
            $pool = ($x1 + $x2) / ($n1 + $n2);
            $se = sqrt($pool * (1 - $pool) * (1 / $n1 + 1 / $n2));
            $z = $se > 0 ? ($p2 - $p1) / $se : INF;
            if ($z > self::Z_CRITICAL) {
                return sprintf(
                    '%s rate %.1f%% against %.1f%% before the switch (%d of %d answers, z = %.1f)',
                    $kind,
                    100 * $p2,
                    100 * $p1,
                    $x2,
                    $n2,
                    $z
                );
            }
        }
        if (
            $base['cost_cents'] !== null && $live['cost_cents'] !== null && $base['cost_cents'] > 0
                && $live['cost_cents'] > self::COST_RATIO * $base['cost_cents']
        ) {
            return sprintf(
                'cost per answer %.4f cents against %.4f before the switch (more than %.2fx)',
                $live['cost_cents'],
                $base['cost_cents'],
                self::COST_RATIO
            );
        }
        return null;
    }

    /**
     * Check every watched switch: roll back, keep watching, or keep.
     *
     * @param int|null $now
     * @return array<int, string> switch id => what happened
     */
    public static function check_all(?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $out = [];
        foreach ($DB->get_records(switcher::TABLE, ['status' => switcher::WATCHING]) as $row) {
            $out[(int) $row->id] = self::check_one($row, $now);
        }
        return $out;
    }

    /**
     * Check one watched switch.
     *
     * @param \stdClass $row
     * @param int $now
     * @return string
     */
    public static function check_one(\stdClass $row, int $now): string {
        global $DB;
        if (!switcher::config_matches(json_decode((string) $row->newconfig, true) ?: [])) {
            $DB->update_record(switcher::TABLE, (object) ['id' => $row->id, 'status' => switcher::SUPERSEDED,
                'timeresolved' => $now]);
            return 'superseded';
        }
        $base = json_decode((string) $row->baseline, true) ?: ['turns' => 0, 'error' => 0, 'truncated' => 0,
            'refused' => 0, 'cost_cents' => null];
        $live = self::metrics((string) $row->to_provider, (string) $row->to_model, (int) $row->timecreated, $now + 1);
        $reason = self::decide($base, $live);
        if ($reason !== null) {
            switcher::rollback((int) $row->id, $reason, 0, $live);
            return 'rolledback';
        }
        if ($now >= (int) $row->watchuntil) {
            $DB->update_record(switcher::TABLE, (object) ['id' => $row->id, 'status' => switcher::KEPT,
                'livemetrics' => json_encode($live), 'timeresolved' => $now]);
            notifier::kept($row, $live, $live['turns'] < self::MIN_TURNS);
            return 'kept';
        }
        $DB->set_field(switcher::TABLE, 'livemetrics', json_encode($live), ['id' => $row->id]);
        return 'watching';
    }
}
