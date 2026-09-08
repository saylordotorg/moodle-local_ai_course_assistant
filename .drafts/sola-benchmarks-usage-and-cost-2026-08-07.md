# SOLA: model benchmarks, retrieval, and what it will actually cost

**Date:** 2026-08-07
**Supersedes and combines:** the 2026-07-30 model benchmark (FINAL) and the 2026-08-01 RAG benchmark. Both can be retired once this is reviewed.

---

## Headline

Three questions, three answers.

**Which model?** Gemini 2.5 Flash stays the chat primary. Opus 5 is not worth adopting anywhere in this workload. The one clear upgrade is Claude Haiku 4.5 for the quiz coach — and the live pilot now shows Haiku earning **24% more messages per learner** than the alternatives, which is stronger evidence than any benchmark.

**How should retrieval be configured?** Keep OpenAI embeddings at 1536 dimensions. Keep chunk size 400. Set `rag_topk` to 3 (done). Leave reranking off; if it is ever enabled, use a candidate pool of 20 with the per-query confidence gate.

**How much will it cost?** **About $11 a month today, and about $13 a month if SOLA is switched on across the whole catalogue.** That is measured, not modelled — it reconciles with actual stored token counts to within 9%.

The adoption assumptions in circulation (5%, 10%, 20%, 30%) are all too high, by between 1.7x and 10.3x. The measured rate is **2.7%** of active learners on Learn and **6.7%** on Degrees. That correction is the substance of this document.

---

## 1. How much SOLA usage should we expect?

### The question that was being asked wrong

Previous projections picked an adoption percentage and applied it to the catalogue. Two problems: the percentage was a guess, and enrolments are the wrong denominator. Enrolment is cumulative — 237,041 across 143 courses — while only a fraction of those learners are active in any month. Multiplying cumulative enrolments by a guessed percentage compounds two errors.

The measurable version:

```
monthly turns  =  active learners  ×  adoption rate  ×  turns per adopting learner
```

Every term on the right is now measured on production.

### What the pilot actually shows

Thirty-day window ending 2026-08-07:

| | Learn | Degrees |
|---|---|---|
| Active learners (site-wide) | 66,972 | 3,545 |
| Courses with any activity | 213 | 64 |
| Courses with SOLA enabled | 30 | 4 |
| Active learners **in** SOLA courses | 44,370 | 1,716 |
| Learners who used SOLA | 1,213 | 115 |
| **Adoption (of active learners who could see it)** | **2.73%** | **6.70%** |
| Turns per adopting learner | 4.80 | 4.98 |

Two-thirds of active learners can already see SOLA. Of those, fewer than 3 in 100 use it on Learn.

Degrees adoption is **2.5x higher** than Learn. Those are admissions and preparation courses with smaller, more committed cohorts — a real signal about where SOLA lands well, not noise.

### The projection

If SOLA is enabled everywhere and adoption holds:

| | Learners | Turns/month |
|---|---|---|
| Learn — 66,972 × 2.73% × 4.80 | 1,828 | 8,776 |
| Degrees — 3,545 × 6.70% × 4.98 | 238 | 1,183 |
| **Total** | **2,066** | **9,959** |

Today's actual footprint is 1,328 learners and ~6,400 turns. Full catalogue rollout is therefore roughly a **1.6x increase**, not a step change — because the courses already covered are the large ones.

### Against the assumptions in circulation

| Assumed adoption | Implied users | Implied turns/mo | Overstates by |
|---|---|---|---|
| 5% | 3,526 | 17,030 | 1.7x |
| 10% | 7,052 | 34,060 | 3.4x |
| 20% | 14,103 | 68,119 | 6.8x |
| 30% | 21,155 | 102,179 | 10.3x |
| **Measured (2.73% / 6.70%)** | **2,066** | **9,959** | — |

### A better way to frame it than a single number

Adoption is a behaviour, not a constant, so plan against a band:

| Scenario | Basis | Turns/mo |
|---|---|---|
| **Floor** | adoption holds at measured rate | ~10,000 |
| **Central** | promotion, onboarding, in-course prompts roughly double it | ~20,000 |
| **Ceiling** | adoption reaches 10%, near Degrees' rate across everything | ~34,000 |

The useful property: **every one of these is cheap.** The band spans 3.4x in volume and $18 to $63 a month in chat spend. Cost does not discriminate between them, so adoption planning should be driven by learning value, not budget.

### The number that should worry us instead

**31% of SOLA users send exactly one message and never return.** 12% reach ten turns; 3% reach twenty-five. The mean of ~4.8 turns describes almost nobody — it is a small committed group averaged with a large bounce.

