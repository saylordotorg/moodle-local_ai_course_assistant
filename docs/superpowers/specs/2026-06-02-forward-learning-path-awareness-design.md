# Forward Learning-Path Awareness — Design Spec

**Date:** 2026-06-02
**Status:** Approved (approach C, hybrid phased)
**Component:** `local_ai_course_assistant` (SOLA)
**Target release:** v5.8.0

---

## 1. Problem

SOLA already looks *backward* across courses: within-course prerequisite-gap detection ("this builds on X — want to review first?") and v5.7.0 cross-course mastery ("you've demonstrated this elsewhere"). It has no concept of what comes *next*. Students can't see, in conversation, how the course they're in fits into a larger learning path — what they'll learn next, or how today's concepts feed forward into later courses.

This feature gives SOLA *forward* awareness: when a course belongs to a Saylor program (specialization or degree), SOLA can naturally tell the learner where this course leads and how the concepts connect forward.

## 2. Goal & non-goals

**Goal:** A conversational, advisory prompt block — mirroring cross-course mastery — that lets SOLA reference the learner's forward path: the program this course belongs to, the learner's position in it, the next course(s), and (when data allows) the specific objective that bridges to the next course.

**Non-goals (v5.8.0):**
- No visual "path map" UI, no dashboard tab, no new student-facing surface beyond the chat conversation.
- No new database table, no scheduled task, no schema change.
- No enrolment changes, no mastery changes — strictly advisory.
- No proactive "you're ready to advance" nudges/emails (possible later).

## 3. Data source

Saylor uses the Open LMS "Programs" plugin. **Production (learn.saylor.org, degrees.saylor.org) runs it under the public `enrol_programs` naming; the dev box (dev.sylr.org) runs the same codebase under the older `muprog` naming (`enrol_muprog` / `tool_muprog` / `block_muprog_my`, version `2026041945`).** The schemas are identical; only the table prefix differs (`enrol_programs_*` vs `tool_muprog_*`). The feature MUST detect and support both, and no-op gracefully where neither is installed (e.g. the local dev Moodle, or any non-program install).

Relevant tables (shown with the `muprog` prefix; `programs` prefix is identical in shape):

| Table | Key columns | Use |
|---|---|---|
| `tool_muprog_program` | `id`, `fullname`, `idnumber`, `archived` | the program (path) container |
| `tool_muprog_item` | `id`, `programid`, `type` (`top`/`set`/`course`), `topitem`, `courseid`, `previtemid`, `sequencejson`, `fullname` | the ordered tree of courses/sets |
| `tool_muprog_prerequisite` | `id`, `itemid`, `prerequisiteitemid` | explicit "item builds on item" edges |
| `tool_muprog_allocation` | `id`, `programid`, `userid`, `archived` | which programs a learner is in |

**Ordering nuances discovered in live data:**
- A program's top/set item carries `sequencejson` like `{"children":["2","3"],"type":"allinanyorder"}`. The `type` distinguishes ordered (`allinorder`) from unordered (`allinanyorder`) sets.
- `previtemid` forms a linked list giving item order within a set.
- Real programs are **mixed**: some are strictly ordered (e.g. "ESL for Healthcare" — "complete each course in order"), most are "progressive but any-order." So forward sequence is reliable only from explicit prerequisites or from sets explicitly marked ordered. Any-order programs yield membership + position but no specific "next."

## 4. Architecture

Separate **logic** from **data access** so the logic is unit-testable without the third-party program tables, and so both table namings are handled in one place. This mirrors the existing `provider_interface` / `stub_provider` pattern.

### 4.1 `program_source_interface` (`classes/program/program_source_interface.php`)

Declares the data the feature needs, in plain-array terms:

```php
interface program_source_interface {
    /** Are program tables present on this install at all? */
    public function is_available(): bool;

    /**
     * Programs (not archived) the user is allocated to.
     * @return array<int, array{programid:int, name:string}>
     */
    public function get_user_programs(int $userid): array;

    /**
     * Programs (not archived) that contain the given course, regardless of
     * allocation — used as a fallback when the learner has no allocation.
     * @return array<int, array{programid:int, name:string}>
     */
    public function get_programs_for_course(int $courseid): array;

    /**
     * All course-bearing items of a program, in resolved order.
     * @return array<int, array{itemid:int, courseid:int, coursename:string,
     *                          visible:bool, ordered:bool, position:int}>
     */
    public function get_program_courses(int $programid): array;

    /**
     * Prerequisite edges within a program: itemid => [prerequisiteitemid, ...].
     * @return array<int, int[]>
     */
    public function get_prerequisites(int $programid): array;
}
```

