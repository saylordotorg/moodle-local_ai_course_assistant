# SOLA answer audit, guidance rules and review queue: implementation plan (8.4.0)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record every SOLA answer with its sources, check risky topics before the student relies on them, check claims in the background, and show unsupported answers to teachers.

**Architecture:** A new append-only table written from `sse.php` after each answer; a rule engine run before sending for risky questions (buffered) and right after for the rest (with a new `replace` SSE event); an adhoc task that judges claims against the retrieved chunks and writes a verdict row, a correction message and review-queue items.

**Tech Stack:** Moodle plugin PHP 8.1-8.3 (4.5-5.3), xmldb, adhoc tasks, AMD JS (src and build both committed), PHPUnit, Node checks in `tests/js`.

**Spec:** `docs/superpowers/specs/2026-11-23-answer-audit-and-guidance-rules-design.md` (approved 2026-11-23).

## Global Constraints
- Base on `main` after 8.3.2 merges; bump `version.php` to release `8.4.0` with a build number above 8.3.2's; also update the README version check and the 8.3.1/8.3.2 version-bump files (see PR #361's file list).
- No key, prompt text or system prompt in any audit row. Fail safe: any checker or rule error delivers the answer unchanged and records `check_failed`.
- Modes `off` / `watch` / `enforce`, site setting `answer_safety_mode` (default `off` on upgrade) with per-course override through `course_config_manager`. Existing `validators_runtime_mode` behavior is unchanged.
- Audit text (question, answer, claim text) is removed on conversation clear, privacy erasure, retention delete and course delete; metadata, verdicts and hashes stay, and the hash chain stays checkable through a tombstone.
- Corrections are assistant messages (`interaction_type` `correction`), never counted toward the conversation cap, skipped if the conversation is gone, rate-limited per student.
- Judge spend goes through `conversation_manager::log_ancillary_usage` with interaction type `answer_check`, added to `analytics::spend_rows_predicate()`; no check runs when `spend_guard::check()` is not `CAP_OK`.
- Wellbeing and crisis safe message is fixed default text (admin-editable): points to the instructor or support, and to local emergency services for immediate danger. No per-country resource list.
- Rules written by site admins only; keyword plus a restricted pattern syntax with length and time limits.
- New strings in `lang/en` only; privacy provider covers the new table; AMD source and `amd/build` change together.

## Review Focus
1. A student clears the chat while a claim check is queued: the task must find nothing to check, write no text, send no correction.
2. Non-English questions and answers: rules must not error and must not block on English-only patterns; the claim check still runs.
3. Judge returns malformed JSON or the provider is down: answer stays as delivered, row verdict `check_failed`, no student-facing change.
4. Buffered (risky) answers: the SSE connection must not time out while held; send keepalives.
5. Two answers in one course at the same moment: the hash chain must not fork or skip.

## File structure
- Create `classes/answer_audit.php` (store, chain, redact, export), `classes/answer_rules.php` (rulebook, question categories, evaluate), `classes/claim_checker.php`, `classes/correction_notice.php`, `classes/task/check_answer_claims.php`, `classes/admin_setting_answer_rules.php` (rule rows), `answer_audit.php` (page + export), `templates/answer_audit.mustache`, `admin/cli/answer_safety_eval.php`, `tests/answer_audit_test.php`, `tests/answer_rules_test.php`, `tests/claim_checker_test.php`, `tests/correction_notice_test.php`, `tests/task/check_answer_claims_test.php`, `tests/review_queue_answer_sources_test.php`, `tests/js/answer-replace-check.js`, `tests/fixtures/answer_safety/` (known good/bad answers).
- Modify `db/install.xml`, `db/upgrade.php`, `version.php`, `sse.php`, `classes/runtime_guard.php`, `classes/conversation_manager.php`, `classes/observer.php`, `classes/task/conversation_retention.php`, `classes/privacy/provider.php`, `classes/review_queue.php`, `instructor_dashboard.php`, `templates/instructor_dashboard.mustache`, `classes/analytics.php`, `settings.php`, `classes/settings_layout_map.php`, `amd/src/sse_client.js`, `amd/src/chat.js`, `amd/src/ui.js` (+ `amd/build`), `tests/js_guards_ci_wiring_test.php`, `lang/en/local_ai_course_assistant.php`, README.

