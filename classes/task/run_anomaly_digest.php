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
use local_ai_course_assistant\secrets;

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
            $threshold,
            self::NEG_MIN_COUNT,
            self::NEG_MIN_RISE
        );
        if ($neg !== null) {
            $line = "Negative ratings up {$neg['pct']}% week-over-week ({$neg['recent']} vs {$neg['prior']} prior).";
            $alerts['negative_ratings'] = ['line' => $line, 'value' => $neg['recent'], 'detail' => $this->negative_rating_detail()];
        }

        // Token spend: 24h vs prior 24h.
        $tok = $this->compare_metric_change(
            // Billable rows via the shared predicate, so background RAG spend is
            // counted. Both windows of the comparison use this same SQL, so the
            // day-over-day baseline moves with it.
            "SELECT COALESCE(SUM(COALESCE(m.prompt_tokens,0)+COALESCE(m.completion_tokens,0)),0) "
            . "FROM {local_ai_course_assistant_msgs} m WHERE "
            . \local_ai_course_assistant\analytics::spend_rows_predicate('m')
            . " AND " . \local_ai_course_assistant\analytics::benchmark_rows_excluded('m')
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
                $alerts['token_spend'] = ['line' => $line, 'value' => $tok['recent'], 'detail' => []];
            }
        }

        // New integrity flags in last 24h (any count above floor triggers).
        global $DB;
        $floor = 5;
        try {
            $flags = (int) $DB->count_records_select(
                'local_ai_course_assistant_audit',
                // action, not event -- see the note in review_queue.
                'action = ? AND timecreated >= ?',
                ['integrity_flagged', time() - 86400]
            );
            if ($flags >= $floor) {
                $alerts['integrity_flags'] = [
                    'line' => "Integrity-flag spike: {$flags} new flags in the last 24h.",
                    'value' => $flags,
                    'detail' => [],
                ];
            }
        } catch (\Throwable $e) {
            // Audit table may not exist on all installs.
        }

        if (empty($alerts)) {
            mtrace('  Anomaly digest: nothing exceeds threshold, no alert sent.');
            return;
        }

        // A rolling window stays over the line for days after one bump, so the
        // same alert would arrive daily. Skip any alert already sent in the last
        // COOLDOWN_DAYS unless its value has risen since.
        foreach ($alerts as $key => $alert) {
            if (self::in_cooldown($key, (int) $alert['value'])) {
                mtrace("  Anomaly digest: {$key} already sent recently and no worse, skipping.");
                unset($alerts[$key]);
            }
        }
        if (empty($alerts)) {
            mtrace('  Anomaly digest: every alert is inside its cooldown, nothing sent.');
            return;
        }

        // Slack and Teams webhooks leave the site, so they get the counts and
        // course names only; learners' own words go to the email recipient.
        $body = self::build_body($alerts, false);
        $emailbody = self::build_body($alerts, true);
        $meta = ['threshold_pct' => $threshold];

        $delivered = false;
        $emailto = (string) (get_config('local_ai_course_assistant', 'anomaly_digest_recipient_email') ?: '');
        if ($emailto !== '') {
            mtrace('  Anomaly digest: emailing ' . $emailto);
            $delivered = radar_delivery::send_email($emailto, 'Anomaly digest', $emailbody, 'text', 'Anomaly digest', $meta) || $delivered;
        }
        $slack = secrets::get('anomaly_digest_slack_webhook');
        if ($slack !== '') {
            mtrace('  Anomaly digest: posting to Slack');
            $delivered = radar_delivery::send_slack($slack, 'Anomaly digest', $body, $meta) || $delivered;
        }
        $teams = secrets::get('anomaly_digest_teams_webhook');
        if ($teams !== '') {
            mtrace('  Anomaly digest: posting to Teams');
            $delivered = radar_delivery::send_teams($teams, 'Anomaly digest', $body, $meta) || $delivered;
        }

        if (!$delivered) {
            $admin = get_admin();
            mtrace('  Anomaly digest: no destination configured, falling back to admin email.');
            $delivered = radar_delivery::send_email($admin->email, 'Anomaly digest', $emailbody, 'text', 'Anomaly digest', $meta);
        }

        if ($delivered) {
            foreach ($alerts as $key => $alert) {
                self::remember_sent($key, (int) $alert['value']);
            }
        }
    }

    /** Fewest negative ratings in the recent week that can raise an alert. */
    private const NEG_MIN_COUNT = 10;

    /** Fewest extra negative ratings, over the prior week, that can raise an alert. */
    private const NEG_MIN_RISE = 5;

    /** Days an alert stays quiet after it was sent, unless its value rises. */
    private const COOLDOWN_DAYS = 3;

    /**
     * Courses with the most negative ratings in the last 7 days, and a few of
     * the written reasons.
     *
     * @return array ['courses' => [name => count], 'comments' => [string]]
     */
    private function negative_rating_detail(): array {
        global $DB;
        $since = time() - 7 * 86400;
        $courses = [];
        $sql = "SELECT c.fullname, COUNT(r.id) AS n
                  FROM {local_ai_course_assistant_msg_ratings} r
                  JOIN {course} c ON c.id = r.courseid
                 WHERE r.rating = -1 AND r.timecreated >= ?
              GROUP BY c.id, c.fullname
              ORDER BY n DESC, c.fullname";
        foreach ($DB->get_records_sql($sql, [$since], 0, 5) as $row) {
            $courses[format_string($row->fullname)] = (int) $row->n;
        }
        $comments = [];
        $rows = $DB->get_records_select(
            'local_ai_course_assistant_msg_ratings',
            "rating = -1 AND timecreated >= ? AND comment IS NOT NULL AND comment <> ''",
            [$since],
            'timecreated DESC',
            'id, comment',
            0,
            3
        );
        foreach ($rows as $row) {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $row->comment)));
            $comments[] = \core_text::substr($text, 0, 120) . (\core_text::strlen($text) > 120 ? '...' : '');
        }
        return ['courses' => $courses, 'comments' => $comments];
    }

    /**
     * Build the digest text.
     *
     * @param array $alerts key => ['line', 'value', 'detail']
     * @param bool $withcomments Include learners' written reasons (email only).
     * @return string
     */
    private static function build_body(array $alerts, bool $withcomments): string {
        $out = "SOLA Anomaly Digest\n";
        foreach ($alerts as $alert) {
            $out .= "\n• " . $alert['line'] . "\n";
            $detail = $alert['detail'];
            if (!empty($detail['courses'])) {
                $parts = [];
                foreach ($detail['courses'] as $name => $n) {
                    $parts[] = "{$name} ({$n})";
                }
                $out .= '  Most thumbs-down, by course: ' . implode('; ', $parts) . "\n";
            }
            if ($withcomments && !empty($detail['comments'])) {
                $out .= "  Recent reasons given:\n";
                foreach ($detail['comments'] as $c) {
                    $out .= '    - "' . $c . "\"\n";
                }
            }
        }
        return $out . "\nReview Learning Radar for detail.";
    }

    /**
     * Was this alert sent within the cooldown, at a value no lower than now?
     *
     * @param string $key Alert key.
     * @param int $value Current value of the metric.
     * @return bool
     */
    private static function in_cooldown(string $key, int $value): bool {
        $raw = get_config('local_ai_course_assistant', 'anomaly_digest_last_' . $key);
        $last = $raw ? json_decode($raw, true) : null;
        if (!is_array($last) || !isset($last['t'], $last['v'])) {
            return false;
        }
        return (time() - (int) $last['t']) < self::COOLDOWN_DAYS * DAYSECS && $value <= (int) $last['v'];
    }

    /**
     * Record that an alert went out.
     *
     * @param string $key Alert key.
     * @param int $value Value of the metric when sent.
     * @return void
     */
    private static function remember_sent(string $key, int $value): void {
        set_config('anomaly_digest_last_' . $key, json_encode(['t' => time(), 'v' => $value]), 'local_ai_course_assistant');
    }

    /**
     * Compare the value of a metric across two consecutive windows.
     *
     * @param string $sql Single-aggregate SQL with two ? placeholders (start, end).
     * @param int $windowsec Window length in seconds.
     * @param int $thresholdpct Percent change that triggers an alert.
     * @param int $mincount Smallest recent value that can alert (0 = no minimum). A percentage on
     *                      a handful of events is noise: 1 to 4 is "+300%".
     * @param int $minrise Smallest rise over the prior window that can alert (0 = no minimum).
     * @return array|null ['recent', 'prior', 'pct'] or null when within threshold.
     */
    private function compare_metric_change(
        string $sql,
        int $windowsec,
        int $thresholdpct,
        int $mincount = 0,
        int $minrise = 0
    ): ?array {
        global $DB;
        $now = time();
        $recent = (float) $DB->get_field_sql($sql, [$now - $windowsec, $now]);
        $prior = (float) $DB->get_field_sql($sql, [$now - 2 * $windowsec, $now - $windowsec]);
        if ($prior <= 0 && $recent <= 0) {
            return null;
        }
        if ($prior <= 0) {
            // Avoid division by zero; only alert when the recent value crosses a small floor.
            return $recent >= max(5, $mincount) ? ['recent' => (int) $recent, 'prior' => 0, 'pct' => 999] : null;
        }
        $pct = (int) round((($recent - $prior) / $prior) * 100);
        if ($pct < $thresholdpct || $recent < $mincount || ($recent - $prior) < $minrise) {
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
     * an unrecognized model appearing in the mix.
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
                 WHERE " . \local_ai_course_assistant\analytics::spend_rows_predicate('m')
                     . " AND " . \local_ai_course_assistant\analytics::benchmark_rows_excluded('m') . "
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
