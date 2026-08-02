# SOLA RAG benchmark, confidence experiment, and usage projections

**Date:** 2026-08-01
**Supersedes:** all earlier rerank cost figures. Consolidates `sola-model-benchmark-2026-07-30.md` (retrieval sections), `sola-retrieval-cost-projections-2026-07-31.md`, and `sola-per-course-rerank-gating-scope-2026-07-31.md`.

---

## Headline

**Reranking should be gated per query on retrieval ambiguity, not enabled site-wide.** A ground-truth-free signal available at query time — the cosine margin between the top-1 and top-3 candidates — predicts when reranking helps, and it works well enough to act on.

Gating at 50% coverage retains **88% of the recall lift**, avoids **52% of the cases where reranking damages an already-correct result**, and **halves the cost**. That is a better operating point than either extreme.

| Configuration | R@3 | Top-1 breakages | Deep rescues | Rerank cost (% of chat spend) |
|---|---|---|---|---|
| Never rerank | 79.2% | 0 | 0 | 0% |
| **Gated at margin < 0.056** | **88.1%** | **32** | **110** | **20.4%** |
| Gated at margin < 0.086 | 89.2% | 48 | 124 | 28.5% |
| Always rerank | 89.3% | 67 | 130 | 40.7% |

---

## 1. The confidence experiment (new)

### Question

The per-course gating scope proposed measuring each course's baseline recall and gating on a threshold. That needs ground truth, and our only source of ground truth is synthetic questions, which are measurably easier than real ones. The alternative was to gate **per query** on how confident the embedding stage looks — no labels needed, adapts within a course, and targets the harm directly.

That was untested, and the harness did not emit the necessary signal. It does now.

### Method

`run_rag_fixture_benchmark.php` was patched to record four ground-truth-free values per query, all available at retrieval time in production:

- `top1_cosine_score`, `top2`, `top3`, `top5` — the raw similarity scores
- `margin_1_2`, `margin_1_3` — how decisively the best candidate beats its rivals

The 1,008-query conversational set (16 courses, 63 questions each) was re-run at pool 30 with these recorded. Every query therefore has: its confidence signals, its embedding-only rank, and its post-rerank rank.

The test is not "is there a correlation" but **"does a gating rule beat always-on"** — for a rule *rerank only when signal < T*, what coverage, recall, and cost does each threshold produce?

### Result: absolute similarity does not work

| Decile of top-1 cosine | Range | Baseline R@3 | With rerank | Delta |
|---|---|---|---|---|
| 1 (least confident) | 0.422-0.587 | 74.3% | 93.1% | +18.8 pp |
| 2 | 0.587-0.618 | 86.3% | 94.1% | +7.8 pp |
| 5 | 0.659-0.675 | 78.4% | 86.3% | +7.8 pp |
| 8 | 0.708-0.724 | 81.4% | 85.3% | +3.9 pp |
| 10 (most confident) | 0.748-0.841 | 90.1% | 90.1% | +0.0 pp |

The extremes behave as hoped, but the middle is noisy and non-monotone. Gating on it requires 90% coverage to retain the full lift — barely better than always-on. **Rejected.**

The reason is intuitive in hindsight: absolute cosine similarity varies with course vocabulary and chunk style, so a threshold that means "unsure" in one course means "confident" in another.

### Result: the margin works

| Decile of margin (top1 − top3) | Range | Baseline R@3 | With rerank | Delta |
|---|---|---|---|---|
| 1 (most ambiguous) | 0.001-0.016 | 54.5% | 79.2% | **+24.8 pp** |
| 2 | 0.016-0.025 | 64.7% | 86.3% | +21.6 pp |
| 3 | 0.025-0.035 | 63.7% | 79.4% | +15.7 pp |
| 4 | 0.035-0.046 | 67.6% | 82.4% | +14.7 pp |
| 5 | 0.046-0.056 | 72.5% | 85.3% | +12.7 pp |
| 6 | 0.056-0.069 | 87.1% | 91.1% | +4.0 pp |
| 7 | 0.069-0.086 | 89.2% | 95.1% | +5.9 pp |
| 8 | 0.086-0.107 | 96.1% | 95.1% | **−1.0 pp** |
| 9 | 0.107-0.147 | 97.1% | 99.0% | +2.0 pp |
| 10 (least ambiguous) | 0.147-0.451 | 99.0% | 99.0% | **+0.0 pp** |

