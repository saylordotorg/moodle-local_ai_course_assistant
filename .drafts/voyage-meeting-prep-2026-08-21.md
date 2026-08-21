# Voyage AI meeting brief — 21 August 2026

Prepared for Tom. This is the walk-in document. Full evidence and method in the
companion benchmark, `.drafts/sola-rag-rerank-benchmark-2026-08-21.md`.

**What changed since the first draft of this brief:** the benchmark finished. It
overturns our previous embedding conclusion, answers four of the questions this
brief originally planned to ask them, and corrects two factual errors I had in
Section 1. Details flagged inline.

---

## 0. The 60-second version

**Ask for embeddings pricing. Voyage embeddings are measurably better than what
we run, and effectively free at our scale.**

Measured on 1,008 labeled queries across 16 courses, two fixtures, noise floor
0.2 pp:

| Change | R@3 (conversational) | R@3 (production-shaped) | Marginal $/mo @25k users | Added latency |
|---|---|---|---|---|
| Today — OpenAI `text-embedding-3-small` 1536d | 79.2% | 52.3% | — | — |
| Turn on rerank-2.5 @ pool 20 | 89.0% (+9.8) | 63.1% (+10.8) | +$95 | +233 ms |
| **Switch embeddings to voyage-3.5 @1024** | **89.6% (+10.4)** | **61.0% (+8.7)** | **+$0.13** | **−52 ms** |

The embedding switch buys roughly what always-on reranking buys, for about 1/700
of the monthly cost, and it makes retrieval *faster*. p < 1e-15 paired; the
effect is 50x the measured run-to-run noise.

**Our BATNA on price, and it is a strong one:** `voyage-3.5-lite` is $0.02/MTok
— *exact parity with OpenAI* — and still delivers **+8.5 pp**. So the accuracy
case does not require paying their $0.06 tier at all. The $0.06 tier has to earn
its 3x premium on the incremental **+1.9 pp**.

---

## 1. Where we actually stand with Voyage today

**We are a rerank-only customer by usage. We are not an embeddings customer.**
That much is unchanged, and it is still the thing to trade with — the embeddings
line is genuinely unclaimed and it is the larger of the two by token volume.

Verified on the dev fleet today:

| Setting | Value | Meaning |
|---|---|---|
| `rerank_apikey` | set | Voyage rerank-2.5 is live on dev |
| `rerank_enabled` | `1` | on, on dev only |
| `rerank_candidates` | `20` | matches the current recommendation |
| `embed_provider` | `openai` | **embeddings run on OpenAI** `text-embedding-3-small` |
| `embed_dimensions` | `1536` | 15,365 chunks embedded at 1536d |

> **Two corrections to what this brief said earlier.** It reported the setting as
> `embedding_provider` and said "unset". That setting name does not exist — the
> real name is `embed_provider`, and it reads `openai`. The conclusion was right
> but the evidence was not; it is now verified properly.
>
> It also said "no Voyage embedding credential exists," and used that to frame
> Commercial Q1 as "we will measure this the week you give us a key." **That was
> wrong and the framing is now unavailable to us — which is good news.** Voyage
> issues one key per account authorizing every endpoint. Our existing rerank key
> returns HTTP 200 from `/v1/embeddings` with a 1024-dim vector; I verified this
> directly, independently of the benchmark. There was never a procurement
> blocker. **So do not tell them we are waiting on a key — we have already run
> the measurement, on their infrastructure, and the numbers are in Section 0.**

Also worth having straight: **production is not running reranking at all,** and
prod's `rerank_candidates` is still **50**, which costs 2.5x pool 20 for no
measurable gain. Any volume commitment we make on reranking is a forecast, not
an extrapolation of a current bill.

## 2. What we measured, and why it is defensible

Three things make these numbers stronger than anything we have brought to a
vendor conversation before, and they are worth saying out loud if pushed:

- **The noise floor is measured, not asserted.** Two independent
  implementations — the plugin's own harness and a standalone script hitting the
  vendor APIs directly, separate calls and different batching — agree to **0.2 pp
  on R@3**, with 99%+ identical per-query ranks. Every delta above is stated
  against that floor.
- **Comparisons are paired.** All arms run the same 1,008 queries, so the test is
  McNemar, not overlapping confidence intervals. On R@3, Voyage wins 130 queries
  OpenAI loses and loses 23 OpenAI wins: p = 7.9e-16.
- **Two published baselines reproduce to 0.2 pp** — our own OpenAI-only figure
  and our own pool-20 rerank row — which is the check that the harness is
  measuring what we think it is.

**This overturns our own prior conclusion, and we should say so plainly if it
comes up.** Our 2026-07-08 and 2026-07-30 head-to-heads found Voyage *slightly
worse* (52.5% vs 55.0%). Those ran **40 fixtures on two courses**, where the 95%
interval on R@3 is about ±15 pp — a 2.5 pp gap was never a result. At 1,008
fixtures over 16 courses the sign reverses and the margin is 50x the noise floor.
We were wrong about them, on too small a sample, and the correction is in their
favor.

