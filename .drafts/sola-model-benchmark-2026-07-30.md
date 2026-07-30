# SOLA model benchmark — Claude 5 family vs. incumbents (2026-07-30)

Natural-language re-run of the chat-tier evaluation, extending the 2026-07-24 bake-off to include the newly released **Claude Opus 5** and **Claude Sonnet 5**, plus a re-measurement of the retrieval layer (embeddings, reranker, RAG pipeline). Run against deployed v6.9.4 on dev.

**Bottom line: Opus 5 is not worth adopting anywhere in SOLA's current workload. Gemini 2.5 Flash stays the chat primary. The one clear upgrade available is Claude Haiku 4.5 for the quiz coach. On the retrieval side, keep OpenAI embeddings, keep parent-document retrieval off, and enable the reranker per course at pool 20 rather than site-wide.**

## Method

- **Prompts:** the 50-prompt golden tutor set (`tests/golden/tutor_prompts.json`) — real natural-language learner messages, tagged by SOLA function: socratic explanation, quiz coach, illustration, anti-cheat, multilingual (10 each).
- **Harness:** `admin/cli/run_tutor_golden.php --mode=all`, judged by Claude Sonnet 4.6 on a 15-point rubric (Socratic guidance / factual accuracy / tone match). The judge is deliberately outside the contestant pool.
- **Scale:** 5 models x 50 prompts = **250 calls, 0 errors**, plus 250 judge calls.
- **Cost figures** come from the live rate card (`rate_card_overrides`, auto-refreshed from the LiteLLM manifest), verified current for all five models.
- **Total API spend: roughly $2.50** for the chat benchmark, plus about $0.20 across the retrieval runs.
- **Retrieval:** `admin/cli/run_rag_fixture_benchmark.php` against the live dev index for BUS101 and POLSC101, in two modes — recall against ground-truth chunk ids, and LLM-judged relevance. Two query styles: 40 human-written terse colloquial queries and 40 synthetic multi-sentence conversational questions.

## Results — overall

| Model | Rubric /15 | Cost (cents/call) | P50 TTFT | P95 TTFT | P50 total | Pareto |
|---|---|---|---|---|---|---|
| claude-sonnet-5 | **14.44** | 0.366 | 1740 ms | 6018 ms | 5688 ms | yes |
| claude-opus-5 | 14.42 | **2.273** | 6317 ms | 16454 ms | 14214 ms | no |
| gemini-2.5-flash | 14.16 | 0.052 | 2272 ms | 5967 ms | 3108 ms | yes |
| claude-haiku-4-5 | 14.10 | 0.115 | **612 ms** | 1412 ms | 3153 ms | no |
| gpt-4o-mini | 12.86 | **0.012** | 461 ms | 892 ms | 2698 ms | yes |

## Results — by SOLA function

| Model | Socratic | Quiz coach | Anti-cheat | Multilingual | Illustration |
|---|---|---|---|---|---|
| claude-sonnet-5 | **15.00** | 14.50 | 14.10 | **14.70** | 13.90 |
| claude-opus-5 | 14.40 | 14.70 | 14.30 | **14.70** | **14.00** |
| gemini-2.5-flash | 14.40 | 14.30 | **14.50** | 14.40 | 13.20 |
| claude-haiku-4-5 | 13.90 | **14.80** | **14.50** | 13.80 | 13.50 |
| gpt-4o-mini | 12.10 | 13.90 | 13.20 | 13.10 | 12.00 |

## What the data says

**1. Opus 5 does not earn its place.** It never wins a category by a meaningful margin, yet costs **6.2x Sonnet 5** and **44x Gemini 2.5 Flash**, and is the slowest model tested by a wide margin — 14.2 seconds median end-to-end versus Gemini's 3.1. For a learner waiting on a tutor reply, that latency is a product problem independent of cost. Opus 5 is an excellent model; SOLA's tutoring workload simply does not have a task hard enough to need it.

**2. Quality has converged at the top.** Four of five models land between 14.10 and 14.44 — a 0.34-point spread on a 15-point rubric, which is inside the noise of a single-pass judge. The meaningful differences in this table are **cost and latency**, not quality. That is a change from earlier bake-offs where quality genuinely separated the field.

