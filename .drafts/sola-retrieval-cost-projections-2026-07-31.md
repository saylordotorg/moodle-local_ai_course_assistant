# SOLA retrieval: benchmark summary and cost projections at 5k / 10k / 25k

**Date:** 2026-07-31
**Supersedes:** every earlier rerank cost figure in `.drafts/` (see the cleanup list at the end)
**Benchmark source:** `.drafts/sola-model-benchmark-2026-07-30.md` — 1,008 queries across 16 courses, four pool sizes, run 2026-07-30

---

## Was a re-run needed?

**No.** The retrieval benchmark is one day old and is the most thorough one we have: 1,008 synthetic conversational queries across all 16 indexed courses, four `rerank_candidates` values, plus embeddings scored two independent ways (recall against ground truth, and LLM-judged relevance). Re-running would cost money and change nothing.

What *was* missing is the cost model, because every previous projection multiplied a correct per-query cost by a wrong volume. That is what this document fixes, using production measurements taken today.

---

## Benchmark summary (unchanged, restated)

### Reranker: Voyage rerank-2.5

| Pool | R@1 | R@3 | R@5 | MRR | Delta R@3 | Cost/query |
|---|---|---|---|---|---|---|
| Embedding only | 55.3% | 79.2% | 86.8% | 0.687 | — | — |
| 30 | 72.7% | 89.3% | 93.6% | 0.817 | +10.1 pp | $0.00112 |
| **20** | 72.3% | 89.0% | 93.5% | 0.813 | +9.8 pp | **$0.00075** |
| 10 | 71.7% | 87.0% | 90.6% | 0.798 | +7.8 pp | $0.00037 |

Pool 20 gives up 0.3 pp of R@3 against pool 30 for 33% less cost. Pool 10 is where real degradation starts. Pool 5 was never tested — the CLI silently clamps `--candidates` to a floor of 10.

Per query, across all 1,008: rerank improved 33.5%, worsened 12.8%, left 53.7% unchanged. It **rescued 61.9% of targets that were outside the top 3**, and **broke 12.0% of targets the embedding stage had already put at rank 1**. Reranking is an excellent rescuer and a mediocre custodian — which is the basis for the per-course gating scope in `sola-per-course-rerank-gating-scope-2026-07-31.md`.

### Embeddings: OpenAI vs Voyage

| Method | OpenAI text-embedding-3-small | Voyage-3.5 |
|---|---|---|
| Recall mode (R@3, curated) | **55.0%** | 52.5% |
| Recall mode (MRR) | **0.468** | 0.451 |
| Judged (nDCG@5) | **0.951** | 0.943 |
| Judged (P@5) | **0.700** | 0.665 |
| Price per 1M tokens | **$0.02** | $0.06 |

OpenAI leads on both scoring methods and is 3x cheaper. **Keep OpenAI.** No migration case exists; this is now the third measurement agreeing (2026-07-08, plus both methods here).

---

## Cost model inputs (all measured, not assumed)

| Input | Value | Source |
|---|---|---|
| Turns per SOLA user per month | **5.07** | prod, July 2026: 6,184 turns / 1,220 users |
| Prompt tokens per chat call | **~5,110** | prod, `msgs.prompt_tokens` on Gemini rows |
| Completion tokens per chat call | **~168** | same |
| Blended chat cost per call | **$0.00184** | 3-provider live mix, below |
| Rerank cost per query (pool 20) | **$0.00075** | 1,008-query sweep |
| Message storage per turn | **~2.8 KB** | prod row sizes + row overhead |

Production currently runs a three-provider mix, so chat cost is blended:

| Model | Calls | Prompt / completion | $/call |
|---|---|---|---|
| gemini-2.5-flash | 2,733 | 5,110 / 168 | $0.00195 |
| gpt-4o-mini | 2,463 | 5,127 / 180 | $0.00088 |
| claude-haiku-4-5 | 1,585 | 1,594 / 312 | $0.00315 |
| **Blended** | 6,781 | — | **$0.00184** |

> **Note on prompt size.** Real prompts are ~5,110 tokens because they carry the system prompt, retrieved RAG chunks, and history. Benchmark prompts are far smaller. Using a benchmark-derived cost per call against real volume understates chat spend by roughly 4x — that error is what produced the earlier wrong conclusions, and it is worth not repeating.

---

## Projections

**Assumption: "students" = SOLA users active in a given month.** At the 20% adoption rate used previously, these correspond to roughly 25k / 50k / 125k total active learners.

| SOLA users | Turns/month | Chat $/mo | Rerank @10 | **Rerank @20** | Rerank @30 | Rerank @50 | Messages GB/yr |
|---|---|---|---|---|---|---|---|
| **5,000** | 25,300 | $47 | $9 | **$19** | $28 | $47 | 0.9 |
| **10,000** | 50,700 | $93 | $19 | **$38** | $57 | $95 | 1.8 |
| **25,000** | 126,700 | $234 | $47 | **$95** | $142 | $237 | 4.4 |