### Task 1: Audit table and store
**Files:** Create `classes/answer_audit.php`, `tests/answer_audit_test.php`; modify `db/install.xml`, `db/upgrade.php`, `version.php`.
**Interfaces:** Produces `answer_audit::record(array $row): int` (row keys: messageid, conversationid, userid, courseid, cmid, mode, question, answer, model, provider, chunks [{cmid, score, ref}], citations, categories, rules [{id, action}], validators); `append_verdict(int $auditid, array $verdict): int` (verdict keys: status ok|warn|blocked|needs_review|check_failed, severity, claims [{text, type, status, evidence_ref, severity}], judge_model, judge_cost); `redact_for_messages(array $msgids): int`, `redact_for_conversation(int): int`, `redact_for_user(int): int`, `redact_for_course(int $courseid, ?int $userid = null): int`; `verify_chain(int $courseid): array{ok: bool, brokenat: ?int}`; `rows_for_export(int $courseid, array $filters): array`.
- [ ] Step 1: Write `answer_audit_test` with: `test_record_stores_row_and_hash`, `test_chain_links_previous_row`, `test_verdict_appends_row_and_leaves_original`, `test_verify_chain_detects_edited_row`, `test_redact_drops_text_keeps_metadata_and_chain_still_verifies`, `test_two_records_same_course_do_not_fork_chain` (use `\core\lock` factory path), `test_record_never_stores_keys` (assert a row containing an `apikey` field is rejected).
- [ ] Step 2: Run `vendor/bin/phpunit --filter answer_audit_test` (see memory: php@8.3 first on PATH); expect FAIL, class missing.
- [ ] Step 3: Add table `local_ai_course_assistant_answer_audit` to `install.xml` and an upgrade block (follow the `model_switch` pattern; index on courseid+id, messageid, userid); bump version. `rowhash` = sha256 of canonical JSON of the row's fixed fields plus the previous row hash for the course; take a per-course lock around read-previous-and-insert. Redaction nulls question/answer/claim text, sets `redacted=1`, and keeps a `texthash` so `verify_chain` hashes `texthash`, not the text.
- [ ] Step 4: Implement the class; run the test; expect PASS.
- [ ] Step 5: Commit `feat: answer audit table and store`.

### Task 2: Tombstone on every delete path and privacy provider
**Files:** Modify `classes/conversation_manager.php` (cap trim `:229`, `clear_conversation`, `delete_user_data`, `delete_course_data`), `classes/task/conversation_retention.php`, `classes/observer.php`, `classes/privacy/provider.php`, `classes/demo_seeder.php`; test `tests/answer_audit_test.php` additions.
**Interfaces:** Consumes Task 1 redact methods.
- [ ] Step 1: Tests: `test_clear_conversation_redacts_audit_text`, `test_user_deletion_redacts_and_keeps_chain`, `test_retention_task_redacts`, `test_course_deletion_redacts`, `test_cap_trim_redacts_trimmed_messages`, `test_privacy_export_contains_audit_metadata_for_user`, `test_privacy_delete_removes_text`.
- [ ] Step 2: Run them; expect FAIL.
- [ ] Step 3: Call the matching redact method before each `msgs` delete listed above; in the privacy provider add `get_metadata` entry (lang `privacy:metadata:answer_audit`), export via `$latecoverage`, and add to both `$courseidkeyed` lists.
- [ ] Step 4: Run; expect PASS. Step 5: Commit.

