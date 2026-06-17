# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# SOLA — Saylor Online Learning Assistant
## Moodle Plugin: `local_ai_course_assistant`

---

## Project Overview

SOLA (Saylor Online Learning Assistant) is a Moodle local plugin that provides an AI-powered learning coach embedded in course pages. Students interact via a side tab on the right edge of the page (default: halfway down), which opens a chat drawer. A floating avatar button at the bottom corner is an alternative placement available via the Display Mode admin setting.

- **Plugin component:** `local_ai_course_assistant`
- **Current version:** `2026061703`, release `6.8.3`
- **Source folder (canonical):** the git repo at `~/Library/CloudStorage/Dropbox/!Saylor/ai-projects/ai_course_assistant/` (edit and commit here; the older `aicoursetutor/ai_course_assistant` path is a stale remnant, do not deploy from it)
- **Zip for upload:** built from the repo via `create_fixed_zip.sh`
- **GitHub:** `https://github.com/saylordotorg/moodle-local_ai_course_assistant` (public)
- **Saylor production:** v5.4.5 on Learn + Degrees (as of 2026-05-12); **v6.8.2 sent to Catalyst for the prod upgrade on 2026-06-17**. Dev sites (dev / dev405 / dev500 / dev501 / dev503) track the latest release. Prod upgrade runbook stays at v6.8.2 (matches the Catalyst submission): one jump v5.4.5 → v6.8.2 (`.drafts/sola-prod-upgrade-runbook-v5.4.5-to-v6.8.2.md`); Catalyst request: `.drafts/catalyst-prod-deploy-request-2026-06-11.md`. NOTE: the Moodle plugin **directory** track is a separate version (v6.8.3, the CONTRIB-10574 29/29 resubmission); the directory listing and the prod pin need not match. Resubmission email: `.drafts/moodle-directory-resubmission-email-v6.8.3.md`.

---

## Key Features

