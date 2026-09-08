# SOLA: RAG benchmark re-run and consolidated LLM recommendations

**Date:** 2026-08-27
**Site:** dev.sylr.org, SOLA 7.2.0
**Corpus:** 16,138 chunks across 45 indexed courses, all embedded
**Retrieval config as measured:** OpenAI `text-embedding-3-small` @1536, `rag_topk=3`, `rag_min_similarity=0.25`, `rerank-2.5` at pool 20
**Harness:** `admin/cli/run_rag_fixture_benchmark.php` — the product's own retrieval path, not a purpose-built evaluator

---

## Headline

**The August 21 retrieval numbers reproduce, through a different code path, on a rebuilt corpus, with regenerated ground truth.**

| Arm | Aug 21 (purpose-built evaluator, 1,008 rows, cached vectors) | Today (product harness, 816 rows, live retriever) | Δ |
|---|---|---|---|
| Production-shaped, embeddings only | 52.3% | **52.9%** | +0.6 pp |
| Production-shaped, + rerank-2.5 @ pool 20 | 63.1% | **63.7%** | +0.6 pp |
| Conversational, embeddings only | 79.1% | **80.5%** | +1.4 pp |
| Conversational, + rerank-2.5 @ pool 20 | 89.0% | **90.2%** | +1.2 pp |

All four arms reproduce within 1.4 pp. Two independent implementations, two different corpus states, two separately-derived ground-truth sets. That is the strongest validity evidence we have produced for any SOLA retrieval number, and it means the August 21 conclusions — including the Voyage-4 recommendation — can be relied on rather than re-litigated.

**Three things changed as a result of this run:**

1. **A defect in the fixture format was found and fixed.** It was inflating measured recall by about 6 points. It affected the published baseline set, not just the new fixtures.
2. **Reranking is worth +10.8 pp** on production-shaped queries at pool 20 — confirming it as the single best retrieval lever currently switched on.
3. **The recommendation to move embeddings to `voyage-4-large` stands**, and is now better supported than when it was made.

---

## 1. A defect in how ground truth was anchored

This is the most important methodological finding, and it is worth stating before any numbers.

The benchmark decides whether retrieval succeeded in one of two ways: matching the chunk ID, or — when the index has been rebuilt and IDs no longer line up — falling back to a text match. **The harness truncates that text anchor to its first 50 characters** (`admin/cli/run_rag_fixture_benchmark.php:1191`).

SOLA's chunker produces deliberately overlapping chunks. A 50-character span therefore very often appears in two or three neighboring chunks. When it does, the fallback credits a hit if *any* of them is retrieved.

Measured against the current index:

| Fixture set | Rows whose 50-character anchor is **not** unique within its course |
|---|---|
| `rag_fixtures_bus101_pol101.json` (the published baseline set) | **36 of 40** |
| First regeneration attempt (uniqueness checked on the full anchor) | **658 of 816** |

The consequence is measurable rather than theoretical. On the same 39 fixtures, same model, same corpus:

| Anchoring | Recall@3, embeddings only |
|---|---|
| Anchors unique only at full length (ambiguous at 50 chars) | 65.0% |
| **Anchors unique in their first 50 characters** | **59.0%** |

**About 6 points of apparent recall was an artifact of the anchor, not the retriever.** The embeddings arm is independent of rerank pool size, so nothing else explains the gap.

I caught this because I made the same mistake myself: my first regeneration verified uniqueness on the whole anchor string and passed cleanly, then failed the moment I checked it at the length the harness actually uses. The check existed and did not fire — the same failure mode that produced the inert spend fix and the never-loaded consent gate earlier this month.

**Fixed.** All regenerated fixtures now select an anchor whose *first 50 characters* are unique within the course. Verified: 0 ambiguous rows across all 1,671 fixtures.

### Fixture repair

