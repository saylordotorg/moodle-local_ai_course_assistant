# SOLA RAG configuration runbook — v6.8.2 (Saylor prod pin)

**Date:** 2026-07-12. **Applies to:** SOLA v6.8.2, the version live on Saylor Learn + Degrees. **Goal:** get Retrieval-Augmented Generation working as well as v6.8.2 allows.

Grounded in the actual settings at the `v6.8.2` tag. In v6.8.2 `rag_enabled` **defaults to on**, so most of this is verify-and-tune, not enable-from-scratch. All keys live under **Site administration → Plugins → Local plugins → SOLA**, Content & RAG section, unless noted. Setting keys are given as `local_ai_course_assistant/<key>`.

Embedding-model choice reflects the measured A/B (2026-07-08): **keep OpenAI text-embedding-3-small**; do not migrate to Voyage on this version. Rationale in `sola-vendor-recommendations-2026-06-09.md` (addendum after Appendix A.7).

---

## A. Embeddings — keep OpenAI

| Setting | Value | Note |
|---|---|---|
| `embed_provider` | `openai` | Default. |
| `embed_model` | `text-embedding-3-small` | Default. Won the local A/B and is cheaper than Voyage. |
| `embed_apikey` | your OpenAI key | **Separate field** from the chat provider key. If blank, RAG silently retrieves nothing. |
| `embed_dimensions` | `1536` | Default; native for 3-small. Leave it. |

**Do not switch to Voyage on v6.8.2.** The A/B says OpenAI matches or beats Voyage on our corpus, and the provider-native embedding-width fix only landed in v6.8.10 — on v6.8.2 a Voyage switch is pinned to 1536 and misbehaves.

## B. Index the content

1. Open **`rag_admin.php`** (RAG Admin page). Reindex each course you want grounded.
2. Watch the stat cards until *chunks embedded* is approximately equal to *chunks*. A persistent gap means the embedding key or quota is failing — fix that before trusting retrieval.
3. Leave `rag_auto_reindex_drifted` = **on** (default) so edited content re-embeds instead of going stale.

## C. Retrieval tuning

The defaults are deliberately conservative. These are the dials, with the direction to move each.

| Setting | Default | Guidance |
|---|---|---|
| `rag_topk` | `5` | Chunks fed to the model. Raise to 6-8 if answers miss context that is clearly in the materials; keep low if the model rambles. |
| `rag_min_similarity` | `0.25` | Relevance gate — drops chunks below this cosine similarity rather than padding to top-k. Raise toward 0.30-0.35 if irrelevant chunks leak in; lower if good chunks get dropped. |
| `rag_chunksize` | `400` | Approx 1-2 paragraphs per chunk. Leave unless content is unusually long-form. |
| `rag_currentpage_boost` | `0.05` | Small ordering boost for chunks from the page the learner is on. Fine as-is. |

## D. Reranker — the biggest quality lever (has a prerequisite)

Two-stage retrieval with Voyage **rerank-2.5** measured **+17.5pp recall@3** (55% to 72.5%) at **P50 +306ms** added latency. It runs on top of the OpenAI embeddings above.

**Prerequisite: a Voyage API key AND a signed Voyage DPA.** As of this writing only the dev fleet has a Voyage key, and the DPA is still pending, so on **prod v6.8.2 the reranker stays OFF** until procurement clears. Do not enable it without the DPA.

When the prerequisite is met:

| Setting | Value | Note |
|---|---|---|
| `rerank_enabled` | on | |
| `rerank_apikey` | Voyage key | |
| `rerank_model` | `rerank-2.5` | Default. |
| `rerank_candidates` | `30` | **Not** the default 50. Pool 30 gave identical recall at lower latency and cost in the benchmark. |

## E. Verify it actually works

1. **`prompt_playground.php`** — run a live-retrieval test against a real course and confirm sensible chunks come back.
2. Turn `prompt_debug_enabled` on briefly and check the **RETRIEVED CHUNKS** block (score / cmid / module per chunk) on a real turn, then turn it back off.

## F. Cost and safety

- Embeddings are under $1/month even at 100k MAU, so no special cap is needed.
- The existing per-course spend cap already covers a runaway reindex loop.

---

## Known v6.8.2 limitation

**Document-scoped retrieval (`rag_scope`)** — grounding on the exact document the learner is viewing, with course-wide fallback — is **not in v6.8.2**. It shipped in v6.8.7-8. On v6.8.2 retrieval is course-wide only; there is no setting that changes this. Stated as fact, not an upgrade recommendation: any move of the prod pin off v6.8.2 is a separate decision (Catalyst engagement).

## Sources

- Settings verified at the `v6.8.2` git tag (`settings.php`, Content & RAG section).
- Embedding A/B decision: `sola-vendor-recommendations-2026-06-09.md` addendum after A.7 (measured 2026-07-08).
- Rerank benchmark: `.drafts/sola-rag-fixture-benchmark-2026-06-10.md` (recall and latency at candidate pools 30 and 50).
