# Forward Learning-Path Awareness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give SOLA conversational *forward* awareness — when a course belongs to a Saylor program (Degrees or Learn cert), SOLA can mention where it leads next and how concepts bridge forward.

**Architecture:** A `program_source_interface` isolates the third-party Programs plugin (prod `enrol_programs_*`, dev `tool_muprog_*`) behind plain arrays. `db_program_source` is the live dual-prefix adapter; `stub_program_source` backs unit tests. `program_path` is pure logic over the interface; it produces an advisory prompt block appended in `context_builder`. Default-off `program_path` pedagogy flag. No new table, no task.

**Tech Stack:** PHP 8.3, Moodle 4.5, PHPUnit, `local_ai_course_assistant` plugin conventions.

Spec: `docs/superpowers/specs/2026-06-02-forward-learning-path-awareness-design.md`.

---

## File Structure

**New:**
- `classes/program/program_source_interface.php` — interface (what the feature needs from a Programs plugin).
- `classes/program/stub_program_source.php` — array-backed test double.
- `classes/program/db_program_source.php` — live dual-prefix adapter (verified on dev, not in CI).
- `classes/program_path.php` — pure logic + prompt block.
- `tests/program_path_test.php` — unit tests over the stub.

**Modified:**
- `classes/context_builder.php` — append the program-path block after the mastery block (~line 313).
- `settings.php` — register `program_path_enabled` pedagogy default (~line 1380).
- `lang/en/local_ai_course_assistant.php` — `pedagogy:program_path` (+ `_desc`).
- `version.php` — bump to `2026060200` / `5.8.0`.
- `.wiki/Changelog.md` — v5.8.0 entry.

Namespacing: `classes/program/foo.php` → `local_ai_course_assistant\program\foo`.

---

## Task 1: program_source_interface + stub

**Files:**
- Create: `classes/program/program_source_interface.php`
- Create: `classes/program/stub_program_source.php`

- [ ] **Step 1: Write the interface**

```php
<?php
namespace local_ai_course_assistant\program;
defined('MOODLE_INTERNAL') || die();

/**
 * What forward learning-path awareness needs from a Programs plugin.
 * Implementations: db_program_source (live), stub_program_source (tests).
 */
interface program_source_interface {
    /** Program tables present on this install? */
    public function is_available(): bool;

    /** Non-archived programs the user is allocated to: [['programid'=>int,'name'=>string], ...]. */
    public function get_user_programs(int $userid): array;

    /** Non-archived programs containing the course (allocation-agnostic fallback). */
    public function get_programs_for_course(int $courseid): array;

    /**
     * Course-bearing items of a program in resolved order:
     * [['itemid'=>int,'courseid'=>int,'coursename'=>string,'visible'=>bool,'ordered'=>bool,'position'=>int], ...].
     */
    public function get_program_courses(int $programid): array;

    /** Prerequisite edges: itemid => [prerequisiteitemid, ...]. */
    public function get_prerequisites(int $programid): array;

    /** Has the user completed this program item? */
    public function is_item_completed(int $userid, int $programid, int $itemid): bool;
}
```

- [ ] **Step 2: Write the stub** (array-backed; fixtures shaped exactly like the interface returns)

```php
<?php
namespace local_ai_course_assistant\program;
defined('MOODLE_INTERNAL') || die();

/** In-memory program source for unit tests. */
class stub_program_source implements program_source_interface {
    /** @var bool */
    private $available;
    /** @var array userid => [['programid'=>,'name'=>], ...] */
    private $userprograms;
    /** @var array courseid => [['programid'=>,'name'=>], ...] */
    private $courseprograms;
    /** @var array programid => [course rows] */
    private $programcourses;
    /** @var array programid => (itemid => [prereqitemid,...]) */
    private $prereqs;
    /** @var array "userid:programid:itemid" => bool */
    private $completed;

    public function __construct(array $cfg = []) {
        $this->available     = $cfg['available']      ?? true;
        $this->userprograms  = $cfg['user_programs']  ?? [];
        $this->courseprograms = $cfg['course_programs'] ?? [];
        $this->programcourses = $cfg['program_courses'] ?? [];
        $this->prereqs       = $cfg['prerequisites']  ?? [];
        $this->completed     = $cfg['completed']      ?? [];
    }
    public function is_available(): bool { return $this->available; }
    public function get_user_programs(int $userid): array { return $this->userprograms[$userid] ?? []; }
    public function get_programs_for_course(int $courseid): array { return $this->courseprograms[$courseid] ?? []; }
    public function get_program_courses(int $programid): array { return $this->programcourses[$programid] ?? []; }
    public function get_prerequisites(int $programid): array { return $this->prereqs[$programid] ?? []; }
    public function is_item_completed(int $userid, int $programid, int $itemid): bool {
        return !empty($this->completed["$userid:$programid:$itemid"]);
    }
}
```