| Set | Original | Kept | Repaired | Dropped | Notes |
|---|---|---|---|---|---|
| `rag_fixtures_bus101_pol101_anchored_2026-08-27.json` | 40 | 39 | 29 | 1 (`bus-028`) | Stale IDs relocated by searching the original anchor text in the current index |
| `rag_fixtures_prodshape_anchored_2026-08-27.json` | 1,008 | 816 | — | 192 | 189 stale IDs, 3 with no unique anchor |
| `rag_fixtures_conv1k_anchored_2026-08-27.json` | 1,008 | 816 | — | 192 | same rows as above |

The 189 dropped production-shaped rows are exactly the ones the August 22 addendum predicted would be lost: courses 2, 11 and 43 were reindexed, and those fixtures carried **no** text anchor at all — `expected_substring` was empty in all 1,008 rows — so they could not be repaired from the fixture file. This run implements that addendum's recommendation: **every fixture is now content-anchored and will survive the next reindex.**

The 40-row baseline set was recoverable precisely because it *was* content-anchored: 29 of its 30 stale rows were relocated by searching their original text. That is the argument for content anchoring, demonstrated rather than asserted.

---

## 2. Production-shaped queries — the headline measurement

816 fixtures, 13 courses, live retriever, pool 20.

| Metric | Embeddings only | + rerank-2.5 | Δ |
|---|---|---|---|
| Recall@1 | 34.6% | **45.6%** | +11.0 pp |
| **Recall@3** | **52.9%** | **63.7%** | **+10.8 pp** |
| Recall@5 | 60.9% | 68.4% | +7.5 pp |
| MRR | 0.469 | 0.557 | +0.088 |
| P50 latency | 246 ms | 208 ms added | — |
| Cost per query | — | $0.00073 | — |

Per-course spread is wide — Recall@3 after reranking runs from 55.6% to 79.4% — which matters more for course-level expectations than the site aggregate does.

**Read the absolute level carefully.** 52.9% is not a bug; it is what retrieval on real query shapes actually looks like. Production queries have a median length of 39 characters and only 29% carry a question mark. The conversational fixtures that produced our historical 79–89% figures are roughly the easiest quarter of real traffic.

---

## 3. The baseline BUS101/POLSC101 set

39 fixtures, pool 20, correctly anchored.

| Group | n | Cos@1 | Cos@3 | Rerank@1 | Rerank@3 | Δ@3 |
|---|---|---|---|---|---|---|
| Overall | 39 | 41.0% | 59.0% | 38.5% | 61.5% | +2.6 pp |
| BUS101 | 29 | 34.5% | 55.2% | 34.5% | 58.6% | +3.4 pp |
| POLSC101 | 10 | 60.0% | 70.0% | 50.0% | 70.0% | 0.0 pp |

**Do not draw a rerank conclusion from this table.** At n=39 a single fixture moves Recall@3 by 2.6 points, so the entire measured rerank gain here is one fixture. The 816-row result in section 2 is the number to cite. This set is useful for continuity with the June report and for catching gross regressions, not for deciding anything.

That caveat applies retroactively. The June 10 report's headline — Recall@3 55% → 72.5%, "+17.5 pp" — was measured on 40 rows, where 17.5 points is seven fixtures. It pointed in the right direction; its precision was overstated. The +10.8 pp measured today on 816 rows supersedes it.

---

## 3b. Fixture shape is worth 28 points — measured on identical rows

The two 816-row fixtures contain **the same ground-truth chunks in the same courses**. Only the surface form of the query differs: conversational phrasing versus the measured production distribution (median 39 characters, 61% bare noun phrases, only 29% carrying a question mark).

| Arm | Conversational | Production-shaped | Fixture effect |
|---|---|---|---|
| Embeddings only | 80.5% | 52.9% | **−27.6 pp** |
| + rerank-2.5 @ pool 20 | 90.2% | 63.7% | **−26.5 pp** |

