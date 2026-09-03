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
 * v7.2.1 audit log viewer.
 *
 * The rows were always being written -- emergency_control, the failover chain,
 * the de-anonymised Redash export and the integrity checker all call
 * audit_logger::log() -- and audit_logger::get_all_logs() has always been able
 * to read them back. What did not exist was any way to see them. Two admin
 * settings nonetheless told operators to go and look: Emergency Controls says
 * each action "writes an audit row", and the per-call failover setting says to
 * "check Admin -> SOLA -> Audit log after enabling to verify the chain is
 * healthy". Both pointed at a page that was never built.
 *
 * Read-only by design. An audit trail an admin can edit is not an audit trail;
 * retention is handled by audit_logger::clean_old_logs() on the schedule set by
 * the audit_retention_days setting.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', \context_system::instance());

use local_ai_course_assistant\audit_logger;
use local_ai_course_assistant\branding;

$perpage = 100;
$page = optional_param('page', 0, PARAM_INT);
$page = max(0, $page);

$PAGE->set_context(\context_system::instance());
$PAGE->set_url(new moodle_url('/local/ai_course_assistant/audit_log.php', ['page' => $page]));
$PAGE->set_title(branding::str('auditlog:title'));
$PAGE->set_heading(branding::str('auditlog:title'));
$PAGE->set_pagelayout('admin');

$records = audit_logger::get_all_logs($perpage + 1, $page * $perpage);
$hasnext = count($records) > $perpage;
if ($hasnext) {
    array_pop($records);
}

global $DB;
$rows = [];
$coursenames = [];
foreach ($records as $rec) {
    // details is a JSON blob written by audit_logger::log(). Render it as
    // readable text rather than raw JSON, and never trust it as markup: it
    // carries values that originated outside this page.
    $details = '';
    if (!empty($rec->details)) {
        $decoded = json_decode((string) $rec->details, true);
        if (is_array($decoded)) {
            $parts = [];
            foreach ($decoded as $k => $v) {
                // json_encode, not a string cast: (string) false is '' , which in
                // an audit trail is indistinguishable from "not recorded".
                if (is_bool($v) || $v === null) {
                    $rendered = json_encode($v);
                } else {
                    $rendered = is_scalar($v) ? (string) $v : json_encode($v);
                }
                $parts[] = $k . '=' . $rendered;
            }
            $details = implode(', ', $parts);
        } else {
            $details = (string) $rec->details;
        }
    }

    // A deleted or unknown account previously fell back to the no-reply user,
    // so the audit trail read "Do not reply to this email" where an actor name
    // belongs. Say plainly that the account is gone, and keep the id.
    $username = '-';
    if (!empty($rec->userid)) {
        $actor = \core_user::get_user((int) $rec->userid, '*', IGNORE_MISSING);
        $username = $actor
            ? fullname($actor)
            : get_string('auditlog:unknown_user', 'local_ai_course_assistant', (int) $rec->userid);
    }

    // Course id paired with its shortname: a bare number is hard to place, and
    // the id alone is the thing most likely to be misread.
    $course = '-';
    if (!empty($rec->courseid)) {
        if (!isset($coursenames[(int) $rec->courseid])) {
            $coursenames[(int) $rec->courseid] = $DB->get_field(
                'course',
                'shortname',
                ['id' => (int) $rec->courseid]
            );
        }
        $shortname = $coursenames[(int) $rec->courseid];
        $course = $shortname === false
            ? (string) (int) $rec->courseid
            : $shortname . ' (' . (int) $rec->courseid . ')';
    }

    $rows[] = [
        'time' => userdate((int) $rec->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
        'action' => (string) $rec->action,
        'username' => $username,
        'course' => $course,
        'ipaddress' => (string) $rec->ipaddress,
        'details' => $details,
    ];
}

$baseurl = new moodle_url('/local/ai_course_assistant/audit_log.php');
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/audit_log', [
    'intro' => branding::str('auditlog:intro'),
    'rows' => $rows,
    'hasrows' => !empty($rows),
    'empty' => $page > 0
        ? get_string('auditlog:empty_page', 'local_ai_course_assistant')
        : get_string('auditlog:empty', 'local_ai_course_assistant'),
    'col_time' => get_string('auditlog:col_time', 'local_ai_course_assistant'),
    'col_action' => get_string('auditlog:col_action', 'local_ai_course_assistant'),
    'col_user' => get_string('auditlog:col_user', 'local_ai_course_assistant'),
    'col_course' => get_string('auditlog:col_course', 'local_ai_course_assistant'),
    'col_ip' => get_string('auditlog:col_ip', 'local_ai_course_assistant'),
    'col_details' => get_string('auditlog:col_details', 'local_ai_course_assistant'),
    'settings_url' => (new moodle_url('/admin/settings.php', ['section' => 'local_ai_course_assistant_general']))->out(false),
    'back_to_settings' => branding::str('ragadmin:back_to_settings'),
    'hasprev' => $page > 0,
    'prevurl' => (new moodle_url($baseurl, ['page' => $page - 1]))->out(false),
    'hasnext' => $hasnext,
    'nexturl' => (new moodle_url($baseurl, ['page' => $page + 1]))->out(false),
    'prevlabel' => get_string('previous'),
    'nextlabel' => get_string('next'),
]);
echo $OUTPUT->footer();
