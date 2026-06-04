# Learning Path Map + Next-Course Nudge — design (v5.9.0)

Status: approved in brainstorming 2026-06-04. Single integrated release.

## 1. Summary

Two complementary surfaces, one shared data model, shipped together:

1. **Learning Path Map** — a visual panel in the SOLA widget (opened from a new
   header button) showing the learner's program as a sequence of course nodes
   (done / current / upcoming, with ordering and "course X of Y"); each node
   expands to its objectives and the learner's mastery of them.
2. **Next-Course Nudge** — when the learner has met the bar for the current
   course, SOLA surfaces a gentle "you're ready for {next course}" prompt in two
   places: a dismissible in-drawer banner (whose CTA opens the map) and an
   advisory line appended to the conversational program-path block.

Both read from a single `learning_path` aggregator. Builds on v5.8.0
`program_path` (forward context) and v5.7.0 `cross_course_mastery` (objective
equivalency). Default off; advisory only; learner's own allocation only;
degrades to silence with no program data.

## 2. Goals / non-goals

Goals:
- Give the learner a forward, visual view of where the current course sits in
  their program and what objectives each course covers.
- Recognize the "ready to advance" moment (completion or high mastery) and point
  forward without pressure.

Non-goals (this release):
- No external digest (email / Moodle notification). In-widget only.
- No starter-chip variant of the nudge.
- No curriculum authoring; we read Saylor's existing program structure.
- No cross-program reconciliation beyond what `program_path` already picks
  (first usable program for the current course).

## 3. Trigger and "readiness"

A learner is **ready** for the current course's next step when **either**:
- the current course is complete per Moodle completion (`completion_info`), OR
- at least `learning_path_mastery_threshold` percent (default **80**) of the
  current course's *tracked* objectives are at mastery,

whichever comes first. "Tracked objectives" = objectives `objective_manager`
lists for the course; mastery uses the same store the v5.7.0 mastery block reads.
Readiness yields the next course from `program_path`'s `next_courses` (empty when
the current course is terminal or the program is any-order with no forward edge).

## 4. Data model (the aggregator)

`classes/program/learning_path.php` — `learning_path` class, constructed with an
optional `program_source_interface` (defaults to `db_program_source`; stub in
tests). Public surface:

- `is_enabled_for_course(int $courseid): bool` — `feature_flags::resolve('learning_path', $courseid)` AND source available.
- `readiness(int $userid, int $courseid): array` — `{ready: bool, reason: 'completion'|'mastery'|null, next_course: ?array{courseid,name}}`. Cheap: current course only. Used by the hook (banner) and `context_builder` (prompt line).
- `full_path(int $userid, int $courseid): ?array` — the complete model for the panel:
  ```
  {
    program: {programid, name},
    position: {index, total},
    courses: [ {courseid, name, position, status: 'done'|'current'|'upcoming',
                ordered: bool, is_current: bool,
                objectives: [ {title, mastery: 'mastered'|'in_progress'|'not_started'|'demonstrated_elsewhere'} ]} ],
    next_courses: [ {courseid, name, reason} ],
    readiness: {ready, reason, next_course}
  }
  ```
  `null` when no usable program/allocation.

Composition:
- program, ordered course list, position, `next_courses`: reuse `program_path`
  (extract its internal program-resolution so both classes share it rather than
  duplicate; `program_path::forward_context` already computes most of this).
- per-course `status`: `done` if the program item is completed
  (`is_item_completed`) or the course is Moodle-complete; `current` if it is the
  course the widget is on; else `upcoming`.
- per-course `objectives`: `objective_manager::list_for_course(courseid)` (only
  where objectives are defined); `mastery` from the mastery store, with
  `demonstrated_elsewhere` when `cross_course_mastery::linked_objectives()` shows
  the learner mastered an equivalent objective in another course.

Honesty: any-order programs set `ordered=false` and are rendered as unordered
membership (never an invented sequence); objectives absent where undefined;
mastery `not_started` where the learner has no data.

## 5. Components

