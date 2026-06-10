# SOLA AI Vendor Optimization by Scale (10K / 50K / 100K MAU)

**Author:** Tom Caswell. **Date:** 2026-06-09. **Status:** decision-grade. Sits alongside `.drafts/sola-vendor-recommendations-2026-06-09.md` (the "which vendors" doc); this doc answers the orthogonal question: **at each scale point, which optimizations are worth doing, which are over-engineering, and what trigger metric moves you to the next tier?**

All cost projections assume 25% of Saylor MAU actively uses SOLA per month (the established baseline). Text-only universal rollout; audio / voice / avatar stay deferred per Appendix B of the companion doc. Reflects the v5.11.0 + v5.12.0 work that just shipped (mastery classifier off chat tier, Voyage embeddings + opt-in rerank, OpenAI auto-prefix cache visibility, Claude Opus 4.7+ temperature fix, premium escalation router).

---

## TL;DR

| Scale | Saylor MAU | SOLA users | Baseline $/mo | Premium add ($5%) | Total $/mo | Procurement burden | Vector DB |
|---|---:|---:|---:|---:|---:|---|---|
| Small | 10,000 | 2,500 | ~$140 | ~$70 (skip recommended) | **~$140-210** | none | SQL is fine |
| Mid | 50,000 | 12,500 | ~$690 | ~$350 | **~$1,040** | start GCP commit conversation | SQL still fine; plan a sidecar |
| Full | 100,000 | 25,000 | ~$1,370 | ~$700 | **~$2,070** | full procurement cycle (Vertex / OpenAI / Anthropic / Voyage) | pgvector if Postgres, else Qdrant sidecar when cross-course semantic ships |

**Optimization sweet spot is non-linear.** At 10K you should skip most of the v5.11+v5.12 machinery; at 50K most of it earns its keep; at 100K you should pull every lever including procurement-flavored ones (~$1.2-1.8k/mo of additional saving available on top of the $2,070 projection).

**Vector vs RAG headline:** SOLA's current "RAG" is JSON-encoded embeddings in MySQL TEXT with PHP cosine. That holds up fine through 100K MAU at per-course-scoped retrieval (~1,250 vectors / course / query). The trigger to move to a real vector DB (pgvector / Qdrant) is **not MAU but feature scope**, specifically a single course exceeding ~5,000 chunks or shipping cross-course semantic search.

---

## 1. Scale at 10K Saylor MAU (~2,500 SOLA users)

### Cost projection

| Component | $/mo |
|---|---:|
| Chat (Gemini 2.5 Flash, blended ESL) | ~$105 |
| Quiz + classifier + analytics (gpt-4o-mini) | ~$22 |
| Anti-cheat reference (Haiku, 5% turns) | ~$3 |
| Embeddings + judge harness | ~$5 |
| Re-ranker (Voyage rerank-2.5, opt-in) | ~$6 |
| **Baseline subtotal (rerank off)** | **~$135** |
| Premium tier (Opus 4.8, 5% of turns, v5.12 opt-in) | ~$70 |
| **Total if all v5.11+v5.12 features ON** | **~$210** |

### Recommendation: minimize complexity

At 10K MAU you're spending pocket change. The optimization machinery has fixed overhead (engineering time to enable + monitor) that doesn't pay back at this volume.

**DO at this level:**
1. Keep v5.11.0's mastery classifier routing on (the upgrade hook set it automatically; saves ~$22/mo on the gpt-4o-mini classifier vs chat-tier).
2. Stay on default OpenAI / Anthropic / Vertex tier rate limits. No procurement.
3. Set per-course soft caps at $30/mo with admin alert at $50. At 10K MAU, one viral ELL course can be 30% of total spend.
4. Reduce conversation history from 50 pairs to 30 pairs (`prompt_history_pairs` setting). Saves ~5-10% of chat input tokens with low quality risk.
5. Keep the failover chain empty + `failover_per_call_enabled=0`. At 10K MAU a brief outage hits 30-50 active learners; document the manual fallback playbook instead of paying for failover overhead.
6. Stay on us-east-1 single-region. Skip EU residency until you have meaningful non-US enrollment.

