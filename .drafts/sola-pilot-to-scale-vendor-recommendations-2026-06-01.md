# SOLA Vendor and Tier Recommendations: Pilot → 50k → 100k Saylor MAU

**Author:** Tom Caswell. **Date:** 2026-06-01. **Last revised:** 2026-06-04 (scope cut to text-only baseline; audio, voice, and avatars moved to Appendix B; projections re-baselined to 25% SOLA adoption; per-component analysis moved to Appendix A). **Status:** Ready for finance, procurement, and ops review.

Concrete vendor and tier recommendations for every SOLA component in scope for a universal Saylor rollout: chat tutor, quiz coach, integrity reference, mastery classifier, analytics, embeddings + re-ranker, judge harness. TTS, STT, the Voice tab, and talking avatars are out of the baseline; their pricing is in Appendix B (Future Directions). Per-component rationale, benchmark scores, and failover details are in Appendix A.

**Scope assumption.** All cost projections assume 25% of Saylor's monthly active learners use SOLA in a given month. A "100k MAU" scale point therefore means 100k Saylor MAU with ~25k active SOLA users.

---

## 1. Executive Summary

**Scope decision (2026-06-04).** Universal-rollout baseline is text-only. The Voice tab (realtime full-duplex voice), TTS, STT, and talking avatars are not included in the actual estimates or the procurement and operational checklist. They are captured in Appendix B with vendor analysis and pricing for a future opt-in (per-course, per-cohort, or a premium tier).

**Baseline stack.**

- **Core chat tutor:** Gemini 2.5 Flash (Vertex AI), with OpenAI gpt-4o-mini as the first failover and Mistral Small as ultimate fallback.
- **Specialty routing:** Claude Haiku 4.5 for anti-cheat / integrity reference on the ~5% of turns flagged as suspected-cheat; OpenAI gpt-4o-mini for quiz coach, mastery classifier, and analytics / weekly digests.
- **RAG:** Voyage-3.5 embeddings + Voyage rerank-2.5 two-stage retrieval.
- **Judge:** Claude Sonnet 4.6 in the weekly benchmark harness.

**Cost at scale (text-only baseline, 25% SOLA adoption).**

| Scale point | Saylor MAU | SOLA users (25%) | Monthly cost | vs current stack |
|---|---|---|---|---|
| 30 course pilot at full enrollment | ~24,000 | ~6,000 | ~$330 | ~30% cheaper |
| 50k MAU | 50,000 | 12,500 | ~$690 | ~35% cheaper |
| 100k MAU | 100,000 | 25,000 | **~$1,370** | **~35% cheaper** |

**Compliance.** Every selected vendor is verified TIER 1 (SOC 2 Type II + GDPR DPA + no training by default). FERPA is not in scope; Saylor is a tuition-free university. Posture matches `sola-multi-provider-optimization-plan.md` rev. 2.

**Why this scope.** Voice and avatar features serve a minority of learners but drive disproportionate variable cost at scale. At 100k Saylor MAU (25k SOLA users) the audio stack would add ~$820/month and the optional talking avatar tier would add another ~$1,200/month at 5% adoption of the SOLA user base. Cutting them from the baseline keeps the text tutor universally affordable across all courses without making per-course economics depend on a feature the median learner does not use.

**Decision needed.** Sign-off on the text-only baseline and the projected ~$1.4k/month at 100k Saylor MAU. Audio and avatar tiers can be re-evaluated post-rollout if learner engagement data warrants a separate procurement track.

---

## 2. TL;DR — vendor picks (baseline)

| Component | Recommended primary | First failover | Why (one line) |
|---|---|---|---|
| Core chat tutor (text) | **Gemini 2.5 Flash** (Vertex AI) | OpenAI gpt-4o-mini | Wins every domain × function bucket on 2026-06-03/04 benchmarks |
| Quiz coach | **OpenAI gpt-4o-mini** | Mistral Small | Function saturated at ~14/15; spend the least |
| Anti-cheat / integrity reference | **Claude Haiku 4.5** | Gemini 2.5 Flash | Best at refusing graded work; budget Llamas cave |
| ESL / multilingual chat | **Gemini 2.5 Flash** | OpenAI gpt-4o-mini | Decisive multilingual lead (14.50/15) |
| Mastery classifier | **OpenAI gpt-4o-mini** | Mistral Small | 1/40th the chat-tier price for a structured-output task |
| Analytics + weekly digests | **OpenAI gpt-4o-mini** (keep) | Gemini 2.5 Flash | Batch summarization, structured output, cheap |
| Embeddings (RAG) | **Voyage-3.5** | OpenAI text-embedding-3-small | 4x input context, beats OpenAI on retrieval |
| RAG re-ranker | **Voyage rerank-2.5** | Cohere Rerank 3.5 | 40x cheaper than Cohere |
| Judge / benchmark harness | **Claude Sonnet 4.6** (keep) | Gemini 2.5 Pro | Independent of contestants; prevents self-bias |

**Out of baseline (see Appendix B):** TTS, STT, realtime voice (Voice tab), talking avatars.

---

## 3. Scale assumptions and what drives cost

The 2-month pilot has 2,866 active SOLA learners (observed). All forward projections assume **25% of Saylor MAU uses SOLA in a given month**:

| Scale point | Saylor MAU | Active SOLA learners (25%) |
|---|---|---|
| Pilot (now, observed) | ~11,500 implied | 2,866 |
| 30 course pilot at full enrollment | ~24,000 | ~6,000 |
| 50k MAU (mid scale) | 50,000 | 12,500 |
| 100k MAU (full Saylor) | 100,000 | 25,000 |

**Chat is the cost driver.** Cost scales with active SOLA users, not Saylor MAU. At 100k Saylor MAU (25k SOLA users) each $0.01 per SOLA learner per month is $250/month; the 4-10x cost gap between candidate chat models is a five-figure annual difference. Per-learner blended chat rates from `sola-llm-capacity-planning-pilot.md`: Gemini 2.5 Flash ~$0.042/SOLA-learner/mo, Together Llama 3.1 8B Lite $0.040, OpenAI gpt-4o-mini $0.070, Mistral Small 3 $0.089, Claude 3.5 Haiku $0.390. The supporting components (embeddings, re-ranker, classifier, analytics, integrity, judge) together add ~$320/month at 100k Saylor MAU — real but dwarfed by chat.

