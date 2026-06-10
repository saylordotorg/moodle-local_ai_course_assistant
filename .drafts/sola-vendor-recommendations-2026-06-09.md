# SOLA Vendor Recommendations (Pilot → 100k Saylor MAU)

**Author:** Tom Caswell. **Date:** 2026-06-09. **Status:** Ready for finance, procurement, ops, and DPO review.

Concise rev of `.drafts/sola-pilot-to-scale-vendor-recommendations-2026-06-01.md` (which stays as the long-form historical record). All conclusions, benchmark evidence, and rate-card sources are unchanged; this version drops repetition between the front-page summary blocks, condenses Appendix A from 10 verbose subsections to bullet form, and trims Appendix B (audio, voice, avatars) to one table per feature.

**Scope.** Text-only universal-rollout baseline. Voice tab, TTS, STT, and talking avatars are out of baseline (Appendix B keeps the analysis for a future opt-in). Every cost assumes **25% of Saylor MAU actively uses SOLA in a given month**.

**Re-benchmark decision.** Considered re-running the SOLA chat bake-off because the 2026-06-03 run had Gemini throttled. Skipped: the 2026-06-04 domain-routing run (un-throttled Gemini) reconfirms the same ordering (Gemini Flash > Claude Haiku > gpt-4o-mini), and the picks are robust to a 5-day-old data point. A fresh API spend would not change a single recommendation.

---

## TL;DR (one page)

| Component | Primary | Failover | Why |
|---|---|---|---|
| Core chat tutor | **Gemini 2.5 Flash** (Vertex AI) | gpt-4o-mini | Wins every domain × function bucket (2026-06-04). 14.35/15 overall, only model >14 on every subject. |
| Quiz coach | gpt-4o-mini | Mistral Small | Function saturated; gpt-4o-mini at 14.00/15 is cheapest TIER 1. |
| Anti-cheat / integrity reference | Claude Haiku 4.5 (~5% of turns) | Gemini 2.5 Flash | Best refusal discipline (14.60/15); budget Llamas cave (10.30–11.50). |
| ESL / multilingual chat | Gemini 2.5 Flash | gpt-4o-mini | Decisive multilingual lead (14.50/15). |
| Mastery classifier | gpt-4o-mini | Mistral Small | Structured-output adhoc task at ~1/40 of chat-tier per-token. |
| Analytics + digests | gpt-4o-mini (keep) | Gemini 2.5 Flash | Batch summarization. Unchanged. |
| Embeddings (RAG) | **Voyage-3.5** | OpenAI text-embedding-3-small | +4 MTEB, 4x context, materially better multilingual. |
| RAG re-ranker | **Voyage rerank-2.5** | Cohere Rerank 3.5 | 40x cheaper than Cohere for near-equivalent quality. |
| Judge harness | Claude Sonnet 4.6 (keep) | Gemini 2.5 Pro | Independent of contestant pool. |

**Cost at scale (text-only, 25% adoption):**

| Scale point | Saylor MAU | SOLA users | Monthly | vs current stack |
|---|---|---|---|---|
| 30-course pilot at full enrollment | ~24,000 | ~6,000 | ~$330 | ~30% cheaper |
| 50k MAU | 50,000 | 12,500 | ~$690 | ~35% cheaper |
| 100k MAU | 100,000 | 25,000 | **~$1,370** | **~35% cheaper** |

**Compliance.** Every baseline vendor is TIER 1 (SOC 2 Type II + GDPR DPA + no training by default), confirmed in `sola-multi-provider-optimization-plan.md` section 2. FERPA out of scope (Saylor is tuition-free).

**Decision needed.** Sign-off on the text-only baseline and the projected ~$1.4k/month at 100k Saylor MAU. Audio / avatar tiers can be re-opened post-rollout if learner research surfaces clear demand.

---

## 1. Scale assumptions and what drives cost