The August 21 report measured this gap at −26.9 pp on OpenAI vectors. It reproduces here at −27.6 pp, on regenerated ground truth and a rebuilt corpus.

**Every historical SOLA retrieval figure in the 79–90% range was measured on roughly the easiest quarter of real traffic.** Real production retrieval sits near 53% Recall@3 before reranking and 64% after. This is the single most consequential number in this document for the product — and the reason recommendation 4 below retires the easy fixtures as a decision instrument.

Because the rows are identical, this is a clean measurement of query phrasing alone: same corpus, same labels, same code path, same 816 targets.

---

## 4. What reranking is worth

| Measurement | Base | + rerank | Δ | n |
|---|---|---|---|---|
| Production-shaped, today, pool 20 | 52.9% | 63.7% | **+10.8 pp** | 816 |
| Production-shaped, Aug 21, pool 20 | 52.3% | 63.1% | +10.8 pp | 1,008 |
| Production-shaped, Aug 21, on `voyage-4-large` vectors | 65.0% | 67.3% | **+2.3 pp** | 1,008 |

The two OpenAI measurements agree to the decimal. The third is the one that should shape the commercial conversation:

**Most of what the reranker buys is compensation for weak embeddings.** On OpenAI vectors it is worth +10.8 pp. On `voyage-4-large` vectors the same reranker is worth +2.3 pp. Reranking is the right lever *today* because our embeddings are the weak link; it is not independently worth $140/month once they are not.

**Gate it on query length, not on confidence.** From the August 21 per-bucket analysis, reranking helps short keyword queries (+5.3 pp at ≤20 chars, +4.5 pp at 21–50) and actively hurts everything longer (−0.7 to −5.1 pp). A length gate at ≤50 characters beats always-on (67.7% vs 67.2%) while firing on 58% of traffic. It needs one `mb_strlen()` call and no score inspection — strictly simpler than the margin-based confidence gate the August 1 report recommended, and better.

---

## 5. Embeddings: Voyage AI-4

### The recommendation

**Move document and query embeddings to `voyage-4-large` at 2048 dimensions.**

| Configuration | $/MTok | R@3 conversational | R@3 production-shaped |
|---|---|---|---|
| OpenAI `text-embedding-3-small` @1536 *(today)* | $0.02 | 79.1% | 52.3% |
| OpenAI + rerank-2.5 @ pool 20 | $0.02 + rerank | 89.0% | 63.1% |
| voyage-3.5 @1024 | $0.06 | 89.6% | 61.0% |
| voyage-4-lite @1024 | **$0.02** | 91.0% | 60.6% |
| voyage-4-lite @2048 | $0.02 | 91.1% | 61.7% |
| voyage-4 @1024 | $0.06 | 90.8% | 63.6% |
| voyage-4 @2048 | $0.06 | 91.3% | 64.1% |
| voyage-4-large @1024 | $0.12 | 90.6% | 65.0% |
| **voyage-4-large @2048** | $0.12 | 91.1% | **66.3%** |

`voyage-4-large` @2048 reaches **66.3%** against **52.3%** today — **+14.0 pp** — *without a reranker*, beating the OpenAI-plus-rerank stack (63.1%) that was the previous plan. It is the largest single retrieval quality gain available to us, for $9.00 one-time and under $4/month.

**If the flagship is not wanted: `voyage-4-lite` costs exactly what OpenAI costs** ($0.02/MTok) and still beats it by +8.3 pp. There is no configuration in which staying on OpenAI is the quality-per-dollar choice.

### Why this reverses an earlier decision

The June 9 recommendation was Voyage-3.5. On July 8 an A/B reversed it — "on our corpus OpenAI matches or beats Voyage, is cheaper, and is incumbent" — on the strength of 55.0% vs 52.5% Recall@3.

**That reversal rested on the 40-row fixture set, where 2.5 points is one fixture.** At 1,008 rows the same comparison runs 61.0% vs 52.3% in Voyage's favor — an 8.7-point gap in the opposite direction. Section 1 above shows the same set moving 6 points from an anchoring change alone. It could not have supported a vendor decision either way.

