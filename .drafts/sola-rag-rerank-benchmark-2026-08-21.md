# SOLA RAG benchmark: Voyage embeddings vs OpenAI, and what reranking is still worth

**Date:** 2026-08-21
**Author:** benchmark run on the dev fleet (`dev.sylr.org`, EC2 `i-04c58928fad484d97`, Moodle 4.5)
**Purpose:** input to the VoyageAI vendor conversation, 2026-08-21
**Supersedes:** the embedding-provider conclusion in `.drafts/sola-vendor-recommendations-2026-06-09.md` (2026-07-08 addendum) and the 2026-07-30 Family-B result. Rerank figures **confirm** `.drafts/sola-rag-benchmark-and-projections-2026-08-01.md` rather than replace it.

---

## 0. Headline

**Switching embeddings from OpenAI `text-embedding-3-small` to Voyage `voyage-3.5` delivers most of what turning reranking on delivers, for roughly 0.2% of the running cost — and it makes retrieval faster rather than slower.**

Measured on 1,008 labeled queries over 16 indexed courses, on two fixtures: the existing conversational set, and a new fixture re-shaped to the measured production query distribution.

| Change | R@3 (conv fixture) | R@3 (production-shaped) | Marginal cost at 25k SOLA users | Added P50 latency |
|---|---|---|---|---|
| Today (OpenAI 1536d, no rerank) | 79.2% | 52.3% | — | — |
| **Turn on rerank-2.5 @ pool 20** | 89.0% *(+9.8 pp)* | 63.1% *(+10.8 pp)* | **+$95 / month** | **+233 ms** |
| **Switch embeddings to voyage-3.5** | 89.6% *(+10.4 pp)* | 61.0% *(+8.7 pp)* | **+$0.13 / month** *(+$3.85 one-time reindex)* | **−52 ms** (Voyage is faster) |

Read those two rows carefully, because they say different things on the two fixtures:

- On the **conversational** fixture the embedding switch slightly beats always-on reranking (89.6% vs 89.0%).
- On the **production-shaped** fixture reranking is slightly ahead (63.1% vs 61.0%, +2.1 pp), and it leads by 3–4 pp in the two short-query buckets that make up 58% of real traffic.

So the honest summary is **not** "Voyage embeddings replace the reranker." It is: *the two interventions buy comparable amounts of recall, and one of them costs about 500x less per month, runs faster, and does not damage 12% of already-correct top-1 results.* The embedding change should therefore land first and the rerank decision should be re-made afterwards — because whether reranking still adds anything **on top of** Voyage embeddings is the one thing this report could not measure (§9).

**This overturns the prior embedding conclusion.** The 2026-07-08 and 2026-07-30 head-to-heads found Voyage *slightly worse* than OpenAI (R@3 52.5% vs 55.0%). Those runs used **40 fixtures on two courses**, where the 95% confidence interval on R@3 is about ±15 pp — a 2.5 pp gap was never a result. At 1,008 fixtures over 16 courses the sign reverses and the margin is 50x the measured noise floor (p < 1e-15, paired).

---

## 1. Method, and what makes these numbers defensible

### Corpus and fixtures

| | |
|---|---|
| Courses | 16 dev-indexed courses (ids 2, 3, 4, 7, 8, 11, 43, 115, 116, 117, 128, 129, 130, 131, 132, 149) |
| Chunks | 10,910 non-empty (min 108, max 1,998 per course); 2,808 chars mean |
| Fixture A ("conv") | `tests/golden/rag_fixtures_conv_1k_recall.json` — 1,008 queries, 63 per course |
| Fixture B ("prodshape") | `tests/golden/rag_fixtures_prodshape_2026-08-21.json` — same 1,008 ground-truth chunks, surface form re-realized to the measured production query distribution |
| Metric | rank of the ground-truth chunk under cosine over that course's chunks; R@k and MRR |
| Ground truth | the chunk each question was generated from |

Every embedding arm **re-embeds all 10,910 chunk texts with the arm's own model and width**, so query and document vectors always come from the same model. No arm compares against the stored index. This matters — see §7.

### The noise floor, measured for the first time

No previous SOLA retrieval doc reports a repeat-run variance; all of them assert "inside noise" without a number. I ran two **independent implementations** of the same measurement — the plugin's own harness A/B path, and a standalone script that calls the vendor APIs directly — on the same fixture, same model, with separate API calls and different batching:

| Model | Run A (plugin harness) | Run B (standalone) | \|Δ R@3\| | Identical ranks |
|---|---|---|---|---|
| openai text-embedding-3-small 1536d | 79.0% | 79.2% | **0.20 pp** | 998 / 1008 (99.0%) |
| voyage-3.5 1024d | 89.6% | 89.6% | **0.00 pp** | 1005 / 1008 (99.7%) |