**3. Gemini 2.5 Flash remains the right chat primary.** 14.16 rubric at 0.052 cents/call. Sonnet 5 buys 0.28 rubric points for 7x the cost. No change recommended.

**4. The real upgrade is the quiz coach — and it was never on the model we documented.** The vendor docs say quiz coaching runs on gpt-4o-mini. It does not. `generate_quiz.php` called `base_provider::create_from_config()`, which resolves the **chat** provider, and there was no quiz-specific setting to override it. On the dev fleet that means quiz generation has been running on **Gemini 2.5 Flash**, not gpt-4o-mini. The documented recommendation was never implementable.

**Haiku 4.5 wins quiz coach outright at 14.80** — ahead of Gemini's 14.30 (what actually runs today) and gpt-4o-mini's 13.90 (what we thought ran) — with the best latency in the field (612 ms TTFT). PR #165 adds the missing `quiz_provider` / `quiz_model` settings so the switch is possible at all.

**5. Sonnet 5 is the standout on Socratic explanation** — a clean 15.00/15, the only perfect category score in the run. That is SOLA's core tutoring behaviour, and it is worth noting even though the cost still favours Gemini.

## Recommendations

| Function | Current | Recommendation |
|---|---|---|
| Chat tutor | Gemini 2.5 Flash | **Keep.** Best cost/quality; re-confirmed. |
| Quiz coach | Chat provider (Gemini 2.5 Flash on dev) — see note | **Switch to Haiku 4.5** — wins the category (14.80 vs 13.90), best latency, still very cheap. Requires PR #165. |
| Anti-cheat / integrity | Haiku 4.5 | **Keep.** Ties for the category win (14.50). |
| Premium escalation | Opus 4.8 | **Evaluate Sonnet 5** — but not on this evidence; see caveats. |
| Chat failover | gpt-4o-mini | **Keep.** Weakest on quality but cheapest and the most compliant model in the 2026-07-24 jailbreak run (98%). |
| Embeddings | OpenAI text-embedding-3-small | **Keep.** Voyage-3.5 is no better and slightly behind on every judged metric. |
| Reranker | Voyage rerank-2.5, off by default; `rerank_candidates=50` on prod | **Enable per course, at pool 20.** Cost is a non-issue (~$65/mo at 20k users). Gain tracks baseline weakness: +10 to +24 pp on weak courses, negative on strong ones. |
| Parent-doc retrieval | Off by default | **Keep off.** Cuts judged precision@5 from 0.695 to 0.435. |

## Retrieval — embeddings, reranker, RAG

The chat benchmark above measures the model that writes the answer. This section measures the retrieval layer that decides what the model sees. Both were re-run on dev against the live index (BUS101 + POLSC101, 40 fixtures each).

### Embeddings: OpenAI vs Voyage

**Voyage is measurably slightly worse on our corpus. Keep OpenAI.** This now has two independent confirmations.

The OpenAI recall arm reproduced the 2026-07-08 baseline **exactly**, confirming the harness and index are stable.

Recall mode, curated queries (ground-truth chunk ids, no judge involved):

| Arm | Model | R@1 | R@3 | R@5 | MRR |
|---|---|---|---|---|---|
| openai | text-embedding-3-small | **32.5%** | **55.0%** | 65.0% | **0.468** |
| voyage | voyage-3.5 | 30.0% | 52.5% | **67.5%** | 0.451 |

Judge mode, conversational queries (LLM-scored relevance):

| Arm | Model | nDCG@5 | P@5 | hit@5 | Mean relevance |
|---|---|---|---|---|---|
| openai:3-small | text-embedding-3-small | **0.951** | **0.700** | 1.000 | **1.89** |
| voyage:3.5 | voyage-3.5 | 0.943 | 0.665 | 1.000 | 1.84 |

OpenAI leads on both scoring methods; Voyage edges R@5 by a single fixture, exactly as in the 2026-07-08 A/B. The gaps are near the noise floor at this sample size, so the honest reading is **"no better, possibly slightly worse"** rather than "clearly worse." Either way there is no case for migrating — and OpenAI is also the incumbent, cheaper ($0.02 vs $0.06 per 1M), and needs no re-index or new privacy path.

