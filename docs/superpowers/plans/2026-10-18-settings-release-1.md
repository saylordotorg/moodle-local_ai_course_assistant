# SOLA 7.10.0: regroup the settings menu (Release 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the 11 flat settings pages with five task pages and an Advanced group of five pages, without renaming, deleting or re-defaulting any setting.

**Architecture:** `settings.php` keeps building every setting where it does today, but onto staging pages that are never registered. A new `settings_layout` class reads the placement table in `classes/settings_layout_map.php` (already written), moves each setting onto its new page, adds a navigation notice per page, attaches the show/hide rules, and registers the pages, the Advanced category, and eleven hidden redirect pages for the old page names.

**Tech Stack:** PHP 8.1+, Moodle 4.5+ admin settings API (`admin_settingpage`, `admin_category`, `admin_setting_heading`, `admin_externalpage`), PHPUnit (Moodle `advanced_testcase`), Mustache not needed.

**Spec:** `docs/superpowers/specs/2026-10-18-settings-simplification-design.md` (read it first; section 3 holds the rulings, Appendix A the layout, Appendix B the new strings).

## Global Constraints

- Moodle 4.5+ (`$plugin->requires = 2024100700`), PHP 8.1+. Plugin component `local_ai_course_assistant`.
- **No setting is renamed, deleted, re-defaulted or re-labeled.** Names, defaults and stored values are untouched. Only the page a setting renders on changes (and the `sec_*_anchor` notices stop rendering).
- `classes/settings_layout_map.php` is fixed input. Do not edit it without writing the reason in your report; it was checked to cover all 355 admin tree entries exactly once and to keep every show/hide rule's two ends on one page.
- Each page's dependency payload must stay at or under 950 characters (`settings_dependencies_test` ceiling; core's own limit is 1,024).
- No secret value may appear in any page's HTML (`settings_no_secret_in_page_test`). Do not move a secret field onto a page whose test rendering you have not run.
- New strings: English only, in `lang/en/local_ai_course_assistant.php`, added to the marked block of `KNOWN_UNTRANSLATED` in `tests/lang_completeness_test.php`. A commit that adds a `get_string()` call carries its `lang/en` key in the SAME commit.
- UI wording follows Tom's writing rules: no em dashes, US English, contractions where natural.
- Test environment: `export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH"`; MySQL running (`brew services start mysql`); rsync THIS worktree (never the primary checkout) to `~/Sites/moodle/local/ai_course_assistant/` before running phpunit from `~/Sites/moodle`; run test FILES by path or `--filter`, and read the test COUNT in the output ("OK (N tests ...)", and N must not be 0). A `version.php` bump needs `php admin/tool/phpunit/cli/init.php` before the next run or phpunit executes zero tests and still exits 0. After every rsync, purge caches: `php ~/Sites/moodle/admin/cli/purge_caches.php`.
- Commits: message subject in imperative mood, body explains why. End every commit message with these two lines:
  `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` and `Claude-Session: https://claude.ai/code/session_016qGUU4qerkRHrXTh1qyBp6`.
- Work only inside `/Users/tom.caswell/ai-projects/wt-settings-r1` on branch `feat/settings-regroup`. Never `cd` to the primary checkout. Never use bare `git stash`.

## Review Focus

Failure modes the spec implies but no single task's tests exercise; each is pinned by the named task.

