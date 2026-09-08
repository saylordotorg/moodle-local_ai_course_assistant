# Catalyst: support-hours estimate, SOLA v6.8.2 → v7.2.0

Status: DRAFT for Tom to send. Email to the Catalyst support address, cc Artem.
Prepared 2026-08-27. This asks Catalyst to price the work; it does not book a slot.

Before sending, re-confirm the inactivity-reminder setting on both sites — the
figure below was checked on 2026-08-20.

Figures are measured against the v7.2.0 tag, not the current main branch.

Everything from the line below is the sendable email.

---

Subject: SOLA plugin upgrade on Learn and Degrees — support-hours estimate

Hi team,

Could you give us an estimate of the support hours to upgrade the SOLA plugin
(local_ai_course_assistant) on Learn and Degrees, from v6.8.2 (build 2026061702)
to v7.2.0 (build 2026082700)?

Release notes and source:
https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.2.0

Please take the build number from this email rather than the release page title —
the release is named 7.2.0 and the build we want deployed is 2026082700.

Scope

It is a single-plugin upgrade with database upgrade steps: install over
local/ai_course_assistant, run the upgrade, purge caches. Nothing outside the
plugin directory is touched.

- 125 commits, 526 files, sixteen upgrade steps.
- All schema changes are additive: 3 new tables and 6 new columns on existing
  ones. No drops, no renames, no data migrations.
- No new server dependencies.
- 17 JavaScript bundles change, so the cache purge is required rather than
  optional.
- Rollback is reinstalling the v6.8.2 zip. Because the schema changes are
  additive only, a downgrade leaves the new columns unused rather than broken.

Worth knowing before you quote

The release carries security fixes. An adversarial review of our own code produced
five high-severity findings, and we have confirmed all five are present in the
6.8.2 source running on production today. The most significant lets an enrolled
learner read the text of any page or book on the site, including hidden activities
in their own course. There is no evidence of exploitation and we are not asking
you to treat this as an emergency, but it should inform scheduling rather than
sitting in the queue as a routine version bump.

Separately, the control that blocks the assistant during quizzes does nothing on
6.8.2 — it was gated on a condition that is always false and keyed off a value the
browser supplied. Until this deploys, learners can use the assistant during any
quiz or exam on either site, including Certificate Final Exams.

Changes visible after the upgrade

None of these need action from you; they are listed so they are on the record.

- The assistant becomes unavailable during Moodle quizzes, on by default.
- Inactivity reminder emails stop on Learn. The off switch had been ignored;
  Learn has it unticked and Degrees ticked, so only Learn changes.
- Learning Radar charts in Redash will need an Authorization header, which we add.
- The assistant now replies in the language a learner writes in.
- The plugin's own self-updater has been removed, so future upgrades go through
  Moodle's normal plugin installer.
- Course backup now carries the plugin's per-course data, so restored courses need
  a search reindex. We run that.

Questions

1. Estimated support hours for both sites, including your pre-deployment checks
   and post-deployment verification.
2. Would you price Learn and Degrees separately or as one piece of work?
3. Is a staging run on a clone included in that figure, or billed on top?
4. What is your current lead time, and does the security content change where this
   sits in your queue?

Happy to send the zip, a staging copy, the full release notes or the schema diff
if any of that helps you scope it.

Thanks,
Tom