**Correction.** An earlier version of this report claimed `--voyage-apikey` was ignored by the recall path, inferred from the Voyage recall arm failing authentication. That was wrong. The harness handles the key correctly in both modes; the failure was a shell-quoting error in the run script. Re-run cleanly, the recall arm produces the numbers above. No harness fix is needed.

### Reranker: Voyage rerank-2.5

This is the finding that holds up. Two-stage retrieval (embed a pool of 30, rerank to top-10) was measured on both query styles.

| Query set | Metric | Embedding only | With rerank-2.5 | Delta |
|---|---|---|---|---|
| Curated colloquial (40) | R@3 | 55.0% | **72.5%** | **+17.5 pp** |
| | R@5 | 65.0% | 82.5% | +17.5 pp |
| | MRR | 0.468 | 0.577 | +0.109 |
| Synthetic conversational (40) | R@3 | 72.5% | **85.0%** | **+12.5 pp** |
| | R@5 | 77.5% | 95.0% | +17.5 pp |
| | MRR | 0.646 | 0.745 | +0.099 |

The +17.5 pp headline from the 2026-06-11 benchmark **reproduces exactly**, and the lift survives the switch to realistic multi-sentence questions. Added latency is modest: P50 238 ms on curated, 273 ms on conversational. Measured cost is roughly **$0.001 per query** ($0.00097 curated, $0.00109 conversational). See the scale projection below for what that means in monthly terms against real usage — it is far less than earlier planning docs assumed.

**Where it is weaker than the headline suggests.** The gain is not uniform, and two results argue for keeping it opt-in:

- **It can hurt the top slot.** On POLSC101 (curated), reranking moved R@1 *down* 60.0% to 50.0% and MRR down 0.704 to 0.654, while still improving R@3. The reranker reorders a good pool well but is not strictly better at rank 1.
- **Independent judging shows far less lift.** Scored by an LLM judge on relevance rather than against a ground-truth chunk, baseline and rerank are nearly tied — nDCG@5 0.952 vs 0.969 — and precision@5 slightly *declines* (0.695 to 0.660). Recall@k rewards surfacing one specific chunk; the judge asks whether the whole returned set is useful. Rerank clearly wins the first framing and roughly ties on the second.

**These 40-fixture results are superseded by the 1,008-query sweep below**, which covers 16 courses instead of two and lands on a different recommendation (per-course enablement at pool 20). They are kept here because they reproduce the 2026-06-11 benchmark exactly, which is what establishes that the harness and index are stable.

### Full pipeline comparison (judged)

The judged run also scored the other retrieval configurations, which is the clearest view of how the pieces interact:

| Pipeline arm | nDCG@5 | P@5 | hit@5 | Mean relevance |
|---|---|---|---|---|
| baseline (embedding only) | 0.952 | **0.695** | 1.000 | **1.89** |
| rerank only | **0.969** | 0.660 | 1.000 | 1.83 |
| parent-doc only | 0.951 | 0.435 | 1.000 | 1.15 |
| full: rerank + parent (window) | **0.969** | 0.450 | 1.000 | 1.22 |
| full: rerank + parent (page) | 0.965 | 0.450 | 1.000 | 1.21 |

**The notable result is parent-document retrieval.** It cuts precision@5 by more than a third (0.695 to 0.435) and mean relevance from 1.89 to 1.15, while nDCG barely moves. That is the expected shape — expanding a matched chunk to its parent document pulls in surrounding material that is topically adjacent but not what was asked — but the size of the drop is larger than the feature's framing suggests. Enabling rerank alongside it does not recover the loss.

Parent-doc retrieval is **off by default**, and this run supports keeping it that way. If it is ever turned on for a specific course, it should be measured on that course rather than assumed neutral.

Two caveats on this table. `hit@5` is 1.000 for every arm, meaning at least one relevant chunk always appeared in the top 5 — the corpus is small and the questions are on-topic, so this metric is saturated and carries no signal here. And the judge is gpt-4o-mini, the weakest model in the chat benchmark above; a stronger judge would make these comparisons more trustworthy.

### On the realism of the query set

Worth stating plainly, because it changes how to read the numbers above. The synthetic conversational questions are **easier than the curated ones, not harder**. Baseline R@3 is 72.5% on the synthetic set versus 55.0% on the curated set, and target-chunk cosine is much higher (mean 0.638 vs 0.486).

