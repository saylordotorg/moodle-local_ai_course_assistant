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
 * Detects courses where learners are asking and SOLA is not answering.
 *
 * Written after a nine-day outage in August 2026. An Anthropic organisation
 * spend cap silently rejected every call for ten courses; 140 learners asked
 * 330 questions and received 9 answers, each failure showing only "Sorry,
 * something went wrong". Nothing alerted, because every existing monitor
 * watches the wrong direction:
 *
 *   - cost_anomaly_detector fires when spend goes UP. A dead provider costs
 *     nothing, so it stayed quiet for the entire outage.
 *   - spend_guard fires when a cap is approached, not when calls fail.
 *   - The failover chain would have masked it, but was unconfigured.
 *
 * The signal that WAS available the whole time is the ratio between learner
 * questions and assistant replies. In normal operation it sits near 1:1; a
 * broken provider drives it to zero and keeps it there. That is what this
 * checks.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unanswered_monitor {

    /** @var int Default lookback. Long enough to gather signal, short enough to alert same-day. */
    public const DEFAULT_WINDOW_HOURS = 6;

    /**
     * Default minimum questions before a course is judged. Below this, a run of
     * bad luck (a learner closing the tab mid-stream) looks like an outage.
     *
     * @var int
     */
    public const DEFAULT_MIN_QUESTIONS = 5;

    /**
     * Default answer rate below which a course is considered failing, as a
     * fraction. 0.5 rather than something near zero so a partial failure —
     * one provider of several down — is still caught.
     *
     * @var float
     */
    public const DEFAULT_MIN_ANSWER_RATE = 0.5;

    /** @var int Cap on courses listed in one alert email. */
    public const MAX_COURSES_IN_EMAIL = 20;

    /**
     * Default number of failed replies in the window, across the whole site, that
     * raises the alert on its own. Below the answer-rate test's reach on purpose:
     * on Learn 4% to 23% of student messages failed each day for days, which no
     * course ever dropped below 50% for. 0 turns this test off.
     *
     * @var int
     */
    public const DEFAULT_MIN_FAILED_TURNS = 3;

    /** @var string stream_outcome the chat stores on the row of a turn that failed at the provider. */
    public const FAILED_OUTCOME = 'provider_error';

    /**
     * Per-course question and answer counts inside the window.
     *
     * Counts assistant rows rather than absence of an error row, because a
     * request that dies before the exception handler leaves no audit entry at
     * all — which is exactly what happened in the August outage on the
     * streaming path. A missing answer is the reliable signal; a recorded
     * error is not.
     *
     * v7.8.8: but since failed turns began to be stored (v7.1.1) a turn that
     * failed at the provider leaves an ASSISTANT row too, with stream_outcome
     * 'provider_error' and the learner's "something went wrong" text. Counting
     * every assistant row as an answer made a provider that failed every call
     * look like 100% answered, so this alert could not fire on the very outage
     * it was written for. Those rows are now counted as `failed`, never as
     * answered.
     *
     * @param int $windowhours How far back to look.
     * @return array<int, array{courseid:int, shortname:string, asked:int, answered:int, failed:int, rate:float}>
     */
    public static function course_stats(int $windowhours): array {
        global $DB;

        $since = time() - ($windowhours * HOURSECS);
        $sql = "SELECT m.courseid,
                       COALESCE(c.shortname, '') AS shortname,
                       SUM(CASE WHEN m.role = 'user' THEN 1 ELSE 0 END) AS asked,
                       SUM(CASE WHEN m.role = 'assistant'
                                 AND (m.stream_outcome IS NULL OR m.stream_outcome <> :failed1) THEN 1 ELSE 0 END) AS answered,
                       SUM(CASE WHEN m.role = 'assistant' AND m.stream_outcome = :failed2 THEN 1 ELSE 0 END) AS failed
                  FROM {local_ai_course_assistant_msgs} m
             LEFT JOIN {course} c ON c.id = m.courseid
                 WHERE m.timecreated >= :since
                   AND m.role IN ('user', 'assistant')
              GROUP BY m.courseid, c.shortname
                HAVING SUM(CASE WHEN m.role = 'user' THEN 1 ELSE 0 END) > 0
              ORDER BY asked DESC";

        $out = [];
        $params = ['since' => $since, 'failed1' => self::FAILED_OUTCOME, 'failed2' => self::FAILED_OUTCOME];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $asked = (int) $row->asked;
            $answered = (int) $row->answered;
            $out[] = [
                'courseid'  => (int) $row->courseid,
                'shortname' => (string) $row->shortname,
                'asked'     => $asked,
                'answered'  => $answered,
                'failed'    => (int) $row->failed,
                'rate'      => $asked > 0 ? round($answered / $asked, 3) : 0.0,
            ];
        }
        return $out;
    }

    /**
     * What the provider said when replies failed in the window: the most common
     * `detail` of the sse_error audit rows (already redacted when written), so the
     * alert can name a cause and not only a count.
     *
     * @param int $windowhours How far back to look.
     * @param int $top Most causes to return.
     * @return array<int, array{detail:string, count:int}>
     */
    public static function top_causes(int $windowhours, int $top = 3): array {
        global $DB;
        $rows = $DB->get_records_select(
            'local_ai_course_assistant_audit',
            'action = :a AND timecreated >= :t',
            ['a' => 'sse_error', 't' => time() - ($windowhours * HOURSECS)],
            'id DESC',
            'id, details',
            0,
            300
        );
        $tally = [];
        foreach ($rows as $row) {
            $d = json_decode((string) $row->details, true);
            if (!is_array($d)) {
                continue;
            }
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) ($d['detail'] ?? $d['msg'] ?? '')));
            if ($text === '') {
                continue;
            }
            $text = \core_text::substr($text, 0, 140);
            $tally[$text] = ($tally[$text] ?? 0) + 1;
        }
        arsort($tally);
        $out = [];
        foreach (array_slice($tally, 0, $top, true) as $detail => $count) {
            $out[] = ['detail' => (string) $detail, 'count' => $count];
        }
        return $out;
    }

    /**
     * Evaluate the window and decide whether anything is wrong.
     *
     * @return array{status:string, window_hours:int, min_questions:int, min_rate:float,
     *               failing:array, checked:int, min_failed:int, failed_total:int,
     *               failed_courses:array, too_many_failed:bool, causes:array}
     */
    public static function evaluate(): array {
        $window = (int) (get_config('local_ai_course_assistant', 'unanswered_window_hours')
            ?: self::DEFAULT_WINDOW_HOURS);
        $minquestions = (int) (get_config('local_ai_course_assistant', 'unanswered_min_questions')
            ?: self::DEFAULT_MIN_QUESTIONS);
        $rawrate = get_config('local_ai_course_assistant', 'unanswered_min_answer_rate');
        // Guard with a null check rather than ?: so a configured 0 is honoured
        // (0 disables the rate test rather than silently restoring the default).
        $minrate = ($rawrate === false || $rawrate === '')
            ? self::DEFAULT_MIN_ANSWER_RATE
            : (float) $rawrate;

        $failing = [];
        $stats = self::course_stats($window);
        foreach ($stats as $s) {
            if ($s['asked'] >= $minquestions && $s['rate'] < $minrate) {
                $failing[] = $s;
            }
        }
        // Worst first: a course answering nothing matters more than one at 40%.
        usort($failing, static function ($a, $b) {
            return $a['rate'] <=> $b['rate'] ?: $b['asked'] <=> $a['asked'];
        });

        // v7.8.8: failed replies across the whole site. The rate test above only
        // sees a course that has mostly stopped answering; a provider that fails
        // one message in five never trips it.
        $rawfailed = get_config('local_ai_course_assistant', 'unanswered_min_failed_turns');
        $minfailed = ($rawfailed === false || $rawfailed === '') ? self::DEFAULT_MIN_FAILED_TURNS : (int) $rawfailed;
        $failedcourses = array_values(array_filter($stats, static fn($s) => $s['failed'] > 0));
        usort($failedcourses, static fn($a, $b) => $b['failed'] <=> $a['failed']);
        $failedtotal = array_sum(array_column($failedcourses, 'failed'));
        $toomanyfailed = $minfailed > 0 && $failedtotal >= $minfailed;

        $alert = $failing !== [] || $toomanyfailed;
        return [
            'status'         => $alert ? 'unanswered' : 'ok',
            'window_hours'   => $window,
            'min_questions'  => $minquestions,
            'min_rate'       => $minrate,
            'failing'        => $failing,
            'checked'        => count($stats),
            'min_failed'     => $minfailed,
            'failed_total'   => $failedtotal,
            'failed_courses' => $failedcourses,
            'too_many_failed' => $toomanyfailed,
            'causes'         => $alert ? self::top_causes($window) : [],
        ];
    }

    /**
     * Email the alert, at most once per day, to the spend-notify recipients.
     *
     * Deliberately reuses spend_notify_emails rather than adding a second
     * recipient list: an operator who wants SOLA alerts wants all of them, and
     * a separate list is one more thing to leave empty.
     *
     * @param array $eval Result of evaluate().
     * @return bool Whether an email was sent.
     */
    public static function maybe_send_alert(array $eval): bool {
        if (($eval['status'] ?? '') !== 'unanswered') {
            return false;
        }

        $today = gmdate('Y-m-d', time());
        $flagkey = 'unanswered_notified_' . $today;
        if (get_config('local_ai_course_assistant', $flagkey)) {
            return false;
        }

        $recipients = trim((string) (get_config('local_ai_course_assistant', 'spend_notify_emails') ?: ''));
        if ($recipients === '') {
            $admins = get_admins();
            $recipients = implode(',', array_map(static fn($a) => $a->email, $admins));
        }
        if ($recipients === '') {
            return false;
        }

        $failing = array_slice($eval['failing'], 0, self::MAX_COURSES_IN_EMAIL);
        $worst = $failing[0] ?? null;

        if ($worst !== null) {
            $subject = '[SOLA] ' . count($eval['failing']) . ' course(s) asking but not answering';
            $subject .= ' — ' . ($worst['shortname'] ?: ('courseid ' . $worst['courseid']))
                . ' answered ' . $worst['answered'] . '/' . $worst['asked'];
        } else {
            $subject = '[SOLA] ' . (int) ($eval['failed_total'] ?? 0) . ' failed repl'
                . ((int) ($eval['failed_total'] ?? 0) === 1 ? 'y' : 'ies') . ' in the last ' . $eval['window_hours'] . ' hours';
        }

        $body  = $worst !== null
            ? "Learners are asking SOLA questions and not getting answers.\n\n"
            : "SOLA replies are failing for learners.\n\n";
        $body .= sprintf("Window:            last %d hours\n", $eval['window_hours']);
        if ($worst !== null) {
            $body .= sprintf("Alert threshold:   answer rate below %.0f%% with at least %d questions\n",
                $eval['min_rate'] * 100, $eval['min_questions']);
        }
        $body .= sprintf("Courses checked:   %d\n\n", $eval['checked']);

        if (!empty($eval['failed_total'])) {
            $body .= sprintf(
                "Failed replies:    %d in the window (alert at %d or more; the learner saw an error)\n",
                $eval['failed_total'],
                $eval['min_failed']
            );
            foreach (array_slice($eval['failed_courses'], 0, self::MAX_COURSES_IN_EMAIL) as $c) {
                $body .= sprintf(
                    "  %-24s %5d failed of %d asked   (courseid=%d)\n",
                    substr($c['shortname'] ?: '<unknown>', 0, 24),
                    $c['failed'],
                    $c['asked'],
                    $c['courseid']
                );
            }
            if (!empty($eval['causes'])) {
                $body .= "What the provider said, most common first:\n";
                foreach ($eval['causes'] as $cause) {
                    $body .= sprintf("  %3d x %s\n", $cause['count'], $cause['detail']);
                }
            }
            $body .= "\n";
        }

        if ($worst !== null) {
            $body .= sprintf("%-24s %8s %9s %8s\n", 'COURSE', 'ASKED', 'ANSWERED', 'RATE');
        }
        foreach ($failing as $c) {
            $body .= sprintf(
                "%-24s %8d %9d %7.0f%%   (courseid=%d)\n",
                substr($c['shortname'] ?: '<unknown>', 0, 24),
                $c['asked'],
                $c['answered'],
                $c['rate'] * 100,
                $c['courseid']
            );
        }
        if (count($eval['failing']) > self::MAX_COURSES_IN_EMAIL) {
            $body .= sprintf("\n... and %d more.\n", count($eval['failing']) - self::MAX_COURSES_IN_EMAIL);
        }

        $body .= "\nUsual causes, most common first:\n";
        $body .= "  1. The provider is rejecting calls — expired key, organisation spend cap,\n";
        $body .= "     retired model. Check the sse_error rows in the SOLA audit log; since\n";
        $body .= "     v6.9.7 they carry the provider's own message in a `detail` field.\n";
        $body .= "  2. A per-course provider override pointing at a provider with no\n";
        $body .= "     credentials configured.\n";
        $body .= "  3. The site or course is over a SOLA spend cap.\n\n";
        $body .= "This detector exists because none of the other monitors catch a silent\n";
        $body .= "provider failure: they watch spend going UP, and a dead provider costs\n";
        $body .= "nothing. In August 2026 that gap hid a nine-day outage across ten courses.\n\n";
        $body .= "Configure or disable at:\n";
        $body .= "  Site administration > Plugins > Local plugins > AI Course Assistant.\n";

        $admin = get_admin();
        $sent = false;
        foreach (array_filter(array_map('trim', explode(',', $recipients))) as $email) {
            if (!validate_email($email)) {
                continue;
            }
            $to = \core_user::get_support_user();
            $to = clone $to;
            $to->email = $email;
            $to->id = -1;
            if (email_to_user($to, $admin, $subject, $body)) {
                $sent = true;
            }
        }

        if ($sent) {
            set_config($flagkey, 1, 'local_ai_course_assistant');
        }
        return $sent;
    }
}