A "100k MAU" scale point = 100k Saylor MAU with ~25k active SOLA users. **Chat is the cost driver**; the supporting components (embeddings, re-ranker, classifier, analytics, integrity, judge) together add ~$320/month at 100k Saylor MAU. Per-learner blended chat rates (from `sola-llm-capacity-planning-pilot.md`):

| Chat model | Per-SOLA-learner blended $/mo |
|---|---|
| Together Llama 3.1 8B Lite | $0.040 |
| **Gemini 2.5 Flash** | **$0.042** |
| OpenAI gpt-4o-mini | $0.070 |
| Mistral Small 3 | $0.089 |
| Claude 3.5 Haiku | $0.390 |

**Sensitivity.** Costs scale linearly with adoption. 35% adoption: multiply by 1.4. 50%: multiply by 2. Refresh quarterly if adoption drifts more than 5 percentage points.

**Not captured by the simple math.** Heavy users (top 10%) drive 50–70% of token volume. ELL courses can spike 3–5x in days. First 2–3 weeks at any new course tier typically run 2–3x steady state. Anthropic prompt-cache (90% off cached prefix) and OpenAI auto-prefix discount (50%) shrink chat spend at scale; Gemini does not currently expose a prefix cache discount.

---

## 2. Consolidated cost projection

| Component | 30-course pilot (6k SOLA users) | 50k MAU (12.5k) | 100k MAU (25k) |
|---|---|---|---|
| Chat tutor (Gemini 2.5 Flash, blended including ESL) | $250 | $525 | $1,050 |
| Quiz coach (gpt-4o-mini) | $20 | $43 | $85 |
| Anti-cheat reference (Haiku 4.5, ~5% turns) | $8 | $16 | $33 |
| Mastery classifier (gpt-4o-mini) | $25 | $53 | $105 |
| Analytics + digests + Radar | $4 | $15 | $30 |
| Embeddings (Voyage-3.5) | <$1 | <$1 | <$1 |
| Re-ranker (Voyage rerank-2.5) | $15 | $31 | $63 |
| Judge harness | $4 | $4 | $4 |
| **Baseline total** | **~$330** | **~$690** | **~$1,370** |
| Premium escalation (Opus 4.8, 5% of turns, ships v5.12+) | $170 | $350 | $700 |
| **Total with premium tier** | **~$500** | **~$1,040** | **~$2,070** |

Saving vs current text-only stack at 100k Saylor MAU: ~$730/month (~35%). Sources: specialty routing (gpt-4o-mini on quiz / classifier / analytics, Haiku only on suspected-cheat turns), Voyage-3.5 + rerank-2.5, cleaner failover chain that prevents accidental Claude-as-fallback burn.

**Note on ESL.** ESL learners are ~30% of the SOLA mix and run 3.5x typical chat volume. The blended $0.042/SOLA-learner/mo rate already reflects this mix; do not double-count when projecting per-course economics for ELL-heavy cohorts.

**Future Directions add-ons (Appendix B, not in baseline).** At 100k Saylor MAU under 25% adoption: TTS ~$500/mo, STT ~$160/mo, realtime voice ~$175/mo, avatar at 5% SOLA adoption ~$1,200/mo. Add-on subtotal if all enabled: ~$2,035/mo (about 1.5x the baseline).

---

## 3. Multi-region and failover

Single-vendor single-region is not acceptable at 100k Saylor MAU.

- **US (primary):** Vertex AI us-east1 + OpenAI default + Voyage default.
- **EU residency:** Vertex AI eu-west1 for chat; OpenAI EU residency once shipped. **Mistral La Plateforme is NOT in Saylor's default failover** as of 2026-06-09 (training-opt-out + ZDR still pending in their portal, and Saylor doesn't need EU residency at current scale). Provider class stays available so non-Saylor sites can opt in; re-add to Saylor's chain once both Mistral conditions clear.
- **APAC residency:** Vertex AI ap-southeast-1.

**Failover chain** (Saylor default; configured in `spend_failover_chain` on dev as of 2026-06-09):

