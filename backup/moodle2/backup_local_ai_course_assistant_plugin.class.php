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
 * Course backup for local_ai_course_assistant.
 *
 * The plugin stores per-course configuration and per-learner activity against a
 * courseid. Before this existed, duplicating or restoring a course silently
 * dropped all of it: the course's AI configuration, its learning objectives, its
 * Soapbox assignments and every learner's conversation history.
 *
 * WHAT IS DELIBERATELY NOT BACKED UP, and why:
 *
 *  - chunks. The RAG index: content chunks and their embedding vectors, keyed on
 *    courseid. Excluded because it is derived data that is rebuilt by reindexing
 *    the course, and because it is by far the largest table the plugin owns --
 *    on Saylor's production site it holds six figures of rows. Carrying it inside
 *    every course backup would multiply backup size for something a single admin
 *    action regenerates. Restored courses simply need a reindex.
 *  - keywords. Cached keyword extraction, likewise derived.
 *  - audit. A security record of activity on the ORIGINATING site. Copying it
 *    into a restored course would attribute events to the wrong site.
 *  - email_optout. A learner's site-wide contact preference, not course content.
 *  - outreach_log. Delivery history; meaningless once restored elsewhere.
 *  - review_res. Moderation-queue state belonging to the original site's staff.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_ai_course_assistant_plugin extends backup_local_plugin {

    /**
     * Structure attached at the course level.
     *
     * @return backup_plugin_element
     */
    protected function define_course_plugin_structure() {
        global $DB;

        // Only include user activity when the backup is set to include users.
        $userinfo = $this->get_setting_value('users');

        $plugin = $this->get_plugin_element(null, null, null);
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        // ---------- course configuration (no user data) ----------

        $configs = new backup_nested_element('aica_course_configs');
        $config = new backup_nested_element('aica_course_config', ['id'], [
            // apikey and apibaseurl are deliberately omitted: a per-course
            // credential must not travel inside a course backup file.
            'enabled', 'provider', 'model', 'systemprompt', 'temperature',
            'timecreated', 'timemodified',
        ]);
        $wrapper->add_child($configs);
        $configs->add_child($config);
        $config->set_source_table('local_ai_course_assistant_course_cfg', ['courseid' => backup::VAR_COURSEID]);

        // ---------- per-course overrides held in config_plugins ----------
        //
        // The course_cfg table carries only provider, model, system prompt and
        // temperature. Every other per-course decision on the Course AI Settings
        // page -- Socratic mode, flashcards, sandbox, essay feedback, Soapbox,
        // worked examples, external resources, the voice tab, auto-open, digest
        // email, English lock, the per-course RAG toggle, the widget on/off
        // choice and all seven starter chips -- is stored as a config_plugins row
        // named "<setting>_course_<courseid>". None of it travelled, so a
        // duplicated course kept its model and prompt (making the copy look
        // correct) while every pedagogy decision silently reverted to the site
        // default.
        //
        // Credentials are excluded here rather than on restore, so they never
        // enter the backup file at all.
        $courseid = (int) $this->task->get_courseid();
        $settings = new backup_nested_element('aica_course_settings');
        $setting = new backup_nested_element('aica_course_setting', ['id'], ['name', 'value']);
        $wrapper->add_child($settings);
        $settings->add_child($setting);

        // set_source_array rather than set_source_sql: in a backup structure a
        // string param value is interpreted as a path to another element, so a
        // literal like the plugin name is looked up as an element and the plan
        // fails to build. Reading the config in PHP is also an exact suffix
        // match, where a LIKE would need escaping that MySQL string literals
        // then re-interpret.
        $suffix = '_course_' . $courseid;
        $rows = [];
        $rowid = 0;
        foreach ((array) get_config('local_ai_course_assistant') as $key => $value) {
            if (substr($key, -strlen($suffix)) !== $suffix) {
                continue;
            }
            foreach (['apikey', 'token', 'secret', 'password', 'webhook'] as $deny) {
                if (stripos($key, $deny) !== false) {
                    continue 2;
                }
            }
            $rows[] = (object) [
                'id' => ++$rowid,
                'name' => $key,
                'value' => (string) $value,
            ];
        }
        $setting->set_source_array($rows);

        // Per-quiz assistance levels. cmid is remapped on restore.
        $quizcfgs = new backup_nested_element('aica_quiz_cfgs');
        $quizcfg = new backup_nested_element('aica_quiz_cfg', ['id'], [
            'cmid', 'assistance_level', 'timecreated', 'timemodified',
        ]);
        $wrapper->add_child($quizcfgs);
        $quizcfgs->add_child($quizcfg);
        $quizcfg->set_source_table('local_ai_course_assistant_quiz_cfg', ['courseid' => backup::VAR_COURSEID]);

        // Learning objectives, with learner attempts nested beneath them so the
        // attempt's objectiveid can be remapped to the restored objective.
        $objectives = new backup_nested_element('aica_objectives');
        $objective = new backup_nested_element('aica_objective', ['id'], [
            'sortorder', 'code', 'title', 'description', 'source',
            'external_ref', 'prereq_ids', 'timecreated', 'timemodified',
        ]);
        $wrapper->add_child($objectives);
        $objectives->add_child($objective);
        $objective->set_source_table('local_ai_course_assistant_objs', ['courseid' => backup::VAR_COURSEID]);

        $objattempts = new backup_nested_element('aica_obj_attempts');
        $objattempt = new backup_nested_element('aica_obj_attempt', ['id'], [
            'userid', 'source', 'iscorrect', 'weight', 'confidence', 'score',
            'timecreated',
        ]);
        $objective->add_child($objattempts);
        $objattempts->add_child($objattempt);
        if ($userinfo) {
            $objattempt->set_source_table(
                'local_ai_course_assistant_obj_att',
                ['objectiveid' => backup::VAR_PARENTID]
            );
        }

        // Practice rubrics.
        $rubrics = new backup_nested_element('aica_rubrics');
        $rubric = new backup_nested_element('aica_rubric', ['id'], [
            'type', 'title', 'criteria', 'active', 'timecreated', 'timemodified',
        ]);
        $wrapper->add_child($rubrics);
        $rubrics->add_child($rubric);
        $rubric->set_source_table('local_ai_course_assistant_rubrics', ['courseid' => backup::VAR_COURSEID]);

        // Soapbox assignments, with their selectable topics nested.
        $sbxassigns = new backup_nested_element('aica_sbx_assigns');
        $sbxassign = new backup_nested_element('aica_sbx_assign', ['id'], [
            'name', 'intro', 'introformat', 'ptype', 'mode', 'min_seconds',
            'max_seconds', 'max_attempts', 'stored_attempts', 'rubricid',
            'speaking_level', 'slides_enabled', 'slide_vision', 'visible',
            'timecreated', 'timemodified',
        ]);
        $wrapper->add_child($sbxassigns);
        $sbxassigns->add_child($sbxassign);
        $sbxassign->set_source_table('local_ai_course_assistant_sbx_assign', ['courseid' => backup::VAR_COURSEID]);

        $sbxtopics = new backup_nested_element('aica_sbx_topics');
        $sbxtopic = new backup_nested_element('aica_sbx_topic', ['id'], [
            'title', 'instructions', 'instructionsformat', 'sortorder',
            'timecreated', 'timemodified',
        ]);
        $sbxassign->add_child($sbxtopics);
        $sbxtopics->add_child($sbxtopic);
        $sbxtopic->set_source_table('local_ai_course_assistant_sbx_topic', ['assignid' => '../../id']);

        // ---------- learner activity (users only) ----------

        if ($userinfo) {
            // Conversations, with their messages and any ratings on those
            // messages nested, so message ids never need remapping across files.
            $convs = new backup_nested_element('aica_convs');
            $conv = new backup_nested_element('aica_conv', ['id'], [
                'userid', 'title', 'offtopic_count', 'offtopic_locked_until',
                'timecreated', 'timemodified',
            ]);
            $wrapper->add_child($convs);
            $convs->add_child($conv);
            $conv->set_source_table('local_ai_course_assistant_convs', ['courseid' => backup::VAR_COURSEID]);

            $msgs = new backup_nested_element('aica_msgs');
            $msg = new backup_nested_element('aica_msg', ['id'], [
                'userid', 'role', 'message', 'tokens_used', 'prompt_tokens',
                'completion_tokens', 'model_name', 'provider', 'interaction_type',
                'cmid', 'rag_latency_ms', 'cached_tokens', 'stream_outcome',
                'chunk_count', 'top_score', 'timecreated',
            ]);
            $conv->add_child($msgs);
            $msgs->add_child($msg);
            $msg->set_source_table('local_ai_course_assistant_msgs', ['conversationid' => backup::VAR_PARENTID]);

            $ratings = new backup_nested_element('aica_msg_ratings');
            $rating = new backup_nested_element('aica_msg_rating', ['id'], [
                'userid', 'rating', 'is_hallucination', 'comment', 'timecreated',
            ]);
            $msg->add_child($ratings);
            $ratings->add_child($rating);
            $rating->set_source_table('local_ai_course_assistant_msg_ratings', ['messageid' => backup::VAR_PARENTID]);

            // Flat per-learner tables.
            $this->add_simple_user_table(
                $wrapper,
                'aica_plans',
                'aica_plan',
                'local_ai_course_assistant_plans',
                ['userid', 'hours_per_week', 'plan_data', 'preferred_days',
                    'preferred_time', 'timecreated', 'timemodified']
            );
            $this->add_simple_user_table(
                $wrapper,
                'aica_reminders',
                'aica_reminder',
                'local_ai_course_assistant_reminders',
                // unsubscribe_token is omitted: it is a per-site secret and a
                // restored copy must mint its own.
                ['userid', 'channel', 'destination', 'country_code', 'frequency',
                    'enabled', 'last_sent', 'timecreated', 'timemodified']
            );
            $this->add_simple_user_table(
                $wrapper,
                'aica_learner_goals',
                'aica_learner_goal',
                'local_ai_course_assistant_learner_goals',
                ['userid', 'q1_answer', 'q2_answer', 'q3_answer', 'consented_at',
                    'dismissed_at', 'timecreated', 'timemodified']
            );
            $this->add_simple_user_table(
                $wrapper,
                'aica_streaks',
                'aica_streak',
                'local_ai_course_assistant_streak',
                ['userid', 'current_streak_days', 'longest_streak_days',
                    'last_active_date', 'last_milestone_kind', 'last_milestone_at',
                    'timecreated', 'timemodified']
            );
            $this->add_simple_user_table(
                $wrapper,
                'aica_practice_scores',
                'aica_practice_score',
                'local_ai_course_assistant_practice_scores',
                ['userid', 'rubricid', 'session_type', 'scores', 'overall_score',
                    'ai_feedback', 'session_duration', 'session_meta', 'timecreated']
            );
            $this->add_simple_user_table(
                $wrapper,
                'aica_flashcards',
                'aica_flashcard',
                'local_ai_course_assistant_flashcards',
                ['userid', 'cmid', 'objectiveid', 'question', 'answer', 'ease',
                    'interval_days', 'repetitions', 'next_review', 'timecreated',
                    'timemodified']
            );
        }

        // userid fields are annotated so the restore can map them to users on
        // the target site; cmid fields go through the course_module mapping.
        $this->annotate_user_ids($wrapper);

        return $plugin;
    }

    /**
     * Attach a flat, course-scoped, per-user table under the wrapper.
     *
     * These all have the same shape -- one row per learner per course, no
     * children -- so declaring them individually would be six copies of the
     * same eight lines.
     *
     * @param backup_nested_element $wrapper Parent element.
     * @param string $plural Wrapper element name.
     * @param string $singular Row element name.
     * @param string $table Database table name.
     * @param array $fields Fields to include.
     * @return void
     */
    private function add_simple_user_table(
        backup_nested_element $wrapper,
        string $plural,
        string $singular,
        string $table,
        array $fields
    ): void {
        $parent = new backup_nested_element($plural);
        $child = new backup_nested_element($singular, ['id'], $fields);
        $wrapper->add_child($parent);
        $parent->add_child($child);
        $child->set_source_table($table, ['courseid' => backup::VAR_COURSEID]);
    }

    /**
     * Annotate every userid in the tree so users are included in the backup.
     *
     * @param backup_nested_element $element Root of the plugin subtree.
     * @return void
     */
    private function annotate_user_ids(backup_nested_element $element): void {
        foreach ($element->get_children() as $child) {
            if ($child instanceof backup_nested_element) {
                if (in_array('userid', array_keys($child->get_final_elements()), true)) {
                    $child->annotate_ids('user', 'userid');
                }
                $this->annotate_user_ids($child);
            }
        }
    }
}
