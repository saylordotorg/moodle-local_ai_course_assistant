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
     * the docs claim, and nothing detected it: the test above only proves a
     * referenced key exists in English, so a new English-only string was invisible.
     *
     * This list is the debt, not an exemption. Removing an entry is what a
     * translation batch does. Adding one should be a deliberate, reviewed act,
     * because the parity test below fails the moment an unlisted key appears in
     * English without translations, which is the drift that went unnoticed.
     */
    private const KNOWN_UNTRANSLATED = [
        // v7.0.0 parked 170 keys here as debt: 86 settings had their title
        // and description written as hardcoded English literals, so this
        // check could not even see them. v7.0.1 translated all 170 into all
        // 45 non-English locales, so the debt is paid and the entries are
        // gone — the parity test below now covers them like any other key.
        // Do not re-add them.
        //
        // What is left is the admin-only diagnostic pages, extracted in
        // v7.0.1 and still awaiting a translation batch.
        ...self::ADMIN_DIAGNOSTIC_UNTRANSLATED,
        ...self::SETTINGS_PAGE_UNTRANSLATED,
        ...self::BATCH_TIER_UNTRANSLATED,
        ...self::MODEL_EOL_UNTRANSLATED,
    ];

    /**
     * v7.4.4: model lifecycle (end-of-life) tracking on the model registry.
     *
     * Twenty-one administrator-only strings, all on the model-registry page and
     * the price-drift settings block -- surfaces no learner and no teacher can
     * reach. Staged as debt for the same reason as the batches above: every
     * locale falls back to lang/en, and an honest fallback beats forty-five
     * unreviewed machine translations.
     *
     * These in particular. Half of them are about WHICH API SURFACE a
     * retirement was announced for, a distinction that already cost a real
     * near-miss in English (gemini-2.5-flash's 2026-10-16 date was a Vertex AI
     * lifecycle event, not the Gemini Developer API this plugin calls). A
     * translation that blurred "surface" into "provider" or "platform" would
     * hand an administrator a plausible-looking sentence that leads to exactly
     * the false outage alarm the field exists to prevent -- and unlike a
     * mistranslated button, nobody would notice until the day it mattered.
     */
    private const MODEL_EOL_UNTRANSLATED = [
        'modelregistry:col_eol',
        'modelregistry:field_eol_date',
        'modelregistry:field_eol_date_help',
        'modelregistry:field_eol_surface',
        'modelregistry:field_eol_surface_help',
        'modelregistry:status_retired',
        'modelregistry:err_badeoldate',
        'modelregistry:err_noeolsurface',
        'modelregistry:eol_passed',
        'modelregistry:eol_soon',
        'modelregistry:eol_later',
        'modelregistry:eol_othersurface',
        'modelregistry:finding_eol',
        'modelregistry:drift_eol_horizon',
        'modelregistry:drift_eol_noapply',
        'modelregistry:drift_eol_record',
        'modelregistry:drift_ran_eol',
        'pricedrift:eol_horizon',
        'pricedrift:eol_horizon_desc',
        'pricedrift:eol_surfaces',
        'pricedrift:eol_surfaces_desc',
    ];

    /**
     * v7.4.4: the Learning Radar offline-batch tier.
     *
     * Five administrator-only strings: one scheduled-task name that appears in
     * Site administration > Server > Scheduled tasks, and four lines of settings
     * copy on a page only a site administrator can open. Staged as debt for the
     * same reason as the two batches above -- every locale falls back to
     * lang/en, and an honest fallback beats forty-five unreviewed machine
     * translations of a paragraph about token pricing, where a mistranslated
     * "50% less" is a number an administrator would act on.
     */
    private const BATCH_TIER_UNTRANSLATED = [
        'task:collect_meta_ai_batches',
        'settings:radar_batch_heading',
        'settings:radar_batch_heading_desc',
        'settings:radar_batch_enabled',
        'settings:radar_batch_enabled_desc',
    ];

    /**
     * v7.2.0 (CONTRIB-10574 #205): interface text extracted from settings.php,
     * where it was written as hardcoded English literals and so could not be
     * translated at all -- the reviewer's finding, and a repeat of the same
     * finding from the June round.
     *
     * Staged here rather than machine-translated for the same reason as the
     * batch above: these are administrator-only strings on one settings page,
     * every locale falls back to lang/en, and that fallback is byte-for-byte
     * what the page displayed before extraction, so nothing regressed. Pushing
     * seventeen strings through 45 locales unreviewed would add plausible-looking
     * translations nobody has checked, which is worse than an honest fallback.
     *
     * The two anomaly_digest_floor keys are here for the same reason: admin-only
     * settings copy, added in the same release.
     */
    private const SETTINGS_PAGE_UNTRANSLATED = [
        'settings:anomaly_digest_floor_usd',
        'settings:anomaly_digest_floor_usd_desc',
        'settingspage:analytics_link',
        'settingspage:analytics_title',
        'settingspage:rag_explainer',
        'settingspage:realtime_explainer',
        'settingspage:reset_prompt_template',
        'settingspage:sec_ai',
        'settingspage:sec_branding',
        'settingspage:sec_content',
        'settingspage:sec_engagement',
        'settingspage:sec_general',
        'settingspage:sec_integrations',
        'settingspage:sec_safety',
        'settingspage:toc_heading',
        'settingspage:toc_save',
        'settingspage:token_analytics_blurb',
        'settingspage:token_analytics_link',
        'settingspage:token_analytics_title',
    ];

    /**
     * v7.0.1 (I18N001/I18N003): keys extracted from the admin-only diagnostic
     * pages — prompt_playground.php, provider_benchmark.php and
     * course_settings.php — where the text was hardcoded English and so was
     * never translatable at all. Staged for a later translation batch; they are
     * part of KNOWN_UNTRANSLATED (spread in above) so the parity check stays
     * green, and listed separately so the v7.0.0 locale guard can skip them
     * (the ten v7.0.0 locales do not carry this newer batch).
     *
     * Every locale falls back to lang/en for these, which is byte-for-byte what
     * the pages displayed before the extraction, so nothing regressed.
     */
    private const ADMIN_DIAGNOSTIC_UNTRANSLATED = [
        // v7.4.3: Soapbox object-storage round-trip probe (admin-only page).
        'selftest:storage_title',
        'selftest:storage_intro',
        'selftest:storage_run',
        // Administrator-only diagnostic-page strings, staged rather than
        // machine-translated: every locale falls back to lang/en, which is
        // byte-for-byte what those pages displayed before extraction, and
        // unreviewed translations would read as finished work nobody had
        // checked. This list has grown well past the audit-log batch it was
        // written for -- it now covers the benchmark, rubric, survey,
        // user-testing, RAG-admin, playground, token-analytics, starters,
        // Redash and course-settings admin surfaces. Count it rather than
        // trusting any number written here.
        //
        // Deliberately NOT staged: emergency:chat_stopped, translated into all
        // 45 locales in this release because a learner sees it.
        'auditlog:col_action',
        'auditlog:col_course',
        'auditlog:col_details',
        'auditlog:col_ip',
        'auditlog:col_time',
        'auditlog:col_user',
        'auditlog:empty',
        'analytics:total_tokens_hint',
        'settings:prompt_truncated_warning',
        'auditlog:empty_page',
        'auditlog:unknown_user',
        'auditlog:intro',
        'auditlog:settings_link',
        'auditlog:title',
        'benchmark:export_csv',
        'benchmark:export_json',
        'benchmark:export_markdown',
        'benchmark:lastrun',
        'benchmark:norun',
        'benchmark:rerun',
        'benchmark:runnow',
        'coursesettings:auto_open',
        'coursesettings:auto_open_desc',
        'coursesettings:auto_open_heading',
        'coursesettings:auto_open_help',
        'coursesettings:back_to_course',
        'coursesettings:course_analytics',
        'coursesettings:english_lock',
        'coursesettings:english_lock_desc',
        'coursesettings:english_lock_heading',
        'coursesettings:english_lock_help',
        'coursesettings:english_lock_toggle',
        'coursesettings:force_off',
        'coursesettings:force_on',
        'coursesettings:how_it_works',
        'coursesettings:how_it_works_desc',
        'coursesettings:inherit_global',
        'coursesettings:reset_prompt',
        'coursesettings:reset_prompt_confirm',
        'coursesettings:reset_prompt_title',
        'coursesettings:state_disabled',
        'coursesettings:state_enabled',
        'coursesettings:systemprompt_hint',
        'coursesettings:voice_tab',
        'coursesettings:voice_tab_desc',
        'coursesettings:voice_tab_help',
        'prompt_playground:assemble',
        'prompt_playground:assemble_failed',
        'prompt_playground:assembled',
        'prompt_playground:breakdown',
        'prompt_playground:col_content',
        'prompt_playground:col_score',
        'prompt_playground:col_type',
        'prompt_playground:intro',
        'prompt_playground:label_chunks',
        'prompt_playground:label_courseid',
        'prompt_playground:label_pageid',
        'prompt_playground:label_query',
        'prompt_playground:live_retrieval',
        'prompt_playground:mode_live',
        'prompt_playground:mode_simulated',
        'prompt_playground:no_chunks',
        'prompt_playground:pagetitle',
        'prompt_playground:question',
        'prompt_playground:result',
        'prompt_playground:result_summary',
        'prompt_playground:retrieval_failed',
        'prompt_playground:score_na',
        'prompt_playground:selected_chunks',
        // v7.0.1 second wave: rag_admin.php, admin_user_data.php,
        // rubric_admin.php and the redash_export.php JSON error payloads.
        'admin:user_data:col_rows',
        'admin:user_data:col_table',
        'admin:user_data:idlabel',
        'admin:user_data:total',
        'ragadmin:badge_off',
        'ragadmin:badge_ready',
        'ragadmin:content_sources',
        'ragadmin:content_sources_desc',
        'ragadmin:indexing_failed',
        'ragadmin:no_chunks_embedded',
        'ragadmin:no_extractable_content',
        'ragadmin:nothing_embedded_all',
        'ragadmin:src_disabled',
        'ragadmin:src_docx',
        'ragadmin:src_docx_ok',
        'ragadmin:src_embedding',
        'ragadmin:src_embedding_keyset',
        'ragadmin:src_embedding_missingkey',
        'ragadmin:src_embedding_model',
        'ragadmin:src_embedding_nokey',
        'ragadmin:src_embedding_provider',
        'ragadmin:src_h5p',
        'ragadmin:src_h5p_ok',
        'ragadmin:src_pdf',
        'ragadmin:src_pdf_missing',
        'ragadmin:src_pdf_ok',
        'ragadmin:src_pptx',
        'ragadmin:src_pptx_ok',
        'ragadmin:src_scorm',
        'ragadmin:src_scorm_off',
        'ragadmin:src_scorm_ok',
        'ragadmin:src_transcripts',
        'ragadmin:src_transcripts_off',
        'ragadmin:src_transcripts_ok',
        'redash:err_deanonymized_disabled',
        'redash:err_invalid_key',
        'redash:err_method_not_allowed',
        'redash:err_nested_sections',
        'redash:err_no_sections',
        'redash:err_not_configured',
        'redash:hint_add_parent',
        'redash:unknown_course',
        'rubric_admin:add_criterion',
        'rubric_admin:analytics_link',
        'rubric_admin:confirm_delete_criterion',
        'rubric_admin:confirm_reset_course',
        'rubric_admin:confirm_reset_global',
        'rubric_admin:criterion',
        'rubric_admin:criterion_name',
        'rubric_admin:criterion_name_placeholder',
        'rubric_admin:delete_criterion',
        'rubric_admin:description',
        'rubric_admin:description_aria',
        'rubric_admin:description_placeholder',
        'rubric_admin:err_invalid_criteria',
        'rubric_admin:err_no_criteria',
        'rubric_admin:inherited_notice',
        'rubric_admin:maps_to_outcome',
        'rubric_admin:max_score',
        'rubric_admin:max_score_aria',
        'rubric_admin:move_down',
        'rubric_admin:move_up',
        'rubric_admin:outcome_aria',
        'rubric_admin:outcome_none',
        'rubric_admin:preview',
        'rubric_admin:preview_close',
        'rubric_admin:preview_score',
        'rubric_admin:preview_title',
        'rubric_admin:preview_total',
        'rubric_admin:preview_type',
        'rubric_admin:preview_unnamed',
        'rubric_admin:remove_override',
        'rubric_admin:reset_course_done',
        'rubric_admin:reset_defaults',
        'rubric_admin:reset_global_done',
        'rubric_admin:rubric_title_conversation',
        'rubric_admin:rubric_title_pronunciation',
        'rubric_admin:rubric_title_speech',
        'rubric_admin:save',
        'rubric_admin:saved_created',
        'rubric_admin:saved_updated',
        'rubric_admin:scope_global',
        'rubric_admin:scope_label',
        'rubric_admin:tab_conversation',
        'rubric_admin:tab_pronunciation',
        'rubric_admin:tab_speech',
        'rubric_admin:title_course',
        'rubric_admin:title_global',
        // v7.0.2 second wave (I18N001): analytics.php Learning Radar chips and
        // suggested questions, plus the starter_settings.php help panel, icon
        // tooltips and the starter-card editor labels the page builds in the
        // browser. All admin-only surfaces; staged for the next translation batch.
        'analytics:chip_activestudents_label',
        'analytics:chip_activestudents_query',
        'analytics:chip_integrity_label',
        'analytics:chip_integrity_query',
        'analytics:chip_negratings_label',
        'analytics:chip_negratings_query',
        'analytics:chip_tokens_label',
        'analytics:chip_tokens_query',
        'analytics:chip_topcourse_label',
        'analytics:chip_topcourse_query',
        'analytics:chip_topcourse_query_empty',
        'analytics:chip_voiceminutes_label',
        'analytics:chip_voiceminutes_query',
        'analytics:provider_primary',
        'analytics:radar_starter_bounce_label',
        'analytics:radar_starter_bounce_query',
        'analytics:radar_starter_frustrated_label',
        'analytics:radar_starter_frustrated_query',
        'analytics:radar_starter_provider_label',
        'analytics:radar_starter_provider_query',
        'analytics:radar_starter_quiet_label',
        'analytics:radar_starter_quiet_query',
        'analytics:radar_starter_review_label',
        'analytics:radar_starter_review_query',
        'analytics:radar_starter_topics_label',
        'analytics:radar_starter_topics_query',
        'analytics:radar_starter_trending_label',
        'analytics:radar_starter_trending_query',
        'analytics:radar_starter_voice_label',
        'analytics:radar_starter_voice_query',
        'starters:howto_builtin',
        'starters:howto_conditional',
        'starters:howto_custom',
        'starters:howto_heading',
        'starters:howto_overrides',
        'starters:howto_placeholders',
        'starters:howto_reorder',
        'starters:howto_type_prompt',
        'starters:howto_type_pronunciation',
        'starters:howto_type_quiz',
        'starters:howto_type_voice',
        'starters:howto_types',
        'starters:icon_book',
        'starters:icon_brain',
        'starters:icon_calendar',
        'starters:icon_chat',
        'starters:icon_compass',
        'starters:icon_graduation',
        'starters:icon_heart',
        'starters:icon_lightbulb',
        'starters:icon_lightning',
        'starters:icon_mic',
        'starters:icon_pencil',
        'starters:icon_refresh',
        'starters:icon_rocket',
        'starters:icon_search',
        'starters:icon_speaker',
        'starters:icon_star',
        'starters:icon_target',
        'starters:js_builtin_note',
        'starters:js_cond_always',
        'starters:js_cond_realtime',
        'starters:js_cond_tts',
        'starters:js_conditional',
        'starters:js_confirm_delete',
        'starters:js_delete',
        'starters:js_desc_aria',
        'starters:js_desc_help',
        'starters:js_desc_placeholder',
        'starters:js_description',
        'starters:js_drag_handle',
        'starters:js_icon',
        'starters:js_name',
        'starters:js_name_aria',
        'starters:js_name_placeholder',
        'starters:js_new_name',
        'starters:js_on',
        'starters:js_prompt',
        'starters:js_prompt_aria',
        'starters:js_prompt_help',
        'starters:js_prompt_placeholder',
        // v7.0.2 third wave (I18N001): survey_admin.php,
        // usertesting_admin.php and token_analytics.php — page headings,
        // scope pickers, save/reset notices and the question/task card
        // editors those two pages build in the browser. All site-admin-only
        // surfaces; staged for the next translation batch.
        'survey_admin:add_option',
        'survey_admin:add_question',
        'survey_admin:confirm_delete_question',
        'survey_admin:confirm_reset_course',
        'survey_admin:confirm_reset_global',
        'survey_admin:delete_question',
        'survey_admin:err_invalid_questions',
        'survey_admin:err_no_questions',
        'survey_admin:inherited_notice',
        'survey_admin:max_label',
        'survey_admin:max_label_aria',
        'survey_admin:max_label_placeholder',
        'survey_admin:max_value',
        'survey_admin:max_value_aria',
        'survey_admin:min_label',
        'survey_admin:min_label_aria',
        'survey_admin:min_label_placeholder',
        'survey_admin:min_value',
        'survey_admin:min_value_aria',
        'survey_admin:option_n',
        'survey_admin:options',
        'survey_admin:preview_answer_hint',
        'survey_admin:preview_no_text',
        'survey_admin:preview_title',
        'survey_admin:question_text',
        'survey_admin:question_type',
        'survey_admin:remove_option',
        'survey_admin:reset_course_done',
        'survey_admin:reset_global_done',
        'survey_admin:save',
        'survey_admin:saved_created',
        'survey_admin:saved_updated',
        'survey_admin:scope_label',
        'survey_admin:survey_title',
        'survey_admin:title_course',
        'survey_admin:title_global',
        'survey_admin:title_placeholder',
        'survey_admin:type_multiple_choice',
        'survey_admin:type_open_text',
        'survey_admin:type_rating',
        'token_analytics:all_courses',
        'token_analytics:cap_unlimited',
        'token_analytics:cat_analytics',
        'token_analytics:cat_other',
        'token_analytics:cat_premium_route',
        'token_analytics:cat_voice_realtime',
        'token_analytics:cat_voice_stt',
        'token_analytics:cat_voice_tts',
        'token_analytics:opt_no_data',
        'token_analytics:opt_no_projection',
        'token_analytics:opt_rank_line',
        'token_analytics:title',
        'token_analytics:unknown_model',
        'usertesting_admin:add_task',
        'usertesting_admin:additional_prompt',
        'usertesting_admin:additional_prompt_aria',
        'usertesting_admin:confirm_delete_task',
        'usertesting_admin:confirm_reset_course',
        'usertesting_admin:confirm_reset_global',
        'usertesting_admin:delete_task',
        'usertesting_admin:err_invalid_tasks',
        'usertesting_admin:err_no_tasks',
        'usertesting_admin:external_url',
        'usertesting_admin:external_url_help',
        'usertesting_admin:follow_up',
        'usertesting_admin:follow_up_aria',
        'usertesting_admin:inherited_notice',
        'usertesting_admin:max_label',
        'usertesting_admin:max_label_placeholder',
        'usertesting_admin:max_value',
        'usertesting_admin:max_value_aria',
        'usertesting_admin:min_label',
        'usertesting_admin:min_label_placeholder',
        'usertesting_admin:min_value',
        'usertesting_admin:min_value_aria',
        'usertesting_admin:preview_follow_up',
        'usertesting_admin:preview_no_instruction',
        'usertesting_admin:preview_rate_fallback',
        'usertesting_admin:preview_rate_range',
        'usertesting_admin:preview_task_label',
        'usertesting_admin:preview_title',
        'usertesting_admin:rating_label',
        'usertesting_admin:reset_course_done',
        'usertesting_admin:reset_global_done',
        'usertesting_admin:save',
        'usertesting_admin:saved_created',
        'usertesting_admin:saved_updated',
        'usertesting_admin:scope_label',
        'usertesting_admin:task_instruction',
        'usertesting_admin:task_type',
        'usertesting_admin:tasks_heading',
        'usertesting_admin:taskset_title',
        'usertesting_admin:taskset_title_placeholder',
        'usertesting_admin:title_course',
        'usertesting_admin:title_global',
        'usertesting_admin:type_action_then_rate',
        'usertesting_admin:type_free_response',
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
        'settings:provider_openai',
        'settings:provider_openrouter',
        'settings:soapbox_heading',
        'soapbox:title',
    ];

    /**
     * Keys that ARE defined in all 45 locales but still hold the English text.
     *
     * This is a different failure from a missing key. A missing key falls back
     * to lang/en, which is honest and is what ADMIN_DIAGNOSTIC_UNTRANSLATED
     * deliberately relies on. These keys instead look translated: they are
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
        'coursepicker:intro',
        'coursepicker:nocourses',
        'coursepicker:title',
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
        'essay_feedback:col_criterion',
        'essay_feedback:col_feedback',
        'essay_feedback:col_score',
        'essay_feedback:disabled',
        'essay_feedback:error',
        'essay_feedback:essay_label',
        'essay_feedback:intro',
        'essay_feedback:link',
        'essay_feedback:overall_heading',
        'essay_feedback:result_heading',
        'essay_feedback:revisions_heading',
        'essay_feedback:rubric_label',
        'essay_feedback:scoring',
        'essay_feedback:submit',
        'essay_feedback:title',
        'essay_feedback:toggle',
        'essay_feedback:toggle_help',
        'essay_feedback:too_short',
        'external_resources:force_off',
        'external_resources:force_on',
        'external_resources:inherit',
        'external_resources:off',
        'external_resources:on',
        'external_resources:title',
        'external_resources:toggle_help',
        'goals:clear',
        'goals:dismiss',
        'goals:edit',
        'goals:save',
        'insights:desc',
        'insights:error',
        'insights:generate',
        'insights:generating',
        'insights:no_data',
        'insights:title',
        'instructor_dashboard:nav_analytics',
        'instructor_dashboard:nav_back_course',
        'instructor_dashboard:nav_settings',
        'instructor_dashboard:review_col_source',
        'instructor_dashboard:review_col_summary',
        'instructor_dashboard:review_col_when',
        'instructor_dashboard:review_col_who',
        'instructor_dashboard:review_empty',
        'instructor_dashboard:review_heading',
        'instructor_dashboard:review_resolve',
        'instructor_dashboard:review_resolved',
        'instructor_dashboard:review_source_integrity',
        'instructor_dashboard:review_source_offtopic',
        'instructor_dashboard:review_source_rating',
        'instructor_dashboard:short',
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
        'pedagogy:off',
        'pedagogy:on',
        'pedagogy:per_course_force_off',
        'pedagogy:per_course_force_on',
        'pedagogy:per_course_inherit',
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
        'quizsettings:coleffective',
        'quizsettings:colgrade',
        'quizsettings:collevel',
        'quizsettings:colquiz',
        'quizsettings:desc',
        'quizsettings:level_coach',
        'quizsettings:level_default',
        'quizsettings:level_full',
        'quizsettings:level_hidden',
        'quizsettings:title',
        'quizsettings:ungraded',
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
        'ragadmin:title',
        'ragadmin:view_status',
        'redash_api_key',
        'redash_api_key_desc',
        'redash_heading',
        'redash_heading_desc',
        'remoteconfigurl',
        'sandbox:clear',
        'sandbox:code_label',
        'sandbox:disabled',
        'sandbox:intro',
        'sandbox:link',
        'sandbox:load_error',
        'sandbox:loading',
        'sandbox:output_heading',
        'sandbox:privacy_note',
        'sandbox:ready',
        'sandbox:run',
        'sandbox:running',
        'sandbox:title',
        'sandbox:toggle',
        'sandbox:toggle_help',
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
        'worked_examples:starter',
        'worked_examples:toggle',
        'worked_examples:toggle_help',
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