- [ ] **Step 3: Lint both**

Run: `/opt/homebrew/opt/php@8.3/bin/php -l classes/program/program_source_interface.php && /opt/homebrew/opt/php@8.3/bin/php -l classes/program/stub_program_source.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add classes/program/program_source_interface.php classes/program/stub_program_source.php
git commit -m "feat: program_source_interface + stub for forward learning-path"
```

---

## Task 2: program_path gating (is_enabled_for_course)

**Files:**
- Create: `classes/program_path.php`
- Test: `tests/program_path_test.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace local_ai_course_assistant;
use local_ai_course_assistant\program\program_path;
use local_ai_course_assistant\program\stub_program_source;

final class program_path_test extends \advanced_testcase {

    /** Build a program_path over a stub source. */
    private function path(array $cfg): program_path {
        return new program_path(new stub_program_source($cfg));
    }

    public function test_disabled_when_source_unavailable(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $p = $this->path(['available' => false]);
        $this->assertFalse($p->is_enabled_for_course((int)$course->id));
    }

    public function test_disabled_when_flag_off(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        // flag not set => default off
        $p = $this->path(['available' => true]);
        $this->assertFalse($p->is_enabled_for_course((int)$course->id));
    }

    public function test_enabled_when_flag_on_and_available(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $p = $this->path(['available' => true]);
        $this->assertTrue($p->is_enabled_for_course((int)$course->id));
    }
}
```

NOTE: `program_path` lives at `classes/program_path.php` → namespace `local_ai_course_assistant\program_path`? No — keep it under `program/` for cohesion. Use `classes/program/program_path.php` → `local_ai_course_assistant\program\program_path`. Update the `use` above accordingly (already `program\program_path`). Move the Create path to `classes/program/program_path.php`.

- [ ] **Step 2: Run, verify fail**

Run: `cd ~/Sites/moodle && export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH" && php vendor/bin/phpunit local/ai_course_assistant/tests/program_path_test.php`
Expected: FAIL (class `program_path` not found).

- [ ] **Step 3: Implement skeleton + gating**

```php
<?php
namespace local_ai_course_assistant\program;
use local_ai_course_assistant\feature_flags;
defined('MOODLE_INTERNAL') || die();

/** Forward learning-path awareness: pure logic over a program_source_interface. */
class program_path {
    /** @var program_source_interface */
    private $source;

    public function __construct(?program_source_interface $source = null) {
        $this->source = $source ?? new db_program_source();
    }

    /** Feature flag on for this course AND program tables available. */
    public function is_enabled_for_course(int $courseid): bool {
        if (!feature_flags::resolve('program_path', $courseid)) {
            return false;
        }
        return $this->source->is_available();
    }
}
```

- [ ] **Step 4: Run, verify pass** (the 3 gating tests).

Run: same phpunit command.
Expected: PASS (3 tests). (`db_program_source` referenced as default only when no source injected; tests always inject, so it need not exist yet — but to avoid autoload error, create an empty placeholder in Task 7 BEFORE running the full suite. For now the 3 tests inject a stub, so the default branch is never hit.)

- [ ] **Step 5: Commit**

```bash
git add classes/program/program_path.php tests/program_path_test.php
git commit -m "feat: program_path gating (flag + availability)"
```

---

## Task 3: forward_context — program selection + position

**Files:**
- Modify: `classes/program/program_path.php`
- Test: `tests/program_path_test.php`

- [ ] **Step 1: Add failing tests**