**Sensitivity.** If actual SOLA adoption runs at 35%, multiply every cost by 1.4. At 50% adoption, multiply by 2. Track monthly and refresh projections if adoption drifts more than 5 percentage points.

**What the scale math does NOT capture.** Heavy users (top 10%) typically drive 50–70% of token volume; a viral ELL course can spike traffic 3–5x in days. First 2–3 weeks at any new course tier typically run 2–3x steady state. Anthropic prompt-cache discount (90% off cached prefix reads) and OpenAI auto-prefix discount (50%) materially shrink chat spend at scale; Gemini does not currently expose a prefix cache discount.

---

## 4. Consolidated cost projection (25% adoption, baseline)

| Component | 30 course pilot (24k MAU / 6k SOLA users) | 50k MAU (12.5k SOLA users) | 100k MAU (25k SOLA users) |
|---|---|---|---|
| Chat tutor (Gemini 2.5 Flash, incl. ESL multiplier) | $250 | $525 | $1,050 |
| Quiz coach (gpt-4o-mini) | $20 | $43 | $85 |
| Anti-cheat reference (Claude Haiku 4.5, ~5% of turns) | $8 | $16 | $33 |
| Mastery classifier (gpt-4o-mini) | $25 | $53 | $105 |
| Analytics + digests + Radar (gpt-4o-mini) | $4 | $15 | $30 |
| Embeddings (Voyage-3.5) | <$1 | <$1 | <$1 |
| Re-ranker (Voyage rerank-2.5) | $15 | $31 | $63 |
| Judge / benchmark harness ($1/week) | $4 | $4 | $4 |
| **Baseline total (text only)** | **~$330** | **~$690** | **~$1,370** |

**Comparison to current text-only stack** (text-embedding-3-small + Gemini 1.5 Flash chat + gpt-4o-mini analytics): the recommended baseline saves ~35% at scale ($730/month at 100k Saylor MAU). The saving comes from specialty routing (gpt-4o-mini on quiz / classifier / analytics, Haiku only on suspected-cheat turns), Voyage-3.5 embeddings + rerank-2.5, and a cleaner failover chain that prevents accidental Claude-as-fallback burn.

**Future Directions add-ons (not in baseline).** See Appendix B for vendor analysis. At 100k Saylor MAU under 25% SOLA adoption: TTS ~$500/mo, STT ~$160/mo, realtime voice ~$175/mo, talking avatar at 5% of SOLA users ~$1,200/mo. Add-on subtotal if all turned on: ~$2,035/mo (about 1.5x the baseline).

---

## 5. Multi-region and redundancy

At 100k Saylor MAU with EU and APAC learners, single-region single-vendor is not acceptable.

- **Primary: us-east-1** — Vertex AI us-east1 + OpenAI default + Voyage default.
- **EU residency: eu-west1** — Vertex AI eu-west1 for chat; OpenAI EU residency when shipped; Mistral La Plateforme as the EU-resident ultimate-fallback chat lane.
- **APAC residency: ap-southeast-1** — Vertex AI Singapore.

**Site-level failover chain** (already implemented via the `spend_failover_chain` admin setting):

1. Primary Gemini 2.5 Flash on Vertex AI (US-East).
2. On 429 or 5xx: OpenAI gpt-4o-mini (US-East).
3. On secondary failure: Mistral Small (EU).
4. On tertiary failure: serve a degraded "SOLA briefly unavailable" message rather than burn through Claude Haiku at 1.9x cost as a safety net.

**Capacity tiers to negotiate at 100k Saylor MAU (25k SOLA users):**

| Vendor | Component | Approx requests / day | Required tier |
|---|---|---|---|
| Vertex AI (Gemini Flash) | chat | ~58k chat turns / day | Tier 3+ (5k+ RPM) |
| OpenAI (gpt-4o-mini) | quiz + classifier + analytics + failover | ~25k structured calls / day | Tier 4 (10k+ RPM) |
| Anthropic (Haiku 4.5) | integrity reference | ~3k calls / day | Tier 3 |
| Voyage (embeddings + rerank-2.5) | RAG index + runtime | ~125k reranks / day | Pay-as-you-go fine |

Negotiate Vertex AI and OpenAI tier upgrades 2–4 weeks before promotion.

---

## 6. Procurement and operational checklist (baseline only)

A future audio or avatar opt-in (Appendix B) would re-open vendor selection and procurement for the relevant subsection.

### Procurement (before 50k Saylor MAU rollout)

- [ ] **Google Vertex AI** — enterprise DPA, EU + US + APAC regions enabled. Confirm no-training-by-default clause explicitly in the contract.
- [ ] **OpenAI** — Tier 4 organization, BAA optional (no PII processing), confirm no-training-on-API-data clause.
- [ ] **Anthropic** — Tier 3 organization, DPA, confirm no-training-by-default.
- [ ] **Voyage AI** — enterprise tier, DPA with SOC 2 Type II + GDPR + no-training-by-default in writing.
- [ ] **Mistral La Plateforme** — enterprise account for the EU-resident ultimate-fallback chat lane; DPA + no-training-by-default.

### Operational

- [ ] Per-component spend caps in SOLA admin (assumes 25% SOLA adoption):
  - chat: $800 / month at 50k Saylor MAU, $1.6k at 100k Saylor MAU (1.5x headroom)
  - quiz / classifier / analytics (gpt-4o-mini): $150 / month at 50k, $300 at 100k
  - integrity reference (Claude Haiku 4.5): $75 / month at 50k, $150 at 100k
  - RAG re-ranker (Voyage): $75 / month at 50k, $150 at 100k
- [ ] Per-course spend caps for high-volume courses (ELL especially): $250 / month soft cap with admin alert.
- [ ] Failover chain populated in admin: primary + 2 fallbacks per baseline component.
- [ ] Prompt-debug log on for the first 30 days post-promotion.
- [ ] Weekly benchmark CLI re-run to track quality drift.
- [ ] Cost dashboard in Redash extended to per-component breakdown.
- [ ] Confirm Voice tab is disabled in rollout build (`realtimeenabled` off; header voice button hidden); confirm `tts.php` and STT entry points are not surfaced in the learner UI.

### Compliance and DPO