1. A setting added later with no home must fail the build, not land silently. (Task 1: `unplaced` empty, and the map names no setting that doesn't exist.)
2. Every show/hide rule must still fire after the move; a rule whose two ends end up on different pages is skipped with only a developer notice, which looks fine in a browser. (Task 1: `skipped` empty.)
3. An old bookmark (`admin/settings.php?section=local_ai_course_assistant_ai`) must land on the new page, not an error. (Task 2.)
4. No secret value reaches any new page's HTML, including pages inside the nested Advanced category, which tests that only read `$category->children` would never visit. (Task 1 updates every such test to recurse.)
5. The all-settings hub page (`admin/category.php?category=local_ai_course_assistant`) and Moodle's admin search must still work with a nested category. (Task 1 checks both and records what it found.)
6. A fresh install still applies every default (`admin_apply_default_settings` runs over registered settings only). (Task 1: defaults snapshot.)

---

### Task 1: Layout engine, new pages, navigation

**Files:**
- Create: `classes/settings_layout.php`
- Create: `scripts/dump_settings_defaults.php` (Moodle CLI script, same style as other `admin/cli` tools but kept under `scripts/`; prints JSON `{name: default}` for every plugin setting in the admin tree)
- Create: `tests/fixtures/settings_defaults_snapshot.json` (generated from UNMODIFIED `main` before you change settings.php)
- Create: `tests/settings_layout_test.php`
- Modify: `settings.php` (stop registering staging pages; replace the dependency-attach loop; rewrite the `version_banner` body; call the layout engine)
- Modify: `lang/en/local_ai_course_assistant.php` (new strings, Appendix B of the spec)
- Modify: `tests/lang_completeness_test.php` (`KNOWN_UNTRANSLATED`)
- Modify: `styles.css` (a small `.sola-pagenav` rule set, reusing the existing `.sola-toc` look)
- Modify: `tests/settings_page_structure_test.php`, `tests/settings_dependencies_test.php`, `tests/settings_no_secret_in_page_test.php`, `tests/settings_secret_masking_test.php`, `tests/settings_branding_test.php`, `tests/settings_save_report_test.php` (collect pages recursively; new page ids; see step 8)

**Interfaces:**
- Consumes: `\local_ai_course_assistant\settings_layout_map::PAGES`, `::REDIRECTS`, `::DROPPED` (constants, fixed). Staging pages: the array `$aicapages` of eleven `admin_settingpage` objects already built in settings.php, and the `$dependencies` array (toggle name => list of dependent names).
- Produces (Task 2 relies on these exact names):
  - `settings_layout::page_id(string $short): string` returns `'local_ai_course_assistant_' . $short`.
  - `settings_layout::build(array $staged, array $dependencies): array` returns `['pages' => array<string, \admin_settingpage> keyed by short id, in registration order, 'unplaced' => string[], 'missing' => string[], 'skipped' => array<int, array{0:string,1:string}>]`. `unplaced` = staged setting names that are neither in `PAGES` nor `DROPPED` (they are appended to the `adv_legacy` page and `debugging(..., DEBUG_DEVELOPER)` is called once per name); `missing` = names `PAGES` lists that no staging page holds; `skipped` = `[dependent, toggle]` pairs whose ends are on different pages (skipped, `debugging(..., DEBUG_DEVELOPER)` as today).
  - `settings_layout::register(\admin_root $admin, array $built): void` adds the five task pages directly under `local_ai_course_assistant`, then an `admin_category('local_ai_course_assistant_advanced', get_string('settingspage:advanced', ...))` holding the five `adv_*` pages. It does NOT add the redirect pages (Task 2 does) and does NOT add the tool pages (settings.php still does, after calling register).
  - `settings_layout::last_report(): ?array` returns the `unplaced`, `missing`, `skipped` of the most recent `build()` (a static, so tests can read what `settings.php` found when `admin_get_root(true, true)` rebuilt the tree).
  - `settings_layout::nav_html(string $currentshort): string` returns the navigation row (links to the five task pages and "Advanced", current one marked with `aria-current="page"`) followed by that page's intro paragraph.

- [ ] **Step 1: Generate the defaults snapshot from unmodified main, before touching settings.php.** Write `scripts/dump_settings_defaults.php` (Moodle CLI: `define('CLI_SCRIPT', true)`, requires `config.php` four levels up like `admin/cli/run_validators.php`, `admin_get_root(true, true)`, walk every `admin_settingpage` under `local_ai_course_assistant`, print `json_encode([name => get_defaultsetting()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)`; for `admin_setting_heading` and `admin_setting_description` skip; for secret fields print the string `"<secret>"` instead of any default). Run it from the Moodle tree against the current code, save the output as `tests/fixtures/settings_defaults_snapshot.json`. Commit this alone ("Snapshot every setting's default before regrouping") with the script. Expected: 289 entries.

- [ ] **Step 2: Read before writing.** Read `lib/adminlib.php` in `~/Sites/moodle` for `admin_settingpage::add`, `admin_settingpage::hide_if`, `admin_category`, `admin_externalpage`, and how a setting's `name` and `plugin` map to the property name on `$page->settings` (settings.php's existing `$aicaowner` closure indexes with `'local_ai_course_assistant' . $name`; find out why and index the same way). Read `settings.php` lines 52 to 285 (page setup, quick links, TOC) and 3688 to 3841 (dependency map and attach loop). Write down in your report: how you index staged settings, and whether `admin_settingpage::add()` accepts an object that sits on another page.