The August 21 work also showed *why* the easy fixture misleads: on conversational queries `voyage-4-lite`, `voyage-4` and `voyage-4-large` sit inside 0.4 pp of each other — twice the noise floor, and in the wrong order. On production-shaped queries they separate cleanly by 4.4 pp in the expected order. **The easy fixture would have told us to buy the cheapest model on the grounds that the flagship adds nothing.**

### Matryoshka widths

| Model | 256d | 512d | 1024d | 2048d | 1024 → 2048 |
|---|---|---|---|---|---|
| voyage-3.5 | 56.3% | 58.4% | 61.0% | 60.1% | −0.9 pp |
| voyage-4-lite | 56.6% | 59.5% | 60.6% | 61.7% | +1.1 pp |
| voyage-4 | 58.3% | 61.9% | 63.6% | 64.1% | +0.5 pp |
| voyage-4-large | 60.6% | 62.3% | 65.0% | **66.3%** | +1.3 pp |

The earlier "2048 buys nothing" finding was true of voyage-3.5 and is **not** true of the v4 family. At our index size the storage cost is not a constraint. Note that **`voyage-4-large` at 256 dimensions (60.6%) beats OpenAI at 1536 (52.3%) by 8.3 pp on one sixth the storage.**

### Asymmetric retrieval: do not adopt it

Voyage's v4 announcement claims quality improves when documents are embedded with a larger model than queries. **We could not reproduce this**, at any width, on either fixture — it was a wash for `voyage-4-lite` and 1.0 pp *worse* for `voyage-4`. A follow-up in-product A/B on August 22 agreed in direction (−3.3 pp), though at n=30 that is one fixture and proves nothing on its own.

The shared embedding space is still worth having, but as **migration insurance, not a quality gain**: the query model can be changed later without re-embedding the corpus. That property was demonstrated directly on August 22 — both arms ran against the same stored vectors with no reindex between them. Given that a full reindex is our expensive, risky operation, decoupling the upgrade path from it has real value.

Worth raising with Voyage plainly — not as a challenge, but because if we are configuring it wrongly we would like to know.

### Operational warning: bulk throughput

An identical 64-text batch took **1,082 ms on voyage-3.5 and 14,621 ms on voyage-4-lite** — 13.5x slower. Reindexing the full corpus is roughly 30 hours single-threaded against about 2 hours today. It parallelizes cleanly (19 MTok across 18 workers took about ten minutes), but **this is a planned migration, not a single `reindex` invocation.** Query-path latency is fine: every v4 model beats OpenAI at p50 (140–195 ms vs 175 ms) with roughly half the tail.

---

## 6. Consolidated SOLA LLM recommendations