### Task 3: Record each answer from sse.php; give runtime_guard the chunks
**Files:** Modify `sse.php` (after `:1145`, once `$assistantmsgid` exists), `classes/runtime_guard.php`; test `tests/answer_audit_test.php` additions.
**Interfaces:** `runtime_guard::apply(string $response, array $context = []): string` now receives `rag_chunks` (chunk text array) from `$retrievedchunks`; add `runtime_guard::last_results(): array` returning the validator results of the last call (for the audit row).
- [ ] Step 1: Tests: `test_runtime_guard_gets_rag_chunks_so_numeric_check_runs` (an answer with a year absent from the chunks yields a WARN result), `test_last_results_lists_validators`, `test_sse_records_row_for_assistant_message` (drive through the message-save helper the sse flow uses; if none exists, extract `local_ai_course_assistant_record_answer(...)` in `sse.php` and test that).
- [ ] Step 2: Run; FAIL. Step 3: Implement; build chunk entries from `$retrievedchunks` (`cmid`, `score`, and the chunk's own id key if `rag_retriever` returns one, otherwise a sha1 of its text as `ref`). Record only when `answer_safety_mode` is not `off`. Step 4: PASS. Step 5: Commit.

### Task 4: Rule engine
**Files:** Create `classes/answer_rules.php`, `classes/admin_setting_answer_rules.php`, `tests/answer_rules_test.php`, `tests/fixtures/answer_safety/*.json`; modify `settings.php`, `classes/settings_layout_map.php`, `lang/en/...`.
**Interfaces:** `answer_rules::mode(int $courseid): string`; `categories_for_question(string $q): string[]` (grades, deadlines, policy, wellbeing, advice, integrity, numbers); `is_risky(array $categories): bool`; `evaluate(string $reply, array $ctx): array{action: string, rules: string[], text: ?string}` where action is none|watch|warn|correct|block and `ctx` has `question`, `categories`, `chunks`, `buffered` (bool); `safe_message(string $category): string`; `validate_rule(array $rule): array` (errors).
- [ ] Step 1: Tests: one per category with a bad and a good sample from fixtures; `test_wellbeing_question_always_blocks_with_safe_text_in_enforce`, `test_watch_mode_never_changes_text`, `test_correct_downgrades_to_warn_when_not_buffered`, `test_non_english_input_does_not_error_or_block_on_english_patterns`, `test_pattern_over_length_or_time_limit_is_rejected`, `test_existing_block_mode_still_wins_when_stricter`.
- [ ] Step 2: FAIL. Step 3: Implement default rule pack in code plus admin rows; pattern syntax = keywords and `preg` limited to 200 characters, checked against a 50 ms budget on a sample. Settings: `answer_safety_mode`, per-course override key, editable safe-message text. Step 4: PASS. Step 5: Commit.

### Task 5: Enforcement in sse.php (buffered path and replace event)
**Files:** Modify `sse.php`, `amd/src/sse_client.js`, `amd/src/chat.js`, `amd/src/ui.js`, `amd/build/*`; create `tests/js/answer-replace-check.js`; modify `tests/js_guards_ci_wiring_test.php`.
**Interfaces:** New SSE payload `{replace: string, messageid: int, reason: string}`; `sse_client` calls `onReplace(data)`; `chat.js` swaps the message text and marks it with `data-replaced`.
- [ ] Step 1: `answer-replace-check.js` (fake DOM like `truncated-note-check.js`): replace swaps text once, ignores a replace for another message id, works in src and build; planted-defect check that removing the handler fails it. Add the script to `js_guards_ci_wiring_test`.
- [ ] Step 2: Run `node tests/js/answer-replace-check.js`; FAIL. Step 3: Implement client; in `sse.php`, when `categories_for_question` is risky and mode is `enforce`, hold tokens (send keepalive comments every 10 s), evaluate, then send the final text (corrected once or blocked); otherwise stream as today, evaluate after, and send `replace` on block. Step 4: PASS plus rebuild AMD (`grunt amd` as in earlier releases); commit src and build.
- [ ] Step 5: PHPUnit: `test_buffered_path_sends_keepalive_before_text` if the sse send function can be exercised; otherwise cover through a live check in Task 10.

### Task 6: Claim check task
**Files:** Create `classes/claim_checker.php`, `classes/task/check_answer_claims.php`, `tests/claim_checker_test.php`, `tests/task/check_answer_claims_test.php`; modify `sse.php` (queue after record), `classes/analytics.php`, `settings.php`.
**Interfaces:** `claim_checker::judge(string $question, string $answer, array $chunks, int $courseid): array{claims: array, status: string, severity: string}`; `claim_checker::severity_for(array $claim, array $categories): string`; task custom data `['auditid' => int]`; settings `answer_check_sample_percent` (default 100 with retrieval, 10 without) and `answer_check_model`/provider reuse the `mastery_classifier_*` resolution pattern.
- [ ] Step 1: Tests using `stub_provider::program_response`: three-way statuses (supported, true-but-not-in-course, contradicted); `test_malformed_json_records_check_failed`; `test_provider_error_records_check_failed_and_delivers_nothing_to_student`; `test_no_check_when_spend_guard_blocks`; `test_cleared_conversation_task_is_noop` (Review Focus 1); `test_sampling_skips_unretrieved_answers`; `test_date_claim_contradicted_is_high_severity`.
- [ ] Step 2: FAIL. Step 3: Implement with `chat_completion(..., ['response_schema' => $schema])`; append verdict via `answer_audit::append_verdict`; log spend as `answer_check`. Step 4: PASS. Step 5: Commit.

### Task 7: Correction message
**Files:** Create `classes/correction_notice.php`, `tests/correction_notice_test.php`; modify `classes/conversation_manager.php` (cap trim must skip `correction` rows), `classes/task/check_answer_claims.php`.
**Interfaces:** `correction_notice::send(int $auditid): ?int` returns the new message id or null (skipped).
- [ ] Step 1: Tests: sends only for severity high with status contradicted; stored with `interaction_type` `correction`; not counted by the cap; skipped when the conversation was cleared; at most one per student per 10 minutes; appears in the next history load.
- [ ] Step 2: FAIL. Step 3: Implement (wording from language strings). Step 4: PASS. Step 5: Commit.

### Task 8: Review queue sources and outcomes
**Files:** Modify `classes/review_queue.php`, `instructor_dashboard.php`, `templates/instructor_dashboard.mustache`, `db/install.xml` and `db/upgrade.php` (add `outcome` char(20) to `review_res`), lang; test `tests/review_queue_answer_sources_test.php`.
**Interfaces:** `pending_for_course` rows may have `source` `claim` or `rule`; `mark_resolved(string $source, int $sourceid, int $courseid, int $userid, string $note = '', string $outcome = ''): void` with outcomes confirmed_bad, false_alarm, content_gap, rule_too_strict.
- [ ] Step 1: Tests: claim and rule items appear for the right course only; resolving stores outcome; unknown outcome rejected; redacted rows show "text removed" and still list verdict. Step 2: FAIL. Step 3: Implement (whitelist, new query blocks, dashboard columns, outcome select). Step 4: PASS. Step 5: Commit.

### Task 9: Audit page, export and settings group
**Files:** Create `answer_audit.php`, `templates/answer_audit.mustache`; modify `settings.php`, `classes/settings_layout_map.php`, lang.
**Interfaces:** Page `/local/ai_course_assistant/answer_audit.php?courseid=N` with filters (verdict, rule, date), detail view of one answer, `&format=csv|json` export; teachers see their course, site admins any course.
- [ ] Step 1: Tests: capability check refuses a student and another course's teacher; export respects filters and omits redacted text; export includes `verify_chain` result in a header row. Step 2: FAIL. Step 3: Implement. Step 4: PASS. Step 5: Commit.

### Task 10: Evaluation, live check, release
**Files:** Create `admin/cli/answer_safety_eval.php`; `.drafts/v8.4.0-release-notes-and-walkthrough.md`; modify README.
- [ ] Step 1: CLI runs the fixture set through rules and the judge (stub or real provider by flag) and prints false-alarm rate, added latency and cost per 1,000 answers.
- [ ] Step 2: Run the full PHPUnit suite, all `tests/js` checks, `sola-reviewer` agent; fix findings.
- [ ] Step 3: On dev (watch mode first): ask five risky questions and five normal ones, confirm rows, queue items, a block with the safe message, a `replace` on a streamed answer, a correction; record what you saw in the release notes. Not verified items stay listed as not verified.
- [ ] Step 4: PR, CI, merge, tag, release, deploy dev then alpha, Marketplace notes under 10,000 characters.
- [ ] Step 5: Have a Saylor student-support person read the default wellbeing message before enforcement is turned on anywhere; note that in the release notes.