1. Primary: Gemini 2.5 Flash (Vertex AI us-east1).
2. On 429 / 5xx: OpenAI gpt-4o-mini.
3. On secondary failure: serve a degraded "SOLA briefly unavailable" message rather than burn Claude Haiku at 1.9x cost.

Mistral Small was originally specified as the EU-resident ultimate fallback (step 3 above). Removed from Saylor's chain on 2026-06-09 pending Mistral training-opt-out + ZDR. Non-Saylor sites that have completed Mistral procurement can add `chat:mistral` as a third line.

**Capacity tiers to negotiate 2–4 weeks before promotion to 100k Saylor MAU:**

| Vendor | Component | Approx volume / day | Required tier |
|---|---|---|---|
| Vertex AI (Gemini Flash) | chat | ~58k chat turns | Tier 3+ (5k+ RPM) |
| OpenAI (gpt-4o-mini) | quiz + classifier + analytics + failover | ~25k structured calls | Tier 4 (10k+ RPM) |
| Anthropic (Haiku 4.5) | integrity reference | ~3k calls | Tier 3 |
| Voyage (embeddings + rerank-2.5) | RAG index + runtime | ~125k reranks | Pay-as-you-go fine |

---

## 4. Procurement and operational checklist

### Procurement (before 50k MAU rollout)

- [ ] **Google Vertex AI** — enterprise DPA, EU + US + APAC regions enabled, explicit no-training-by-default clause.
- [ ] **OpenAI** — Tier 4 organization, no-training-on-API-data clause.
- [ ] **Anthropic** — Tier 3 organization, DPA, no-training-by-default.
- [ ] **Voyage AI** — enterprise tier, SOC 2 Type II + GDPR + no-training in writing.
- [ ] **~~Mistral La Plateforme~~** — DROPPED from Saylor procurement 2026-06-09. Provider class stays available; if Saylor ever needs the EU-resident ultimate-fallback lane, complete Mistral training-opt-out + ZDR in their portal and re-open this item.

### Operational (in admin settings)

- [ ] Per-component spend caps (1.5x headroom at 100k MAU): chat $1.6k/mo, quiz / classifier / analytics $300/mo, integrity reference $150/mo, re-ranker $150/mo.
- [ ] Per-course soft cap $250/mo with admin alert (especially ELL).
- [ ] Failover chain populated in admin: primary + 2 fallbacks per component.
- [ ] Prompt-debug log on for first 30 days post-promotion.
- [ ] Weekly benchmark CLI re-run to track quality drift.
- [ ] Cost dashboard in Redash extended to per-component breakdown.
- [ ] Voice tab disabled in rollout build: `realtimeenabled` off, voice button hidden, `tts.php` and STT entry points removed from the learner UI.

### Compliance

- [ ] Update `sola-privacy-notice-learner-facing.md` with final baseline vendor list (no audio vendors).
- [ ] Update `sola-multi-provider-optimization-plan.md` rate cards and tier classifications to align with this doc.
- [ ] DPO sign-off on the multi-vendor data flow diagram.

---

## 5. Risks

1. **Gemini outage during EU evening.** Existing per-region failover chain handles it (Vertex US-East → OpenAI gpt-4o-mini US-East). No code change.
2. **Voyage is a smaller vendor.** Keep OpenAI text-embedding-3-small as a one-flag-flip fallback; full re-index costs $1.60.
3. **Gemini quality regression.** Weekly benchmark catches within 7 days. Fall back to gpt-4o-mini as chat tier until a Gemini version pins better.
4. **Voyage acquisition risk** (sub-100-employee startup). Second picks: OpenAI 3-small (embeddings), Cohere Rerank 3.5. Transition is one config setting + one-time re-index.
5. **EU Vertex AI is ~10–15% more expensive than US-East.** Budget accordingly.
6. **Anthropic rate-limit on Haiku 4.5 during exam windows.** Per-course Haiku cap; fail over integrity lane to Gemini 2.5 Flash (14.30/15 close second) on 429.
7. **ESL multiplier higher than 3.5x for a viral ELL cohort.** Per-course soft cap with admin alert; temporary cap raises on request.
8. **SOLA adoption higher than 25%.** Costs scale linearly. At 50% adoption: double the figures. Refresh quarterly.
9. **Learner demand for voice / avatar post-rollout.** Appendix B is the opt-in path; lever is a single-course A/B on BUS101 before wider enable.