- Multi-provider AI backend with SSE streaming
- Personalized greeting and coaching (first name, nicknames, encouragement style)
- Practice quizzes (setup panel + interactive question cards + score summary)
- Study plans and scheduled reminders
- Analytics dashboard (usage, provider comparison)
- Per-course configuration (`classes/course_config_manager.php`)
- 46-language i18n with auto-detection; 46-lang STT/TTS support
- Voice input (STT) and output (OpenAI TTS with browser Speech API fallback)
- OpenAI Realtime voice mode (WebSocket, live transcript, ELL coaching)
- Procedurally-generated SVG avatar (3 genders, 5 skin tones, 6 hair colours, 3 styles/gender)
- Draggable widget (header + avatar toggle button)
- Resizable drawer (drag N/W/NW handles); size saved to localStorage
- 2-state expand (normal / expanded)
- 50-pair conversation cap
- Dynamic SOLA_NEXT suggestion chips after each AI response
- RAG semantic search (embedding-based retrieval, enabled by default)
- Conversation starters overlay (shown on open / after reset / after quiz exit)
- Self-hosted readiness (v5.10.0): token-aware prompt budgeting clamped to a backend `max_model_len` (`backend_context_tokens`), bounded pre-first-byte retry on transient 429/503, apply-once deployment presets, and an on-demand backend self-test page
- Security/privacy (v5.10.1): `email_optout` covered by the privacy provider erasure path, avatar-viewer session-ownership check, Zendesk escalation gated on disclosed first-run consent (`zendesk_require_consent`), de-anonymized Redash export audit-logged
- First-run consent scroll-gate (v5.10.1, fixed v5.10.2): Accept button disabled until the learner scrolls the notice to the bottom; inline JS in `templates/chat_widget.mustache`, regression harness `tests/a11y/consent-gate-check.js` (Puppeteer)
- Vendor-rec optimizations (v5.11.0): mastery classifier routed off chat tier via `mastery_classifier_provider` (default `openai`/`gpt-4o-mini`, saves ~$220/mo at 100k MAU); Voyage AI embeddings (voyage-3.5, asymmetric query/document `input_type`, MRL `output_dimension`); opt-in Voyage rerank-2.5 two-stage RAG (`rerank_enabled` checkbox, default off); OpenAI `prompt_tokens_details.cached_tokens` captured for auto-prefix cache-hit visibility; Claude Opus 4.7+ temperature deny-list (Anthropic deprecated `temperature` on reasoning-class Opus models, was bubbling up as the generic error string)
- Premium escalation tier (v5.12.0): per-turn `premium_router` evaluates each chat call against admin-configured regex triggers (default ships with multi-step STEM markers from the A.10 bake-off: derive, prove that, step by step, LaTeX math, fenced code blocks, big-O, integrals, optimization, thermodynamics) plus an optional course-shortname/idnumber allowlist; matching turns route to Claude Opus 4.8 instead of the workhorse chat tier. Off by default; expected ~$700/mo at 100k MAU at 5% escalation rate.
- Defensive defaults (v5.13.0): `spend_cap_per_course_default` fallback (every course without an explicit per-course override gets this cap; existing 80/95/100% notification email pipeline carries through). Fix: `emergency_control --chat` was a silent no-op v5.4.5 → v5.12.x; v5.13 adds a dedicated `emergency_chat_disabled` flag that spend_guard::check() consults first.
- Operational maturity (v6.0.0): daily `cost_anomaly_check` scheduled task compares today's site-wide SOLA spend vs rolling 7-day median, emails `spend_notify_emails` recipients when today > multiplier × median (default 2.0). Catches runaway courses + accidental premium-tier enable + provider misroute that the cap thresholds miss. Off by default. Companion `admin/cli/send_spend_alert_test_email.php` lets admins verify alert delivery BEFORE relying on it.
- Security + hardening hotfix (v6.0.1): conversation history filtered to user/assistant roles so internal telemetry rows ([PremiumRouter]/[Rerank]/[Embedding]) never reach learners or the LLM; email opt-out honored by the spend-alert self-test; `role_timecreated` index on msgs.
- Admin UX (v6.1.0): web emergency kill-switch panel (`emergency_admin.php`, red link from settings quicklinks); `cached_tokens` persisted per call + Cached Tokens card in token analytics; token-analytics category fix (RAG rows were silently excluded); settings regrouping. v6.1.1: flashcards review page Moodle 5.x fatal fixed (`print_error` → `moodle_exception`).
- Selfhosted Whisper STT (v6.3.0): `stt_selfhosted_url`/`stt_selfhosted_model`/`stt_selfhosted_apikey` settings; any OpenAI-compatible transcription server (whisper-server Docker, speaches/faster-whisper, whisper.cpp). Default STT path when URL configured; paid label in `voice_active_stt` overrides; bypasses voice spend guard (free); keyless auth supported; private/http servers need an `ssrf_trusted_endpoints` entry. Resolution + normalization in `classes/voice_registry.php` (`SELFHOSTED_LABEL`, `selfhosted_stt_config()`, `selfhosted_stt_endpoint()`); tests in `tests/voice_registry_selfhosted_test.php`. Realtime voice conversations remain OpenAI WebSocket only — selfhosted covers the mic STT path.
- i18n catch-up (v6.3.0): 100 keys (v5.11→v6.2 surfaces) translated into all 45 non-English languages; 46/46 locales pass the completeness check with zero missing keys.
- Signed policy bundles (v6.4.0): behavior-as-data updates without a code deploy. Daily `policy_bundle_sync` task fetches a JSON envelope from `policy_bundle_url`, verifies the Ed25519 signature against `policy_bundle_pubkey`, enforces a hard-coded 43-setting allowlist (`policy_bundle::ALLOWED_KEYS` — never keys/URLs/webhooks/emails/SSRF/consent/emergency) and a strictly-increasing version, applies with an audit row (old/new per key). Fail closed. Authoring CLI: `admin/cli/policy_bundle_tool.php` (--keygen/--sign/--verify/--status/--sync); --sign pre-flights the same checks. Off by default. Core: `classes/policy_bundle.php`; tests: `tests/policy_bundle_test.php`. Strategy doc: `.drafts/sola-upgrade-independence-2026-06-11.md`. LIVE on the dev fleet: all 5 sites sync from `https://dev.sylr.org/sola-policy.json`; private key at `~/.sola/` on Tom's Mac (never commit); fleet bundles carry only fleet-safe settings (rerank keys stay per-site — only dev has a Voyage key). Operator howto: `.drafts/sola-policy-bundle-howto-2026-06-11.md`.
- RAG chunk-selection debug (v6.4.1): `prompt_debug_enabled` log gains a RETRIEVED CHUNKS block (score/cmid/module/content per chunk); debug viewer renders a chunks card; `prompt_playground.php` gains a live-retrieval test against the real retriever.
- Avatar animation probe (v6.5.0, Stage 0 of the talking-avatar evaluation): SVG avatar idle blink (pure CSS, keyed off `data-avataranim` on the widget root) + `avatar_animation_enabled` setting (default on) gating blink, Web Audio mouth-sync, CSS mouth fallback, and the speaking pulse as one unit; per-course A/B override `avatar_animation_course_<id>`; on the policy bundle allowlist; `prefers-reduced-motion` respected in the rAF path. Decision doc: `.drafts/sola-talking-avatars-decision-2026-06-11.md`.
- A/B experiment readout (v6.6.0): analytics dashboard comparison card (two course pickers, 10 engagement metrics with B vs A deltas) powered by `analytics::get_experiment_metrics()`; generic for any per-course experiment. Fix: `get_session_stats()` undercounted sessions (userid-keyed rows collapsed by get_records_sql; now id-keyed). Prompt debug formatter/parser/rotation extracted to `classes/prompt_debug.php` (shared by sse.php, prompt_debug_view.php, prompt_playground.php) with 12 tests pinning the log format (`tests/prompt_debug_test.php`).
- Full white-label rebranding (v6.8.0): four admin settings drive the entire product name — `institution_name` (default "Saylor University"), `institution_short_name` ("Saylor"), `display_name` ("Saylor Online Learning Assistant"), `short_name` ("SOLA"). `classes/branding.php` is the single source: `apply()` resolves `[[uniname]]`/`[[unishort]]`/`[[tutorname]]`/`[[tutorshort]]` tokens, `str()` = apply(get_string()), `token_map()`/`token_map_json()` feed the browser. All 46 lang files are tokenized (literal names replaced with tokens, copyright headers excepted); resolution happens at output boundaries — system prompt (`context_builder::build_system_prompt` applies after `{{coursename}}` substitution; the old incomplete `str_replace('You are SOLA'...)` is gone), widget JS strings + greeting (token map on the widget root, resolved in `chat.js`), the one mustache `{{#str}}` brand label (pre-rendered `chat_open_label`), admin settings copy (every brand-bearing `get_string` in `settings.php` wrapped, incl. the dynamic pedagogy + talking-avatar loops), scheduled-task names, and the standalone pages/emails. `SOLA_NEXT` (protocol token) and lowercase `saylor.org` (emails/URLs) are intentionally NOT tokenized. Defaults unchanged → Saylor installs render identically. Leak-guard test asserts no lang string retains an unresolved `[[token]]` after `apply()` (`tests/branding_test.php`).
- Moodle plugin-directory review remediation (v6.8.1, CONTRIB-10574): all 29 reviewer findings addressed — external-fn capability checks, `LICENSE`, PARAM_RAW tightening, privacy `add_external_location_link()` (AI/voice/Zendesk/radar), frankenstyle-prefixed global funcs, File-API temp dirs (no `mkdir(0777)`), `curl_init`→Moodle `\curl` everywhere (DNS-rebinding pin via `security::resolve_pin_options()`; streaming SSE smoke-tested), `$_SESSION`→`MODE_SESSION` cache (`uistate`), config API in upgrade/digest, autoloading, boilerplate headers, inline CSS→`styles.css`, recordset course picker. AJAX→external services: `consent.php`/`radar_cite.php` removed, replaced by `external\record_consent` + `external\get_radar_citation` (`transcribe.php` stays AJAX — binary audio upload). Templates/Output API: `vendor_dpa.php` + `privacy.php` migrated (`instructor_dashboard.php` still echo-based, follow-up). innerHTML→`core/templates` in `audio_player`/`learning_radar`/`analytics_dashboard`. Non-Moodle build sources (`cdn/` shims, `services/`) excluded from the published zip. Per-issue fix notes on GitHub issues #68-#96 (closed).
- Post-release hardening (v6.8.2): `audio_player` glyph built via DOM `createElementNS` (it ships in the standalone CDN bundle, which has no `core/templates`) — fixes the "Deploy CDN Assets" dependency-check; live-API smoke test (`tests/smoke_api.py`) skips narrow transient REST timeouts/5xx instead of filing false-positive issues; the course "More" / left-nav instructor-dashboard link relabeled `analytics:title`→`instructor_dashboard:navlink` ("AI Tutor Dashboard") + `showinflatnavigation`, gated on `:viewanalytics` (teachers + admins).
- Soapbox speech practice (v6.7.0): learner records a longer spoken presentation (optional name/topic/target-length), it is transcribed, scored against a per-course `speech` rubric, and saved to a personal score history. Off by default; per-course gated via `feature_flags::resolve('soapbox', $courseid)` (site `soapbox_enabled` default + `soapbox_enabled_course_<id>` three-way override). Learner page `soapbox.php` (course-nav link for `:use` when enabled); long-form STT endpoint `soapbox_transcribe.php` (300s timeout, 25MB cap, `soapbox_stt` rate bucket 12/600); scoring external function `score_speech`. Three STT tiers chosen by `soapbox_stt_mode` (server default / browser): self-hosted Whisper (free, via voice_registry), hosted OpenAI Whisper, or in-browser Web Speech API (free, no server). New `speech` rubric type in `rubric_manager` (authorable on `rubric_admin.php`). Nullable `session_meta` column on `_practice_scores` holds the name/topic/target blob; audio and transcript text are NEVER stored. Privacy provider covers `session_meta` (metadata + export). ESL support (v6.7.0/v6.7.1): per-course `soapbox_level_course_<id>` "Course type / speaking level" select drives BOTH the AI coaching register AND the fallback rubric via `rubric_manager::speech_presets()` — four levels: General speech (default), ESL beginner, ESL intermediate (v6.7.1), ESL advanced, each a loadable/editable sample rubric (`rubric_admin.php` "Load a sample rubric" picker, `?preset=<level>`). `score_speech` injects the level hint and uses the preset criteria as the no-custom-rubric fallback. Full i18n: all 50 Soapbox strings translated into 45 languages (46/46 complete).

