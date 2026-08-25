# Quote request to Catalyst: support-hours estimate for the SOLA v6.8.2 → v7.1.0 upgrade

Status: DRAFT for Tom to review and send (Catalyst ticket + cc Artem).
Prepared: 2026-08-25. Supersedes
`.drafts/catalyst-support-hours-quote-request-v7.0.5.md`, which was never sent.

This is **not** a deployment request — it asks Catalyst to price the work before
we schedule it.

Note before sending: the inactivity-reminder state below was checked on
2026-08-20. Worth re-confirming both sites' current setting before this goes out.

Everything from `---` down is the sendable message.

---

Subject: Request for a support-hours estimate: SOLA plugin upgrade v6.8.2 → v7.1.0 on Learn and Degrees

Hi team,

Could you give us an estimate of the support hours you would bill for upgrading
the SOLA plugin (`local_ai_course_assistant`) on both production sites, Learn
(learn.saylor.org) and Degrees, from the current v6.8.2 (build 2026061702) to
v7.1.0 (build 2026082501)?

We are asking for the number rather than a slot, so we can get spend approved on
our side first. That said, this upgrade carries security fixes, so we would like
to understand your lead time in the same reply.

Release: https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.1.0

Please take the build number from this message rather than the release page
title: the release is named 7.1.0 and the build we want deployed is 2026082501.

## Why we are asking now

Two reasons.

**Security.** An adversarial review of our own code produced eleven findings, five
of them high severity, and we have confirmed against the v6.8.2 source that all
five are present in what is running on production today. The most significant lets
an enrolled learner read the text of any page or book on the site, including
hidden activities in their own course, by passing that activity's id to one of the
plugin's endpoints. There is no evidence of exploitation, and we are not asking
you to treat this as an emergency — but it should inform scheduling rather than
sitting in a queue as a routine version bump.

**Academic integrity.** v7.1.0 makes the assistant unavailable while a learner has
a Moodle quiz attempt in progress. The control existed before but did nothing: it
was gated on a condition that was always false, it was never enforced on the
server, and it keyed off a value the browser supplied, so a second browser tab
defeated it. Until this deploys, learners can use the assistant during any quiz or
exam on either site, including Certificate Final Exams.

## What the upgrade involves, so you can scope it

Single-plugin upgrade with database upgrade steps, no core changes, no dependency
on other plugins. Same shape as the v5.4.5 → v6.8.2 upgrade you ran for us in
June, though a larger jump: 123 commits, 504 files.

- **One plugin, one zip.** Install over the existing `local/ai_course_assistant`
  directory, run the upgrade, purge caches. No files outside the plugin directory
  are touched.
- **Schema changes are additive only.** Fourteen upgrade steps across the range,
  comprising 3 new tables, 46 new fields, 12 keys and 7 indexes. **No table drops,
  no field drops, no data migrations, no renames** — we checked the upgrade path
  specifically for destructive operations and there are none.
- **No new server dependencies.** Nothing new to install at the OS level. One
  optional feature (Soapbox slide rendering) shells out to Ghostscript via
  Moodle's existing `$CFG->pathtogs`, gated behind a setting that is off by
  default.
- **Rollback** is reinstalling the v6.8.2 zip. Because every schema change is
  additive, a downgrade leaves the new columns unused rather than broken.
- 28 JavaScript bundles change, so a cache purge after the upgrade is required
  rather than optional.

## Behavior changes we want in writing before you quote

Four. None need action from you; all are visible after the upgrade.

**1. The assistant becomes unavailable during Moodle quizzes.** On by default, on
every quiz on both sites, graded or not — 3,142 quizzes on Learn and 561 on
Degrees. This is the intended change and the main reason for the release. It is
switchable site-wide, and a teacher can exempt an individual quiz. Learners with
an abandoned attempt are not affected: attempts are bounded by their own time
limit, or by a configurable window where the quiz sets none.

**2. Inactivity reminder emails on Learn will stop.** A scheduled task had been
ignoring its own off switch, so unchecking the box did nothing and the weekly
learner email kept going out. That is fixed in this range. When we last checked,
Learn had that box unticked and Degrees had it ticked, so on upgrade Learn stops
sending those emails and Degrees carries on unchanged.

**3. Learning Radar charts in Redash will need an authorization header.** Charts
created through that integration previously carried our reporting API key inside
the saved query; they no longer do, because that meant storing the credential in
plaintext in a third-party system. Existing saved queries keep working until the
key is rotated. That is our task, not yours.

**4. Prompts grow slightly on activity pages.** A wiring defect meant the current
activity's content never reached the model; it now does. Expect a modest increase
in tokens per chat turn. The read is gated by the course-pin and visibility checks
added in this range, so it cannot reach an activity a learner may not see.

## Questions for the quote

1. Estimated support hours for the upgrade on both sites, including your
   pre-deployment check and post-deployment verification.
2. Whether you would price Learn and Degrees separately or as one piece of work.
3. Whether a staging run on a clone is included in that figure or billed on top.
4. Your current lead time — and whether the security content changes where this
   would sit in your queue.
5. If it would materially shorten the lead time, we can prepare a reduced patch
   containing only the highest-severity fix rather than the full version upgrade.
   We would rather do the full upgrade, but tell us if that trade is worth pricing.

Happy to provide the zip, a staging copy, the full release notes, or the schema
diff if any of that helps you scope it.

Thanks,
Tom