---

## 6. Next steps

1. Review with finance + procurement + ops + DPO. Sign-off on the baseline vendor list and the ~$1.4k/month at 100k Saylor MAU figure.
2. Populate per-component admin settings on dev; confirm BUS101 smoke passes.
3. Wire two-stage RAG retrieval: add Voyage rerank-2.5 to `sse.php` between embedding retrieval and chat. Half-day work.
4. Migrate embeddings to Voyage-3.5: one-time re-index (~$4.80) + config update in `sse.php` and `rag_admin.php`. Half-day work.
5. Confirm Voice tab is disabled in the rollout build.
6. Negotiate vendor tier upgrades (Vertex Tier 3+, OpenAI Tier 4, Anthropic Tier 3, Voyage enterprise). 2–4 week procurement window.
7. Run the deferred premium-escalation-tier bake-off (A.10 below, ~$5, half-day). Decides whether SOLA needs a premium tier.
8. Defer audio / voice / avatar opt-in. Revisit per Appendix B only if post-rollout learner research surfaces demand.
9. Refresh `sola-multi-provider-optimization-plan.md` to align rate cards, tier classifications, and failover chain spec with this doc.

---

# Appendix A: Per-component evidence (condensed)

Full benchmark scores live in `.drafts/sola-benchmark-decision-2026-06-03.md` and `.drafts/sola-benchmark-domain-routing-2026-06-04.md`. The original long-form per-component analysis is in `.drafts/sola-pilot-to-scale-vendor-recommendations-2026-06-01.md` Appendix A. Below is the load-bearing evidence per pick.

### A.1 Chat tutor: Gemini 2.5 Flash

- **Quality:** 14.35/15 overall on domain benchmark. Only model above 14 on every subject. Ties Claude on multilingual at 14.50/15.
- **Cost:** $0.060 per call on SOLA prompt shape (verified). Half of Claude Haiku 4.5 ($0.111).
- **Blended:** $0.042 per SOLA learner per month → $1,050/mo chat at 100k Saylor MAU. Claude Haiku at same scale: ~$9,750/mo.
- **Compliance:** Vertex AI TIER 1; EU + US + APAC residency.
- **Why not Claude Haiku as primary:** 1.9x per-call cost, 4x per-token cost, for <0.5 point on the 15-point rubric. Claude earns its slot at A.3 instead.
- **Why not Sonnet 4.6 or Opus 4.x:** Sonnet is the judge (self-bias risk). Opus 4.x at $15/$75 per MTok is 50x Gemini Flash per token; ~$50k/mo at 100k Saylor MAU is fiction. Premium routing is A.10's job.

**Qwen 2.5-VL-7B (self-hosted vLLM at `sola-llm-api.sylr.org`):** tested in the 2026-06-03 bake-off. Scored **9.88/15, lowest of all 8 providers**, with ~10s latency. Exam Booster v2 (2026-06-07) re-confirmed Qwen-as-generator failed completeness gate twice (LO 3d short, then 3k short). **Not in any chat / quiz / classifier lane.** Self-hosting earns a slot only at A.7 as a multilingual embedding fallback (different model: Qwen3-Embedding-8B, MMTEB multilingual leader at 70.58).

### A.2 Quiz coach: gpt-4o-mini

Quiz function saturated: every TIER 1 between 14.0 and 14.7. gpt-4o-mini at $0.012/call at TIER 1 + 14.00/15 wins on price. OpenRouter Llama 3.1 8B is 8x cheaper but sits at TIER 3 (training disclaimer): disqualifying for student-facing traffic.