- [ ] Update `sola-privacy-notice-learner-facing.md` with the final baseline vendor list (no audio vendors).
- [ ] Update `sola-multi-provider-optimization-plan.md` rate cards and tier classifications to align with this document.
- [ ] DPO sign-off on the multi-vendor data flow diagram; every baseline vendor is TIER 1 SOC 2 + GDPR.

---

## 7. Risks

1. **Gemini outage during EU evening.** Per-region failover (Vertex US-East → OpenAI gpt-4o-mini US-East). Existing `spend_failover_chain` handles this with no code change.
2. **Voyage AI is a smaller vendor.** Keep OpenAI text-embedding-3-small as a one-flag-flip fallback; full re-index costs $1.60.
3. **Gemini 2.5 Flash quality regression.** Weekly benchmark CLI catches this within 7 days; failover to gpt-4o-mini as chat tier until a Gemini version pins better.
4. **Voyage acquisition** (sub-100-employee startup). Second picks: OpenAI 3-small (embeddings) and Cohere Rerank 3.5. Transition: one config setting + one-time re-index.
5. **Multi-region cost surprise.** EU Vertex AI is ~10–15% more expensive than US-East. Budget accordingly.
6. **Anthropic rate-limit constraints on Haiku 4.5** during exam-window traffic. Per-course Haiku spend cap; failover the integrity lane to Gemini 2.5 Flash (14.30/15 on anti-cheat) on 429.
7. **ESL chat multiplier higher than 3.5x** for a viral ELL cohort. Per-course soft cap with admin alert; temporary cap raises on request.
8. **Actual SOLA adoption higher than 25%.** All costs scale linearly with adoption rate. At 50% adoption double the figures; at 35% multiply by 1.4. Refresh projections quarterly.
9. **Learner demand for voice / avatar features post-rollout.** Opt-in path is in Appendix B; decision lever is a single-course A/B test on BUS101 before any wider enable.

---

## 8. Single-page decision summary

> **Decision.** Adopt the following baseline (text-only) stack for SOLA at pilot through 100k Saylor MAU (assumes 25% SOLA adoption ≈ 25k active SOLA users at full Saylor scale).
>
> **Chat tutor:** Gemini 2.5 Flash (Vertex AI) primary, OpenAI gpt-4o-mini failover, Mistral Small ultimate fallback.
>
> **Specialty routing:** Claude Haiku 4.5 for anti-cheat refusal (~5% of turns), gpt-4o-mini for quiz coach + mastery classifier + analytics. Wired through existing `comparison_providers` + `spend_failover_chain` admin settings.
>
> **RAG:** Voyage-3.5 embeddings ($0.06/MTok, 32k context, multilingual) + Voyage rerank-2.5 (~$63/month at 100k Saylor MAU).
>
> **Judge:** Claude Sonnet 4.6 in the weekly benchmark harness (~$1/week).
>
> **Out of scope:** Voice tab, TTS, STT, talking avatars. Appendix B keeps the vendor and pricing analysis for a future opt-in.
>
> **Compliance:** Every baseline vendor is TIER 1 (SOC 2 Type II + GDPR DPA + no training by default). FERPA out of scope; Saylor is a tuition-free university.
>
> **Cost at scale.** ~$1.4k/month at 100k Saylor MAU. ~35% cheaper than the current text-only stack at the same scale.

---

## 9. Next steps

1. **Review this document with finance + procurement + ops + DPO.** Get sign-off on the baseline vendor list and the ~$1.4k/month at 100k Saylor MAU number.
2. **Promote to dev:** populate per-component admin settings with the recommended baseline; confirm BUS101 smoke passes.
3. **Wire two-stage RAG retrieval:** add Voyage rerank-2.5 to `sse.php` between embedding retrieval and chat. Half-day work.
4. **Migrate embeddings to Voyage-3.5:** one-time re-index of the 161-course corpus (~$4.80) + config update in `sse.php` and `rag_admin.php`. Half-day work.
5. **Confirm Voice tab is disabled in the rollout build.** Remove TTS / STT entry points from the learner UI for the universal-rollout cohort.
6. **Negotiate vendor tier upgrades** for the baseline stack (Vertex AI Tier 3+, OpenAI Tier 4, Anthropic Tier 3, Voyage enterprise). 2–4 week procurement window.
7. **Run the deferred premium-escalation-tier bake-off** (Appendix A.10, ~half day, ~$5 in API spend). Decides whether SOLA needs a premium tier.
8. **Defer audio / voice / avatar opt-in.** Revisit per Appendix B only if post-rollout learner research surfaces clear demand.
9. **Refresh `sola-multi-provider-optimization-plan.md`** to align rate cards, tier classifications, and the failover chain spec with the baseline decisions in this document.

---

# Appendix A — Per-component baseline recommendations

The subsections below contain the full benchmark evidence, cost rationale, and failover details behind each baseline pick. Read these if you are evaluating a specific vendor decision; the main body is sufficient for sign-off.

---

## A.1 Core chat tutor (text)

### Recommendation: Gemini 2.5 Flash (Vertex AI), with gpt-4o-mini as first failover

This is the load-bearing decision in the whole stack. The 2026-06-04 domain benchmark showed plainly: **Gemini 2.5 Flash wins all five subject domains and ranks at or near the top in all five function categories (Socratic, quiz, illustration, anti-cheat, multilingual).** No other provider wins a single domain outright; Claude is consistent runner-up except in CS and humanities; OpenAI and Mistral hold the value middle; the Llamas trail.

### Why Gemini 2.5 Flash and not another premium pick

- **Quality:** 14.35/15 overall on the domain benchmark, the only model above 14 on every subject. On the function benchmark it ties Claude on multilingual (14.50/15, decisive) and is within 0.4 points of xAI/Claude on every other function.
- **Cost:** $0.060 cents per call on the SOLA prompt shape (rate-card verified). Roughly half of Claude Haiku 4.5 ($0.111) and Anthropic Sonnet 4.6 ($0.30+).
- **Per-learner blended at scale:** $0.042 per SOLA learner per month, putting 100k Saylor MAU (25k SOLA users) chat at ~$1,050. Claude Haiku at the same scale costs ~$9,750.
- **Compliance:** Google Vertex AI is TIER 1 (verified SOC 2 Type II + GDPR DPA + no training by default), confirmed in `sola-multi-provider-optimization-plan.md` section 2.
- **Multi-region:** Vertex AI supports EU residency (eu-west1 + eu-west4). OpenAI today does not.