```php
    /** Helper: a 3-course any-order program; learner allocated; on course 2. */
    private function threecourse_cfg(int $userid, array $courseids): array {
        [$c1, $c2, $c3] = $courseids;
        return [
            'available' => true,
            'user_programs' => [$userid => [['programid'=>10, 'name'=>'Business Path']]],
            'program_courses' => [10 => [
                ['itemid'=>101,'courseid'=>$c1,'coursename'=>'Intro','visible'=>true,'ordered'=>false,'position'=>1],
                ['itemid'=>102,'courseid'=>$c2,'coursename'=>'Mid','visible'=>true,'ordered'=>false,'position'=>2],
                ['itemid'=>103,'courseid'=>$c3,'coursename'=>'Adv','visible'=>true,'ordered'=>false,'position'=>3],
            ]],
        ];
    }

    public function test_position_index_and_total(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = $this->threecourse_cfg(7, [201, 202, 203]);
        $ctx = $this->path($cfg)->forward_context(7, 202);
        $this->assertNotNull($ctx);
        $this->assertSame('Business Path', $ctx['program']['name']);
        $this->assertSame(2, $ctx['position']['index']);
        $this->assertSame(3, $ctx['position']['total']);
    }

    public function test_hidden_course_not_counted_in_total(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = $this->threecourse_cfg(7, [201, 202, 203]);
        $cfg['program_courses'][10][2]['visible'] = false; // hide Adv
        $ctx = $this->path($cfg)->forward_context(7, 202);
        $this->assertSame(2, $ctx['position']['total']); // only 2 visible
    }

    public function test_no_allocation_returns_null_by_default(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        // course is in a program, but learner has no allocation; fallback off by default.
        $cfg = [
            'available' => true,
            'course_programs' => [202 => [['programid'=>10,'name'=>'Business Path']]],
            'program_courses' => [10 => [
                ['itemid'=>102,'courseid'=>202,'coursename'=>'Mid','visible'=>true,'ordered'=>false,'position'=>1],
            ]],
        ];
        $this->assertNull($this->path($cfg)->forward_context(7, 202));
    }

    public function test_single_course_program_returns_null(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = [
            'available' => true,
            'user_programs' => [7 => [['programid'=>10,'name'=>'Solo']]],
            'program_courses' => [10 => [
                ['itemid'=>102,'courseid'=>202,'coursename'=>'Only','visible'=>true,'ordered'=>false,'position'=>1],
            ]],
        ];
        // total < 2 and no next course => skip => null.
        $this->assertNull($this->path($cfg)->forward_context(7, 202));
    }
```

- [ ] **Step 2: Run, verify fail** (forward_context missing).

- [ ] **Step 3: Implement forward_context (selection + position; next/concept added in Tasks 4–5)**

```php
    /**
     * @return null|array{program:array,position:array,next_courses:array,concept_links:array}
     */
    public function forward_context(int $userid, int $courseid): ?array {
        if (!$this->is_enabled_for_course($courseid)) {
            return null;
        }
        $candidates = $this->source->get_user_programs($userid);
        // Course-membership fallback is OFF by default (honesty rule).
        foreach ($candidates as $prog) {
            $programid = (int)$prog['programid'];
            $courses = $this->source->get_program_courses($programid);
            $visible = array_values(array_filter($courses, static fn($c) => !empty($c['visible'])));
            // Locate current course among visible items.
            $index = null;
            foreach ($visible as $i => $c) {
                if ((int)$c['courseid'] === $courseid) { $index = $i + 1; break; }
            }
            if ($index === null) { continue; }
            $total = count($visible);
            $next = $this->compute_next_courses($userid, $programid, $courseid, $courses);
            if ($total < 2 && empty($next)) { continue; }
            return [
                'program' => ['programid' => $programid, 'name' => (string)$prog['name']],
                'position' => ['index' => $index, 'total' => $total],
                'next_courses' => $next,
                'concept_links' => $this->compute_concept_links($courseid, $next),
            ];
        }
        return null;
    }

    /** Placeholder filled in Task 4. */
    private function compute_next_courses(int $userid, int $programid, int $courseid, array $courses): array {
        return [];
    }

    /** Placeholder filled in Task 5. */
    private function compute_concept_links(int $courseid, array $next): array {
        return [];
    }
```

- [ ] **Step 4: Run, verify pass** (position + null cases).

- [ ] **Step 5: Commit**

```bash
git add classes/program/program_path.php tests/program_path_test.php
git commit -m "feat: program_path forward_context program selection + position"
```

---

## Task 4: forward_context — next_courses (prerequisite > sequence)

**Files:**
- Modify: `classes/program/program_path.php`
- Test: `tests/program_path_test.php`

- [ ] **Step 1: Add failing tests**

