# SOLA 7.10.0, 7.11.0 and 8.0.0: simplify the settings menu

Status: APPROVED to build by Tom on 2026-10-18 ("Do release 1 with the five task pages, then release 2, then 8.0 - work autonomously"), on the strength of the audit report "SOLA settings audit and simplification plan" (Google Doc 1LxEWy4Ml0DUeQ1e3KpAG30-kV-wx0dFThBWE0AX3Qds). Written 2026-10-18. Release 1 is specified in full here; Releases 2 and 8.0 are specified at the level of decisions and get their own plans after the release before them ships, because each depends on what the code looks like then.

## 1. What we are building, and how we will know it worked

**Purpose.** An admin should find a setting by what they are trying to do, and should not have to wade through settings nobody has ever changed. The audit found 289 changeable settings on 11 pages plus 14 tool pages, of which 178 (62%) were never changed on Learn, Degrees, dev or alpha.

**Success criteria**

1. After 7.10.0, the menu under Site administration > Plugins > Local plugins > AI Course Assistant shows five task pages (Essentials, Models and keys, Features, Safety and privacy, Cost and reliability) and an Advanced group, instead of 11 flat pages.
2. Every setting that exists today still exists with the same name, default and stored value. Nothing is renamed, deleted or re-defaulted in 7.10.0. A site that configures nothing new behaves identically.
3. Every setting is placed exactly once, no page strands a heading, every show/hide rule still fires, and no page exceeds core's 1,024-character dependency budget. Tests enforce all four.
4. Old settings URLs (`admin/settings.php?section=local_ai_course_assistant_ai` and the rest) still land somewhere sensible.
5. A new setting cannot be added without a home, and the day-to-day pages cannot quietly grow (tests, section 4.8).
6. By 8.0.0 the repeated choices are tables (one Model roles table, no pasted-key boxes), courses can use a named key instead of pasting their own, and the dead settings are gone.

**Out of scope for all three releases.** Prod Learn and Degrees stay on 7.5.7 until 2027 and `sola-config.json` is not touched; nothing here is deployed to production. Clearing saved secrets on prod, setting spend caps, and deciding the talking-avatar feature's future are Tom's calls (section 3).

## 2. Current state (verified)