## 3. What we are asking for

1. **Embeddings pricing, as the primary ask.** Combined-volume pricing across
   embeddings and rerank versus the two lines priced separately.
2. **Specifically: what does `voyage-3.5` cost us over `voyage-3.5-lite`?** We
   have measured the quality gap at +1.9 pp. Make them price that gap.
3. **A pilot allowance for the one-time reindex.** Full-catalog re-embed is
   64.1 M tokens — $3.85 at list on `voyage-3.5`, $1.28 on lite. Small enough
   that it is a goodwill ask, not a budget item.

### Be honest about our exposure

Reranking is not in production, so we cannot credibly promise near-term rerank
volume. And our own analysis says the correct rerank configuration is *gated*,
which cuts what we would spend with them by ~30%. **Quote the gated number.** It
would be bad faith to quote always-on volume knowing we intend to gate.

## 4. Questions for Voyage

Reordered by how much the answer changes our decision. **Four questions from the
earlier draft are struck — we measured them ourselves.**

### The one that matters most

1. **Does rerank-2.5 add measurable recall on top of `voyage-3.5` retrieval, or
   is it largely correcting weaker embeddings?** Every rerank number we have sits
   on OpenAI vectors. Since Voyage embeddings alone roughly match always-on
   reranking, the rerank business case could either evaporate after we switch or
   compound into a materially better stack. We have not measured it — it needs
   one combined run — and it decides whether we are a one-line or two-line
   customer. Ask them what they see across their customer base.

2. **Short, keyword-shaped queries are our dominant retrieval problem, and no
   configuration we control fixes them.** Real learner queries have a median of
   39 characters; 61% are not syntactically questions. On queries over 50
   characters we retrieve at 75–92%. On the short mode — **58% of real traffic** —
   nothing gets past 49%, with either vendor. Voyage leads OpenAI by 8 pp there
   and reranking leads Voyage by 3–4 pp, but those are rearrangements of a bad
   number. What do they recommend for bare-noun-phrase retrieval: a different
   model, query expansion, `input_type` handling we are not doing, hybrid
   lexical+dense? This is the highest-value thing they could tell us.

### Commercial

3. **Is there a floor or minimum**, and what happens if our forecast is wrong in
   either direction? Our projections come off a 1,008-query benchmark and a
   162-course corpus, not a bill.
4. **How does pricing scale from pilot to 100k MAU?** We need the shape of the
   curve. The internal decision is gate-at-70%-coverage versus always-on, a ~1.4x
   volume difference on rerank.
5. **Production rate limits per key and per organization.** We have been running
   on a key whose limits were lifted by a payment method on file, and we now know
   that one key authorizes every endpoint — so we would like the per-endpoint
   limits stated explicitly.

### Technical — embeddings

6. **`voyage-4` / `voyage-4-lite`: should we be testing them instead?** Our
   40-fixture run put voyage-4 *below* 3.5, but that sample proved nothing. Since
   `voyage-4-lite` is also $0.02/MTok, what should we expect at 1,008 fixtures on
   a course-scoped corpus of ~681 vectors per query?
7. **Recommended width at our corpus size.** We measured 2048 buying nothing over
   native 1024 (90.0% vs 89.6%, against a 0.2 pp floor). Is 1024 the right
   default, and does that hold as a course grows?
8. **Deprecation and version pinning on the embedding models specifically.** A
   model change means a full 64 M-token reindex for us, not a config flip. How
   much notice, and how long are superseded versions served?

- ~~*`voyage-3.5` vs `voyage-3.5-lite` for our corpus*~~ — **measured: +10.4 pp
  vs +8.5 pp.** Now question 2 in Section 3 instead.
- ~~*What is the accuracy cost of MRL dimension reduction?*~~ — **measured, and
  it is a point in their favor.** We verified their server-side `output_dimension`
  is an exact prefix truncation plus renormalization (cosine 1.00000000 against
  locally truncated vectors, residual at float32 rounding). `voyage-3.5` at **256
  dims beats OpenAI at 1536 by +8.6 pp on one sixth the storage.** Worth
  crediting them for out loud — their MRL story is real on our corpus.
- ~~*Does asymmetric `input_type` matter for short messy queries?*~~ —
  **measured, and the concern was unfounded in their favor.** `input_type: query`
  beats `document` in **every** length bucket including bare keywords; getting it
  wrong halves recall (61.0% → 30.2%). Our adapter already does it correctly.
- ~~*Migration cost / bulk-embedding allowance*~~ — folded into ask 3 above now
  that we have the real number ($3.85).

### Technical — reranking

