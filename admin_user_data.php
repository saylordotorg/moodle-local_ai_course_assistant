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
 * Admin page: find a learner and export or purge their SOLA data.
 *
 * This is the operational path for a GDPR Article 15 (access) or Article 17
 * (erasure) request. Wraps the Moodle Privacy API plus the plugin's own
 * conversation manager, with a preview of row counts per table and a two
 * step confirmation for the purge action.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);

$targetuserid = optional_param('targetuserid', 0, PARAM_INT);
$action       = optional_param('action', '', PARAM_ALPHA);
$confirm      = optional_param('confirm', 0, PARAM_INT);

$PAGE->set_url('/local/ai_course_assistant/admin_user_data.php');
$PAGE->set_context($syscontext);
$PAGE->set_title(get_string(
    'admin:user_data:title',
    'local_ai_course_assistant',
    \local_ai_course_assistant\branding::short_name()
));
$PAGE->set_heading(get_string(
    'admin:user_data:title',
    'local_ai_course_assistant',
    \local_ai_course_assistant\branding::short_name()
));

$tables = [
    'convs'          => 'local_ai_course_assistant_convs',
    'ratings'        => 'local_ai_course_assistant_msg_ratings',
    'plans'          => 'local_ai_course_assistant_plans',
    'reminders'      => 'local_ai_course_assistant_reminders',
    'feedback'       => 'local_ai_course_assistant_feedback',
    'survey_resp'    => 'local_ai_course_assistant_survey_resp',
    'ut_resp'        => 'local_ai_course_assistant_ut_resp',
    'audit'          => 'local_ai_course_assistant_audit',
    'practice_scores' => 'local_ai_course_assistant_practice_scores',
    'profiles'       => 'local_ai_course_assistant_profiles',
];