```php
    public function test_next_course_via_prerequisite(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = $this->threecourse_cfg(7, [201, 202, 203]);
        // Item 103 (course 203) lists item 102 (current course 202) as a prerequisite.
        $cfg['prerequisites'] = [10 => [103 => [102]]];
        $ctx = $this->path($cfg)->forward_context(7, 202);
        $this->assertCount(1, $ctx['next_courses']);
        $this->assertSame(203, $ctx['next_courses'][0]['courseid']);
        $this->assertSame('prerequisite', $ctx['next_courses'][0]['reason']);
    }

    public function test_next_course_via_ordered_sequence(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = $this->threecourse_cfg(7, [201, 202, 203]);
        foreach ($cfg['program_courses'][10] as &$c) { $c['ordered'] = true; }
        unset($c);
        $ctx = $this->path($cfg)->forward_context(7, 202);
        $this->assertCount(1, $ctx['next_courses']);
        $this->assertSame(203, $ctx['next_courses'][0]['courseid']); // next visible after position 2
        $this->assertSame('sequence', $ctx['next_courses'][0]['reason']);
    }

    public function test_anyorder_no_prereq_yields_no_next(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = $this->threecourse_cfg(7, [201, 202, 203]); // ordered=false, no prereqs
        $ctx = $this->path($cfg)->forward_context(7, 202);
        $this->assertSame([], $ctx['next_courses']);
    }

    public function test_completed_next_course_filtered_out(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = $this->threecourse_cfg(7, [201, 202, 203]);
        $cfg['prerequisites'] = [10 => [103 => [102]]];
        $cfg['completed'] = ['7:10:103' => true]; // learner already did course 203's item
        $ctx = $this->path($cfg)->forward_context(7, 202);
        $this->assertSame([], $ctx['next_courses']);
    }
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Implement compute_next_courses**

```php
    private function compute_next_courses(int $userid, int $programid, int $courseid, array $courses): array {
        // Map courseid -> item row (visible only).
        $byid = [];
        $myitemid = null;
        foreach ($courses as $c) {
            if (empty($c['visible'])) { continue; }
            $byid[(int)$c['itemid']] = $c;
            if ((int)$c['courseid'] === $courseid) { $myitemid = (int)$c['itemid']; }
        }
        if ($myitemid === null) { return []; }
        $out = [];

        // 1. Prerequisite edges: items that list my item as a prerequisite.
        $prereqs = $this->source->get_prerequisites($programid);
        foreach ($prereqs as $itemid => $deps) {
            if (in_array($myitemid, array_map('intval', $deps), true) && isset($byid[(int)$itemid])) {
                $out[(int)$itemid] = 'prerequisite';
            }
        }

        // 2. Ordered sequence: next visible item after mine, only if my set is ordered.
        if (empty($out)) {
            $ordered = array_values(array_filter($courses, static fn($c) => !empty($c['visible'])));
            foreach ($ordered as $i => $c) {
                if ((int)$c['itemid'] === $myitemid && !empty($c['ordered']) && isset($ordered[$i + 1])) {
                    $nx = $ordered[$i + 1];
                    if (!empty($nx['ordered'])) { $out[(int)$nx['itemid']] = 'sequence'; }
                }
            }
        }

        // Build result rows; exclude current + completed; cap at 2.
        $result = [];
        foreach ($out as $itemid => $reason) {
            $row = $byid[(int)$itemid];
            if ((int)$row['courseid'] === $courseid) { continue; }
            if ($this->source->is_item_completed($userid, $programid, (int)$itemid)) { continue; }
            $result[] = ['courseid' => (int)$row['courseid'], 'name' => (string)$row['coursename'], 'reason' => $reason];
            if (count($result) >= 2) { break; }
        }
        return $result;
    }
```

- [ ] **Step 4: Run, verify pass.**

- [ ] **Step 5: Commit**

```bash
git add classes/program/program_path.php tests/program_path_test.php
git commit -m "feat: program_path next-course derivation (prereq > sequence, filtered)"
```

---

## Task 5: forward_context — concept_links (via obj_links equivalency)

**Files:**
- Modify: `classes/program/program_path.php`
- Test: `tests/program_path_test.php`

- [ ] **Step 1: Add failing test** (uses real objectives + obj_links; cross_course_mastery::rebuild_links wires equivalency)

```php
    public function test_concept_link_when_objective_equates_into_next_course(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cur = $this->getDataGenerator()->create_course();
        $nextc = $this->getDataGenerator()->create_course();
        // Same-titled objective in both => cross-course equivalency link.
        objective_manager::set_enabled_for_course((int)$cur->id, true);
        objective_manager::set_enabled_for_course((int)$nextc->id, true);
        objective_manager::create((int)$cur->id, 'Interpret a balance sheet');
        objective_manager::create((int)$nextc->id, 'Interpret a balance sheet');
        cross_course_mastery::rebuild_links();

        $cfg = [
            'available' => true,
            'user_programs' => [7 => [['programid'=>10,'name'=>'Acct Path']]],
            'program_courses' => [10 => [
                ['itemid'=>101,'courseid'=>(int)$cur->id,'coursename'=>'Acct I','visible'=>true,'ordered'=>false,'position'=>1],
                ['itemid'=>102,'courseid'=>(int)$nextc->id,'coursename'=>'Acct II','visible'=>true,'ordered'=>false,'position'=>2],
            ]],
            'prerequisites' => [10 => [102 => [101]]], // Acct II builds on Acct I
        ];
        $ctx = $this->path($cfg)->forward_context(7, (int)$cur->id);
        $this->assertCount(1, $ctx['next_courses']);
        $this->assertCount(1, $ctx['concept_links']);
        $this->assertSame('Interpret a balance sheet', $ctx['concept_links'][0]['objective']);
        $this->assertSame('Acct II', $ctx['concept_links'][0]['next_course']);
    }

    public function test_no_concept_link_when_no_equivalence(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = $this->threecourse_cfg(7, [201, 202, 203]);
        $cfg['prerequisites'] = [10 => [103 => [102]]];
        $ctx = $this->path($cfg)->forward_context(7, 202);
        $this->assertSame([], $ctx['concept_links']);
    }
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Implement compute_concept_links**