- [ ] **Step 3: Write `settings_layout_test` first (failing).** Cases, each with a real assertion:
  1. `test_every_staged_setting_has_a_home`: build the tree with `admin_get_root(true, true)` (admin user, `resetAfterTest`), then `settings_layout::last_report()['unplaced']` is `[]` and `['missing']` is `[]`.
  2. `test_no_dependency_rule_is_skipped`: `last_report()['skipped']` is `[]`.
  3. `test_pages_exist_in_the_expected_places`: the category `local_ai_course_assistant` has direct settings-page children with ids `essentials, models, features, safety, cost` (full ids via `page_id()`), a child category `local_ai_course_assistant_advanced`, and that category holds `adv_retrieval, adv_documents, adv_prompt, adv_integrations, adv_legacy`.
  4. `test_each_map_entry_lands_on_its_page`: for every `PAGES` entry and every listed name that is a setting, the page named holds a setting with that bare name (use the same indexing as step 2).
  5. `test_new_headings_have_strings`: for every `['new', key, ...]` section, `get_string_manager()->string_exists('layout:' . key, 'local_ai_course_assistant')`; for every page id, `settingspage:<id>` and `settingspage:<id>_intro` exist.
  6. `test_day_to_day_pages_stay_small`: the total count of value settings (not `admin_setting_heading`, not `admin_setting_description`) across the five task pages is at most 150 (today's layout has 144), and no task page has more than 60.
  7. `test_defaults_are_unchanged`: load `tests/fixtures/settings_defaults_snapshot.json`; for every setting in the rebuilt tree compare `get_defaultsetting()` (skip headings, descriptions; secret fields compare as `"<secret>"`); the key sets are equal and every value is equal.
  8. `test_placement_logic_with_a_fake_staging_set`: call `settings_layout::build()` directly with a tiny staged set of fake `admin_setting_configtext` objects (one named in the map, one not, one dropped name) and a fake `$dependencies`; assert `unplaced` contains exactly the unmapped one, the mapped one is on the right page, the dropped one is nowhere, and a dependency whose ends are mapped to different pages shows up in `skipped`. (This is the test that proves the engine's own failure paths, since the real tree has none.)
  Run it; all fail (class missing). Expected output: errors naming `settings_layout`.

- [ ] **Step 4: Write `classes/settings_layout.php`.** Behavior, in order, per spec section 4.2: index staged objects; create the ten pages (`new \admin_settingpage(page_id($short), get_string('settingspage:' . $short, ...), 'moodle/site:config')`); for each page in `PAGES` order add the nav notice (`new \admin_setting_description('local_ai_course_assistant/nav_' . $short, '', self::nav_html($short))`), then each section (banner: move the object; existing: move the heading object, then its settings; new: create `admin_setting_heading('local_ai_course_assistant/layout_' . $key . '_heading', get_string('layout:' . $key, ...), get_string_manager()->string_exists('layout:' . $key . '_desc', ...) ? get_string(...) : '')`); skip `DROPPED`; append every unplaced object to `adv_legacy` with one `debugging()` call each; attach each rule in `$dependencies` to the page that holds BOTH ends via `$page->hide_if('local_ai_course_assistant/' . $dependent, 'local_ai_course_assistant/' . $toggle, 'notchecked')`, recording mismatches in `skipped`. Branding: any `get_string` that carries a brand token in the old code goes through `branding::str()` in the old code and the moved objects keep their already-resolved labels, so you add none. `nav_html()` builds links with `new \moodle_url('/admin/settings.php', ['section' => page_id($short)])`, escapes with `s()`, marks the current page, and appends `<p class="sola-pagenav-intro">` + the intro string. Keep each method short and documented (PHPDoc with `@param` and `@return` on every method, the repo's lint gate requires it).

- [ ] **Step 5: Wire `settings.php`.** (a) Delete the ten `$ADMIN->add('local_ai_course_assistant', $settings);` lines that close pages 1 to 10 and the eleventh at the end (keep every `$aicapages[] = $settings;`). (b) Replace the whole old attach block (the `$aicaowner` closure and the `foreach ($dependencies ...)` loop, about lines 3797 to 3839) with:
  ```php
  $aicabuilt = \local_ai_course_assistant\settings_layout::build($aicapages, $dependencies);
  \local_ai_course_assistant\settings_layout::register($ADMIN, $aicabuilt);
  ```
  placed where the final `$ADMIN->add('local_ai_course_assistant', $settings);` was, so the tool pages that follow still register after the settings pages. (c) Rewrite the `$toc` string: drop the `<ul>` of `#sec-*` jump links and the `$tocstyle`; keep `<div class="sola-toc"><div class="sola-quicklinks">...</div></div>` so the quick links stay on Essentials. (d) Update the file's docblock (it still says "7 settings pages"). Leave every `$settings->add(...)` untouched.

- [ ] **Step 6: Strings.** Add the Appendix B strings to `lang/en` (`settingspage:<id>`, `settingspage:<id>_intro` for the ten pages, `settingspage:advanced`, `settingspage:nav_*` only if the nav row needs labels beyond the page titles, and `layout:<key>` for the 16 headings). Add each new key to `KNOWN_UNTRANSLATED` in the block 7.9.1 used (search for how `providerkeys:default_stale_key` is listed and follow that). No em dashes.

- [ ] **Step 7: Style.** Add `.sola-pagenav` rules to `styles.css` (a flex row of links, current page bold, matching `.sola-toc` colors). Do not add inline styles to the notice.

- [ ] **Step 8: Update the existing tests.** For each test in the Files list, replace "walk `$category->children` and take `admin_settingpage` instances" with a recursive walk that also descends into child `admin_category` objects (put one shared helper in a test trait or a small final class under `tests/`, and use it everywhere so nothing walks the tree its own way again). `settings_page_structure_test`: the navigation notice name starts `nav_`, not `_anchor`; skip both when finding the first real setting; the page count assertion becomes `>= 10`; the "general page leads with the enable switch" special case becomes `essentials`. `settings_dependencies_test`: `settings_page()` (the "first page" helper) must not silently assert on the wrong page; make the two tests that use it read ALL pages. Any test that names an old page id (grep `local_ai_course_assistant_\(general\|ai\|content\|rerank\|extraction\|operations\|engagement\|feedback\|branding\|integrations\)`) is pointed at the page that now holds the setting it is about.

- [ ] **Step 9: Run and prove.** Rsync, purge, run `tests/settings_layout_test.php` (expect OK with 8 tests), then each updated test file by path and read each COUNT. Then run `tests/lang_completeness_test.php`, `tests/i18n_translation_drift_test.php` and `tests/lang_placeholder_integrity_test.php` (they must stay green). Prove two guards can fail with `scripts/prove_test.py`: (a) delete one name from a section in `settings_layout_map.php` and show `test_every_staged_setting_has_a_home` fails; (b) move `rag_extract_pdf` to another page in the map and show `test_no_dependency_rule_is_skipped` fails. Report both exit codes. (Mutate through prove_test.py or a temp copy, never by editing and reverting the tracked file.)

- [ ] **Step 10: Look at the real thing.** With the local Moodle running (`php -S 0.0.0.0:8080 -t ~/Sites/moodle`), as admin open each of the ten pages once (`/admin/settings.php?section=...`), the hub page `/admin/category.php?category=local_ai_course_assistant`, and an admin search (`/admin/search.php?query=embedding`). In your report say: do the ten pages render, does the hub page show the Advanced group's settings and does it double-render any, and does search find `rag_topk`? If a page throws or the hub page breaks, fix it or stop and say so. Take no screenshots into the repo.

- [ ] **Step 11: Commit** the layout engine, wiring, strings, styles and test updates as one commit ("Regroup the settings menu into five task pages and an Advanced group"). `php -l` every changed PHP file first.

---

### Task 2: Old page names redirect, internal links follow

**Files:**
- Modify: `classes/settings_layout.php` (add `register_redirects()` and call it from `register()`)
- Modify: `audit_log.php`, `emergency_admin.php`, `rag_admin.php`, `classes/autoupgrade/page.php`
- Modify: `tests/settings_layout_test.php` (add cases)

**Interfaces:**
- Consumes: Task 1's `settings_layout::register()` and `settings_layout_map::REDIRECTS` (old short name => new short name).
- Produces: eleven hidden `admin_externalpage` objects (ten from `REDIRECTS`; the old `safety` id is also a new id and has no redirect) registered under `local_ai_course_assistant`, each named `local_ai_course_assistant_<old>`.

- [ ] **Step 1: Failing tests.** Add to `settings_layout_test`: `test_every_old_page_name_redirects` (for each `REDIRECTS` pair the tree `locate('local_ai_course_assistant_<old>')` returns an `admin_externalpage`, its `url` is `/admin/settings.php?section=<full new id>`, and the new id locates an `admin_settingpage`); `test_redirect_pages_are_hidden` (none appears in `$category->children` of non-hidden pages when rendered for the navigation: assert the object's `hidden` property is true); `test_internal_links_point_at_real_pages` (read `audit_log.php`, `emergency_admin.php`, `rag_admin.php`, `classes/autoupgrade/page.php` source, find every `'section' => '...'` value, assert each locates an `admin_settingpage` in the tree). Run: fail.
- [ ] **Step 2: Implement.** First check, in `~/Sites/moodle/admin/settings.php`, what core does with a section that is an external page. If it redirects, use `new \admin_externalpage($oldid, get_string('settingspage:redirect', ...), new \moodle_url('/admin/settings.php', ['section' => page_id($new)]), 'moodle/site:config', true)`. If core rejects non-settings pages there, use the nearest mechanism core supports and record what you chose and why in the report. Add the one string `settingspage:redirect` ("Moved") to `lang/en` and `KNOWN_UNTRANSLATED`.
- [ ] **Step 3: Links.** `audit_log.php`, `emergency_admin.php`, `rag_admin.php`: `'section' => 'local_ai_course_assistant_general'` becomes `'local_ai_course_assistant_essentials'` using `settings_layout::page_id('essentials')` rather than a literal. `classes/autoupgrade/page.php`: `..._operations` becomes `settings_layout::page_id('cost')`. Grep the repo (excluding `lang/`, `.wiki`, `.drafts`) for any other `section=` or `'section' =>` pointing at an old id and fix it.
- [ ] **Step 4: Run** the new cases plus `settings_layout_test` and `settings_page_structure_test`; read the counts. In a browser (local Moodle) request `/admin/settings.php?section=local_ai_course_assistant_ai` and confirm it lands on Models and keys; say what you saw.
- [ ] **Step 5: Commit** ("Old settings page names redirect to the page that replaced them").

---

### Task 3: Course page "More options"

**Files:**
- Modify: `course_settings.php` (and its template or helper if the form is rendered elsewhere; find where)
- Create: `tests/course_settings_more_options_test.php` if the page's markup is reachable from a test, otherwise extend the nearest existing course-settings test
- Modify: `lang/en/local_ai_course_assistant.php`, `tests/lang_completeness_test.php`

**Interfaces:** none consumed from other tasks. Produces a closed-by-default `<details>` wrapper; no data or setting name changes.

- [ ] **Step 1: Read** `course_settings.php` and find where base URL, system prompt, temperature and monthly spend cap are rendered. Say in your report where, and how the form posts them (the wrapper must not change field names, order of submission or validation).
- [ ] **Step 2: Failing test.** Render the page's form markup for a course with none of the four set and assert the four fields sit inside `<details class="sola-more-options">` without the `open` attribute; render for a course with a temperature set and assert `open` is present; assert the four field names are unchanged. If the page can't be rendered from a test, extract the "any of the four set?" decision into a small pure method on `course_config_manager` and test that, plus a source-level assertion on the wrapper in the template.
- [ ] **Step 3: Implement.** `<details class="sola-more-options"><summary>` + string `coursesettings:more_options` ("More options") + the four fields. Open when any of the four has a stored value. No JS.
- [ ] **Step 4: Run** the test and the nearest existing course settings tests; read the counts. Commit ("Put rarely used course settings under More options").

---

### Task 4: Settings usage report

**Files:**
- Create: `admin/cli/settings_usage_report.php`
- Create: `classes/settings_usage.php` (the logic, so it can be tested without the CLI wrapper)
- Create: `tests/settings_usage_test.php`

**Interfaces:**
- Consumes: Task 1's tree layout only through `admin_get_root()`; it reads page and setting names from the live admin tree, not from `settings_layout_map`.
- Produces: `settings_usage::report(bool $percourse = false, bool $tables = false): array` returning `['settings' => array<string, string>, 'summary' => array{default:int, changed:int, secret_set:int, secret_unset:int, not_stored:int}, 'percourse' => ?array, 'tables' => ?array<string,int>]` where each settings value is one of `default`, `changed`, `secret set`, `secret unset`, `not stored`.

- [ ] **Step 1: Failing tests** in `settings_usage_test`: a setting stored at its default reports `default`; a stored non-default value reports `changed` and the report array nowhere contains that value (assert `json_encode($report)` does not contain a sentinel string you stored); a secret set through `secrets::conceal()` reports `secret set` and its text appears nowhere in the report; a secret never saved reports `secret unset`; a setting with no `config_plugins` row reports `not stored`; `percourse` counts rows of the per-course config table and the per-course dynamic setting names (`*_course_<id>`) grouped by base name, values never printed; `tables` returns an exact `COUNT(*)` per plugin table (use `$DB->get_tables()` filtered by the plugin prefix). Run: fail.
- [ ] **Step 2: Implement** `settings_usage` (compare stored to default by `md5` equality without ever returning the value; identify secrets with `secrets::names()`) and the CLI wrapper (`--json`, `--per-course`, `--tables`, `--help`; default output is a short summary plus one line per `changed` or `secret set` setting; never a value). Use `cli_get_params`, follow the layout of the other `admin/cli` scripts.
- [ ] **Step 3: Run** the test and read the count; run the CLI on the local Moodle once and paste the summary line (not any value) into your report. Commit ("Add a settings usage report that never prints a value").

---

### Task 5: Docs, rule, version, release notes

**Files:**
- Modify: `README.md`, `CLAUDE.md`, `version.php`
- Create: `.drafts/v7.10.0-release-notes-and-walkthrough.md`
- Modify: `docs/superpowers/specs/2026-10-18-settings-simplification-design.md` (status line: Release 1 built)

**Interfaces:** none.

- [ ] **Step 1: README.** Find every sentence that names the old pages ("AI provider and models", "Content and retrieval", "Resilience and operations", "Surveys and feedback", "Branding and interface", "Document extraction", "Integrations and delivery", "Safety and moderation", "Engagement") and rewrite to the new menu. Do not touch `.wiki/` (it is a separate repository and does not exist in this worktree; the controller publishes it with `scripts/push_wiki.py`).
- [ ] **Step 2: CLAUDE.md.** Update the version lines (current version, release) to `2026101801` / `7.10.0`. Add one short bullet to Key Features describing the new menu and the layout map, and a rule under "Important Architecture Notes": a new setting must be added to `classes/settings_layout_map.php` and a new feature ships with at most three visible settings.
- [ ] **Step 3: Version.** `version.php`: `$plugin->version = 2026101801; $plugin->release = '7.10.0';`. No upgrade step is needed (no data change); say so in the release notes.
- [ ] **Step 4: Release notes.** Seed with `python3 scripts/new_release_notes.py --version 7.10.0`, then fill Part 1 (headline: "A settings menu organized by task"; bullets: five task pages and an Advanced group; nothing renamed or re-defaulted; old URLs redirect; More options on the course page; usage report tool; how to find a moved setting) and update Part 3's walkthrough to the new pages. Carry Part 2 forward unchanged. List the rulings from spec section 3 that an admin would notice (English-only strings for now; Soapbox and avatar settings moved to Advanced; no Marketplace upload). No em dashes.
- [ ] **Step 5: Verify and commit.** `php -l version.php`; re-init phpunit (`php admin/tool/phpunit/cli/init.php` from `~/Sites/moodle`) and re-run `tests/settings_layout_test.php`, reading the count (must not be 0). Commit ("SOLA 7.10.0: version, docs and release notes").