// Handle download JSON action.
if ($action === 'download' && $targetuserid && confirm_sesskey()) {
    global $DB;
    $bundle = [
        'generated_at' => date('c'),
        'userid' => $targetuserid,
        'exported_by' => (int)$USER->id,
    ];
    // One query per plugin table for this user's export. The list is a fixed,
    // small constant (not row-driven), so this is bounded, not an N+1.
    foreach ($tables as $label => $table) {
        try {
            $bundle[$label] = array_values($DB->get_records($table, ['userid' => $targetuserid]));
        } catch (\Throwable $e) {
            $bundle[$label] = ['error' => 'table unavailable'];
        }
    }
    // Messages join through convs.
    try {
        $sql = "SELECT m.* FROM {local_ai_course_assistant_msgs} m
                JOIN {local_ai_course_assistant_convs} c ON c.id = m.conversationid
                WHERE c.userid = :uid ORDER BY m.timecreated ASC";
        $bundle['messages'] = array_values($DB->get_records_sql($sql, ['uid' => $targetuserid]));
    } catch (\Throwable $e) {
        $bundle['messages'] = ['error' => 'table unavailable'];
    }
    \local_ai_course_assistant\audit_logger::log(
        'admin_export_learner_data',
        (int)$USER->id,
        0,
        ['target_userid' => $targetuserid]
    );
    header('Content-Type: application/json; charset=utf-8');
    $fnslug = \local_ai_course_assistant\branding::filename_slug();
    header('Content-Disposition: attachment; filename="' . $fnslug . '-data-' . $targetuserid . '-' . date('Ymd') . '.json"');
    header('Cache-Control: no-store');
    echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Handle purge action (two step confirm).
if ($action === 'purge' && $targetuserid && confirm_sesskey()) {
    if ($confirm) {
        // Contexts first: the provider derives them from tables that
        // delete_user_data() is about to empty, so computing them afterwards
        // yields an empty list and silently skips the Privacy leg.
        // Also note this was \core_privacy\manager::get_contexts_for_userid()
        // called statically with two arguments -- it is a non-static one-argument
        // instance method, so it raised an Error straight into the catch below.
        $contextids = [];
        try {
            $contextlist = \local_ai_course_assistant\privacy\provider::get_contexts_for_userid($targetuserid);
            $contextids = $contextlist ? $contextlist->get_contextids() : [];
        } catch (\Throwable $e) {
            debugging('Privacy API contextlist threw: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        \local_ai_course_assistant\conversation_manager::delete_user_data($targetuserid);
        try {
            if (!empty($contextids)) {
                $approved = new \core_privacy\local\request\approved_contextlist(
                    \core\user::get_user($targetuserid) ?: (object)['id' => $targetuserid],
                    'local_ai_course_assistant',
                    $contextids
                );
                \local_ai_course_assistant\privacy\provider::delete_data_for_user($approved);
            }
        } catch (\Throwable $e) {
            debugging('Privacy API purge threw: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        \local_ai_course_assistant\audit_logger::log(
            'admin_purge_learner_data',
            (int)$USER->id,
            0,
            ['target_userid' => $targetuserid]
        );
        redirect(
            $PAGE->url,
            get_string('admin:user_data:purged', 'local_ai_course_assistant'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        echo $OUTPUT->header();
        $target = core_user::get_user($targetuserid);
        $targetname = $target
            ? fullname($target)
            : get_string('admin:user_data:idlabel', 'local_ai_course_assistant', $targetuserid);
        echo $OUTPUT->confirm(
            get_string('admin:user_data:confirm_purge', 'local_ai_course_assistant', $targetname),
            new moodle_url($PAGE->url, [
                'action' => 'purge',
                'targetuserid' => $targetuserid,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]),
            $PAGE->url
        );
        echo $OUTPUT->footer();
        die;
    }
}

$templatedata = [
    'intro' => get_string('admin:user_data:intro', 'local_ai_course_assistant'),
    'formurl' => $PAGE->url->out(false),
    'searchlabel' => get_string('admin:user_data:search_label', 'local_ai_course_assistant'),
    'targetuserid' => $targetuserid,
    'lookuplabel' => get_string('admin:user_data:lookup', 'local_ai_course_assistant'),
    'notfound' => false,
    'notfoundmsg' => get_string('admin:user_data:not_found', 'local_ai_course_assistant'),
    'hasuser' => false,
    'coltable' => get_string('admin:user_data:col_table', 'local_ai_course_assistant'),
    'colrows' => get_string('admin:user_data:col_rows', 'local_ai_course_assistant'),
    'totallabel' => get_string('admin:user_data:total', 'local_ai_course_assistant'),
    'downloadlabel' => get_string('admin:user_data:download', 'local_ai_course_assistant'),
    'purgelabel' => get_string('admin:user_data:purge', 'local_ai_course_assistant'),
];

if ($targetuserid) {
    $target = core_user::get_user($targetuserid);
    if (!$target) {
        $templatedata['notfound'] = true;
    } else {
        global $DB;
        $rows = [];
        $totalrows = 0;
        // The table list is a fixed, small constant (not row-driven), so one
        // count per table is bounded rather than an N+1.
        foreach ($tables as $label => $table) {
            try {
                $count = $DB->count_records($table, ['userid' => $targetuserid]);
            } catch (\Throwable $e) {
                $count = 0;
            }
            $totalrows += $count;
            $rows[] = ['label' => $label, 'count' => $count];
        }
        // Messages join through convs, so they need their own count.
        try {
            $msgsql = "SELECT COUNT(m.id) FROM {local_ai_course_assistant_msgs} m
                       JOIN {local_ai_course_assistant_convs} c ON c.id = m.conversationid
                       WHERE c.userid = :uid";
            $msgcount = $DB->count_records_sql($msgsql, ['uid' => $targetuserid]);
        } catch (\Throwable $e) {
            $msgcount = 0;
        }
        $totalrows += $msgcount;
        $rows[] = ['label' => 'messages', 'count' => $msgcount];

        $templatedata['hasuser'] = true;
        $templatedata['fullname'] = fullname($target);
        $templatedata['iddisplay'] = get_string(
            'admin:user_data:idlabel',
            'local_ai_course_assistant',
            $targetuserid
        );
        $templatedata['rows'] = $rows;
        $templatedata['totalrows'] = $totalrows;
        $templatedata['downloadurl'] = (new moodle_url($PAGE->url, [
            'action' => 'download',
            'targetuserid' => $targetuserid,
            'sesskey' => sesskey(),
        ]))->out(false);
        $templatedata['purgeurl'] = (new moodle_url($PAGE->url, [
            'action' => 'purge',
            'targetuserid' => $targetuserid,
            'sesskey' => sesskey(),
        ]))->out(false);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_course_assistant/admin_user_data', $templatedata);
echo $OUTPUT->footer();
