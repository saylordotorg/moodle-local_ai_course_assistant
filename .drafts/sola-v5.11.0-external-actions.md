# SOLA v5.11.0 — External Actions Required (Tom)

**Date:** 2026-06-09. **Status:** unblocked-able by code; needs Tom's hand on vendor portals.

Actions below cannot be performed by code or by SSM from this laptop. They sit in vendor portals (Mistral, Voyage, Anthropic, OpenAI, Google) and require Tom's authenticated session.

---

## Opt #7: Verify Mistral training opt-out + ZDR

**Why:** Mistral La Plateforme defaults to using submitted data for training improvement. SOLA's failover lane sends student chat content to Mistral when the primary chat tier is down. Without explicit opt-out, that traffic is being used to train Mistral's models. Combined with the legal posture (Saylor is tuition-free; SOC 2 + GDPR + no-training-by-default are the actual constraints per `project_saylor_free_university`), this is a procurement TIER 1 disqualifier.

**Action:**

1. Log in to `console.mistral.ai`.
2. Navigate to Account > Workspace > Privacy or Account > Settings > Data usage.
3. Confirm "Use my data to improve Mistral models" is **off** on the SOLA workspace.
4. Request the Mistral DPA in writing if not already on file; confirm it includes a no-training-by-default clause.
5. Request Zero Data Retention (ZDR) — Mistral enterprise tier; requires sales contact.
6. After confirmation: tick the rev. 3 multi-provider optimization plan item ("Verify Mistral training opt-out + ZDR before EU traffic").

**Until done:** keep Mistral out of the production failover chain. Currently the chain is empty on dev, so no immediate exposure. When the chain is populated per the vendor doc spec (`chat:openai → chat:mistral`), this must be done first.

---

## Opt #8: Negotiate vendor tier-ups

Per section 5 of `sola-vendor-recommendations-2026-06-09.md`. 2–4 week procurement window before 100k MAU promotion.

| Vendor | Current tier | Target | Approx volume to justify | Contact |
|---|---|---|---|---|
| Google Vertex AI | unknown | **Tier 3+** (5k+ RPM) | ~58k chat turns/day at 100k MAU | sales@google.com or GCP account rep |
| OpenAI | unknown | **Tier 4** (10k+ RPM) | ~25k structured calls/day (quiz + classifier + analytics + failover) | platform.openai.com → Settings → Limits → request increase |
| Anthropic | unknown | **Tier 3** | ~3k integrity calls/day (once integrity lane built; today: minimal) | console.anthropic.com → Settings → Limits |
| Voyage AI | none (need to onboard) | **Enterprise** | ~125k reranks/day at 100k MAU | sales@voyageai.com |

**For each:** request the rate-card in writing, SOC 2 Type II report, GDPR DPA with no-training clause, EU residency confirmation where applicable.

**Voyage is the new vendor relationship** — opening this in parallel with the v5.11.0 code rollout. Recommend: get the trial API key today so the migration in section 2 of this doc can be tested on dev within a week.

---

## Side findings (in scope for separate follow-up, not v5.11.0)

### Dev failover chain is empty + failover_per_call_enabled=0

Probed via SSM 2026-06-09. Currently:

- `spend_failover_chain` = empty
- `failover_per_call_enabled` = 0
- Primary chat provider on dev: `openai` (`gpt-4o-mini`), NOT Gemini

Per `sola-vendor-recommendations-2026-06-09.md` section 3, the recommended config is:

```
spend_failover_chain:
  chat:openai
  chat:mistral
failover_per_call_enabled: 1
```

And primary chat should be Gemini 2.5 Flash on Vertex AI (not OpenAI gpt-4o-mini). The primary-flip is a separate behavioural change — flagging here so it isn't missed but it needs its own ticket + smoke pass.

### Voyage onboarding sequence (suggested)

1. Tom signs up at voyageai.com → grabs trial API key (~$5 free credit).
2. Set on dev: `embed_apikey=<voyage key>`, `embed_provider=voyage`, `embed_model=voyage-3.5`, `embed_dimensions=1024`.
3. Run `rag_admin.php` reindex on a single test course (BUS101).
4. Confirm chat quality on a 5-question RAG smoke.
5. Set `rerank_enabled=1`, `rerank_apikey=<voyage key>` (or leave blank to reuse embed_apikey).
6. Re-run BUS101 smoke; confirm rerank flag in chunk telemetry.
7. Full corpus reindex ($4.80 one-time).
8. Open Voyage enterprise contract for the production rollout.
