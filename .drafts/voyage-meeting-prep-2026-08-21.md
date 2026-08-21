# Voyage AI meeting prep — 21 August 2026

Prepared for Tom. Benchmark numbers land in a companion doc,
`.drafts/sola-rag-rerank-benchmark-2026-08-21.md`; this is the negotiating
brief that does not depend on them.

**Read this first:** the numbers you may remember from June are superseded.
`.drafts/sola-rag-benchmark-and-projections-2026-08-01.md` replaced them, and
the story changed materially in Voyage's favor on quality and against it on
cost-at-current-config.

---

## 1. Where we actually are with Voyage today

One fact worth having straight before the call, because it is easy to overstate
the relationship:

**We are a rerank-only customer. We are not an embeddings customer.**

Verified on the dev fleet today:

| Setting | Value | Meaning |
|---|---|---|
| `rerank_apikey` | set | Voyage rerank-2.5 is live |
| `rerank_enabled` | `1` | on, on dev |
| `rerank_candidates` | `20` | matches the current recommendation |
| `embedding_provider` | unset | **embeddings run on OpenAI** `text-embedding-3-small` |
| `voyage_apikey` | unset | no Voyage embedding credential exists |

So the entire embeddings side of the account is greenfield. That is the thing to
trade with.

Also worth knowing: **production is not running reranking at all.** Prod's pool
is configured at 50, and at pool 50 always-on reranking costs 101.3% of chat
spend — it would roughly double the AI bill. Reranking is a dev-only
configuration today. Any volume commitment we make is therefore a forecast, not
an extrapolation of current spend.

## 2. What the August measurements actually say

From the 1,008-query conversational set across 16 courses, pool 30:

| Configuration | Recall@3 | Top-1 results broken | Deep rescues | Rerank cost as % of chat spend |
|---|---|---|---|---|
| Never rerank | 79.2% | 0 | 0 | 0% |
| Gated at margin < 0.056 | 88.1% | 32 | 110 | 20.4% |
| Gated at margin < 0.086 | 89.2% | 48 | 124 | 28.5% |
| Always rerank | 89.3% | 67 | 130 | 40.7% |
| Pool 50, always (prod's setting) | — | — | — | 101.3% |

Two things in that table matter for the conversation, and one of them is not
flattering:

- **The lift is real: +10.1 pp Recall@3.** On a tutor that answers from course
  content, that is the difference between citing the right page and citing a
  neighboring one.
- **Reranking is not purely additive.** Always-on breaks 67 previously-correct
  top-1 results while rescuing 130. It is net positive, but it is a trade, not a
  free win. We found the damage is concentrated where the embedding stage was
  already confident — in the least-ambiguous decile reranking adds +0.0 pp.

That second point is the basis of our gating work and it is a fair question to
put to them directly: their reranker is being asked to re-order results it
should arguably decline to touch.

## 3. Questions for Voyage

Ordered by how much the answer changes our decision.

### Commercial

1. **What does an embeddings commitment buy?** We are rerank-only today. If we
   move embeddings from OpenAI `text-embedding-3-small` to `voyage-3.5`, what
   does the combined-volume pricing look like versus the two lines priced
   separately? This is the main thing we came to find out.
2. **Is there a floor or minimum we would be signing up to**, and what happens
   if our forecast is wrong in either direction? Our reranking is not in
   production yet, so any number we give you is a projection off a 1,008-query
   benchmark and a 162-course corpus, not a bill you can look at.
3. **How does pricing scale between our pilot and 100k MAU?** We need the shape
   of the curve, not just a rate, because the internal decision is
   gate-at-50%-coverage versus always-on, and that is a 2x volume difference.
4. **Free-tier and rate-limit behavior.** We have been running on a key whose
   limits were lifted by having a payment method on file. What are the actual
   production rate limits per key, and per organization?

### Technical — embeddings

5. **`voyage-3.5` versus `voyage-3.5-lite` for our shape of corpus:** ~110,000
   chunks, 77.5M corpus tokens, retrieval scoped per course so a query is
   compared against roughly 681 vectors regardless of catalog size. At that
   per-query candidate count, does the larger model earn its cost?
6. **MRL `output_dimension`.** Our adapter already supports it. What is the
   accuracy cost of dropping to a smaller dimension at our corpus size, and do
   you have numbers for retrieval specifically rather than general benchmarks?
   Storage is a real cost for us at 110k chunks.
7. **Asymmetric `input_type`** (`query` versus `document`). We implement it. How
   much does it actually matter for short, messy learner queries — which is what
   we have, not clean well-formed questions?
8. **Migration cost.** Re-embedding 77.5M corpus tokens is a one-off. Is there
   any onboarding or bulk-embedding allowance for a provider switch?

### Technical — reranking

9. **Should the reranker be declining work?** We measured that reranking damages
   results specifically where the first-stage embedding was already confident
   (+0.0 pp in the least-ambiguous decile, +24.8 pp in the most). We are building
   a client-side confidence gate to skip those. Is there a server-side signal, a
   score-calibration output, or a documented pattern for this that we should use
   instead of rolling our own?
10. **Does rerank-2.5 return calibrated scores** we can threshold on, or only
    relative ordering? If the scores are comparable across queries, our gate gets
    much simpler.
11. **Latency at our pool sizes.** We measured roughly 306ms added at pool 30 in
    June. What should we expect at pool 20 and pool 50, at P50 and P95, from a
    region near us? Our reranking sits in a learner-facing chat path, so P95 is
    what we care about.
12. **`rerank-2.5` versus `rerank-2.5-lite`** on the same trade.

### Operational and contractual

13. **Data handling.** Are query and document text retained, logged, or used for
    training? We run under FERPA-adjacent obligations for learner data and our
    privacy provider declares every third-party endpoint. We need a written
    answer, not a docs link.
14. **Zero-data-retention option** — available, and at what price?
15. **DPA.** Do you have a standard data-processing agreement we can execute? Our
    failover chain will not enable a provider until the institution confirms the
    processing agreements exist.
16. **Regional processing / residency options**, if any.
17. **Deprecation policy.** How much notice on a model version, and how long are
    superseded versions served? We have been burned by a provider deprecating a
    parameter mid-flight this year.
18. **Status and incident communication.** Where do we watch, and what is the
    notification path? We had a nine-day silent-failure incident with a different
    vendor in August because their errors were being swallowed.

## 4. What we are NOT asking for

Useful to know so the conversation stays short: we do not need a hosted vector
database, we do not need their chat models, and we are not evaluating them for
generation. Retrieval only — embeddings and reranking.

## 5. Our leverage and our exposure, honestly

**Leverage:** the embeddings line is genuinely unclaimed and it is the larger of
the two by volume. We have a working adapter for their embeddings API already
written and tested, so switching is a config change, not a project. We also have
a measured, documented benchmark, which makes us a cheap customer to sell to.

**Exposure:** reranking is not in production, so we cannot credibly promise
near-term volume. And our own analysis says the correct configuration is *gated*
reranking, which roughly halves what we would otherwise spend with them. It
would be bad faith to quote them the always-on volume knowing we intend to gate.
Quote the gated number.

## 6. Open items on our side, not theirs

Flagging these so they are not mistaken for vendor questions:

- The confidence gate is recommendation #1 of the August report and **has not
  been built**. Until it is, the choice is 40.7% or 101.3% of chat spend.
- **Gate latency was never modeled.** We know it should cut median latency by
  skipping ~30-50% of rerank calls; we have not measured it.
- The **Gemini chat rate is unconfirmed** — $0.30/$2.50 versus $0.10/$0.40 per
  MTok. Every "rerank as % of chat spend" figure above moves by 3x depending on
  which is right, so treat those percentages as provisional.
- Voyage embeddings have **never been benchmarked against our corpus.** If the
  companion benchmark doc could not get a key in time, that measurement is
  outstanding and question 1 above should be framed as "we will measure this the
  week you give us a key."