**Run-to-run noise floor on R@3 is ≤ 0.2 pp.** Retrieval is near-deterministic; embedding APIs return stable vectors, and the only movement is float-level tie-breaking. The *sampling* interval is wider — Wilson 95% CI on R@3 at n=1008 is ±2.5 pp for an unpaired comparison — but every arm here runs on the **same** 1,008 queries, so the correct test is paired:

| Comparison | Voyage-only wins | OpenAI-only wins | Net | McNemar exact p |
|---|---|---|---|---|
| R@1 | 200 | 74 | +126 | 1.5e-14 |
| **R@3** | **130** | **23** | **+107** | **7.9e-16** |
| R@5 | 89 | 18 | +71 | 1.8e-12 |

The Voyage advantage is 52x the noise floor and paired-significant beyond any reasonable doubt.

### Three independent validity checks

1. The OpenAI arm reproduces the published 2026-08-01 embedding-only baseline (55.3 / 79.2 / 86.8 / MRR 0.687) to **0.0–0.2 pp** on every metric.
2. The rerank arm at pool 20 reproduces the published pool-20 row (72.3 / 89.0 / 93.5 / 0.813, $0.00075/query) to **0.2 pp** and to the cent.
3. Production floor check: target-chunk cosine over 1,008 fixtures min 0.3207, mean 0.6443 — **0 of 1008** below the production `rag_min_similarity` = 0.25 floor. Nothing here is an artifact of a dimension mismatch (see §7).

---

## 2. Embeddings: the central commercial question

All arms, Fixture A (conv), 1,008 queries. Every MRL width is derived from a single max-width pass — see §3 for why that is exact.

| Arm | Dim | R@1 | R@3 | R@5 | MRR | Δ R@3 vs today | List price |
|---|---|---|---|---|---|---|---|
| **openai text-embedding-3-small** *(today)* | **1536** | 55.2% | **79.2%** | 86.7% | 0.686 | — | $0.02/MTok |
| openai text-embedding-3-small | 1024 | 53.9% | 78.2% | 86.8% | 0.678 | −1.0 pp | $0.02 |
| openai text-embedding-3-small | 512 | 53.1% | 77.4% | 85.6% | 0.672 | −1.8 pp | $0.02 |
| openai text-embedding-3-small | 256 | 51.5% | 75.9% | 84.1% | 0.655 | −3.3 pp | $0.02 |
| **voyage-3.5** | **2048** | 67.3% | **90.0%** | 94.0% | 0.790 | **+10.8 pp** | $0.06 |
| **voyage-3.5** | **1024** *(native)* | 67.7% | **89.6%** | 93.8% | 0.791 | **+10.4 pp** | $0.06 |
| voyage-3.5 | 512 | 67.2% | 87.9% | 93.9% | 0.784 | +8.7 pp | $0.06 |
| voyage-3.5 | 256 | 65.4% | 87.8% | 92.8% | 0.772 | +8.6 pp | $0.06 |
| **voyage-3.5-lite** | **1024** | 65.7% | **87.7%** | 92.6% | 0.771 | **+8.5 pp** | **$0.02** |
| voyage-3.5-lite | 2048 | 65.8% | 87.7% | 93.0% | 0.773 | +8.5 pp | $0.02 |
| voyage-3.5-lite | 512 | 64.7% | 86.3% | 92.4% | 0.765 | +7.1 pp | $0.02 |
| voyage-3.5-lite | 256 | 62.1% | 84.2% | 90.6% | 0.746 | +5.1 pp | $0.02 |

Three things to take into the meeting:

- **`voyage-3.5-lite` at price parity with OpenAI ($0.02/MTok) still delivers +8.5 pp.** The accuracy case does not depend on paying the $0.06 tier at all. That is our BATNA on price: if the 3x premium for `voyage-3.5` is not justified on the extra +1.9 pp, `lite` is already better than what we run.
- **`voyage-3.5` at 256 dimensions (87.8%) beats OpenAI at 1536 (79.2%) by +8.6 pp using one sixth of the vector storage.** Voyage's MRL story is real on our corpus. At 110,300 chunks that is 0.11 GB of float32 vectors against 0.68 GB today.
- **2048 buys nothing over the native 1024** (90.0% vs 89.6%, a 0.4 pp gap against a 0.2 pp noise floor). Recommend staying at native 1024. This also removes the confound in the earlier head-to-heads, which compared Voyage@1024 against OpenAI@1536 and flagged it as a caveat: Voyage wins at *equal* width (1024: 89.6% vs 78.2%, **+11.4 pp**) and wins at its *smallest* width against OpenAI's largest.

Latency favors Voyage as well — query-embed P50 **140 ms vs 192 ms**, P95 **170 ms vs 300 ms**. Voyage's tail is dramatically tighter.