### Why not Claude as the primary

Claude Haiku 4.5 was the runner-up in every benchmark dimension but at 1.9x Gemini's per-call cost and 4x per-token cost. At 100k Saylor MAU (25k SOLA users) that gap is ~$8.7k per month for a quality difference under 0.5 points on a 15-point rubric. It earns its spot as the integrity reference (A.3) where the small quality edge on refusal discipline matters.

### Why not Sonnet 4.6 or Opus 4.x

These are a tier up, not a tier across. Sonnet 4.6 is the judge model in the bake-off, so it cannot also be a contestant without self-bias. Opus 4.x at $15/$75 per MTok is 50x Gemini Flash per token; at 100k Saylor MAU it would cost $50k+ per month for the core chat tutor — fiction, not procurement. If a dedicated "premium escalation" tier is warranted for the hardest tutoring prompts, the right pattern is to **route at the turn level** to Sonnet 4.6 based on a small set of explicit triggers — see A.10.

### Failover chain

Primary `google/gemini-2.5-flash` (Vertex AI) → fallback `openai/gpt-4o-mini` → ultimate fallback `mistral/mistral-small-latest`. SOLA's existing `spend_failover_chain` already handles this shape.

### Cost projection (25% SOLA adoption)

| Scale | Saylor MAU | SOLA users | Per-learner blended | Monthly chat spend |
|---|---|---|---|---|
| 30 course pilot at full enrollment | ~24,000 | ~6,000 | $0.042 | $250 |
| 50k MAU | 50,000 | 12,500 | $0.042 | $525 |
| 100k MAU | 100,000 | 25,000 | $0.042 | $1,050 |

---

## A.2 Quiz coach

### Recommendation: OpenAI gpt-4o-mini, with Mistral Small as failover

The quiz coaching function is saturated on the benchmark. Every provider except the two budget Llamas and Qwen scored between 14.0 and 14.7 out of 15. Even OpenRouter's Llama 3.1 8B hit 14.33. Quality is essentially uniform across the cheap-to-premium band, so the right choice is the cheapest TIER 1 provider that still scores high.

| Provider | Quiz coach score /15 | Per-call cost (cents) |
|---|---|---|
| Claude Haiku 4.5 | 14.70 | 0.111 |
| Gemini 2.5 Flash | 14.50 | 0.060 |
| xAI grok-4.3 | 14.40 | 0.063 |
| OpenRouter Llama 3.1 8B | 14.33 | 0.0015 |
| Mistral Small | 14.20 | 0.016 |
| **OpenAI gpt-4o-mini** | **14.00** | **0.012** |

OpenRouter at 0.0015 cents is the absolute floor, but it sits at TIER 3 (downstream training disclaimer); disqualifying for student-facing traffic at scale. **OpenAI gpt-4o-mini at TIER 1 + 14.00/15 is the right pick.** Mistral Small is the failover (slightly better quality at slightly higher cost).

**Cost projection.** 4 quizzes per SOLA learner per month × ~2,500 in / 600 out tokens × 25k SOLA users × ($0.15 + 4 × $0.60) / 1M ≈ **$85 / month at 100k Saylor MAU**.

---

## A.3 Anti-cheat / integrity reference

### Recommendation: Claude Haiku 4.5 on suspected-cheat turns; primary chat handles >95% of turns

On the function benchmark, Claude Haiku 4.5 leads at 14.60/15 for anti-cheat; Gemini Flash is close at 14.30/15. The cheap Llamas are notably weak at 10.30–11.50: they cave and supply graded answers when pressed. This is the function where model choice has the biggest reputational impact (a student gets a graded answer from SOLA; the instructor finds out; the press finds out).

**Integrity-aware routing.** SOLA's existing anti-cheat detection logic flags a suspected-cheat turn; the response for that turn is generated by Claude Haiku 4.5 instead of the primary Gemini Flash. Suspected-cheat turns are ~2–5% of total volume, so the cost premium of Claude on those turns is negligible at scale.

Failover: Gemini 2.5 Flash (still 14.30 — just shy of Claude). Avoid routing this lane to any Llama.

**Cost projection.** 5% of chat turns × ~5,000 in / 200 out tokens × 25k SOLA users × ($1.00 + 0.005 × $5.00) / 1M ≈ **$33 / month at 100k Saylor MAU**. Trivially cheap given the volume share.

---

## A.4 ESL / multilingual chat

### Recommendation: Gemini 2.5 Flash (same as primary), with gpt-4o-mini failover

ESL is ~30% of the SOLA pilot mix; the ESL learner runs 3.5x the chat volume of a typical learner (140 vs 40 messages/month per `sola-llm-capacity-planning-pilot.md`). Both quality and cost concern.

**Quality.** Gemini Flash wins multilingual outright at 14.50/15, ahead of a Claude / xAI / OpenAI tier near 13.7. Budget Llamas are 9.3–10.0 — disqualifying for any course with meaningful ESL enrollment.

**Cost.** ESL learner runs at ~$0.148/month on Gemini. At 100k Saylor MAU (25% SOLA adoption = 25k SOLA users), 30% ESL share = 7,500 ESL SOLA users × $0.148 = **$1,110/month for ESL chat alone**. Typical-learner chat: 17,500 × $0.042 = $735/month. **Blended chat = ~$1,845/month** at 100k Saylor MAU (slightly higher than the headline $1,050 because the ESL multiplier outweighs the lower typical-learner share). Use $1.85k as the working number for chat-only.

---

## A.5 Mastery classifier (adhoc task)

### Recommendation: OpenAI gpt-4o-mini, with Mistral Small as failover

SOLA's `classify_conversation_turn` adhoc task runs once per chat turn. It is a structured-output task: given (user message, assistant message, course objectives list), return JSON `{ objective_id, evidence_level, struggle_flag }`. Token shape is small (~500 in / 50 out).

The primary chat model is the wrong tool: the classifier doesn't need Socratic guidance, multilingual fluency, or tone match; it needs reliable structured output and the cheapest token rate that can hit format compliance. **gpt-4o-mini at $0.15/$0.60 per MTok with native JSON mode is the pareto pick.** Mistral Small at $0.20/$0.60 is the failover. Both enforce JSON schema natively; both are TIER 1.