Query embedding cost is negligible at every scale (under $0.10/month even at 25,000 users) and is omitted.

### Rerank as a share of chat spend

This ratio is scale-invariant, which makes it the more useful number for a decision:

| Pool | Share of chat spend |
|---|---|
| 10 | 20.1% |
| **20** | **40.7%** |
| 30 | 60.8% |
| **50 (prod's current value)** | **101.3%** |

**At prod's configured `rerank_candidates=50`, switching reranking on would roughly double the AI bill.** At pool 20 it adds about 41%. That is the single strongest argument for dropping the setting to 20 before any enable — it is not a rounding-error optimization, it is the difference between a 41% and a 101% increase.

### What does not scale with student count

These are driven by course count (162), not enrolment:

| Quantity | Value |
|---|---|
| Chunks | 110,300 |
| Corpus tokens | 77.5M |
| One-time full index | **$1.55** |
| Full index build time | ~14 minutes |
| Embedding storage (JSON, as stored today) | 3.25 GB |
| Embedding storage (float32 binary) | 0.68 GB |
| Embedding storage (512d MRL binary) | 0.23 GB |

Retrieval is scoped per course, so a learner's query is compared against ~681 vectors regardless of catalog size. **Adding courses costs storage, not latency or recall.**

### A storage note worth acting on

The `msgs` table is dominated by non-conversation rows. On prod: 72,982 `system` rows versus 7,050 `user` and 6,798 `assistant` — **84% of rows are telemetry**, averaging 10.9 bytes each. They are only 11% of stored bytes but 12.3 rows are written per learner turn.

At 25,000 users that is ~18.7M rows/year rather than ~3M. Storage stays modest (4.4 GB/yr), but row count drives index size and query plans on a table that already has a `role_timecreated` index. Worth a retention policy or moving telemetry to its own table before the higher scale points, not urgent at 5k.

---

## Recommendations

1. **Keep OpenAI embeddings.** Settled by three independent measurements.
2. **Set `rerank_candidates=20`** everywhere before any rerank enable. Prod is at 50, which costs 2.5x for no measurable quality gain.
3. **Do not enable rerank site-wide.** At pool 20 it is +41% on the AI bill for +9.8 pp recall — defensible on weak courses, waste-plus-harm on strong ones. See the gating scope.
4. **Revisit the `msgs` telemetry rows** before 25k.
5. **Confirm the Gemini rate.** These figures use the rate card's $0.30/$2.50 per MTok. An earlier doc used $0.10/$0.40. If the lower figure is right, chat costs drop ~3x and reranking looks *worse* by comparison, strengthening recommendations 2 and 3.

---

## Cleanup: documents carrying superseded rerank figures

Every figure below predates the production measurement and is wrong by roughly an order of magnitude in one direction or the other.

**Trash (Google Docs, superseded — I have no delete capability, these need doing by hand):**

| Doc | Problem |
|---|---|
| [SOLA Model Benchmark — Claude 5 family vs. incumbents](https://docs.google.com/document/d/1XIPcOtpEETmVyPHp47KmpROx05oPWGGeWFwd4-KkJAo/edit) | rerank projected at $1,346/mo |
| [SOLA Model Benchmark — Claude 5 family + retrieval layer](https://docs.google.com/document/d/1oqxJDKmyB6zNUt2SYyRKr-AocAtM-i1mhXa26hn_k8E/edit) | rerank projected at $1,346/mo |

**Keep:** [SOLA Model Benchmark + Retrieval + Scale Projection — FINAL](https://docs.google.com/document/d/1rXgU8tJg5bW1lRwI0H254BiIoEeE4ksnlujSWZLy6Zw/edit)

**Correct, do not delete (these are historical records with other still-valid content):**

| File | Stale content |
|---|---|
| `sola-vendor-recommendations-2026-06-09.md` | rerank $63/mo; 58k turns/day at 25k users (13.8x too high) |
| `sola-vendor-optimization-by-mau-2026-06-09.md` | rerank $63/mo |
| `sola-multi-provider-optimization-plan.md` | rerank $63/mo |
| `sola-pilot-to-scale-vendor-recommendations-2026-06-01.md` | rerank $63/mo; stale turn assumption |
| `sola-rag-fixture-benchmark-2026-06-10.md` | rerank $63/mo, $0.00163/query |
| `sola-v5.11.0-external-actions.md` | stale turn assumption |
| `v5.11.0-release-notes-and-walkthrough.md` | rerank $63/month (shipped release note — annotate, do not rewrite history) |

The single most load-bearing correction is in `sola-vendor-recommendations-2026-06-09.md`: its capacity table drives the procurement and rate-limit conclusions, and its 70-turns-per-user assumption is 13.8x the measured 5.07.
