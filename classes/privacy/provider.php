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

namespace local_ai_course_assistant\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;
use local_ai_course_assistant\conversation_manager;
use local_ai_course_assistant\study_planner;
use local_ai_course_assistant\reminder_manager;

/**
 * Privacy API implementation for GDPR compliance.
 *
 * @package    local_ai_course_assistant
 * @copyright  2025 AI Course Assistant
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe the type of data stored.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_ai_course_assistant_convs',
            [
                'userid' => 'privacy:metadata:local_ai_course_assistant_convs:userid',
                'courseid' => 'privacy:metadata:local_ai_course_assistant_convs:courseid',
                'title' => 'privacy:metadata:local_ai_course_assistant_convs:title',
                'timecreated' => 'privacy:metadata:local_ai_course_assistant_convs:timecreated',
                'timemodified' => 'privacy:metadata:local_ai_course_assistant_convs:timemodified',
            ],
            'privacy:metadata:local_ai_course_assistant_convs'
        );

        $collection->add_database_table(
            'local_ai_course_assistant_msgs',
            [
                'userid' => 'privacy:metadata:local_ai_course_assistant_msgs:userid',
                'courseid' => 'privacy:metadata:local_ai_course_assistant_msgs:courseid',
                'role' => 'privacy:metadata:local_ai_course_assistant_msgs:role',
                'message' => 'privacy:metadata:local_ai_course_assistant_msgs:message',
                'tokens_used' => 'privacy:metadata:local_ai_course_assistant_msgs:tokens_used',
                'prompt_tokens' => 'privacy:metadata:local_ai_course_assistant_msgs:prompt_tokens',
                'completion_tokens' => 'privacy:metadata:local_ai_course_assistant_msgs:completion_tokens',
                'model_name' => 'privacy:metadata:local_ai_course_assistant_msgs:model_name',
                'provider' => 'privacy:metadata:local_ai_course_assistant_msgs:provider',
                'timecreated' => 'privacy:metadata:local_ai_course_assistant_msgs:timecreated',
            ],
            'privacy:metadata:local_ai_course_assistant_msgs'
        );

        $collection->add_database_table(
            'local_ai_course_assistant_plans',
            [
                'userid' => 'privacy:metadata:local_ai_course_assistant_plans:userid',
                'courseid' => 'privacy:metadata:local_ai_course_assistant_plans:courseid',
                'hours_per_week' => 'privacy:metadata:local_ai_course_assistant_plans:hours_per_week',
                'plan_data' => 'privacy:metadata:local_ai_course_assistant_plans:plan_data',
            ],
            'privacy:metadata:local_ai_course_assistant_plans'
        );

        $collection->add_database_table(
            'local_ai_course_assistant_reminders',
            [
                'userid' => 'privacy:metadata:local_ai_course_assistant_reminders:userid',
                'channel' => 'privacy:metadata:local_ai_course_assistant_reminders:channel',
                'destination' => 'privacy:metadata:local_ai_course_assistant_reminders:destination',
                'country_code' => 'privacy:metadata:local_ai_course_assistant_reminders:country_code',
            ],
            'privacy:metadata:local_ai_course_assistant_reminders'
        );

        $collection->add_database_table(
            'local_ai_course_assistant_feedback',
            [
                'userid' => 'privacy:metadata:local_ai_course_assistant_feedback:userid',
                'courseid' => 'privacy:metadata:local_ai_course_assistant_feedback:courseid',
                'rating' => 'privacy:metadata:local_ai_course_assistant_feedback:rating',
                'comment' => 'privacy:metadata:local_ai_course_assistant_feedback:comment',
                'browser' => 'privacy:metadata:local_ai_course_assistant_feedback:browser',
                'os' => 'privacy:metadata:local_ai_course_assistant_feedback:os',
                'device' => 'privacy:metadata:local_ai_course_assistant_feedback:device',
                'screen_size' => 'privacy:metadata:local_ai_course_assistant_feedback:screen_size',
                'user_agent' => 'privacy:metadata:local_ai_course_assistant_feedback:user_agent',
                'page_url' => 'privacy:metadata:local_ai_course_assistant_feedback:page_url',
                'timecreated' => 'privacy:metadata:local_ai_course_assistant_feedback:timecreated',
            ],
            'privacy:metadata:local_ai_course_assistant_feedback'
        );

        $collection->add_database_table(
            'local_ai_course_assistant_survey_resp',
            [
                'userid' => 'privacy:metadata:local_ai_course_assistant_survey_resp:userid',
                'courseid' => 'privacy:metadata:local_ai_course_assistant_survey_resp:courseid',
                'question_index' => 'privacy:metadata:local_ai_course_assistant_survey_resp:question_index',
                'answer' => 'privacy:metadata:local_ai_course_assistant_survey_resp:answer',
                'timecreated' => 'privacy:metadata:local_ai_course_assistant_survey_resp:timecreated',
            ],
            'privacy:metadata:local_ai_course_assistant_survey_resp'
        );

        $collection->add_database_table(
            'local_ai_course_assistant_ut_resp',
            [
                'userid' => 'privacy:metadata:local_ai_course_assistant_ut_resp:userid',
                'courseid' => 'privacy:metadata:local_ai_course_assistant_ut_resp:courseid',
                'task_index' => 'privacy:metadata:local_ai_course_assistant_ut_resp:task_index',
                'rating' => 'privacy:metadata:local_ai_course_assistant_ut_resp:rating',
                'answer' => 'privacy:metadata:local_ai_course_assistant_ut_resp:answer',
                'timecreated' => 'privacy:metadata:local_ai_course_assistant_ut_resp:timecreated',
            ],
            'privacy:metadata:local_ai_course_assistant_ut_resp'
        );

        $collection->add_database_table(
            'local_ai_course_assistant_audit',
            [
                'userid' => 'privacy:metadata:local_ai_course_assistant_audit:userid',
                'courseid' => 'privacy:metadata:local_ai_course_assistant_audit:courseid',
                'action' => 'privacy:metadata:local_ai_course_assistant_audit:action',
                'ipaddress' => 'privacy:metadata:local_ai_course_assistant_audit:ipaddress',
                'useragent' => 'privacy:metadata:local_ai_course_assistant_audit:useragent',
                'details' => 'privacy:metadata:local_ai_course_assistant_audit:details',
                'timecreated' => 'privacy:metadata:local_ai_course_assistant_audit:timecreated',
            ],
            'privacy:metadata:local_ai_course_assistant_audit'
        );

        $collection->add_database_table(
            'local_ai_course_assistant_practice_scores',
            [
                'userid' => 'privacy:metadata:local_ai_course_assistant_practice_scores:userid',
                'courseid' => 'privacy:metadata:local_ai_course_assistant_practice_scores:courseid',
                'session_type' => 'privacy:metadata:local_ai_course_assistant_practice_scores:session_type',
                'overall_score' => 'privacy:metadata:local_ai_course_assistant_practice_scores:overall_score',
                'scores' => 'privacy:metadata:local_ai_course_assistant_practice_scores:scores',
                'ai_feedback' => 'privacy:metadata:local_ai_course_assistant_practice_scores:ai_feedback',
                'timecreated' => 'privacy:metadata:local_ai_course_assistant_practice_scores:timecreated',
            ],
            'privacy:metadata:local_ai_course_assistant_practice_scores'
        );

        // v5.3.17: privacy metadata for tables added in v5.0–v5.3 that had
        // not yet been declared. Without these the core_privacy
        // table-coverage test fails because every table with a userid
        // field must be either declared here or explicitly excluded.
        $collection->add_database_table('local_ai_course_assistant_msg_ratings',
            ['userid' => 'privacy:metadata:msg_ratings:userid'],
            'privacy:metadata:msg_ratings');
        $collection->add_database_table('local_ai_course_assistant_profiles',
            ['userid' => 'privacy:metadata:profiles:userid'],
            'privacy:metadata:profiles');
        $collection->add_database_table('local_ai_course_assistant_obj_att',
            ['userid' => 'privacy:metadata:obj_att:userid'],
            'privacy:metadata:obj_att');
        $collection->add_database_table('local_ai_course_assistant_flashcards',
            ['userid' => 'privacy:metadata:flashcards:userid'],
            'privacy:metadata:flashcards');
        $collection->add_database_table('local_ai_course_assistant_review_res',
            ['resolved_by' => 'privacy:metadata:review_res:resolved_by'],
            'privacy:metadata:review_res');
        $collection->add_database_table('local_ai_course_assistant_radar_sched',
            ['creator' => 'privacy:metadata:radar_sched:creator'],
            'privacy:metadata:radar_sched');
        $collection->add_database_table('local_ai_course_assistant_avatar_sess',
            ['userid' => 'privacy:metadata:avatar_sess:userid'],
            'privacy:metadata:avatar_sess');
        // v5.3.0 carryover-personalisation tables.
        $collection->add_database_table('local_ai_course_assistant_learner_goals',
            ['userid' => 'privacy:metadata:learner_goals:userid'],
            'privacy:metadata:learner_goals');
        $collection->add_database_table('local_ai_course_assistant_learner_memory',
            ['userid' => 'privacy:metadata:learner_memory:userid'],
            'privacy:metadata:learner_memory');
        $collection->add_database_table('local_ai_course_assistant_streak',
            ['userid' => 'privacy:metadata:streak:userid'],
            'privacy:metadata:streak');
        $collection->add_database_table('local_ai_course_assistant_struggle_signal',
            ['userid' => 'privacy:metadata:struggle_signal:userid'],
            'privacy:metadata:struggle_signal');
        $collection->add_database_table('local_ai_course_assistant_outreach_log',
            ['userid' => 'privacy:metadata:outreach_log:userid'],
            'privacy:metadata:outreach_log');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user data.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT DISTINCT ctx.id
                  FROM {local_ai_course_assistant_convs} c
                  JOIN {context} ctx ON ctx.instanceid = c.courseid AND ctx.contextlevel = :contextlevel
                 WHERE c.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        // Also include contexts from study plans and reminders.
        $sql = "SELECT DISTINCT ctx.id
                  FROM {local_ai_course_assistant_plans} p
                  JOIN {context} ctx ON ctx.instanceid = p.courseid AND ctx.contextlevel = :contextlevel
                 WHERE p.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        $sql = "SELECT DISTINCT ctx.id
                  FROM {local_ai_course_assistant_reminders} r
                  JOIN {context} ctx ON ctx.instanceid = r.courseid AND ctx.contextlevel = :contextlevel
                 WHERE r.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        $sql = "SELECT DISTINCT ctx.id
                  FROM {local_ai_course_assistant_feedback} f
                  JOIN {context} ctx ON ctx.instanceid = f.courseid AND ctx.contextlevel = :contextlevel
                 WHERE f.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        $sql = "SELECT DISTINCT ctx.id
                  FROM {local_ai_course_assistant_survey_resp} sr
                  JOIN {context} ctx ON ctx.instanceid = sr.courseid AND ctx.contextlevel = :contextlevel
                 WHERE sr.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        $sql = "SELECT DISTINCT ctx.id
                  FROM {local_ai_course_assistant_ut_resp} ur
                  JOIN {context} ctx ON ctx.instanceid = ur.courseid AND ctx.contextlevel = :contextlevel
                 WHERE ur.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        $sql = "SELECT DISTINCT ctx.id
                  FROM {local_ai_course_assistant_audit} a
                  JOIN {context} ctx ON ctx.instanceid = a.courseid AND ctx.contextlevel = :contextlevel
                 WHERE a.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        $sql = "SELECT DISTINCT ctx.id
                  FROM {local_ai_course_assistant_practice_scores} ps
                  JOIN {context} ctx ON ctx.instanceid = ps.courseid AND ctx.contextlevel = :contextlevel
                 WHERE ps.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Get the list of users within a specific context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        $sql = "SELECT DISTINCT userid
                  FROM {local_ai_course_assistant_convs}
                 WHERE courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);

        $sql = "SELECT DISTINCT userid
                  FROM {local_ai_course_assistant_plans}
                 WHERE courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);

        $sql = "SELECT DISTINCT userid
                  FROM {local_ai_course_assistant_reminders}
                 WHERE courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);

        $sql = "SELECT DISTINCT userid
                  FROM {local_ai_course_assistant_feedback}
                 WHERE courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);

        $sql = "SELECT DISTINCT userid
                  FROM {local_ai_course_assistant_survey_resp}
                 WHERE courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);

        $sql = "SELECT DISTINCT userid
                  FROM {local_ai_course_assistant_ut_resp}
                 WHERE courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);

        $sql = "SELECT DISTINCT userid
                  FROM {local_ai_course_assistant_audit}
                 WHERE courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);

        $sql = "SELECT DISTINCT userid
                  FROM {local_ai_course_assistant_practice_scores}
                 WHERE courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
    }

    /**
     * Export user data for approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }

            $convs = $DB->get_records('local_ai_course_assistant_convs', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);

            foreach ($convs as $conv) {
                $messages = $DB->get_records('local_ai_course_assistant_msgs', [
                    'conversationid' => $conv->id,
                ], 'timecreated ASC');

                $exportedmessages = [];
                foreach ($messages as $msg) {
                    $exportedmessages[] = [
                        'role' => $msg->role,
                        'message' => $msg->message,
                        'timecreated' => transform::datetime($msg->timecreated),
                    ];
                }

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_ai_course_assistant'), $conv->id],
                    (object) [
                        'title' => $conv->title,
                        'timecreated' => transform::datetime($conv->timecreated),
                        'messages' => $exportedmessages,
                    ]
                );
            }

            // Export study plans.
            $plans = $DB->get_records('local_ai_course_assistant_plans', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            foreach ($plans as $plan) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_ai_course_assistant'), 'study_plans', $plan->id],
                    (object) [
                        'hours_per_week' => $plan->hours_per_week,
                        'plan_data' => $plan->plan_data,
                        'preferred_days' => $plan->preferred_days,
                        'preferred_time' => $plan->preferred_time,
                        'timecreated' => transform::datetime($plan->timecreated),
                        'timemodified' => transform::datetime($plan->timemodified),
                    ]
                );
            }

            // Export reminder preferences.
            $reminders = $DB->get_records('local_ai_course_assistant_reminders', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            foreach ($reminders as $reminder) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_ai_course_assistant'), 'reminders', $reminder->id],
                    (object) [
                        'channel' => $reminder->channel,
                        'destination' => $reminder->destination,
                        'country_code' => $reminder->country_code,
                        'frequency' => $reminder->frequency,
                        'enabled' => transform::yesno($reminder->enabled),
                        'timecreated' => transform::datetime($reminder->timecreated),
                    ]
                );
            }

            // Export feedback.
            $feedbacks = $DB->get_records('local_ai_course_assistant_feedback', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            foreach ($feedbacks as $feedback) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_ai_course_assistant'), 'feedback', $feedback->id],
                    (object) [
                        'rating' => $feedback->rating,
                        'comment' => $feedback->comment,
                        'browser' => $feedback->browser,
                        'os' => $feedback->os,
                        'device' => $feedback->device,
                        'screen_size' => $feedback->screen_size,
                        'user_agent' => $feedback->user_agent,
                        'page_url' => $feedback->page_url,
                        'timecreated' => transform::datetime($feedback->timecreated),
                    ]
                );
            }

            // Export survey responses.
            $surveyresps = $DB->get_records('local_ai_course_assistant_survey_resp', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            foreach ($surveyresps as $resp) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_ai_course_assistant'), 'survey_responses', $resp->id],
                    (object) [
                        'surveyid' => $resp->surveyid,
                        'question_index' => $resp->question_index,
                        'answer' => $resp->answer,
                        'timecreated' => transform::datetime($resp->timecreated),
                    ]
                );
            }

            // Export user testing responses.
            $utresps = $DB->get_records('local_ai_course_assistant_ut_resp', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            foreach ($utresps as $resp) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_ai_course_assistant'), 'ut_responses', $resp->id],
                    (object) [
                        'tasksetid' => $resp->tasksetid,
                        'task_index' => $resp->task_index,
                        'rating' => $resp->rating,
                        'answer' => $resp->answer,
                        'message_count' => $resp->message_count,
                        'session_minutes' => $resp->session_minutes,
                        'timecreated' => transform::datetime($resp->timecreated),
                    ]
                );
            }

            // Export audit log entries.
            $auditentries = $DB->get_records('local_ai_course_assistant_audit', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            foreach ($auditentries as $entry) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_ai_course_assistant'), 'audit_log', $entry->id],
                    (object) [
                        'action' => $entry->action,
                        'ipaddress' => $entry->ipaddress,
                        'useragent' => $entry->useragent,
                        'details' => $entry->details,
                        'timecreated' => transform::datetime($entry->timecreated),
                    ]
                );
            }

            // Export practice scores.
            $scores = $DB->get_records('local_ai_course_assistant_practice_scores', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            foreach ($scores as $score) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_ai_course_assistant'), 'practice_scores', $score->id],
                    (object) [
                        'session_type' => $score->session_type,
                        'overall_score' => $score->overall_score,
                        'scores' => $score->scores,
                        'ai_feedback' => $score->ai_feedback,
                        'session_duration' => $score->session_duration,
                        'timecreated' => transform::datetime($score->timecreated),
                    ]
                );
            }
        }
    }

    /**
     * Delete all data for all users in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }
        conversation_manager::delete_course_data($context->instanceid);
        $DB->delete_records('local_ai_course_assistant_plans', ['courseid' => $context->instanceid]);
        $DB->delete_records('local_ai_course_assistant_reminders', ['courseid' => $context->instanceid]);
        $DB->delete_records('local_ai_course_assistant_feedback', ['courseid' => $context->instanceid]);
        $DB->delete_records('local_ai_course_assistant_survey_resp', ['courseid' => $context->instanceid]);
        $DB->delete_records('local_ai_course_assistant_ut_resp', ['courseid' => $context->instanceid]);
        $DB->delete_records('local_ai_course_assistant_audit', ['courseid' => $context->instanceid]);
        $DB->delete_records('local_ai_course_assistant_practice_scores', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete data for a specific user in approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }

            $convs = $DB->get_records('local_ai_course_assistant_convs', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);

            foreach ($convs as $conv) {
                conversation_manager::clear_conversation($conv->id, $userid);
            }

            $DB->delete_records('local_ai_course_assistant_plans', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            $DB->delete_records('local_ai_course_assistant_reminders', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            $DB->delete_records('local_ai_course_assistant_feedback', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            $DB->delete_records('local_ai_course_assistant_survey_resp', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            $DB->delete_records('local_ai_course_assistant_ut_resp', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            $DB->delete_records('local_ai_course_assistant_audit', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            $DB->delete_records('local_ai_course_assistant_practice_scores', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);

            // v5.3.18: cover the tables added in v3.x–v5.3 that were
            // declared in the metadata above but were never purged here.
            // Without these, a learner who exercises Moodle's Article 17
            // erasure right via Site administration → Users → Privacy
            // would keep their carryover-memory and outreach state.
            $courseidkeyed = [
                'local_ai_course_assistant_msg_ratings',
                'local_ai_course_assistant_profiles',
                'local_ai_course_assistant_obj_att',
                'local_ai_course_assistant_flashcards',
                'local_ai_course_assistant_avatar_sess',
                'local_ai_course_assistant_learner_goals',
                'local_ai_course_assistant_learner_memory',
                'local_ai_course_assistant_streak',
                'local_ai_course_assistant_struggle_signal',
                'local_ai_course_assistant_outreach_log',
            ];
            foreach ($courseidkeyed as $table) {
                try {
                    $DB->delete_records($table, [
                        'userid' => $userid,
                        'courseid' => $context->instanceid,
                    ]);
                } catch (\Throwable $e) { /* table absent on older installs */ }
            }
            // Tables keyed on a different user-field name.
            try {
                $DB->delete_records('local_ai_course_assistant_review_res', [
                    'resolved_by' => $userid,
                    'courseid' => $context->instanceid,
                ]);
            } catch (\Throwable $e) { /* ignore */ }
            try {
                $DB->delete_records('local_ai_course_assistant_radar_sched', [
                    'creator' => $userid,
                    'courseid' => $context->instanceid,
                ]);
            } catch (\Throwable $e) { /* ignore */ }
        }
    }

    /**
     * Delete data for specific users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        $userids = $userlist->get_userids();
        foreach ($userids as $userid) {
            $convs = $DB->get_records('local_ai_course_assistant_convs', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);

            foreach ($convs as $conv) {
                conversation_manager::clear_conversation($conv->id, $userid);
            }

            $DB->delete_records('local_ai_course_assistant_plans', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            $DB->delete_records('local_ai_course_assistant_reminders', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            $DB->delete_records('local_ai_course_assistant_feedback', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            $DB->delete_records('local_ai_course_assistant_survey_resp', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            $DB->delete_records('local_ai_course_assistant_ut_resp', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            $DB->delete_records('local_ai_course_assistant_audit', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
            $DB->delete_records('local_ai_course_assistant_practice_scores', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);

            // v5.3.18: same coverage extension as delete_data_for_user.
            $courseidkeyed = [
                'local_ai_course_assistant_msg_ratings',
                'local_ai_course_assistant_profiles',
                'local_ai_course_assistant_obj_att',
                'local_ai_course_assistant_flashcards',
                'local_ai_course_assistant_avatar_sess',
                'local_ai_course_assistant_learner_goals',
                'local_ai_course_assistant_learner_memory',
                'local_ai_course_assistant_streak',
                'local_ai_course_assistant_struggle_signal',
                'local_ai_course_assistant_outreach_log',
            ];
            foreach ($courseidkeyed as $table) {
                try {
                    $DB->delete_records($table, [
                        'userid' => $userid,
                        'courseid' => $context->instanceid,
                    ]);
                } catch (\Throwable $e) { /* ignore */ }
            }
            try {
                $DB->delete_records('local_ai_course_assistant_review_res', [
                    'resolved_by' => $userid,
                    'courseid' => $context->instanceid,
                ]);
            } catch (\Throwable $e) { /* ignore */ }
            try {
                $DB->delete_records('local_ai_course_assistant_radar_sched', [
                    'creator' => $userid,
                    'courseid' => $context->instanceid,
                ]);
            } catch (\Throwable $e) { /* ignore */ }
        }
    }
}
