# Spec: Parent-document retrieval

**Date:** 2026-07-17
**Status:** Approved (brainstorming)
**Component:** `local_ai_course_assistant` RAG retrieval

## Goal

Select on small chunks for precision, but return a larger *parent* unit (a
neighbor window or the whole module page) to the LLM, so answers that span a
page arrive as coherent passages instead of mid-sentence fragments. Uses the
metadata chunks already carry (`cmid`, `chunkindex`); no new embedding model or
vendor.

## Motivation (measured)

Live retrieval on dev, BUS101 (courseid 11), query "What is the difference
between a sole proprietorship and a corporation, and how does liability
differ?" returned 5 chunks spanning 4 pages of one book (cmids 510, 509, 508,
504), several beginning mid-sentence ("difficult to setup...", "wanted the
company to be owned by..."). The right content is present but arrives as
disjoint ~400-word fragments. Handing the model the full page(s) those hits
belong to gives a coherent, cross-page answer.

## Current state (do not change the selection path)

- Chunker (`classes/content_chunker.php`): paragraph-aware merge to ~400 words,
  50-word overlap, each chunk prefixed `[Section] Title:`. Chunks store
  `courseid, cmid, modtype, chunkindex, content, contenthash, embedding`.
- Retriever (`classes/rag_retriever.php::retrieve`): embed query -> cosine over
  course chunks -> relevance floor (`rag_min_similarity`, 0.25) -> current-page
  boost -> scope (`rag_scope`, `document_first`) -> optional Voyage rerank
  (`rerank_candidates` 50 -> top-k) -> returns top-k rows
  `{content, score, cmid, modtype, chunkindex}`.

Selection/ranking stays exactly as-is. Parent expansion is a **post-selection**
step, so it composes cleanly with the floor, scope, and reranker (which keep
operating on small chunks).

## Design

Add one config-gated step at the end of `retrieve()`: after the final top-k
(post-rerank) rows are chosen, expand them into parents and de-duplicate.

Modes (`rag_return_scope`):
- `chunk` (default) - current behavior, return the matched chunk unchanged.
- `window` - return the matched chunk plus +/- N neighbors by `chunkindex`
  within the same `cmid` (`rag_window_size`, default 1).
- `page` - return the whole module: all chunks for that `cmid`, ordered by
  `chunkindex`, reconstructed into one passage.

Mechanics:
- **Dedup:** multiple top-k hits from the same `cmid` collapse to a single
  expanded parent; it inherits the best (highest) score of its member hits for
  ordering. So `page` mode never injects the same page twice.
- **De-overlap + prefix strip:** because chunks overlap by 50 words and each
  repeats the `[Section] Title:` prefix, reconstruction must (a) keep the prefix
  only on the first segment, and (b) drop the duplicated overlap tail at each
  join. This is the main correctness risk and gets a pure, unit-tested helper.
- **Budget cap:** expanded parents are larger. A `rag_parent_max_chars` cap
  (default 6000) bounds each returned passage; if a page exceeds it, fall back
  to `window` (then `chunk`) for that hit. Expansion also participates in the
  existing `prompt_budget_chars` / section-weight budgeting in
  `context_builder` so page mode cannot blow the prompt.
- **Return shape unchanged** except `content` is now the expanded text; add
  `expand_mode` and `expanded_from` (member chunk count) for telemetry.
  `context_builder` needs no change beyond receiving fuller passages.

No DB schema change (uses existing columns).

## Config (settings.php, RAG section)

| Setting | Default | Meaning |
|---|---|---|
| `rag_return_scope` | `chunk` | `chunk` \| `window` \| `page`. Opt-in to expansion; default preserves current behavior. |
| `rag_window_size` | `1` | Neighbors each side for `window` mode. |
| `rag_parent_max_chars` | `6000` | Per-passage cap; over-cap pages fall back window -> chunk. |

Per-course override via the existing per-course settings pattern
(`rag_return_scope_course_<id>`), optional.

## Implementation units

1. `rag_retriever::expand_parents(array $rows, string $mode, int $courseid): array`
   - For `window`/`page`, fetch the needed sibling chunks per `cmid` (one
     `get_records_select` by `cmid`, ordered by `chunkindex`), reconstruct,
     dedup by `cmid`, apply the char cap with fallback.
2. `content_chunker::reconstruct(array $orderedchunks): string` - pure helper
   that strips the repeated `[Section] Title:` prefix from all but the first
   segment and removes the 50-word overlap at each join. Unit-tested.
3. Wire `expand_parents()` into `retrieve()` after the top-k/rerank selection,
   gated on `rag_return_scope !== 'chunk'`.
4. `prompt_playground.php` and `prompt_debug_view.php` show the expanded
   passage (so admins can inspect what actually gets injected).

## Testing

- Unit (`content_chunker::reconstruct`): overlap removed at joins; prefix kept
  once; ordering by `chunkindex`; a single-chunk page returns unchanged.
- Unit (`expand_parents`): `window` selects correct neighbors; `page` returns
  full module; same-`cmid` hits dedup to one parent with the best score;
  over-cap page falls back to window then chunk.
- Integration (`retrieve`): `rag_return_scope=page` on the BUS101 liability
  query returns whole "Legal Forms of Business" pages, not fragments;
  `rag_return_scope=chunk` is byte-identical to today (regression guard).

## Evaluation note

Recall@k is a property of the small-chunk selection, which is unchanged, so the
existing embedding/rerank benchmark does not measure this feature. The win is
answer coherence/completeness on cross-page questions, which is a generation-
quality signal - evaluate with the real-student-question set (separate spec)
via answer grading, not recall@k.

## Risks / trade-offs

- Higher token cost per turn (bigger passages) - mitigated by the char cap,
  budget integration, and default-off.
- De-overlap correctness is the main implementation risk - isolated in the
  tested pure helper.
- Interaction with `document_first` scoping and rerank is safe: expansion runs
  after both, on already-selected chunks.

## Out of scope

- Cross-document / graph retrieval (GraphRAG) - separate, program-level idea.
- Changing chunk size/overlap or the embedding model.
- Contextual/late chunking (separate embedding-side experiment).
