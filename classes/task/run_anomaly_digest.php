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
            7 * 86400, $threshold);
        if ($neg !== null) {
            $alerts[] = "Negative ratings up {$neg['pct']}% week-over-week ({$neg['recent']} vs {$neg['prior']} prior).";
        }

        // Token spend: 24h vs prior 24h.
        $tok = $this->compare_metric_change(
            "SELECT COALESCE(SUM(COALESCE(prompt_tokens,0)+COALESCE(completion_tokens,0)),0) "
            . "FROM {local_ai_course_assistant_msgs} WHERE role='assistant' AND timecreated >= ? AND timecreated < ?",
            86400, $threshold);
        if ($tok !== null) {
            $alerts[] = "Token spend up {$tok['pct']}% day-over-day ({$tok['recent']} vs {$tok['prior']} prior).";
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
}