---

## AI vendor stack (text-only baseline)

- **Chat tutor:** Gemini 2.5 Flash on Vertex AI (primary), gpt-4o-mini failover.
- **Quiz coach / mastery classifier / analytics / digests:** gpt-4o-mini.
- **Anti-cheat reference (~5% of turns when integrity routing ships):** Claude Haiku 4.5.
- **Premium escalation tier (v5.12 router):** Claude Opus 4.8 on ~5% of turns matching STEM markers + course allowlist. Off by default.
- **Embeddings:** OpenAI `text-embedding-3-small` currently; recommended migration to Voyage-3.5 at 50K+ MAU.
- **Re-ranker (opt-in):** Voyage rerank-2.5.
- **Judge harness:** Claude Sonnet 4.6 (out of contestant pool).
- **Ultimate-fallback chat for EU residency (NOT in Saylor default):** Mistral Small. Provider class stays available so non-Saylor sites can select it; not in Saylor's `spend_failover_chain` (pending Mistral training-opt-out + ZDR, and Saylor doesn't need EU residency at current scale).
- **Audio / Voice / Avatar:** deferred (Appendix B of `.drafts/sola-vendor-recommendations-2026-06-09.md`).

See `.drafts/sola-vendor-recommendations-2026-06-09.md` (concise canonical) and `.drafts/sola-vendor-optimization-by-mau-2026-06-09.md` (per-MAU-tier optimization playbook) for the full vendor story.