### 4.2 `db_program_source` (`classes/program/db_program_source.php`)

The real adapter. Responsibilities:
- Detect the active prefix once: `tool_muprog_` if `tool_muprog_program` exists, else `enrol_programs_` if `enrol_programs_program` exists, else "unavailable."
- Implement each interface method against that prefix using `$DB`.
- Resolve item order from `sequencejson` children + `previtemid`, and mark each set ordered/unordered from `sequencejson.type`: treat `type === 'allinorder'` as ordered; any other value (`allinanyorder`, `atleast`, `all`, etc.) as unordered for v1.
- Return only **visible** courses' names truthfully; include hidden ones flagged `visible=false` so the logic can exclude them (never leak a hidden course title).
- Every public method wrapped so any DB/schema error returns the empty/`false` result (the feature degrades to silence). `\Throwable`, not `\Exception`.

This adapter is intentionally thin and contains no business logic. It is verified on dev (where the real tables live), not in PHPUnit.

### 4.3 `stub_program_source` (`classes/program/stub_program_source.php`)

Array-backed implementation constructed from fixtures, for unit tests. Lets tests express "a 5-course ordered program where the learner is on course 2, course 3 lists course 2 as a prerequisite" without any database.

### 4.4 `program_path` (`classes/program_path.php`)

Pure logic over a `program_source_interface`. The single place that decides what to say.

```php
class program_path {
    public function __construct(program_source_interface $source) { ... }

    /** Feature available AND flag on for this course. */
    public function is_enabled_for_course(int $courseid): bool;

    /**
     * The forward-path context for this learner+course, or null if none.
     * @return null|array{
     *   program: array{programid:int, name:string},
     *   position: array{index:int, total:int},        // 1-based; index/total
     *   next_courses: array<int, array{courseid:int, name:string,
     *                                  reason:'prerequisite'|'sequence'}>,
     *   concept_links: array<int, array{objective:string,
     *                                   next_course:string,
     *                                   next_objective:string}>
     * }
     */
    public function forward_context(int $userid, int $courseid): ?array;

    /** Render the advisory prompt block, or '' when forward_context is null. */
    public function build_prompt_injection(int $userid, int $courseid): string;
}
```

**`forward_context` algorithm:**
1. If not available / flag off → null.
2. Programs = `get_user_programs(userid)`; if empty, fall back to `get_programs_for_course(courseid)`.
3. For each candidate program (stop at the first that yields a usable result):
   a. `courses = get_program_courses(programid)`; find the item whose `courseid == courseid`. Skip program if not found.
   b. `position` = that course's 1-based index among **visible** course items, and the visible total.
   c. `next_courses` (cap 2), in priority order:
      - **prerequisite:** items J where this course's item ∈ `get_prerequisites(programid)[J]`; reason `prerequisite`.
      - **sequence:** if none, and this course's set is `ordered`, the next visible course item after it; reason `sequence`.
      - exclude hidden courses and the current course.
   d. `concept_links` (cap 2, optional): only if `next_courses` non-empty AND objectives are enabled for the current course. The only cross-course objective signal in the data model is **equivalency** (the v5.7.0 `obj_links` table), not a forward prerequisite chain (objective prereqs are within-course only). So a concept link is: an objective in the *current* course whose cross-course equivalent (`cross_course_mastery::linked_objectives()`) lands in one of the `next_courses`. `next_objective` is that linked objective's title (usually identical; may differ slightly under fuzzy match). The forward framing is truthful because the linked course is later in the path: "the work you're doing on X here is the foundation for X in {next course}." Empty when no current-course objective links into a next course.
   e. If the program yields neither a `next_course` nor a meaningful `position` (total < 2), skip it.
4. Return the first usable program's context, else null.

**Multiple programs:** take the first candidate that yields a usable result (allocations before course-membership fallback). Do not stack multiple programs into one block — keep the message focused.

### 4.5 Prompt injection wiring

