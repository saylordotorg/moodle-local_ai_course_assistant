# Learning Path Map + Next-Course Nudge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship v5.9.0: a visual learning-path panel in the SOLA widget plus a "ready for next course" nudge (banner + conversational line), both backed by one `learning_path` aggregator.

**Architecture:** A new `learning_path` class composes v5.8.0 `program_path` (program/ordering/next course) with `objective_manager::compute_course_summary` (per-course objectives + mastery) and `cross_course_mastery` (demonstrated-elsewhere). `readiness()` (current course only) feeds `hook_callbacks` (banner) and `context_builder` (prompt line); `full_path()` feeds a `get_learning_path` web service that the AMD `path_map` panel renders lazily. Default off, `\Throwable`-guarded to silence, learner's own allocation only.

**Tech Stack:** Moodle 4.5 local plugin, PHP 8.3, PHPUnit, Moodle external API, AMD (terser build), Mustache, Behat, axe-core a11y harness.

**Conventions:** US spelling. PHPUnit via `/opt/homebrew/opt/php@8.3/bin/php`. Run a single test: `/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --filter <name> local/ai_course_assistant/tests/<file>` from `~/Sites/moodle`. Commit after each green step. Deploy to local: `rsync` + `purge_caches.php` (see CLAUDE.md).

---

## Task 1: Extract shared program resolution from `program_path`

Both `program_path` and the new `learning_path` need "which program + ordered course list + position does this (user, course) belong to". Extract it so we do not duplicate.

**Files:**
- Modify: `classes/program/program_path.php`
- Test: `tests/program_path_test.php` (must stay green)

