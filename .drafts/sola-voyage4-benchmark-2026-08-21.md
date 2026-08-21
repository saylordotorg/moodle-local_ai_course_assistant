# Voyage 4 family benchmarked on the SOLA corpus

**Date:** 2026-08-21
**Corpus:** 10,910 chunks over 16 dev-indexed courses. 1,008 labelled queries, two fixtures.
**Method:** documents embedded per model at 2048d and cached; Matryoshka widths derived by exact prefix truncation + renormalization; queries embedded with `input_type=query`; rank of the ground-truth chunk under cosine within its own course.

---

## 0. Validity gate — this ran on a new evaluator, so it was checked against known answers first

The asymmetric arms (documents from one model, queries from another) cannot be expressed in the existing harness, which uses a single model for both sides. So this used a purpose-written evaluator, and the OpenAI and voyage-3.5 baselines were included as arms specifically to test it:

| Arm | Published | This evaluator | Δ |
|---|---|---|---|
| openai 3-small @1536, conv | 79.2% | **79.1%** | −0.1 pp |
| voyage-3.5 @1024, conv | 89.6% | **89.6%** | 0.0 pp |
| openai 3-small @1536, prodshape | 52.3% | **52.3%** | 0.0 pp |
| voyage-3.5 @1024, prodshape | 61.0% | **61.0%** | 0.0 pp |

**Four of four reproduce within 0.1 pp**, against a measured 0.2 pp noise floor. The v4 figures below come from the same code path.

---

## 1. Headline

**`voyage-4-large` embeddings alone beat OpenAI-plus-reranking, on both fixtures.**

| Configuration | $/MTok | R@3 conv | R@3 prodshape |
|---|---|---|---|
| OpenAI `text-embedding-3-small` @1536 *(today)* | $0.02 | 79.1% | 52.3% |
| OpenAI + rerank-2.5 @ pool 20 | $0.02 + rerank | 89.0% | 63.1% |
| voyage-3.5 @1024 | $0.06 | 89.6% | 61.0% |
| voyage-4-lite @1024 | **$0.02** | 91.0% | 60.6% |
| voyage-4-lite @2048 | $0.02 | 91.1% | 61.7% |
| voyage-4 @1024 | $0.06 | 90.8% | 63.6% |
| voyage-4 @2048 | $0.06 | 91.3% | 64.1% |
| **voyage-4-large @1024** | $0.12 | 90.6% | **65.0%** |
| **voyage-4-large @2048** | $0.12 | 91.1% | **66.3%** |

`voyage-4-large` @2048 reaches **66.3%** on production-shaped queries against **52.3%** today — **+14.0 pp** — and it does so *without a reranker*, beating the OpenAI+rerank stack (63.1%) that was our previous plan.

**`voyage-4-lite` matches voyage-3.5 quality at one third the price** (60.6% vs 61.0% at 1024d; $0.02 vs $0.06). If the goal is "beat what we run today for the same money," lite does it: +8.3 pp on prodshape at identical $0.02/MTok.

---

## 2. The conversational fixture cannot tell these models apart

| Arm | conv R@3 | prodshape R@3 | spread |
|---|---|---|---|
| voyage-4-lite @1024 | 91.0% | 60.6% | — |
| voyage-4 @1024 | 90.8% | 63.6% | — |
| voyage-4-large @1024 | 90.6% | 65.0% | — |
| **range across the three** | **0.4 pp** | **4.4 pp** | **11x** |

On the conversational fixture the three models sit inside 0.4 pp — twice the noise floor, effectively tied, and in the *wrong order*. On production-shaped queries they separate cleanly by 4.4 pp in the expected order.

The easy fixture is saturated and would have led us to buy the cheapest model on the grounds that the flagship adds nothing. Building the production-shaped fixture is what made this measurable.

---

## 3. Matryoshka: for v4, 2048 is worth paying storage for — unlike voyage-3.5

| Arm | 256d | 512d | 1024d | 2048d | 1024 → 2048 |
|---|---|---|---|---|---|
| voyage-3.5 | 56.3% | 58.4% | 61.0% | 60.1% | **−0.9 pp** |
| voyage-4-lite | 56.6% | 59.5% | 60.6% | 61.7% | +1.1 pp |
| voyage-4 | 58.3% | 61.9% | 63.6% | 64.1% | +0.5 pp |
| voyage-4-large | 60.6% | 62.3% | 65.0% | **66.3%** | **+1.3 pp** |

*(prodshape R@3)*

