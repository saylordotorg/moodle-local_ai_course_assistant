# Forward Learning-Path Awareness — Design Spec

**Date:** 2026-06-02
**Status:** Approved (design); pending implementation plan
**Component:** `local_ai_course_assistant` (SOLA)
**Target release:** likely v5.8.0
**Relationship:** Forward-direction complement to v5.7.0 cross-course mastery rollup.

> **Provenance note.** An earlier auto-generated draft of this file asserted
> production-specific details (an `enrol_programs_*` table prefix on prod,
> named programs, a plugin version) that were **never verified**. This version
> keeps only what was actually confirmed on dev.sylr.org and marks every
> remaining assumption as something to verify before release. See §3.

---

## 1. Problem

SOLA already looks *backward* across courses: within-course prerequisite-gap detection ("this builds on X — want to review first?") and v5.7.0 cross-course mastery ("you've demonstrated this elsewhere"). It has no concept of what comes *next* — students can't see, in conversation, how the course they're in fits into a larger learning path, what they'll study next, or how today's concepts feed forward.

## 2. Goal & non-goals

**Goal:** a conversational, advisory prompt block — mirroring cross-course mastery — that lets SOLA reference the learner's forward path: the program this course belongs to, the learner's position in it, the next course(s), and (when data allows) the specific objective that bridges to the next course.

**Non-goals (v1):**
- No visual "path map" UI, no dashboard tab, no new student-facing surface beyond chat.
- No new database table, no scheduled task, no schema change.
- No enrolment changes, no mastery changes — strictly advisory.
- No proactive "you're ready to advance" nudges/emails (possible later).
- No authoring of curriculum — we read Saylor's existing program structure.

## 3. Data source

Saylor's "Degrees" are represented in Moodle by the **Programs plugin**.

**Verified (dev.sylr.org, 2026-06-02, read-only SSM probe):**
- The plugin is installed under the `tool_muprog_*` table prefix.
- `tool_muprog_program` had 1 row: "Administrative or Virtual Assistant Specialization".
- `tool_muprog_item` modeled it as a `top` item with `sequencejson` `{"children":["2"],"type":"allinanyorder"}` and a `course` item (`courseid=2`, "PRDV225: Managing Employees").
- `tool_muprog_prerequisite` and `tool_muprog_allocation` exist with the schemas below and held data (1 row each).
- Core competency tables are empty (0 rows) — competencies are **not** the path source.

**Production naming (confirmed by route evidence):**
- Both `learn.saylor.org` and `degrees.saylor.org` serve a live `/enrol/programs/catalogue/index.php` catalog. That route is owned by the upstream **`enrol_programs`** plugin, so **production uses the `enrol_programs_*` table prefix** while dev uses `tool_muprog_*`. The schemas are otherwise identical; the adapter (§4.2) detects whichever prefix is present. (The catalogs require login, so program rows could not be read anonymously; prod row-level confirmation happens during the dev/prod verification step, but the prefix is settled.)

**Two real use cases this must serve (per product owner):**
- **Degrees (`degrees.saylor.org`)** — more degree sequences *with prerequisites and required order*. Here forward "next course" fires reliably from prerequisite edges and `allinorder` sets.
- **Learn (`learn.saylor.org`)** — most of the course catalog plus small certificates, *often with little or no required sequence*. Here many programs are any-order with no prerequisites, so the feature surfaces program membership + position (and concept equivalencies when present) but asserts no specific "next course."
- The design meets both because it only claims "next" where prerequisites or `allinorder` encode it, and otherwise degrades to membership + position. The any-order degradation is a feature, not a gap.

Relevant tables (shown with the verified `tool_muprog_` prefix; any `enrol_programs_` equivalent is expected to be schema-identical and MUST be confirmed):

| Table | Key columns | Use |
|---|---|---|
| `tool_muprog_program` | `id`, `fullname`, `idnumber`, `archived` | the program (path) container |
| `tool_muprog_item` | `id`, `programid`, `type` (`top`/`set`/`course`), `topitem`, `courseid`, `previtemid`, `sequencejson`, `fullname` | the ordered tree of courses/sets |
| `tool_muprog_prerequisite` | `id`, `itemid`, `prerequisiteitemid` | explicit "item builds on item" edges |
| `tool_muprog_allocation` | `id`, `programid`, `userid`, `archived` | which programs a learner is in |
| `tool_muprog_completion` | `itemid`, `allocationid`, `timecompleted` | filter already-completed forward courses |

**Ordering nuances (verified in dev data + schema):**
- A `top`/`set` item carries `sequencejson` like `{"children":["2","3"],"type":"allinanyorder"}`. `type` distinguishes ordered (`allinorder`) from unordered (`allinanyorder`).
- `previtemid` forms a linked list giving item order within a set.
- Forward sequence is asserted **only** from explicit prerequisites or from sets explicitly marked `allinorder`. Any-order programs with no prerequisites yield membership + position but no specific "next."