---

## Important Architecture Notes

- `handleReset` keeps message history visible — it just shows the starters overlay on top; history is NOT cleared
- Topic picker (starters): no AI-guided option; quiz setup keeps AI-guided option
- Exiting a quiz (exit button or cancel) returns to the conversation starters view
- `data-firstname` is passed from PHP → mustache → JS for personalized greetings
- `bindEvents` has null guards on all optional buttons (clearBtn, expandBtn, micBtn, etc.) — if a button is removed from the mustache, it won't crash init
- Admin course settings link in the header is a WRENCH icon gated on `{{#cansiteconfig}}` (re-introduced after an earlier duplicate-gear removal); the gear is the all-users preferences panel. Admins also reach settings via the course More menu (v6.6.1) and Site administration
- SOLA_NEXT: system prompt emits `[SOLA_NEXT]chip1||chip2||chip3||chip4[/SOLA_NEXT]`; `chat.js` strips tag and calls `UI.showSuggestions()`; tags are never shown to students
- Starter translations live in `STARTER_LABELS` in `amd/src/speech.js` (46 languages)
- SVG avatar: `buildFaceSVG()` + `renderAvatar()` in `ui.js`; prefs in localStorage `aica_avatar`; legacy img hidden when SVG active

---

## Key Files

| File | Purpose |
|------|---------|
| `classes/hook_callbacks.php` | Injects widget into course pages; builds template data |
| `classes/context_builder.php` | Builds AI system prompt; personalization; multilingual instructions; SOLA_NEXT instruction; v5.10.0 `effective_budget_chars` window clamp + truncation hint |
| `classes/token_estimator.php` | v5.10.0 language-aware char/token estimation; `budget_chars_for_window` ceiling (shared by the budget clamp, metrics tuner, and self-test) |
| `classes/backend_probe.php` | v5.10.0 on-demand live backend capability probe (chat round-trip, window detect, embedding); rendered by `backend_selftest.php` |
| `classes/deployment_profile.php` | v5.10.0 apply-once preset definitions (self-hosted small-context / hosted large-context); applied by `deployment_profile.php` page |
| `classes/course_config_manager.php` | Per-course AI configuration (including per-course `spend_cap_monthly` override) |
| `classes/spend_guard.php` | Site-wide + per-capability + per-course spend cap enforcement; emits notification emails at 80% and 95% thresholds (v5.13 adds `spend_cap_per_course_default` fallback) |
| `classes/emergency_control.php` | Site-wide kill switch. `--chat` sets `emergency_chat_disabled` (v5.13 fix; was silent no-op pre-v5.13); other flags toggle `enabled` / `voice_active_realtime` / `rag_enabled` / `outreach_master_enabled`. Admin UI at `emergency_control.php`; CLI at `admin/cli/emergency_disable.php` |
| `classes/cost_anomaly_detector.php` | v6.0.0 daily cost-anomaly detector (today vs rolling 7-day median × multiplier); `classes/task/cost_anomaly_check.php` is the scheduled task wrapper; `admin/cli/send_spend_alert_test_email.php` is the alert-delivery self-test |
| `classes/policy_bundle.php` | v6.4.0 signed policy bundle: Ed25519 verify + ALLOWED_KEYS allowlist + monotonic version + audit; `classes/task/policy_bundle_sync.php` daily task; `admin/cli/policy_bundle_tool.php` authoring CLI |
| `classes/premium_router.php` | v5.12.0 per-turn router for premium escalation tier (regex triggers + course allowlist → Opus 4.8) |
| `classes/embedding_provider/voyage_embedding_provider.php` | v5.11.0 Voyage-3.5 embedding client (asymmetric query/document, MRL dims) |
| `classes/embedding_provider/voyage_reranker.php` | v5.11.0 Voyage rerank-2.5 cross-encoder for two-stage RAG |
| `classes/conversation_classifier.php` | v5.11.0 reads `mastery_classifier_provider` + `mastery_classifier_model`; routes per-turn classification through gpt-4o-mini by default |
| `classes/provider/claude_provider.php` | v5.11.0 `model_supports_temperature()` per-prefix deny-list (Opus 4.7/4.8/4.9 reject temperature) |
| `classes/analytics.php` | Usage analytics and provider comparison |
| `classes/external/generate_quiz.php` | Quiz generation (AI-guided + manual topic) |
| `soapbox.php` | v6.7.0 Soapbox learner page (setup panel, record/transcribe, rubric feedback, My Speeches history); inline vanilla JS, server + browser STT modes |
| `soapbox_transcribe.php` | v6.7.0 long-form STT endpoint (300s timeout, 25MB cap, `soapbox_stt` rate bucket); voice_registry-resolved Whisper |
| `classes/external/score_speech.php` | v6.7.0 score a transcribed speech vs the `speech` rubric; persists score + meta (no audio/transcript) via `rubric_manager::save_score` |
| `classes/rubric_manager.php` | Practice rubrics + `_practice_scores`; v6.7.0 adds `TYPE_SPEECH`, `DEFAULT_SPEECH_CRITERIA`, and `save_score` `$meta` (→ `session_meta`) |
| `classes/external/get_realtime_token.php` | Fetches OpenAI Realtime ephemeral token |
| `classes/external/save_avatar_preference.php` | Saves avatar selection to user preferences |
| `rag_admin.php` | RAG index admin page (stat cards, per-course reindex/clear) |
| `sse.php` | SSE streaming endpoint; requires `lib/filelib.php` for curl |
| `tts.php` | OpenAI TTS endpoint |
| `amd/src/chat.js` | Main chat controller; event binding; quiz/STT/voice routing; SOLA_NEXT parsing |
| `amd/src/ui.js` | DOM manipulation; widget state; drag; expand; SVG avatar; settings panel; suggestions |
| `amd/src/quiz.js` | Quiz setup panel + interactive question cards + summary |
| `amd/src/speech.js` | STT/TTS; voice quality scoring; language detection; STARTER_LABELS (46-lang) |
| `amd/src/realtime.js` | OpenAI Realtime WebSocket; mic capture; PCM16 playback; ELL mode |
| `amd/src/repository.js` | Moodle web service calls (history, avatar, clear, realtime token, etc.) |
| `amd/src/sse_client.js` | SSE streaming client |
| `lang/en/local_ai_course_assistant.php` | All English strings |
| `templates/chat_widget.mustache` | Main widget HTML template |
| `styles.css` | All widget CSS |