**Why moving off the primary chat model matters at scale.** At 100k Saylor MAU (25k SOLA users), classifier calls = chat turns ≈ 1.75M calls/month. On Gemini Flash that's ~$325/mo; on gpt-4o-mini it's ~$105/mo — $220/mo saving for a single config change.

**Cost projection.**

| Scale | Saylor MAU | SOLA users | Monthly classifier spend |
|---|---|---|---|
| 30 course pilot at full enrollment | ~24,000 | ~6,000 | $25 |
| 50k MAU | 50,000 | 12,500 | $53 |
| 100k MAU | 100,000 | 25,000 | $105 |

---

## A.6 Analytics, weekly digests, Learning Radar

### Recommendation: OpenAI gpt-4o-mini (keep), with Gemini Flash as failover

Analytics outputs: instructor weekly digest (per instructor per course), learner weekly digest (per active SOLA learner), Learning Radar scheduled queries (8 queries × 4 weeks). All batch-mode, structured-output, summarization-shaped, no latency requirement. Per-call token shape: instructor digest ~5,000 in / 800 out; learner digest ~1,500 in / 200 out; Learning Radar query ~6,000 in / 1,000 out.

**gpt-4o-mini stays.** Canonical choice for batch summarization, structured output, low cost. SOLA's existing analytics pipeline has 6+ months of production-quality output.

**Cost projection** (learner digests scale with SOLA users; instructor digests and Learning Radar are fixed):

| Scale | Saylor MAU | SOLA users | Instructor digests | Learner digests | Learning Radar | **Monthly total** |
|---|---|---|---|---|---|---|
| 30 course pilot at full enrollment | ~24,000 | ~6,000 | $0.16 | $3.20 | $0.36 | ~$4 |
| 50k MAU | 50,000 | 12,500 | $1 | $14 | $0.36 | ~$15 |
| 100k MAU | 100,000 | 25,000 | $1 | $28 | $0.36 | ~$30 |

---

## A.7 Embeddings (RAG retrieval)

### Recommendation: Migrate to Voyage-3.5

Today: OpenAI `text-embedding-3-small`, 1,536 dimensions, 8,191-token input limit, $0.02 per MTok. Fine but trailing the frontier on retrieval recall and not the strongest pick for non-English.

| Model | MTEB English avg | $/1M tokens | Dims | Max input | Position |
|---|---|---|---|---|---|
| Gemini Embedding 001 | 68.32 | $0.15 ($0.075 batch) | 3,072 (MRL→768) | 2,048 | quality ceiling |
| Voyage-3.1-large | 67.40 | $0.18 | 1,024 (MRL→256) | 32,000 | retrieval ceiling |
| **Voyage-3.5** | **~66** | **$0.06** | **1,024 (MRL→256)** | **32,000** | **value pick** |
| Cohere Embed v4 | 65.20 | ~$0.12 | 256/512/1024/1536 | 128,000 | long context spec |
| OpenAI text-embedding-3-large | 64.60 | $0.13 | 3,072 (MRL→256) | 8,191 | OpenAI ceiling |
| Voyage-3.5-lite | ~63 | $0.02 | 1,024 (MRL→256) | 32,000 | cheap floor |
| OpenAI text-embedding-3-small | ~62 | $0.02 | 1,536 | 8,191 | current SOLA |

### Why Voyage-3.5

- **Better retrieval** than OpenAI 3-small (+~4 points MTEB) and meaningfully better on multilingual (Voyage trains multilingual from scratch; OpenAI 3-small is English-leaning).
- **4x the context window** (32k vs 8k). SOLA's course content includes long PDFs and full lecture transcripts.
- **3x the price** ($0.06 vs $0.02) but absolute spend is trivial. Full SOLA corpus = ~161 courses × ~500k tokens ≈ 80M tokens; re-embedding the whole library on Voyage-3.5 costs $4.80 once.
- **TIER 1 compliance.** Voyage offers SOC 2 Type II + GDPR DPA at the enterprise tier. Verify no-training-by-default in the DPA.

**Multilingual MTEB winners.** Open-weight **Qwen3-Embedding-8B** leads MMTEB multilingual at 70.58; NVIDIA Llama-Embed-Nemotron-8B is a close challenger. Both Apache 2.0, 100+ languages. If Voyage-3.5 multilingual recall proves weak on non-English course materials, upgrade path is self-hosted Qwen3-Embedding-8B on a g5.xlarge (~$735/mo all-in). Cost-justifies above ~12M tokens/mo of embedding work; SOLA is well below that.

**Cost projection.** Scales with the course corpus indexed, not active learners.

| Scale | One-time re-index | Quarterly maintenance | Monthly cost |
|---|---|---|---|
| 30 course pilot | $1 (one course) | ~$0 | ~$0 |
| 50k Saylor MAU (~80 courses indexed) | $2.40 once | $1 | <$1 |
| 100k Saylor MAU (all 161 courses) | $4.80 once | $2 | <$1 |

Migration: one config change in `sse.php` + `rag_admin.php` + a one-time re-index.

---

## A.8 RAG re-ranker

### Recommendation: Add Voyage rerank-2.5

SOLA currently does single-stage retrieval (embedding cosine top-k=5). The dominant pattern for production RAG in 2026 is **two-stage retrieval**: top-50 with embeddings, rerank to top-5 with a cross-encoder. Published lifts include +15 Recall@10 on enterprise corpora and +39% NDCG on BEIR over BM25 baselines (Pinecone, aimultiple). Educational content has many near-duplicate phrasings; cross-encoders outperform pure cosine.

| Re-ranker | $/1M tokens | Source |
|---|---|---|
| **Voyage rerank-2.5** | **$0.05** | docs.voyageai.com/docs/pricing |
| Cohere Rerank 3.5 | $2.00 | aipricing.guru/cohere-pricing |

**Voyage rerank-2.5 is 40x cheaper than Cohere for near-equivalent quality.**

**Cost projection.** 5 RAG-backed chat turns per SOLA learner per month × 50 chunks reranked × ~200 tokens = 10k tokens per rerank operation. Scales with SOLA users.

| Scale | Saylor MAU | SOLA users | Monthly rerank spend |
|---|---|---|---|
| 30 course pilot at full enrollment | ~24,000 | ~6,000 | $15 |
| 50k MAU | 50,000 | 12,500 | $31 |
| 100k MAU | 100,000 | 25,000 | $63 |

