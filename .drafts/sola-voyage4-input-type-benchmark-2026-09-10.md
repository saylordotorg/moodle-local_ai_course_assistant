# SOLA RAG benchmark: `input_type` on voyage-4-large, and what the shipped default costs

**Date:** 2026-09-10
**Site:** `dev.sylr.org`, SOLA 7.4.3, 17,257 indexed chunks across 49 indexed courses
**Harness:** `admin/cli/run_rag_fixture_benchmark.php`, `--candidates=20` (matching production `rerank_candidates`)
**Fixtures:** `tests/golden/rag_fixtures_prodshape_anchored_2026-08-27.json`, 816 rows, 13 courses, anchor preflight clean
**Complements:** `.drafts/sola-rag-rerank-benchmark-2026-08-21.md` section 5b, which measured the same effect on a different model
**Status:** decision-grade. This is the evidence behind the 7.4.5 default flip.

---

## 0. Headline

**On voyage-4-large, sending the same `input_type` for queries and documents costs 24.1 percentage points of Recall@3, and leaves retrieval 10.2 pp worse than the OpenAI index it was meant to replace.**

That configuration, `embed_input_type_mode = shared`, was the shipped default up to and including 7.4.4.

| Arm | R@1 | R@3 | R@5 | MRR |
|---|---|---|---|---|
| text-embedding-3-small @1536 (control) | 34.4% | 52.8% | 60.4% | 0.466 |
| control + rerank-2.5 | 45.3% | 63.2% | 68.1% | 0.554 |
| voyage-4-large @2048 SHARED | 25.1% | 42.6% | 49.4% | 0.373 |
| voyage-4-large @2048 SHARED + rerank | 46.3% | 62.5% | 67.0% | 0.551 |
| **voyage-4-large @2048 ASYMMETRIC** | **46.9%** | **66.7%** | **73.0%** | **0.584** |
| voyage-4-large @2048 ASYM + rerank | 48.7% | 68.8% | 74.1% | 0.598 |

Three readings of that table, in order of how much they should change what anyone does:

1. **Asymmetric against shared, same model, same index, same fixtures: +24.1 pp R@3** (66.7% against 42.6%). One setting. No reindex. This is the finding.
2. **Shared is a regression against the incumbent.** A site that migrated to voyage-4-large on the shipped default would have moved from 52.8% to 42.6% R@3, a 10.2 pp loss, having just paid to re-embed its corpus. Asymmetric moves the same site to 66.7%, a 13.9 pp gain.
3. **The reranker hides the defect.** Shared plus rerank scores 62.5% against the control's reranked 63.2%: within a point, so a site running the reranker would see nothing obviously wrong, while paying per query for a cross-encoder to undo a free configuration error. Note also what happens to the reranker's own marginal value once `input_type` is right: it is worth +10.4 pp on the control arm, +19.9 pp on shared, and only +2.1 pp on asymmetric.

Migration cost for the corpus that produced these numbers: **$1.21 actual**, 10.11 MTok at $0.12/MTok.

---

## 1. What was measured, precisely

`input_type` is a per-call parameter on the Voyage embeddings API. Voyage projects text differently depending on whether it is being indexed or searched for.

- **Asymmetric** sends `input_type=query` on the retrieval call and `input_type=document` on the index call. This is what the vendor intends.
- **Shared** sends `input_type=document` for both sides. Queries and documents then live in one projection, which is why it was chosen: a single projection means the query model can be changed later without re-embedding the corpus.

Both arms score the **same stored document vectors**. Only the query-side projection differs. That is why switching this setting needs no reindex, and it is also why the comparison is clean: nothing else in the pipeline moves between the two rows.

Each arm ran the full 816-row fixture set against the 13 courses it covers. The metric is the rank of the ground-truth chunk under the arm's own scoring, reported as Recall@1/3/5 and mean reciprocal rank.

### What this is NOT