9. **Should the reranker be declining work?** Always-on reranking demotes **68 of
   557** already-correct top-1 results while rescuing 126. We measured the damage
   concentrated where the embedding stage was already confident. Is there a
   server-side confidence signal or documented pattern for skipping those, or do
   we keep rolling our own client-side gate?
10. **Are rerank-2.5 scores calibrated across queries**, or only relative
    ordering within one call? If comparable, our gate gets much simpler.
11. **P95 rerank latency at pool 20 from a region near us.** We measure +235 ms
    P50 / +383 ms P95 added. It sits in a learner-facing chat path, so P95 is what
    we care about. Is that what they expect, and does `rerank-2.5-lite` move it?

### Operational and contractual

12. **Data handling — retention, logging, training use, for both endpoints.** We
    run under FERPA-adjacent obligations and our privacy provider declares every
    third-party endpoint. Written answer, not a docs link.
13. **Zero-data-retention** — available, at what price?
14. **DPA** we can execute. Our failover chain will not enable a provider until
    the institution confirms the processing agreements exist.
15. **Regional processing / residency**, if any.
16. **Status page and incident notification path.** We had a nine-day silent
    failure with a different vendor this month because their errors were being
    swallowed.

## 5. What we are NOT asking for

Keeps the conversation short: no hosted vector database, no chat models, not
evaluating them for generation. Retrieval only — embeddings and reranking.

## 6. Open items on our side, not theirs

Do not raise these as vendor questions; they are ours to fix.

- **`rag_min_similarity` = 0.25 is mis-calibrated for short queries** — it
  discards the correct chunk on 23.3% of them (9.2% overall) before any ranking.
  It was validated on question-shaped fixtures where it cost nothing (0 of
  1,008). Drop it to about 0.15; free and safe.

  **Correction to an earlier version of this brief, which called this "a bigger
  lever than either vendor change." It is not — that was wrong by roughly
  7-25x.** Most of those discarded targets ranked far too low to be retrieved
  anyway: of the 93, only 4 were inside the top 3. Removing the floor is worth
  **0.40 pp** of R@3 with rerank off, and about **1.24 pp** with it on, against
  +8.7 pp for the embedding switch and +10.8 pp for reranking. The floor is not
  what loses these queries — the embedding is. **Do not raise this in the meeting
  as a major finding.**
- **Prod `rerank_candidates` = 50** — 2.5x the cost of pool 20 for no measurable
  gain. Fix regardless of any vendor decision.
- **The confidence gate is still not built.** Now modeled: at margin < 0.086 it
  covers 70% of queries for +9.6 pp at ~$0.52/1k. Build it for cost and for the
  18 avoided top-1 breakages — **not for latency.** Newly measured: gating saves
  only 43 ms of P50, because it fires precisely on the ambiguous queries and does
  nothing about the embed stage that dominates the floor.
- **Rerank-on-Voyage is unmeasured** and is the top follow-up, ~$0.75 for one run.
- ~~*The Gemini chat rate is unconfirmed*~~ — **resolved.** `gemini-2.5-flash` is
  **$0.30 in / $2.50 out** per MTok, verified two ways. The $0.10/$0.40 figure was
  the Gemini **2.0** Flash rate misapplied. All "% of chat spend" figures now
  stand as written rather than provisional.
- ~~*Voyage embeddings never benchmarked against our corpus*~~ — **done, Section 0.**

## 7. Caveats to keep in your back pocket

If they push on methodology, these are the honest limits — better that we name
them first:

- **All figures are recall-mode** against each fixture's own ground-truth chunk.
  No judged relevance (nDCG), so a different-but-equally-good chunk counts as a
  miss.
- **Latency is warm-path.** Vector load is amortized across 1,008 queries in one
  process. Real cold retrieval was previously measured at 835 ms typical. The
  numbers above are the *incremental* vendor cost, not learner-visible total.
- **The production-shaped fixture's short queries may be unfairly hard.** Some
  read as truncated fragments where discriminating content was lost with the
  surface form. The bimodal *shape* is trustworthy; the exact 28–36% level on the
  ≤20-char bucket is not, and 52% should not be quoted as "production recall"
  without a short-but-specific fixture to check it against.
- **No fixture models conversation history**, yet 76.8% of real messages are turn
  2+ and 21.6% carry a bare deictic whose referent the retriever never sees. How
  much of the short-query problem is really a *context* problem is plausibly the
  largest unmeasured factor in all of this.
- **16 of 162 courses measured**, as in all prior SOLA retrieval work.

## 8. Total measurement spend

**$3.14** against the $25 ceiling, for a three-model, four-width, two-fixture,
two-`input_type` sweep. Two things made that possible: the MRL prefix property
(one embedding pass yields every width) and an on-disk document-vector cache.
Worth mentioning to them only as evidence we are a cheap, technically competent
customer to support.
