# SOLA Production Upgrade Runbook: v5.4.5 to v6.7.1

Date prepared: 2026-06-10, retested against v6.7.1 on 2026-06-16
Scope: Learn (learn.saylor.org) and Degrees, both currently on v5.4.5 (2026050945).
Target: v6.7.1 (2026061501), one jump, no intermediate versions needed.

## Upgrade path verification (done)

The exact jump was retested on 2026-06-16 on local Moodle 4.5.10 + MySQL: a clean install of v5.4.5, seeded config (provider, spend cap) and conversation + message + practice-score data, then a single upgrade run to v6.7.1.

| Check | Result |
|---|---|
| `admin/cli/upgrade.php --non-interactive` | Completed, no errors |
| `admin/cli/check_database_schema.php` | Database structure is ok |
| Conversation + message data | Survived intact |
| Config (spend caps, provider) | Survived intact |
| New `cached_tokens` column (v6.1.0) | Created |
| New `role_timecreated` index (v6.0.1) | Created |
| New `courseid_role_timecreated` index (v6.6.0) | Created |
| New `session_meta` column on `_practice_scores` (v6.7.0, savepoint 2026061500) | Created; pre-existing rows left NULL |
| Scheduled tasks | All registered, including `cost_anomaly_check` and `policy_bundle_sync` |

The same single-column migration (`session_meta`, savepoint 2026061500) and the v6.7.1 marker (2026061501) also applied cleanly on the 5-site dev fleet spanning Moodle 4.5 / 5.0 / 5.1 / 5.3. All upgrade steps between 2026050945 and 2026061501 are guarded (`index_exists` / `field_exists`), so a partial rerun is safe.

## Why upgrade (what prod is missing today)

1. **Security fix (v6.0.1):** on v5.4.5 through v6.0.0, internal telemetry rows (premium router, rerank, embedding logs) could appear in learner conversation history and in the LLM context. Fixed by filtering history to user and assistant roles. This alone justifies the upgrade.
2. **Kill switch fix (v5.13.0):** `emergency_control --chat` is a silent no op on v5.4.5. If you ever need to stop chat spend in an incident today, the CLI will not actually do it.
3. Spend protections: per course default cap, cost anomaly detector, web emergency panel.
4. Cost optimizations: mastery classifier routed to gpt-4o-mini, prompt cache visibility.
5. 46 language coverage for every admin surface added since v5.10, and selfhosted Whisper STT support (v6.3.0+).
6. New opt-in pedagogy tool: Soapbox speech practice (v6.7.0/v6.7.1) for speech and ESL courses. Off by default; nothing changes for prod unless a course turns it on.

## Behavior changes to be aware of

- **Mastery classifier (v5.11.0):** per turn classification now defaults to routing through `mastery_classifier_provider` = openai / gpt-4o-mini instead of the chat tier. Prod must have a valid OpenAI key configured (it does; gpt-4o-mini is already the failover chat tier). Expected effect is a cost reduction of roughly $220/mo at 100k MAU; quality is unchanged per the v5.11 evaluation.
- **Voice STT resolution (v6.3.0 selfhosted Whisper):** when no selfhosted server URL is configured, behavior is identical to v5.4.5. Leave `stt_selfhosted_url` empty on prod for now.
- **RAG relevance gating (v6.2.0):** retrieval now applies a cosine relevance floor (`rag_min_similarity` 0.25 default) and a small current page bias. Weakly matched chunks are dropped instead of padding the prompt; off topic questions may retrieve fewer or zero passages. This is the intended behavior change and reduces cost and hallucination risk.
- **Voice upload validation (v6.2.2):** real browser recordings (MediaRecorder containers) now pass STT upload validation; previously every real recording got HTTP 415. Only matters if voice is enabled (it is off on prod).
- **Soapbox (v6.7.0/v6.7.1):** adds a learner speech-practice page, a long-form STT endpoint, a `score_speech` web service, a new `speech` rubric type, and the `session_meta` column. All gated behind `soapbox_enabled` (off by default), so there is no learner-facing change until a course enables it. Audio and transcript text are never stored.
- Everything else new ships OFF by default: premium escalation, cost anomaly alerts, rerank, per course default cap, anomaly emails, policy bundle sync, Soapbox.