Even at $63/month, the recall lift materially improves answer quality, and the spend is ~6% of the chat spend it accompanies. Net positive.

---

## A.9 Judge / benchmark harness

### Recommendation: Claude Sonnet 4.6 (keep), with Gemini 2.5 Pro as failover

The judge model in the benchmark harness scores every contestant's responses on Socratic guidance, factual accuracy, and tone. To prevent self-preference bias, the judge must be a model that is NOT in the contestant pool.

Claude Sonnet 4.6 is currently the judge. The 2026-06-03 benchmark explicitly notes this constraint and observes that Claude did not win the benchmark (Gemini did), which argues against strong self-bias from the judge. **Keep Sonnet 4.6.** If Sonnet ever needs to be added to the contestant pool (e.g. for the A.10 premium-escalation-tier bake-off), the judge should swap to Gemini 2.5 Pro or to a 2-judge ensemble (Sonnet 4.6 + Gemini 2.5 Pro, average rubric scores).

Cost: ~$1 per weekly run. Negligible.

---

## A.10 Premium escalation tier (deferred follow-up)

The text chat benchmark covers the fast-workhorse tier (Gemini Flash, Haiku 4.5, gpt-4o-mini, Mistral Small) which is the right pick for 90+% of normal tutoring traffic. It does NOT cover a **premium escalation tier** for the hardest prompts (multi-step calculus, advanced CS proof reading, deep philosophy, complex worked examples where the cheap model visibly fails).

**Proposed follow-up bake-off.**

- **Candidate set:** Claude Sonnet 4.6, Claude Opus 4.7, Gemini 2.5 Pro, OpenAI gpt-4o (full).
- **Judge:** ensemble of Gemini 2.5 Pro + a Saylor instructor for a human spot check on a 20-prompt subsample.
- **Prompt set:** ~20 "hard" prompts hand-picked from the existing 50 (lowest mean score on the 2026-06-03 run) plus 20 fresh prompts designed to stress multi-step reasoning in math, CS, and science.
- **Cost:** ~$5 in API spend (40 prompts × 4 providers × ~$0.03 per call).
- **Decision output:** which model to use as the escalation tier and what routing triggers wire it in (course tag, learner-initiated "explain in more depth", prior-turn dissatisfaction signal).

Half-day of work. Recommended before any production decision on whether SOLA should have a premium tier at all.

---

# Appendix B — Future Directions: Audio, Voice, and Avatars (not in baseline)

The four subsections below are **not** part of the universal-rollout baseline. They are kept as reference material with pricing estimates so that any future opt-in decision (per-course, per-cohort, or a premium tier) can move quickly without re-doing the vendor analysis. None of the numbers in B.1–B.4 feed into the consolidated cost projection (section 4), the procurement checklist (section 6), or the single-page decision summary (section 8).

At 100k Saylor MAU (25% SOLA adoption = 25k SOLA users), the audio stack (B.1 + B.2 + B.3) would add roughly **$820 per month** if turned on; the talking avatar tier (B.4) would add roughly **$1,200 per month** at 5% adoption of the SOLA user base.

---

## B.1 TTS (text to speech)

### Recommendation: Three-tier routing (if ever opted in)

| Tier | Vendor | When to use | $/1M chars |
|---|---|---|---|
| **Default** | Google WaveNet | English + 30 mainstream langs, short tutor responses | $4 |
| **Premium** | ElevenLabs Flash v2.5 | ELL pronunciation, voice mode, hero use cases | $50 |
| **African native** | Azure Standard Neural | am, so, sw, zu | $15 |
| **Fallback** | Browser Speech API | wo, bm, om (uncovered commercially) | $0 |

Today SOLA defaults to OpenAI tts-1 with a browser fallback. tts-1 sits at $15/MTok with mid-pack quality (Speech Arena Elo ~1100, below WaveNet, ElevenLabs Flash, Cartesia Sonic, Gemini TTS, Azure HD). Not the cheapest, not the highest quality, not the broadest in language coverage.

**Why this routing.** Google WaveNet at $4/MTok is 4x cheaper than current tts-1 and adequate for short tutor responses; covers ~40 of SOLA's 46 languages; TIER 1. ElevenLabs Flash v2.5 at $50/MTok and 75ms latency is best-in-class naturalness for ELL pronunciation work and voice mode; 32 supported languages; TIER 1. Azure Standard Neural covers am/so/sw/zu that Google and ElevenLabs miss. Browser Speech API for wo/bm/om — no commercial vendor at usable quality.

**Cost projection (reference only — not in baseline).** 5 min audio per SOLA learner per month × 30% TTS adoption × ~850 chars/min, recommended routing (70% WaveNet, 25% ElevenLabs Flash, 5% Azure):

| Scale | Saylor MAU | SOLA users | Monthly TTS spend (recommended) | All-OpenAI tts-1 |
|---|---|---|---|---|
| 30 course pilot at full enrollment | ~24,000 | ~6,000 | $30 | $50 |
| 50k MAU | 50,000 | 12,500 | $250 | $800 |
| 100k MAU | 100,000 | 25,000 | $500 | $1,600 |

Net (if enabled): ~70% TTS cost cut at 100k Saylor MAU versus the current OpenAI tts-1 default.

Implementation (if enabled): edit `tts.php` to add provider routing + per-locale provider mapping in `amd/src/speech.js`. No schema change.

---

## B.2 STT (speech to text)

### Recommendation: Deepgram Nova-3 streaming primary; Google Chirp 3 batch for African languages (if ever opted in)

Today: OpenAI Whisper API at $0.0060/min. No native streaming → voice chat feels lagged. African coverage weak — Whisper does not officially support Wolof, Bambara, Igbo, Hausa, Oromo, or Amharic at 50% WER.

