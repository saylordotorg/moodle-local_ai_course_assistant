# SOLA 7.13.0: Soapbox cleanup interlock and the spend-cap failover fix

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** (1) SOLA's nightly Soapbox cleanup never deletes a recording that PresenterAI's importer now owns. (2) A chat call that trips a spend cap and fails over uses the failover row's own model and base URL, as the per-call failover has since 7.2.8.

**Architecture:** (1) A small class `classes/presenterai_interlock.php` answers "does PresenterAI's migration map hold this recording for this site?" and `soapbox_cleanup` consults it in both loops. (2) `base_provider::create_from_config()` applies the failover entry's model and base URL on the cap path, through the same rules `build_fallbacks()` already applies.

**Tech Stack:** PHP 8.1+, Moodle 4.5+, PHPUnit.

**Specs:** PresenterAI importer design `docs/superpowers/specs/2026-10-19-soapbox-importer-design.md` section 5 (in the repo `~/ai-projects/wt-presenterai-importer`, read-only for you), and the spend analysis `/private/tmp/claude-501/-Users-tom-caswell/0cd15b05-1706-43ba-b90b-4612814f78c3/scratchpad/spend/spend-caps-report.md` section 1 "What a learner sees when a cap trips" (Task B).

## Global Constraints

- Work only in `/Users/tom.caswell/ai-projects/wt-sola-7130` on branch `feat/7.13.0-interlock-and-cap-failover` (from main 94e5d580). Never `cd` to `~/ai-projects/ai_course_assistant`. No bare `git stash`.
- **Map contract shared with PresenterAI's migrator (pinned by a test here and one there):** table `presenterai_migration_map` (Moodle name; use `$DB->get_manager()->table_exists('presenterai_migration_map')`), columns used: `sourcetable`, `sourceid`, `sourcesiteid`. A recording is held when a row exists with `sourcetable = 'local_ai_course_assistant_sbx_rec'`, `sourceid = <rec id>` and `sourcesiteid = sha1($CFG->wwwroot . '|' . get_site_identifier())`. Put the two literals and the formula in ONE place: `presenterai_interlock::SOURCE_TABLE` and `presenterai_interlock::site_id()`.
- Never write to a PresenterAI table. SOLA only READS `presenterai_migration_map`, only behind `table_exists()`, so a site without PresenterAI behaves exactly as today. Never print or log a learner's name or transcript.
- No schema change, no upgrade step, no new setting, no new capability. New lang strings (if any) only in `lang/en/local_ai_course_assistant.php`, in the same commit as the `get_string()`/`branding::str()` call; add new English-only strings to `KNOWN_UNTRANSLATED` in `tests/lang_completeness_test.php` the way the 7.10.0 to 7.12.0 strings are (read how).
- PHPDoc `@param`/`@return` on every method with PLAIN `array` types; lines at most 132 characters counted in characters; no em dashes in docs or strings; US English.
- Test environment: `export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH"`; `rsync -a --delete --exclude=.git --exclude=.superpowers --exclude=node_modules /Users/tom.caswell/ai-projects/wt-sola-7130/ ~/Sites/moodle/local/ai_course_assistant/` (THIS worktree) then `php ~/Sites/moodle/admin/cli/purge_caches.php`; run ONE test file per phpunit call by path (`cd ~/Sites/moodle && php vendor/bin/phpunit local/ai_course_assistant/tests/<file>.php`) and READ THE COUNT ("OK (N tests ...)", N must not be 0). Do NOT run the whole suite (the controller does). The local Moodle PHPUnit database may not have PresenterAI's tables: the interlock tests must create the map table rows only when it exists, and a separate test covers the table-absent path; to test the held path without PresenterAI installed, the interlock class takes an optional table-name/clock-free seam described in Task A.
- Prove guards can fail with `python3 scripts/prove_test.py --file <path> --find <text> --replace <text> --filter <Class::method>` (read its header for env vars such as `SOLA_REPO`; set it to this worktree) and report the exit codes (0 caught, 1 not pinned, 2 inconclusive).
- Commits end with: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` and `Claude-Session: https://claude.ai/code/session_016qGUU4qerkRHrXTh1qyBp6`. Do not bump the version (the controller does).