### A.3 Anti-cheat / integrity reference: Claude Haiku 4.5 on suspected-cheat turns only

Haiku 4.5 leads at 14.60/15. Cheap Llamas cave at 10.30–11.50. SOLA's existing anti-cheat detection logic flags a suspected-cheat turn and routes only that turn to Claude Haiku 4.5; the other ~95% of turns stay on Gemini Flash. Volume share keeps cost trivial (~$33/mo at 100k MAU).

### A.4 ESL / multilingual chat: Gemini 2.5 Flash (same as primary)

Wins multilingual outright at 14.50/15. ESL share is ~30% of SOLA mix and runs 3.5x typical chat volume, but the blended $0.042/SOLA-learner rate already reflects this; no extra line item required. Budget Llamas at 9.3–10.0 multilingual are disqualifying for any course with meaningful ESL enrollment.

### A.5 Mastery classifier: gpt-4o-mini

Structured-output adhoc task, ~500 in / 50 out tokens, native JSON mode. Running on gpt-4o-mini saves ~$220/mo vs running on Gemini Flash because the classifier doesn't need Socratic guidance or multilingual fluency; it needs cheap and JSON-clean.

### A.6 Analytics, weekly digests, Learning Radar: gpt-4o-mini (keep)

Batch summarization, structured output, no latency requirement. SOLA's existing pipeline has 6+ months of production-quality output; no change.

### A.7 Embeddings: migrate to Voyage-3.5

| Model | MTEB English avg | $/1M tokens | Dims | Max input |
|---|---|---|---|---|
| **Voyage-3.5** (pick) | ~66 | $0.06 | 1,024 (MRL→256) | 32,000 |
| Voyage-3.1-large (retrieval ceiling) | 67.40 | $0.18 | 1,024 (MRL→256) | 32,000 |
| Gemini Embedding 001 (quality ceiling) | 68.32 | $0.15 | 3,072 (MRL→768) | 2,048 |
| OpenAI text-embedding-3-small (current) | ~62 | $0.02 | 1,536 | 8,191 |

+4 MTEB over OpenAI 3-small, 4x context window, materially better multilingual. Re-indexing 161 courses at Voyage-3.5: $4.80 one-time. TIER 1; verify no-training in DPA at enterprise tier.

Multilingual escalation lever if Voyage proves weak on non-English course materials: self-host Qwen3-Embedding-8B (MMTEB multilingual leader at 70.58, Apache 2.0) on a g5.xlarge (~$735/mo all-in). Cost-justifies above ~12M tokens/mo of embedding work; SOLA is well below that today.

### A.8 RAG re-ranker: add Voyage rerank-2.5

| Re-ranker | $/1M tokens |
|---|---|
| **Voyage rerank-2.5** (pick) | $0.05 |
| Cohere Rerank 3.5 (failover) | $2.00 |

40x cheaper than Cohere for near-equivalent quality. Two-stage retrieval (top-50 embed → top-5 cross-encoder) is the dominant 2026 RAG pattern; published lifts include +15 Recall@10 (enterprise corpora) and +39% NDCG on BEIR. ~$63/mo at 100k Saylor MAU; ~6% of chat spend for material answer-quality lift.

### A.9 Judge / benchmark harness: Claude Sonnet 4.6 (keep)

Must be a model that is NOT in the contestant pool. 2026-06-03 run notes Claude did not win, which argues against strong self-bias. If Sonnet enters a future contestant pool (e.g. A.10), swap judge to Gemini 2.5 Pro or use a Sonnet + Gemini 2.5 Pro ensemble. ~$1/week.

### A.10 Premium escalation tier — RESOLVED 2026-06-09

A.10 bake-off ran 2026-06-09 on dev. Full writeup: `.drafts/sola-a10-premium-escalation-bakeoff-2026-06-09.md`. 40 prompts × 4 contestants (Sonnet 4.6, Opus 4.8, Gemini 2.5 Pro, gpt-4o) × 1 judge (gpt-4.1). 160 calls each side. ~$2 API spend.

