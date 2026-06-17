# CONTRIB-10574 remediation — status / handoff

Branch: `fix/moodle-plugin-review-contrib-10574` (off origin/main @ v6.8.0).
Version bumped to **6.8.1** (`2026061700`). No schema change, no upgrade savepoint needed.

Moodle plugin-directory reviewer (Volodymyr Dovhan) filed 29 GitHub issues
(#68-#96). The review was run against the PUBLISHED directory version, which
lagged origin/main, so several findings were already fixed on main. Every issue
was verified against current code before acting.

## Validation done (local Moodle 4.5 + MySQL, PHP 8.3)
- **PHPUnit full plugin suite: 549 tests, 0 failures, 1 pre-existing skip.**
- **Security validators: 36 passed, 0 failed.**
- **i18n completeness: passes** (new referenced strings all defined).
- **PHP lint: clean on all 50 changed files. node --check clean on all changed JS.**
- Re-run after each batch; green throughout.

## Status by issue (26 of 29 done; 3 large UI refactors documented for follow-up)

| # | Title | Pri | State |
|---|-------|-----|-------|
| 68 | External svc capability validation | BLOCKER | DONE — all 40 external fns now enforce a capability |
| 69 | LICENSE file | BLOCKER | DONE |
| 70 | PARAM_RAW | HIGH | DONE — justified JSON/secret cases; model→PARAM_TEXT |
| 71 | External API privacy link | HIGH | DONE — ai/voice/zendesk/radar external-location links |
| 72 | Frankenstyle global funcs | HIGH | DONE |
| 73 | Capability checks on pages | HIGH | DONE — consent guest guard (viewer already hardened on main) |
| 74 | mkdir 0777 | HIGH | DONE — all 5 extractor paths |
| 75 | print_error | HIGH | ALREADY FIXED on main |
| 76 | Direct config_plugins access | HIGH | DONE — config API in upgrade.php + digest task |
| 77 | Privacy email_optout table | HIGH | ALREADY FIXED on main (v5.10.1) |
| 78 | Templates / Output API | MEDIUM | DONE for vendor_dpa + privacy (instructor_dashboard left: interactive, needs visual QA) |
| 79 | Hard-coded strings | MEDIUM | DONE — benchmark + vendor_dpa headers/labels (19 keys) |
| 80 | N+1 DB in loops | MEDIUM | DONE — demo_seeder preload; export loops annotated bounded |
| 81 | snake_case naming | MEDIUM | DONE for flagged vars (full phpcs sweep optional) |
| 82 | File API temp dirs | MEDIUM | DONE — all temp dirs via make_*_directory |
| 83 | curl_init -> \curl | MEDIUM | DONE — **streaming SSE path needs a dev smoke test** |
| 84 | $_SESSION -> Cache API | MEDIUM | DONE — MODE_SESSION 'uistate' cache |
| 85 | Legacy JS (cdn/services) | MEDIUM | DONE — external build-sources excluded from zip |
| 86 | AJAX -> external services | MEDIUM | DONE — consent + radar_cite are external services (transcribe stays AJAX) |
| 87 | Inline CSS -> styles.css | MEDIUM | DONE — 6 admin pages; export doc justified inline |
| 88 | Boilerplate headers | MEDIUM | DONE — 6 JS files (all PHP already had them) |
| 89 | Comprehensive i18n | MEDIUM | DONE — provider_benchmark strings |
| 90 | Harden exec/proc_open | MEDIUM | DONE — array-form pdftotext, consistent escaping |
| 91 | Autoloading vs require_once | MEDIUM | DONE — removed redundant class requires |
| 92 | $USER mutation | MEDIUM | DONE |
| 93 | $DB->execute -> set_field | MEDIUM | DONE — set_field_select |
| 94 | innerHTML -> templates in JS | LOW | DONE for the 3 flagged modules (audio_player/learning_radar/analytics_dashboard) |
| 95 | Course picker recordset | LOW | DONE — capped get_recordset |
| 96 | Missing lang strings | - | DONE — cachedef_* strings |

## UPDATE 2026-06-17: the 3 deferred refactors are now done in this PR

- **#86 done.** consent.php + radar_cite.php are gone; replaced by external\record_consent
  and external\get_radar_citation (db/services.php), called via core/ajax / Ajax.call.
  4 new PHPUnit tests. transcribe.php stays AJAX (binary upload).
- **#94 done** for the three cited modules via core/templates + new mustache templates
  (audio_button_icon, radar_citation, analytics_message). AMD rebuilt; templates render-verified.
- **#78 done** for vendor_dpa.php and privacy.php (templates + Output API + 19 lang strings
  + CSS tone classes). instructor_dashboard.php (interactive, 5 sections) is the one
  remaining echo-HTML page — left for a pass with visual QA.
- **#83 streaming smoke PASSED on dev** (openai_provider, 9-10 chunks, coherent reply).

Full regression after all of the above: PHPUnit 553/0 (1 skip), validators 36/0.

## Original deferral notes (kept for reference)
## Deferred items — why, and the plan

These three are MEDIUM/LOW UI refactors that change the learner-facing widget /
admin-page rendering and MUST be visually verified in a browser. They were not
done blind overnight because a wrong template/AJAX rewire breaks the live widget
or SSE/voice path with no automated signal to catch it.

- **#78 Templates / Output API + #79 remainder.** Migrate the admin pages that
  echo HTML (vendor_dpa, instructor_dashboard, the *_admin pages) to Mustache +
  `$OUTPUT->render_from_template()`, moving their hard-coded English into lang
  strings as part of the same pass. Plan: one template per page under
  `templates/`, build a `$templatedata` array in PHP, render once. Verify each
  page renders identically. Largest single item.
- **#86 AJAX_SCRIPT -> External Services.** consent.php and radar_cite.php convert
  cleanly to `classes/external/*` + `db/services.php` + `core/ajax` from JS (then
  rebuild AMD). transcribe.php is a binary audio upload — keep it as an
  AJAX_SCRIPT (it already enforces sesskey + SSRF + rate limit + capability) and
  document why, since external services are a poor fit for raw file uploads.
- **#94 innerHTML -> templates in JS.** audio_player.js / analytics_dashboard.js /
  learning_radar.js build small HTML via innerHTML; move to
  `Templates.render()` from `core/templates`. Rebuild AMD, browser-verify.

## Also needs a browser check before merge
- **#83 streaming smoke test:** base_provider::http_post_stream now streams via
  Moodle \curl (CURLOPT_WRITEFUNCTION). Send one live chat turn on dev and
  confirm tokens stream as before.

## Commits (chronological)
license/strings/temp/dml; curl->\curl; capability+PARAM_RAW; privacy links;
frankenstyle+config-api; session-cache; File-API/exec/recordset; docx/pptx temp;
autoloading; JS boilerplate; zip exclusions; inline-CSS+benchmark-i18n; naming+seeder.