## 4. Architecture

Separate **logic** from **data access** so the logic is unit-testable without the third-party program tables, and so the table-prefix variance is handled in one place. Mirrors the existing `provider_interface` / `stub_provider` pattern.

### 4.1 `program_source_interface` (`classes/program/program_source_interface.php`)

Declares the data the feature needs, in plain-array terms:

```php
interface program_source_interface {
    /** Are program tables present on this install at all? */
    public function is_available(): bool;

    /**
     * Non-archived programs the user is allocated to.
     * @return array<int, array{programid:int, name:string}>
     */
    public function get_user_programs(int $userid): array;

    /**
     * Non-archived programs that contain the given course, regardless of
     * allocation — used only as a fallback when the learner has no allocation
     * (see §4.4 step 2; off by default per the honesty rule).
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

    /** Has the user completed the given program item? */
    public function is_item_completed(int $userid, int $programid, int $itemid): bool;
}
```

### 4.2 `db_program_source` (`classes/program/db_program_source.php`)

The real adapter. Responsibilities:
- Detect the active prefix once: `tool_muprog_` if `tool_muprog_program` exists, else `enrol_programs_` if `enrol_programs_program` exists, else "unavailable." (Prefix set confirmed against prod before release — §3.)
- Implement each interface method against that prefix using `$DB`.
- Resolve item order from `sequencejson` children + `previtemid`; mark each set ordered/unordered from `sequencejson.type` (`allinorder` → ordered; anything else → unordered for v1).
- Return **visible** courses truthfully; include hidden ones flagged `visible=false` so the logic excludes them (never leak a hidden course title).
- Every public method wrapped so any DB/schema error returns the empty/`false` result (degrade to silence). Catch `\Throwable`, not `\Exception`.

Thin, no business logic. Verified on dev against the live tables (§9), not in PHPUnit.

### 4.3 `stub_program_source` (`classes/program/stub_program_source.php`)

Array-backed implementation built from fixtures, for unit tests. Lets tests express "a 5-course ordered program where the learner is on course 2, and course 3 lists course 2 as a prerequisite" with no database.

### 4.4 `program_path` (`classes/program_path.php`)

Pure logic over a `program_source_interface` — the single place that decides what to say.

