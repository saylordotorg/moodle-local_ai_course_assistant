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
 * Course restore for local_ai_course_assistant.
 *
 * Mirrors backup_local_ai_course_assistant_plugin. Three kinds of id need
 * translating on the way in and each is handled explicitly:
 *
 *  - userid, through the 'user' mapping, so activity lands on the right person
 *    on the target site (and is dropped when that person does not exist).
 *  - cmid, through the 'course_module' mapping, for per-quiz assistance levels
 *    and for the activity a message or flashcard was anchored to. A cmid that
 *    does not resolve is stored as null rather than left pointing at whatever
 *    module happens to hold that id on the new site -- the same class of bug as
 *    the cross-course read fixed in v7.0.5.
 *  - objectiveid and rubricid, through mappings this class sets itself, since
 *    those rows are created during this same restore.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_ai_course_assistant_plugin extends restore_local_plugin {

    /**
     * Tables reached by insert_simple_user_row() that carry a unique constraint,
     * mapped to the fields that constraint covers.
     *
     * Taken from db/install.xml rather than assumed: plans, learner_goals and
     * streak are unique on (userid, courseid); reminders adds channel. The other
     * callers -- practice_scores and flashcards -- are UNIQUE="false" and are
     * deliberately absent, so repeat rows are allowed there.
     */
    private const UNIQUE_KEYS = [
        'local_ai_course_assistant_plans' => ['userid', 'courseid'],
        'local_ai_course_assistant_learner_goals' => ['userid', 'courseid'],
        'local_ai_course_assistant_streak' => ['userid', 'courseid'],
        'local_ai_course_assistant_reminders' => ['userid', 'courseid', 'channel'],
    ];

    /**
     * Paths this plugin claims at the course level.
     *
     * @return restore_path_element[]
     */
    protected function define_course_plugin_structure() {
        $paths = [];

        $elepath = $this->get_pathfor('/');

        $paths[] = new restore_path_element(
            'aica_course_config', $elepath . '/aica_course_configs/aica_course_config');
        $paths[] = new restore_path_element(
            'aica_course_setting', $elepath . '/aica_course_settings/aica_course_setting');
        $paths[] = new restore_path_element(
            'aica_quiz_cfg', $elepath . '/aica_quiz_cfgs/aica_quiz_cfg');
        $paths[] = new restore_path_element(
            'aica_objective', $elepath . '/aica_objectives/aica_objective');
        $paths[] = new restore_path_element(
            'aica_obj_attempt',
            $elepath . '/aica_objectives/aica_objective/aica_obj_attempts/aica_obj_attempt');
        $paths[] = new restore_path_element(
            'aica_rubric', $elepath . '/aica_rubrics/aica_rubric');
        $paths[] = new restore_path_element(
            'aica_sbx_assign', $elepath . '/aica_sbx_assigns/aica_sbx_assign');
        $paths[] = new restore_path_element(
            'aica_sbx_topic',
            $elepath . '/aica_sbx_assigns/aica_sbx_assign/aica_sbx_topics/aica_sbx_topic');
        $paths[] = new restore_path_element(
            'aica_conv', $elepath . '/aica_convs/aica_conv');
        $paths[] = new restore_path_element(
            'aica_msg', $elepath . '/aica_convs/aica_conv/aica_msgs/aica_msg');
        $paths[] = new restore_path_element(
            'aica_msg_rating',
            $elepath . '/aica_convs/aica_conv/aica_msgs/aica_msg/aica_msg_ratings/aica_msg_rating');

        foreach ([
            'aica_plan' => 'aica_plans',
            'aica_reminder' => 'aica_reminders',
            'aica_learner_goal' => 'aica_learner_goals',
            'aica_streak' => 'aica_streaks',
            'aica_practice_score' => 'aica_practice_scores',
            'aica_flashcard' => 'aica_flashcards',
        ] as $singular => $plural) {
            $paths[] = new restore_path_element($singular, $elepath . '/' . $plural . '/' . $singular);
        }

        return $paths;
    }

    /**
     * Per-course AI configuration.
     *
     * @param array $data
     * @return void
     */
    /**
     * A per-course override stored in config_plugins as "<setting>_course_<id>".
     *
     * The course id is part of the setting name, so it is remapped here the way
     * a foreign key would be elsewhere. Without this the row would either be
     * dropped or, worse, written back against the ORIGINATING course and change
     * the settings of the course that was backed up.
     *
     * @param array|object $data
     * @return void
     */
    public function process_aica_course_setting($data) {
        $data = (object) $data;
        $name = (string) ($data->name ?? '');
        if ($name === '') {
            return;
        }

        // Defence in depth: the backup already excludes credentials, but an
        // archive built by an older release, or edited by hand, must not be able
        // to write one into this site's config.
        foreach (['apikey', 'token', 'secret', 'password', 'webhook'] as $deny) {
            if (stripos($name, $deny) !== false) {
                return;
            }
        }

        // Only ever rewrite a trailing course id, and only when there is one.
        if (!preg_match('/_course_\d+$/', $name)) {
            return;
        }
        $newname = preg_replace(
            '/_course_\d+$/',
            '_course_' . (int) $this->task->get_courseid(),
            $name
        );

        // Restoring into a course that already carries this override must not
        // silently overwrite the teacher's current choice, matching how the
        // course_cfg row is handled below.
        if (get_config('local_ai_course_assistant', $newname) !== false) {
            return;
        }
        set_config($newname, $data->value, 'local_ai_course_assistant');
    }

    public function process_aica_course_config($data) {
        global $DB;

        $data = (object) $data;
        $data->courseid = $this->task->get_courseid();
        unset($data->id);

        // A course can only hold one configuration row. Restoring into a course
        // that already has one must not create a second.
        if ($DB->record_exists('local_ai_course_assistant_course_cfg', ['courseid' => $data->courseid])) {
            return;
        }
        $DB->insert_record('local_ai_course_assistant_course_cfg', $data);
    }

    /**
     * Per-quiz assistance level.
     *
     * @param array $data
     * @return void
     */
    public function process_aica_quiz_cfg($data) {
        global $DB;

        $data = (object) $data;
        $data->courseid = $this->task->get_courseid();
        $cmid = $this->get_mappingid('course_module', $data->cmid);
        if (!$cmid) {
            // The quiz did not come across. Dropping the row is correct: keeping
            // it would attach an assistance level to an unrelated module.
            return;
        }
        $data->cmid = $cmid;
        unset($data->id);
        if ($DB->record_exists('local_ai_course_assistant_quiz_cfg', ['cmid' => $data->cmid])) {
            return;
        }
        $DB->insert_record('local_ai_course_assistant_quiz_cfg', $data);
    }

    /**
     * Learning objective.
     *
     * @param array $data
     * @return void
     */
    public function process_aica_objective($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->courseid = $this->task->get_courseid();
        unset($data->id);
        $newid = $DB->insert_record('local_ai_course_assistant_objs', $data);
        $this->set_mapping('aica_objective', $oldid, $newid);
    }

    /**
     * Learner attempt against an objective.
     *
     * @param array $data
     * @return void
     */
    public function process_aica_obj_attempt($data) {
        global $DB;

        $data = (object) $data;
        $userid = $this->get_mappingid('user', $data->userid);
        $objectiveid = $this->get_new_parentid('aica_objective');
        if (!$userid || !$objectiveid) {
            return;
        }
        $data->userid = $userid;
        $data->objectiveid = $objectiveid;
        $data->courseid = $this->task->get_courseid();
        // msgid points into a message row that may not have been restored, and
        // it is only ever used for provenance, so it is not carried across.
        $data->msgid = null;
        unset($data->id);
        $DB->insert_record('local_ai_course_assistant_obj_att', $data);
    }

    /**
     * Practice rubric.
     *
     * @param array $data
     * @return void
     */
    public function process_aica_rubric($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->courseid = $this->task->get_courseid();
        unset($data->id);
        $newid = $DB->insert_record('local_ai_course_assistant_rubrics', $data);
        $this->set_mapping('aica_rubric', $oldid, $newid);
    }

    /**
     * Soapbox assignment.
     *
     * @param array $data
     * @return void
     */
    public function process_aica_sbx_assign($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->courseid = $this->task->get_courseid();
        // Rubrics are restored before assignments in document order, so the
        // mapping is available; fall back to unset rather than a stale id.
        if (!empty($data->rubricid)) {
            $data->rubricid = $this->get_mappingid('aica_rubric', $data->rubricid) ?: null;
        }
        unset($data->id);
        $newid = $DB->insert_record('local_ai_course_assistant_sbx_assign', $data);
        $this->set_mapping('aica_sbx_assign', $oldid, $newid);
    }

    /**
     * Selectable topic under a Soapbox assignment.
     *
     * @param array $data
     * @return void
     */
    public function process_aica_sbx_topic($data) {
        global $DB;

        $data = (object) $data;
        $assignid = $this->get_new_parentid('aica_sbx_assign');
        if (!$assignid) {
            return;
        }
        $data->assignid = $assignid;
        unset($data->id);
        $DB->insert_record('local_ai_course_assistant_sbx_topic', $data);
    }

    /**
     * Conversation.
     *
     * @param array $data
     * @return void
     */
    public function process_aica_conv($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $userid = $this->get_mappingid('user', $data->userid);
        if (!$userid) {
            return;
        }
        $data->userid = $userid;
        $data->courseid = $this->task->get_courseid();
        unset($data->id);

        // convs carries UNIQUE (userid, courseid). Restoring into a course that
        // already holds this learner's conversation would abort the restore, so
        // reuse the existing row and map onto it -- the messages beneath then
        // append to the conversation already there, which is what a merge
        // restore should do.
        $existing = $DB->get_field(
            'local_ai_course_assistant_convs',
            'id',
            ['userid' => $userid, 'courseid' => $data->courseid]
        );
        $newid = $existing ?: $DB->insert_record('local_ai_course_assistant_convs', $data);
        $this->set_mapping('aica_conv', $oldid, $newid);
    }

    /**
     * Message within a conversation.
     *
     * @param array $data
     * @return void
     */
    public function process_aica_msg($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $conversationid = $this->get_new_parentid('aica_conv');
        $userid = $this->get_mappingid('user', $data->userid);
        if (!$conversationid || !$userid) {
            return;
        }
        $data->conversationid = $conversationid;
        $data->userid = $userid;
        $data->courseid = $this->task->get_courseid();
        $data->cmid = !empty($data->cmid)
            ? ($this->get_mappingid('course_module', $data->cmid) ?: null)
            : null;
        unset($data->id);
        $newid = $DB->insert_record('local_ai_course_assistant_msgs', $data);
        $this->set_mapping('aica_msg', $oldid, $newid);
    }

    /**
     * Rating on a message.
     *
     * @param array $data
     * @return void
     */
    public function process_aica_msg_rating($data) {
        global $DB;

        $data = (object) $data;
        $messageid = $this->get_new_parentid('aica_msg');
        $userid = $this->get_mappingid('user', $data->userid);
        if (!$messageid || !$userid) {
            return;
        }
        $data->messageid = $messageid;
        $data->userid = $userid;
        $data->courseid = $this->task->get_courseid();
        unset($data->id);
        $DB->insert_record('local_ai_course_assistant_msg_ratings', $data);
    }

    /**
     * Study plan.
     *
     * @param array $data
     * @return void
     */
    public function process_aica_plan($data) {
        $this->insert_simple_user_row('local_ai_course_assistant_plans', $data);
    }

    /**
     * Reminder subscription.
     *
     * @param array $data
     * @return void
     */
    public function process_aica_reminder($data) {
        // unsubscribe_token is NOT NULL and site-specific, so a restored copy
        // mints a fresh one rather than inheriting the original's secret.
        $data = (array) $data;
        $data['unsubscribe_token'] = bin2hex(random_bytes(16));
        $this->insert_simple_user_row('local_ai_course_assistant_reminders', $data);
    }

    /**
     * Learner onboarding goals.
     *
     * @param array $data
     * @return void
     */
    public function process_aica_learner_goal($data) {
        $this->insert_simple_user_row('local_ai_course_assistant_learner_goals', $data);
    }

    /**
     * Streak counters.
     *
     * @param array $data
     * @return void
     */
    public function process_aica_streak($data) {
        $this->insert_simple_user_row('local_ai_course_assistant_streak', $data);
    }

    /**
     * Practice score.
     *
     * @param array $data
     * @return void
     */
    public function process_aica_practice_score($data) {
        global $DB;

        $data = (array) $data;

        // rubricid is NOT NULL, so it cannot simply be nulled when the mapping
        // misses -- and it misses on the COMMON case, not an edge one: the
        // default rubrics are global (courseid = 0), a course backup only carries
        // course-scoped ones, so any score written against a default rubric has
        // no mapping. Nulling it aborted the whole restore with a
        // dml_write_exception.
        //
        // Resolve in order: the rubric restored alongside this course, then a
        // global rubric of the same type on the target site, then give up on the
        // row. Losing one score is better than losing the restore.
        $mapped = !empty($data['rubricid'])
            ? $this->get_mappingid('aica_rubric', $data['rubricid'])
            : 0;

        if (!$mapped) {
            $type = (string) ($data['session_type'] ?? '');
            $mapped = (int) $DB->get_field_select(
                'local_ai_course_assistant_rubrics',
                'id',
                'courseid = 0' . ($type !== '' ? ' AND type = :type' : ''),
                $type !== '' ? ['type' => $type] : [],
                IGNORE_MULTIPLE
            );
        }
        if (!$mapped) {
            return;
        }
        $data['rubricid'] = $mapped;

        $this->insert_simple_user_row('local_ai_course_assistant_practice_scores', $data);
    }

    /**
     * Flashcard.
     *
     * @param array $data
     * @return void
     */
    public function process_aica_flashcard($data) {
        $data = (array) $data;
        $data['cmid'] = !empty($data['cmid'])
            ? ($this->get_mappingid('course_module', $data['cmid']) ?: null)
            : null;
        if (!empty($data['objectiveid'])) {
            $data['objectiveid'] = $this->get_mappingid('aica_objective', $data['objectiveid']) ?: null;
        }
        $this->insert_simple_user_row('local_ai_course_assistant_flashcards', $data);
    }

    /**
     * Insert a flat per-learner row, remapping the user and course.
     *
     * Skips the row when the user does not exist on the target site, which is
     * the normal case for a course restored without users or onto a fresh site.
     *
     * @param string $table
     * @param array|object $data
     * @return void
     */
    private function insert_simple_user_row(string $table, $data): void {
        global $DB;

        $data = (object) $data;
        $userid = $this->get_mappingid('user', $data->userid ?? 0);
        if (!$userid) {
            return;
        }
        $data->userid = $userid;
        $data->courseid = $this->task->get_courseid();
        unset($data->id);

        // Several of these tables carry a unique constraint on (userid,
        // courseid) -- plans, learner_goals and streak -- and reminders adds
        // channel to it. A merge restore into a course that already holds the
        // learner's row would abort the whole restore on a duplicate key, so
        // skip rather than insert. The existing row is the live one and the
        // backup's copy is by definition older.
        $unique = self::UNIQUE_KEYS[$table] ?? null;
        if ($unique !== null) {
            $conditions = [];
            foreach ($unique as $field) {
                $conditions[$field] = $data->$field ?? null;
            }
            if ($DB->record_exists($table, $conditions)) {
                return;
            }
        }

        $DB->insert_record($table, $data);
    }
}