## New settings appearing after upgrade (all default safe)

| Setting | Default | Recommendation for prod |
|---|---|---|
| `premium_escalation_enabled` | off | Leave off until the escalation budget is approved |
| `cost_anomaly_enabled` | off | Turn ON after verifying alert delivery (below) |
| `cost_anomaly_multiplier` | 2.0 | Keep |
| `spend_cap_per_course_default` | 0 (off) | Set to 30 (USD per month) as on dev |
| `spend_notify_emails` | empty | Set to the ops alias |
| `rerank_enabled` | off | Leave off pending the RAG fixture benchmark GO call |
| `stt_selfhosted_url` | empty | Leave empty until a Whisper server exists in prod infra |
| `mastery_classifier_provider` | openai | Keep |
| `policy_bundle_enabled` | off | Adopt after the dev soak: lets behavior settings update via signed bundle without further Catalyst engagements (`.drafts/sola-upgrade-independence-2026-06-11.md`) |
| `rag_min_similarity` | 0.25 | Keep (v6.2.0 relevance floor) |
| `avatar_animation_enabled` | on | Keep (v6.5.0; preserves prior behavior, adds idle blink). Stage 0 A/B uses per-course `avatar_animation_course_<id>` overrides post-upgrade |
| `soapbox_enabled` | off | Leave off site-wide; enable per course (`soapbox_enabled_course_<id>`) only for the speech/ESL courses that want it |
| `soapbox_stt_mode` | server | Keep. Server uses the configured Whisper provider; only relevant once a course enables Soapbox. Browser mode is free but Chrome/Safari only |

## Deployment steps

Prod deploys are managed by Catalyst (Artem workflow, git submodule), NOT zip upload and NOT the dev AWS path.

1. Confirm v6.7.1 is tagged and the GitHub release is published with green CI.
2. Request Catalyst pin the submodule to tag `v6.7.1` for staging first.
3. On staging after deploy: run `php admin/cli/upgrade.php --non-interactive`, then purge caches, then the 5 minute smoke checklist (`.wiki/Release-Checklist.md`) against BUS101.
4. Schedule the prod window. The DB migration is small (one nullable column for v6.7.x, plus the two v6.x indexes on the messages table). On a messages table in the low millions of rows each index build takes seconds to low minutes; the `session_meta` column add is instant. No maintenance mode required, but a low traffic window is polite.
5. Before the prod deploy: database backup plus a copy of the current plugin directory.
6. Deploy, upgrade, purge caches.
7. Post deploy verification (10 minutes):
   - Plugin version shows 2026061501 / 6.7.1 in Site administration, Plugins overview.
   - Open BUS101 as a test learner: tab present, drawer opens, ask one question, streamed answer arrives, SOLA_NEXT chips render.
   - Conversation history shows no `[PremiumRouter]` or `[Rerank]` style rows (v6.0.1 fix working).
   - Analytics page renders; token analytics page renders.
   - `php admin/cli/emergency_disable.php --status` (or the new web panel at emergency_control.php) shows all flags inactive.
   - Confirm `soapbox_enabled` is off (no Soapbox link in course navigation for a test learner).
8. Within the first week: set `spend_notify_emails`, run `php admin/cli/send_spend_alert_test_email.php`, confirm the email lands, then enable `cost_anomaly_enabled` and set `spend_cap_per_course_default` to 30.

## Rollback plan

- Code: restore the saved v5.4.5 plugin directory, purge caches. Moodle will not complain about the higher recorded version as long as code paths exist; however the clean rollback is code restore PLUS database restore from the pre upgrade backup.
- The v6.x schema additions are additive (nullable `cached_tokens` and `session_meta` columns, two indexes), so v5.4.5 code runs against a v6.7.1 schema without errors if a fast code only rollback is ever needed; the recorded plugin version in config will be ahead, which blocks future upgrades until corrected. Prefer the full restore.

## Open question for Tom

Single decision required: upgrade both Learn and Degrees in one window, or stagger (Degrees first as the smaller blast radius, Learn a few days later). Recommendation: stagger, Degrees first.