That single-turn rate is the largest number in this document, and it is not a cost problem. If the goal is more value from SOLA, the lever is the first-turn experience, not capacity.

---

## 2. What it costs

### Measured, not projected

Actual stored token counts for the last 30 days, priced at the current rate card:

| Site | Model | Calls | Avg prompt | $/month |
|---|---|---|---|---|
| Learn | gemini-2.5-flash | 2,419 | 5,477 | $4.99 |
| Learn | gpt-4o-mini | 1,721 | 5,500 | $1.61 |
| Learn | claude-haiku-4-5 | 1,349 | 1,262 | $3.77 |
| Degrees | gpt-4o-mini | 335 | 4,223 | $0.26 |
| Degrees | gemini-2.5-flash | 189 | 5,004 | $0.41 |
| | **Total** | **6,013** | | **$11.05** |

That is **$0.0017 per learner turn**, or **$0.008 per SOLA user per month**.

The bottom-up model predicted $12 for this footprint against $11.05 actual — a 9% overstatement, which is close enough to trust the projections built on it.

### Forward run rate

`rag_topk` moved from 5 to 3 on 2026-08-04, after most of this window. RAG passages are 66% of the prompt, so that cuts chat spend roughly 26%:

| Scenario | Turns/mo | Chat | Rerank @20 if enabled | Total |
|---|---|---|---|---|
| Today's footprint, post-topk change | 6,400 | ~$8 | $5 | ~$13 |
| **Full catalogue, adoption holds** | **9,959** | **~$13** | **$7** | **~$20** |
| Full catalogue, adoption doubles | 19,918 | ~$26 | $15 | ~$41 |
| Ceiling: 10% adoption | 34,060 | ~$45 | $26 | ~$71 |

Query embedding is under $0.05/month at every scale and is omitted.

**Fixed costs do not scale with learners.** Indexing the whole catalogue is a one-time **$1.55** and about 14 minutes; embedding storage is 3.25 GB as JSON, 0.68 GB packed.

### The honest framing

Earlier versions of this analysis reported figures like $1,346/month for reranking. Those came from multiplying a correct per-query cost by a hypothetical 25,000-user population that does not exist. At real volume the entire AI bill is **an order of magnitude below the cost of a single working day of staff time.**

This should change how the project is discussed. Optimisation work on SOLA is worth doing for **latency and answer quality**, not for savings. There are no savings of consequence available.

---

## 3. Which chat model

### Benchmark: 5 models, 50 prompts, judged by Claude Sonnet 4.6

| Model | Rubric /15 | Cost (¢/call) | P50 TTFT | P50 total |
|---|---|---|---|---|
| claude-sonnet-5 | **14.44** | 0.366 | 1,740 ms | 5,688 ms |
| claude-opus-5 | 14.42 | 2.273 | 6,317 ms | 14,214 ms |
| gemini-2.5-flash | 14.16 | 0.052 | 2,272 ms | 3,108 ms |
| claude-haiku-4-5 | 14.10 | 0.115 | **612 ms** | 3,153 ms |
| gpt-4o-mini | 12.86 | **0.012** | 461 ms | 2,698 ms |

By SOLA function:

| Model | Socratic | Quiz coach | Anti-cheat | Multilingual | Illustration |
|---|---|---|---|---|---|
| claude-sonnet-5 | **15.00** | 14.50 | 14.10 | **14.70** | 13.90 |
| claude-opus-5 | 14.40 | 14.70 | 14.30 | **14.70** | **14.00** |
| gemini-2.5-flash | 14.40 | 14.30 | **14.50** | 14.40 | 13.20 |
| claude-haiku-4-5 | 13.90 | **14.80** | **14.50** | 13.80 | 13.50 |
| gpt-4o-mini | 12.10 | 13.90 | 13.20 | 13.10 | 12.00 |

**Opus 5 does not earn its place.** It never wins a category meaningfully, costs 6.2x Sonnet 5 and 44x Gemini, and is the slowest tested — 14.2 seconds median end-to-end against Gemini's 3.1. For a learner waiting on a tutor, that is a product problem independent of price.

**Quality has converged.** Four of five models sit between 14.10 and 14.44, a 0.34-point spread on a 15-point rubric — inside single-pass judge noise. What separates them now is cost and latency.

### The live pilot is better evidence, and it agrees

Three provider groups have been running on production since 25 June across 25 courses:

| Model | Messages | Learners | **Messages per learner** | Avg reply length | Tokens/reply |
|---|---|---|---|---|---|
| claude-haiku-4-5 | 1,887 | 332 | **5.68** | 987 chars | 307 |
| gemini-2.5-flash | 3,411 | 744 | 4.58 | 570 chars | 169 |
| gpt-4o-mini | 2,487 | 544 | 4.57 | 844 chars | 181 |

**Haiku 4.5 keeps learners engaged 24% longer** than either alternative. That is real behaviour from 332 learners, not a rubric score.

Read it carefully, though: Haiku also writes replies **73% longer** than Gemini. Longer answers may prompt more follow-ups without being more useful, and the engagement gain may partly be a length artefact. The two candidate explanations — better answers, or answers that invite another question — are not separable from this data.

What it does support: Haiku is not worse in practice, its benchmark quiz-coach win (14.80, best in field) is consistent with real engagement, and its 612 ms time-to-first-token is the best measured.

**The quiz coach was never on the model the docs claimed.** `generate_quiz.php` resolved the chat provider, so quiz generation ran on Gemini, not gpt-4o-mini. The setting to change it did not exist until it was added.

---

## 4. Retrieval

### Rerank pool size

1,008 queries, 16 courses:

| Pool | R@1 | R@3 | R@5 | Cost/query |
|---|---|---|---|---|
| Embedding only | 55.3% | 79.2% | 86.8% | — |
| 30 | 72.7% | 89.3% | 93.6% | $0.00112 |
| **20** | 72.3% | **89.0%** | 93.5% | **$0.00075** |
| 10 | 71.7% | 87.0% | 90.6% | $0.00037 |

Pool 20 matches pool 30 for a third less cost. Pool 50 — the old default — costs 2.5x pool 20 for no measurable gain. The shipped default is now 20.

### Reranking helps only where retrieval is already struggling

Per query across all 1,008:

| Situation | Count | Outcome |
|---|---|---|
| Target already at **rank 1** | 557 | rerank pushed it out in **67 (12.0%)** |
| Target **outside top 3** | 210 | rerank pulled it in for **130 (61.9%)** |

Reranking is an excellent rescuer and a mediocre custodian. Per course, the gain tracks baseline weakness precisely: the weakest course gained +23.8 pp, while the strongest (98.4% baseline) **lost** 3.2 pp.

### The confidence gate

The cosine margin between the top-1 and top-3 candidates predicts when reranking helps — and needs no ground truth, so it works at query time in production.

| Margin decile | Baseline R@3 | With rerank | Delta |
|---|---|---|---|
| Most ambiguous | 54.5% | 79.2% | **+24.8 pp** |
| Least ambiguous | 99.0% | 99.0% | **+0.0 pp** |

Validated through the live retriever on all 1,008 fixtures:

| Arm | R@3 | Reranked | ms/query |
|---|---|---|---|
| Never | 79.2% | 0% | 288 |
| Always | 89.1% | 100% | 534 |
| **Gated (0.086)** | **88.9%** | **70%** | **471** |

Absolute cosine score was tested as an alternative signal and **rejected** — non-monotone, and needing 90% coverage to hold recall, because absolute similarity varies with course vocabulary.

### Embeddings: keep OpenAI at 1536

| Method | OpenAI 3-small | Voyage-3.5 |
|---|---|---|
| Recall R@3 | **55.0%** | 52.5% |
| Judged nDCG@5 | **0.951** | 0.943 |
| Price / 1M tokens | **$0.02** | $0.06 |

Three independent measurements agree. Dimension reduction was also swept:

| Dimensions | R@3 | MRR |
|---|---|---|
| **1536** | **82.1%** | **0.707** |
| 1024 | 81.3% | 0.697 |
| 512 | 80.2% | 0.678 |
| 256 | 78.6% | 0.672 |

Recall degrades monotonically and scoring time barely improves (PHP loop overhead dominates, not arithmetic). Keep 1536.

### `rag_topk`: 3, on quality-neutral evidence

| topk | hit@k | RAG tokens | Prompt | $/mo at 25k users |
|---|---|---|---|---|
| **3** | 88.9% | 2,343 | 3,537 | $234 |
| 5 | 93.2% | 3,916 | 5,110 | $316 |
| 7 | 94.8% | 5,491 | 6,685 | $398 |

hit@k drops 4.3 pp going from 5 to 3 — but hit@k measures whether the target chunk was *present*, not whether the answer was better. A blind A/B with order alternated, 40 questions, judged by Sonnet 4.6:

| | Wins |
|---|---|
| topk=3 better | 11 (28%) |
| topk=5 better | 12 (30%) |
| Tie | 17 (42%) |