```php
    private function compute_concept_links(int $courseid, array $next): array {
        if (empty($next) || !objective_manager::is_enabled_for_course($courseid)) {
            return [];
        }
        $nextcourseids = [];
        foreach ($next as $n) { $nextcourseids[(int)$n['courseid']] = (string)$n['name']; }

        $links = [];
        foreach (objective_manager::list_for_course($courseid) as $obj) {
            foreach (cross_course_mastery::linked_objectives((int)$obj->id) as $lk) {
                $lcourseid = (int)$lk['courseid'];
                if (!isset($nextcourseids[$lcourseid])) { continue; }
                $target = objective_manager::get((int)$lk['objectiveid']);
                if (!$target) { continue; }
                $links[] = [
                    'objective' => (string)$obj->title,
                    'next_course' => $nextcourseids[$lcourseid],
                    'next_objective' => (string)$target->title,
                ];
                if (count($links) >= 2) { return $links; }
            }
        }
        return $links;
    }
```

Add `use` statements at top of `program_path.php`:
```php
use local_ai_course_assistant\objective_manager;
use local_ai_course_assistant\cross_course_mastery;
```

- [ ] **Step 4: Run, verify pass.**

- [ ] **Step 5: Commit**

```bash
git add classes/program/program_path.php tests/program_path_test.php
git commit -m "feat: program_path concept bridges via cross-course equivalency"
```

---

## Task 6: build_prompt_injection (advisory English block)

**Files:**
- Modify: `classes/program/program_path.php`
- Test: `tests/program_path_test.php`

- [ ] **Step 1: Add failing tests**

```php
    public function test_block_with_next_and_concept(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        // Reuse the concept-link scenario.
        $cur = $this->getDataGenerator()->create_course();
        $nextc = $this->getDataGenerator()->create_course();
        objective_manager::set_enabled_for_course((int)$cur->id, true);
        objective_manager::set_enabled_for_course((int)$nextc->id, true);
        objective_manager::create((int)$cur->id, 'Interpret a balance sheet');
        objective_manager::create((int)$nextc->id, 'Interpret a balance sheet');
        \local_ai_course_assistant\cross_course_mastery::rebuild_links();
        $cfg = [
            'available' => true,
            'user_programs' => [7 => [['programid'=>10,'name'=>'Acct Path']]],
            'program_courses' => [10 => [
                ['itemid'=>101,'courseid'=>(int)$cur->id,'coursename'=>'Acct I','visible'=>true,'ordered'=>false,'position'=>1],
                ['itemid'=>102,'courseid'=>(int)$nextc->id,'coursename'=>'Acct II','visible'=>true,'ordered'=>false,'position'=>2],
            ]],
            'prerequisites' => [10 => [102 => [101]]],
        ];
        $block = $this->path($cfg)->build_prompt_injection(7, (int)$cur->id);
        $this->assertStringContainsString('### Where this course leads', $block);
        $this->assertStringContainsString('Acct Path', $block);
        $this->assertStringContainsString('Acct II', $block);
        $this->assertStringContainsString('Interpret a balance sheet', $block);
    }

    public function test_block_membership_only_when_no_next(): void {
        $this->resetAfterTest();
        set_config('program_path_enabled', 1, 'local_ai_course_assistant');
        $cfg = $this->threecourse_cfg(7, [201, 202, 203]); // any-order, no prereqs
        $block = $this->path($cfg)->build_prompt_injection(7, 202);
        $this->assertStringContainsString('### Where this course leads', $block);
        $this->assertStringContainsString('course 2 of 3', $block);
        $this->assertStringNotContainsString('leads into', $block);
    }

    public function test_block_empty_when_no_context(): void {
        $this->resetAfterTest();
        $cfg = ['available' => true]; // flag off
        $this->assertSame('', $this->path($cfg)->build_prompt_injection(7, 202));
    }
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Implement build_prompt_injection** (hardcoded English, like the cross-course block)

```php
    public function build_prompt_injection(int $userid, int $courseid): string {
        $ctx = $this->forward_context($userid, $courseid);
        if ($ctx === null) { return ''; }

        $program = $ctx['program']['name'];
        $idx = $ctx['position']['index'];
        $total = $ctx['position']['total'];

        $block = "\n\n### Where this course leads\n"
            . "This course is part of the \"{$program}\" path (course {$idx} of {$total}). ";

        if (!empty($ctx['next_courses'])) {
            $names = array_map(static fn($n) => '"' . $n['name'] . '"', $ctx['next_courses']);
            $block .= "What the learner builds here leads into " . implode(' and ', $names)
                . ", which build" . (count($names) === 1 ? 's' : '') . " on it directly. ";
            if (!empty($ctx['concept_links'])) {
                $cl = $ctx['concept_links'][0];
                $block .= "Specifically, the \"{$cl['objective']}\" work here is the foundation for "
                    . "\"{$cl['next_objective']}\" in \"{$cl['next_course']}\". ";
            }
        }
        $block .= "When it fits naturally, help the learner see how today's work connects forward; "
            . "motivate with the path, but never sound like you're reading a database, and never "
            . "push them ahead before they're ready.";
        return $block;
    }