This is **not** asymmetric model *pairing*, and the two have been confused before in this codebase to the point where the settings page recommended the losing option in 46 languages.

| | `input_type` asymmetry | asymmetric model pairing |
|---|---|---|
| Mechanism | one model, two projections of the same text | two different models, one for documents and one for queries |
| Governed by | `embed_input_type_mode` | `embed_query_model` |
| Measured here | +24.1 pp R@3 (this report) | not measured here |
| Measured previously | +30.8 pp R@3 (2026-08-21, voyage-3.5) | a wash on voyage-4-lite, 1.0 pp worse on voyage-4 |
| Recorded in | this file, and 2026-08-21 section 5b | `embedding_compat::SHARED_SPACES` |

The pairing result is real and it is negative. It says nothing about `input_type`, and the 7.4.4 help text that cited it as if it did is what this release corrects.

---

## 2. The validity gate

**The control arm reproduced the previously recorded 7.4.1 baseline bit-identically on all six recall figures.**

This is the load-bearing check, and it is the reason the rest of the table can be trusted. The measurement ran on a patched tree. If the patch had perturbed scoring, ordering, candidate selection, or fixture handling in any way, the control arm would have drifted from a number recorded weeks earlier on unpatched code. It did not drift by a decimal place. The patch is therefore non-distorting with respect to this measurement, and the Voyage arms are being compared against an OpenAI arm that is still the same OpenAI arm.

A reproduction to the last digit is a stronger claim than "within noise", and it is made here deliberately. Prior SOLA retrieval work established a run-to-run noise floor of 0.2 pp or less on R@3 (2026-08-21, two independent implementations). Against that floor, a 24.1 pp separation is roughly 120 times the noise.

---

## 3. Caveats, stated before the recommendation

### 3.1 The harness does not apply `rag_min_similarity`

The harness ranks by raw cosine and does not apply the production relevance floor before ranking. This is documented in the harness itself (`run_rag_fixture_benchmark.php` line 1513) and it reports a separate production floor check rather than folding the floor into the arms.

Consequence, and it is not a small one:

- The headline migration delta of **+13.9 pp** (control 52.8% to asymmetric 66.7%) is a no-floor figure.
- **With the live floor applied, the real-world delta is +10.9 pp, not +13.9 pp.**
- At a 0.25 floor, **9.2% of queries returned zero passages** on the Voyage arm, against **1.0% for the control**.

The 24.1 pp shared-against-asymmetric figure is the comparison this release turns on, and both of its arms are equally unfloored, so the floor does not explain it away. But the migration case should be argued at +10.9 pp.

### 3.2 The floor is not calibrated for Voyage

The zero-passage rates above are the practical form of a known gap: `rag_min_similarity` was calibrated for `text-embedding-3-small`, where 0.25 is a sensible floor. Voyage models produce a different similarity distribution and the same number is not equivalent on voyage-4-large. It has not been recalibrated.

**Both production sites currently run 0.15**, not 0.25, so the 9.2% figure is not a description of either of them. It is a warning about what a Voyage site running the OpenAI-era default floor would experience. The 7.4.5 help text for that setting now says so, and points the reader at this harness.

### 3.3 One site, one corpus, one fixture generation

816 fixtures over 13 courses on one dev index. The effect has now reproduced across two model generations and two independently built fixture sets, which is the strongest available argument that it is a property of the API rather than of this corpus. It is still not a multi-site result.

---

## 4. Relationship to the 2026-08-21 measurement

`.drafts/sola-rag-rerank-benchmark-2026-08-21.md` section 5b measured the same mechanism on **voyage-3.5**, 1,008 production-shaped fixtures, by embedding identical queries both ways against identical cached document vectors: **61.0% R@3 for `query` against 30.2% for `document`, a 30.8 pp penalty**, with `query` winning in every one of the five query-length buckets, from 19.9 pp on the shortest to 43.3 pp in the middle.

Different model, different fixture set, different experiment, run three weeks apart. Same direction, same order of magnitude.

