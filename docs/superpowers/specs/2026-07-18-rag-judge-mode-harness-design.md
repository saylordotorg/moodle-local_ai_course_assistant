# Spec: RAG judge-mode eval harness

**Date:** 2026-07-18
**Status:** Approved (brainstorming)
**Component:** `admin/cli/run_rag_fixture_benchmark.php` (new `--judge` mode)

## Goal

Label-free retrieval evaluation. Add a `--judge` mode to the existing RAG
benchmark CLI that, for each (unlabeled) question, retrieves top-k passages and
has an LLM grade each passage's relevance 0-3, then computes graded metrics
(nDCG@k, precision@k, hit@k, mean relevance) and compares configurations. This
sidesteps the blocker that we have no labeled "expected chunk" per question and
effectively no real question corpus.

## Why (context)

- Recall@k needs a pre-labeled gold chunk per query; we can't build that (dev
  has ~6 real user messages; real questions live on prod, access-gated + private).
- Judged relevance needs no labels: retrieve, then grade the retrieved passages.
  Works at small N (dozens) with confidence intervals.
- Reusing the 984 synthetic questions under judged grading also removes the
  "ground truth = source chunk" optimism of the earlier recall run, because the
  judge grades the retrieved passages independently of where a question came from.

## Two comparison families (design constraint)

Dev's stored chunk embeddings are OpenAI vectors, and `rag_retriever::retrieve()`
ranks the query against those stored vectors. So:

- **Family A — pipeline configs, via the real `retrieve()`.** Arms are plugin
  config flips: `rag_return_scope` = `chunk` vs `page`; `rerank_enabled` = 0 vs 1.
  Exercises the full live pipeline (floor, current-page boost, scope, rerank,
  parent-document expansion). Config is set per arm and restored at exit (the
  same `register_shutdown_function` pattern the embedding A/B mode already uses).
- **Family B — embedding provider, via in-memory re-embed.** Arms `openai` vs
  `voyage`. Reuses the existing A/B re-embed path: re-embed the fixture courses'
  chunk contents and each query with the arm's provider, cosine top-k (no rerank,
  scope, or parent-doc). Judged the same way. Reported as a **separate table** —
  not directly comparable to Family A (different pipeline), directly comparable
  to the earlier embedding A/B (same method, judged instead of recall).

v1 runs both; each family prints its own arms table.

## Input

- Unlabeled questions JSON: `{"questions": [{"courseid": int, "question": str}, ...]}`.
- v1 source: the existing `tests/golden/rag_fixtures_synthetic_1000.json` with
  `expected_chunk_id` ignored (judge mode never reads it). A `--questions=PATH`
  flag; default to the synthetic file.
- `--sample=N` (default 100): deterministic subsample (stable order, e.g. first N
  after a fixed sort by id) so a ~100-question run is cheap and reproducible.

## Judge

- Model: `gpt-4o-mini` (off the chat tier, so it never biases a provider arm).
  `--judge-model=` override.
- One batched call per (arm, question): send the question + the k retrieved
  passages (numbered), ask for a JSON array of integer grades 0-3, one per
  passage. Grade rubric in the prompt: 0 irrelevant, 1 tangential, 2 relevant,
  3 directly answers.
- Key injected server-side (dev `embed_apikey`/`apikey`), never printed.
- Robust parse: JSON array length must equal k; on mismatch/parse failure, mark
  that question's grades null and count it as a judge error (reported, excluded
  from metrics), never silently zero.
- Cache grades per (arm, question-hash, passage-hash) in the output JSON so a
  re-run does not re-pay for unchanged (arm, question, passage) tuples.

## Metrics (per arm, top-k, default k=5)

Given judge grades g_1..g_k for the ranked passages:
- **nDCG@k**: DCG = Σ (2^g_i - 1)/log2(i+1); IDCG from the same grades sorted
  desc; nDCG = DCG/IDCG (1.0 if IDCG=0).
- **precision@k**: fraction of the k passages with g_i >= 2.
- **hit@k**: 1 if any g_i >= 2, else 0.
- **mean_relevance**: mean of g_i over the k passages.
Aggregate = mean across questions; report per arm + deltas vs the first arm, plus
the judge-error count and questions scored.

## Output & run

- Writes `runs/YYYY-MM-DD-His-rag-judge.json` (arms, per-question grades, config)
  and prints two arms tables (Family A, Family B) with deltas.
- Executed on dev via SSM (has chunks, keys, the live retriever). Family A sets/
  restores config per arm; Family B re-embeds in memory. Non-invasive: no reindex,
  config restored on exit.

## Testing

- Pure metric functions (`ndcg_at_k`, `precision_at_k`, `hit_at_k`,
  `mean_relevance`) — unit-tested with hand-computed cases (incl. all-zero grades,
  perfect ranking, IDCG=0).
- Judge-response parser — unit-tested with canned good/short/garbage JSON
  (asserts length check + error path).
- End-to-end is a dev run (like the embedding A/B), not a unit test (retrieval +
  live LLM).

## v1 scope / out of scope

- **In:** `--judge` mode; Family A arms (chunk/page, rerank off/on) via
  `retrieve()`; Family B arms (openai/voyage) via re-embed; the four metrics;
  `--sample`; synthetic questions input; dev run; unit tests for metrics + parse.
- **Out (later):** curated real-question bank (separate deliverable);
  production A/B on helpfulness signals; per-passage rationale from the judge;
  multi-judge agreement / strong-judge spot-check (add if the mini judge looks
  noisy).
