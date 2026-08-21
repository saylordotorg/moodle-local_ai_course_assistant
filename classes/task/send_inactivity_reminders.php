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

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task: send weekly inactivity reminders.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_inactivity_reminders extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task:send_inactivity_reminders', 'local_ai_course_assistant');
    }

    public function execute(): void {
        global $DB;

        // Both gates were missing until v7.0.0, so this task sent learner email
        // regardless of the site kill switch and regardless of its own on/off
        // setting. Its sibling send_reminders has always had the first one.
        if (!get_config('local_ai_course_assistant', 'enabled')) {
            return;
        }

        // Documented default is on (settings.php ships 1), so an unset value
        // must read as enabled: a site that upgraded before the setting row was
        // written would otherwise have its reminders silently switched off by
        // this fix. Only an explicit "0" stops the send.
        $rawenabled = get_config('local_ai_course_assistant', 'inactivity_reminder_enabled');
        if ($rawenabled !== false && !(bool) $rawenabled) {
            mtrace('send_inactivity_reminders: disabled (inactivity_reminder_enabled is off)');
            return;
        }

        // Use ?? for default-when-unset; ?: would fall through on a literal
        // "0" config value and apply the 7-day default instead of the admin's
        // explicit choice.
        $rawthreshold = get_config('local_ai_course_assistant', 'inactivity_threshold_days');
        $threshold = ($rawthreshold === false || $rawthreshold === '') ? 7 : (int) $rawthreshold;
        $cutoff = time() - ($threshold * 86400);
        $display_name = get_config('local_ai_course_assistant', 'display_name') ?: 'SOLA';

        // Find users with active email reminders who haven't accessed their course recently.
        $sql = "SELECT r.id, r.userid, r.courseid, r.destination, r.last_sent,
                       c.fullname AS coursename, u.firstname, u.lastname,
                       COALESCE(la.timeaccess, 0) AS lastaccess
                  FROM {local_ai_course_assistant_reminders} r
                  JOIN {user} u ON u.id = r.userid
                  JOIN {course} c ON c.id = r.courseid
                  LEFT JOIN {user_lastaccess} la ON la.userid = r.userid AND la.courseid = r.courseid
                 WHERE r.channel = 'email' AND r.enabled = 1
                   AND (la.timeaccess IS NULL OR la.timeaccess < :cutoff)
                   AND (r.last_sent IS NULL OR r.last_sent < :weeksince)";

        $weeksince = time() - (7 * 86400); // Don't send more than once per week.
        $records = $DB->get_records_sql($sql, ['cutoff' => $cutoff, 'weeksince' => $weeksince]);

        // Pre-load the two per-recipient lookups in two queries instead of two
        // per recipient: the study plan for each (userid, courseid) pair and
        // the full user record. Both were a get_record() inside the loop, so
        // the query count grew with the number of due reminders.
        $plansbypair = [];
        $usersbyid = [];
        if (!empty($records)) {
            $userids = [];
            $courseids = [];
            foreach ($records as $rec) {
                $userids[(int) $rec->userid] = true;
                $courseids[(int) $rec->courseid] = true;
            }
            [$usql, $uparams] = $DB->get_in_or_equal(array_keys($userids), SQL_PARAMS_NAMED, 'pu');
            [$csql, $cparams] = $DB->get_in_or_equal(array_keys($courseids), SQL_PARAMS_NAMED, 'pc');
            $rs = $DB->get_recordset_select(
                'local_ai_course_assistant_plans',
                "userid {$usql} AND courseid {$csql}",
                $uparams + $cparams,
                'id ASC'
            );
            try {
                foreach ($rs as $row) {
                    // (userid, courseid) is unique on this table; keep the
                    // lowest id anyway so a legacy duplicate resolves the same
                    // way the single-row get_record() did.
                    $key = (int) $row->userid . ':' . (int) $row->courseid;
                    if (!isset($plansbypair[$key])) {
                        $plansbypair[$key] = $row;
                    }
                }
            } finally {
                $rs->close();
            }
            $usersbyid = $DB->get_records_list('user', 'id', array_keys($userids));
        }

        $sent = 0;
        foreach ($records as $rec) {
            // Check if student has a study plan — skip if plan covers their absence.
            $plan = $plansbypair[(int) $rec->userid . ':' . (int) $rec->courseid] ?? null;
            if ($plan && !empty($plan->preferred_days)) {
                // If today is not one of their preferred study days, they might be on schedule.
                $today = strtolower(date('l'));
                $days = array_map('trim', explode(',', strtolower($plan->preferred_days)));
                if (!in_array($today, $days)) {
                    continue; // Respect their study schedule.
                }
            }

            $days_since = $rec->lastaccess > 0 ? round((time() - $rec->lastaccess) / 86400) : 0;
            $subject = "We miss you in {$rec->coursename}!";
            $body = "Hi {$rec->firstname},\n\n"
                . "It's been " . ($days_since > 0 ? "{$days_since} days" : "a while")
                . " since you last visited {$rec->coursename}. "
                . "{$display_name} is ready to help you pick up where you left off.\n\n"
                . "Even 10 minutes of review can make a difference. Log in and let's keep your momentum going!\n\n"
                . "— {$display_name}";

            try {
                // The driving query INNER JOINs {user}, so the pre-loaded map
                // always holds the row; the fallback keeps the MUST_EXIST
                // contract if that ever stops being true. Cloned so a mutation
                // (customheaders, mail flags) cannot leak between recipients
                // sharing a userid across courses.
                $user = isset($usersbyid[(int) $rec->userid])
                    ? clone $usersbyid[(int) $rec->userid]
                    : $DB->get_record('user', ['id' => $rec->userid], '*', MUST_EXIST);
                // v5.4.3: per-recipient opt-out check + footer.
                if (
                    \local_ai_course_assistant\email_optout::is_opted_out(
                        (string) $user->email,
                        \local_ai_course_assistant\email_optout::TYPE_INACTIVITY_REMINDER
                    )
                ) {
                    continue;
                }
                $bodywithfooter = \local_ai_course_assistant\email_footer::append_text(
                    $body,
                    (string) $user->email,
                    \local_ai_course_assistant\email_optout::TYPE_INACTIVITY_REMINDER,
                    'You receive these because you signed up for course-activity '
                    . 'reminders for ' . $rec->coursename . '.'
                );
                $message = new \core\message\message();
                $message->component = 'local_ai_course_assistant';
                $message->name = 'study_reminder';
                $message->userfrom = \core_user::get_noreply_user();
                $message->userto = $user;
                $message->subject = $subject;
                $message->fullmessage = $bodywithfooter;
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = nl2br(s($bodywithfooter));
                $message->smallmessage = $subject;
                $message->notification = 1;
                message_send($message);
                \local_ai_course_assistant\reminder_manager::mark_sent($rec->id);
                $sent++;
            } catch (\Throwable $e) {
                mtrace("  Inactivity reminder failed for user {$rec->userid}: " . $e->getMessage());
            }
        }

        mtrace("Inactivity reminders done. Sent: {$sent}");
    }
}
