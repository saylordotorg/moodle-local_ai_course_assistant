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

defined('MOODLE_INTERNAL') || die();

/**
 * v6.0.0 cost-anomaly detector.
 *
 * Daily-running detector that complements the existing 80% / 95% / 100%
 * spend-cap thresholds in `spend_guard`. Catches three failure modes the
 * cap thresholds miss because they fire only when an absolute ceiling is
 * crossed:
 *
 *  1. **Runaway course** — a single course suddenly drives 10x its usual
 *     volume (viral content, copy-paste loop, mass-enrollment glitch).
 *     Spend stays well under the site cap; cap thresholds stay quiet.
 *  2. **Accidental premium-tier enable** — someone flips the v5.12
 *     premium-router on with the default 5% rate, but the trigger regex
 *     matches more turns than expected. Spend climbs for a day or two
 *     before the site cap notices.
 *  3. **Provider misroute** — a failover-chain misconfig sends all chat
 *     to Claude Haiku ($0.39/learner/mo) instead of Gemini Flash
 *     ($0.042). 10x cost spike that the cap eventually catches; the
 *     anomaly detector catches it on day 1.
 *
 * Algorithm: compute today's site-wide SOLA spend in USD by aggregating
 * tokens from `local_ai_course_assistant_msgs` × `token_cost_manager`
 * rates. Compute the same for each of the prior 7 days. Take the median
 * of the prior 7 days. If today exceeds MULTIPLIER × median, emit an
 * alert email to the `spend_notify_emails` recipient list (falling back
 * to site admins).
 *
 * The detector is **off by default**; admins opt in via the
 * `cost_anomaly_enabled` setting. When on, the scheduled task
 * `cost_anomaly_check` runs daily (default cron schedule: 09:05 every
 * day; admins can adjust via the standard Moodle scheduled-task UI).
 *
 * Idempotency: a per-day notification flag in `config_plugins`
 * (`cost_anomaly_notified_<YYYY-MM-DD>`) prevents duplicate emails if
 * cron runs the task more than once on a single day.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cost_anomaly_detector {

    /** @var int Minimum days of history required before evaluating an anomaly. */
    public const MIN_HISTORY_DAYS = 7;

    /** @var float Default multiplier (today must exceed median by this much). */
    public const DEFAULT_MULTIPLIER = 2.0;

    /** @var int Top-N courses included in the per-course breakdown in the alert email. */
    public const TOP_COURSES_IN_EMAIL = 10;

    /**
     * Compute total SOLA spend in USD for a single UTC day.
     *
     * Sums prompt + completion tokens across every assistant message
     * `timecreated` between $daystart and $daystart + 86400, joins to
     * the rate card via `token_cost_manager`, and returns the total.
     *
     * @param int $daystart Unix timestamp at the START of the day (UTC midnight).
     * @return float USD spent that day.
     */
    public static function compute_daily_spend(int $daystart): float {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/ai_course_assistant/classes/token_cost_manager.php');

        $dayend = $daystart + 86400;
        $rows = $DB->get_records_sql(
            "SELECT " . $DB->sql_concat('m.model_name', "'_'", 'm.provider') . " AS id,
                    m.model_name AS model,
                    SUM(COALESCE(m.prompt_tokens, 0))     AS prompt,
                    SUM(COALESCE(m.completion_tokens, 0)) AS completion
               FROM {local_ai_course_assistant_msgs} m
              WHERE m.role = 'assistant'
                AND m.model_name IS NOT NULL
                AND m.timecreated >= :daystart
                AND m.timecreated <  :dayend
              GROUP BY m.model_name, m.provider",
            ['daystart' => $daystart, 'dayend' => $dayend]
        );
        $total = 0.0;
        foreach ($rows as $r) {
            $cost = token_cost_manager::estimate_cost(
                (string) $r->model,
                (int) $r->prompt,
                (int) $r->completion
            );
            if ($cost !== null) {
                $total += (float) $cost;
            }
        }
        return $total;
    }

    /**
     * Per-course spend breakdown for a single day. Used in the alert email
     * so admins can immediately see WHICH course drove a spike.
     *
     * @param int $daystart Unix timestamp at the start of the day (UTC midnight).
     * @param int $topn Cap the result at this many courses (descending by spend).
     * @return array<int, array{courseid:int, shortname:string, spend_usd:float}>
     */
    public static function per_course_spend_for_day(int $daystart, int $topn = self::TOP_COURSES_IN_EMAIL): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/ai_course_assistant/classes/token_cost_manager.php');

        $dayend = $daystart + 86400;
        $rows = $DB->get_records_sql(
            "SELECT " . $DB->sql_concat('m.courseid', "'_'", 'm.model_name', "'_'", 'm.provider') . " AS id,
                    m.courseid AS courseid,
                    m.model_name AS model,
                    SUM(COALESCE(m.prompt_tokens, 0))     AS prompt,
                    SUM(COALESCE(m.completion_tokens, 0)) AS completion
               FROM {local_ai_course_assistant_msgs} m
              WHERE m.role = 'assistant'
                AND m.model_name IS NOT NULL
                AND m.timecreated >= :daystart
                AND m.timecreated <  :dayend
              GROUP BY m.courseid, m.model_name, m.provider",
            ['daystart' => $daystart, 'dayend' => $dayend]
        );

        // Aggregate cost by courseid, then map to shortname.
        $bycourse = [];
        foreach ($rows as $r) {
            $cost = token_cost_manager::estimate_cost(
                (string) $r->model,
                (int) $r->prompt,
                (int) $r->completion
            );
            if ($cost === null) {
                continue;
            }
            $cid = (int) $r->courseid;
            $bycourse[$cid] = ($bycourse[$cid] ?? 0.0) + (float) $cost;
        }
        arsort($bycourse);
        $top = array_slice($bycourse, 0, $topn, true);

        $out = [];
        foreach ($top as $cid => $spend) {
            $shortname = '<unknown>';
            $course = $DB->get_record('course', ['id' => $cid], 'shortname', IGNORE_MISSING);
            if ($course) {
                $shortname = $course->shortname;
            }
            $out[] = [
                'courseid' => $cid,
                'shortname' => $shortname,
                'spend_usd' => round($spend, 4),
            ];
        }
        return $out;
    }

    /**
     * Compute the median of an array of floats. Returns 0.0 for an empty
     * input. Linear-sort implementation; the inputs here are always 7
     * floats so an asymptotically faster median is not worth the code.
     *
     * @param float[] $values
     * @return float
     */
    public static function median(array $values): float {
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        if ($n % 2 === 1) {
            return (float) $values[intdiv($n, 2)];
        }
        return ((float) $values[$n / 2 - 1] + (float) $values[$n / 2]) / 2.0;
    }

    /**
     * Build the day-start timestamp for a given offset from "today".
     * Offset 0 = today's UTC midnight, 1 = yesterday's, etc.
     *
     * @param int $offset
     * @return int Unix timestamp at UTC midnight.
     */
    public static function utc_day_start(int $offset = 0): int {
        $now = time();
        $todayutc = strtotime(gmdate('Y-m-d', $now) . ' 00:00:00 UTC');
        return $todayutc - ($offset * 86400);
    }

    /**
     * Evaluate today's spend against the rolling 7-day median.
     *
     * @return array {
     *     status: string,         // 'ok' | 'anomaly' | 'insufficient_history' | 'disabled' | 'no_recipients'
     *     today_usd: float,
     *     median_usd: float,
     *     ratio: float,           // today / median; 0.0 if insufficient history
     *     multiplier: float,      // configured threshold
     *     window_days: int,       // number of prior days actually available
     *     top_courses: array,     // per-course breakdown (only populated on anomaly)
     * }
     */
    public static function evaluate(): array {
        $enabled = (bool) get_config('local_ai_course_assistant', 'cost_anomaly_enabled');
        if (!$enabled) {
            return [
                'status' => 'disabled',
                'today_usd' => 0.0, 'median_usd' => 0.0,
                'ratio' => 0.0, 'multiplier' => self::DEFAULT_MULTIPLIER,
                'window_days' => 0, 'top_courses' => [],
            ];
        }

        $rawmult = get_config('local_ai_course_assistant', 'cost_anomaly_multiplier');
        $multiplier = ($rawmult === false || $rawmult === '') ? self::DEFAULT_MULTIPLIER : (float) $rawmult;
        if ($multiplier < 1.0) {
            $multiplier = self::DEFAULT_MULTIPLIER;
        }

        $today = self::compute_daily_spend(self::utc_day_start(0));
        $prior = [];
        for ($d = 1; $d <= self::MIN_HISTORY_DAYS; $d++) {
            $prior[] = self::compute_daily_spend(self::utc_day_start($d));
        }

        // Insufficient-history shape: the first 7 days after the feature is
        // enabled produce $0 medians because there's no historical data yet.
        // Detect by counting how many of the prior days had nonzero spend.
        $nonzero = count(array_filter($prior, fn($v) => $v > 0));
        if ($nonzero < self::MIN_HISTORY_DAYS) {
            return [
                'status' => 'insufficient_history',
                'today_usd' => round($today, 4),
                'median_usd' => 0.0,
                'ratio' => 0.0,
                'multiplier' => $multiplier,
                'window_days' => $nonzero,
                'top_courses' => [],
            ];
        }

        $median = self::median($prior);
        $ratio = $median > 0 ? ($today / $median) : 0.0;

        if ($today > $multiplier * $median) {
            return [
                'status' => 'anomaly',
                'today_usd' => round($today, 4),
                'median_usd' => round($median, 4),
                'ratio' => round($ratio, 2),
                'multiplier' => $multiplier,
                'window_days' => self::MIN_HISTORY_DAYS,
                'top_courses' => self::per_course_spend_for_day(self::utc_day_start(0)),
            ];
        }

        return [
            'status' => 'ok',
            'today_usd' => round($today, 4),
            'median_usd' => round($median, 4),
            'ratio' => round($ratio, 2),
            'multiplier' => $multiplier,
            'window_days' => self::MIN_HISTORY_DAYS,
            'top_courses' => [],
        ];
    }

    /**
     * Send the anomaly email to configured recipients. Idempotent: writes
     * a per-day flag into config_plugins so a second cron tick on the same
     * day does not re-send.
     *
     * @param array $eval Output of self::evaluate().
     * @return bool True if an email was sent; false if skipped (status != anomaly,
     *              no recipients, or already-notified-today flag set).
     */
    public static function maybe_send_alert(array $eval): bool {
        if (($eval['status'] ?? '') !== 'anomaly') {
            return false;
        }

        $today = gmdate('Y-m-d', time());
        $flagkey = 'cost_anomaly_notified_' . $today;
        if (get_config('local_ai_course_assistant', $flagkey)) {
            return false;
        }

        $recipients = trim((string) (get_config('local_ai_course_assistant', 'spend_notify_emails') ?: ''));
        if ($recipients === '') {
            // Fall back to site admins, matching spend_guard's behaviour.
            $admins = get_admins();
            $recipients = implode(',', array_map(fn($a) => $a->email, $admins));
        }
        if ($recipients === '') {
            return false;
        }

        $subject = '[SOLA cost anomaly] Today ($' . number_format($eval['today_usd'], 2)
            . ') is ' . $eval['ratio'] . 'x the rolling 7-day median ($'
            . number_format($eval['median_usd'], 2) . ')';

        $body  = "SOLA daily cost anomaly detected.\n\n";
        $body .= sprintf("Today's spend:      \$%.4f\n", $eval['today_usd']);
        $body .= sprintf("7-day median:       \$%.4f\n", $eval['median_usd']);
        $body .= sprintf("Ratio:              %.2fx\n", $eval['ratio']);
        $body .= sprintf("Alert threshold:    %.2fx (configurable via cost_anomaly_multiplier)\n\n", $eval['multiplier']);

        if (!empty($eval['top_courses'])) {
            $body .= "Top courses by spend today:\n";
            foreach ($eval['top_courses'] as $c) {
                $body .= sprintf("  %-40s \$%.4f  (courseid=%d)\n",
                    substr($c['shortname'] ?? '<unknown>', 0, 40),
                    $c['spend_usd'],
                    $c['courseid']
                );
            }
            $body .= "\n";
        }

        $body .= "Three failure modes this detector catches that the existing spend caps miss:\n";
        $body .= "  1. Runaway course (viral / copy-paste / mass-enrollment) — site cap stays quiet because absolute spend is still under the ceiling.\n";
        $body .= "  2. Accidental premium-tier enable (v5.12 router on with broader-than-expected regex matches).\n";
        $body .= "  3. Provider misroute (e.g. failover chain sending chat to Claude Haiku instead of Gemini Flash).\n\n";
        $body .= "Adjust the multiplier or disable the detector at:\n";
        $body .= "  Site administration > Plugins > Local plugins > AI Course Assistant > Cost anomaly detector.\n";
        $body .= "Unsubscribe (cost-alerts): https://help.saylor.org/sola/email-preferences\n";

        $sent = false;
        foreach (array_filter(array_map('trim', explode(',', $recipients))) as $email) {
            try {
                if (email_optout::is_opted_out($email, email_optout::TYPE_SPEND_ALERT)) {
                    continue;
                }
            } catch (\Throwable $e) {
                // email_optout missing? Send anyway — better than swallowing the alert.
            }
            $to = new \stdClass();
            $to->email = $email;
            $to->firstname = '';
            $to->lastname = '';
            $to->id = -10;
            $to->maildisplay = true;
            $to->mailformat = 1;
            $to->firstnamephonetic = '';
            $to->lastnamephonetic = '';
            $to->middlename = '';
            $to->alternatename = '';
            try {
                if (\email_to_user($to, \core_user::get_noreply_user(), $subject, $body)) {
                    $sent = true;
                }
            } catch (\Throwable $e) {
                debugging('cost_anomaly_detector email send failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        if ($sent) {
            set_config($flagkey, '1', 'local_ai_course_assistant');
            // Audit row for SOC 2 trail.
            try {
                global $DB;
                $DB->insert_record('local_ai_course_assistant_audit', (object) [
                    'action' => 'cost_anomaly_alert',
                    'userid' => 0,
                    'courseid' => 0,
                    'ipaddress' => '',
                    'useragent' => 'cli/scheduled_task',
                    'details' => json_encode($eval),
                    'timecreated' => time(),
                ]);
            } catch (\Throwable $e) {
                // Non-critical.
            }
        }
        return $sent;
    }
}