- [ ] **Step 1: Add a `resolve_program(int $userid, int $courseid): ?array` public method to `program_path`** returning `{programid, name, courses:array, visible:array, index:int, total:int}` (the program the course belongs to via the learner's allocation, with the visible course list and 1-based position), or `null`. Move the body of `forward_context` that walks `get_user_programs` → `get_program_courses` → finds index into this method; have `forward_context` call it. Keep `compute_next_courses` / `compute_concept_links` as-is.

- [ ] **Step 2: Run the existing suite to verify no regression**

Run: `/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --filter program_path_test local/ai_course_assistant/tests/program_path_test.php`
Expected: PASS (16/16) — the refactor is behavior-preserving.

- [ ] **Step 3: Commit**

```bash
git add local/ai_course_assistant/classes/program/program_path.php
git commit -m "refactor(program_path): extract resolve_program for reuse"
```

---

## Task 2: `learning_path` aggregator — `readiness()`

**Files:**
- Create: `classes/program/learning_path.php`
- Create: `tests/learning_path_test.php`

`readiness` is the cheap, current-course-only computation used by the banner and the prompt line.

- [ ] **Step 1: Write the failing test**

```php
// tests/learning_path_test.php
namespace local_ai_course_assistant;
use local_ai_course_assistant\program\learning_path;
use local_ai_course_assistant\program\stub_program_source;

final class learning_path_test extends \advanced_testcase {
    /** Build a stub program: 3 ordered courses, learner on course 2 (cid 202). */
    private function stub(): stub_program_source {
        $it = fn($itemid,$cid,$name,$ord) => ['itemid'=>$itemid,'courseid'=>$cid,'coursename'=>$name,'ordered'=>$ord,'visible'=>1];
        return new stub_program_source([
            'user_programs' => [100 => [['programid'=>1,'name'=>'BA Business']]],
            'program_courses' => [1 => [$it(11,201,'Intro',1),$it(12,202,'Comms',1),$it(13,203,'Org Behavior',1)]],
        ]);
    }

    public function test_readiness_false_when_flag_off(): void {
        $this->resetAfterTest();
        set_config('learning_path_enabled','0','local_ai_course_assistant');
        $lp = new learning_path($this->stub());
        $r = $lp->readiness(100, 202);
        $this->assertFalse($r['ready']);
        $this->assertNull($r['reason']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --filter learning_path_test local/ai_course_assistant/tests/learning_path_test.php`
Expected: FAIL ("Class learning_path not found").

- [ ] **Step 3: Implement `learning_path` with `is_enabled_for_course` + `readiness`**

```php
// classes/program/learning_path.php
namespace local_ai_course_assistant\program;
use local_ai_course_assistant\feature_flags;
use local_ai_course_assistant\objective_manager;
defined('MOODLE_INTERNAL') || die();

class learning_path {
    /** Mastery-readiness default, percent. */
    const DEFAULT_MASTERY_THRESHOLD = 80;
    private $source;
    public function __construct(?program_source_interface $source = null) {
        $this->source = $source ?? new db_program_source();
    }
    public function is_enabled_for_course(int $courseid): bool {
        return feature_flags::resolve('learning_path', $courseid) && $this->source->is_available();
    }
    /** @return array{ready:bool, reason:?string, next_course:?array} */
    public function readiness(int $userid, int $courseid): array {
        $none = ['ready'=>false,'reason'=>null,'next_course'=>null];
        try {
            if (!$this->is_enabled_for_course($courseid)) { return $none; }
            $pp = new program_path($this->source);
            $ctx = $pp->forward_context($userid, $courseid);
            $next = $ctx['next_courses'][0] ?? null;
            if ($this->course_complete($userid, $courseid)) {
                return ['ready'=>true,'reason'=>'completion','next_course'=>$next];
            }
            if ($this->mastery_ready($userid, $courseid)) {
                return ['ready'=>true,'reason'=>'mastery','next_course'=>$next];
            }
            return $none;
        } catch (\Throwable $e) { return $none; }
    }
    private function mastery_ready(int $userid, int $courseid): bool {
        if (!objective_manager::is_enabled_for_course($courseid)) { return false; }
        $sum = objective_manager::compute_course_summary($userid, $courseid);
        if (($sum['total'] ?? 0) < 1) { return false; }
        $raw = get_config('local_ai_course_assistant', 'learning_path_mastery_threshold');
        $thresh = ($raw === false || $raw === '') ? self::DEFAULT_MASTERY_THRESHOLD : (int)$raw;
        return ($sum['mastered'] / $sum['total']) * 100 >= $thresh;
    }
    private function course_complete(int $userid, int $courseid): bool {
        $course = get_course($courseid);
        $info = new \completion_info($course);
        if (!$info->is_enabled()) { return false; }
        return $info->is_course_complete($userid);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --filter learning_path_test local/ai_course_assistant/tests/learning_path_test.php`
Expected: PASS.

- [ ] **Step 5: Add readiness tests for completion + mastery threshold boundary**

Add to the test class: enable the flag + objectives, seed `record_attempt` so `compute_course_summary` yields 4/5 mastered (80%) → `ready, reason=mastery`; 3/5 (60%) → not ready. Use `objective_manager::create` + `record_attempt` against a real course (`getDataGenerator()->create_course()`), set `set_config('learning_path_enabled','1',...)` and the course objectives flag. (Completion-path test can assert the mastery path independently; full completion plumbing is covered by Behat in Task 11.)

- [ ] **Step 6: Run + commit**

Run the filter again (Expected: PASS), then:
```bash
git add local/ai_course_assistant/classes/program/learning_path.php local/ai_course_assistant/tests/learning_path_test.php
git commit -m "feat(learning_path): readiness (completion or mastery threshold)"
```

---

## Task 3: `learning_path` aggregator — `full_path()`

**Files:**
- Modify: `classes/program/learning_path.php`
- Modify: `tests/learning_path_test.php`

- [ ] **Step 1: Write the failing test** — assert `full_path(100,202)` on the stub returns `program.name === 'BA Business'`, `position` `{index:2,total:3}`, three `courses` with statuses `['upcoming','current','upcoming']` for the not-completed stub (course 202 is `current`), and `courses[1].is_current === true`.

- [ ] **Step 2: Run to verify it fails** (`full_path` undefined).

- [ ] **Step 3: Implement `full_path`**

```php
/** @return array|null Full path model, or null when no usable program. */
public function full_path(int $userid, int $courseid): ?array {
    try {
        if (!$this->is_enabled_for_course($courseid)) { return null; }
        $pp = new program_path($this->source);
        $res = $pp->resolve_program($userid, $courseid);   // Task 1
        if ($res === null) { return null; }
        $courses = [];
        foreach ($res['visible'] as $i => $c) {
            $cid = (int)$c['courseid'];
            $status = $this->status_for($userid, (int)$res['programid'], $c, $cid === $courseid);
            $courses[] = [
                'courseid' => $cid,
                'name' => (string)$c['coursename'],
                'position' => $i + 1,
                'status' => $status,
                'ordered' => !empty($c['ordered']),
                'is_current' => $cid === $courseid,
                'objectives' => $this->objectives_for($userid, $cid),
            ];
        }
        $ctx = $pp->forward_context($userid, $courseid);
        return [
            'program' => ['programid'=>(int)$res['programid'], 'name'=>(string)$res['name']],
            'position' => ['index'=>(int)$res['index'], 'total'=>(int)$res['total']],
            'courses' => $courses,
            'next_courses' => $ctx['next_courses'] ?? [],
            'readiness' => $this->readiness($userid, $courseid),
        ];
    } catch (\Throwable $e) { return null; }
}
private function status_for(int $userid, int $programid, array $c, bool $iscurrent): string {
    if ($iscurrent) { return 'current'; }
    if (!empty($c['itemid']) && $this->source->is_item_completed($userid, $programid, (int)$c['itemid'])) {
        return 'done';
    }
    return 'upcoming';
}
private function objectives_for(int $userid, int $courseid): array {
    if (!objective_manager::is_enabled_for_course($courseid)) { return []; }
    $sum = objective_manager::compute_course_summary($userid, $courseid);
    $out = [];
    foreach ($sum['objectives'] as $row) {
        $status = $row['mastery']['status']; // not_started|learning|mastered
        $map = ['learning'=>'in_progress'];
        $out[] = ['title'=>(string)$row['objective']->title, 'mastery'=>$map[$status] ?? $status];
    }
    return $out;
}
```

- [ ] **Step 4: Run to verify it passes.**

- [ ] **Step 5: Add a `demonstrated_elsewhere` test + implementation** — in `objectives_for`, before finalizing each `not_started` objective, if `cross_course_mastery::linked_objectives($obj->id)` has a link whose objective the learner has mastered elsewhere, set mastery to `demonstrated_elsewhere`. Test with two stub courses sharing an `obj_links` row (seed via `cross_course_mastery::rebuild_links` or insert the link row directly).

- [ ] **Step 6: Run + commit**
```bash
git add local/ai_course_assistant/classes/program/learning_path.php local/ai_course_assistant/tests/learning_path_test.php
git commit -m "feat(learning_path): full_path model with per-course objectives + mastery"
```

---

## Task 4: `get_learning_path` web service

**Files:**
- Create: `classes/external/get_learning_path.php`
- Modify: `db/services.php`
- Modify: `tests/learning_path_test.php` (external-function test)

- [ ] **Step 1: Write the failing external test** — `get_learning_path::execute($courseid)` for an enrolled student on the stub program returns a structure with `has_path === true` and a non-empty `courses` array; for a course with no program returns `has_path === false`, `courses === []`. Use `$this->setUser($student)` and `webservice` context validation.

- [ ] **Step 2: Run to verify it fails.**

- [ ] **Step 3: Implement the external function** (mirror `classes/external/save_avatar_preference.php`):

```php
namespace local_ai_course_assistant\external;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use local_ai_course_assistant\program\learning_path;
defined('MOODLE_INTERNAL') || die();

class get_learning_path extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(['courseid' => new external_value(PARAM_INT, 'Course id')]);
    }
    public static function execute(int $courseid): array {
        global $USER;
        $p = self::validate_parameters(self::execute_parameters(), ['courseid'=>$courseid]);
        $context = \context_course::instance($p['courseid']);
        self::validate_context($context);
        require_capability('local/ai_course_assistant:use', $context);
        $model = (new learning_path())->full_path((int)$USER->id, (int)$p['courseid']);
        if ($model === null) {
            return ['has_path'=>false,'program_name'=>'','index'=>0,'total'=>0,'courses'=>[]];
        }
        $courses = array_map(fn($c) => [
            'courseid'=>$c['courseid'],'name'=>$c['name'],'position'=>$c['position'],
            'status'=>$c['status'],'ordered'=>$c['ordered'],'is_current'=>$c['is_current'],
            'objectives'=>array_map(fn($o)=>['title'=>$o['title'],'mastery'=>$o['mastery']], $c['objectives']),
        ], $model['courses']);
        return ['has_path'=>true,'program_name'=>$model['program']['name'],
                'index'=>$model['position']['index'],'total'=>$model['position']['total'],'courses'=>$courses];
    }
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'has_path'=>new external_value(PARAM_BOOL,'Whether a program path applies'),
            'program_name'=>new external_value(PARAM_TEXT,'Program name'),
            'index'=>new external_value(PARAM_INT,'1-based position of current course'),
            'total'=>new external_value(PARAM_INT,'Total visible courses'),
            'courses'=>new external_multiple_structure(new external_single_structure([
                'courseid'=>new external_value(PARAM_INT,'Course id'),
                'name'=>new external_value(PARAM_TEXT,'Course name'),
                'position'=>new external_value(PARAM_INT,'1-based position'),
                'status'=>new external_value(PARAM_ALPHA,'done|current|upcoming'),
                'ordered'=>new external_value(PARAM_BOOL,'In an ordered sequence'),
                'is_current'=>new external_value(PARAM_BOOL,'Is the current course'),
                'objectives'=>new external_multiple_structure(new external_single_structure([
                    'title'=>new external_value(PARAM_TEXT,'Objective title'),
                    'mastery'=>new external_value(PARAM_ALPHAEXT,'mastered|in_progress|not_started|demonstrated_elsewhere'),
                ])),
            ])),
        ]);
    }
}
```

- [ ] **Step 4: Register in `db/services.php`** — add after an existing read entry:
```php
    'local_ai_course_assistant_get_learning_path' => [
        'classname' => \local_ai_course_assistant\external\get_learning_path::class,
        'description' => 'Get the learner\'s program learning path for a course.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/ai_course_assistant:use',
    ],
```
Then bump `version.php` is NOT needed yet (done in Task 11), but run the upgrade so the WS registers locally: deploy + `php admin/cli/upgrade.php` (Task 11 covers the version bump; for local testing during dev, `purge_caches.php` is enough since services.php is re-read on cache purge in dev).

- [ ] **Step 5: Run external test to verify it passes.**

- [ ] **Step 6: Commit**
```bash
git add local/ai_course_assistant/classes/external/get_learning_path.php local/ai_course_assistant/db/services.php local/ai_course_assistant/tests/learning_path_test.php
git commit -m "feat(learning_path): get_learning_path web service"
```

---

## Task 5: Settings + English strings

**Files:**
- Modify: `settings.php`
- Modify: `lang/en/local_ai_course_assistant.php`

- [ ] **Step 1: Add the pedagogy default + threshold** to `settings.php` near the other `pedagogy:*` flags: a `learning_path` checkbox (default 0) registered in the same `$pedagogydefaults` map the existing flags use (see `crossmastery_enabled` => `pedagogy:crossmastery`), and an `admin_setting_configtext` `learning_path_mastery_threshold` default `80`.

- [ ] **Step 2: Add English strings** to `lang/en/local_ai_course_assistant.php`: `pedagogy:learning_path`, `pedagogy:learning_path_desc`, `learning_path_mastery_threshold`, `learning_path_mastery_threshold_desc`, `pathpanel_title`, `pathpanel_open`, `path_status_done`, `path_status_current`, `path_status_upcoming`, `path_mastery_mastered`, `path_mastery_in_progress`, `path_mastery_not_started`, `path_mastery_demonstrated_elsewhere`, `path_position` (`{$a->index} of {$a->total}`), `nudge_ready_title`, `nudge_ready_body` (`You're ready for {$a}`), `nudge_view_path`, `nudge_dismiss`.

- [ ] **Step 3: PHP lint + commit**
```bash
/opt/homebrew/opt/php@8.3/bin/php -l local/ai_course_assistant/settings.php
/opt/homebrew/opt/php@8.3/bin/php -l local/ai_course_assistant/lang/en/local_ai_course_assistant.php
git add local/ai_course_assistant/settings.php local/ai_course_assistant/lang/en/local_ai_course_assistant.php
git commit -m "feat(learning_path): settings flag + threshold + en strings"
```

---

## Task 6: Conversational readiness line in `context_builder`

**Files:**
- Modify: `classes/context_builder.php` (the `program_path` block, ~line 314-321)

- [ ] **Step 1: Extend the program_path section** — after the existing `program_path` block is appended, compute `readiness = (new learning_path())->readiness($userid, $courseid)` and, when `ready`, append one advisory sentence to that section's text: when `reason==='mastery'` / `completion` and a `next_course` exists, e.g. "The learner has met the bar for this course (X); when it fits, affirm that and point them toward \"{next}\" as the natural next step, without pressure." Guard with `\Throwable` (degrade to the block unchanged). Reuse the existing section; do not add a new one (keeps budget priority).

- [ ] **Step 2: Add a unit test** in `tests/learning_path_test.php` or `context_builder_test.php`: with the flag on and the learner ready, assert the assembled prompt contains the next-course name; with not-ready, assert it does not. (Use the existing context_builder test harness pattern if present; otherwise assert via `readiness()` + a focused string build.)

- [ ] **Step 3: Run relevant tests + commit**
```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --filter learning_path local/ai_course_assistant/tests/learning_path_test.php
git add local/ai_course_assistant/classes/context_builder.php local/ai_course_assistant/tests/learning_path_test.php
git commit -m "feat(learning_path): conversational readiness line in program_path block"
```

---

## Task 7: Banner + header button (server side)

**Files:**
- Modify: `classes/hook_callbacks.php`
- Modify: `templates/chat_widget.mustache`

- [ ] **Step 1: Template data** — in `hook_callbacks` where the widget template context is built, add `pathenabled` = `(new learning_path())->is_enabled_for_course($courseid)`, and a `nudge` block: when `pathenabled`, compute `readiness($userid,$courseid)`; if `ready && next_course`, set `nudge_ready=true`, `nudge_next_name`, `nudge_next_courseid`. All `\Throwable`-guarded (no keys on error). Follow the existing `data-firstname` passing pattern.

- [ ] **Step 2: Header button** — in `templates/chat_widget.mustache` `.local-ai-course-assistant__header-actions`, add a `.btn-path` button wrapped in `{{#pathenabled}}...{{/pathenabled}}` with the "my path" icon and `aria-label="{{#str}}pathpanel_open, local_ai_course_assistant{{/str}}"`, placed left of `.btn-settings-panel`.

- [ ] **Step 3: Banner markup** — add a hidden banner element near the top of the drawer body, rendered only `{{#nudge_ready}}`, with the ready text (`nudge_ready_body` with `{$a}`=`nudge_next_name`), a "View my path" CTA (`.btn-nudge-path`) and a dismiss (`.btn-nudge-dismiss`), `role="status"`. Carry `data-next-courseid="{{nudge_next_courseid}}"`.

- [ ] **Step 4: Lint mustache via the local build check (no JS yet)** — deploy + load BUS101: the header button + (when seeded ready) banner appear. Commit:
```bash
git add local/ai_course_assistant/classes/hook_callbacks.php local/ai_course_assistant/templates/chat_widget.mustache
git commit -m "feat(learning_path): header button + readiness banner template data"
```

---

## Task 8: Panel + nudge JS + styles

**Files:**
- Modify: `amd/src/repository.js`
- Create: `amd/src/path_map.js`
- Modify: `amd/src/chat.js`, `amd/src/ui.js`
- Modify: `styles.css`

- [ ] **Step 1: repository.js** — add `getLearningPath = (courseid) => Ajax.call([{methodname:'local_ai_course_assistant_get_learning_path', args:{courseid}}])[0];` and export it (mirror `saveAvatarPreference`).

- [ ] **Step 2: path_map.js** — module that renders the panel: `open(courseid)` fetches via `Repository.getLearningPath`, builds the node list (status icon+label, "course X of Y", connectors for `ordered`), each node a `<button aria-expanded>` toggling an objectives sublist (title + mastery label). `close()`, `toggle()`. No color-only cues (icon/shape + text).

- [ ] **Step 3: Wire in chat.js/ui.js** — `bindEvents` null-guards `.btn-path` → `PathMap.toggle()`; show the banner on open when present and not dismissed (`localStorage aica_path_nudge_<courseid>`); `.btn-nudge-path` → `PathMap.open()`; `.btn-nudge-dismiss` → hide + set localStorage. Follow the existing settings-panel toggle + null-guard pattern.

- [ ] **Step 4: styles.css** — panel container, node rows, status styles (shape/icon, not color alone), connectors, banner. Scope under `.local-ai-course-assistant`.

- [ ] **Step 5: Build AMD** (terser) for `repository chat ui path_map`:
```bash
BASE="$HOME/Library/CloudStorage/Dropbox/!Saylor/aicoursetutor/ai_course_assistant"
# NOTE: source of truth is the git repo; build against the repo path then rsync.
for f in repository chat ui path_map; do terser "$f source" ...; done   # see CLAUDE.md build block
```
Deploy to local + purge; manually open the panel and expand a node.

- [ ] **Step 6: Commit**
```bash
git add local/ai_course_assistant/amd/src/repository.js local/ai_course_assistant/amd/src/path_map.js local/ai_course_assistant/amd/src/chat.js local/ai_course_assistant/amd/src/ui.js local/ai_course_assistant/amd/build/ local/ai_course_assistant/styles.css
git commit -m "feat(learning_path): path panel + nudge banner JS + styles"
```

---

## Task 9: Accessibility

**Files:**
- Modify: `tests/a11y/axe-run.js`

- [ ] **Step 1: Add panel-open + banner states** to the audited set: a variant that opens the path panel before auditing (and a course seeded ready so the banner renders). At minimum add the course page with the widget; assert keyboard operability of toggle/expand/dismiss with correct ARIA.

- [ ] **Step 2: Run the harness** (server with `PHP_CLI_SERVER_WORKERS>=4`, plugin upgraded): `node tests/a11y/axe-run.js` → Expected: 0 violations. Fix any contrast/role issues in `styles.css`/mustache.

- [ ] **Step 3: Commit**
```bash
git add local/ai_course_assistant/tests/a11y/axe-run.js local/ai_course_assistant/styles.css
git commit -m "test(a11y): cover learning-path panel + banner"
```

---

## Task 10: i18n sync (45 languages)

- [ ] **Step 1:** Run the project i18n sync to propagate the new English keys into the 45 non-English lang files (same tool used in prior releases; see CLAUDE.md i18n section / `scripts`).
- [ ] **Step 2:** Lint all lang files (Moodle string lint) — 0 warnings.
- [ ] **Step 3: Commit**
```bash
git add local/ai_course_assistant/lang/
git commit -m "i18n: learning-path strings (45 languages)"
```

---

## Task 11: Build, version, changelog, release notes

- [ ] **Step 1:** `python3 scripts/new_release_notes.py --version 5.9.0` and fill Part 1 (headline + bullets); carry Parts 2-3 forward from the latest draft.
- [ ] **Step 2:** Bump `version.php` → `$plugin->version = 2026060500;` `$plugin->release = '5.9.0';`.
- [ ] **Step 3:** Add `.wiki/Changelog.md` v5.9.0 entry.
- [ ] **Step 4:** Rebuild any remaining AMD modules; run validators (`php admin/cli/run_validators.php` → 0 failures), jailbreak (32/32), full PHPUnit suite, i18n lint.
- [ ] **Step 5: Commit (plugin + wiki separately)**
```bash
git add local/ai_course_assistant/version.php local/ai_course_assistant/amd/build/
git commit -m "v5.9.0: learning path map + next-course nudge"
# wiki repo:
( cd local/ai_course_assistant/.wiki && git add Changelog.md && git commit -m "Changelog: v5.9.0" && git push )
```

---

## Task 12: Dev deploy + smoke

- [ ] **Step 1:** `python3 deploy_dev.py --target all` (background; rely on completion notification, do not tail).
- [ ] **Step 2:** On dev.sylr.org enable `learning_path` for the `solapilot` program courses (19/20/21), then as learner 45 (or via the read-only render approach used in `.drafts/pull-pilot-program-blocks.py`) confirm: header button shows, panel renders 3 nodes with course 2→3 ordering, readiness banner appears when the learner is complete/≥80% on a course. BUS101 smoke on all 5 sites.
- [ ] **Step 3:** Open PR `feat/learning-path-map-and-nudge` → main; CI green; squash-merge.

---

## Self-review notes

- Spec §3 readiness → Task 2. §4 model → Tasks 2-3. §5 components → Tasks 4-8. §6 data flow → Tasks 6-8. §7 guardrails → `\Throwable` guards in Tasks 2-4,6-7 + capability check Task 4. §8 a11y → Task 9. §9 testing → Tasks 2-4,6,9,10. §10 build/release → Tasks 11-12. §11 out-of-scope respected (no digest, no starter chip). §12 files all assigned.
- Type consistency: `readiness` shape `{ready,reason,next_course}` used identically in Tasks 2,3,6,7; `full_path`/WS `courses[]` fields match Task 3 ↔ Task 4 ↔ Task 8; mastery vocabulary `mastered|in_progress|not_started|demonstrated_elsewhere` consistent across Tasks 3,4,8, strings (Task 5).
