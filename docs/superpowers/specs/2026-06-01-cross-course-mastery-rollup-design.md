# Cross-Course Mastery Rollup + Mastery-Aware Starters — Design

**Date:** 2026-06-01
**Author:** Tom Caswell (with Claude)
**Status:** Draft for review
**Target release:** v5.7.0 (minor — new opt-in capability, additive schema)

---

## Problem

SOLA tracks mastery per course. A learner who has demonstrated mastery of "Interpret a balance sheet" in ACCT201 is treated as a blank slate when the same competency reappears in BUS101. SOLA re-drills it and never acknowledges the transfer. We want SOLA to recognize transferable competency across courses, steer away from re-drilling it, and surface it in the opening starter chips.

Two sub-goals:
1. **Cross-course rollup** — recognize that an objective in course B is "the same competency" as one the learner has already worked in course A, and feed that into SOLA's steering.
2. **Mastery-aware starters (Feature C)** — personalize the existing `focus-next` starter chip so it names the learner's actual weak objective, and can nudge "go deeper" on competencies already mastered elsewhere.

## Key finding (dev investigation, 2026-06-01)

Dev site has **zero Moodle competencies** (`frameworks=0, competencies=0, course_links=0`). So the exact competency-id join matches nothing on real Saylor data today. The **fuzzy title path is what will actually fire** on Saylor courses. We build both: exact match is cheap, correct, and free for any install that adopts competencies later; fuzzy match carries the real data now.

## Scope (v1)

In scope:
- Cross-course objective linking by two methods: exact competency `external_ref`, and normalized/fuzzy title similarity. Optional embedding-backed similarity is **seamed but gated on RAG embeddings being configured** — string similarity is the always-on default.
- A precomputed link table, rebuilt by a scheduled task + an admin "Rebuild now" button.
- A read-side resolver returning **transfer evidence** for a learner in a course.
- Advisory prompt-injection block (does NOT mutate stored mastery numbers).
- Personalized, cross-course-aware `focus-next` starter label.
- Two new per-course feature flags (default off), both additionally gated on `mastery` being on.

Explicitly out of scope (YAGNI for v1):
- Merging cross-course attempts into the stored mastery score. We stay **advisory only** — same low-risk posture as prereq-gap detection and the reason the decay model shipped behind a flag. Changing the displayed mastery number based on another course is a separate, riskier decision.
- A cross-course visual dashboard. The learner Progress tab stays per-course.
- Admin UI to hand-curate links (the third matching option from brainstorming). Exact + fuzzy covers it; manual curation is a future add if fuzzy proves noisy.

## Architecture

### New class: `classes/cross_course_mastery.php`

Pure read-side + a rebuild routine. No state beyond the link table.

```
normalize_title(string $title): string
    lowercase, collapse internal whitespace, strip leading/trailing
    punctuation and a leading "LOn:"/"Unit n —" style prefix.

title_similarity(string $a, string $b): float   // 0.0–1.0
    string-based (similar_text percent / 100). Single seam where an
    embedding-backed implementation can later override when RAG is on.

rebuild_links(): array   // returns counts by method, for admin feedback
    Scan all objectives across all courses. Emit link rows for:
      - 'ref'        identical non-empty external_ref          score 1.0
      - 'title_exact' identical normalize_title()              score 1.0
      - 'title_fuzzy' title_similarity() >= threshold (0.88)   score = ratio
    Never link two objectives in the SAME course. Idempotent:
    truncate-and-rebuild inside a transaction. Symmetric pairs stored
    once with objectiveida < objectiveidb.

linked_objectives(int $objectiveid): array
    Indexed lookup in the link table — both directions.

get_transfer_evidence(int $userid, int $courseid): array
    For each objective in $courseid the learner has NOT mastered locally,
    find linked objectives in OTHER courses where the learner's mastery
    status is 'mastered' (and optionally 'learning'). Return:
      [ ['objective'=>row, 'source_courseid'=>int, 'source_coursename'=>str,
         'source_status'=>str, 'method'=>str, 'score'=>float], ... ]
    Returns [] when crossmastery flag is off for $courseid.

is_enabled_for_course(int $courseid): bool
    feature_flags::resolve('crossmastery', $courseid)  AND  mastery on.
```

### New table: `local_ai_course_assistant_obj_links`

| field | type | notes |
|---|---|---|
| id | int seq | |
| objectiveida | int | FK objs.id, always the lower id |
| objectiveidb | int | FK objs.id, always the higher id |
| method | char(20) | ref / title_exact / title_fuzzy / title_embed |
| score | number(5,4) | match confidence 0–1 |
| timemodified | int | |

Indexes: unique (objectiveida, objectiveidb); non-unique (objectiveidb) for reverse lookup. One savepoint bump in `db/upgrade.php`.

### Scheduled task: `classes/task/rebuild_objective_links.php`

Daily. Calls `cross_course_mastery::rebuild_links()`. Registered in `db/tasks.php`. Cheap to run; objective counts are small (tens per course).

### Admin "Rebuild now" button

On the existing `objectives_admin.php` (or a small action on the RAG/mastery admin surface), a button that triggers `rebuild_links()` and reports the per-method counts. Mirrors the existing reindex-now pattern from `rag_admin.php`.

### Prompt injection integration

Extend `objective_manager::build_prompt_injection()`: after the prereq-gap block, if `cross_course_mastery::is_enabled_for_course()` and `get_transfer_evidence()` is non-empty, append:

