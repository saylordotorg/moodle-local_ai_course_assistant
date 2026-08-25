# Quote request to Catalyst: support-hours estimate for the SOLA v6.8.2 → v7.0.5 upgrade

Status: DRAFT for Tom to review and send (Catalyst ticket + cc Artem).
Prepared: 2026-08-24. Supersedes
`.drafts/catalyst-support-hours-quote-request-v7.0.1.md`, which was never sent.

This is **not** a deployment request — it asks Catalyst to price the work before
we schedule it.

Note before sending: the inactivity-reminder state below was checked on
2026-08-20. Worth re-confirming both sites' current setting before this goes out.

Everything from `---` down is the sendable message.

---

Subject: Request for a support-hours estimate: SOLA plugin upgrade v6.8.2 → v7.0.5 on Learn and Degrees

Hi team,

Could you give us an estimate of the support hours you would bill for upgrading
the SOLA plugin (`local_ai_course_assistant`) on both production sites, Learn
(learn.saylor.org) and Degrees, from the current v6.8.2 (build 2026061702) to
v7.0.5 (build 2026082400)?

We are asking for the number rather than a slot, so we can get spend approved on
our side first. That said, please see the note on timing below — this upgrade
carries security fixes, so we would like to understand your lead time in the same
reply.

Release: https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.0.5

Please take the build number from this message rather than the release page
title: the release is named 7.0.5 and the build we want deployed is 2026082400.

## Why we are asking now

v7.0.5 is a security release. An adversarial review of our own code produced
eleven findings, five of them high severity, and we have confirmed against the
v6.8.2 source that **all five are present in what is running on production
today.** The most significant lets an enrolled learner read the text of any page
or book on the site, including hidden activities in their own course, by passing
that activity's id to one of the plugin's endpoints.

We are not asking you to treat this as an emergency, and there is no evidence of
exploitation. We are telling you because it should inform how you schedule the
work rather than sitting in a queue as a routine version bump.

## What the upgrade involves, so you can scope it

Single-plugin upgrade with database upgrade steps, no core changes, no dependency
on other plugins. Same shape as the v5.4.5 → v6.8.2 upgrade you ran for us in
June, though a larger jump.

- **One plugin, one zip.** Install over the existing `local/ai_course_assistant`
  directory, run the upgrade, purge caches. No files outside the plugin directory
  are touched.
- **Schema changes are additive only.** Nine upgrade steps across the range,
  comprising 3 new tables, 46 new fields, 12 keys and 7 indexes. **No table
  drops, no field drops, no data migrations, no renames** — we checked the
  upgrade path specifically for destructive operations and there are none.
- **No new server dependencies.** Nothing new to install at the OS level. One new
  optional feature (Soapbox slide rendering) shells out to Ghostscript via
  Moodle's existing `$CFG->pathtogs`, and is gated behind a setting that is off
  by default — so it does nothing unless we enable it.
- **Nothing new defaults to on.** The upgrade adds 40 admin settings and they all
  ship off or unset. It will not switch on any new feature by itself.
- **Rollback** is reinstalling the v6.8.2 zip. Because every schema change is
  additive, a downgrade leaves the new columns unused rather than broken.
- 28 JavaScript bundles change, so a cache purge after the upgrade is required
  rather than optional.

## Behavior changes we want in writing before you quote

Three, none of which need action from you, but all of which are visible after the
upgrade.

**1. Inactivity reminder emails on Learn will stop.** A scheduled task had been
ignoring its own off switch: `inactivity_reminder_enabled` was registered in the
admin UI but read nowhere, so unchecking the box did nothing and the weekly
learner email kept going out. That is fixed in this range. When we last checked,
**Learn had that box unticked and Degrees had it ticked**, so on upgrade Learn
stops sending those emails and Degrees carries on unchanged. That is the setting
finally doing what the UI always claimed, but it is a visible change in
learner-facing email volume, so we would rather have it on the record.

**2. Learning Radar charts in Redash will need an authorization header.** Charts
created through that integration previously carried our reporting API key inside
the saved query. They no longer do, because that meant storing the credential in
plaintext in a third-party system. Existing saved queries keep working until the
key is rotated; new ones need an `Authorization: Bearer` header configured on the
Redash data source. That is our task, not yours.

**3. Support escalation now requires the learner to ask.** The assistant used to
open a support ticket whenever its own reply contained a marker, which meant text
written into course content could trigger one. It now additionally requires the
learner's own message to ask for a human. Ticket volume from the assistant may
fall as a result; that is intended.

## Questions for the quote

1. Estimated support hours for the upgrade on both sites, including your
   pre-deployment check and post-deployment verification.
2. Whether you would price Learn and Degrees separately or as one piece of work.
3. Whether a staging run on a clone is included in that figure or billed on top.
4. Your current lead time — and whether the security content changes where this
   would sit in your queue.
5. If it would materially shorten the lead time, we can prepare a reduced patch
   containing only the highest-severity fix rather than the full version upgrade.
   We would rather do the full upgrade, but tell us if that trade is worth
   pricing.

Happy to provide the zip, a staging copy, the full release notes, or the schema
diff if any of that helps you scope it.

Thanks,
Tom