```

- [ ] **Step 4: Run, verify pass** (full file).

Run: `cd ~/Sites/moodle && export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH" && php vendor/bin/phpunit local/ai_course_assistant/tests/program_path_test.php`
Expected: PASS (all tests).

- [ ] **Step 5: Commit**

```bash
git add classes/program/program_path.php tests/program_path_test.php
git commit -m "feat: program_path advisory prompt block"
```

---

## Task 7: db_program_source (live dual-prefix adapter)

**Files:**
- Create: `classes/program/db_program_source.php`

No CI test (program tables absent in test DB); verified on dev in Task 11.

- [ ] **Step 1: Implement adapter**

```php
<?php
namespace local_ai_course_assistant\program;
defined('MOODLE_INTERNAL') || die();

/**
 * Live adapter over the Moodle Programs plugin. Detects the table prefix:
 * prod uses enrol_programs_*, dev uses tool_muprog_*. Every method is
 * \Throwable-guarded so a schema difference degrades to silence.
 */
class db_program_source implements program_source_interface {
    /** @var string|null detected prefix without trailing underscore, e.g. 'enrol_programs' */
    private $prefix = null;
    /** @var bool */
    private $detected = false;

    private function detect(): void {
        global $DB;
        if ($this->detected) { return; }
        $this->detected = true;
        $dbman = $DB->get_manager();
        foreach (['enrol_programs', 'tool_muprog'] as $p) {
            try {
                if ($dbman->table_exists($p . '_program')) { $this->prefix = $p; return; }
            } catch (\Throwable $e) { /* ignore */ }
        }
    }

    public function is_available(): bool {
        $this->detect();
        return $this->prefix !== null;
    }

    public function get_user_programs(int $userid): array {
        $this->detect();
        if ($this->prefix === null) { return []; }
        global $DB;
        try {
            $p = $this->prefix;
            $sql = "SELECT pr.id, pr.fullname
                      FROM {{$p}_allocation} a
                      JOIN {{$p}_program} pr ON pr.id = a.programid
                     WHERE a.userid = :uid AND a.archived = 0 AND pr.archived = 0";
            $rows = $DB->get_records_sql($sql, ['uid' => $userid]);
            return array_map(static fn($r) => ['programid' => (int)$r->id, 'name' => (string)$r->fullname], array_values($rows));
        } catch (\Throwable $e) { return []; }
    }

    public function get_programs_for_course(int $courseid): array {
        $this->detect();
        if ($this->prefix === null) { return []; }
        global $DB;
        try {
            $p = $this->prefix;
            $sql = "SELECT DISTINCT pr.id, pr.fullname
                      FROM {{$p}_item} i
                      JOIN {{$p}_program} pr ON pr.id = i.programid
                     WHERE i.courseid = :cid AND pr.archived = 0";
            $rows = $DB->get_records_sql($sql, ['cid' => $courseid]);
            return array_map(static fn($r) => ['programid' => (int)$r->id, 'name' => (string)$r->fullname], array_values($rows));
        } catch (\Throwable $e) { return []; }
    }

    public function get_program_courses(int $programid): array {
        $this->detect();
        if ($this->prefix === null) { return []; }
        global $DB;
        try {
            $p = $this->prefix;
            // Course-bearing items + course visibility + ordered flag from parent set sequencejson.
            $items = $DB->get_records_select("{$p}_item",
                'programid = :pid AND courseid IS NOT NULL',
                ['pid' => $programid], 'id ASC',
                'id, courseid, fullname, previtemid, topitem, sequencejson');
            // Determine ordered sets: any item whose sequencejson.type === 'allinorder'.
            $allitems = $DB->get_records("{$p}_item", ['programid' => $programid], '', 'id, sequencejson');
            $orderedset = [];
            foreach ($allitems as $it) {
                $j = json_decode((string)$it->sequencejson, true);
                if (is_array($j) && ($j['type'] ?? '') === 'allinorder') {
                    foreach (($j['children'] ?? []) as $childid) { $orderedset[(int)$childid] = true; }
                }
            }
            $out = [];
            $pos = 0;
            foreach ($items as $it) {
                $course = $DB->get_record('course', ['id' => (int)$it->courseid], 'id, fullname, visible');
                if (!$course) { continue; }
                $pos++;
                $out[] = [
                    'itemid' => (int)$it->id,
                    'courseid' => (int)$it->courseid,
                    'coursename' => (string)$course->fullname,
                    'visible' => (bool)$course->visible,
                    'ordered' => !empty($orderedset[(int)$it->id]),
                    'position' => $pos,
                ];
            }
            return $out;
        } catch (\Throwable $e) { return []; }
    }

    public function get_prerequisites(int $programid): array {
        $this->detect();
        if ($this->prefix === null) { return []; }
        global $DB;
        try {
            $p = $this->prefix;
            $sql = "SELECT pre.id, pre.itemid, pre.prerequisiteitemid
                      FROM {{$p}_prerequisite} pre
                      JOIN {{$p}_item} i ON i.id = pre.itemid
                     WHERE i.programid = :pid";
            $rows = $DB->get_records_sql($sql, ['pid' => $programid]);
            $out = [];
            foreach ($rows as $r) { $out[(int)$r->itemid][] = (int)$r->prerequisiteitemid; }
            return $out;
        } catch (\Throwable $e) { return []; }
    }

    public function is_item_completed(int $userid, int $programid, int $itemid): bool {
        $this->detect();
        if ($this->prefix === null) { return false; }
        global $DB;
        try {
            $p = $this->prefix;
            $sql = "SELECT c.id
                      FROM {{$p}_completion} c
                      JOIN {{$p}_allocation} a ON a.id = c.allocationid
                     WHERE a.userid = :uid AND a.programid = :pid AND c.itemid = :iid
                       AND c.timecompleted > 0";
            return $DB->record_exists_sql($sql, ['uid' => $userid, 'pid' => $programid, 'iid' => $itemid]);
        } catch (\Throwable $e) { return false; }
    }
}
```