**Statistically tied.** 31% fewer prompt tokens for no measurable quality cost. Applied to production.

### `rag_chunksize`: keep 400

| Chunk words | Chunks | Retrieved tokens | Judged sufficiency (0-3) |
|---|---|---|---|
| 200 | 687 | 2,318 | 2.33 |
| **400** | 477 | 3,201 | **2.44** |
| 800 | 301 | 4,491 | 2.58 |

A smooth linear trade with no knee — bigger chunks answer better and cost proportionally more. 400 sits mid-curve; moving costs a full reindex for a marginal step along a line.

---

## 5. Current production configuration

| Setting | Learn | Degrees | Basis |
|---|---|---|---|
| `rag_topk` | 3 | 3 | quality-neutral, 31% fewer tokens |
| `rerank_enabled` | 0 | 0 | off pending release of the gate |
| `rerank_candidates` | 20 | 20 | staged at measured-good value |
| `embed_model` | 3-small | 3-small | three measurements |
| `embed_dimensions` | 1536 | 1536 | recall degrades below |
| `rag_chunksize` | 400 | 400 | no knee in the curve |

Production runs plugin version `2026061702`. The confidence gate, packed float32 vectors and cache invalidation are merged to `main` but **not yet released**, which is why reranking is off rather than gated.

---

## 6. Recommendations

1. **Stop planning against 5-30% adoption.** Use 2.7% (Learn) and 6.7% (Degrees) as the floor, and a 2x uplift as the planning case. Revisit quarterly.
2. **Drop cost as a decision input.** Every plausible scenario lands between $13 and $71 a month. Choose on learning value and latency.
3. **Enable SOLA on the remaining courses.** Full rollout is ~1.6x today's volume and about $7/month more. Nothing about capacity argues against it.
4. **Attack the 31% single-turn rate.** It is the largest number here and the only one with real upside behind it.
5. **Switch the quiz coach to Haiku 4.5** — best category score, best latency, and the pilot shows it holding learners longer.
6. **Release the confidence gate before re-enabling reranking**, then enable per course rather than site-wide.
7. **Do not migrate embeddings, change dimensions, or change chunk size.** All three are settled.

---

## 7. What this does not establish

- **Adoption may not hold at 2.73%.** The 30 enabled courses were chosen, not sampled — mostly high-traffic. Rolling out to the tail could see lower engagement per course, though the tail is small in learner terms.
- **The pilot engagement result is confounded by reply length.** Haiku's 24% engagement lead comes with 73% longer replies. Better answers and more-inviting answers are not separable here.
- **Compliance was not re-tested for this document.** Sonnet 5 and Opus 5 passed a 3-run jailbreak suite (0 FAIL across 12 runs), but the 2026-07-24 percentage-scored methodology has not been re-run against the current prompt.
- **Retrieval quality is measured on 16 dev-indexed courses.** The rest of the catalogue is assumed similar in shape.
- **The confidence gate threshold is tuned on synthetic questions**, which are measurably easier than real learner queries (79.2% vs 55.0% baseline recall on the same corpus). The shape should transfer; 0.086 specifically may not.
- **Retrieval latency figures are warm-path.** Benchmarks amortise the vector load across thousands of queries in one process; production pays it cold every turn. Real cold retrieval is 835 ms on a typical course and 1,957 ms on the largest.

---

## Appendix: method and sources

**Usage and cost** — production databases for `learn.saylor.org` and `degrees.saylor.org`, read-only aggregate queries via the existing Redash data sources, 30-day window ending 2026-08-07. Active learners from `user_lastaccess`; SOLA usage from the plugin's own message table; spend from stored `prompt_tokens` / `completion_tokens` priced at the live rate card.

**Enrolment context** — Redash query 1515 (143 courses, 237,041 cumulative enrolments; top 16 courses hold 50%).

**Pilot outcomes** — Redash query 2222, the three-group provider A/B running on production since 25 June.

**Chat benchmark** — `admin/cli/run_tutor_golden.php`, 50-prompt golden set, 5 models, 250 calls, judged by Claude Sonnet 4.6. Roughly $2.50.

**Retrieval benchmarks** — `admin/cli/run_rag_fixture_benchmark.php` against the live dev index; 1,008 synthetic conversational queries across 16 courses, plus 40 curated colloquial queries.

**On re-running the chat benchmark.** It was not re-run for this document. The 2026-07-30 run is eight days old, the models are unchanged, and the live pilot now supplies something better — real learner engagement across three models on 25 production courses. A synthetic rubric score is a proxy for exactly what the pilot measures directly. Re-running would have cost money to reproduce a result the pilot supersedes.