| Component | Primary | Failover | Basis |
|---|---|---|---|
| **Core chat tutor** | **Gemini 2.5 Flash** (Vertex AI) | gpt-4o-mini | Reconfirmed 2026-07-24 against newer Gemini Flash models: most compliant Gemini (86% jailbreak vs 79% for 3.5-flash-lite, 62% for 3.6-flash) and cheapest (0.056¢/call vs 0.117); tutor quality effectively tied. Newer Flash models rejected on safety and cost. |
| **Quiz coach** | gpt-4o-mini | Mistral Small | Function saturated; cheapest model scoring in the top tier. |
| **Mastery classifier** | gpt-4o-mini | Mistral Small | Structured-output task at ~1/40 of chat-tier per-token. Routed off the chat tier in v5.11 — saves ~$220/month at 100k MAU. |
| **Anti-cheat / integrity reference** | Claude Haiku 4.5 (~5% of turns) | Gemini 2.5 Flash | Best refusal discipline (14.60/15); budget models cave (10.30–11.50). |
| **ESL / multilingual chat** | Gemini 2.5 Flash | gpt-4o-mini | Decisive multilingual lead (14.50/15). |
| **Analytics, digests, Learning Radar** | gpt-4o-mini | Gemini 2.5 Flash | Batch summarization. Unchanged. |
| **Premium escalation tier** (~5% of turns, off by default) | **Claude Sonnet 5** | — | Target changed from Opus 4.8 on 2026-08-23. On the 50-prompt golden set Sonnet 5 scored 14.56/15 vs Opus 5's 14.22, at **0.352¢/call vs 2.224¢** and a third the time-to-first-token. |
| **Embeddings** | **→ `voyage-4-large` @2048** (currently OpenAI 3-small @1536) | OpenAI 3-small | +14.0 pp on production-shaped queries. See section 5. |
| **Re-ranker** | Voyage rerank-2.5, **length-gated at ≤50 chars** | Cohere Rerank 3.5 | +10.8 pp on today's embeddings; +2.3 pp once on v4 vectors. 40x cheaper than Cohere. |
| **Judge harness** | Claude Sonnet 4.6 | Gemini 2.5 Pro | Deliberately outside the contestant pool. |
| **EU-residency fallback** (not in Saylor default) | Mistral Small | — | Provider class stays available for non-Saylor sites. Pending training-opt-out and ZDR; Saylor does not need it at current scale. |

### Chat model evidence

| Model | Rubric /15 | Cost (¢/call) | P50 TTFT | P50 total |
|---|---|---|---|---|
| claude-sonnet-5 | **14.44** | 0.366 | 1,740 ms | 5,688 ms |
| claude-opus-5 | 14.42 | 2.273 | 6,317 ms | 14,214 ms |
| gemini-2.5-flash | 14.16 | 0.052 | 2,272 ms | 3,108 ms |
| claude-haiku-4-5 | 14.10 | 0.115 | **612 ms** | 3,153 ms |
| gpt-4o-mini | 12.86 | **0.012** | 461 ms | 2,698 ms |

**Quality has converged.** Four of five models sit within 0.34 points on a 15-point rubric — inside single-pass judge noise. Cost and latency are what separate them now.

**Opus 5 does not earn its place**: it never meaningfully wins a category, costs 6.2x Sonnet 5 and 44x Gemini, and is the slowest tested at 14.2 seconds median end-to-end against Gemini's 3.1. For a learner waiting on a tutor that is a product problem independent of price.

**One live-pilot signal worth keeping in view.** Across 25 production courses since June 25, Haiku 4.5 held learners for 5.68 messages per learner against 4.58 for Gemini — 24% longer engagement from 332 real learners. But Haiku also writes replies 73% longer, and "better answers" versus "answers that invite another question" are not separable from that data. It supports "Haiku is not worse in practice"; it does not by itself justify a switch.

---

## 7. Cost

| Component | 30-course pilot (~6k SOLA users) | 50k MAU (12.5k) | 100k MAU (25k) |
|---|---|---|---|
| Chat tutor (Gemini 2.5 Flash, blended incl. ESL) | $250 | $525 | $1,050 |
| Quiz coach | $20 | $43 | $85 |
| Mastery classifier | $25 | $53 | $105 |
| Anti-cheat reference (~5% turns) | $8 | $16 | $33 |
| Analytics + digests + Radar | $4 | $15 | $30 |
| Embeddings | <$1 | <$1 | <$4 |
| Re-ranker (pool 20, length-gated) | $15 | $31 | ~$81 |
| Judge harness | $4 | $4 | $4 |
| **Baseline total** | **~$330** | **~$690** | **~$1,390** |
| Premium tier (Sonnet 5, 5% of turns, off by default) | — | — | see note |

Two corrections to figures still in circulation:

- **The reranker line was understated.** The original $63/month at 100k MAU assumed a small pool. Measured at production chunk sizes (21,124 tokens per rerank) it is **$1.06 per 1,000 queries** — about $140/month always-on, or **~$81/month length-gated at 58% coverage**.
- **The premium-tier figure of ~$700/month was an Opus number.** Per-call cost fell about 6x with the move to Sonnet 5. The monthly total depends on production output length, so treat $700 as an upper bound rather than scaling it down precisely.

**The entire embeddings decision is worth under $4/month. The reranking decision is worth ~$140/month.** Spend negotiating energy accordingly — and note that adopting v4 embeddings is what makes the reranker line optional.

---

## 8. Recommendations

1. **Adopt `voyage-4-large` @2048 for documents and queries.** Largest available quality gain (+14.0 pp on realistic queries), under $4/month, and it removes the reranker's necessity rather than adding to it. Plan the reindex as a parallelized migration, not a single command.
2. **Length-gate the reranker at ≤50 characters.** Better than always-on today (67.7% vs 67.2%), a third cheaper, one function call to implement. Do this regardless of the embeddings decision.
3. **Do not adopt asymmetric retrieval.** It has now failed to reproduce twice. Keep the shared embedding space as migration insurance.
4. **Retire the 40-row fixture set as a decision instrument.** Use it for regression smoke only. Every vendor or configuration decision should be made on the 816-row production-shaped set.
5. **Make content anchoring mandatory in the fixture generator**, with uniqueness enforced at the harness's 50-character truncation length. This run had to discard 189 fixtures that were unrepairable purely because they lacked a text anchor.
6. **Fix the group-label bug in the benchmark harness.** The per-course summary repeats labels (`course11` appears three times, `course13` three times) for genuinely distinct groups. The grouping is correct — 13 groups matching the 13 surviving courses — but the labels are not usable as written.
7. **No change to the chat stack.** Gemini 2.5 Flash primary, gpt-4o-mini failover, Sonnet 5 for premium escalation. Re-open only if adoption drifts more than 5 percentage points.

---

## 9. What this does not establish

- **Retrieval quality, not answer quality.** Recall@3 measures whether the right chunk was retrieved. Whether the learner got a better answer is a separate question, and the `rag_topk` A/B is the cautionary example: hit@k fell 4.3 pp going from 5 to 3, and blind judging found the answers statistically tied.
- **The v4 numbers in section 5 were not re-measured today.** They are carried forward from August 21, where they passed a four-arm validity gate reproducing published baselines within 0.1 pp, and were confirmed in-product on August 22. Re-running them would require re-embedding the corpus per model. The OpenAI arms *were* re-measured today and reproduce within 0.6 pp, which is the evidence that the August 21 method was sound.
- **816 rows, not 1,008.** The 192 dropped rows are not random — 189 come from three specific courses (2, 11, 43). Course-level composition differs slightly from the August 21 run, which is one plausible source of the residual 0.6 pp.
- **One site, one corpus.** dev.sylr.org with 45 indexed courses. Production has more courses and a different content mix.

---

## 10. Provenance and spend

| Item | Detail |
|---|---|
| Site | dev.sylr.org, SOLA 7.2.0, 16,138 chunks / 45 courses, all embedded |
| Runs | `2026-08-27-221133-rag-bench.json` (39 rows), `2026-08-27-221212-rag-bench.json` (816 production-shaped), plus the 816-row conversational arm |
| New fixtures | `tests/golden/rag_fixtures_{prodshape,conv1k,bus101_pol101}_anchored_2026-08-27.json` |
| Embedding + rerank spend, this run | ~$2.60 (two 816-row runs at ~$0.00073-0.00077/query, one 39-row run, one aborted run) |
| Superseded by this document | `sola-rag-fixture-benchmark-2026-06-10.md` §6–7 (n=40 precision), the 2026-07-08 embeddings A/B reversal |
| Carried forward unchanged | `sola-voyage4-benchmark-2026-08-21.md` §1–8, `sola-vendor-recommendations-2026-06-09.md` chat/quiz/classifier tiers |