---

## Header Buttons (current layout, verified against the template 2026-06-12)

From left to right in `.local-ai-course-assistant__header-actions` (template order):
1. Avatar picker (`.btn-avatar`) — opens the avatar chooser; all users
2. Talking avatar (`.btn-talking-avatar`) — person icon; conditional on `{{#talkingavatarenabled}}`
3. Learning path (`.btn-path`) — conditional on `{{#pathenabled}}`; opens the "my path" panel
4. Analytics link (`.btn-analytics`) — stats icon; conditional on `{{#canviewanalytics}}`
5. Admin course settings (`.btn-settings`) — **wrench icon**; conditional on `{{#cansiteconfig}}`; `<a href="{{coursesettingsurl}}" target="_blank">`, tooltip "Course settings"
6. Settings panel (`.btn-settings-panel`) — **gear icon**; opens in-drawer language/avatar/voice panel; all users
7. Context debug (`.btn-debug`) — conditional on `{{#contextdebugvisible}}`
8. Help (`.btn-help`) — opens the in-drawer help panel; all users
9. Clear screen (`.btn-clear`) — trash icon; tooltip "Clear screen"
10. Reset/Home (`.btn-reset`) — home icon; tooltip "Start over"; shows starters overlay WITHOUT clearing history
11. Close (`.btn-close`) — X icon; closes drawer

