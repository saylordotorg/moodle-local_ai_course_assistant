# Catalyst: support-hours estimate, SOLA v6.8.2 → v7.1.1

Status: DRAFT for Tom to send (Catalyst ticket + cc Artem). Prepared 2026-08-26.
Not a deployment request — this asks Catalyst to price the work first.
Fuller scoping detail is in git history if they come back wanting it.

---

Subject: Support-hours estimate: SOLA plugin upgrade on Learn and Degrees

Hi team,

Could you estimate the support hours to upgrade the SOLA plugin
(`local_ai_course_assistant`) on Learn and Degrees, from v6.8.2 (build
2026061702) to v7.1.1 (build 2026082600)?

Release: https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.1.1
Please take the build number from this message rather than the release page
title.

Single-plugin upgrade with database upgrade steps: install over
`local/ai_course_assistant`, run the upgrade, purge caches. Nothing outside the
plugin directory is touched. All schema changes are additive — 3 new tables and 6
new columns, no drops or renames. No new server dependencies. Rollback is
reinstalling the v6.8.2 zip.

The release carries security fixes, so please include your current lead time with
the estimate, and let us know whether you would price the two sites separately.

Happy to send the zip, a staging copy or the full release notes.

Thanks,
Tom