| Unit | File | Responsibility |
|------|------|----------------|
| Aggregator | `classes/program/learning_path.php` | Single source of truth (§4). |
| Web service | `classes/external/get_learning_path.php` | `full_path()` → JSON for the panel. Capability + enrolment checks; `\Throwable`-guarded → empty. |
| WS registration | `db/services.php` | `local_ai_course_assistant_get_learning_path`, ajax=true, logged-in. |
| Panel JS | `amd/src/path_map.js` | Header-toggle panel; fetch via repository; render nodes + expandable objectives; open/close; expand/collapse; banner CTA target. |
| Repository | `amd/src/repository.js` | Add `getLearningPath(courseid)`. |
| Header button | `templates/chat_widget.mustache` | `.btn-path` ("my path" icon), conditional on `{{#pathenabled}}`. |
| Banner | `templates/chat_widget.mustache` + `chat.js` | Server-rendered banner data; shown on open when `ready && !dismissed` (localStorage `aica_path_nudge_<courseid>`); CTA opens panel. |
| Prompt line | `classes/context_builder.php` | When `readiness.ready`, append one advisory line to the existing `program_path` block. |
| Template data | `classes/hook_callbacks.php` | Compute `pathenabled` + readiness summary at render; pass to mustache. |
| Settings | `settings.php` + lang | `learning_path` pedagogy default (off); `learning_path_mastery_threshold` (default 80). |
| Styles | `styles.css` | Panel, nodes, status colors, connectors, banner. Scoped to widget classes; a11y-clean (non-color status cues). |

## 6. Data flow

- **Page load** (`hook_callbacks`): if `learning_path` enabled for the course and
  a program applies to `(user, course)`, compute `readiness` (current course
  only) → pass `pathenabled=true` + banner data to the template. Header button
  renders; banner renders hidden, shown by `chat.js` on open if not dismissed.
- **Panel open** (`path_map.js`): call `get_learning_path` → render the full
  multi-course map lazily (the only place that loads every course's objectives).
- **Chat** (`context_builder`): include the readiness line inside the
  `program_path` block (so it inherits that block's tone + budget priority).

## 7. Error handling and guardrails

- Every read is `\Throwable`-guarded; on any error the feature degrades to
  silence (no button, no banner, empty path, no prompt line) — the widget is
  never affected. Matches `program_path`.
- The web service enforces capability + enrolment and returns only the requesting
  learner's data; the panel renders only what the WS returns.
- Advisory only: never enrolls, never writes mastery. Banner dismissal persists
  per course. The prompt line reuses the `program_path` no-pressure rule.
- Default off; per-course resolvable via `feature_flags`; respects the global
  emergency kill switch; self-suppresses with no program/allocation/forward edge.

## 8. Accessibility

- Status (done/current/upcoming) and mastery use a non-color cue (icon/shape +
  text label), not color alone (WCAG 1.4.1) — consistent with the v5.8.1 fix.
- Panel toggle, node expand/collapse, and banner dismiss are keyboard operable
  with correct ARIA (`aria-expanded`, `aria-controls`, button semantics).
- Add the panel-open and banner states to `tests/a11y/axe-run.js`; target 0
  violations.

## 9. Testing

- **`tests/learning_path_test.php`** (PHPUnit, `stub_program_source` + seeded
  objectives/mastery): status derivation; readiness at complete / ≥80% / the
  79%↔80% boundary / neither; ordered vs any-order; objective mapping;
  `demonstrated_elsewhere`; honesty (no allocation → empty / null).
- **External-function test**: `get_learning_path` structure; capability/enrolment
  enforcement; `\Throwable` degrade to empty.
- **Behat** (`@javascript`, validated locally first): open panel, expand a node,
  dismiss banner, banner CTA opens panel.
- **a11y**: `axe-run.js` includes the new states; 0 violations.
- **i18n**: all new strings translated into the 45 non-English languages; lint
  clean.
- Full plugin suite + validators (0 failures) + jailbreak (32/32) per the
  release checklist.

## 10. Build / release

- New mustache partial (path panel) + `amd/build/path_map.min.js` (terser) +
  `repository.min.js`/`chat.min.js`/`ui.min.js` rebuilds for touched modules.
- `version.php` → v5.9.0; `.wiki/Changelog.md`; release-notes draft via
  `scripts/new_release_notes.py`.
- Deploy to dev (all 5 sites) + BUS101 smoke; verify the panel against the
  `solapilot` program seeded on dev (program id 2, courses 19/20/21, learner 45).

## 11. Out of scope / future

- External nudge digest (email/notification).
- Configurable next-course count and concept-bridge threshold.
- Opt-in course-membership fallback as a configurable mode.
- Drag-to-reorder or any authoring affordance on the map (read-only view).

## 12. Files

**New:** `classes/program/learning_path.php`, `classes/external/get_learning_path.php`,
`tests/learning_path_test.php`, `amd/src/path_map.js` (+ build), a path-panel
mustache partial.

**Modified:** `classes/program/program_path.php` (extract shared program
resolution), `classes/context_builder.php` (readiness line), `classes/hook_callbacks.php`
(template data + banner), `db/services.php`, `amd/src/repository.js`,
`amd/src/chat.js`, `amd/src/ui.js` (panel wiring), `templates/chat_widget.mustache`,
`settings.php`, `lang/en/local_ai_course_assistant.php` (+ 45 langs), `styles.css`,
`tests/a11y/axe-run.js`, `version.php`, `.wiki/Changelog.md`.