- [ ] **Step 2: Lint**

Run: `/opt/homebrew/opt/php@8.3/bin/php -l classes/program/db_program_source.php`
Expected: No syntax errors.

- [ ] **Step 3: Run full program_path test file** (now the default `new db_program_source()` autoloads; tests still inject stub so DB is untouched).

Run: `cd ~/Sites/moodle && export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH" && php vendor/bin/phpunit local/ai_course_assistant/tests/program_path_test.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add classes/program/db_program_source.php
git commit -m "feat: db_program_source dual-prefix adapter (enrol_programs/tool_muprog)"
```

---

## Task 8: Wire into context_builder + flag + lang

**Files:**
- Modify: `classes/context_builder.php` (after line ~313, outside the objectives-enabled block)
- Modify: `settings.php` (~line 1380)
- Modify: `lang/en/local_ai_course_assistant.php`

- [ ] **Step 1: Wire the block into context_builder**

After the mastery/study blocks (immediately after line 313's closing `}` of the objectives-enabled `if`), add:

```php
        // v5.8.0 — Forward learning-path awareness (program_path). Independent
        // of objectives/mastery being enabled; default-off feature flag.
        $pathblock = (new \local_ai_course_assistant\program\program_path())
            ->build_prompt_injection($userid, $courseid);
        if ($pathblock !== '') {
            $sections[] = new section('program_path', section::CAT_LEARNER, 45, $pathblock, 0);
        }
```

- [ ] **Step 2: Register the flag** in `settings.php` pedagogy-defaults `foreach` array (after `'mastery_starter_enabled' => 'pedagogy:mastery_starter',`):

```php
        'program_path_enabled'    => 'pedagogy:program_path',
```

- [ ] **Step 3: Add EN lang strings** in `lang/en/local_ai_course_assistant.php` near the other `pedagogy:` strings:

```php
$string['pedagogy:program_path']      = 'Forward learning-path awareness on by default';
$string['pedagogy:program_path_desc'] = 'When on, SOLA can tell a learner where the current course leads next in their program (degree or certificate) and how today\'s concepts bridge to later courses. Reads the Moodle Programs plugin (Degrees and Learn); silently does nothing where no program applies. Advisory only — it never changes enrolment or mastery, and only ever uses the current learner\'s own program allocation.';
```

- [ ] **Step 4: Lint all three**

Run: `/opt/homebrew/opt/php@8.3/bin/php -l classes/context_builder.php && /opt/homebrew/opt/php@8.3/bin/php -l settings.php && /opt/homebrew/opt/php@8.3/bin/php -l lang/en/local_ai_course_assistant.php`
Expected: No syntax errors.

- [ ] **Step 5: Run lang_completeness + program_path tests**

Run: `cd ~/Sites/moodle && export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH" && php vendor/bin/phpunit local/ai_course_assistant/tests/lang_completeness_test.php local/ai_course_assistant/tests/program_path_test.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add classes/context_builder.php settings.php lang/en/local_ai_course_assistant.php
git commit -m "feat: wire program_path block + program_path pedagogy flag + EN strings"
```

---

## Task 9: Version bump + changelog + release notes

**Files:**
- Modify: `version.php`
- Modify: `.wiki/Changelog.md`
- Create: `.drafts/v5.8.0-release-notes-and-walkthrough.md` (via `scripts/new_release_notes.py --version 5.8.0`)

- [ ] **Step 1:** Bump `version.php`: `$plugin->version = 2026060200;` and `$plugin->release = '5.8.0';`
- [ ] **Step 2:** Add a v5.8.0 entry to `.wiki/Changelog.md` (top), summarizing forward learning-path awareness.
- [ ] **Step 3:** `python3 scripts/new_release_notes.py --version 5.8.0`; fill Part 1, carry Part 2/3 forward from v5.7.0 (add the new capability row + a forward-path walkthrough section).
- [ ] **Step 4:** Lint `version.php`.
- [ ] **Step 5:** Commit `version.php` + wiki (wiki is a separate repo — commit there separately at release time).

---

## Task 10: Deploy to local, run pre-release gates

- [ ] **Step 1:** Deploy + purge: `rsync -a --exclude=.git --exclude=docs ./ ~/Sites/moodle/local/ai_course_assistant/ && /opt/homebrew/opt/php@8.3/bin/php ~/Sites/moodle/admin/cli/purge_caches.php`
- [ ] **Step 2:** Validators: `php ~/Sites/moodle/local/ai_course_assistant/admin/cli/run_validators.php` → 36/0.
- [ ] **Step 3:** Jailbreak: `php ~/Sites/moodle/local/ai_course_assistant/admin/cli/jailbreak_test.php` → 32/32 (the new block uses only the current learner's allocation, so MemoryLeak stays green).
- [ ] **Step 4:** Full suite on a FRESH phpunit DB: `cd ~/Sites/moodle && php admin/tool/phpunit/cli/util.php --drop && php admin/tool/phpunit/cli/init.php && php admin/tool/phpunit/cli/util.php --buildconfig && php vendor/bin/phpunit --testsuite local_ai_course_assistant_testsuite` → all pass (health_check requires fresh DB).
- [ ] **Step 5:** a11y: the feature adds no DOM, so run the existing a11y suite to confirm no regression: `cd local/ai_course_assistant/tests/a11y && npm test` (or the documented command). Expected: no new violations.

---

## Task 11: Verify adapter on dev (read-only)

- [ ] **Step 1:** SSM probe dev.sylr.org: enable `program_path` on a course inside the demo program, send a turn, read the prompt-debug log, confirm the "Where this course leads" block renders and suppresses correctly.
- [ ] **Step 2:** Confirm `db_program_source` detects `tool_muprog` on dev and (by route evidence) `enrol_programs` on prod.

---

## Task 12: i18n — translate the 2 new keys into 45 languages

- [ ] **Step 1:** Run the translation workflow (one agent per language) for `pedagogy:program_path` and `pedagogy:program_path_desc`.
- [ ] **Step 2:** Unescape HTML entities, validate no placeholders (these keys have none), append to all 45 lang files, lint all.
- [ ] **Step 3:** Commit `lang/`.

---

## Task 13: Release (CI → merge → tag → GH → deploy)

- [ ] **Step 1:** Push branch; open PR; confirm CI green (PHPDoc, lint, phpunit on fresh CI DB).
- [ ] **Step 2:** Squash-merge to main with a clean `v5.8.0:` subject.
- [ ] **Step 3:** Tag `v5.8.0`, push tag; commit + push wiki Changelog.
- [ ] **Step 4:** `gh release create v5.8.0` with Part 1 of the drafts file as body.
- [ ] **Step 5:** `deploy_dev.py --target all`; confirm all sites at 5.8.0 + BUS101 smoke OK.

---

## Self-Review Notes

- **Spec coverage:** interface/adapter/stub/logic (§4) → Tasks 1,2,3,7; gating + off switches (§5) → Tasks 2,8; prompt block (§4.5) → Task 6,8; concept bridge (§4.4d) → Task 5; privacy/jailbreak (§7) → Task 10 step 3; dual-prefix (§3) → Task 7; testing (§9) → Tasks 2–6,10; release mechanics (§10, §9 spec) → Tasks 9,12,13. All covered.
- **Type consistency:** course-row shape `{itemid,courseid,coursename,visible,ordered,position}` used identically in interface, stub, db adapter, and `program_path`. `next_courses` row `{courseid,name,reason}` consistent across Task 4/5/6. `concept_links` row `{objective,next_course,next_objective}` consistent Task 5/6.
- **Placeholders:** none — every code step is complete.