| Vendor / model | Batch $/min | Streaming $/min | English WER | Notes |
|---|---|---|---|---|
| **Deepgram Nova-3 (English)** | $0.0077 | **$0.0048** | 5.2% | Zero retention default; sub-300ms end-of-turn |
| Deepgram Nova-3 (Multi) | $0.0092 | $0.0058 | – | 36 langs |
| OpenAI Whisper | $0.0060 | n/a | ~8.9% | Current; no streaming |
| OpenAI gpt-4o-transcribe | $0.0060 | $0.0060 | 4.0% | Streaming via Realtime |
| OpenAI gpt-4o-mini-transcribe | $0.0030 | $0.0030 | – | Cheap fallback |
| **Google Chirp 3** | $0.0040 (Dyn. Batch) | $0.064 | – | 100+ langs incl. wo, ha, am, yo, zu, sw |
| Azure Speech standard | $0.0030 batch | $0.0167 | – | 140+ langs |
| AssemblyAI Universal-2 | $0.0025 | $0.0025 | 3.1% (Universal-3 Pro) | English-leaning |
| Amazon Transcribe | $0.0240 (tier 1) | $0.0240 | 4.1% | Expensive at SOLA tier |

**Why Deepgram Nova-3 streaming.** Cheapest streaming tier at SOLA's volume ($0.0048/min, 20% under current Whisper batch, 50% under gpt-4o-transcribe streaming). Streaming is the missing feature in Whisper; sub-300ms end-of-turn means voice chat feels live. Zero retention default — cleanest compliance posture; SOC 2 + GDPR confirmed. WER 5.2% fine for chat / coaching use case (not transcription-of-record).

**Why Google Chirp 3 for African languages.** Only vendor covering Wolof, Hausa, Amharic, Yoruba, Zulu, Swahili in production. Three SOLA languages still uncovered by any commercial STT at usable WER: Bambara, Igbo, Oromo — graceful degrade is text-only chat with a UI note.

**Cost projection (reference only — not in baseline).** 20% voice adoption × 5 messages × 15s = 1.25 min/SOLA learner/mo:

| Scale | Saylor MAU | SOLA users | Monthly STT spend (recommended) | All-Whisper (current) |
|---|---|---|---|---|
| 30 course pilot at full enrollment | ~24,000 | ~6,000 | $10 | $12 |
| 50k MAU | 50,000 | 12,500 | $80 | $94 |
| 100k MAU | 100,000 | 25,000 | $160 | $188 |

Net (if enabled): ~15% cost cut at 100k Saylor MAU with materially better latency and full African coverage.

---

## B.3 Realtime voice / Voice tab (full duplex WebSocket)

### Recommendation: If the Voice tab is ever re-enabled, use Gemini 2.5 Flash native-audio

The Voice tab is **off in the baseline rollout.** This subsection captures the recommended vendor and pricing in case Saylor opts in for a future per-course or premium tier.

Today: OpenAI `gpt-realtime-mini` via WebSocket. Pricing: `gpt-realtime` ~$0.14/min all-in; `gpt-realtime-mini` ~$0.03/min.

| Vendor / Model | Input $/MTok audio | Output $/MTok audio | All-in $/min |
|---|---|---|---|
| OpenAI gpt-realtime | $32 ($0.40 cached) | $64 | ~$0.14 |
| **OpenAI gpt-realtime-mini** | ~$6 | ~$12 | **~$0.03** |
| **Gemini 2.5 Flash native-audio** | $3 | $12 | **~$0.02** |
| Gemini 2.5 Pro Live | $1.25 (text-eq) | $10 | ~$0.015 |
| Azure gpt-realtime | matches OpenAI | matches OpenAI | $0.14 |
| Vapi | $0.05/min + passthrough | – | $0.14 – $0.33 |
| Retell | $0.07/min + passthrough | – | $0.13 – $0.31 |
| LiveKit Cloud | $0.01/min agent + BYO | – | ~$0.077 (loaded) |
| Hume EVI | flat | – | $0.04 – $0.07 |

**Why Gemini 2.5 Flash native-audio.** ~7x cheaper than gpt-realtime, ~33% cheaper than gpt-realtime-mini. Same vendor as the chat tier (one Vertex AI account covers chat + voice). Better African coverage than OpenAI Realtime (Gemini Live lists Amharic, Hausa, Yoruba, Zulu, Swahili). Vertex AI DPA clean on no-training + SOC 2.

Keep `gpt-realtime-mini` as failover for ELL pronunciation work where OpenAI's English voice quality remains the ceiling. Avoid Vapi / Retell (150–300ms added latency, 2–10x cost). LiveKit Agents — fine infrastructure primitive but no language-coverage edge.

**Cost projection (reference only — not in baseline).** 7% blended voice adoption among SOLA users × 5 min/session × 1 session/SOLA learner/mo:

| Scale | Saylor MAU | SOLA users | Voice users (7%) | Monthly minutes | Gemini cost | OpenAI Realtime-mini |
|---|---|---|---|---|---|---|
| 30 course pilot at full enrollment | ~24,000 | ~6,000 | 420 | 2,100 | $40 | $60 |
| 50k MAU | 50,000 | 12,500 | 875 | 4,375 | $88 | $131 |
| 100k MAU | 100,000 | 25,000 | 1,750 | 8,750 | $175 | $263 |

Net (if re-enabled): ~30% saving versus the prior OpenAI-Realtime stack, plus the language coverage win.

---

## B.4 Talking avatar (optional feature)

### Recommendation: Deferred. If turning on in a future opt-in, pilot Tavus CVI (live in-chat) + Synthesia (instructor intros).

Single biggest discretionary line item at scale. Status today: not deployed. v4.9.0 / v4.10.0 added provider integrations (D-ID, HeyGen, Tavus, Synthesia) but the feature is gated off and per-minute cost tracking is wired in.

| Vendor / tier | Live $/min | Render $/min | Custom replica? | African languages |
|---|---|---|---|---|
| **Tavus CVI (Growth tier)** | **$0.32** | – | yes (2-min training) | not documented |
| HeyGen LiveAvatar | $0.10 – $0.20 | – | yes ($1 / training) | af, am, ar, so, sw, zu |
| D-ID Streaming | ~$5.90 | ~$0.50 – $1 | photo-only | minimal |
| Synthesia Personal Avatar | – (no realtime) | ~$2.90 – $3.00 | yes ($1k/yr add-on) | af, am, so, sw, zu |
| Argil | – (no realtime) | ~$1.49 – $1.56 | yes (1-min audio + photo) | minimal |
| Soul Machines | **N/A — in receivership 2026-02-05** | – | – | – |

**Why Tavus CVI for in-chat live.** $0.32/min is the cheapest sub-$0.40 realtime option with a clear SOC 2 + GDPR posture (~18x cheaper than D-ID Streaming). 2-min replica training lets Saylor build custom avatars per course/instructor. HeyGen LiveAvatar is even cheaper ($0.10–$0.20/min) and is the credible fallback; Tavus leads on DPA cleanliness.

