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
 * Meta-test: every plugin lang key referenced from a mustache template
 * or a PHP file must be defined in lang/en/local_ai_course_assistant.php
 * (v5.3.19).
 *
 * Catches the class of bug where new code references a string that was
 * never added (the v5.3.17 missing `messageprovider:study_reminder` is
 * a concrete example — caught only because Moodle's own test happened
 * to run on it). Generalises that check to every key the plugin uses.
 *
 * Scans:
 *   - All `*.mustache` templates for `{{#str KEY, local_ai_course_assistant}}`.
 *   - All `*.php` files for `get_string('KEY', 'local_ai_course_assistant')`.
 *
 * Skips dynamically-built keys (e.g. `'foo:' . $bar`) since those need
 * runtime context to resolve.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lang_completeness_test extends \basic_testcase {
    /**
     * Plugin root.
     *
     * @return string
     */
    private function plugin_root(): string {
        global $CFG;
        return $CFG->dirroot . '/local/ai_course_assistant';
    }

    /**
     * Load the EN string set.
     *
     * @return array<string, string>
     */
    private function load_en_strings(): array {
        $string = [];
        include($this->plugin_root() . '/lang/en/local_ai_course_assistant.php');
        return $string;
    }

    /**
     * Walk a directory and return every file matching the extension list.
     *
     * @param string $dir
     * @param array $exts list of file extensions to include (e.g. ['.php', '.mustache'])
     * @return array list of absolute file paths matching one of $exts
     */
    private function walk(string $dir, array $exts): array {
        $out = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iter as $f) {
            $path = $f->getPathname();
            // Skip the test dir itself, vendor, build artefacts, drafts.
            if (preg_match('#/(\.git|node_modules|vendor|amd/build|tests/|\.drafts/|\.wiki/)#', $path)) {
                continue;
            }
            foreach ($exts as $ext) {
                if (str_ends_with($path, $ext)) {
                    $out[] = $path;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Extract every static lang key referenced from mustache + PHP files.
     *
     * @return array<int, array{key: string, file: string}>
     */
    private function referenced_keys(): array {
        $refs = [];
        $component = 'local_ai_course_assistant';

        // Mustache: `{{#str KEY, local_ai_course_assistant}}`
        foreach ($this->walk($this->plugin_root() . '/templates', ['.mustache']) as $f) {
            $body = file_get_contents($f);
            if (
                preg_match_all(
                    '/\{\{#str\}\}\s*([a-zA-Z0-9_:]+)\s*,\s*' . preg_quote($component, '/') . '\s*\{\{\/str\}\}/',
                    $body,
                    $m
                )
            ) {
                foreach ($m[1] as $k) {
                    $refs[] = ['key' => $k, 'file' => basename($f)];
                }
            }
            // Older mustache form: `{{#str}}KEY, comp{{/str}}`
            if (
                preg_match_all(
                    '/\{\{#str\}\}\s*([a-zA-Z0-9_:]+)\s*,\s*' . preg_quote($component, '/') . '\s*\{\{\/str\}\}/s',
                    $body,
                    $m2
                )
            ) {
                foreach ($m2[1] as $k) {
                    $refs[] = ['key' => $k, 'file' => basename($f)];
                }
            }
        }

        // PHP: `get_string('KEY', 'local_ai_course_assistant')`. We only
        // capture single-quoted literal keys; dynamic keys (concatenation
        // or interpolation) are skipped because they need runtime context.
        foreach ($this->walk($this->plugin_root(), ['.php']) as $f) {
            $body = file_get_contents($f);
            if (
                preg_match_all(
                    "/get_string\(\s*'([a-zA-Z0-9_:]+)'\s*,\s*'" . preg_quote($component, '/') . "'/",
                    $body,
                    $m
                )
            ) {
                foreach ($m[1] as $k) {
                    $refs[] = ['key' => $k, 'file' => str_replace($this->plugin_root() . '/', '', $f)];
                }
            }
        }

        return $refs;
    }

    public function test_every_referenced_key_is_defined(): void {
        $defined = $this->load_en_strings();
        $refs = $this->referenced_keys();
        $this->assertNotEmpty($refs, 'Reference scan must find at least some keys');

        $missing = [];
        foreach ($refs as $r) {
            if (!array_key_exists($r['key'], $defined)) {
                $missing[$r['key']] = ($missing[$r['key']] ?? []);
                $missing[$r['key']][] = $r['file'];
            }
        }

        if (!empty($missing)) {
            $report = [];
            foreach ($missing as $k => $files) {
                $report[] = $k . ' (referenced in: ' . implode(', ', array_unique($files)) . ')';
            }
            $this->fail("Lang keys referenced in templates/PHP but missing from "
                . "lang/en/local_ai_course_assistant.php:\n  - "
                . implode("\n  - ", $report));
        }
    }

    /**
     * English keys that are knowingly not translated yet.
     *
     * The pre-7.0.0 audit found the plugin was 8 keys short of the 46/46 parity
     * the docs claim, and nothing detected it: the reference test only proves a
     * key exists in English, so a new English-only string was invisible.
     *
     * This list is the debt, not an exemption. Removing an entry is what a
     * translation batch does, and v7.5.3 removed 401 of them: every
     * administrator and teacher surface extracted since v7.0.1, translated into
     * all 45 locales with placeholder, branding-token, HTML and <code> parity
     * checked mechanically on every string.
     *
     * The teacher half of that was overdue rather than optional. course_settings.php
     * is gated on local/ai_course_assistant:manage, which editing teachers hold, so
     * its twenty-five strings had been showing English to every non-English teacher
     * on every site while the docs claimed 46/46.
     *
     * What is left is eleven keys with a reason each, below. Adding one should be
     * a deliberate, reviewed act, because the parity test fails the moment an
     * unlisted key appears in English without translations, which is the drift
     * that went unnoticed.
     */
    private const KNOWN_UNTRANSLATED = [
        // The video rubric tab. NOT deferred translation debt: the tab was specced
        // and cut, so nothing in the product can reach these. rubric_admin.php
        // whitelists conversation, pronunciation and speech only, and clamps an
        // unknown ?type back to conversation. They are held rather than deleted
        // because the video rubric TYPE is live (rubric_manager::TYPE_VIDEO,
        // reached from score_speech), so these are the labels that ship with the
        // tab when it exists. Do not send them to a translator until it does.
        'rubric_admin:tab_video',
        'rubric_admin:rubric_title_video',
        'rubric_admin:needs_video',
        'rubric_admin:needs_video_help',
        'rubric_admin:needs_video_aria',
        'rubric_admin:preview_conditional',
        'rubric_admin:preview_total_novideo',
        // Two pairs of settings copy whose whole content is a consequence an
        // administrator would act on, where a subtly wrong translation is worse
        // than an honest English fallback.
        //
        // outcomes_panel_enabled explains a capability boundary in another plugin
        // and what happens if you enable the panel before that plugin is new
        // enough. Getting it slightly wrong means an administrator switches it on
        // believing something false about who can see the result.
        'settings:outcomes_panel_enabled',
        'settings:outcomes_panel_enabled_desc',
        // soapbox_gesture_vision turns on scoring a learner's body language from
        // sampled video stills. What it does and what it retains is the entire
        // point of the description.
        'settings:soapbox_gesture_vision',
        'settings:soapbox_gesture_vision_desc',
    ];

    /**
     * Keys defined in a locale file.
     *
     * @param string $lang locale directory name.
     * @return array<string, true>
     */
    private function locale_keys(string $lang): array {
        $path = $this->plugin_root() . '/lang/' . $lang . '/local_ai_course_assistant.php';
        if (!file_exists($path)) {
            return [];
        }
        $string = [];
        include($path);
        return $string;
    }

    public function test_translation_parity_has_not_regressed(): void {
        $en = array_keys($this->load_en_strings());
        $locales = array_filter(
            scandir($this->plugin_root() . '/lang'),
            fn($d) => $d !== '.' && $d !== '..' && $d !== 'en'
        );
        $this->assertGreaterThan(40, count($locales), 'expected the full locale set');

        $unexpected = [];
        foreach ($locales as $lang) {
            $keys = $this->locale_keys($lang);
            foreach ($en as $key) {
                if (
                    !array_key_exists($key, $keys)
                        && !in_array($key, self::KNOWN_UNTRANSLATED, true)
                ) {
                    $unexpected[$key][] = $lang;
                }
            }
        }

        if (!empty($unexpected)) {
            $report = [];
            foreach ($unexpected as $key => $langs) {
                $report[] = $key . ' (missing from ' . count($langs) . ' locales)';
            }
            $this->fail("English strings with no translations, and not listed in "
                . "KNOWN_UNTRANSLATED. Either translate them or add them to that "
                . "list deliberately:\n  - " . implode("\n  - ", $report));
        }
    }

    /**
     * Strings that are identical to English in every locale on purpose, and
     * always will be. Acronyms (CSV, DPA, LLM, RAG), product names (Redash,
     * Soapbox, OpenAI, OpenRouter) and strings whose entire content is a
     * placeholder token ([[tutorshort]]). Translating these would be wrong,
     * not merely unnecessary.
     */
    private const TRANSLATION_NOT_REQUIRED = [
        'admin:vendor_dpa:col_dpa',
        'analytics:format_csv',
        'analytics:format_json',
        'analytics:format_markdown',
        'analytics:redash',
        'chat:assistant',
        'chat:llm_label',
        'chat:title',
        'emergency:flag_rag',
        'settings:provider_coreai',
        'settings:provider_deepseek',
        'settings:provider_minimax',
        'settings:provider_openai',
        'settings:provider_openrouter',
        'settings:soapbox_heading',
        'soapbox:title',
    ];

    /**
     * Keys that ARE defined in all 45 locales but still hold the English text.
     *
     * This is a different failure from a missing key. A missing key falls back
     * to lang/en, which is honest and is what KNOWN_UNTRANSLATED deliberately
     * relies on for its eleven. These keys instead look translated: they are
     * present in the locale file, so test_translation_parity_has_not_regressed
     * passes, and CLAUDE.md's "46/46 with zero missing keys" is true and tells
     * you nothing. 68 of them are privacy: strings, which learners read.
     *
     * The list is a backlog to be worked down, not a policy. Removing an entry
     * by translating it is the only correct way to shrink it.
     */
    private const IDENTICAL_TO_ENGLISH_BACKLOG = [
        'analytics:avatar_cost_empty',
        'analytics:avatar_cost_heading',
        'analytics:avatar_cost_minutes',
        'analytics:avatar_cost_provider',
        'analytics:avatar_cost_rate',
        'analytics:avatar_cost_sessions',
        'analytics:avatar_cost_total',
        'analytics_js:course',
        'bench:quality_of',
        'courses_admin:ai_assistant',
        'courses_admin:back_to_analytics',
        'courses_admin:click_to_disable',
        'courses_admin:click_to_enable',
        'courses_admin:column_course',
        'courses_admin:column_has_data',
        'courses_admin:disable',
        'courses_admin:disabled',
        'courses_admin:enable',
        'courses_admin:enabled',
        'courses_admin:enabled_count',
        'courses_admin:filter_disabled',
        'courses_admin:filter_enabled',
        'courses_admin:filter_status',
        'courses_admin:filter_ut',
        'courses_admin:filter_ut_inherit',
        'courses_admin:filter_ut_off',
        'courses_admin:filter_ut_on',
        'courses_admin:global_off',
        'courses_admin:global_on',
        'courses_admin:inherit',
        'courses_admin:lede',
        'courses_admin:no_courses',
        'courses_admin:off',
        'courses_admin:on',
        'courses_admin:plugin_settings',
        'courses_admin:search_placeholder',
        'courses_admin:select_all',
        'courses_admin:selected_zero',
        'courses_admin:title',
        'courses_admin:usability_testing',
        'courses_admin:yes',
        'empathy:desc',
        'empathy:goals_enabled',
        'empathy:goals_enabled_desc',
        'empathy:memory_enabled',
        'empathy:memory_enabled_desc',
        'empathy:milestones_enabled',
        'empathy:milestones_enabled_desc',
        'empathy:outreach_dryrun',
        'empathy:outreach_dryrun_desc',
        'empathy:outreach_master_enabled',
        'empathy:outreach_master_enabled_desc',
        'empathy:struggle_enabled',
        'empathy:struggle_enabled_desc',
        'empathy:title',
        'error',
        'essay_feedback:toggle',
        'instructor_dashboard:review_source_integrity',
        'instructor_dashboard:review_source_offtopic',
        'instructor_dashboard:review_source_rating',
        'integrity:desc',
        'integrity:email',
        'integrity:email_desc',
        'integrity:enabled',
        'integrity:enabled_desc',
        'integrity:run_now',
        'integrity:title',
        'integrity:view_results',
        'messageprovider:study_reminder',
        'modelregistry:cents',
        'modelregistry:ms',
        'objectives:prereqs_label',
        'objectives:prereqs_none',
        'objectives:prereqs_summary',
        'pedagogy:code_sandbox',
        'pedagogy:code_sandbox_desc',
        'pedagogy:essay_feedback',
        'pedagogy:essay_feedback_desc',
        'pedagogy:flashcards',
        'pedagogy:flashcards_desc',
        'pedagogy:mastery',
        'pedagogy:mastery_desc',
        'pedagogy:socratic_mode',
        'pedagogy:socratic_mode_desc',
        'pedagogy:talking_avatar',
        'pedagogy:talking_avatar_desc',
        'pedagogy:worked_examples',
        'pedagogy:worked_examples_desc',
        'privacy:metadata:avatar_sess',
        'privacy:metadata:avatar_sess:userid',
        'privacy:metadata:flashcards',
        'privacy:metadata:flashcards:userid',
        'privacy:metadata:learner_goals',
        'privacy:metadata:learner_goals:userid',
        'privacy:metadata:learner_memory',
        'privacy:metadata:learner_memory:userid',
        'privacy:metadata:local_ai_course_assistant_audit:action',
        'privacy:metadata:local_ai_course_assistant_audit:courseid',
        'privacy:metadata:local_ai_course_assistant_audit:details',
        'privacy:metadata:local_ai_course_assistant_audit:ipaddress',
        'privacy:metadata:local_ai_course_assistant_audit:timecreated',
        'privacy:metadata:local_ai_course_assistant_audit:useragent',
        'privacy:metadata:local_ai_course_assistant_audit:userid',
        'privacy:metadata:local_ai_course_assistant_feedback',
        'privacy:metadata:local_ai_course_assistant_feedback:browser',
        'privacy:metadata:local_ai_course_assistant_feedback:comment',
        'privacy:metadata:local_ai_course_assistant_feedback:courseid',
        'privacy:metadata:local_ai_course_assistant_feedback:device',
        'privacy:metadata:local_ai_course_assistant_feedback:os',
        'privacy:metadata:local_ai_course_assistant_feedback:page_url',
        'privacy:metadata:local_ai_course_assistant_feedback:rating',
        'privacy:metadata:local_ai_course_assistant_feedback:screen_size',
        'privacy:metadata:local_ai_course_assistant_feedback:timecreated',
        'privacy:metadata:local_ai_course_assistant_feedback:user_agent',
        'privacy:metadata:local_ai_course_assistant_feedback:userid',
        'privacy:metadata:local_ai_course_assistant_msgs:completion_tokens',
        'privacy:metadata:local_ai_course_assistant_msgs:model_name',
        'privacy:metadata:local_ai_course_assistant_msgs:prompt_tokens',
        'privacy:metadata:local_ai_course_assistant_msgs:provider',
        'privacy:metadata:local_ai_course_assistant_practice_scores',
        'privacy:metadata:local_ai_course_assistant_practice_scores:ai_feedback',
        'privacy:metadata:local_ai_course_assistant_practice_scores:courseid',
        'privacy:metadata:local_ai_course_assistant_practice_scores:overall_score',
        'privacy:metadata:local_ai_course_assistant_practice_scores:scores',
        'privacy:metadata:local_ai_course_assistant_practice_scores:session_type',
        'privacy:metadata:local_ai_course_assistant_practice_scores:timecreated',
        'privacy:metadata:local_ai_course_assistant_practice_scores:userid',
        'privacy:metadata:local_ai_course_assistant_survey_resp',
        'privacy:metadata:local_ai_course_assistant_survey_resp:answer',
        'privacy:metadata:local_ai_course_assistant_survey_resp:courseid',
        'privacy:metadata:local_ai_course_assistant_survey_resp:question_index',
        'privacy:metadata:local_ai_course_assistant_survey_resp:timecreated',
        'privacy:metadata:local_ai_course_assistant_survey_resp:userid',
        'privacy:metadata:local_ai_course_assistant_ut_resp',
        'privacy:metadata:local_ai_course_assistant_ut_resp:answer',
        'privacy:metadata:local_ai_course_assistant_ut_resp:courseid',
        'privacy:metadata:local_ai_course_assistant_ut_resp:rating',
        'privacy:metadata:local_ai_course_assistant_ut_resp:task_index',
        'privacy:metadata:local_ai_course_assistant_ut_resp:timecreated',
        'privacy:metadata:local_ai_course_assistant_ut_resp:userid',
        'privacy:metadata:msg_ratings',
        'privacy:metadata:msg_ratings:userid',
        'privacy:metadata:obj_att',
        'privacy:metadata:obj_att:userid',
        'privacy:metadata:outreach_log',
        'privacy:metadata:outreach_log:userid',
        'privacy:metadata:profiles',
        'privacy:metadata:profiles:userid',
        'privacy:metadata:radar_sched',
        'privacy:metadata:radar_sched:creator',
        'privacy:metadata:review_res',
        'privacy:metadata:review_res:resolved_by',
        'privacy:metadata:streak',
        'privacy:metadata:streak:userid',
        'privacy:metadata:struggle_signal',
        'privacy:metadata:struggle_signal:userid',
        'prompt_metrics:applied',
        'prompt_metrics:apply',
        'prompt_metrics:auto_tune_heading',
        'prompt_metrics:auto_tune_off',
        'prompt_metrics:auto_tune_on',
        'prompt_metrics:avg_budget',
        'prompt_metrics:avg_chars',
        'prompt_metrics:avg_total',
        'prompt_metrics:by_category',
        'prompt_metrics:category',
        'prompt_metrics:current_budget',
        'prompt_metrics:headline',
        'prompt_metrics:last_seen',
        'prompt_metrics:max_total',
        'prompt_metrics:no_data',
        'prompt_metrics:noop',
        'prompt_metrics:pct_dropped',
        'prompt_metrics:pct_truncated',
        'prompt_metrics:rec_insufficient_data',
        'prompt_metrics:rec_optimal',
        'prompt_metrics:recommendation',
        'prompt_metrics:recommended',
        'prompt_metrics:samples',
        'prompt_metrics:settings_link',
        'prompt_metrics:subtitle',
        'prompt_metrics:title',
        'ragadmin:back_to_settings',
        'ragadmin:col_actions',
        'ragadmin:col_chunks',
        'ragadmin:col_course',
        'ragadmin:col_embedded',
        'ragadmin:col_lastindexed',
        'ragadmin:deleteindex',
        'ragadmin:deleteindex_confirm',
        'ragadmin:deleteindex_done',
        'ragadmin:index_status',
        'ragadmin:never',
        'ragadmin:no_courses',
        'ragadmin:rag_disabled_notice',
        'ragadmin:reindex',
        'ragadmin:reindexall',
        'ragadmin:reindexall_confirm',
        'ragadmin:reindexall_desc',
        'ragadmin:reindexall_done',
        'ragadmin:reindexcourse_done',
        'ragadmin:stat_active_courses',
        'ragadmin:stat_courses_indexed',
        'ragadmin:stat_embedded_chunks',
        'ragadmin:stat_total_chunks',
        'ragadmin:view_status',
        'redash_api_key',
        'redash_api_key_desc',
        'redash_heading',
        'redash_heading_desc',
        'remoteconfigurl',
        'sandbox:toggle',
        'settings:avatar_rate_card_overrides',
        'settings:avatar_rate_card_overrides_desc',
        'settings:contact_email',
        'settings:csp_course_pages_mode',
        'settings:csp_course_pages_mode_desc',
        'settings:csp_mode_enforce',
        'settings:csp_mode_off',
        'settings:csp_mode_report_only',
        'settings:current_page_content_maxchars',
        'settings:embed_apibaseurl',
        'settings:embed_apibaseurl_desc',
        'settings:embed_apikey',
        'settings:embed_apikey_desc',
        'settings:embed_dimensions',
        'settings:embed_model',
        'settings:embed_model_desc',
        'settings:embed_provider_ollama',
        'settings:embed_provider_openai',
        'settings:external_resources_allowlist',
        'settings:external_resources_allowlist_desc',
        'settings:external_resources_enabled',
        'settings:external_resources_enabled_desc',
        'settings:external_resources_heading',
        'settings:external_resources_heading_desc',
        'settings:institution_name',
        'settings:institution_name_desc',
        'settings:pedagogy_defaults_heading',
        'settings:pedagogy_defaults_heading_desc',
        'settings:prompt_budget_chars',
        'settings:prompt_metrics_enabled',
        'settings:prompt_metrics_enabled_desc',
        'settings:prompt_verbosity',
        'settings:prompt_verbosity_concise',
        'settings:prompt_verbosity_desc',
        'settings:prompt_verbosity_standard',
        'settings:prompt_verbosity_verbose',
        'settings:provider_coreai',
        'settings:provider_deepseek',
        'settings:provider_gemini',
        'settings:provider_minimax',
        'settings:provider_mistral',
        'settings:provider_together',
        'settings:provider_xai',
        'settings:rag_auto_reindex_drifted',
        'settings:rag_chunksize',
        'settings:rag_chunksize_desc',
        'settings:rag_enabled',
        'settings:rag_enabled_desc',
        'settings:rag_heading',
        'settings:rag_heading_desc',
        'settings:rag_topk',
        'settings:rate_card_auto_refresh',
        'settings:rate_card_last_refresh_at',
        'settings:rate_card_last_refresh_success',
        'settings:rate_card_never_refreshed',
        'settings:rate_card_overrides',
        'settings:rate_card_refresh_error',
        'settings:rate_card_refresh_now',
        'settings:rate_card_refresh_now_label',
        'settings:rate_card_refresh_success',
        'settings:rate_card_upstream_url',
        'settings:redash_base_url',
        'settings:redash_base_url_desc',
        'settings:redash_data_source_id',
        'settings:redash_data_source_id_desc',
        'settings:redash_user_api_key',
        'settings:redash_user_api_key_desc',
        'settings:socratic_verbose',
        'settings:socratic_verbose_desc',
        'settings:talking_avatar_did_api_key',
        'settings:talking_avatar_did_api_key_desc',
        'settings:talking_avatar_did_persona_id',
        'settings:talking_avatar_did_persona_id_desc',
        'settings:talking_avatar_did_webhook_secret',
        'settings:talking_avatar_did_webhook_secret_desc',
        'settings:talking_avatar_heading',
        'settings:talking_avatar_heading_desc',
        'settings:talking_avatar_heygen_api_key',
        'settings:talking_avatar_heygen_api_key_desc',
        'settings:talking_avatar_heygen_persona_id',
        'settings:talking_avatar_heygen_persona_id_desc',
        'settings:talking_avatar_heygen_webhook_secret',
        'settings:talking_avatar_heygen_webhook_secret_desc',
        'settings:talking_avatar_provider',
        'settings:talking_avatar_provider_desc',
        'settings:talking_avatar_provider_did',
        'settings:talking_avatar_provider_heygen',
        'settings:talking_avatar_provider_none',
        'settings:talking_avatar_provider_synthesia',
        'settings:talking_avatar_provider_tavus',
        'settings:talking_avatar_synthesia_api_key',
        'settings:talking_avatar_synthesia_api_key_desc',
        'settings:talking_avatar_synthesia_persona_id',
        'settings:talking_avatar_synthesia_persona_id_desc',
        'settings:talking_avatar_synthesia_webhook_secret',
        'settings:talking_avatar_synthesia_webhook_secret_desc',
        'settings:talking_avatar_tavus_api_key',
        'settings:talking_avatar_tavus_api_key_desc',
        'settings:talking_avatar_tavus_persona_id',
        'settings:talking_avatar_tavus_persona_id_desc',
        'settings:talking_avatar_tavus_webhook_secret',
        'settings:talking_avatar_tavus_webhook_secret_desc',
        'settings:validators_runtime_annotate',
        'settings:validators_runtime_block',
        'settings:validators_runtime_mode',
        'settings:validators_runtime_off',
        'settings:vendor_data_heading',
        'settings:vendor_data_heading_desc',
        'settings:vendor_dpa_admin_page_enabled',
        'settings:vendor_dpa_admin_page_enabled_desc',
        'settings:vendor_dpa_overrides',
        'talking_avatar:bundle_required',
        'talking_avatar:close',
        'talking_avatar:disabled',
        'talking_avatar:open',
        'talking_avatar:session_failed',
        'talking_avatar:unconfigured',
        'talking_avatar:viewer_title',
        'task:auto_reindex_rag_drifted',
        'task:auto_tune_prompt_budget',
        'task:index_course_content',
        'task:milestone_check',
        'task:refresh_rate_card',
        'task:run_anomaly_digest',
        'task:struggle_signal_review',
        'task:sweep_avatar_sessions',
        'worked_examples:toggle',
    ];

    /**
     * Fail when a key is byte-identical to English in EVERY locale that
     * defines it, unless it is declared above.
     *
     * "Every locale" is the point. A single locale matching English is usually
     * a cognate -- Spanish "Radar", German "Chat" -- and flagging those would
     * bury the real gaps in noise (869 keys match in at least one locale, only
     * 434 in all of them). Agreement across all 45 at once, Japanese, Arabic,
     * Thai and Chinese included, does not happen by coincidence; it means the
     * key was copied from English and never translated.
     *
     * What this deliberately does NOT catch: a key translated in 44 locales
     * and left English in one. That needs a per-locale rule and a much larger
     * allowlist; this gate is the high-signal half.
     *
     * @return void
     */
    public function test_present_keys_are_not_just_english(): void {
        $en = $this->load_en_strings();
        $locales = array_filter(
            scandir($this->plugin_root() . '/lang'),
            fn($d) => $d !== '.' && $d !== '..' && $d !== 'en'
        );
        $this->assertGreaterThan(40, count($locales), 'expected the full locale set');

        $tables = [];
        foreach ($locales as $lang) {
            $tables[$lang] = $this->locale_keys($lang);
        }

        $allowed = array_flip(array_merge(
            self::TRANSLATION_NOT_REQUIRED,
            self::IDENTICAL_TO_ENGLISH_BACKLOG
        ));

        $untranslated = [];
        foreach ($en as $key => $value) {
            if (trim($value) === '' || isset($allowed[$key])) {
                continue;
            }
            $defining = 0;
            $matching = 0;
            foreach ($tables as $table) {
                if (!array_key_exists($key, $table)) {
                    continue;
                }
                $defining++;
                if ($table[$key] === $value) {
                    $matching++;
                }
            }
            if ($defining > 0 && $matching === $defining) {
                $untranslated[] = $key . ' (English in all ' . $defining . ' locales)';
            }
        }

        if (!empty($untranslated)) {
            $this->fail("Keys present in every locale but still holding the English "
                . "text. Translate them, or add them to IDENTICAL_TO_ENGLISH_BACKLOG "
                . "(a backlog) or TRANSLATION_NOT_REQUIRED (an acronym or product "
                . "name) deliberately:\n  - " . implode("\n  - ", $untranslated));
        }
    }
}