**Verdict: add Claude Opus 4.8 as premium escalation tier for hard_math, hard_cs, hard_science prompts at ~5% escalation rate.** Opus 4.8 won at 14.97/15 overall (near-perfect across every category). Gap to gpt-4o on hard categories: 3.0-3.2 points. Gap to Gemini 2.5 Pro: 0.12 points — but Pro has 11.5s P50 TTFT (unusable for real-time chat) so loses on UX.

Disqualified candidates:
- **gpt-4o** finished last (12.68/15) — Socratic discipline collapses on hard prompts (2.70/5). Despite being a tier above gpt-4o-mini, it is an answer-first workhorse, not a premium escalation target.
- **Gemini 2.5 Pro** dominates on price (0.296¢ vs Opus 1.377¢) but 11.5s P50 TTFT breaks the streaming-chat UX. Reserve for async / background analysis only.
- **Sonnet 4.6** dominated by both Opus (quality) and Pro (price). Stays as the benchmark judge model.

**Routing triggers:** regex on user message ("derive", "prove that", "step by step", LaTeX `$...$`, code blocks) + course-tag fast path for MATH/CS/PHYS/CHEM/BIOL.

**Cost at 5% escalation:** ~$700/mo at 100k Saylor MAU. Added to the section 2 cost table.

**Open follow-ups:** (1) v5.12 — build the routing logic (half-day eng + 1 day prompt-tag curation). (2) Instructor spot-check on 20 of the bake-off responses to validate the LLM judge's calls. (3) Per-course escalation-rate monitoring after rollout.

---

# Appendix B: Future Directions (audio, voice, avatars — NOT in baseline)

Reference picks and pricing kept here so a future opt-in can move quickly without redoing the vendor analysis. None of these figures feed the consolidated cost projection, procurement checklist, or decision summary. At 100k Saylor MAU under 25% SOLA adoption, the audio stack (B.1 + B.2 + B.3) would add ~$820/mo if enabled; the talking avatar tier (B.4) would add ~$1,200/mo at 5% SOLA-user adoption.

### B.1 TTS: three-tier routing (if enabled)

| Tier | Vendor | When | $/MTok |
|---|---|---|---|
| Default | Google WaveNet | English + 30 mainstream langs | $4 |
| Premium | ElevenLabs Flash v2.5 | ELL pronunciation, voice mode | $50 |
| African native | Azure Standard Neural | am, so, sw, zu | $15 |
| Fallback | Browser Speech API | wo, bm, om (uncovered commercially) | $0 |

At 100k Saylor MAU: ~$500/mo recommended routing vs ~$1,600/mo if all-OpenAI tts-1. ~70% saving on the same use case. Implementation: edit `tts.php` to add provider routing + per-locale provider mapping in `amd/src/speech.js`. No schema change.

### B.2 STT: Deepgram Nova-3 streaming primary + Google Chirp 3 for African (if enabled)

Streaming at $0.0048/min (20% under Whisper batch, 50% under gpt-4o-transcribe). Sub-300ms end-of-turn means voice chat feels live. Zero retention default. SOC 2 + GDPR. WER 5.2% (fine for chat / coaching). Google Chirp 3 covers Wolof, Hausa, Amharic, Yoruba, Zulu, Swahili (only commercial vendor with usable WER on those). Bambara, Igbo, Oromo remain uncovered commercially: graceful degrade is text-only chat with a UI note.

At 100k Saylor MAU: ~$160/mo recommended vs $188/mo all-Whisper. ~15% saving plus materially better latency and African coverage.

### B.3 Realtime voice / Voice tab: Gemini 2.5 Flash native-audio (if ever re-enabled)

| Vendor / model | All-in $/min |
|---|---|
| **Gemini 2.5 Flash native-audio** (pick) | ~$0.02 |
| OpenAI gpt-realtime-mini (failover for ELL) | ~$0.03 |
| OpenAI gpt-realtime | ~$0.14 |
| Vapi / Retell | $0.13 – $0.33 |