> **History note:** the admin settings link was removed at one point as a duplicate gear, then re-introduced as a **wrench** icon gated on `{{#cansiteconfig}}` — so admins see wrench (course settings page) + gear (preferences panel); learners see only the gear. There is no `.btn-voice`/`.btn-expand` in the header anymore: voice moved to the bottom mode nav (`{{#voicetabenabled}}`), and drawer sizing is via the drag/resize handles.

### Bottom mode nav (`.local-ai-course-assistant__bottom-nav`)
Tabs: Chat, Voice (`{{#voicetabenabled}}`), History, Progress. **Re-clicking the active Chat tab calls `UI.showStarters()`** (chat.js `setBottomMode`) — a secondary way back to the conversation starters; the primary affordance is the home/reset header button.

---

## Build Process

**CRITICAL: Always rebuild AMD build files after any JS change.**
Moodle serves `amd/build/*.min.js`, NOT the source files in `amd/src/`. Changes to source files have no effect until rebuilt.

### Rebuild JS (terser required):
```bash
BASE="/Users/tom.caswell/Library/CloudStorage/Dropbox/!Saylor/aicoursetutor/ai_course_assistant"
for f in chat ui quiz speech repository sse_client markdown audio_player realtime; do
  terser "$BASE/amd/src/${f}.js" --compress --mangle \
    --source-map "url=${f}.min.js.map" \
    -o "$BASE/amd/build/${f}.min.js"
done
```
Only rebuild the files you actually changed to save time.

