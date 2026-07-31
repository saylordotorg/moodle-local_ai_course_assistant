# Scope: per-course rerank gating

**Status:** scoping only, nothing built.
**Date:** 2026-07-31
**Source data:** `.drafts/sola-model-benchmark-2026-07-30.md` (1,008-query sweep, 16 courses)

---

## The finding this responds to

Reranking is not uniformly good. On the 1,008-query sweep it helped 14 of 16 courses, was neutral on one, and actively hurt the one course whose embedding recall was already 98.4%. The benefit tracks how *weak* the embedding baseline is.

Per-query, across all 1,008 queries at pool 30:

| Outcome | Queries | Share |
|---|---|---|
| Rerank improved the target's rank | 338 | 33.5% |
| Rerank worsened it | 129 | 12.8% |
| No change | 541 | 53.7% |

Split by what the embedding stage had already achieved:

| Situation | Count | Outcome |
|---|---|---|
| Embedding already had the target at **rank 1** | 557 | Rerank pushed it **out of rank 1 in 67 (12.0%)** |
| Embedding had the target **outside top 3** | 210 | Rerank pulled it **into top 3 in 130 (61.9%)** |

That is the whole story in two rows. **Reranking is an excellent rescuer and a mediocre custodian.** It fixes 62% of deep misses and breaks 12% of already-perfect results. Whether it is worth enabling depends entirely on how many of each a given course has.

Per course, sorted by baseline recall@3:

| Course | Base R@3 | Already rank 1 | Broken by rerank | Deep misses | Rescued |
|---|---|---|---|---|---|
| course43 | 58.7% | 18 | 1 | 26 | 10 |
| course117 | 65.1% | 29 | 8 | 22 | 14 |
| course132 | 69.8% | 29 | 3 | 19 | 11 |
| course130 | 71.4% | 35 | 4 | 18 | 16 |
| course116 | 74.6% | 35 | 5 | 16 | 10 |
| course128 | 76.2% | 38 | 2 | 15 | 11 |
| course149 | 76.2% | 32 | 3 | 15 | 8 |
| course11 | 77.8% | 36 | 2 | 14 | 7 |
| course129 | 77.8% | 34 | 2 | 14 | 8 |
| course3 | 79.4% | 31 | 2 | 13 | 10 |
| course2 | 82.5% | 32 | 6 | 11 | 8 |
| course115 | 85.7% | 38 | 5 | 9 | 7 |
| course8 | 85.7% | 42 | 5 | 9 | 4 |
| course131 | 93.7% | 46 | 5 | 4 | 2 |
| course4 | 93.7% | 42 | 5 | 4 | 4 |
| **course7** | **98.4%** | **40** | **9** | **1** | **0** |

course7 is the clean negative case: 9 correct top-1 results broken, 1 deep miss available, 0 rescued. Pure loss. course43 is the clean positive: 26 deep misses, 10 rescued, 1 broken.

---

## The part that is easy, and the part that is not

**The mechanism is nearly free.** `rag_retriever::retrieve()` already takes `$courseid`, and `feature_flags::resolve()` already implements exactly this override pattern (`<feature>_enabled_course_<id>` beating `<feature>_enabled`, as used by Soapbox and the avatar animation A/B). The gating change at `classes/rag_retriever.php:176` is one line:

```php
// from
if ((bool) get_config('local_ai_course_assistant', 'rerank_enabled')) {
// to
if (feature_flags::resolve('rerank', $courseid)) {
```

**The decision procedure is the entire cost of this project.** Deciding *which* courses to enable requires knowing each course's baseline recall, and recall requires ground truth — a set of questions with known correct chunks. Production courses do not have that.

---

## Options

### A. Measure per course with synthetic fixtures, gate on a threshold

Reuse the pipeline from the benchmark: for each course, sample N chunks, generate one question per chunk with gpt-4o-mini, measure embedding-only recall@3, write `rerank_enabled_course_<id>` when below threshold.

- **Pro:** fully automatic; reuses `generate_conversational_fixtures.php` and `run_rag_fixture_benchmark.php`; cheap (~$0.02 and ~2 minutes per course).
- **Con — and this is serious:** synthetic questions are *easier than real ones*. The same benchmark found baseline R@3 of 79.2% on synthetic questions versus 55.0% on human-written colloquial ones, because a question generated from a chunk inherits that chunk's vocabulary. **A synthetic-derived baseline is optimistic, so a fixed threshold will systematically under-enable reranking on courses that actually need it.**
- Mitigation: calibrate the threshold against synthetic scale rather than intuition, or gate on *relative* rank (enable on the weakest N courses) rather than an absolute number. Relative ranking is immune to the bias as long as it is uniform across courses — which is plausible but unverified.

