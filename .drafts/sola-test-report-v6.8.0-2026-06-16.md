# SOLA v6.8.0 — Full Test Report

Date: 2026-06-16. Scope: the v6.8.0 release (white-label branding) on top of the v6.7.x line (Soapbox speech practice + ESL levels, voice reserved-token fix). Run on local Moodle 4.5.10 + MySQL (PHP 8.3) plus the GitHub Actions CI matrix.

## 1. Automated test suites — results

| Suite | Result |
|---|---|
| **PHPUnit (full plugin)** | **549 tests, 1306 assertions, 0 failures, 1 skipped** |
| **Security validator corpus** (`run_validators.php`) | **36 passed, 0 failed** |
| **i18n completeness** | **46/46 locales, 0 missing keys** (1416 EN keys) |
| **PHP lint** | clean on every tracked `.php` file (incl. all 46 lang files) |
| **CI matrix** (on the v6.8.0 merge commit) | green: PHP 8.1 / 8.2 / 8.3 × MySQL/MariaDB + PostgreSQL, Mustache/Grunt/codechecker, Behat |
| **Live jailbreak suite** (32 prompts) | NOT run locally — needs a live LLM key (the local provider key was unavailable in this environment). The static security corpus (validators, 36/0) passed; the generated system prompt is verified substantively identical for Saylor defaults. Re-run on dev before/with the production decision. |

The 1 skipped PHPUnit test is a pre-existing environment-gated skip (not a failure).

## 2. New tests added this round (+19)

Closing the biggest gaps in the recently-shipped features:

- **`tests/rubric_manager_test.php` (11 tests)** — first coverage for `rubric_manager` at all. `TYPE_SPEECH`, `DEFAULT_SPEECH_CRITERIA` shape, `get_default_criteria` by type, the four `speech_presets()` levels (general / esl_beginner / esl_intermediate / esl_advanced), `speech_preset()` resolution + safe fallback, `ensure_default_rubrics()` seeding the global speech rubric, `get_active_rubric()` global fallback, `save_score()` persisting (and null-defaulting) the v6.7.0 `session_meta` blob, and `get_user_scores()` decoding.
- **`tests/get_realtime_token_test.php` (2 tests)** — the v6.7.2 voice fix: asserts the realtime `instructions` contain no OpenAI reserved special token (`<|...|>`), that the cited `<|im_start|>` is neutralized to `[special token]`, and that the voice-mode tail + `SOLA_NEXT` marker survive. Uses the built-in `$test_http_response` mock (no network).
- **`tests/score_speech_test.php` (4 tests)** — the Soapbox scoring gate: returns `disabled` when off, `too_short` under the 40-character boundary, and a stable return shape. (The LLM scoring path needs a live provider and is covered by the functional smoke.)
- **`tests/context_builder_branding_test.php` (2 tests)** — the v6.8.0 integration: a rebrand (`ZBRANDX` / `Zeta University`) reaches the generated system prompt, the default resolves to SOLA branding, and no `[[token]]` ever leaks into what the LLM receives.

Plus the pre-existing `tests/branding_test.php` leak-guard (every EN lang string resolves with no leftover token after `apply()`).

## 3. Issue found and fixed during testing

- **`score_speech` used the deprecated global `external_api` alias** (`use external_api;` + `require_once externallib.php`) rather than `core_external\external_api`. This failed PHPUnit autoload and risked breaking on Moodle 5.x, where the global aliases are removed. Modernized to `core_external\…` (matching `get_realtime_token`). Confirmed it was the **only** external function with the old alias.

## 4. Functional smoke (HTTP, local Moodle, default SOLA branding)

| Path | Result |
|---|---|
| Course page widget (course 2) | 200, `data-shortname="SOLA"`, **0 token leaks** |
| Soapbox page (feature on) | 200, renders setup panel + record button, **0 leaks** |
| Soapbox page (feature off) | error page (the off-by-default per-course gate correctly blocks access) |
| Rubric admin, speech type, `?preset=esl_intermediate` | 200, loads the ESL-intermediate sample criteria, **0 leaks** |
| Course settings (Soapbox toggle + level select) | 200, **0 leaks** |
| Admin settings (general section, all brand-bearing descriptions) | renders SOLA resolved, **0 leaks** across every section |

**Rebrand verification**: setting the four branding values to a test brand (Acme University / Acme / Acme Study Buddy / ASB) propagates completely — widget, greeting, admin settings copy, and the system prompt all switch to the new names with zero residual SOLA and zero `[[token]]` leaks. Reverting to unset renders SOLA/Saylor identically.

**Dev fleet**: v6.7.2 and v6.8.0 each deployed to all 5 dev sites (dev / 405 / 500 / 501 / 503) with BUS101 smoke OK; a public dev page (privacy notice) confirmed 0 token leaks.

## 5. Coverage map

Well-covered (unit + functional): branding token system, rubric_manager + Soapbox scoring gate, realtime instruction sanitization, context_builder prompt assembly + branding, spend guard / emergency control / policy bundle / cost anomaly / premium router / voice registry / providers / analytics / privacy (existing suites).

Covered by functional smoke only (need a live LLM or browser): the LLM scoring path in `score_speech`, the in-browser recording → STT → feedback round trip on `soapbox.php`, and the OpenAI Realtime voice session. These are exercised manually on dev.

## 6. Verdict

All automated suites green (549 PHPUnit, 36 validators, 46/46 i18n, lint, CI matrix). One latent Moodle-5.x compatibility bug found and fixed. Functional smoke green with zero brand-token leaks on every surface and the off-by-default gates working. **Recommended GO for the v6.8.0 tag and GitHub release**, with the live jailbreak suite to be re-run on dev as the final pre-production check.