**DON'T at this level:**
- Don't enable premium escalation routing (~$70/mo on a $140 base = 50% lift for a feature the median learner won't notice yet). Defer until 50K.
- Don't migrate to Voyage embeddings. The +4 MTEB and multilingual lift doesn't move the needle at 2,500 mostly-English users. Stay on `text-embedding-3-small` ($0.02/MTok).
- Don't enable Voyage rerank ($6/mo for +15 Recall@10). Real but tiny absolute saving / quality benefit at this scale.
- Don't negotiate enterprise commits with any vendor. Standard pricing tier rate limits are far above 10K MAU traffic.
- Don't build the integrity-lane router. Anti-cheat false-cave incidents are too rare at 2,500 users to justify the dev work.
- Don't enable Mistral as ultimate-fallback. The procurement / training-opt-out work isn't worth it for a fallback that may never fire.

**The thing to actually watch:** per-course spend in the SOLA cost dashboard. At 10K MAU the heavy users + ELL courses are the only things that produce surprise bills.

### Vector vs RAG at 10K MAU

**No action.** Current SQL-stored JSON embeddings + PHP cosine are fine. Median course ~1,250 vectors / 1024 dims = ~30-50ms retrieval latency in PHP. Well within streaming-chat budget. The two-stage rerank is OFF (per the recommendation above); single-stage cosine is plenty.

---

## 2. Scale at 50K Saylor MAU (~12,500 SOLA users)

### Cost projection

| Component | $/mo |
|---|---:|
| Chat (Gemini 2.5 Flash, blended ESL) | ~$525 |
| Quiz + classifier + analytics (gpt-4o-mini) | ~$111 |
| Anti-cheat reference (Haiku, 5% turns) | ~$16 |
| Embeddings (Voyage-3.5) | <$1 |
| Re-ranker (Voyage rerank-2.5) | ~$31 |
| Judge harness | ~$4 |
| **Baseline subtotal** | **~$690** |
| Premium tier (Opus 4.8, 5% of turns) | ~$350 |
| **Total with premium tier ON** | **~$1,040** |

### Recommendation: turn on the v5.11+v5.12 features, start procurement conversations

50K is the inflection point where the v5.11+v5.12 machinery starts earning back. Multilingual matters (ESL share is meaningful now), failover protection actually saves users from outages, and the premium tier addresses learner complaints on hard STEM courses.

**DO at this level:**
1. **Migrate embeddings to Voyage-3.5.** Four config lines + a $4.80 one-time reindex. +4 MTEB English, 4x context window, materially better multilingual. Pays for itself in ELL courses (30% of mix at this scale).
2. **Enable Voyage rerank-2.5.** ~$31/mo for a published +15 Recall@10 / +39% NDCG lift on BEIR. ~6% of chat spend for a measurable answer-quality improvement.
3. **Wire the failover chain.** `spend_failover_chain` = `chat:openai\nchat:mistral`; `failover_per_call_enabled=1`. At 12,500 SOLA users a Vertex AI outage during EU evening is a real incident; failover keeps it invisible.
4. **Enable premium escalation routing (v5.12) at 5% rate.** Default trigger list is fine. Monitor escalation rate per course via the `[PremiumRouter]` telemetry table. Cost ~$350/mo; worth it for the visible quality lift on STEM courses.
5. **Verify Mistral training-opt-out + ZDR before EU traffic.** External vendor action; flagged in `.drafts/sola-v5.11.0-external-actions.md`. Required if the failover chain is wired (above) and any non-US learners exist.
6. **Set per-course soft caps at $100/mo, admin alert at $150.** Higher than 10K MAU because legitimate course usage grows; still tight enough to catch ELL spikes.
7. **Start the Vertex AI committed-use conversation.** At ~$525/mo Gemini Flash spend, a 1-year commit saves ~$130/mo (25%). Procurement window is 2-4 weeks; start now so the commit is in place by 100K MAU.
8. **Build a Redash dashboard for `cached_tokens` / `cache_read_tokens`.** The v5.11 infrastructure captures both Anthropic and OpenAI cache metrics; visibility is the prereq for tuning at 100K.