---

## 3. Method note: MRL widths are exact prefix truncations

Before running the width sweep I verified that Voyage's server-side `output_dimension` is exactly a prefix truncation of the full vector plus L2 renormalization, by embedding the same text at 2048 and at each smaller width and comparing:

| Width | cosine(server-truncated, locally-truncated-from-2048) | max abs elementwise diff |
|---|---|---|
| 1024 | 1.00000000 | 3.4e-9 |
| 512 | 1.00000000 | 5.6e-9 |
| 256 | 1.00000000 | 8.4e-9 |

The residual is float32 rounding. This means **one embedding pass at max width yields every MRL width for free**, and it is why this report can price a four-width sweep across three models at under a dollar. The same holds for OpenAI's `dimensions` parameter. Useful operationally too: we can re-tune `embed_dimensions` downward without re-embedding the corpus.

---

## 4. Reranking: confirmed, and now with a latency model

Rerank-2.5 at pool 20, OpenAI embeddings, Fixture A, 1,008 queries. This reproduces 2026-08-01 and adds measured per-query latency.

| Arm | R@1 | R@3 | R@5 | MRR |
|---|---|---|---|---|
| Never rerank | 55.3% | 79.2% | 86.8% | 0.687 |
| Always rerank @20 | 72.1% | **89.0%** | 93.5% | 0.812 |

- Rerank tokens/query **15,018** → **$0.75 per 1,000 queries** at $0.05/MTok.
- Embed stage P50 **275 ms** / P95 **497 ms**. Rerank stage adds P50 **235 ms** / P95 **383 ms**.
- Total retrieval always-on: P50 **522 ms**, P95 **867 ms**.

### The confidence gate, with the latency hole closed

Recommendation #1 of the 2026-08-01 report was never implemented, and its latency was explicitly not modeled. Replaying the gate offline over the recorded `margin_1_3` and the recorded per-query latencies:

| Threshold T | Coverage | R@3 | Δ vs never | Top-1 broken | Deep rescues | P50 ms | P95 ms | $/1k queries |
|---|---|---|---|---|---|---|---|---|
| never | 0% | 79.2% | — | 0 | 0 | 275 | 497 | $0.00 |
| 0.0250 | 20% | 83.5% | +4.4 pp | 10 | 51 | 298 | 663 | $0.15 |
| 0.0459 | 40% | 86.5% | +7.3 pp | 27 | 92 | 364 | 730 | $0.30 |
| 0.0555 | 50% | 87.7% | +8.5 pp | 32 | 105 | 436 | 756 | $0.37 |
| **0.0861** | **70%** | **88.8%** | **+9.6 pp** | 50 | 120 | 479 | 811 | **$0.52** |
| 0.1455 | 90% | 89.0% | +9.8 pp | 63 | 125 | 512 | 839 | $0.68 |
| always | 100% | 89.0% | +9.8 pp | 68 | 126 | 522 | 867 | $0.75 |

The recall/cost shape confirms 2026-08-01. **The new finding is that gating is not a latency win.** At T=0.086 the gate saves only 43 ms of P50 (479 vs 522) and 56 ms of P95, because the gate fires precisely on the ambiguous queries and does nothing to shorten the embed stage that dominates the floor. If the gate is built, build it for cost and for avoiding 18 of 68 top-1 breakages — not for speed.

---

## 5. The fixture problem: prior RAG numbers overstate real recall by ~27 pp

Fixture B re-realizes the same 1,008 ground-truth chunks into the measured production query distribution (median 39 chars in production; 61% bare noun phrases; only 29% carry a question mark). Same corpus, same labels, same code — only the surface form changes.

| Arm | Fixture A (conv) R@3 | Fixture B (prodshape) R@3 | Fixture effect |
|---|---|---|---|
| openai 3-small 1536d | 79.2% | **52.3%** | **−26.9 pp** |
| voyage-3.5 1024d | 89.6% | **61.0%** | **−28.6 pp** |
| voyage-3.5 2048d | 90.0% | 60.1% | −29.9 pp |
| voyage-3.5 512d | 87.9% | 58.4% | −29.5 pp |
| voyage-3.5 256d | 87.8% | 56.3% | −31.5 pp |
| openai 3-small 1024d | 78.2% | 52.6% | −25.6 pp |
| openai 3-small 512d | 77.4% | 50.4% | −27.0 pp |
| openai 3-small 256d | 75.9% | 49.2% | −26.7 pp |

Two conclusions:

1. **Every published SOLA retrieval number, including the 79.2% / 89.0% pair, is measured on roughly the easiest quarter of real traffic.** Real production retrieval is very likely closer to 52% R@3 than to 79%. This is the most consequential finding in this report for the product, though not for the vendor choice.
2. **The Voyage advantage survives the harder fixture: +8.7 pp** (61.0% vs 52.3%), against the same ≤0.2 pp noise floor, and it holds in **every** length bucket (§5b). The vendor conclusion is robust to fixture shape; the absolute levels are not.

---

## 5b. Production-shaped queries: length buckets, `input_type`, and a silent floor

All figures below are the production-shaped fixture, n=1,008. Bucket sizes: ≤20 chars **206**, 21–50 **374**, 51–100 **141**, 101–200 **248**, 200+ **39**. The two shortest buckets are 58% of the fixture, matching the production distribution.

### R@3 by query length

| Arm | ALL | ≤20 | 21–50 | 51–100 | 101–200 | 200+ |
|---|---|---|---|---|---|---|
| openai 3-small 1536d | 52.3% | 28.6% | 36.1% | 75.2% | 79.8% | 74.4% |
| **voyage-3.5 1024d** | **61.0%** | **36.4%** | **44.4%** | **80.9%** | **90.3%** | **92.3%** |
| openai + rerank-2.5 @20 | 63.1% | 39.8% | 48.7% | 85.8% | 87.5% | 87.2% |
| voyage-3.5, wrong `input_type` | 30.2% | 16.5% | 20.9% | 37.6% | 47.2% | 56.4% |

**Retrieval quality is bimodal, and it tracks the query-length modes almost exactly.** On queries over 50 characters we retrieve well (75–92%). On the short keyword mode — 58% of real traffic — nothing we can configure gets past 49%. That is the dominant retrieval problem at Saylor, and it is much larger than the gap between any two vendors.

**Voyage leads OpenAI in every bucket**, paired within-bucket McNemar:

| Bucket | n | openai | voyage | Δ | wins / losses | p |
|---|---|---|---|---|---|---|
| ≤20 | 206 | 28.2% | 36.4% | +8.3 pp | 28 / 11 | 0.0095 |
| 21–50 | 374 | 36.4% | 44.4% | +8.0 pp | 52 / 22 | 0.00064 |
| 51–100 | 141 | 75.2% | 80.9% | +5.7 pp | 12 / 4 | 0.077 |
| 101–200 | 248 | 79.8% | 90.3% | +10.5 pp | 36 / 10 | 0.00016 |
| 200+ | 39 | 74.4% | 92.3% | +17.9 pp | 7 / 0 | 0.016 |

### Does reranking earn more on short queries?

Not disproportionately. Reranking's lift is broadly flat across buckets (+7.7 to +12.8 pp), so the "reranking is what rescues keyword queries" hypothesis is **not** supported as a *relative* effect. It matters more in absolute terms only because short-query recall is so low to begin with. Where rerank does beat the embedding switch is precisely those two short buckets (−3.4 pp and −4.3 pp for Voyage), and it loses on the two longest (+2.8 pp and +5.1 pp for Voyage).

### `input_type` is load-bearing, and the concern about it was unfounded

Voyage's asymmetric retrieval was flagged as a risk: `input_type: query` presumes question-shaped input, and 61% of real traffic is keyword-shaped. Measured directly, by embedding the identical queries both ways against the identical cached document vectors:

| Bucket | `input_type: query` | `input_type: document` | penalty for getting it wrong |
|---|---|---|---|
| ≤20 | 36.4% | 16.5% | −19.9 pp |
| 21–50 | 44.4% | 20.9% | −23.5 pp |
| 51–100 | 80.9% | 37.6% | −43.3 pp |
| 101–200 | 90.3% | 47.2% | −43.1 pp |
| 200+ | 92.3% | 56.4% | −35.9 pp |
| **ALL** | **61.0%** | **30.2%** | **−30.8 pp** |

`query` wins in **every** bucket, including the keyword mode. The asymmetric projection is doing real work — using the wrong `input_type` halves recall — and the plugin's `voyage_embedding_provider` already does the right thing (`embed_query()` sends `input_type: query`, indexing sends `document`). The penalty is *smaller* on short queries (−20 pp vs −43 pp), which is the expected shape: a bare noun phrase looks more like a document fragment than a question does. But it is never an advantage. **No change needed, and this is a point in Voyage's favor rather than a risk.**

### The `rag_min_similarity` floor drops a quarter of short-query targets, but costs little recall

The production retriever discards any chunk scoring below `rag_min_similarity` = 0.25 before reranking. On the conversational fixture this was harmless — **0 of 1,008** target chunks fell below it. On production-shaped queries:

| Bucket | n | target below 0.25 floor |
|---|---|---|
| ≤20 | 206 | **48 (23.3%)** |
| 21–50 | 374 | **45 (12.0%)** |
| 51–100 | 141 | 0 |
| 101–200 | 248 | 0 |
| 200+ | 39 | 0 |
| **ALL** | **1,008** | **93 (9.2%)** |