This is close to monotone and the spread is large: **+24.8 pp in the most ambiguous decile, +0.0 pp in the least.** Where the embedding stage is decisive it is already right 99% of the time, and reranking has nothing to add.

Note that the margin is also a good predictor of baseline quality on its own — the most ambiguous decile starts at 54.5% recall, the least at 99.0%. It identifies hard queries, and hard queries are where reranking earns its cost.

### The operating curve

Rule: *rerank only when margin(top1, top3) < T*.

| T | Coverage | R@3 | vs always | Breakages (avoided) | Rescues (lost) | Rerank cost |
|---|---|---|---|---|---|---|
| 0.025 | 20% | 83.8% | −5.5 pp | 11 (56 avoided) | 54 (76 lost) | 8.1% |
| 0.035 | 30% | 85.3% | −4.0 pp | 20 (47) | 74 (56) | 12.2% |
| 0.046 | 40% | 86.8% | −2.5 pp | 27 (40) | 95 (35) | 16.3% |
| **0.056** | **50%** | **88.1%** | **−1.2 pp** | **32 (35)** | **110 (20)** | **20.4%** |
| 0.069 | 60% | 88.5% | −0.8 pp | 41 (26) | 117 (13) | 24.4% |
| **0.086** | **70%** | **89.2%** | **−0.1 pp** | **48 (19)** | **124 (6)** | **28.5%** |
| 0.107 | 80% | 89.1% | −0.2 pp | 56 (11) | 127 (3) | 32.5% |
| — | 100% | 89.3% | — | 67 (0) | 130 (0) | 40.7% |

Two defensible operating points:

- **margin < 0.086 (70% coverage)** — recall is statistically indistinguishable from always-on (89.2% vs 89.3%), cost drops 30%, and 19 of 67 breakages are avoided. Effectively free.
- **margin < 0.056 (50% coverage)** — recall costs 1.2 pp, cost halves, and **more than half the breakages are avoided**. The better choice if the 12% top-1 damage rate is what concerns you.

### Verdict against the stated bar

I said beforehand this should only proceed if a threshold retained most of the lift while reranking "well under half" the queries. **It does not clear that bar as written** — 50% coverage is the point at which recall starts to degrade noticeably, and getting near-full recall needs 70%.

It clears a weaker but still useful bar: a real, monotone signal that halves cost for ~1 pp of recall, or cuts cost 30% for essentially nothing. **Recommendation: build it, at margin < 0.086, but on quality grounds rather than cost.** The absolute savings are small ($27/month at 25,000 users); the reason to do it is that it avoids nearly a third of the cases where reranking makes a correct answer worse, for free.

---

## 2. Rerank pool size

| Pool | R@1 | R@3 | R@5 | MRR | Delta R@3 | Cost/query |
|---|---|---|---|---|---|---|
| Embedding only | 55.3% | 79.2% | 86.8% | 0.687 | — | — |
| 30 | 72.7% | 89.3% | 93.6% | 0.817 | +10.1 pp | $0.00112 |
| **20** | 72.3% | 89.0% | 93.5% | 0.813 | +9.8 pp | **$0.00075** |
| 10 | 71.7% | 87.0% | 90.6% | 0.798 | +7.8 pp | $0.00037 |

Pool 20 gives up 0.3 pp against pool 30 for 33% less cost. Pool 10 is where real degradation starts. **Production is configured at 50**, which costs 2.5x pool 20 for no measurable gain.

Pool 5 was never tested — the CLI clamps `--candidates` to a floor of 10.

---

## 3. Embeddings

| Method | OpenAI text-embedding-3-small | Voyage-3.5 |
|---|---|---|
| Recall R@3 (curated) | **55.0%** | 52.5% |
| Recall MRR | **0.468** | 0.451 |
| Judged nDCG@5 | **0.951** | 0.943 |
| Judged P@5 | **0.700** | 0.665 |
| Price per 1M tokens | **$0.02** | $0.06 |

**Keep OpenAI.** Three independent measurements now agree. No migration case.

---

## 4. Per-course variance

Reranking helped 14 of 16 courses, was neutral on one, and hurt the one already at 98.4% baseline recall. The gain tracks baseline weakness: course130 (71.4% baseline) gained +23.8 pp; course7 (98.4%) lost 3.2 pp.

The confidence gate above largely subsumes per-course gating, and does it better: it adapts *within* a course, needs no per-course measurement, and cannot go stale as content changes. Per-course overrides remain available for anything the query-level signal misses.

---

## 5. Usage and cost projections