**DON'T at this level:**
- Don't pay for OpenAI Tier 4 or Anthropic Tier 3 yet. Standard tier rate limits are still well above 50K MAU traffic. Save the procurement effort for 100K.
- Don't negotiate enterprise discounts at OpenAI / Anthropic yet. Not enough volume to get a meaningful percentage.
- Don't enable per-region routing yet. The 10-15% EU Vertex premium on a small EU share isn't worth the routing complexity. Decide based on actual enrollment mix.
- Don't build the integrity-lane router yet. Still flagged as a v5.12+ feature in the docs but not actually built. At 50K MAU the Haiku-on-suspected-cheat saving is ~$8/mo; deferring is fine.
- Don't compress the system prompt aggressively. The quality-regression risk on a $525/mo line item exceeds the saving.

**The thing to actually watch:** premium-escalation rate per course (target ~5%) and per-region latency distributions (EU learners on us-east-1 see noticeable lag at this scale; that's the trigger for the EU residency decision).

### Vector vs RAG at 50K MAU

**Still no action required, but plan the cutover path.** Per-course retrieval scope keeps PHP cosine load identical to the 10K case (still ~1,250 vectors per query). The Voyage rerank ON change adds an API call but not local compute. Retrieval latency holds at ~30-50ms.

The thing to monitor: max chunks per course. Currently ~1,250 median; if Saylor adds a graduate-level course exceeding ~4,000 chunks (5K pages of source material), THAT course should get its own vector index. Easiest path: install pgvector (if Saylor moves to Postgres) or run a single Qdrant sidecar binary on the existing EC2 and migrate only the oversized course's vectors. Cost: free (pgvector) or ~$0 additional compute (Qdrant in 50MB RAM next to Moodle).

---

## 3. Scale at 100K Saylor MAU (~25,000 SOLA users)

### Cost projection

| Component | $/mo |
|---|---:|
| Chat (Gemini 2.5 Flash, blended ESL) | ~$1,050 |
| Quiz coach (gpt-4o-mini) | ~$85 |
| Anti-cheat reference (Haiku, 5% turns) | ~$33 |
| Mastery classifier (gpt-4o-mini) | ~$105 |
| Analytics + digests + Radar (gpt-4o-mini) | ~$30 |
| Embeddings (Voyage-3.5) | <$1 |
| Re-ranker (Voyage rerank-2.5) | ~$63 |
| Judge harness | ~$4 |
| **Baseline subtotal** | **~$1,370** |
| Premium tier (Opus 4.8, 5% of turns) | ~$700 |
| **Total with premium tier ON** | **~$2,070** |

### Recommendation: pull every lever, procurement included

At 100K the v5.11+v5.12 features are all paying their keep. The additional optimizations are procurement-flavored and worth ~$1.2-1.8k/mo of further saving, bringing the realistic floor to **~$300-870/mo with everything pulled**.

**Procurement levers (highest ROI):**

| Lever | $/mo saving | Effort | Notes |
|---|---:|---|---|
| Vertex AI committed-use discount | ~$260 | 1 phone call | 1-year or 3-year commit at ~$12.6k/yr Gemini Flash spend → 20-25% off |
| Anthropic enterprise commit | ~$70-100 | Procurement | 10-15% off ~$700/mo Opus spend |
| OpenAI enterprise commit | ~$30-45 | Procurement | 10-15% off ~$300/mo specialty-routing spend |
| Voyage enterprise tier | ~$5-10 | Procurement | Confirms SOC 2 Type II + GDPR DPA in writing; required compliance |

Negotiate Vertex / OpenAI / Anthropic Tier 3+ / Tier 4 / Tier 3 simultaneously with the commit discount. 2-4 week procurement window.

**Engineering levers (next highest ROI):**

| Lever | $/mo saving | Effort | Notes |
|---|---:|---|---|
| Anthropic prompt-cache uptake on premium tier | ~$200-300 | Audit + monitor | Plumbing exists (v5.10.x); ensure system prefix is stable across calls in a conversation; target ≥70% `cache_read_tokens` hit rate |
| System prompt compression | ~$200 | 1 day eng + A/B | Trim ~20% from the 3-5k-token system prompt; risk of Socratic-quality regression; smoke on BUS101 + premium-escalation prompts first |
| History window 50 → 30 pairs | ~$100 | 1 setting | Drops `prompt_history_pairs`; learners lose deep-context recall after 30 turns (rare in practice) |
| OpenAI auto-prefix cache hit-rate tuning | ~$50 | Redash query + prompt audit | v5.11 captures `cached_tokens`; if hit rate is low, stabilize the system prefix order |
| OpenAI Batch API for analytics | ~$15 | 1 day eng | 50% off on top of normal rates; weekly digests + Learning Radar already run on schedule |
| Per-region routing (US-East default, EU only for confirmed-EU) | ~$15-25 | 1 day eng | Routes by learner profile country; EU Vertex is 10-15% pricier |

**Product-policy levers (biggest absolute lever):**

| Lever | $/mo saving | Effort | Notes |
|---|---:|---|---|
| Heavy-user soft cap (top 10% drive 50-70% volume) | ~$300-500 | Product policy | Cap at e.g. 200 messages / SOLA-learner / month with admin-override; not technical, needs enrollment-team alignment on fair-use definition |

**Total recoverable on top of the $2,070 baseline: ~$1,200-1,800/mo.**

**DO at this level:**
1. All the v5.11+v5.12 features ON: classifier routing, Voyage embeddings, Voyage rerank, premium escalation (5% rate), failover chain populated + enabled, cached_tokens dashboards.
2. Call the GCP account rep this week. Vertex committed-use is the single largest no-risk saving.
3. Build the Redash cache-hit dashboard. The infrastructure is there; visibility on Anthropic + OpenAI cache hit rates tells you whether the auto-discounts are firing.
4. Run the system-prompt-compression A/B on BUS101. Highest tech-only saving with a manageable risk profile.
5. Decide the heavy-user policy with the Saylor enrollment team. Not technical, but biggest absolute lever.
6. Open Anthropic + OpenAI enterprise-commit conversations in parallel with the GCP call. Bundle the three into one procurement quarter.
7. Enable per-region routing (US-East default, EU-routed by learner country).
8. Migrate Learning Radar + weekly digests to the OpenAI Batch API.

**DON'T at this level:**
- **Don't raise premium escalation above 5% without per-course data.** At 10% escalation Opus 4.8 alone is $1,400/mo (more than baseline). Raise only with evidence from a per-course retention or completion A/B.
- **Don't move chat off Gemini 2.5 Flash.** The 06-04 domain benchmark shows it wins every category; per-call cost is the bottleneck at 100K MAU.
- **Don't enable Voyage rerank without a SOLA-fixture RAG benchmark.** The +15 Recall@10 published lift is on enterprise corpora, not Saylor's OER content. Build the fixture (30-50 real BUS101 / PHIL101 questions with expected-passage labels) before paying for rerank at scale.
- **Don't switch embeddings vendor mid-flight.** Voyage migration needs a clean cutover and a full reindex; mixed-vendor states work but degrade retrieval until reindex completes.
- **Don't pay for Pinecone or other managed vector DBs.** pgvector or a Qdrant sidecar is free at SOLA scale.
- **Don't underfund the next bets to shrink the bill.** The $2k/mo stack is fine for Saylor. Optimize to fund the premium-tier expansion, talking-avatar pilot, or voice-mode opt-in for ELL cohorts.

### Vector vs RAG at 100K MAU

**SQL-stored embeddings still hold the per-course retrieval load** (~1,250 vectors / query / course; ~30-50ms PHP cosine; under 50 concurrent RAG-backed turns is well within current capacity). v5.11's two-stage rerank does NOT change this (Voyage rerank is an API call, not local compute).

**The trigger for a real vector DB at 100K is feature scope, not user count:**

1. **Cross-course semantic search.** If a future release wants "show me anywhere in any Saylor course where this concept appears" via semantic match (not just the pre-computed objective links shipped in v5.7), you have to search ~200K total corpus vectors. PHP cosine can't do that at chat-streaming latency. **pgvector with HNSW index = 1-5ms queries** at this corpus size.

2. **Single course exceeding ~4,000 chunks.** Graduate-level courses with 5k+ pages of source material cross this on their own. PHP cosine exceeds 200ms; learners feel the lag. Migrate just that course to a vector index.

3. **Real-time semantic UI features.** Anything that needs <50ms retrieval (e.g. as-you-type concept suggestions, live citation surfacing, related-concept widgets). PHP cosine on JSON-loaded vectors can't do <50ms reliably.

**When the trigger fires, the migration path:**

- **If Saylor moves to Postgres:** `pgvector` extension + HNSW index. Free, in-database, no new ops surface. Single `ALTER TABLE local_ai_course_assistant_chunks ADD COLUMN embedding_vec vector(1024)` + a `CREATE INDEX USING hnsw` migration. SOLA's existing chunk-loading code becomes `SELECT ... ORDER BY embedding_vec <=> $queryvec LIMIT 50` in SQL. Best path.
- **If staying on MySQL:** MySQL 9.0+ has native `VECTOR` type with HNSW but it's less mature; alternatively run a Qdrant sidecar binary (~50MB RAM) on the existing Moodle EC2. Self-hosted Qdrant at SOLA scale = ~$0 marginal compute.
- **Don't reach for Pinecone / managed vector DBs.** $70-300/mo for a problem you can solve with a free extension. The latency win over self-hosted is real but not at SOLA's query volumes.

**Today's action at 100K:** none. Add two metrics to the Redash dashboard so the trigger doesn't sneak up:
- `MAX(chunks per course)` — alert if any course exceeds 4,000.
- P95 retrieval latency (the `rag_retrieval_ms` field captured in `sse.php`) — alert if it exceeds 150ms.

---

## 4. Migration triggers (when to move from one tier to the next)

| From | To | Trigger | Effort window |
|---|---|---|---|
| 10K | 50K | Active SOLA users sustained above ~10k/month for 30 days | 2 weeks to enable v5.11+v5.12 features + start GCP commit conversation |
| 50K | 100K | Active SOLA users sustained above ~20k/month for 30 days, OR Vertex Tier 3+ rate-limit headroom drops below 30% during peak hour | 4 weeks for procurement + 1 week for engineering optimizations (prompt compression A/B, history window tune, per-region routing) |
| Any | Vector DB | Single course exceeding 4,000 chunks, OR cross-course semantic search becomes a roadmap item, OR P95 retrieval latency above 150ms | 1-2 weeks (pgvector if Postgres; Qdrant sidecar if MySQL) |

The 10K → 50K transition is mostly turning ON the v5.11+v5.12 machinery that's already built (Voyage migration, rerank enable, premium tier on, failover chain). The 50K → 100K transition is mostly procurement (committed-use discounts) plus a small set of engineering optimizations (prompt compression, history window, per-region routing, Batch API for analytics).

---

## 5. Procurement timeline by tier

| Tier | Vendor | Action | Lead time |
|---|---|---|---|
| 50K | Google Vertex AI | Start committed-use conversation | 2-4 weeks |
| 50K | Mistral La Plateforme | Training opt-out + ZDR (if EU failover lane in use) | 1-2 weeks |
| 100K | Google Vertex AI | Sign 1-year or 3-year commit at projected volume | 2-4 weeks |
| 100K | OpenAI | Tier 4 + enterprise volume discount | 2-3 weeks |
| 100K | Anthropic | Tier 3 + enterprise volume discount | 2-3 weeks |
| 100K | Voyage AI | Enterprise tier + SOC 2 Type II + GDPR DPA in writing | 2-4 weeks |

Start the GCP call at 50K — Vertex Flash is the largest line item at every tier above 10K, and the lead time is the longest. Bundle Anthropic + OpenAI + Voyage into one procurement quarter at 100K.

---

## 6. What to NOT do at any scale

- **Don't move primary chat off Gemini 2.5 Flash.** It wins every category on the 06-04 domain benchmark; per-call cost is the constraint at scale.
- **Don't use gpt-4o as a premium escalation candidate.** A.10 bake-off scored it last on hard prompts (12.68/15, Socratic discipline collapses to 2.70/5). Opus 4.8 is the right escalation target.
- **Don't enable real-time chat to Gemini 2.5 Pro.** Pareto-equal to Opus on quality and much cheaper, but 11.5s P50 TTFT breaks the streaming UX. Reserve for async / Learning Radar / background analysis.
- **Don't keep xAI in any production chain.** SOC 2 Type II still unverifiable per rev. 3 of the multi-provider plan. Stays in `comparison_providers` for benchmarking only.
- **Don't enable the OpenAI Realtime voice tab or talking-avatar features in the universal rollout.** Per the 2026-06-04 scope cut; sits in Appendix B of the companion doc for a future opt-in.
- **Don't pay for managed vector DBs (Pinecone, Weaviate Cloud) at any SOLA scale.** pgvector or self-hosted Qdrant covers the entire range.

---

## 7. Single-page decision summary

| Question | Answer |
|---|---|
| Recommended primary chat (all tiers) | Gemini 2.5 Flash on Vertex AI |
| Recommended quiz / classifier / analytics (all tiers) | OpenAI gpt-4o-mini |
| Recommended embedding provider | OpenAI text-embedding-3-small at 10K; **migrate to Voyage-3.5 at 50K**; stay on Voyage at 100K |
| Two-stage RAG with rerank | OFF at 10K; **ON at 50K**; ON at 100K |
| Premium escalation tier (Opus 4.8 via v5.12 router) | OFF at 10K; **ON at 5% at 50K**; ON at 5% at 100K (raise to 10% only with per-course data) |
| Failover chain | Empty at 10K; **populated + enabled at 50K**; same at 100K |
| Vendor enterprise commit | None at 10K; **start GCP at 50K**; bundle GCP + OpenAI + Anthropic + Voyage at 100K |
| Vector DB cutover | No action; trigger metrics (max chunks per course; P95 retrieval latency); pgvector or Qdrant when triggered |
| Total $/mo with recommended config | ~$140 (10K), ~$1,040 (50K), ~$2,070 (100K); further ~$1.2-1.8k/mo recoverable at 100K via procurement + prompt compression + heavy-user policy |
| Compliance | All recommended vendors TIER 1 (SOC 2 Type II + GDPR DPA + no-training-by-default). FERPA out of scope; Saylor is tuition-free |

---

## Sources

- `.drafts/sola-vendor-recommendations-2026-06-09.md` — the canonical vendor recommendations doc; this report inherits its baseline assumptions
- `.drafts/sola-a10-premium-escalation-bakeoff-2026-06-09.md` — A.10 bake-off resolution; Opus 4.8 verdict
- `.drafts/sola-v5.11.0-external-actions.md` — external procurement actions punch list
- `.wiki/Changelog.md` v5.11.0 + v5.12.0 entries — what shipped to dev on 2026-06-09
- `classes/premium_router.php` — v5.12.0 routing logic + default trigger set
- `classes/embedding_provider/voyage_*.php` — v5.11.0 Voyage embedding + rerank providers
- `classes/rag_retriever.php` — current SQL-stored JSON embedding retrieval path