`context_builder` already calls `objective_manager::build_prompt_injection(...)` (around `context_builder.php:309`). Add an independent call to `program_path::build_prompt_injection(...)` immediately after the objective/cross-course block, so the program block:
- appends after "Already demonstrated elsewhere," and
- does **not** depend on objectives/mastery being enabled (course-level path awareness works without them).

The block text:

```
### Where this course leads
This course is part of the "{program}" path (course {index} of {total}). What the
learner builds here leads into "{next course}", which builds on it directly.
{optional: Specifically, the "{objective}" work here is the foundation for
"{next objective}" there.} When it fits naturally, help the learner see how
today's work connects forward; motivate with the path, but never sound like
you're reading a database, and never push them ahead before they're ready.
```

When there is no `next_courses` (any-order program), the block degrades to a single membership+position sentence and drops the "leads into" clause.

## 5. Configuration

- New pedagogy-default flag **`program_path`**, **default off**, resolvable per-course via the existing `feature_flags::resolve('program_path', $courseid)` order (per-course override → site default → false).
- `settings.php`: add `'program_path_enabled' => 'pedagogy:program_path'` to the pedagogy-defaults `foreach` block.
- **Not** gated on mastery. The concept-enrichment layer simply checks objective availability at runtime and is a no-op when off.

## 6. Lang strings (English; 45-language translation at release like v5.7.0)

- `pedagogy:program_path` — "Forward learning-path awareness on by default"
- `pedagogy:program_path_desc` — describes the advisory forward block, the program-plugin dependency, default-off, advisory-only.

## 7. Privacy & safety

- Reads only the **current learner's own** allocations and the program's public structure. No other user's data → memory-leak/jailbreak safe (covered by existing suite).
- Emits only **visible** course titles; hidden/restricted courses are excluded by the `visible` flag.
- Advisory only: never enrols, never changes mastery, never reveals scores or "how it knows."
- Whole feature is `\Throwable`-guarded at the adapter boundary: any program-plugin schema difference degrades to no block rather than an error.

## 8. Performance

- Program tables are small; the feature issues a handful of indexed reads per request only when the flag is on and a program is found.
- Per-request static memoization in `db_program_source` (cache the detected prefix and per-program reads within a request). No persistent cache table and no scheduled rebuild — the source plugin is authoritative and read live, so data is always fresh.

## 9. Testing (TDD)

`tests/program_path_test.php`, driving `program_path` with `stub_program_source` fixtures:
- **Position math:** course 2 of 5; visible-only counting (a hidden course doesn't shift the visible index/total).
- **Next-course priority:** prerequisite beats sequence; ordered-set sequence used when no prereq; any-order with no prereq yields no next course (membership/position only).
- **Concept bridge:** when objectives link current→next, a concept_link is produced; when they don't, concept_links is empty and the block omits the clause.
- **Gating:** flag off → null; source unavailable → null.
- **Fallback:** no allocation but course is in a public program → uses course-membership fallback.
- **Multiple programs:** first usable candidate wins; allocation preferred over fallback.
- **Block formatting:** exact strings for next-course present, next-course absent, and concept-link present.
- **Graceful no-op:** empty source → null, '' block.

The `db_program_source` adapter is verified manually on dev.sylr.org against the live `tool_muprog_*` tables (and is structurally identical for `enrol_programs_*`).

## 10. Out of scope / future

- Visual learning-path map (the "Visual path map" option from brainstorming).
- Proactive milestone nudges ("you're ready for the next course") via starter/digest.
- Configurable number of next courses surfaced; configurable fuzzy concept-bridge threshold.
- Degrees-site enumeration was gated behind login during design; structure is identical to Learn, so no design impact.

## 11. Files

**New:**
- `classes/program/program_source_interface.php`
- `classes/program/db_program_source.php`
- `classes/program/stub_program_source.php`
- `classes/program_path.php`
- `tests/program_path_test.php`

**Modified:**
- `classes/context_builder.php` — call `program_path::build_prompt_injection()` after the objective block.
- `settings.php` — register `program_path_enabled` pedagogy default.
- `lang/en/local_ai_course_assistant.php` — two new strings.
- `version.php` — bump to v5.8.0.
- `.wiki/Changelog.md` — v5.8.0 entry.