```php
class program_path {
    public function __construct(program_source_interface $source) { ... }

    /** Feature available AND flag on for this course. */
    public function is_enabled_for_course(int $courseid): bool;

    /**
     * Forward-path context for this learner+course, or null if none.
     * @return null|array{
     *   program: array{programid:int, name:string},
     *   position: array{index:int, total:int},          // 1-based
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
2. Programs = `get_user_programs(userid)` (allocation = "your path"). Course-membership fallback (`get_programs_for_course`) is **off by default** per the honesty rule (§7); it exists in the interface for a possible opt-in later, not v1's default path.
3. For the first candidate program that yields a usable result:
   a. `courses = get_program_courses(programid)`; find the item with `courseid == courseid`. Skip program if absent.
   b. `position` = that course's 1-based index among **visible** course items, and the visible total.
   c. `next_courses` (cap 2), priority order:
      - **prerequisite:** items J where this course's item ∈ `get_prerequisites(programid)[J]`; reason `prerequisite`.
      - **sequence:** else, if this course's set is `ordered`, the next visible course item; reason `sequence`.
      - exclude hidden courses, the current course, and items the learner has already completed (`is_item_completed`).
   d. `concept_links` (cap 2, optional): only if `next_courses` non-empty AND objectives are enabled for the current course. The only cross-course objective signal in the data model is **equivalency** (the v5.7.0 `obj_links` table via `cross_course_mastery::linked_objectives()`), not a forward objective-prerequisite chain (objective prereqs are within-course only). So a concept link = a current-course objective whose cross-course equivalent lands in one of the `next_courses`. Forward framing stays truthful because that course is later in the path: "the work you're doing on X here is the foundation for X in {next course}." Empty when nothing links forward.
   e. If a program yields neither a `next_course` nor a meaningful `position` (total < 2), skip it.
4. Return the first usable program's context, else null.

**Multiple programs:** first usable candidate wins; do not stack programs into one block.

### 4.5 Prompt injection wiring

`context_builder` already calls `objective_manager::build_prompt_injection(...)` (~`context_builder.php:309`). Add an independent call to `program_path::build_prompt_injection(...)` immediately after the objective/cross-course block, so the program block appends after "Already demonstrated elsewhere" and does **not** depend on objectives/mastery being enabled (course-level path awareness works without them).

Block text:

```
### Where this course leads
This course is part of the "{program}" path (course {index} of {total}). What the
learner builds here leads into "{next course}", which builds on it directly.
{optional: Specifically, the "{objective}" work here is the foundation for
"{next objective}" there.} When it fits naturally, help the learner see how
today's work connects forward; motivate with the path, but never sound like
you're reading a database, and never push them ahead before they're ready.
```

With no `next_courses` (any-order program), the block degrades to a single membership+position sentence and drops the "leads into" clause.

## 5. Configuration & off switches

- New pedagogy-default flag **`program_path`**, **default off**, resolved via `feature_flags::resolve('program_path', $courseid)` (per-course override → site default → false).
- `settings.php`: add `'program_path_enabled' => 'pedagogy:program_path'` to the pedagogy-defaults `foreach` block.
- Respects the existing **`emergency_control`** global kill switch alongside the other pedagogy features.
- **Three independent off switches** plus auto-suppression: per-course override, site default, emergency kill; and the feature self-disables silently when the program plugin is absent, the learner has no allocation, or no valid forward context exists.
- **Not** gated on mastery; the concept layer checks objective availability at runtime and no-ops when unavailable.

## 6. Lang strings (English; 45-language translation at release, like v5.7.0)

- `pedagogy:program_path` — e.g. "Forward learning-path awareness on by default"
- `pedagogy:program_path_desc` — describes the advisory forward block, the program-plugin dependency, default-off, advisory-only.
- Any block scaffolding strings used by `build_prompt_injection` (English source; translated at release).

## 7. Privacy & safety

- Reads only the **current learner's own** allocations and the program's public structure. No other user's data → memory-leak/jailbreak safe (covered by the existing suite; re-confirm 32/32).
- Emits only **visible** course titles; hidden/restricted courses excluded via the `visible` flag.
- Advisory only: never enrols, never changes mastery, never reveals scores or "how it knows."
- `\Throwable`-guarded at the adapter boundary: any program-plugin schema difference degrades to no block rather than an error.
- **Honesty rule:** "your path" claims come from the learner's own allocation. The course-membership fallback is off by default so SOLA never calls a generic program "your path."

## 8. Performance

- Program tables are small; a handful of indexed reads per request, only when the flag is on and a program is found.
- Per-request static memoization in `db_program_source` (detected prefix + per-program reads). No persistent cache table, no scheduled rebuild — the source plugin is authoritative and read live, so data is always fresh.

## 9. Testing (TDD)

`tests/program_path_test.php`, driving `program_path` with `stub_program_source` fixtures:
- **Position math:** course 2 of 5; visible-only counting (a hidden course doesn't shift visible index/total).
- **Next-course priority:** prerequisite beats sequence; ordered-set sequence used when no prereq; any-order with no prereq → no next course (membership/position only).
- **Completed-course filtering:** a completed forward item is excluded.
- **Concept bridge:** objectives linking current→next produce a concept_link; otherwise concept_links empty and the block omits the clause.
- **Gating:** flag off → null; source unavailable → null.
- **Fallback flag:** with the (default-off) course-membership fallback disabled, no allocation → null; behavior of the fallback path covered separately.
- **Multiple programs:** first usable candidate wins; allocation preferred.
- **Block formatting:** exact strings for next-course-present, next-course-absent, and concept-link-present.
- **Graceful no-op:** empty source → null, '' block.

The `db_program_source` adapter is **verified manually on dev.sylr.org** against the live `tool_muprog_*` tables, and the **prod table prefix is confirmed** there (§3) before release. Not run in CI (no program tables in the CI/test DB).

## 10. Pre-release checklist additions (beyond the standard release flow)

- Production prefix is `enrol_programs_*` (route-confirmed); verify `db_program_source` detects it and reads `enrol_programs_program/_item/_prerequisite/_allocation/_completion` correctly. Dev remains `tool_muprog_*`.
- Confirm behavior against both shapes: an **ordered/prereq** degree program (degrees) and an **any-order** Learn program/cert (membership + position only).
- Standard gates: full plugin suite, validators 36/0, jailbreak 32/32, lang-completeness; version bump; 45-language strings; `.wiki/Changelog.md`; `.drafts/` release notes + walkthrough; tag; GH release; `deploy_dev --target all` + smoke.

## 11. Out of scope / future

- Visual learning-path map (the "Visual path map" brainstorming option).
- Proactive milestone nudges via starter/digest.
- Opt-in course-membership fallback as a configurable mode.
- Configurable next-course count; configurable fuzzy concept-bridge threshold.
- Precomputed concept-bridge table (only if live concept coverage proves valuable but too sparse).

## 12. Files

**New:**
- `classes/program/program_source_interface.php`
- `classes/program/db_program_source.php`
- `classes/program/stub_program_source.php`
- `classes/program_path.php`
- `tests/program_path_test.php`

**Modified:**
- `classes/context_builder.php` — call `program_path::build_prompt_injection()` after the objective block.
- `settings.php` — register `program_path_enabled` pedagogy default.
- `lang/en/local_ai_course_assistant.php` — new strings.
- `version.php` — bump (likely v5.8.0).
- `.wiki/Changelog.md` — new entry.
