# Catalyst: support-hours estimate, SOLA v6.8.2 → v7.1.0

Status: DRAFT for Tom to send (Catalyst ticket + cc Artem). Prepared 2026-08-25.
Not a deployment request — this asks Catalyst to price the work first.

Before sending: re-confirm the inactivity-reminder setting on both sites (checked
2026-08-20). The longer version of this request is in git history if you want the
full scoping detail back.

---

Subject: Support-hours estimate: SOLA plugin upgrade v6.8.2 → v7.1.0 (Learn + Degrees)

Hi team,

Could you estimate the support hours to upgrade the SOLA plugin
(`local_ai_course_assistant`) on Learn and Degrees, from v6.8.2 (build
2026061702) to v7.1.0 (build 2026082501)?

Release: https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.1.0

Please take the build number from this message rather than the release page: the
release is named 7.1.0 and the build we want deployed is 2026082501.

We want the figure before booking a slot, but please include your lead time,
because this upgrade carries security fixes. An adversarial review produced five
high-severity findings that we have confirmed are present in what production runs
today — the most significant lets an enrolled learner read the text of any page or
book on the site, including hidden activities. Separately, the control that blocks
the assistant during quizzes does nothing on 6.8.2, so learners can currently use
it during any quiz or exam.

## Scope

- One plugin, one zip. Install over `local/ai_course_assistant`, run the upgrade,
  purge caches. Nothing outside the plugin directory is touched.
- 123 commits, 504 files. Fourteen upgrade steps, all additive: 3 new tables, 46
  fields, 12 keys, 7 indexes. No drops, no renames, no data migrations.
- No new server dependencies.
- 28 JavaScript bundles change, so the cache purge is required rather than
  optional.
- Rollback is reinstalling the v6.8.2 zip. Because the schema changes are additive
  only, a downgrade leaves the new columns unused rather than broken.

## Visible after upgrade

No action needed from you; listed so they are on the record.

- The assistant becomes unavailable during Moodle quizzes, on by default.
- Inactivity reminder emails stop on Learn. The off switch was being ignored;
  Learn has it unticked and Degrees ticked, so only Learn changes.
- Learning Radar charts in Redash will need an `Authorization` header, which we
  will add.
- Prompts grow modestly on activity pages.

## Questions

1. Estimated hours for both sites, including your pre-checks and post-deployment
   verification.
2. Learn and Degrees priced separately, or as one piece of work?
3. Is a staging run on a clone included in that figure, or billed on top?
4. Current lead time — and does the security content change where this sits in
   your queue?
5. If lead time is long, is it worth us preparing a reduced patch carrying only
   the highest-severity fix instead?

Happy to send the zip, a staging copy, the full release notes or the schema diff.

Thanks,
Tom