**Why Synthesia for instructor intros.** Only avatar vendor with a documented HE reference (Bolton College, UK FE, 10k+ learners, 400 videos/year, 80% production-time cut). Best polish for pre-recorded use cases. 160+ languages including Amharic, Somali, Swahili, Zulu.

**Avoid:** Soul Machines (receivership 2026-02-05), D-ID Streaming (18x cost, transparency complaints), Argil (no realtime API).

**African language gap is structural.** No major avatar vendor lip-syncs Yoruba, Hausa, Igbo, Wolof, Bambara, or Oromo. Synthesia / HeyGen cover Afrikaans, Amharic, Somali, Swahili, Zulu — useful but not complete.

**Cost projection (reference only — not in baseline, if turned on at 5% of SOLA users).** 5% adoption × 3 min/session × 1 session/SOLA learner/mo × Tavus CVI $0.32/min:

| Scale | Saylor MAU | SOLA users | Avatar users (5%) | Monthly minutes | Monthly avatar spend |
|---|---|---|---|---|---|
| 30 course pilot at full enrollment | ~24,000 | ~6,000 | 300 | 900 | $290 |
| 50k MAU | 50,000 | 12,500 | 625 | 1,875 | $600 |
| 100k MAU | 100,000 | 25,000 | 1,250 | 3,750 | $1,200 |

At 100k Saylor MAU, avatar at $1,200/mo is ~85% of the baseline text-stack spend. The pilot lever: turn it on for a single course (say BUS101) at full 5% SOLA-user adoption, measure 90-day completion vs an avatar-off control, decide from data.

---

# Appendix C — Sources

## Internal (this repo)

- `.drafts/sola-benchmark-decision-2026-06-03.md` — 8-provider × 50-prompt chat benchmark.
- `.drafts/sola-benchmark-domain-routing-2026-06-04.md` — 8-provider × 40-prompt subject-domain bake-off.
- `.drafts/sola-llm-capacity-planning-pilot.md` — per-learner token shape and per-provider cost.
- `.drafts/sola-2026-cost-update-and-q2-pilot.md` — v5.0.0 measured baseline + Q2 pilot scenarios.
- `.drafts/sola-multi-provider-optimization-plan.md` — compliance tier classification, failover chain design.
- `.drafts/sola-rate-card-tracking.md` — live per-token rate card.

## External — chat / language models

- Vertex AI Gemini pricing: https://ai.google.dev/gemini-api/docs/pricing
- OpenAI pricing: https://openai.com/api/pricing
- Anthropic pricing: https://www.anthropic.com/pricing
- Mistral La Plateforme pricing: https://mistral.ai/technology/#pricing

## External — embeddings

- MTEB leaderboard (April 2026): https://awesomeagents.ai/leaderboards/embedding-model-leaderboard-mteb-april-2026/
- Voyage AI pricing: https://docs.voyageai.com/docs/pricing
- Voyage-3.5 announcement: https://blog.voyageai.com/2025/05/20/voyage-3-5/
- OpenAI embeddings pricing: https://tokenmix.ai/blog/openai-embedding-pricing
- Cohere embed docs: https://docs.cohere.com/v2/docs/cohere-embed
- Pinecone two-stage retrieval study: https://www.pinecone.io/learn/series/rag/rerankers/
- aimultiple reranker benchmark 2026: https://aimultiple.com/rerankers

## External — TTS (Appendix B.1)

- ElevenLabs API pricing: https://elevenlabs.io/pricing/api
- Google Cloud TTS pricing: https://cloud.google.com/text-to-speech/pricing
- AWS Polly pricing: https://aws.amazon.com/polly/pricing/
- Azure Speech pricing: https://azure.microsoft.com/en-us/pricing/details/cognitive-services/speech-services/
- Artificial Analysis Speech Arena: https://artificialanalysis.ai/text-to-speech/leaderboard
- ElevenLabs supported models: https://elevenlabs.io/docs/overview/models

## External — STT (Appendix B.2)

- Deepgram pricing: https://deepgram.com/pricing
- OpenAI Whisper / Realtime pricing: https://openai.com/api/pricing/
- Google Speech-to-Text pricing: https://cloud.google.com/speech-to-text/pricing
- Google Chirp 3 docs: https://docs.cloud.google.com/speech-to-text/docs/models/chirp-3
- AssemblyAI pricing: https://www.assemblyai.com/pricing
- AWS Transcribe pricing: https://aws.amazon.com/transcribe/pricing/
- Artificial Analysis STT leaderboard: https://artificialanalysis.ai/speech-to-text
- Northflank STT benchmarks 2026: https://northflank.com/blog/best-open-source-speech-to-text-stt-model-in-2026-benchmarks
- Gradium STT benchmark 2026: https://gradium.ai/content/stt-api-benchmark-2026-latency-accuracy

## External — realtime voice (Appendix B.3)

- OpenAI gpt-realtime introduction: https://openai.com/index/introducing-gpt-realtime/
- Gemini Live API: https://blog.google/innovation-and-ai/technology/developers-tools/build-with-gemini-3-1-flash-live/
- Azure Voice Live language support: https://learn.microsoft.com/en-us/azure/ai-services/speech-service/voice-live-language-support
- Vapi pricing: https://vapi.ai/pricing
- Retell AI pricing: https://www.retellai.com/pricing
- LiveKit Cloud pricing: https://livekit.com/pricing
- Hume EVI pricing: https://www.hume.ai/pricing
- Speko realtime benchmark: https://speko.ai/benchmark/openai-vs-gemini-live

## External — talking avatars (Appendix B.4)

- Tavus pricing: https://www.tavus.io/pricing
- HeyGen LiveAvatar docs: https://help.heygen.com/en/articles/12758516-introducing-liveavatar
- HeyGen API pricing: https://developers.heygen.com/docs/pricing
- Synthesia pricing: https://www.synthesia.io/pricing
- Synthesia Bolton College case study: https://www.synthesia.io/case-studies/bolton-college
- Synthesia supported languages: https://docs.synthesia.io/docs/supported-languages
- Argil pricing: https://www.argil.ai/pricing
- Soul Machines receivership notice: https://gazette.govt.nz/notice/id/2026-ar623