### Build zip (must use Python subprocess — `!` in path causes zsh history expansion):
```python
python3 -c "import subprocess,os; subprocess.run(['bash', 'ai_course_assistant/create_fixed_zip.sh'], cwd=os.path.expanduser('~/Library/CloudStorage/Dropbox/!Saylor/aicoursetutor'), capture_output=True)"
```

---

## Release Process

Every tagged release must ship with a release-notes-and-walkthrough draft in `.drafts/`. The draft has three parts: a terse changelog headline + bullets, a "Key SOLA Features" snapshot, and a 20-minute conversational admin walkthrough script. These drafts become the source of truth for the GH release body, onboarding docs, and admin demo scripts.

### Seed the draft (first step of every release)
```bash
python3 scripts/new_release_notes.py --version 3.9.6
# -> writes .drafts/v3.9.6-release-notes-and-walkthrough.md with TODOs
```
Then carry forward Part 2 (Key Features) and Part 3 (Admin Walkthrough) from the most recent `.drafts/v*-release-notes-and-walkthrough.md`, updating only the sections that drifted. Fill in Part 1 with the headline + bullets for this release.

### Release checklist (run in order)
1. `python3 scripts/new_release_notes.py --version <N>` and fill the TODOs.
2. Bump `version.php` (`$plugin->version` and `$plugin->release`).
3. Update `.wiki/Changelog.md`.
4. Run i18n sync, PHP lint, jailbreak test (32/32), validator suite: `php admin/cli/run_validators.php` (must be 0 failures).
5. Run the manual smoke checklist (`.wiki/Release-Checklist.md`) on local Moodle. Critical path takes ~5 minutes and catches the runtime UI bugs static checks can't.
6. Commit plugin + wiki, tag `v<N>`, push, `gh release create` using the `.drafts/` file as the body source.
7. `python3 deploy_dev.py --target all` and verify BUS101 smoke on all 5 dev sites.

The validator suite is corpus-driven (`tests/security/`) and runs in milliseconds against static fixtures — no LLM round-trip, no cost. Skipping it is fine for CSS-only or i18n-only fixes; required otherwise. The smoke checklist has its own skip rules in the doc.

---

## Local Development

- **Moodle 4.5** at `~/Sites/moodle/`, moodledata at `~/Sites/moodledata/`
- **MySQL:** `brew services start mysql` — db=`moodle`, user=`moodle`, pass=`moodle`
- **PHP 8.3:** `/opt/homebrew/opt/php@8.3/bin/php`
- **Start server:** `/opt/homebrew/opt/php@8.3/bin/php -S 0.0.0.0:8080 -t ~/Sites/moodle`
  - Use `0.0.0.0` not `localhost` — makes it accessible on Tailscale network
