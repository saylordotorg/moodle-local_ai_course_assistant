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

use local_ai_course_assistant\objective_manager;
use local_ai_course_assistant\next_best_action;
use local_ai_course_assistant\digest_unsubscribe_token;
use local_ai_course_assistant\branding;

/**
 * v4.0 / M3 — Learner-facing weekly digest email.
 *
 * For every learner who opted in to the per-course digest (preference
 * `local_ai_course_assistant_digest_optin_<courseid> = 1`) and who is
 * still enrolled on a mastery-enabled course, computes their two-to-three
 * weakest objectives and emails a personalized summary with deep links
 * back into the course (and SOLA).
 *
 * Default schedule: Mondays 09:00 server time. Opt-in is the *only* way
 * an email goes out — there is no auto-enrolment of learners.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class learner_weekly_digest extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task:learner_weekly_digest', 'local_ai_course_assistant');
    }

    public function execute(): void {
        global $DB;

        // Find every (userid, courseid) pair where the opt-in preference
        // is set to '1'. The user_preferences `name` carries the courseid
        // suffix, so a single LIKE query enumerates all opted-in pairs
        // without scanning every user.
        $rows = $DB->get_records_sql(
            "SELECT id, userid, name, value FROM {user_preferences}
              WHERE " . $DB->sql_like('name', ':pattern') . "
                AND value = '1'",
            ['pattern' => 'local_ai_course_assistant_digest_optin_%']
        );
        if (empty($rows)) {
            mtrace('learner_weekly_digest: no opt-ins.');
            return;
        }

        $sent = 0;
        $skipped = 0;
        $supportuser = \core_user::get_support_user();
        $prefix = 'local_ai_course_assistant_digest_optin_';

        foreach ($rows as $row) {
            $courseid = (int) substr($row->name, strlen($prefix));
            $userid = (int) $row->userid;
            if ($courseid <= 0 || $userid <= 0) {
                continue;
            }
            try {
                // Skip if mastery is no longer enabled on this course.
                if (!objective_manager::is_enabled_for_course($courseid)) {
                    $skipped++;
                    continue;
                }
                $course = $DB->get_record('course', ['id' => $courseid]);
                if (!$course || $course->visible == 0) {
                    $skipped++;
                    continue;
                }
                $user = $DB->get_record('user', ['id' => $userid]);
                if (!$user || empty($user->email) || !empty($user->deleted) || !empty($user->suspended)) {
                    $skipped++;
                    continue;
                }
                // Skip if learner is no longer enrolled on the course. Cheap
                // check: any role in the course context.
                $coursecontext = \context_course::instance($courseid);
                if (!is_enrolled($coursecontext, $user, '', true)) {
                    $skipped++;
                    continue;
                }
                // v4.1 / F1 — single-source-of-truth recommendations. The
                // chat focus-next starter and any third-party integration
                // consume the same shape, so what learners see in the email
                // matches what they see in chat.
                $recs = next_best_action::recommend($userid, $courseid, 3);
                if (empty($recs)) {
                    // Nothing to nudge about this week — silent skip is the
                    // right call for a "weekly recap" tone. Sending an empty
                    // email teaches the learner to ignore the channel.
                    $skipped++;
                    continue;
                }

                $subject = get_string(
                    'learner_digest:subject',
                    'local_ai_course_assistant',
                    (object) [
                        'product' => branding::short_name(),
                        'course'  => $course->fullname,
                    ]
                );
                $text = $this->render_text($course, $user, $recs);
                $html = $this->render_html($course, $user, $recs);

                // v4.1 / F2 — one-click unsubscribe.
                // Email body link is set up in render_text/render_html below.
                // Mail-client-native button comes from the List-Unsubscribe and
                // List-Unsubscribe-Post headers. Gmail and Outlook show a
                // single "Unsubscribe" button next to the sender when these
                // are present, and POST `List-Unsubscribe=One-Click` to our
                // public endpoint. RFC 8058.
                $unsuburl = digest_unsubscribe_token::url($userid, $courseid);
                $extraheaders = [
                    'List-Unsubscribe: <' . $unsuburl . '>',
                    'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
                ];
                // email_to_user accepts custom headers via $user->customheaders.
                $user->customheaders = $extraheaders;

                email_to_user($user, $supportuser, $subject, $text, $html);
                $sent++;
            } catch (\Throwable $e) {
                mtrace('learner_weekly_digest: user ' . $userid . ' course ' . $courseid . ' failed: ' . $e->getMessage());
            }
        }
        mtrace('learner_weekly_digest: sent ' . $sent . ' email(s), skipped ' . $skipped . '.');
    }

    /**
     * Plain-text email body. v4.1 / F1 — consumes structured recommendations
     * from next_best_action so the email and the chat focus-next starter
     * share one data shape.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @param array $recs Recommendations from next_best_action::recommend().
     * @return string
     */
    protected function render_text(\stdClass $course, \stdClass $user, array $recs): string {
        $product = branding::short_name();
        $courseurl = (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
        $unsuburl  = digest_unsubscribe_token::url((int) $user->id, (int) $course->id);

        $lines = [];
        $lines[] = 'Hi ' . trim($user->firstname) . ',';
        $lines[] = '';
        $lines[] = 'Quick weekly check-in for ' . $course->fullname . '.';
        $lines[] = '';
        $lines[] = 'Based on your progress so far, here is what I would focus on this week:';
        $lines[] = '';
        foreach ($recs as $r) {
            $lines[] = '  - ' . $r['title'];
            $lines[] = '    ' . $r['suggestion'];
            if (!empty($r['moduleurl'])) {
                $lines[] = '    Module: ' . $r['moduleurl'];
            }
        }
        $lines[] = '';
        $lines[] = 'Open the course: ' . $courseurl;
        $lines[] = '';
        $lines[] = 'Unsubscribe (one click): ' . $unsuburl;
        $lines[] = '';
        $lines[] = '— ' . $product;
        return implode("\n", $lines);
    }

    /**
     * HTML email body. Consumes the same structured recommendations as the
     * text version.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @param array $recs
     * @return string
     */
    protected function render_html(\stdClass $course, \stdClass $user, array $recs): string {
        $product = s(branding::short_name());
        $coursename = s($course->fullname);
        $firstname = s(trim($user->firstname));
        $courseurl = (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
        $unsuburl  = digest_unsubscribe_token::url((int) $user->id, (int) $course->id);

        $html  = '<div style="font-family:Helvetica,Arial,sans-serif;line-height:1.5;color:#1f2937;max-width:560px">';
        $html .= '<p style="margin:0 0 12px">Hi ' . $firstname . ',</p>';
        $html .= '<p style="margin:0 0 12px">Quick weekly check-in for <strong>' . $coursename . '</strong>.</p>';
        $html .= '<p style="margin:0 0 6px">Based on your progress so far, here is what I would focus on this week:</p>';
        $html .= '<ul style="padding-left:20px;margin:0 0 18px">';
        foreach ($recs as $r) {
            $title = s((string) $r['title']);
            $suggestion = s((string) $r['suggestion']);
            $extra = '';
            if (!empty($r['moduleurl']) && !empty($r['modulename'])) {
                $extra = '<br><a href="' . s($r['moduleurl']) . '" style="color:#1f2937">'
                    . s((string) $r['modulename']) . '</a>';
            }
            $html .= '<li style="margin-bottom:10px"><strong>' . $title . '</strong><br>'
                . '<span style="color:#4b5563">' . $suggestion . '</span>' . $extra . '</li>';
        }
        $html .= '</ul>';
        $html .= '<p style="margin:0 0 14px">'
            . '<a href="' . $courseurl . '" style="display:inline-block;padding:10px 16px;background:#1f2937;color:#fff;text-decoration:none;border-radius:6px;font-weight:600">Open the course</a>'
            . '</p>';
        $html .= '<p style="margin:18px 0 0;color:#9ca3af;font-size:12px">'
            . 'You are receiving this because you opted in to weekly progress emails for this course. '
            . '<a href="' . $unsuburl . '" style="color:#9ca3af">Unsubscribe with one click</a>.'
            . '</p>';
        $html .= '<p style="margin:8px 0 0;color:#9ca3af;font-size:12px">— ' . $product . '</p>';
        $html .= '</div>';
        return $html;
    }
}