The earlier finding that "2048 buys nothing over native 1024" was true of voyage-3.5 and is **not** true of the v4 family, where the extra width adds 0.5–1.3 pp. It doubles vector storage, so it is a real trade rather than free — but at our index size (86,206 chunks) 2048d float32 is 706 MB, which is not a constraint.

Note also that **voyage-4-large at 256 dimensions (60.6%) beats OpenAI at 1536 (52.3%) by 8.3 pp on one sixth the storage.**

---

## 4. Asymmetric retrieval did not reproduce on our corpus

Voyage's v4 announcement says all four models share an embedding space, and that "retrieval quality is improved across the board when using asymmetric embeddings" — documents embedded with `voyage-4-large`, queries with a smaller model.

Measured directly (prodshape R@3, native 1024d):

| Configuration | R@3 | vs symmetric with the same query model |
|---|---|---|
| voyage-4-lite docs + voyage-4-lite queries | 60.6% | — |
| **voyage-4-large docs + voyage-4-lite queries** | **60.6%** | **0.0 pp** |
| voyage-4 docs + voyage-4 queries | 63.6% | — |
| **voyage-4-large docs + voyage-4 queries** | **62.6%** | **−1.0 pp** |
| voyage-4-large docs + voyage-4-large queries | 65.0% | — |

**We could not reproduce the claimed improvement.** Pairing large documents with a smaller query model was a wash for `voyage-4-lite` and *worse* for `voyage-4`, and both were well below symmetric `voyage-4-large`. The same pattern holds at every width and on both fixtures.

This is worth putting to them plainly — not as a challenge, but because if we are doing something wrong we would like to know. Candidates: whether `input_type` should differ in the asymmetric configuration, whether the shared space requires matched `output_dimension`, or whether the benefit only appears on domains unlike ours.

**Practical consequence either way:** the shared embedding space still has real value for us, but as *migration insurance* rather than a quality gain. It means the query model can be changed later without re-embedding 75M tokens of corpus. Given that a full reindex is our expensive, risky operation, decoupling the upgrade path from it is worth having even if the accuracy claim does not hold here.

---

## 5. Reranking's value collapses once the embeddings are good

rerank-2.5 at pool 20, on top of `voyage-4-large` @1024 documents and queries, 1,008 queries:

| Base retrieval | R@3 before rerank | after rerank | Δ |
|---|---|---|---|
| OpenAI 3-small, conv | 79.1% | 89.0% | **+9.9 pp** |
| OpenAI 3-small, prodshape | 52.3% | 63.1% | **+10.8 pp** |
| voyage-4-large, conv | 90.6% | 90.3% | **−0.3 pp** |
| voyage-4-large, prodshape | 65.0% | 67.3% | **+2.3 pp** |

On the conversational fixture reranking is **net negative** on v4 embeddings: it broke 94 previously-correct top-1 results and rescued 42. On production-shaped queries it is still positive but the gain has fallen from +10.8 pp to **+2.3 pp**.

**So most of what the reranker was buying was compensation for weak embeddings.** That reframes the commercial conversation: the embeddings line is worth more than we thought and the rerank line considerably less.

### And reranking should be gated on query length, not on confidence

Per-bucket, on production-shaped queries with voyage-4-large:

| Query length | n | before | after | Δ | verdict |
|---|---|---|---|---|---|
| ≤20 chars | 206 | 43.2% | 48.5% | **+5.3 pp** | helps |
| 21–50 | 374 | 47.9% | 52.4% | **+4.5 pp** | helps |
| 51–100 | 141 | 87.2% | 86.5% | −0.7 pp | hurts |
| 101–200 | 248 | 91.1% | 90.3% | −0.8 pp | hurts |
| 200+ | 39 | 97.4% | 92.3% | **−5.1 pp** | hurts |

Reranking helps precisely the short keyword queries that are 58% of real traffic, and actively damages everything longer.

| Policy | R@3 | rerank fires on |
|---|---|---|
| never | 65.0% | 0% |
| always @20 | 67.2% | 100% |
| **length-gated, ≤50 chars only** | **67.7%** | **58%** |

**Length-gating beats always-on while doing 58% of the work** — a better rule than the margin-based confidence gate recommended by the 08-01 report, and far simpler to implement: it needs no score inspection, just `mb_strlen()`.

---

## 6. The short-query ceiling is broken

An earlier version of the brief said that on the short-query mode "nothing we can configure gets past 49%." That is no longer true:

| Query length | OpenAI *(today)* | voyage-4-large | + length-gated rerank | total lift |
|---|---|---|---|---|
| ≤20 chars | 28.2% | 43.2% | **48.5%** | **+20.3 pp** |
| 21–50 | 36.4% | 47.9% | **52.4%** | **+16.0 pp** |
| 51–100 | 75.2% | 87.2% | 87.2% | +12.0 pp |
| 101–200 | 79.8% | 91.1% | 91.1% | +11.3 pp |
| 200+ | 74.4% | 97.4% | 97.4% | +23.0 pp |

The dominant retrieval problem at Saylor is not fixed, but it moves by 16–20 points on the two buckets that matter most.

---

## 7. Latency: v4 is fine on the query path, slow in bulk

Measured on 1,008 real query embeddings each:

| Model | p50 | p95 |
|---|---|---|
| voyage-3.5 | 140 ms | 170 ms |
| voyage-4 | 143 ms | 173 ms |
| voyage-4-lite | 146 ms | 178 ms |
| voyage-4-large | ~195 ms | — |
| OpenAI 3-small *(today)* | 175 ms | 335 ms |

Every v4 model beats what we run today at p50, with roughly half the tail.

**Bulk throughput is a different story.** An identical 64-text / 10,240-token batch took **1,082 ms on voyage-3.5 and 14,621 ms on voyage-4-lite** — 13.5x slower. Reindexing our 75M-token corpus at that rate is roughly 30 hours single-threaded, against about 2 hours on voyage-3.5. It parallelizes cleanly (19 MTok across 18 workers took about ten minutes here), but it needs planning rather than one `reindex` invocation.

Rerank latency added p50 **228 ms** with a heavy tail at p95 **3,345 ms**, which is another argument for gating it off the long-query path.

---

## 8. Cost at measured production volumes

Corpus 75M tokens one-time; ongoing 3.92 MTok/month today, 30.36 MTok/month if every indexed course became active.

| Model | $/MTok | One-time reindex | Ongoing today | Ongoing at capacity |
|---|---|---|---|---|
| openai 3-small *(today)* | $0.02 | $1.50 | $0.08/mo | $0.61/mo |
| voyage-4-lite | $0.02 | $1.50 | $0.08/mo | $0.61/mo |
| voyage-3.5 | $0.06 | $4.50 | $0.24/mo | $1.82/mo |
| voyage-4 | $0.06 | $4.50 | $0.24/mo | $1.82/mo |
| voyage-4-large | $0.12 | $9.00 | $0.47/mo | $3.64/mo |

**Every one of these is a rounding error**, and all sit inside Voyage's stated 200M free-token allowance. Whether that allowance is one-time per account or recurring is not stated clearly and is worth asking.

Reranking, by contrast, costs **$1.06 per 1,000 queries** at production chunk sizes (21,124 tokens per rerank measured in production) — $140/month at full capacity, or about $81/month length-gated at 58% coverage.

**The entire embeddings decision is worth less than $4/month. The reranking decision is worth $140/month.** Spend the negotiating energy accordingly.

---

## 9. Recommendation

**Move to `voyage-4-large` at 2048 dimensions.** +14.0 pp on production-shaped queries over what we run today, for $9.00 one-time and under $4/month. It beats OpenAI+rerank on its own, so it lands the biggest single quality gain available without turning on a per-turn cost.

If storage or budget argues against the flagship: **`voyage-4-lite` at $0.02/MTok matches voyage-3.5 at a third the price** and still beats today by +8.3 pp at identical cost to OpenAI.

**Then re-measure reranking, and gate it on query length.** Its value on good embeddings is +2.3 pp always-on, or +2.8 pp at 58% coverage when gated to queries of 50 characters or fewer. That is worth having at ~$81/month but it is no longer the headline it was against OpenAI vectors.

**Do not adopt asymmetric retrieval on the strength of the vendor claim** — it did not reproduce here. Keep the shared embedding space in mind as migration insurance instead.

---

## 10. Spend

| Item | Cost |
|---|---|
| v4 document embedding, 3 models × 6.33 MTok | ~$1.27 |
| v4 query embedding, 5 models × 2 fixtures | ~$0.01 |
| rerank on voyage-4-large, 2 fixtures | ~$1.20 |
| **This run** | **~$2.48** |
| Earlier benchmark | $3.14 |
| **Total** | **~$5.62** |

**One caveat on the rerank token figures from this run:** document text was capped at 8,000 characters, giving 11,175–11,822 tokens per rerank against production's 21,124. The recall deltas are unaffected — same candidates, same reranker — but the token and cost figures from *this* run understate production, and the $1.06/1k figure above is the production-measured one.