The reason is leakage: each synthetic question was generated *from* its target chunk, so it inherits that chunk's vocabulary. That makes it easy to retrieve. Real learners do not phrase questions using the source material's wording — the curated set, written as terse colloquial openers ("what even is a business? like why do we need them"), is the more honest proxy for production difficulty.

So the two sets bracket the problem usefully: **treat the curated numbers as the conservative estimate and the conversational ones as the optimistic bound.** Both agree the reranker helps; they disagree only on how much.

One reassurance from both runs: with `rag_min_similarity` at 0.25, **0 of 40 target chunks fell below the production floor** on either set. The floor is not silently dropping good material.

## The 1,008-query sweep — 16 courses, four pool sizes

The 40-fixture results above are small-sample and cover two courses. This run scales the question up: **1,008 synthetic conversational questions across all 16 indexed courses** (63 per course), scored in recall mode against ground truth, at four `rerank_candidates` values.

The larger, more diverse sample moves the baseline up sharply — embedding-only R@3 is **79.2%** here versus 55.0% on the curated two-course set — which is the more trustworthy figure for what production retrieval actually does.

| Pool | R@1 | R@3 | R@5 | MRR | Delta R@3 | Rerank P50 | Cost/query |
|---|---|---|---|---|---|---|---|
| Embedding only | 55.3% | 79.2% | 86.8% | 0.687 | — | — | — |
| 30 | **72.7%** | **89.3%** | **93.6%** | **0.817** | **+10.1 pp** | 266 ms | $0.00112 |
| **20** | 72.3% | 89.0% | 93.5% | 0.813 | +9.8 pp | 242 ms | **$0.00075** |
| 10 | 71.7% | 87.0% | 90.6% | 0.798 | +7.8 pp | 198 ms | $0.00037 |

**Pool 20 is the sweet spot.** It gives up 0.3 pp of R@3 and 0.4 pp of R@1 against pool 30 — inside noise — for a **33% cost reduction**. Pool 10 is where real degradation starts: −2.3 pp R@3 and −3.0 pp R@5.

Since production is configured at `rerank_candidates=50`, the concrete recommendation before any enable is to **drop it to 20**: indistinguishable quality from 30, and 2.5x cheaper than 50.

**Pool 5 could not be tested.** The CLI clamps the flag with `max(10, ...)` at `run_rag_fixture_benchmark.php:88`, so `--candidates=5` silently ran as 10 and produced byte-identical results (verified: `candidates_n=10` and the same 7,539,834 rerank tokens in both output files). The clamp is silent — no warning — which is worth fixing so a future sweep does not quietly report a value it never ran. Pool 5 remains an open question, though the shape of the curve suggests it would lose more than it saves.

Note the lift here (+10.1 pp) is smaller than the +17.5 pp measured on the curated set. That is expected: the baseline is far higher, so there is less headroom. Reranking is not fixing 17 points of failure — it is fixing about 10.

### Rerank helps most where the embedding baseline is weakest

Per-course results at pool 30 make the pattern unmistakable:

| Course | Baseline R@3 | With rerank | Delta |
|---|---|---|---|
| course130 | 71.4% | 95.2% | **+23.8 pp** |
| course128 | 76.2% | 92.1% | +15.9 pp |
| course132 | 69.8% | 85.7% | +15.9 pp |
| course117 | 65.1% | 81.0% | +15.9 pp |
| course43 | 58.7% | 74.6% | +15.9 pp |
| course3 | 79.4% | 93.7% | +14.3 pp |
| course116 | 74.6% | 87.3% | +12.7 pp |
| course129 | 77.8% | 88.9% | +11.1 pp |
| course11 | 77.8% | 87.3% | +9.5 pp |
| course115 | 85.7% | 95.2% | +9.5 pp |
| course2 | 82.5% | 90.5% | +7.9 pp |
| course149 | 76.2% | 82.5% | +6.3 pp |
| course8 | 85.7% | 90.5% | +4.8 pp |
| course4 | 93.7% | 95.2% | +1.6 pp |
| course131 | 93.7% | 93.7% | 0.0 pp |
| course7 | 98.4% | 95.2% | **-3.2 pp** |