```
### Already demonstrated elsewhere
The learner has shown mastery of related objectives in other courses.
Acknowledge this prior competency, connect to it when relevant, and do
NOT re-drill from scratch unless the learner struggles. Phrase naturally
("you've worked with X before in another course — let's build on that").

- [obj title] — mastered in [course name]
```

Advisory only. Stored mastery numbers untouched. `objective_manager` calls into `cross_course_mastery`, not vice versa (one-way dependency).

### Mastery-aware starter (Feature C)

Today: `chat.js` has a static `focus-next` starter ("What should I focus on?") that calls `get_next_best_action`. v1 makes the **chip label dynamic**:

- Extend `next_best_action::recommend()` (or a thin wrapper) to also return: a short personalized starter label naming the top weak objective (e.g. "Practice: Cash flow statements"), and at most one cross-course "go deeper" nudge drawn from `get_transfer_evidence()`.
- New per-course flag `mastery_starter` (default off, gated on mastery). When on, `chat.js` fetches the personalized label when building the starters overlay and swaps the generic chip text. Falls back to the generic "What should I focus on?" label whenever mastery is off, the flag is off, or there is no weak/transfer data — so the chip never renders empty.
- No new external strictly required if NBA already returns weak objectives; add a `starter_label` field to its response and a cross-course nudge field. Keeps one source of truth.

### Feature flags

Add to `feature_flags` + the Pedagogy-defaults admin section (both default off):
- `crossmastery` — cross-course rollup master switch.
- `mastery_starter` — personalized starter chip.

Both resolve via the existing `feature_flags::resolve($feature, $courseid)` order (per-course override → site default → off), and both are no-ops unless `mastery` is also on for the course.

## Data flow

```
[daily task / admin button]
   -> cross_course_mastery::rebuild_links()
   -> obj_links table (precomputed cross-course pairs)

[chat request, mastery + crossmastery on]
   context_builder
   -> objective_manager::build_prompt_injection()
        -> cross_course_mastery::get_transfer_evidence()
             -> linked_objectives() [obj_links lookup]
             -> objective_manager::compute_mastery() on linked objs
        -> advisory "Already demonstrated elsewhere" block

[starters overlay, mastery + mastery_starter on]
   chat.js -> Repo.getNextBestAction()
   -> next_best_action::recommend()  (+ starter_label, + cross-course nudge)
   -> dynamic chip label, graceful fallback
```

## Error handling

- `rebuild_links()` runs in a DB transaction; a throw rolls back and leaves the prior link table intact (task logs the error, retries next run).
- All read paths fail soft: if `obj_links` is empty or a linked course/objective was deleted, `get_transfer_evidence()` returns `[]` and prompt injection simply omits the block. Same `\Throwable` fall-back posture as the RAG path.
- Front-end: any failure fetching the personalized label falls back to the static starter text. Never blocks the overlay.
- Deleted objectives: link rows are cleaned on the next rebuild; a stale row resolving to a missing objective is skipped at read time (defensive `isset`/record-exists check).

## Testing (TDD)

`tests/cross_course_mastery_test.php`:
- `normalize_title` strips case/whitespace/punctuation/prefixes correctly.
- `title_similarity` near-1.0 for paraphrases, low for unrelated.
- `rebuild_links`: exact-ref grouping; title_exact; title_fuzzy above/below threshold; **no same-course links**; idempotent across two runs; symmetric pair stored once.
- `get_transfer_evidence`: returns evidence when learner mastered a linked obj in another course and not locally; empty when crossmastery off; empty when already mastered locally; respects method/score.
- `build_prompt_injection`: includes the transfer block when evidence present + flag on; omits when flag off; omits when no evidence.
- `next_best_action`: returns a non-empty `starter_label` for a learner with weak objectives; safe generic fallback when none.

Run via the existing PHPUnit harness alongside `tests/mastery_test.php`. No LLM round-trip — all deterministic fixtures.

## Schema / version impact

- One new table, one savepoint in `db/upgrade.php`, `version.php` bump to `5.7.0`.
- New scheduled task in `db/tasks.php`.
- Two new admin settings (`crossmastery`, `mastery_starter` defaults), both off — upgrades are a no-op until an admin opts in.
- New lang strings (flag labels, admin button, starter fallback) + i18n sync across 46 languages.
- No breaking changes. No change to any existing stored value.

## Sequencing (implementation order)

1. Schema + `cross_course_mastery` class + unit tests (TDD) — the pure-PHP heart.
2. Scheduled task + admin rebuild button.
3. Prompt-injection integration + test.
4. Feature flags + Pedagogy-defaults wiring.
5. Mastery-aware starter (NBA field + chat.js + flag).
6. Lang strings + i18n sync, JS rebuild, CI/jailbreak/BUS101 smoke, release.

Steps 1–4 are shippable as the rollup core even if step 5 (the starter) slips.

## Open questions for Tom

- **Include 'learning' (not just 'mastered') status in transfer evidence?** Default proposed: only `mastered` transfers as "already demonstrated"; `learning` elsewhere is too weak a signal to suppress drilling. Easy to widen later.
- **Fuzzy threshold 0.88** — tunable; will eyeball real BUS101/other-course objective pairs once objectives are seeded.
- **Embedding-backed similarity in v1?** Proposed: seam it, ship string-similarity, turn on embeddings only if string match proves too noisy. Cheaper and deterministic for the first cut.
