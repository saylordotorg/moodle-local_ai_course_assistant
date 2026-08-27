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

use local_ai_course_assistant\radar_delivery;

/**
 * Daily anomaly digest. Compares rolling windows for the metrics that admins
 * care about most — negative feedback, token spend, integrity flags — and
 * sends a digest if any metric crosses the configured threshold. Quiet by
 * default: only fires when something looks off.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_anomaly_digest extends \core\task\scheduled_task {
    public function get_name(): string {
        return \local_ai_course_assistant\branding::apply(get_string('task:run_anomaly_digest', 'local_ai_course_assistant'));
    }

    public function execute(): void {
        if (!get_config('local_ai_course_assistant', 'anomaly_digest_enabled')) {
            mtrace('  Anomaly digest: disabled, skipping.');
            return;
        }

        // Use explicit default-when-unset; ?: would fall through on the
        // string "0" and silently apply the 50% default to an admin who
        // explicitly set the threshold to 0 (alert on any change).
        $rawthresh = get_config('local_ai_course_assistant', 'anomaly_digest_threshold_pct');
        $threshold = ($rawthresh === false || $rawthresh === '') ? 50 : (int) $rawthresh;
        $alerts = [];

        // Negative ratings: 7-day window vs prior 7-day window.
        $neg = $this->compare_metric_change(
            "SELECT COUNT(id) FROM {local_ai_course_assistant_msg_ratings} WHERE rating = -1 AND timecreated >= ? AND timecreated < ?",
            7 * 86400,
            $threshold
        );
        if ($neg !== null) {
            $alerts[] = "Negative ratings up {$neg['pct']}% week-over-week ({$neg['recent']} vs {$neg['prior']} prior).";
        }

        // Token spend: 24h vs prior 24h.
        $tok = $this->compare_metric_change(
            // Billable rows via the shared predicate, so background RAG spend is
            // counted. Both windows of the comparison use this same SQL, so the
            // day-over-day baseline moves with it.
            "SELECT COALESCE(SUM(COALESCE(m.prompt_tokens,0)+COALESCE(m.completion_tokens,0)),0) "
            . "FROM {local_ai_course_assistant_msgs} m WHERE "
            . \local_ai_course_assistant\analytics::spend_rows_predicate('m')
            . " AND m.timecreated >= ? AND m.timecreated < ?",
            86400,
            $threshold
        );
        if ($tok !== null) {
            // v7.1.2: an absolute floor, because a percentage on a small base is
            // noise. Learn's daily token volume swings between roughly 1.7M and
            // 3.7M with nothing behind it, which clears 50% day-over-day
            // routinely -- and the day that triggered the 76% alert cost $1.33
            // in total. Percentages cannot tell "doubled" from "doubled and
            // worth worrying about"; money can.
            $floor = self::floor_usd();
            $cost = $floor > 0 ? self::window_cost_usd(86400) : null;

            if ($floor > 0 && $cost !== null && $cost < $floor) {
                mtrace(sprintf(
                    '  Token spend up %s%% but the last 24h cost $%.2f, below the $%.2f floor: not alerting.',
                    $tok['pct'],
                    $cost,
                    $floor
                ));
            } else {
                $line = "Token spend up {$tok['pct']}% day-over-day ({$tok['recent']} vs {$tok['prior']} prior).";
                if ($cost !== null) {
                    $line .= sprintf(' Last 24h cost about $%.2f.', $cost);
                }
                $alerts[] = $line;
            }
        }

        // New integrity flags in last 24h (any count above floor triggers).
        global $DB;
        $floor = 5;
        try {
            $flags = (int) $DB->count_records_select(
                'local_ai_course_assistant_audit',
                'event = ? AND timecreated >= ?',
                ['integrity_flagged', time() - 86400]
            );
            if ($flags >= $floor) {
                $alerts[] = "Integrity-flag spike: {$flags} new flags in the last 24h.";
            }
        } catch (\Throwable $e) {
            // Audit table may not exist on all installs.
        }

        if (empty($alerts)) {
            mtrace('  Anomaly digest: nothing exceeds threshold, no alert sent.');
            return;
        }

        $body = "SOLA Anomaly Digest\n\n" . implode("\n", array_map(function ($a) {
            return '• ' . $a;
        }, $alerts)) . "\n\nReview Learning Radar for detail.";
        $meta = ['threshold_pct' => $threshold];

        $delivered = false;
        $emailto = (string) (get_config('local_ai_course_assistant', 'anomaly_digest_recipient_email') ?: '');
        if ($emailto !== '') {
            mtrace('  Anomaly digest: emailing ' . $emailto);
            $delivered = radar_delivery::send_email($emailto, 'Anomaly digest', $body, 'text', 'Anomaly digest', $meta) || $delivered;
        }
        $slack = (string) (get_config('local_ai_course_assistant', 'anomaly_digest_slack_webhook') ?: '');
        if ($slack !== '') {
            mtrace('  Anomaly digest: posting to Slack');
            $delivered = radar_delivery::send_slack($slack, 'Anomaly digest', $body, $meta) || $delivered;
        }
        $teams = (string) (get_config('local_ai_course_assistant', 'anomaly_digest_teams_webhook') ?: '');
        if ($teams !== '') {
            mtrace('  Anomaly digest: posting to Teams');
            $delivered = radar_delivery::send_teams($teams, 'Anomaly digest', $body, $meta) || $delivered;
        }

        if (!$delivered) {
            $admin = get_admin();
            mtrace('  Anomaly digest: no destination configured, falling back to admin email.');
            radar_delivery::send_email($admin->email, 'Anomaly digest', $body, 'text', 'Anomaly digest', $meta);
        }
    }

    /**
     * Compare the value of a metric across two consecutive windows.
     *
     * @param string $sql Single-aggregate SQL with two ? placeholders (start, end).
     * @param int $windowsec Window length in seconds.
     * @param int $thresholdpct Percent change that triggers an alert.
     * @return array|null ['recent', 'prior', 'pct'] or null when within threshold.
     */
    private function compare_metric_change(string $sql, int $windowsec, int $thresholdpct): ?array {
        global $DB;
        $now = time();
        $recent = (float) $DB->get_field_sql($sql, [$now - $windowsec, $now]);
        $prior = (float) $DB->get_field_sql($sql, [$now - 2 * $windowsec, $now - $windowsec]);
        if ($prior <= 0 && $recent <= 0) {
            return null;
        }
        if ($prior <= 0) {
            // Avoid division by zero; only alert when the recent value crosses a small floor.
            return $recent >= 5 ? ['recent' => (int) $recent, 'prior' => 0, 'pct' => 999] : null;
        }
        $pct = (int) round((($recent - $prior) / $prior) * 100);
        if ($pct < $thresholdpct) {
            return null;
        }
        return ['recent' => (int) $recent, 'prior' => (int) $prior, 'pct' => $pct];
    }

    /**
     * Configured absolute floor, in US dollars. 0 disables it.
     *
     * Defaults to 0 so an upgrade does not silently start suppressing alerts an
     * admin was relying on.
     *
     * @return float
     */
    private static function floor_usd(): float {
        $raw = get_config('local_ai_course_assistant', 'anomaly_digest_floor_usd');
        if ($raw === false || $raw === '') {
            return 0.0;
        }
        return max(0.0, (float) $raw);
    }

    /**
     * Priced spend over the last $seconds, or null if it cannot be priced.
     *
     * Returns null -- which callers treat as "do not suppress" -- when any
     * billable tokens in the window came from a model with no rate card entry.
     * Failing open matters here: a floor that silences an alert because the
     * spend was unpriceable would hide exactly the case worth seeing, which is
     * an unrecognised model appearing in the mix.
     *
     * @param int $seconds Window length.
     * @return float|null
     */
    private static function window_cost_usd(int $seconds): ?float {
        global $DB;

        $since = time() - $seconds;
        $sql = "SELECT m.model_name,
                       SUM(COALESCE(m.prompt_tokens, 0))     AS prompt_tokens,
                       SUM(COALESCE(m.completion_tokens, 0)) AS completion_tokens
                  FROM {local_ai_course_assistant_msgs} m
                 WHERE " . \local_ai_course_assistant\analytics::spend_rows_predicate('m') . "
                   AND m.timecreated >= :since
              GROUP BY m.model_name";

        try {
            $rows = $DB->get_recordset_sql($sql, ['since' => $since]);
        } catch (\Throwable $e) {
            debugging('anomaly digest: could not price the window: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }

        $total = 0.0;
        foreach ($rows as $r) {
            $tokens = (int) $r->prompt_tokens + (int) $r->completion_tokens;
            if ($tokens === 0) {
                continue;
            }
            $cost = \local_ai_course_assistant\token_cost_manager::estimate_cost(
                (string) ($r->model_name ?? ''),
                (int) $r->prompt_tokens,
                (int) $r->completion_tokens
            );
            if ($cost === null) {
                $rows->close();
                return null;
            }
            $total += (float) $cost;
        }
        $rows->close();

        return $total;
    }
}