On nearly a quarter of short real-world queries the correct chunk scores below the floor and is dropped before ranking. That is a real mis-calibration — the floor was validated on question-shaped fixtures where it cost nothing (0 of 1,008), and it now bites almost exclusively on the short mode.

**But the recall it actually costs is small, and an earlier draft of this report overstated it badly.** The 9.2% figure counts every target below the floor, including targets that ranked far too low to be retrieved regardless. Of the 93:

| Where the discarded target actually ranked | count | share of 1,008 |
|---|---|---|
| rank 1 | 3 | 0.30 pp |
| rank ≤ 3 | 4 | **0.40 pp** |
| rank ≤ 5 | 8 | 0.79 pp |
| rank ≤ 10 | 12 | 1.19 pp |
| rank ≤ 20 (the rerank candidate pool) | 21 | 2.08 pp |

So with reranking off, removing the floor entirely would raise R@3 by **0.40 pp**. With reranking on the floor also keeps 21 targets out of the 20-chunk candidate pool; at the promotion rate measured in this very run — the reranker pulled 150 of 252 deep-but-in-pool targets into the top 3, 59.5% — that is worth about **1.24 pp**, with a hard ceiling of 2.08 pp if the reranker promoted every one.

Against +8.7 pp for the embedding switch and +10.8 pp for reranking on this same fixture, the floor is a rounding error, not a lever. **The floor is not what loses these queries — the embedding is.** A target sitting at cosine rank 38 is not being failed by a threshold.

It is still worth dropping to about 0.15, where the measured cost goes to zero (1 target lost at 0.20, none at 0.15) and nothing in the long-query modes is affected, since their p5 is 0.433 or better. That is a cheap, safe tidy-up. It is not the headline finding of this work.

---

## 6. Cost model at Saylor's real scale

Usage model taken unchanged from `.drafts/sola-benchmarks-usage-and-cost-2026-08-07.md` so these figures are comparable to it. RAG query volume equals turn volume (`rag_enabled=1` on both prod sites).