**Assumption: "students" = SOLA users active in a given month.** At 20% adoption these correspond to roughly 25k / 50k / 125k total active learners.

All inputs measured on production, not assumed:

| Input | Value | Source |
|---|---|---|
| Turns per SOLA user per month | 5.07 | prod July 2026: 6,184 turns / 1,220 users |
| Prompt / completion tokens per call | ~5,110 / ~168 | prod `msgs` rows |
| Blended chat cost per call | $0.00184 | live 3-provider mix |
| Message storage per turn | ~2.8 KB | prod row sizes |

| SOLA users | Turns/month | Chat $/mo | Rerank @20 always | **Rerank @20 gated (70%)** | Messages GB/yr |
|---|---|---|---|---|---|
| **5,000** | 25,300 | $47 | $19 | **$13** | 0.9 |
| **10,000** | 50,700 | $93 | $38 | **$27** | 1.8 |
| **25,000** | 126,700 | $234 | $95 | **$67** | 4.4 |

Rerank as a share of chat spend, scale-invariant:

| Configuration | Share of chat spend |
|---|---|
| Pool 20, gated at 50% coverage | 20.4% |
| Pool 20, gated at 70% coverage | 28.5% |
| Pool 20, always | 40.7% |
| Pool 50, always (**prod's current setting**) | 101.3% |

**At prod's configured pool 50, enabling reranking would roughly double the AI bill.** Pool 20 with a confidence gate brings that to roughly a quarter.

### Fixed costs (course-driven, not enrolment-driven)

| Quantity | 162 courses |
|---|---|
| Chunks | 110,300 |
| Corpus tokens | 77.5M |
| One-time full index | **$1.55** |
| Full index build | ~14 minutes |
| Embedding storage (JSON, as stored) | 3.25 GB |
| Embedding storage (float32 binary) | 0.68 GB |
| Embedding storage (512d MRL binary) | 0.23 GB |

Retrieval is scoped per course, so a query is compared against ~681 vectors regardless of catalog size. Adding courses costs storage, not latency or recall.

### Storage note

84% of `msgs` rows are telemetry — 72,982 `system` rows at 10.9 bytes versus 7,050 user and 6,798 assistant, i.e. 12.3 rows per learner turn. Bytes stay small but row count reaches ~18.7M/year at 25,000 users. Worth a retention policy before that scale point.

---

## 6. Recommendations

1. **Implement the confidence gate at `margin(top1, top3) < 0.086`.** Recall is indistinguishable from always-on, cost drops ~30%, and it avoids 19 of 67 cases where reranking damages a correct result. Use 0.056 instead if halving cost matters more than 1.2 pp of recall.
2. **Set `rerank_candidates=20`** everywhere. Prod is at 50.
3. **Keep OpenAI embeddings.**
4. **Do not enable reranking site-wide at pool 50** under any circumstances — it would double the AI bill.
5. **Revisit `msgs` telemetry rows** before 25,000 users.
6. **Confirm the Gemini rate.** These figures use the rate card's $0.30/$2.50 per MTok; an earlier doc used $0.10/$0.40. If the lower figure is right, chat cost drops ~3x and every rerank ratio above triples, strengthening 1, 2 and 4.

---

## 7. What this does not establish

- **The threshold is tuned on synthetic questions.** They are measurably easier than real learner queries (79.2% vs 55.0% baseline R@3 on the same corpus), because a generated question inherits its source chunk's vocabulary. The *shape* of the margin relationship should transfer; the specific value 0.086 may not. Validate against sampled production queries before pinning it.
- **Sixteen courses, all dev-indexed.** The other ~146 production courses were not measured.
- **Single pass.** One run per query, no repetition.
- **The gate is untested in the live retriever.** These results are computed offline from recorded ranks; wiring it into `rag_retriever` and confirming behaviour under real traffic is a separate step.
- **Latency was not modelled for the gate.** Skipping reranking on 30% of queries should reduce median latency, but that was not measured.

---

## Appendix: reproducing

```
php admin/cli/run_rag_fixture_benchmark.php \
  --fixtures=tests/golden/rag_fixtures_conv_1k_recall.json \
  --candidates=30 --topk=10 --voyage-apikey=<key> --out=/tmp/conf.json
```

The per-fixture records now carry `top1_cosine_score`, `top2_cosine_score`, `top3_cosine_score`, `top5_cosine_score`, `margin_1_2` and `margin_1_3`. The gating analysis is a pure post-process over that file — no further API calls are needed to re-derive any table in section 1.