7x cheaper than gpt-realtime, 33% cheaper than gpt-realtime-mini. Same vendor as the chat tier (one Vertex AI account covers both). Lists Amharic, Hausa, Yoruba, Zulu, Swahili. At 100k Saylor MAU (7% voice adoption × 5 min/session): ~$175/mo. Avoid Vapi / Retell (150–300ms added latency, 2–10x cost) and LiveKit Cloud (no language coverage edge over Vertex).

### B.4 Talking avatar (deferred)

If enabled, pilot **Tavus CVI** ($0.32/min live, custom replica with 2-min training, cleanest SOC 2 + GDPR posture) for in-chat live, and **Synthesia** ($2.90–$3.00/min render, only HE reference in the space — Bolton College, 10k+ learners, 400 videos/year, 80% production-time cut) for instructor intros.

| Vendor | Live $/min | Render $/min | Custom replica? | African langs |
|---|---|---|---|---|
| **Tavus CVI (Growth)** | **$0.32** | – | yes (2-min training) | not documented |
| HeyGen LiveAvatar | $0.10–$0.20 | – | yes ($1 / training) | af, am, ar, so, sw, zu |
| D-ID Streaming | ~$5.90 | ~$0.50–$1 | photo-only | minimal |
| **Synthesia (Personal Avatar)** | – | ~$2.90–$3.00 | yes ($1k/yr add-on) | af, am, so, sw, zu |
| Soul Machines | **N/A — receivership 2026-02-05** | – | – | – |

At 100k Saylor MAU with 5% SOLA-user adoption: ~$1,200/mo — about 85% of the baseline text-stack spend. **Single-course A/B on BUS101 before any wider enable.** Measure 90-day completion vs an avatar-off control; decide from data.

**African language gap is structural.** No major vendor lip-syncs Yoruba, Hausa, Igbo, Wolof, Bambara, or Oromo. Synthesia / HeyGen cover Afrikaans, Amharic, Somali, Swahili, Zulu: useful but not complete. Avoid Soul Machines (receivership), D-ID Streaming (18x cost, transparency complaints), Argil (no realtime API).

---

# Sources

**Internal (this repo):** `.drafts/sola-benchmark-decision-2026-06-03.md`, `.drafts/sola-benchmark-domain-routing-2026-06-04.md`, `.drafts/sola-llm-capacity-planning-pilot.md`, `.drafts/sola-2026-cost-update-and-q2-pilot.md`, `.drafts/sola-multi-provider-optimization-plan.md`, `.drafts/sola-rate-card-tracking.md`, `.drafts/sola-pilot-to-scale-vendor-recommendations-2026-06-01.md` (long-form predecessor).

**External (chat / language):** Vertex AI Gemini pricing, OpenAI pricing, Anthropic pricing, Mistral La Plateforme pricing.

**External (embeddings + reranker):** MTEB leaderboard (April 2026), Voyage AI pricing docs, Voyage-3.5 announcement, Cohere embed v2 docs, Pinecone two-stage retrieval study, aimultiple reranker benchmark 2026.

**External (audio / voice / avatar — Appendix B only):** ElevenLabs, Google Cloud TTS, Azure Speech, Deepgram, Google Chirp 3, AssemblyAI, AWS Transcribe, Artificial Analysis Speech Arena + STT leaderboard, OpenAI gpt-realtime intro, Gemini Live API, Vapi, Retell, LiveKit Cloud, Hume EVI, Tavus pricing, HeyGen LiveAvatar docs + API pricing, Synthesia pricing + Bolton College case study + supported languages, Argil pricing, Soul Machines receivership notice (gazette.govt.nz/notice/id/2026-ar623).

Full URLs in `.drafts/sola-pilot-to-scale-vendor-recommendations-2026-06-01.md` Appendix C.
