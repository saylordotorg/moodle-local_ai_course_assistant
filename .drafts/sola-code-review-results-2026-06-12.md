# SOLA Full Code Review — Results and Recommendations

**Date:** 2026-06-12 · **Version reviewed/fixed:** 6.6.0 (2026061014)
**Method:** Four parallel review passes (security, stability/correctness, Moodle conventions, UI/UX-accessibility), each finding manually verified against source before action.

This is the internal companion to the Catalyst-facing security report
(`.drafts/sola-security-review-2026-06-12.md` / PDF). It covers all four dimensions and records what was fixed in 6.6.0 versus what is recommended follow-up.

---

## What was fixed in 6.6.0

### Security (9 of 12 findings)
- **[CRITICAL] Redash export wildcard CORS removed** — was `Access-Control-Allow-Origin: *` on a bulk PII endpoint; now opt-in single-origin only. Verified live.
- **[CRITICAL] Redash feedback/survey PII now honours anonymize** — fingerprint fields (user_agent, page_url, browser/OS/device, raw userid) dropped under the default export. Verified live.
- **[MAJOR] rate_message IDOR** — added conversation-ownership check; a learner can no longer rate/flag another learner's messages.
- **[MAJOR] Chat-greeting stored XSS** — admin greeting now rendered with textContent, not innerHTML.
- **[MAJOR] Essay-feedback reflected XSS** — AI response fields HTML-escaped before render.
- **[MAJOR] Avatar viewer guest guard** — guests rejected (feature is future-direction, not enabled).
- **[MAJOR] Meta-AI history cap** — admin endpoint history bounded to 50 turns.
- **[MINOR] i18n JSON script-injection hardening** — JSON_HEX_TAG so a translated string can't break out of the inline script tag.
- **[MINOR] Feedback comment double-encoding** display fix.

### Stability / correctness
- **[CRITICAL] Missing composite index** `(courseid, role, timecreated)` on the messages table — added via upgrade; analytics, spend-guard, and the cost-anomaly task filter on exactly these columns. Verified the index builds.
- **[MAJOR] Session-stats undercount** (shipped earlier in 6.6.0) — `get_records_sql` keyed by userid collapsed duplicate rows; now keyed by message id.
- **[MAJOR] content_indexer error handling** — `catch (\Exception)` → `\Throwable` so a provider TypeError counts as a per-module error instead of risking a half-built or wrongly-pruned index; stale-chunk cleanup switched to an O(1) hash set + streamed recordset (was O(n^2) `in_array` over a full in-memory load).
- **[MAJOR] policy_bundle atomic apply** — settings application + version stamp wrapped in a transaction so a mid-loop crash cannot leave a partially-applied bundle.
- **[MAJOR] Unbounded analytics text scans** — hotspots, common-prompts, and keyword extraction now sample the most recent 20,000 messages instead of loading every body into memory.
- **[MINOR] context_builder error handling** — three `catch (\Exception)` widened to `\Throwable`.

### Moodle conventions
- **[MAJOR] Duplicate web-service keys** in db/services.php removed (the dead first pair that lacked the mobile-service key).
- **[MAJOR] `$plugin->supported`** declaration added to version.php (4.5–5.3).
- **[MAJOR] thirdpartylibs.xml** created declaring the bundled Chart.js (MIT) — required for plugin-directory CI.
- Upgrade savepoint realigned to the version number.

---

## Recommended follow-up (not fixed in 6.6.0)

Ordered by priority. None are exploitable-as-shipped; each needs its own testing and is better as a scoped change than rushed into this release.

### High
1. **SSRF DNS-rebinding defence** (`security.php`) — pin the validated IP into the outbound call (`CURLOPT_RESOLVE`) so the host can't be re-resolved to a private address after validation. Low residual risk (admin-configured URLs, first-lookup blocklist still applies) but worth closing. Touches every provider call path → needs connectivity testing.
2. **SQL-side aggregation for the heavy analytics queries** (`analytics.php`: get_session_stats, get_time_distribution, get_messages_to_resolution, get_ai_vs_nonusers). The 20k-row sampling cap is a memory backstop, not a fix; at 100k MAU these should aggregate in the database (GROUP BY / window functions) with UTC-consistent day bucketing. Also chunk the `get_in_or_equal` over enrolled users (crashes on 50k+ enrolment).
3. **RAG retrieval memory** (`rag_retriever.php`) — loads the whole course vector set into PHP per request; fine under ~2k chunks, OOM-prone beyond. Needs a candidate cap or an approximate-nearest-neighbour path before large-course rollout.
4. **Timezone consistency** (`spend_guard.php`, `analytics.php`) — period and day boundaries use PHP server timezone in places and MySQL timezone in others; standardize on UTC as `cost_anomaly_detector` already does. Affects spend-period accounting when PHP tz ≠ UTC.

### Medium
5. **Unbounded task queries** — `reminder_manager` and `learner_weekly_digest` load all enabled/opted-in rows; push the due-time/opt-in filter into SQL with a batch LIMIT.
6. **N+1 in instructor_analytics** — `compute_mastery` per user per objective and `get_enrolled_users` inside the objective loop; bulk-fetch and hoist.
7. **PostgreSQL portability** — `analytics::get_return_rate` uses MySQL-only `FROM_UNIXTIME`; would error on a PostgreSQL Moodle. (Saylor runs MySQL/MariaDB, so not urgent.)
8. **rate_limiter is a fixed window, not sliding** — allows ~2x burst at the boundary despite the docstring; switch to a token-bucket or timestamp-eviction window.
9. **Accessibility batch** — drawer needs `aria-modal="true"` and focus placement on open; tablist buttons should use `role="tab"`/`aria-selected`; typing indicator needs an announced label; typewriter effect should respect `prefers-reduced-motion`.
10. **RTL batch (Arabic/Hebrew)** — drawer/resize/page-push use physical left/right; move to logical properties (`margin-inline-end`) and direction-aware swipe-to-close.
11. **Hardcoded English in JS/templates** — source pills, relative timestamps, several aria-labels, the avatar-picker panel, and the help button bypass i18n; route through the existing 46-language string bundle.

### Low
12. SSE inactivity timeout (silent TCP stall leaves the input locked); AudioContext close on all mouth-sync paths; remove `document`-level listeners on re-init; ResizeObserver disconnect on the consent banner; localStorage try/catch on the remaining unguarded reads; mobile touch targets to 44px; conversation-cap trim race (cosmetic off-by-one); privacy-provider metadata field name for the radar-schedule table.

---

## Notes on false positives

Several agent findings were checked and dismissed: `classify_conversation_turn` "missing get_name()" (the adhoc-task base class provides a default), `quiz.min.js.bak` "committed to git" (it is not tracked), and the `stars`/feedback `{{{ }}}` "XSS" (safe HTML entities). These were not actioned.

---

## Release gate (6.6.0 with fixes)
Security validator corpus 36/36 · jailbreak suite 100% · full PHPUnit suite · Behat browser suite · 46/46 locales complete · live verification of the two redash fixes and the new DB index.
