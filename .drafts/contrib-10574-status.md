# CONTRIB-10574 remediation — status

Branch: `fix/moodle-plugin-review-contrib-10574` (off origin/main @ v6.8.0).
Worktree: `.claude/worktrees/contrib-10574`. One PR planned when complete.

Moodle reviewer (Volodymyr Dovhan) filed 29 GitHub issues (#68-#96). The review
was run against the PUBLISHED directory version, which lags origin/main — so a
few findings were already fixed on main. Each issue is verified against current
code before fixing.

Approach chosen by Tom: everything in one branch, full proper migration.
Constraints honoured: no deploy/tag/release autonomously; lint each change; keep
the plugin working at every commit; US spelling; no em-dashes/emojis in output.

## Status by issue

| # | Title | Priority | State |
|---|-------|----------|-------|
| 68 | External svc param/context/capability | BLOCKER | TODO |
| 69 | LICENSE file | BLOCKER | DONE (c1) |
| 70 | PARAM_RAW | HIGH | TODO |
| 71 | External API privacy link | HIGH | TODO |
| 72 | Frankenstyle prefix global funcs | HIGH | TODO |
| 73 | Capability checks on pages | HIGH | TODO |
| 74 | mkdir 0777 | HIGH | DONE (c1) |
| 75 | print_error | HIGH | ALREADY FIXED on main |
| 76 | Direct config_plugins access | HIGH | TODO (verify scope) |
| 77 | Privacy email_optout table | HIGH | TODO |
| 78 | Templates/Output API migration | MEDIUM | TODO (large) |
| 79 | Hard-coded strings | MEDIUM | TODO |
| 80 | N+1 DB in loops | MEDIUM | TODO |
| 81 | snake_case naming | MEDIUM | TODO |
| 82 | File API temp dirs | MEDIUM | PARTIAL (extractors done c1; radar_delivery/plugin_updater TODO) |
| 83 | curl_init -> \curl | MEDIUM | DONE (c2) — streaming path needs dev smoke |
| 84 | $_SESSION -> Cache API | MEDIUM | TODO |
| 85 | Legacy JS (cdn/ shims, worker.js) | MEDIUM | TODO (check zip exclusion) |
| 86 | AJAX_SCRIPT -> external services | MEDIUM | TODO (large) |
| 87 | Inline CSS -> styles.css | MEDIUM | TODO |
| 88 | Boilerplate headers | MEDIUM | TODO |
| 89 | Comprehensive i18n | MEDIUM | TODO |
| 90 | Harden exec/proc_open | MEDIUM | PARTIAL (file_extractor pdf done c1; integrity_checker TODO) |
| 91 | Autoloading vs require_once | MEDIUM | TODO |
| 92 | $USER mutation | MEDIUM | DONE (c1) |
| 93 | $DB->execute -> set_field | MEDIUM | DONE (c1) |
| 94 | innerHTML -> templates in JS | LOW | TODO |
| 95 | Course picker recordset | LOW | TODO |
| 96 | Missing lang strings (cachedef_*) | - | DONE (c1) |

Commits: c1 = 93ef9b6, c2 = 8a68379.

## Notes / decisions
- #75 already fixed on main (flashcards.php uses moodle_exception; string exists).
- #96 reduced to the 5 cachedef_* strings (en only; Moodle falls back to en;
  completeness test only checks referenced keys). flashcards_disabled stale.
- #83 base_provider streaming migrated; MUST be streaming-smoke-tested on dev.
- pin_curl_handle() now unused by callers but kept as public back-compat helper.