**Measured unit costs** (from this run's actual token counts, priced through the plugin rate card):

| Component | Measured tokens | Rate | Cost per 1,000 queries |
|---|---|---|---|
| Query embedding, openai 3-small | 27.8 tok/query | $0.02/MTok | **$0.0006** |
| Query embedding, voyage-3.5 | 26.8 tok/query | $0.06/MTok | **$0.0016** |
| Query embedding, voyage-3.5-lite | 26.8 tok/query | $0.02/MTok | **$0.0005** |
| Rerank-2.5 @ pool 20 | 15,018 tok/query | $0.05/MTok | **$0.75** |
| Rerank-2.5 @ pool 20, gated at 0.086 | 10,467 tok/query | $0.05/MTok | **$0.52** |
| Rerank-2.5 @ pool 20, production-shaped queries | 14,260 tok/query | $0.05/MTok | **$0.71** |

**One-time full-catalog index** (extrapolating the measured 571 tok/chunk OpenAI, 581 tok/chunk Voyage from 10,910 chunks to the 110,300-chunk catalog):

| Provider | Index tokens | One-time cost |
|---|---|---|
| openai text-embedding-3-small | 63.0 M | $1.26 |
| voyage-3.5 | 64.1 M | $3.85 |
| voyage-3.5-lite | 64.1 M | $1.28 |

**Monthly run-rate:**

| Scenario | RAG queries/mo | Chat spend | Rerank @20 always | Rerank @20 gated | Voyage-3.5 embeddings (delta vs OpenAI) |
|---|---|---|---|---|---|
| Today's real footprint | 6,400 | ~$8 | $4.80 | $3.33 | **+$0.01** |
| Full catalog, adoption holds | 9,959 | ~$13 | $7.47 | $5.18 | **+$0.01** |
| Adoption doubles | 19,918 | ~$26 | $14.94 | $10.36 | **+$0.02** |
| Ceiling, 10% adoption | 34,060 | ~$45 | $25.55 | $17.71 | **+$0.03** |
| 25,000 SOLA users (08-01 projection) | 126,700 | $234 | $95.03 | $65.88 | **+$0.13** |

As a share of chat spend: rerank @20 always-on is **40.6%**; gated it is **28.2%**; **moving embeddings to voyage-3.5 is 0.09%**.

### The Gemini rate ambiguity, resolved

The 2026-08-01 report flagged an unconfirmed 3x swing ($0.30/$2.50 vs $0.10/$0.40 per MTok) that moves every rerank-to-chat ratio. **The correct rate for `gemini-2.5-flash` is $0.30 in / $2.50 out.** Verified two ways: the live rate card on dev returns $0.00155 for a 1,000-in / 500-out call, which back-solves to exactly $0.30/$2.50; and that matches Google's published Gemini 2.5 Flash price. The $0.10/$0.40 figure is the **Gemini 2.0 Flash** rate — it is present in the plugin's static card as `gemini-2.0-flash` and was misapplied to 2.5 in the earlier doc. The percentages above therefore stand as written and are no longer provisional.

---

## 7. Provenance and contamination audit

Stated plainly because two agents ran against dev concurrently today.

**Did I change persisted site config? Yes — and I am the likely cause of a transient broken window another agent observed.** The plugin harness's `--embed-provider` A/B mode is *not* config-free, contrary to how it is often described: `run_rag_fixture_benchmark.php` calls `set_config()` on `embed_provider`, `embed_model`, `embed_dimensions` and `embed_apikey` for each arm, restoring them per-arm and via a shutdown hook. During my `voyage:voyage-3.5` arm, dev's persisted config was therefore `voyage` / `voyage-3.5` / `1024` for roughly ten minutes. Any *other* process reading config in that window would have embedded 1024-dim queries against 1536-dim stored vectors, producing exactly the ~0.03 cosine signature and 0% recall that was reported. **I did not reindex; the change was config-only and is restored.**

**Are any of my numbers void? No.** The A/B path re-embeds all document vectors in memory with the same model and width as the query and never touches the stored index, so no arm in §2, §3 or §5 ever compared across mismatched dimensions. Independent confirmation:

- The `openai` arm reproduces the published baseline to 0.2 pp — impossible under a dimension mismatch.
- The rerank run in §4 *does* read stored vectors and site config. It was launched only after I verified config had been restored (`openai` / `text-embedding-3-small` / `1536`, `sk-` key), and its output carries no mismatch signature: target-chunk cosine min **0.3207**, mean **0.6443**, **zero** fixtures below 0.10, **zero** below the 0.25 floor, zero errors across 1,008 fixtures.
- My standalone sweep script never reads or writes `embed_provider` / `embed_model` / `embed_dimensions`; it reads API keys once and calls the vendors with explicit model and width. It is immune to any concurrent config change in either direction.

**State dev was left in:** verified `embed_provider='openai'`, `embed_model='text-embedding-3-small'`, `embed_dimensions='1536'`, `embed_apikey` an `sk-` key, `rerank_enabled='1'`, `rerank_candidates='20'`, `rag_min_similarity='0.25'`, `rag_return_scope='window'`. Identical to the pre-run state. No reindex, no schema change, no writes outside `/tmp` on the dev host.

**Key handling.** No key value was printed at any point. A Voyage key was passed on the harness command line for one run (visible in `ps` on the dev host for its duration); the standalone runs read keys from a `0600` file instead, which is the better pattern and what I would use again.

### A procurement finding worth raising in the meeting

`voyage_apikey` is unset on dev and only `rerank_apikey` is configured — which is why this measurement was believed to be blocked. **It was not: Voyage issues one key per account that authorizes every endpoint.** The existing rerank key returns HTTP 200 from `/v1/embeddings` (verified, 1024-dim vector, 2 tokens billed). There is no procurement blocker to piloting Voyage embeddings; the plugin simply reads the embedding key from a different setting.

---

## 8. Recommendations

**(a) Embeddings: yes, move to Voyage. Pilot `voyage-3.5-lite` first, at native 1024 dimensions.**

The accuracy case is unambiguous and holds on both fixtures and in every length bucket: +10.4 pp R@3 on the conversational set, +8.7 pp on production-shaped queries, p < 1e-15 paired, 50x the measured noise floor. The cost case is a rounding error: **+$0.13/month at 25,000 SOLA users, plus a one-time $3.85 reindex.** It is also faster than what we run today at both P50 and P95, and unlike reranking it adds no per-turn latency at all.

Sequence: reindex one course to `voyage-3.5-lite` @1024 on dev, confirm live retrieval, then decide whether the +1.9 pp from full `voyage-3.5` justifies the 3x token price. Note the switch requires a **full reindex** — vectors from different models are not comparable, which is exactly the failure mode §7 documents. Keep `input_type` asymmetry on (§5b) and keep `embed_dimensions` at the native 1024; 2048 buys nothing measurable.

**Negotiating position:** `voyage-3.5-lite` at $0.02/MTok — exact price parity with OpenAI `text-embedding-3-small` — already delivers +8.5 pp. The accuracy case does not require paying the $0.06 tier, so the $0.06 tier has to earn its 3x premium on the incremental +1.9 pp. And `voyage-3.5` at 256 dimensions beats OpenAI at 1536 by +8.6 pp on one sixth the storage, so the MRL story is real on our corpus and worth crediting them for.

**(b) `rerank_candidates`: leave it at 20.**

Pool 20 is the shipped default and dev's setting. This run reproduces the published pool-20 figures to 0.2 pp, and the 2026-08-01 sweep already settled 20-vs-30 (0.3 pp for 33% more cost). I did not re-run the pool sweep, per instruction, and nothing here disturbs that conclusion.

**Correction to an earlier version of this report:** it stated that production was configured at pool 50. It is not — both production sites read `rerank_candidates=20`, verified directly against the databases on 2026-08-21. There is nothing to fix there.

**But the token count per rerank was too low.** Production reranking ran from 23 June to 4 August 2026 and left 1,359 real calls averaging **21,124 tokens** per rerank at pool 20 — **41% above the 15,018 measured here** — because production chunks average 3,917 characters against this corpus's 2,808. Every rerank cost figure in §4 and §6 is therefore understated by 41% relative to production: **$1.06 per 1,000 queries rather than $0.75**, and always-on rerank is about **57% of chat spend rather than 40.6%**. See `.drafts/sola-volumes-and-language-mix-2026-08-21.md`.

**(c) Reranking: keep it off for now, and re-decide after the embedding switch.**

At today's configuration reranking buys +9.8 to +10.8 pp for **40.6% of chat spend** and +233 ms P50, and it demotes 68 of 557 already-correct top-1 results. The embedding switch buys a comparable +8.7 to +10.4 pp for **0.09% of chat spend** and *negative* latency. On strict cost-effectiveness the embedding change dominates by roughly three orders of magnitude and should land first.

But be precise about what that does and does not settle. On production-shaped queries reranking is **ahead** of the embedding switch by 2.1 pp overall, and by 3–4 pp on the short-query mode that is most of real traffic. So this is not an argument that we will never want a reranker — it is an argument that we should buy the cheap 8–10 pp first and then measure the reranker against the *new* baseline rather than the old one. The two are plausibly complementary; nothing here rules out a Voyage-embeddings-plus-rerank stack reaching well past 63%.

If rerank is enabled before that measurement exists, use pool 20 with the confidence gate at margin < 0.086 — for the ~30% cost saving and the 18 avoided top-1 breakages, **not** for latency, which the gate barely improves (§4).

**(d) Independent of any vendor decision: drop `rag_min_similarity` to ~0.15. Cheap and safe, but minor.**

The 0.25 floor discards the correct chunk on 23.3% of short queries and 9.2% overall, before ranking. It was validated against question-shaped fixtures where it cost nothing (0 of 1,008), so it is genuinely mis-calibrated for real traffic.

**An earlier draft of this report called it "a bigger lever than either vendor change." That was wrong, by roughly 7-25x, and is corrected in §5b.** Most of those 93 discarded targets ranked far too low to be retrieved anyway: only 4 were inside the top 3. Removing the floor is worth **0.40 pp** of R@3 with reranking off, and about **1.24 pp** with it on (21 targets excluded from the 20-chunk rerank pool, times the 59.5% promotion rate measured in this run). Against +8.7 and +10.8 pp for the two real interventions, it is a rounding error.

Drop it to 0.15 — measured cost goes to zero there and no long-query bucket is touched (p5 ≥ 0.433 in every one). Do it because it is free, not because it will move the number.

## 9. What I could not measure, and what is still open

- **Rerank on top of Voyage embeddings — the single most important gap.** Every rerank number here sits on OpenAI vectors. Since Voyage embeddings alone roughly match always-on rerank, the rerank business case could either evaporate after the switch or compound into a materially better stack. Not measured. It needs one combined run (~$0.75) and it should happen before any rerank rollout. **It is also the sharpest question to put to Voyage: does rerank-2.5 add measurable recall on top of voyage-3.5 retrieval, or is it largely correcting weaker embeddings?**
- **Judged relevance (nDCG / precision).** This report is recall-mode only, against each fixture's own ground-truth chunk. A judged comparison of OpenAI vs Voyage on production-shaped queries would be a stronger quality claim than recall alone, and would catch cases where a different-but-equally-good chunk is retrieved.
- **`voyage-4` and `voyage-4-lite`** were not run. The 2026-07-08 40-fixture run put them *below* voyage-3.5, but that sample was too small to conclude anything; at 1,008 fixtures they deserve a re-test, especially since `voyage-4-lite` is also $0.02/MTok.
- **Cold-path latency.** All latency here is warm-path — the vector load is amortized across 1,008 queries in one process. Prior work measured real cold retrieval at 835 ms typical and 1,957 ms worst case. The P50/P95 figures above are the *incremental* vendor cost, not the learner-visible total.
- **Prodshape fixture caveat.** Some short-mode items read as truncated fragments ("positive work?", "social norms come?") where discriminating content may have been lost along with the surface form. The ≤20-char bucket may therefore understate achievable recall on genuinely short-but-specific production queries. The bimodal *shape* of the result is trustworthy; the exact 28–36% level on that bucket is not, and should be checked against a fixture whose short queries are short *and* specific before 52% is quoted as "production recall".
- **Follow-up and deixis.** 76.8% of real messages are turn 2+ and 21.6% contain a bare deictic whose referent the retriever never sees. No fixture here models conversation history, so nothing in this report speaks to how much of the short-query problem is really a *context* problem rather than a retrieval problem. That is plausibly the largest unmeasured factor of all.
- **The other ~146 production courses are unmeasured**, as in all prior SOLA retrieval work.

## 10. Reproducing this

All runs on `dev.sylr.org` via SSM. Plugin CLI paths are relative to `/var/www/html/moodle/local/ai_course_assistant`.

**Embedding A/B, Fixture A** (headline, §2 first two rows; ~$0.51):
```
php admin/cli/run_rag_fixture_benchmark.php \
  --fixtures=tests/golden/rag_fixtures_conv_1k_recall.json \
  --embed-provider=openai:text-embedding-3-small \
  --embed-provider=voyage:voyage-3.5 \
  --voyage-apikey=<voyage key> \
  --out=2026-08-21-embed-ab-run1.json
```
Note this mutates site config for the duration of each arm (§7). Prefer the standalone script below when anything else is using the site.

**Rerank @ pool 20, Fixture A** (§4; ~$0.76):
```
php admin/cli/run_rag_fixture_benchmark.php \
  --fixtures=tests/golden/rag_fixtures_conv_1k_recall.json \
  --candidates=20 --topk=10 \
  --out=2026-08-21-rerank-pool20.json
```

**Rerank @ pool 20, production-shaped fixture** (§5b; ~$0.72):
```
php admin/cli/run_rag_fixture_benchmark.php \
  --fixtures=/tmp/solabench/prodshape.json --candidates=20 --topk=10 \
  --out=2026-08-21-rerank-pool20-prodshape.json
```

**MRL / tier sweep, prodshape arms and the `input_type` test** (§2, §3, §5, §5b): standalone script, **config-read-only** (it never writes `embed_*`), reading API keys from a `0600` file and caching document vectors on disk so extra fixtures, widths and `input_type` variants cost nothing further. Preserved at `/tmp/sweep2.php` on the dev host; artifacts in `/tmp/solabench/`.
```
# tier + MRL sweep, conversational fixture (3 models x 4 widths, ~$0.64)
php /tmp/sweep2.php --fixtures=tests/golden/rag_fixtures_conv_1k_recall.json \
  --arms=openai:text-embedding-3-small:1536,voyage:voyage-3.5:2048,voyage:voyage-3.5-lite:2048 \
  --qtypes=query --out=/tmp/solabench/mrl_sweep.json

# production-shaped fixture, both providers (~$0.51)
php /tmp/sweep2.php --fixtures=/tmp/solabench/prodshape.json \
  --arms=openai:text-embedding-3-small:1536,voyage:voyage-3.5:2048 \
  --qtypes=query --out=/tmp/solabench/sweep2_prodshape.json

# input_type test: reuses the cached document vectors, so ~$0.002
php /tmp/sweep2.php --fixtures=/tmp/solabench/prodshape.json \
  --arms=voyage:voyage-3.5:2048 --qtypes=document \
  --out=/tmp/solabench/sweep2_ps_doctype.json
```

Gate replay (§4), the noise-floor / McNemar analysis (§1), and every length-bucket table in §5b are pure post-processes over the per-fixture records — no API calls.

### Measured spend

| Run | Measured tokens | Cost |
|---|---|---|
| Embedding A/B, conv fixture (harness) | 6.23 M openai + 6.33 M voyage-3.5 | $0.51 |
| Tier + MRL sweep, conv fixture | 6.23 M openai + 6.33 M voyage-3.5 + 6.33 M lite | $0.64 |
| Rerank @20, conv fixture | 15.14 M rerank-2.5 | $0.76 |
| Embedding A/B, prodshape fixture | 6.23 M openai + 6.33 M voyage-3.5 | $0.51 |
| Rerank @20, prodshape fixture | 14.37 M rerank-2.5 | $0.72 |
| `input_type` tests x2 (cache warm) | 0.05 M voyage query tokens | $0.003 |
| MRL truncation verification | ~10 tokens | $0.00 |
| **Total** | | **≈ $3.14** |

Against the $25 ceiling. Two things kept a three-model, four-width, two-fixture, two-`input_type` sweep inside $3: the MRL prefix property in §3 (one embedding pass yields every width) and the on-disk document-vector cache (one pass per model serves every fixture and `input_type` variant thereafter).