### B. Per-query gate on retrieval confidence

Skip the per-course question entirely. Rerank only when the embedding stage looks *unsure* — low top-1 score, or a narrow margin between top-1 and top-3.

- **Pro:** no ground truth needed anywhere; adapts within a course; targets spend precisely; and it attacks the harm directly, since 557 of 1,008 queries were confident top-1 hits and that is where all 67 breakages came from. Skipping those avoids the harm *and* cuts most of the cost.
- **Con:** unvalidated. The hypothesis that score margin predicts rerank benefit is plausible but untested, and **the benchmark harness does not currently emit the needed signal** — `per_fixture` records the *target's* cosine score (which needs ground truth) but not the top-1 score or the top-k spread of the retrieved set.
- **Prerequisite:** one harness change (emit top-1 and top-3 cosine scores per query) plus a re-analysis of the existing 1,008-query run. No new API spend — the run can be replayed. This is a few hours, and it either validates the whole approach or kills it cheaply.

### C. Manual override list

Enable reranking globally, then set `rerank_enabled_course_<id> = 0` by hand on the handful of courses measured as already strong.

- **Pro:** ships today, needs only the one-line change, zero new machinery.
- **Con:** does not scale to 162 courses and goes stale as content changes.

---

## Recommendation

**Do the one-line mechanism change now. Do not build measurement automation yet. Run the Option B prerequisite experiment before committing to anything larger.**

Reasoning:

1. **The mechanism is worth having regardless.** It is one line against existing, tested machinery, and it unblocks C immediately.
2. **This is a quality project, not a cost project, and it should be justified as one.** At 20,000 SOLA users the entire rerank bill is $65-163/month. Per-course gating might halve it. Nobody should build a measurement subsystem to save $50/month. The real prize is not paying a 12% top-1 breakage tax on courses that gain nothing — and on course7 specifically, reranking is strictly worse than not reranking.
3. **Option B probably dominates A** if the confidence signal holds, because it addresses the harm at query granularity, needs no ground truth, and saves more money. It is also much less code. Testing it is cheap and can reuse data already paid for.
4. **Option A's bias is a real trap.** Building automation on a measurement we already know is optimistic would produce a system that looks principled and quietly under-enables the feature where it matters most.

---

## Implementation surface

If Option B validates, the shape is roughly:

| Change | File | Size |
|---|---|---|
| Per-course gate | `classes/rag_retriever.php:176` | 1 line |
| Emit top-k score spread | `admin/cli/run_rag_fixture_benchmark.php` | small |
| Confidence threshold setting | `settings.php` + `policy_bundle::ALLOWED_KEYS` | small |
| Per-query gate | `classes/rag_retriever.php` | ~10 lines |
| Admin visibility | `rag_admin.php` per-course cards | moderate |

Note `policy_bundle::ALLOWED_KEYS` is a fixed list and per-course keys are dynamic (`rerank_enabled_course_<id>`), so per-course overrides are **not** currently pushable via a signed bundle. Either accept that (set them via CLI/UI) or extend the allowlist to support a suffix pattern — the latter needs care, since the allowlist is a security control and pattern-matching weakens it.

---

## Rollout and rollback

- Ship the mechanism behind unchanged defaults: with no per-course key set, `resolve()` returns the global, so **behaviour is identical for every existing site**.
- Roll out on dev first, per course, watching the analytics comparison card (`analytics::get_experiment_metrics()`) which already supports A/B by course.
- Rollback is deleting a config row.

---

## Open questions

1. Does embedding-stage confidence actually predict rerank benefit? (Option B prerequisite — answerable from existing data.)
2. Is the synthetic-vs-real recall bias uniform across courses? If not, relative ranking in Option A is unsafe too.
3. Does the pattern hold on *real* learner queries? Everything here rests on synthetic questions. Prod now logs real turns; sampling those and hand-labelling a few dozen would be the strongest possible validation, and is the only route to ground truth that has no generation bias.
4. Should the gate be recall-based at all, rather than "does this course have long, homogeneous chunks that confuse cosine similarity?" There may be a cheaper structural predictor.

---

## What this does not require

Prod changes. Reranking is off in production (`rerank_enabled=0`) and `rerank_candidates` is inert there. Nothing in this scope needs a prod deploy until the feature is actually adopted.