## Review Focus

1. A held recording is neither deleted from storage nor changed in the database, in BOTH the retention loop and the stored-attempts pruning loop. (Task A.)
2. With no `presenterai_migration_map` table, or a map row for another site's `sourcesiteid`, cleanup behaves exactly as before. (Task A.)
3. A cap-tripped chat call to a failover with its own model and base URL sends exactly those, and a failover row with no model sends the provider's default model, never the primary's name. (Task B.)
4. The cap path still refuses with the same message when no failover is configured, and the emergency-stop path is untouched. (Task B.)

---

### Task A: The Soapbox cleanup interlock

**Files:**
- Create: `classes/presenterai_interlock.php`, `tests/presenterai_interlock_test.php`
- Modify: `classes/task/soapbox_cleanup.php`, `lang/en/local_ai_course_assistant.php` only if an audit string is needed (prefer none: audit rows carry action codes and counts)

**Interfaces:**
- Produces: `presenterai_interlock::SOURCE_TABLE = 'local_ai_course_assistant_sbx_rec'`; `presenterai_interlock::MAP_TABLE = 'presenterai_migration_map'`; `presenterai_interlock::site_id(): string`; `presenterai_interlock::held_ids(array $recids): array` (the subset of the given recording ids held by the map, as ints; `[]` when the map table does not exist or the input is empty; one query for the whole list); `soapbox_cleanup::PRESENTERAI_INTERLOCK = true` (a public class constant PresenterAI's reader checks with `defined()`).
- Consumes: nothing from other tasks.

- [ ] **Step 1: Read** `classes/task/soapbox_cleanup.php` (both loops and `drop_object`), `tests/soapbox_observation_retention_test.php` (how rows are built; there is no SOLA generator, plain `insert_record`), `classes/audit_logger.php`.
- [ ] **Step 2: Failing tests first** (`tests/presenterai_interlock_test.php`): `site_id()` equals `sha1($CFG->wwwroot . '|' . get_site_identifier())` and the two literals are exactly the strings in Global Constraints (this test is the SOLA half of the shared pin); `held_ids()` returns `[]` for an empty input and when the map table is absent (guard the rest with `markTestSkipped` only where a test truly needs the PresenterAI table; the table-absent behavior must be asserted on a Moodle where the table is genuinely absent OR through a `$DB->get_manager()->table_exists()` stub: use whichever is possible, and say which in your report); when the table exists (create rows with `$DB->insert_record('presenterai_migration_map', ...)` using only the columns you read plus whatever NOT NULL columns `db/install.xml` of PresenterAI requires: read it at `/Users/tom.caswell/ai-projects/wt-presenterai-importer/db/install.xml`) a mapped id for this site is held, an unmapped id is not, a mapped id for another `sourcesiteid` is not, a map row with a different `sourcetable` is not. Cleanup tests (new test class in the same file or `tests/soapbox_cleanup_interlock_test.php`; the `soapbox_storage::is_configured()` gate means the test must either configure storage with a fake or call a seam: read `soapbox_cleanup` and choose the least invasive seam, for example making the two loops separate protected methods the test can call, and say what you chose): an expired recording held by the map is not touched (row status, `storage_key`, `deck_key`, `frames_key` all unchanged, and `soapbox_storage::delete_object` never called: use a recording test double of the storage class if the class is final-free, else assert via a spy subclass); an expired unmapped recording is retired as before; an over-quota held recording is not pruned; one audit row `soapbox_cleanup_interlock` per run with `details` = `['held' => N]` when N > 0 and none when N = 0. Run: fail.
- [ ] **Step 3: Implement.** `soapbox_cleanup` loads the candidate ids, asks `held_ids()` once per loop (not once per recording), and skips held ones in both loops; it counts the skipped ids across both loops (distinct) and writes one audit row at the end of `execute()`. Add `PRESENTERAI_INTERLOCK` with a docblock saying what depends on it (PresenterAI 0.5.0's importer refuses to run unless the constant exists).
- [ ] **Step 4: Run** the interlock tests, `soapbox_observation_retention_test`, `history_order_tiebreak_test`, `records_sql_keying_lint_test`, `lang_completeness_test`, `php -l`, line length. Prove three guards can fail: remove the `table_exists` guard, skip the held check in the pruning loop only, change the `sourcesiteid` formula. Commit ("Soapbox cleanup skips recordings PresenterAI's importer holds").

---

### Task B: The spend-cap failover uses the failover's own model and base URL

**Files:**
- Modify: `classes/provider/base_provider.php` (the CAP_BLOCKED branch of `create_from_config()`, about lines 788 to 811)
- Create: `tests/spend_cap_failover_request_test.php`

**Interfaces:**
- Consumes: `spend_guard::resolve_failover('chat')` returns `['provider', 'apikey', 'label', 'model', 'apibaseurl']` (keys `model` and `apibaseurl` may be empty strings).
- Produces: no new public API. A private static helper (name of your choice, documented) that both this path and `build_fallbacks()` can share is welcome ONLY if extracting it does not change `build_fallbacks()` behavior; otherwise duplicate the five lines and leave `build_fallbacks()` alone.

- [ ] **Step 1: Read** `base_provider.php` from the cap branch through `instantiate()`, `build_fallbacks()` (the v7.2.8 comment explains the original bug), `use_default_model()`, and `spend_guard::lookup_chat_label()`. Write in your report exactly what `$overrides` holds at the cap branch (provider, apikey, model, apibaseurl, and where the constructor reads the global `model` and `apibaseurl` from when `$overrides` lacks them) and what a failover call would send BEFORE your change.
- [ ] **Step 2: Failing tests first** (`tests/spend_cap_failover_request_test.php`): use whatever seam the repo's existing failover tests use to capture the constructed provider (read `tests/failover*_test.php` and `tests/spend_guard*_test.php`; do not invent a mock framework). Cases: (a) primary `gemini` with site model `gemini-2.5-flash` and a base URL, cap tripped, failover row `openai` with model `gpt-4o-mini` and no base URL: the built provider is OpenAI, its model is `gpt-4o-mini`, and no inherited base URL is used; (b) failover row with no model: the built provider is not asked for `gemini-2.5-flash` (it uses its own default, as `build_fallbacks()` does via `use_default_model()` when the provider differs); (c) failover row with its own base URL: that URL is used; (d) failover of the SAME provider as the primary with no model keeps the primary's model (as `build_fallbacks()` does); (e) no failover configured: `chat_refused_exception` with the unchanged message `SOLA spend cap reached for this period; no failover provider configured.`; (f) the emergency-stop path still throws its own refusal and never fails over. Run: (a)(b)(c) fail before the change.
- [ ] **Step 3: Implement** in the cap branch: set `$overrides['apibaseurl']` from the entry or unset it, set `$overrides['model']` from the entry or unset it, keep `provider` and `apikey` as now, and after `instantiate()` call `use_default_model()` when the entry has no model and its provider differs from the original provider (capture the original provider id before it is overwritten). Mirror `build_fallbacks()` exactly; do not touch the comment-explained rules there.
- [ ] **Step 4: Run** the new test, the existing failover and spend_guard test files you identified in Step 2 (one call each, read counts), `php -l`, line length. Prove two guards can fail (leave the model override out; drop the same-provider check). Commit ("A spend-cap failover now sends the failover's own model and base URL").

---

### Task C: Release (controller)

Version `7.13.0` (`2026101901`... check `version.php`; build number must be greater than `2026101803`), `README` and `CLAUDE.md` header lines, `.wiki/Changelog.md`, `.wiki/Migration-to-PresenterAI.md` and the in-product Soapbox notices updated to say the importer exists, `.drafts/v7.13.0-release-notes-and-walkthrough.md`, full suite, PR, CI, merge, tag, GitHub release, wiki push, deploy to dev then alpha, verify. No Marketplace upload, no prod.
