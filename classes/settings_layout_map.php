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
 * The settings menu layout, as data: which page each setting lives on.
 *
 * settings.php still builds every setting once, in the order it always has,
 * but onto staging pages that are never registered. settings_layout::apply()
 * then moves each one onto the page named here. Setting names, defaults and
 * stored values are not touched, so moving a setting is safe; renaming one is
 * not (the policy bundle allowlist, emergency control and auto-upgrade all
 * write settings by name).
 *
 * Section kinds:
 *   'banner'   a notice that is placed with no heading above it.
 *   'existing' reuse the heading setting whose name is given; its settings follow.
 *   'new'      create a heading from the lang string "layout:<key>".
 *
 * Every entry in the old admin tree is either placed exactly once below or
 * listed in DROPPED. settings_layout_test enforces both, so a setting added
 * later without a home fails the build instead of landing somewhere by accident.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_layout_map {
    /**
     * @var string[] Warnings settings.php builds only under a condition (a prompt
     *               budget with no model window, a truncation in the last 7 days,
     *               the deprecated tuner on while the budget mode is not fixed).
     *               Each is placed in PAGES like any other entry; when it was not
     *               built, settings_layout::build() does not report it missing.
     */
    public const OPTIONAL = [
        'prompt_budget_nowindow_warning', 'prompt_truncation_warning', 'prompt_budget_tuner_conflict',
    ];

    /** @var string[] Entries that no longer render: the old in-page table of contents anchors. */
    public const DROPPED = [
        'sec_general_anchor', 'sec_ai_anchor', 'provider_heading', 'sec_content_anchor',
        'sec_safety_anchor', 'sec_engagement_anchor', 'sec_branding_anchor',
        'sec_integrations_anchor', 'sec_save_anchor'
    ];

    /** @var string[] Old settings page name (without the plugin prefix) => the page that replaced it. */
    public const REDIRECTS = [
        'general' => 'essentials',
        'ai' => 'models',
        'content' => 'adv_retrieval',
        'rerank' => 'adv_documents',
        'extraction' => 'adv_documents',
        'operations' => 'cost',
        'engagement' => 'features',
        'feedback' => 'features',
        'branding' => 'essentials',
        'integrations' => 'adv_integrations',
    ];

    /**
     * @var array[] Page id (without the plugin prefix) => ['parent' => null|'advanced', 'sections' => [...]].
     *              A section is [kind, heading name or key, [setting names]].
     */
    public const PAGES = [
        'essentials' => [
            'parent' => null,
            'sections' => [
                ['banner', null, [
                    'version_banner'
                ]],
                ['new', 'general', [
                    'enabled', 'default_course_mode', 'auto_open', 'hidden_categories',
                    'starters_link', 'analytics_link'
                ]],
                ['existing', 'branding_heading', [
                    'institution_name', 'institution_short_name', 'display_name', 'short_name',
                    'welcome_message', 'chat_greeting', 'avatar', 'customavatars'
                ]],
                ['new', 'contact', [
                    'contact_email', 'dpo_email', 'privacy_external_url', 'privacy_notice_override'
                ]],
                ['new', 'faq', [
                    'faq_content', 'supplemental_courses'
                ]],
                ['existing', 'footer_links_heading', [
                    'footer_courses_text', 'footer_courses_url', 'feedback_link_label',
                    'feedback_panel_intro'
                ]],
            ],
        ],
        'models' => [
            'parent' => null,
            'sections' => [
                ['existing', 'providertable_heading', [
                    'comparison_providers'
                ]],
                ['existing', 'providerdefault_heading', [
                    'provider', 'model', 'max_tokens', 'reasoning_effort', 'systemprompt',
                    'deployment_profile_link', 'selftest_link'
                ]],
                ['new', 'roles', [
                    'quiz_provider', 'quiz_model', 'essay_provider', 'essay_model',
                    'mastery_classifier_provider', 'mastery_classifier_model',
                    'mastery_classifier_weight', 'mastery_classifier_threshold'
                ]],
                ['existing', 'premium_escalation_heading', [
                    'premium_escalation_enabled', 'premium_escalation_provider',
                    'premium_escalation_model', 'premium_escalation_triggers',
                    'premium_escalation_course_tags'
                ]],
                ['new', 'failover', [
                    'spend_failover_chain', 'failover_per_call_enabled', 'failover_timeout_chat',
                    'backend_retry_attempts', 'backend_retry_max_wait'
                ]],
            ],
        ],
        'features' => [
            'parent' => null,
            'sections' => [
                ['existing', 'pedagogy_defaults_heading', [
                    'mastery_enabled', 'socratic_mode_enabled', 'worked_examples_enabled',
                    'flashcards_enabled', 'code_sandbox_enabled', 'code_sandbox_pyodide_baseurl',
                    'essay_feedback_enabled'
                ]],
                ['existing', 'mastery_heading', [
                    'objectives_admin_link', 'crossmastery_enabled', 'mastery_starter_enabled',
                    'program_path_enabled', 'learning_path_enabled', 'mastery_threshold',
                    'outcomes_benchmark_default', 'outcomes_panel_enabled', 'mastery_window',
                    'learning_path_mastery_threshold', 'mastery_decay_enabled',
                    'mastery_decay_half_life_days'
                ]],
                ['existing', 'studyplan_heading', [
                    'studyplan_enabled', 'reminders_email_enabled', 'reminders_whatsapp_enabled',
                    'whatsapp_api_url', 'whatsapp_api_token', 'whatsapp_from_number',
                    'whatsapp_blocked_countries', 'inactivity_reminder_enabled',
                    'inactivity_threshold_days'
                ]],
                ['new', 'voice', [
                    'voice_tab_enabled'
                ]],
                ['existing', 'survey_heading', [
                    'survey_enabled', 'survey_trigger_messages', 'survey_frequency'
                ]],
                ['existing', 'rubric_heading', [
                    'practice_scoring_enabled'
                ]],
                ['existing', 'usertesting_heading', [
                    'usertesting_enabled', 'usertesting_external_url', 'usertesting_editor_link'
                ]],
                ['new', 'activelearners', [
                    'active_learners_enabled', 'active_learners_scope'
                ]],
                ['existing', 'radar_batch_heading', [
                    'radar_batch_enabled'
                ]],
                ['existing', 'empathy_heading', [
                    'outreach_master_enabled', 'goals_feature_enabled',
                    'milestones_feature_enabled', 'memory_feature_enabled',
                    'struggle_classifier_enabled', 'outreach_dryrun'
                ]],
            ],
        ],
        'safety' => [
            'parent' => null,
            'sections' => [
                ['existing', 'quizlock_heading', [
                    'quiz_lock_enabled', 'quiz_lock_window_minutes', 'quiz_lock_scope'
                ]],
                ['existing', 'quiz_hide_heading', [
                    'hide_on_quiz_for_students', 'hide_on_quiz_for_staff'
                ]],
                ['existing', 'offtopic_heading', [
                    'offtopic_enabled', 'offtopic_max', 'offtopic_action',
                    'offtopic_lockout_duration'
                ]],
                ['existing', 'wellbeing_heading', [
                    'wellbeing_enabled'
                ]],
                ['existing', 'integrity_heading', [
                    'integrity_enabled', 'integrity_email', 'integrity_link',
                    'audit_retention_days', 'conversation_retention_days',
                    'validators_runtime_mode', 'csp_course_pages_mode'
                ]],
            ],
        ],
        'cost' => [
            'parent' => null,
            'sections' => [
                ['existing', 'spend_guard_heading', [
                    'token_analytics_link', 'spend_cap_period', 'spend_cap_site', 'spend_cap_chat',
                    'spend_cap_voice', 'spend_cap_rag', 'spend_cap_analytics',
                    'spend_cap_per_course_default', 'spend_notify_emails'
                ]],
                ['existing', 'cost_anomaly_heading', [
                    'cost_anomaly_enabled', 'cost_anomaly_multiplier', 'unanswered_check_enabled',
                    'unanswered_window_hours', 'unanswered_min_questions',
                    'unanswered_min_answer_rate', 'unanswered_min_failed_turns'
                ]],
                ['existing', 'anomaly_digest_heading', [
                    'anomaly_digest_enabled', 'anomaly_digest_threshold_pct',
                    'anomaly_digest_floor_usd', 'anomaly_digest_recipient_email',
                    'anomaly_digest_slack_webhook', 'anomaly_digest_teams_webhook'
                ]],
                ['existing', 'autoupgrade_heading', [
                    'autoupgrade_mode', 'autoupgrade_budget_usd', 'autoupgrade_eval_courseid'
                ]],
            ],
        ],
        'adv_retrieval' => [
            'parent' => 'advanced',
            'sections' => [
                ['existing', 'rag_heading', [
                    'rag_enabled', 'rag_auto_reindex_drifted'
                ]],
                ['existing', 'external_resources_heading', [
                    'external_resources_enabled', 'external_resources_allowlist'
                ]],
                ['new', 'embedding', [
                    'embed_provider', 'embed_key_from', 'embed_apikey', 'embed_model',
                    'embed_apibaseurl', 'embed_dimensions', 'embed_query_model',
                    'embed_input_type_mode', 'embed_dtype'
                ]],
                ['new', 'searchtuning', [
                    'rag_topk', 'rag_min_similarity', 'rag_currentpage_boost', 'rag_scope',
                    'rag_chunksize', 'rag_return_scope', 'rag_window_size', 'rag_parent_max_chars'
                ]],
            ],
        ],
        'adv_documents' => [
            'parent' => 'advanced',
            'sections' => [
                ['existing', 'rerank_heading', [
                    'rerank_enabled', 'rerank_key_from', 'rerank_apikey', 'rerank_model',
                    'rerank_apibaseurl', 'rerank_candidates', 'rerank_margin_threshold',
                    'rerank_min_query_chars', 'rag_admin_link'
                ]],
                ['existing', 'embed_migration_heading', [
                    'embed_migration_target_provider', 'embed_migration_target_model',
                    'embed_migration_target_dimensions', 'embed_migration_target_apikey'
                ]],
                ['existing', 'rag_sources_heading', [
                    'rag_extract_pdf', 'rag_pdftotext_path', 'rag_extract_docx', 'rag_extract_pptx',
                    'rag_extract_h5p', 'rag_extract_scorm', 'rag_scorm_max_mb',
                    'rag_fetch_transcripts', 'rag_iframe_host_patterns',
                    'rag_transcript_url_pattern'
                ]],
                ['existing', 'performance_heading', [
                    'max_content_per_resource', 'max_total_content'
                ]],
            ],
        ],
        'adv_prompt' => [
            'parent' => 'advanced',
            'sections' => [
                ['new', 'modelcalls', [
                    'apibaseurl', 'temperature', 'claude_temperature_allow_prefixes',
                    'backend_context_tokens', 'enable_thinking', 'ssrf_trusted_endpoints',
                    'xai_proxy_url', 'xai_proxy_jwt_secret'
                ]],
                ['new', 'promptbudget', [
                    'prompt_budget_mode', 'prompt_budget_chars', 'prompt_budget_nowindow_warning',
                    'prompt_truncation_warning', 'current_page_content_maxchars', 'prompt_verbosity', 'prompt_debug_enabled',
                    'socratic_verbose', 'prompt_metrics_enabled'
                ]],
                ['existing', 'prompt_proportions_heading', [
                    'prompt_section_weights', 'prompt_context_boost_mode',
                    'prompt_section_weights_coach'
                ]],
                ['new', 'history', [
                    'maxhistory', 'history_mode', 'history_semantic_minscore', 'history_candidates',
                    'profile_update_interval'
                ]],
                ['existing', 'realtime_heading', [
                    'realtime_enabled', 'realtime_key_from', 'realtime_apikey', 'realtime_voice'
                ]],
                ['existing', 'voice_providers_heading', [
                    'voice_providers', 'voice_active_realtime', 'voice_active_tts',
                    'voice_active_stt', 'max_audio_mb'
                ]],
                ['existing', 'stt_selfhosted_heading', [
                    'stt_selfhosted_enabled', 'stt_selfhosted_url', 'stt_selfhosted_model',
                    'stt_selfhosted_apikey', 'stt_selfhosted_warm'
                ]],
                ['new', 'appearance', [
                    'display_mode', 'avatar_animation_enabled', 'position', 'avatar_color',
                    'avatar_fill'
                ]],
            ],
        ],
        'adv_integrations' => [
            'parent' => 'advanced',
            'sections' => [
                ['existing', 'faq_heading', [
                    'support_enabled', 'support_courseid', 'zendesk_enabled',
                    'escalation_intent_patterns', 'zendesk_require_consent', 'zendesk_subdomain',
                    'zendesk_email', 'zendesk_token'
                ]],
                ['existing', 'redash_heading', [
                    'redash_api_key', 'redash_allowed_origin', 'redash_export_window_days',
                    'redash_allow_deanonymized', 'spend_export_key', 'redash_base_url',
                    'redash_user_api_key', 'redash_data_source_id'
                ]],
                ['existing', 'cdn_heading', [
                    'cdn_url', 'cdn_version'
                ]],
                ['new', 'remote', [
                    'remoteconfigurl'
                ]],
                ['existing', 'vendor_data_heading', [
                    'vendor_dpa_admin_page_enabled', 'vendor_dpa_overrides', 'rate_card_overrides',
                    'rate_card_auto_refresh', 'rate_card_upstream_url', 'rate_card_refresh_button',
                    'model_registry_link'
                ]],
                ['existing', 'price_drift_heading', [
                    'price_drift_check_enabled', 'price_drift_tolerance_pct',
                    'price_drift_eol_horizon_days', 'model_eol_surfaces'
                ]],
                ['existing', 'policy_bundle_heading', [
                    'policy_bundle_enabled', 'policy_bundle_url', 'policy_bundle_pubkey'
                ]],
                ['existing', 'bench_heading', [
                    'bench_default_samples', 'bench_min_quality_n', 'bench_judge_provider',
                    'bench_judge_model'
                ]],
                ['existing', 'rec_heading', [
                    'rec_quality_epsilon', 'rec_savings_floor', 'rec_quality_margin',
                    'rec_min_quality_n'
                ]],
                ['new', 'optimizer', [
                    'opt_cost_weight', 'opt_quality_weight'
                ]],
            ],
        ],
        'adv_legacy' => [
            'parent' => 'advanced',
            'sections' => [
                ['existing', 'soapbox_heading', [
                    'soapbox_deprecated_notice', 'soapbox_enabled', 'soapbox_stt_mode',
                    'soapbox_rubric_link', 'soapbox_max_seconds', 'soapbox_max_recordings',
                    'soapbox_retention_days', 'soapbox_video_quality', 'soapbox_slide_vision',
                    'soapbox_gesture_vision', 'soapbox_vision_provider', 'soapbox_vision_model',
                    'soapbox_storage_bucket', 'soapbox_storage_region', 'soapbox_storage_prefix',
                    'soapbox_storage_key', 'soapbox_storage_secret'
                ]],
                ['existing', 'talking_avatar_heading', [
                    'talking_avatar_enabled', 'talking_avatar_provider', 'cdn_bundle_url',
                    'did_api_key', 'did_persona_id', 'heygen_api_key', 'heygen_persona_id',
                    'tavus_api_key', 'tavus_persona_id', 'synthesia_api_key',
                    'synthesia_persona_id', 'avatar_rate_card_overrides', 'did_webhook_secret',
                    'heygen_webhook_secret', 'tavus_webhook_secret', 'synthesia_webhook_secret'
                ]],
                ['new', 'legacy', [
                    'prompt_budget_auto_tune', 'prompt_budget_tuner_conflict'
                ]],
            ],
        ],
    ];
}