- `settings.php` (3,984 lines) builds one long run of settings and cuts it into 11 `admin_settingpage` objects at section boundaries ("Close the previous page and open the next one", issue #292). A `$dependencies` map (21 toggles, 71 dependents) is applied at the end, with each rule attached to the page that holds both ends; a rule whose ends sit on different pages is skipped with a developer notice.
- Core serialises a page's whole show/hide map into one `js_call_amd` argument limited to 1,024 characters. `settings_dependencies_test` holds each page to 950. 18 dependents is about the most one page can carry.
- The first page carries a version banner plus an in-page table of contents and quick links; each old page opens with a `sec_*_anchor` notice that only that table of contents used.
- Code outside settings.php that links to a settings page by name: `audit_log.php`, `emergency_admin.php`, `rag_admin.php` (all `local_ai_course_assistant_general`) and `classes/autoupgrade/page.php` (`..._operations`). Tests that walk the page list: `settings_page_structure_test`, `settings_dependencies_test`, `settings_no_secret_in_page_test`, `settings_secret_masking_test`, `settings_branding_test`, `settings_save_report_test`.
- The audit data behind the layout is in the Google Doc above.

## 3. Rulings

Each says what was decided, why, and what it costs if wrong. Tom did not answer the five questions in the report before saying "go", so these are made on his behalf and listed in the release notes.

1. **Regroup plus an Advanced group, no global Simple/Advanced switch** (question 5). A switch would have to be hand-built and adds state to every page. Cost if wrong: a later switch is still possible on top of this layout.
2. **Advanced is a group of five pages, not one.** Core's 1,024-character budget limits each page to about 18 dependents; retrieval alone needs 18. Five pages keep every show/hide rule alive. Cost if wrong: two more clicks for someone who goes looking.
3. **Soapbox and talking-avatar settings move to an Advanced "Soapbox, avatars and legacy" page instead of being hidden until used.** Hiding by toggle would hide Soapbox config from a site where only a course turns it on (the same trap the code already documents for the vision settings). Placement gives the same relief with no conditional logic.
4. **Talking avatars are parked, not cut** (question 1): settings stay, on Advanced. The Avatar vendors table (report item B4) is not built.
5. **Saved secrets for switched-off features are left alone** (question 3). It is a data change on production that only Tom or Catalyst can make. Release 1 does not clear anything.
6. **Spend caps stay together on Cost and reliability** (question 2): all seven plus the notify emails. The finding that no cap is set anywhere argues for keeping them visible.
7. **Old settings URLs redirect.** Eleven hidden external pages named after the old pages send the browser to the page that replaced each. Cost if wrong: none; they are invisible.
8. **New admin strings ship English-only** and go in `KNOWN_UNTRANSLATED` (precedent: 7.8.9, 7.9.1; admin-only surface, an honest English fallback beats a rushed translation). A translation batch follows later.
9. **Marketplace upload is not part of these releases.** Tom has asked for it release by release. The work ends at: PR green, merged, tagged, GitHub release published, dev and alpha upgraded and checked. I will say so in the final report and offer the uploads.
10. **Release 2 keeps every stored setting name.** It changes how settings are shown and where keys come from, not what they are called. Renames and deletions wait for 8.0.0, the major version, where the upgrade chain from any older version runs the copy steps before the delete steps.
11. **Course key profiles (report item B3) ship the capability and a report, not an automatic migration.** A course can choose a key row by label; a CLI tool with `--dry-run` proposes consolidating existing per-course keys; nothing is rewritten until an admin runs it. Existing course keys keep working untouched.
12. **8.0.0 removes the Soapbox code and its settings, keeps the tables, and drops nothing.** This follows Tom's 2026-09-19 decision (code in 8.0, tables later and only after learners have an export path). The audit found zero Soapbox rows on Learn and Degrees, which fits the "no data lost" premise. The decision's other condition (PresenterAI migrator proven) is not met, so the release notes say plainly that Soapbox data stays in its tables, is still covered by the privacy provider, and has no UI after 8.0. If the code removal turns out to touch anything that holds data, stop and re-rule.
13. **No automatic deletion of "never changed" settings** (report item C3). It needs usage evidence from 7.9.x and Tom's call. Release 1 ships the usage report tool so that evidence can be collected.

## 4. Release 1 (7.10.0): regroup, no data change

### 4.1 Layout as data

`classes/settings_layout_map.php` (already written, committed with this spec) holds `PAGES`, `REDIRECTS` and `DROPPED`. It is the single source of truth for placement. The full table is in Appendix A. Do not edit it without a ruling: it was checked to cover all 355 entries of the current admin tree exactly once, and to keep every dependency rule's two ends on one page.

### 4.2 Staging, then placement

`settings.php` keeps building every setting in its current order and its current blocks. Change only what happens at each block boundary: stop registering each of the 11 staging pages with `$ADMIN->add()` (keep collecting them in `$aicapages`), rename nothing else. After the `$dependencies` map, call `\local_ai_course_assistant\settings_layout::apply($ADMIN, $aicapages, $dependencies)`.

`classes/settings_layout.php` (new) does, in order:

1. Index every setting object on the staging pages by `$setting->name` (the name after the plugin prefix).
2. Create the Advanced `admin_category` and the ten pages. Page ids are `local_ai_course_assistant_<id>` (`essentials`, `models`, `features`, `safety`, `cost`, `adv_retrieval`, `adv_documents`, `adv_prompt`, `adv_integrations`, `adv_legacy`). The existing id `safety` is reused on purpose. Titles come from lang strings `settingspage:<id>`, `settingspage:advanced` for the category.
3. For each page in `PAGES` order: add a navigation notice (4.3), then each section: an `existing` heading is the staged heading object moved as is; a `new` heading is `new admin_setting_heading('local_ai_course_assistant/layout_<key>_heading', get_string('layout:<key>', ...), optional get_string('layout:<key>_desc'))`; a `banner` entry is the staged notice moved with no heading. Then the section's settings, in the listed order.
4. Any staged object that is neither placed nor in `DROPPED` is appended to `adv_legacy` with `debugging(..., DEBUG_DEVELOPER)`. It must never be silently lost.
5. Attach each rule in `$dependencies`: both ends must be on the same page; otherwise skip and `debugging(...)` exactly as today. Tests assert none is skipped.
6. Register: the five task pages directly under `local_ai_course_assistant`, then the Advanced category with its five pages, then the external tool pages as today, then eleven hidden redirect pages (4.4).

A page's `intro` text (one sentence, `settingspage:<id>_intro`) goes in the navigation notice. Pages open with that notice, then a heading, so `settings_page_structure_test` keeps its meaning (a notice is not a setting; it already skips `_anchor` names, extend it to the new `nav_` names).

### 4.3 Navigation

The old in-page table of contents (`#sec-ai` jump links) is replaced. `version_banner` stays on Essentials, but its body becomes the version banner plus the quick links (the existing `$quicklinks` string, unchanged); the `<div class="sola-toc">` list of jump links goes. Every page gets a notice named `nav_<id>` that renders one row of links to the five task pages plus "Advanced" (linking to the first Advanced page), the current page marked, then the page intro. Links are `admin/settings.php?section=<id>` URLs. Styling reuses the `sola-toc` classes in `styles.css` where possible.

### 4.4 Redirects

For each pair in `REDIRECTS` register a hidden `admin_externalpage('local_ai_course_assistant_<old>', ..., new moodle_url('/admin/settings.php', ['section' => '<new full id>']), 'moodle/site:config', true)`. The one old id that is also a new id (`safety`) needs no redirect and is not in the map. Implementer: confirm against `admin/settings.php` that an external page is redirected rather than rejected; if not, use the mechanism core does support and say so.

### 4.5 Internal links

`audit_log.php`, `emergency_admin.php` and `rag_admin.php` link to `..._general`; point them at `..._essentials`. `classes/autoupgrade/page.php` links to `..._operations`; point it at `..._cost`. Any lang string or doc that names an old page by title is updated in 4.9.

### 4.6 Lang strings

Add to `lang/en`: `settingspage:essentials`, `:models`, `:features`, `:cost`, `:advanced`, `:adv_retrieval`, `:adv_documents`, `:adv_prompt`, `:adv_integrations`, `:adv_legacy`, each `_intro`, the page names for the nav row, and `layout:<key>` for the 16 new headings (`general`, `contact`, `faq`, `roles`, `failover`, `voice`, `activelearners`, `embedding`, `searchtuning`, `modelcalls`, `promptbudget`, `history`, `appearance`, `remote`, `optimizer`, `legacy`). Wording is in Appendix B. Add them to the marked block in `KNOWN_UNTRANSLATED`. Leave the old `settingspage:*` page title and `sec_*` strings in place (other locales translated them; a later cleanup removes the dead ones).

### 4.7 Course page "More options" (report item A4)

`course_settings.php` shows base URL, system prompt, temperature and monthly spend cap for each course. On prod no course uses base URL or system prompt or spend cap and one uses temperature. Put those four behind a native `<details>` element titled "More options" (closed unless one of them already has a value). No data change.

### 4.8 Tests and guards

- `settings_layout_test` (new): every name in the old admin tree is placed once or dropped; every `new` heading key has a lang string; every `PAGES` entry names a real staged object; page ids unique; no rule skipped; each page within the dependency budget; the redirect pages exist and point at real pages; **budget guard**: the five task pages together carry at most 150 settings (today's layout has 144) and no page carries a secret field that is not on a page that already had one (do not move secrets to Essentials).
- Update every test that reads `$category->children` to collect pages recursively (the Advanced group is a nested category): `settings_page_structure_test`, `settings_dependencies_test`, `settings_no_secret_in_page_test`, `settings_secret_masking_test`, `settings_branding_test`, `settings_save_report_test`. `settings_page_structure_test` expects at least 10 pages.
- `settings_dependencies_test::test_no_setting_carries_more_than_one_dependency` and `test_removed_settings_are_no_longer_registered` read only the first page today; make them read all pages.
- No change to any setting's default is allowed: a test snapshots `name => default` for every setting before and after the move (the snapshot is taken from a fixture generated now, committed under `tests/fixtures/`).

### 4.9 Usage report tool (report item D1)

`admin/cli/settings_usage_report.php`: for the site it runs on, print every plugin setting as one of `default`, `changed`, `secret set`, `secret unset`, `not stored`, comparing stored values to the registered default by hash so no value is printed; `--json` for machines; `--per-course` adds counts of per-course override settings and per-course config rows; `--tables` adds exact row counts of the plugin's tables. Never prints a stored value or a secret. Tested for: a default setting, a changed one, a set secret (shows `secret set` and nothing else), a missing row.

### 4.10 Docs and release prep

`.wiki/Admin-Settings.md` and `README.md` describe the old page names: rewrite to the new menu (use `scripts/push_wiki.py`, never commit `.wiki/` by hand). Add `.drafts/v7.10.0-release-notes-and-walkthrough.md` (seed with `scripts/new_release_notes.py --version 7.10.0`). Add a rule to the repo `CLAUDE.md`: a new feature ships with at most three visible settings; every new setting gets a home in `settings_layout_map.php`. Bump `version.php` to `2026101801` / `7.10.0` and update the CLAUDE.md version lines. `.wiki/Changelog.md` entry.

### 4.11 Not in Release 1

No setting renamed, deleted, re-defaulted or re-labeled; no data migration; no Model roles table; no key changes. `sec_*_anchor` notices stop rendering (they are in `DROPPED`); that is the only thing that disappears.

## 5. Release 2 (7.11.0): collapse the repeated choices

Decided here, designed in its own plan after 7.10.0 ships.

- **B1 Model roles table.** One table with a row per role (quiz, essay, mastery classifier, premium escalation, Soapbox vision, benchmark judge, embeddings, rerank). It is a view over the existing settings: a custom admin setting reads and writes the existing `quiz_provider`, `quiz_model`, ... names, logs each real name to the config log, and keeps defaults working. The stored names stay (ruling 10), so the policy bundle allowlist, auto-upgrade, the model registry and every reader keep working untouched.
- **B2 Pasted keys into the key table.** The five single-key boxes (embedding, rerank, migration target, realtime, self-hosted speech) and the three "key from" selects stop rendering; an upgrade step copies each pasted key into a Providers and keys row (same vendor and base-URL checks as 7.9.0), points the role at it, and keeps the old setting readable as a fallback until 8.0.0. The base URL column replaces the three separate base-URL settings in the UI.
- **B3 Course key profiles.** A course can choose a key row by label instead of holding its own key (new nullable column on the per-course config table, upgrade step, backup/restore, privacy). `admin/cli/course_keys_report.php` with `--dry-run` lists per-course keys that are duplicates of a table row (by hash) and proposes the switch; `--apply` does it. Nothing runs automatically.
- Tests and migration rollback notes per upgrade step. Same i18n approach as ruling 8.

## 6. 8.0.0: retire what's dead

Decided here, designed in its own plan after 7.11.0 ships.

- Remove the Soapbox code and its settings (ruling 12). Keep the tables and the privacy provider's coverage of them; drop nothing. In-product notice already says Soapbox is deprecated.
- Remove `prompt_budget_auto_tune` and its daily task.
- Delete the pasted-key settings and their fallbacks (after 7.11.0's copy step; the upgrade chain runs the copy before the delete) and the legacy per-role settings only if Release 2 left any duplicated.
- Delete the old `settingspage:*` and `sec_*` strings from all 46 locales.
- Talking avatars: untouched (ruling 4).

## 7. Risks

- **Moving objects between pages** relies on `admin_settingpage::add()` accepting an object that was on another page and on `$page->settings` keeping insertion order. Verified by reading core; the first task proves it with the layout test.
- **Admin search** indexes whatever is in the tree, so moved settings stay findable.
- **The all-settings hub page** (`admin/category.php?category=local_ai_course_assistant`) renders pages in one form. Check how it treats the nested Advanced group and record the result in the release notes; the 7.8.1 incident was never reproduced, so do not claim it is fixed.
- **Prod is frozen**: 7.10.0 reaches Saylor only when Catalyst next upgrades Learn and Degrees (2027). Measurements of prod describe 7.5.7.

## Appendix A: the layout

Generated from `classes/settings_layout_map.php`; the PHP file is authoritative.

**Essentials** (`essentials`)

- (notice, no heading): `version_banner`
- new heading `layout:general`: `enabled`, `default_course_mode`, `auto_open`, `hidden_categories`, `starters_link`, `analytics_link`
- heading `branding_heading`: `institution_name`, `institution_short_name`, `display_name`, `short_name`, `welcome_message`, `chat_greeting`, `avatar`, `customavatars`
- new heading `layout:contact`: `contact_email`, `dpo_email`, `privacy_external_url`, `privacy_notice_override`
- new heading `layout:faq`: `faq_content`, `supplemental_courses`
- heading `footer_links_heading`: `footer_courses_text`, `footer_courses_url`, `feedback_link_label`, `feedback_panel_intro`

**Models and keys** (`models`)

- heading `providertable_heading`: `comparison_providers`
- heading `providerdefault_heading`: `provider`, `model`, `max_tokens`, `reasoning_effort`, `systemprompt`, `deployment_profile_link`, `selftest_link`
- new heading `layout:roles`: `quiz_provider`, `quiz_model`, `essay_provider`, `essay_model`, `mastery_classifier_provider`, `mastery_classifier_model`, `mastery_classifier_weight`, `mastery_classifier_threshold`
- heading `premium_escalation_heading`: `premium_escalation_enabled`, `premium_escalation_provider`, `premium_escalation_model`, `premium_escalation_triggers`, `premium_escalation_course_tags`
- new heading `layout:failover`: `spend_failover_chain`, `failover_per_call_enabled`, `failover_timeout_chat`, `backend_retry_attempts`, `backend_retry_max_wait`

**Features** (`features`)

- heading `pedagogy_defaults_heading`: `mastery_enabled`, `socratic_mode_enabled`, `worked_examples_enabled`, `flashcards_enabled`, `code_sandbox_enabled`, `code_sandbox_pyodide_baseurl`, `essay_feedback_enabled`
- heading `mastery_heading`: `objectives_admin_link`, `crossmastery_enabled`, `mastery_starter_enabled`, `program_path_enabled`, `learning_path_enabled`, `mastery_threshold`, `outcomes_benchmark_default`, `outcomes_panel_enabled`, `mastery_window`, `learning_path_mastery_threshold`, `mastery_decay_enabled`, `mastery_decay_half_life_days`
- heading `studyplan_heading`: `studyplan_enabled`, `reminders_email_enabled`, `reminders_whatsapp_enabled`, `whatsapp_api_url`, `whatsapp_api_token`, `whatsapp_from_number`, `whatsapp_blocked_countries`, `inactivity_reminder_enabled`, `inactivity_threshold_days`
- new heading `layout:voice`: `voice_tab_enabled`
- heading `survey_heading`: `survey_enabled`, `survey_trigger_messages`, `survey_frequency`
- heading `rubric_heading`: `practice_scoring_enabled`
- heading `usertesting_heading`: `usertesting_enabled`, `usertesting_external_url`, `usertesting_editor_link`
- new heading `layout:activelearners`: `active_learners_enabled`, `active_learners_scope`
- heading `faq_heading`: `support_enabled`, `support_courseid`, `zendesk_enabled`, `escalation_intent_patterns`, `zendesk_require_consent`, `zendesk_subdomain`, `zendesk_email`, `zendesk_token`
- heading `radar_batch_heading`: `radar_batch_enabled`
- heading `empathy_heading`: `outreach_master_enabled`, `goals_feature_enabled`, `milestones_feature_enabled`, `memory_feature_enabled`, `struggle_classifier_enabled`, `outreach_dryrun`

**Safety and privacy** (`safety`)

- heading `quizlock_heading`: `quiz_lock_enabled`, `quiz_lock_window_minutes`, `quiz_lock_scope`
- heading `quiz_hide_heading`: `hide_on_quiz_for_students`, `hide_on_quiz_for_staff`
- heading `offtopic_heading`: `offtopic_enabled`, `offtopic_max`, `offtopic_action`, `offtopic_lockout_duration`
- heading `wellbeing_heading`: `wellbeing_enabled`
- heading `integrity_heading`: `integrity_enabled`, `integrity_email`, `integrity_link`, `audit_retention_days`, `conversation_retention_days`, `validators_runtime_mode`, `csp_course_pages_mode`

**Cost and reliability** (`cost`)

- heading `spend_guard_heading`: `token_analytics_link`, `spend_cap_period`, `spend_cap_site`, `spend_cap_chat`, `spend_cap_voice`, `spend_cap_rag`, `spend_cap_analytics`, `spend_cap_per_course_default`, `spend_notify_emails`
- heading `cost_anomaly_heading`: `cost_anomaly_enabled`, `cost_anomaly_multiplier`, `unanswered_check_enabled`, `unanswered_window_hours`, `unanswered_min_questions`, `unanswered_min_answer_rate`, `unanswered_min_failed_turns`
- heading `anomaly_digest_heading`: `anomaly_digest_enabled`, `anomaly_digest_threshold_pct`, `anomaly_digest_floor_usd`, `anomaly_digest_recipient_email`, `anomaly_digest_slack_webhook`, `anomaly_digest_teams_webhook`
- heading `autoupgrade_heading`: `autoupgrade_mode`, `autoupgrade_budget_usd`, `autoupgrade_eval_courseid`
- heading `price_drift_heading`: `price_drift_check_enabled`, `price_drift_tolerance_pct`, `price_drift_eol_horizon_days`, `model_eol_surfaces`
- heading `policy_bundle_heading`: `policy_bundle_enabled`, `policy_bundle_url`, `policy_bundle_pubkey`

**Advanced > Retrieval** (`adv_retrieval`)

- heading `rag_heading`: `rag_enabled`, `rag_auto_reindex_drifted`
- heading `external_resources_heading`: `external_resources_enabled`, `external_resources_allowlist`
- new heading `layout:embedding`: `embed_provider`, `embed_key_from`, `embed_apikey`, `embed_model`, `embed_apibaseurl`, `embed_dimensions`, `embed_query_model`, `embed_input_type_mode`, `embed_dtype`
- new heading `layout:searchtuning`: `rag_topk`, `rag_min_similarity`, `rag_currentpage_boost`, `rag_scope`, `rag_chunksize`, `rag_return_scope`, `rag_window_size`, `rag_parent_max_chars`

**Advanced > Reranking and documents** (`adv_documents`)

- heading `rerank_heading`: `rerank_enabled`, `rerank_key_from`, `rerank_apikey`, `rerank_model`, `rerank_apibaseurl`, `rerank_candidates`, `rerank_margin_threshold`, `rerank_min_query_chars`, `rag_admin_link`
- heading `embed_migration_heading`: `embed_migration_target_provider`, `embed_migration_target_model`, `embed_migration_target_dimensions`, `embed_migration_target_apikey`
- heading `rag_sources_heading`: `rag_extract_pdf`, `rag_pdftotext_path`, `rag_extract_docx`, `rag_extract_pptx`, `rag_extract_h5p`, `rag_extract_scorm`, `rag_scorm_max_mb`, `rag_fetch_transcripts`, `rag_iframe_host_patterns`, `rag_transcript_url_pattern`
- heading `performance_heading`: `max_content_per_resource`, `max_total_content`

**Advanced > Prompt, voice and display** (`adv_prompt`)

- new heading `layout:modelcalls`: `apibaseurl`, `temperature`, `claude_temperature_allow_prefixes`, `backend_context_tokens`, `enable_thinking`, `ssrf_trusted_endpoints`, `xai_proxy_url`, `xai_proxy_jwt_secret`
- new heading `layout:promptbudget`: `prompt_budget_mode`, `prompt_budget_chars`, `prompt_budget_nowindow_warning`, `current_page_content_maxchars`, `prompt_verbosity`, `prompt_debug_enabled`, `socratic_verbose`, `prompt_metrics_enabled`
- heading `prompt_proportions_heading`: `prompt_section_weights`, `prompt_context_boost_mode`, `prompt_section_weights_coach`
- new heading `layout:history`: `maxhistory`, `history_mode`, `history_semantic_minscore`, `history_candidates`, `profile_update_interval`
- heading `realtime_heading`: `realtime_enabled`, `realtime_key_from`, `realtime_apikey`, `realtime_voice`
- heading `voice_providers_heading`: `voice_providers`, `voice_active_realtime`, `voice_active_tts`, `voice_active_stt`, `max_audio_mb`
- heading `stt_selfhosted_heading`: `stt_selfhosted_enabled`, `stt_selfhosted_url`, `stt_selfhosted_model`, `stt_selfhosted_apikey`, `stt_selfhosted_warm`
- new heading `layout:appearance`: `display_mode`, `avatar_animation_enabled`, `position`, `avatar_color`, `avatar_fill`

**Advanced > Integrations and governance** (`adv_integrations`)

- heading `redash_heading`: `redash_api_key`, `redash_allowed_origin`, `redash_export_window_days`, `redash_allow_deanonymized`, `spend_export_key`, `redash_base_url`, `redash_user_api_key`, `redash_data_source_id`
- heading `cdn_heading`: `cdn_url`, `cdn_version`
- new heading `layout:remote`: `remoteconfigurl`
- heading `vendor_data_heading`: `vendor_dpa_admin_page_enabled`, `vendor_dpa_overrides`, `rate_card_overrides`, `rate_card_auto_refresh`, `rate_card_upstream_url`, `rate_card_refresh_button`, `model_registry_link`
- heading `bench_heading`: `bench_default_samples`, `bench_min_quality_n`, `bench_judge_provider`, `bench_judge_model`
- heading `rec_heading`: `rec_quality_epsilon`, `rec_savings_floor`, `rec_quality_margin`, `rec_min_quality_n`
- new heading `layout:optimizer`: `opt_cost_weight`, `opt_quality_weight`

**Advanced > Soapbox, avatars and legacy** (`adv_legacy`)

- heading `soapbox_heading`: `soapbox_deprecated_notice`, `soapbox_enabled`, `soapbox_stt_mode`, `soapbox_rubric_link`, `soapbox_max_seconds`, `soapbox_max_recordings`, `soapbox_retention_days`, `soapbox_video_quality`, `soapbox_slide_vision`, `soapbox_gesture_vision`, `soapbox_vision_provider`, `soapbox_vision_model`, `soapbox_storage_bucket`, `soapbox_storage_region`, `soapbox_storage_prefix`, `soapbox_storage_key`, `soapbox_storage_secret`
- heading `talking_avatar_heading`: `talking_avatar_enabled`, `talking_avatar_provider`, `cdn_bundle_url`, `did_api_key`, `did_persona_id`, `heygen_api_key`, `heygen_persona_id`, `tavus_api_key`, `tavus_persona_id`, `synthesia_api_key`, `synthesia_persona_id`, `avatar_rate_card_overrides`, `did_webhook_secret`, `heygen_webhook_secret`, `tavus_webhook_secret`, `synthesia_webhook_secret`
- new heading `layout:legacy`: `prompt_budget_auto_tune`

**Dropped (no longer render):** `sec_general_anchor`, `sec_ai_anchor`, `provider_heading`, `sec_content_anchor`, `sec_safety_anchor`, `sec_engagement_anchor`, `sec_branding_anchor`, `sec_integrations_anchor`, `sec_save_anchor`

## Appendix B: new strings (English)

Page titles: essentials "Essentials"; models "Models and keys"; features "Features"; safety "Safety and privacy"; cost "Cost and reliability"; advanced "Advanced"; adv_retrieval "Retrieval"; adv_documents "Reranking and documents"; adv_prompt "Prompt, voice and display"; adv_integrations "Integrations and governance"; adv_legacy "Soapbox, avatars and legacy".

Page intros (one sentence each, shown under the navigation row):
- essentials: "The settings every site needs: switch SOLA on, name it, and say who to contact."
- models: "Which AI providers and keys SOLA uses, the default model, and what takes over if it fails."
- features: "Switch learner-facing features on or off. A feature's own options appear when you turn it on."
- safety: "Quiz mode, off-topic limits, wellbeing, integrity checks and data retention."
- cost: "Spend caps, cost and answer-rate alerts, and automatic model upgrades."
- adv_retrieval: "Search over course content: embeddings and how many passages are returned. Most sites never change these."
- adv_documents: "Reranking, switching embedding models, and which document types are read into the index."
- adv_prompt: "Prompt size and history, voice and speech providers, and the look of the chat widget."
- adv_integrations: "Analytics export, CDN, remote configuration, vendor data, benchmarks and optimizer weights."
- adv_legacy: "Deprecated Soapbox, talking-avatar vendors and other settings that are parked here."

New headings (`layout:<key>`): general "General"; contact "Contact and privacy"; faq "FAQ and conversation starters"; roles "Model roles"; failover "Failover and retries"; voice "Voice"; activelearners "Active learners"; embedding "Embeddings"; searchtuning "Search tuning"; modelcalls "Model call details"; promptbudget "Prompt budget and debugging"; history "History and learner profile"; appearance "Appearance"; remote "Remote configuration"; optimizer "Optimizer weights"; legacy "Deprecated settings".