Reranking helped 14 of 16 courses, was neutral on one, and **hurt the single course whose embedding recall was already 98.4%**. The correlation is strong and intuitive: when the embedding stage already puts the right chunk in the top 3, a reranker can only shuffle it out.

**This argues for enabling rerank per course rather than site-wide.** Courses with weak baseline recall (below ~80%) gain 10-24 pp; courses already above ~93% gain nothing and risk a small loss. The per-course override machinery already exists. A sensible policy is to measure baseline recall per course at index time and switch reranking on only where it is below threshold — which also cuts the bill, since the strong courses are skipped.

This also resolves the earlier POLSC101 anomaly, where reranking dropped R@1 from 60% to 50%: POLSC101 is course 7, the highest-baseline course in the catalog. That was not noise; it was the same effect at small sample size.

## Scale projection — 162 production courses, 20,000 SOLA users

The retrieval numbers above are quality measurements on two courses. This section projects what the retrieval layer costs and stores across the full production catalog.

### Corpus size

Measured on the dev index, which carries real Saylor course content: **16 courses, 10,895 chunks, 30.6M characters**. Per course that is 681 chunks and 1.91M characters, with an average chunk of 2,809 characters (~702 tokens). The distribution is wide — 108 chunks at the small end, 1,998 at the large end — but median (628) and mean (681) are close, so the mean projects reasonably.

| Quantity | Per course | 162 courses |
|---|---|---|
| Chunks | 681 | **110,300** |
| Characters | 1.91M | **310M** |
| Tokens | 478k | **77.5M** |
| One-time full index (embedding) | $0.01 | **$1.55** |

**Indexing cost is a non-issue.** Embedding the entire production catalog with `text-embedding-3-small` costs about **$1.55**, one time. Re-indexing after a content update costs the same per course, i.e. fractions of a cent. This should never be a factor in any decision.

**Storage is worth a look.** Embeddings are currently stored as JSON text at 29,454 bytes per chunk, which projects to **3.25 GB** of database for the full catalog.

| Storage format | 162 courses |
|---|---|
| JSON text, 1536 dims (as stored today) | 3.25 GB |
| float32 binary, 1536 dims | 0.68 GB |
| float32 binary, 512 dims (MRL truncation) | 0.23 GB |

A binary column would cut this roughly 5x, and MRL truncation to 512 dimensions about 14x. Neither is urgent at 3 GB, but both are worth knowing before the catalog grows or before anyone proposes moving the index into a managed vector store priced per GB.

### Query volume — measured on production

**These are real numbers, pulled from the production databases on 2026-07-30**, not modeled. Read-only aggregate queries against `learn.saylor.org` and `degrees.saylor.org` via the existing Redash data sources.

| July 2026 (near-complete month) | learn | degrees | combined |
|---|---|---|---|
| SOLA users | 1,139 | 81 | **1,220** |
| Learner turns | 5,640 | 544 | **6,184** |
| **Turns per user per month** | 4.95 | 6.72 | **5.07** |
| Courses with activity | 24 | 4 | 28 |
| Busiest single day | 254 turns | 53 turns | 307 |

All-time to date: learn 1,459 users / 7,021 turns; degrees 100 users / 670 turns.

**The vendor doc's capacity assumption is 13.8x too high.** It models 58,000 turns/day at 25,000 SOLA users, i.e. ~70 turns per user per month. Actual observed usage is **5.07**. Every volume-derived figure in that doc should be re-checked against this.

Engagement is heavily skewed, which the mean hides:

| July cohort (learn) | Users | Share |
|---|---|---|
| Exactly 1 turn | 349 | 31% |
| 10 or more turns | 142 | 12% |
| 25 or more turns | 33 | 3% |
| Heaviest single user | 51 turns | — |

Roughly a third of learners try SOLA once and stop; a small core drives most of the volume.

**Production config, confirmed:** `rag_enabled=1` on both sites (so retrieval volume does equal turn volume today), `rerank_enabled=0` on both, and `rerank_candidates=50` — note that is 50, not the 30 used on dev and in the benchmark.

### Projected cost at 20,000 SOLA users

Applying the measured 5.07 turns/user/month:

| Quantity | Value |
|---|---|
| Chat turns / month | **101,400** |
| Chat turns / day | 3,379 |
| Peak load (observed peak scaled by user growth) | ~5,000/day, **~7/minute** |

| Component | Monthly cost |
|---|---|
| Query embedding | **$0.04** |
| Rerank, pool 50 (prod's configured value) | **$163** |
| Rerank, pool 30 (dev / benchmark value) | **$98** |
| Rerank, pool 20 | $65 |
| Rerank, pool 10 | $33 |

**Correction to an earlier version of this report.** A previous draft projected reranking at **$1,346/month** and concluded it "does not scale." That was wrong. It applied the measured per-query cost to the vendor doc's inflated turn volume rather than to observed usage. With real volume, reranking costs **about $98-163/month at 20,000 SOLA users** — roughly 7% of the projected text baseline, not 100% of it. The per-query cost was right; the volume it was multiplied by was not.

The per-query economics still hold and are worth recording: 773,748 rerank tokens across 40 queries is **19,344 tokens per query at pool 30** ($0.000967), because our chunks average ~702 tokens. Pool size remains a linear cost lever — prod's 50 costs 1.7x dev's 30 — but at observed volume the absolute numbers are small enough that **quality, not cost, should decide the pool size.**

Retrieval is not where the money goes. Chat generation is.

### Operational sizing

**Index build time.** Measured throughput is 984 chunks in 7.6 seconds, i.e. **~129 chunks/second**. A full cold index of all 162 courses is therefore about **14 minutes** of wall clock, and a single course re-index is roughly 5 seconds. Reindexing is cheap in both time and money; there is no reason to avoid it after a content update.

**Rate limits at peak.** At the projected ~7 queries/minute peak, both the embedding and rerank calls sit far inside normal paid-tier limits (OpenAI Tier 2+ allows thousands of RPM for embeddings; Voyage pay-as-you-go is comfortable at this rate). The vendor doc's advice to negotiate capacity tiers applies to the **chat** tier, not retrieval — retrieval is not where the rate-limit risk lives.

**Latency budget.** Embedding query P50 is 207 ms (P95 428 ms); reranking adds a further 238-273 ms P50. A rerank-enabled turn therefore spends roughly **0.5 seconds in retrieval before the model starts generating**. Against Gemini 2.5 Flash's 2,272 ms P50 time-to-first-token, that is a ~20% increase in perceived wait — noticeable but not disqualifying. It matters more if a faster chat model is ever adopted.

### What does not change with scale

Retrieval is **scoped per course**, so a learner's query is compared against roughly 681 chunk vectors, not 110,300. Adding courses to the catalog does not slow retrieval or degrade recall; it only adds storage. The measured P50 of ~200-320 ms should hold at 162 courses just as it does at 16.

## Caveats — what this run does *not* establish

These matter, and they bound how far the recommendations should be pushed.

- **No compliance testing.** This run measured quality, cost and latency only. Compliance was the *deciding factor* in the 2026-07-24 benchmark (Gemini 2.5 Flash 86%, gpt-4o-mini 98%). **Sonnet 5 and Opus 5 have not been jailbreak-tested against the v6.9.4 hardened prompt.** No learner-facing switch to either should happen before a 3-run jailbreak pass.
- **The premium-escalation question is unanswered.** The premium router targets hard multi-step STEM prompts. This golden set is general tutoring, so it does not exercise that workload. Sonnet 5 looks promising as a cheaper premium tier, but that needs the 40-prompt domain set (`tutor_prompts_domains.json`) or the original A.10 hard-prompt corpus before acting.
- **Structured-output functions untested.** The mastery classifier, analytics digests and Soapbox speech scoring are JSON/rubric tasks, not conversational. gpt-4o-mini's weak conversational showing does not imply weakness there, and prior analysis found that function saturated.
- **Single pass.** One run per prompt, one judge pass. The 2026-07-24 work averaged 3 runs to smooth judge variance; treat sub-0.5-point gaps as ties.
- **The retrieval judge is gpt-4o-mini.** Every judged retrieval number above was scored by the weakest model in the chat benchmark. The recall-mode numbers do not depend on it (they use ground-truth chunk ids), but the nDCG / precision comparisons do. Re-running the judge on Sonnet 4.6, as the chat benchmark does, would firm them up.
- **Retrieval quality is now measured on 16 courses** (1,008 queries), which supersedes the earlier two-course caveat. The remaining gap is that all 16 are dev-indexed courses; the other ~146 production courses are assumed similar in shape but were not measured.
- **Pool 5 was never tested** — the CLI silently clamps `--candidates` to a floor of 10.
- **Production usage is early.** The 5.07 turns/user figure comes from a pilot across 28 courses and 1,220 learners. Early adopters may not represent a full rollout in either direction, and the 31%-single-turn skew suggests onboarding, not capacity, is the live problem.
- **Gemini cost carries a question mark.** The rate card prices Gemini 2.5 Flash at $0.30/$2.50 per MTok; the 2026-07-24 doc used $0.10/$0.40. If the lower figure is correct, Gemini is ~3x cheaper still — the direction of the error only strengthens the recommendation, but the absolute figure should be confirmed.

## Suggested next steps

1. **Jailbreak-test Sonnet 5 and Opus 5** (3 runs each) against the v6.9.4 prompt — required before any learner-facing change.
2. **Run the domain set** (math / science / cs_tech) with Sonnet 5 vs Opus 4.8 vs Opus 5 to settle the premium-escalation tier properly.
3. **Switch the quiz coach to Haiku 4.5** — the best-supported change in this run. Needs PR #165 merged first.
4. **Confirm the Gemini rate** against Google's current list price.
5. **Re-run the retrieval judge on Sonnet 4.6** instead of gpt-4o-mini, so the rerank and parent-doc comparisons rest on the same judge quality as the chat benchmark.
6. **Re-check every volume-derived figure in the vendor doc.** Its 70-turns-per-user assumption is 13.8x the measured 5.07, so any cost, capacity-tier or rate-limit conclusion drawn from it needs revisiting.
7. **Drop `rerank_candidates` from 50 to 20 on prod** before any rerank enable — same quality as 30, 2.5x cheaper than 50.
8. **Gate rerank per course on baseline recall** rather than site-wide; it is negative on courses already above ~93%.
9. **Fix two harness papercuts:** the silent `--candidates` floor of 10, and passing API keys as CLI arguments (visible in the process table to any user on the box; prefer env var or config).

## Appendix — reproducing

```
php admin/cli/run_tutor_golden.php --mode=all --delay=0.3 \
  --providers=claude:claude-opus-5,claude:claude-sonnet-5,claude:claude-haiku-4-5,gemini,openai \
  --judge-provider=claude --judge-model=claude-sonnet-4-6 --out=/tmp/golden
```

Requires the Claude 5 temperature deny-list fix (PR #163) — without it every Opus 5 / Sonnet 5 call returns HTTP 400, because those models reject sampling parameters.

Retrieval runs:

```
# Rerank lift, curated colloquial queries
php admin/cli/run_rag_fixture_benchmark.php \
  --fixtures=tests/golden/rag_fixtures_bus101_pol101.json \
  --candidates=30 --topk=10 --voyage-apikey=<key> --out=/tmp/rerank.json

# Rerank lift, synthetic conversational queries (recall mode)
php admin/cli/run_rag_fixture_benchmark.php \
  --fixtures=tests/golden/rag_fixtures_conversational_recall_bus101_pol101.json \
  --candidates=30 --topk=10 --voyage-apikey=<key> --out=/tmp/conv_recall.json

# Judged pipeline + embedding comparison
php admin/cli/run_rag_fixture_benchmark.php --judge \
  --questions=tests/golden/rag_fixtures_conversational_bus101_pol101.json \
  --topk=5 --voyage-apikey=<key> --out=/tmp/conv_judge.json
```

`rag_fixtures_conversational_recall_bus101_pol101.json` was added for this run. The conversational set was originally built for judge mode only (`{"questions": [...]}`, no ground truth), so it could not be scored with recall@k. Each question was generated from a specific chunk and the fixture ids encode it (`conv_<courseid>_<chunkid>`), so the recall-mode file reuses the same 40 questions with `expected_chunk_id` recovered from the id — verified against the live index, 40 of 40 resolving to a chunk in the stated course.