| | 2026-08-21 | 2026-09-10 |
|---|---|---|
| Model | voyage-3.5 @1024 | voyage-4-large @2048 |
| Fixtures | 1,008, production-shaped | 816, production-shaped anchored |
| Asymmetric R@3 | 61.0% | 66.7% |
| Shared R@3 | 30.2% | 42.6% |
| Penalty for shared | 30.8 pp | 24.1 pp |

Both are valid. Neither supersedes the other. The 2026-08-21 run is the more direct isolation of the mechanism, because it reuses cached document vectors and varies literally one API parameter. This run is the more representative of production, because it goes through the production harness at production `--candidates` against a production-shaped anchored fixture set on a 17,257-chunk index, and it includes the reranked arms.

---

## 5. What this establishes, and what it does not

**Establishes:**

- On voyage-4-large, `embed_input_type_mode = asymmetric` retrieves substantially better than `shared` on this corpus: +24.1 pp R@3, +21.8 pp R@1, +23.6 pp R@5, +0.211 MRR.
- `shared` on voyage-4-large is worse than the OpenAI index it replaces, by 10.2 pp R@3.
- The effect survives reranking in the sense that asymmetric is still ahead (68.8% against 62.5%), but reranking compresses the visible gap to about a point against the control, so a reranked site cannot detect the problem from its own numbers.
- The effect is not an artifact of the patched tree, because the control arm reproduced its recorded baseline exactly.

**Does not establish:**

- Anything about asymmetric model *pairing*. That remains a wash to slightly negative.
- A recalibrated `rag_min_similarity` for Voyage. Not measured. Section 3.2 is a flag, not a value.
- The size of the win under the live floor at either production site's actual 0.15 setting. The +10.9 pp figure is the floored migration delta, not a per-site projection.
- Anything about corpora other than SOLA's.

---

## 6. Recommendation

1. **Flip the shipped default of `embed_input_type_mode` from `shared` to `asymmetric`.** Done in 7.4.5.
2. **Understand what the flip reaches.** Moodle's `admin_apply_default_settings()` writes a default only where no value is stored. The flip therefore binds **new installs only**. It does not reach a site that has explicitly saved `shared`, and it does not reach a site that saved `asymmetric` either. Today the explicitly-`shared` set is empty: `learn.saylor.org` is on OpenAI embeddings and the setting is inert there, and `degrees.saylor.org` is explicitly asymmetric. No site needs remediation, which is why 7.4.5 ships no `db/upgrade.php` step. A site that later finds itself on `shared` fixes it by setting the value on the settings page; no reindex is required.
3. **Any Voyage site should verify it is on `asymmetric`**, whatever its version. This is a one-line check with a 24 pp payoff.
4. **Do not raise `rag_min_similarity` toward 0.25 on a Voyage index** until it has been recalibrated against that index. Section 3.2.

---

## 7. Provenance

| | |
|---|---|
| Site | `dev.sylr.org` |
| Plugin version at run time | SOLA 7.4.3 |
| Index | 17,257 chunks, 49 indexed courses |
| Harness | `admin/cli/run_rag_fixture_benchmark.php` |
| Harness flags | `--candidates=20` (matching production `rerank_candidates`) |
| Fixtures | `tests/golden/rag_fixtures_prodshape_anchored_2026-08-27.json` |
| Fixture size | 816 rows across 13 courses |
| Anchor preflight | clean (the harness aborts on a broken or ambiguous anchor; it did not) |
| Embedding arms | `text-embedding-3-small` @1536; `voyage-4-large` @2048 |
| Rerank arms | `rerank-2.5`, candidate pool 20 |
| Re-embed cost | 10.11 MTok at $0.12/MTok = $1.21 |
| Floor applied by harness | none; see section 3.1 |

The harness reads config and does not mutate it. Every figure in section 0 is a harness output; every figure elsewhere in this document is either a harness output or a difference between two of them, and the differences are labelled as such.