- **URL:** http://localhost:8080 — admin: `admin` / `Admin1234!`
- **Test course:** id=2 (TEST101 "Test Course 101") with sections "Introduction", "Core Concepts"
- **Plugin location:** `~/Sites/moodle/local/ai_course_assistant/` (direct copy, no symlink)

### Deploy to local Moodle:
```bash
rsync -a --exclude=.git \
  "/Users/tom.caswell/Library/CloudStorage/Dropbox/!Saylor/aicoursetutor/ai_course_assistant/" \
  ~/Sites/moodle/local/ai_course_assistant/
```

### ALWAYS purge caches after every deploy — no exceptions:
```bash
/opt/homebrew/opt/php@8.3/bin/php ~/Sites/moodle/admin/cli/purge_caches.php
```

---

## i18n

- **46 language files:** en + ar, am, bg, bm, bn, cs, da, de, el, es, fi, fr, ha, he, hi, hu, id, ig, it, ja, ko, ms, nb, ne, nl, om, pa, pl, pt_br, ro, ru, sk, so, sv, sw, ta, th, tl, tr, uk, vi, wo, yo, zh_cn, zu
- Lang codes for STT/TTS: `amd/src/speech.js` → `SUPPORTED_LANGS` (46 total, including `en`)
- Starter button translations: `STARTER_LABELS` in `amd/src/speech.js` (46 languages × 5 starters)
- ISO 639-1 → language name mapping: `classes/context_builder.php::get_multilingual_instructions()`
- **JS string substitution:** Moodle's string cache returns raw `{$a}` — always do `.replace('{$a}', value)` in JS rather than relying on `Str.get_string()` third-argument substitution

---

## RAG (Retrieval-Augmented Generation)

- Disabled by default; enable in plugin settings
- Admin page: `rag_admin.php` — stat cards (courses indexed, chunks, embedded, active), per-course reindex/clear buttons
- **Critical:** `sse.php` must `require_once($CFG->dirroot . '/lib/filelib.php')` before calling embedding providers (curl class dependency)
- Catch block uses `\Throwable` (not `\Exception`) so PHP Errors fall back gracefully

---

## Upcoming Work

1. Prod upgrade decision: v5.4.5 → v6.8.2 on Learn + Degrees (Tom's call; runbook ready at `.drafts/sola-prod-upgrade-runbook-v5.4.5-to-v6.8.2.md`; Catalyst/Artem workflow, stagger Degrees-first recommended). v6.8.2 is merged to main (4326003) and on the full dev fleet; the v6.8.2 git tag + GitHub release still need to be cut before the Catalyst engagement.
2. Mistral training-opt-out + ZDR (external action — Saylor portal). Currently NOT in Saylor's `spend_failover_chain`; provider class stays available so non-Saylor sites can opt in.
3. Vendor enterprise commits (Vertex Tier 3+, OpenAI Tier 4, Anthropic Tier 3, Voyage enterprise). 2-4 week procurement window; only matters past 50K MAU.
4. RAG rerank: MEASURED GO (2026-06-11). Both arms run on dev with Tom's Voyage key (payment method on file lifts free-tier rate limits; spend stays in free tokens). Recall@3 55% → 72.5% (+17.5pp, identical at candidate pool 50 and 30); P50 added latency 306ms at pool 30. Recommended: `rerank_candidates=30` + `rerank_enabled=1` on dev (Tom's call), revisit cost model before prod scale (measured ~$0.97/1k queries, 4x projection — ~$485/mo at 100k MAU usage model). Report: `.drafts/sola-rag-fixture-benchmark-2026-06-10.md` section 6-7.
5. Talking avatars in the plugin (pricing / architecture discussion still pending; sit in Appendix B of `.drafts/sola-vendor-recommendations-2026-06-09.md`).

Done recently: lp-i18n catch-up batch (100 keys × 45 languages, v6.3.0); A.10 judge spot-check (verdict CONFIRMED, `.drafts/sola-a10-spot-check-2026-06-10.md`); upgrade-path test v5.4.5 → v6.3.0.
