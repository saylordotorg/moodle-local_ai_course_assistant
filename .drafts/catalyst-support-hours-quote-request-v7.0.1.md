# Quote request to Catalyst: support-hours estimate for the SOLA v6.8.2 → v7.0.1 upgrade

Status: DRAFT for Tom to review and send (Catalyst ticket + cc Artem).
Prepared: 2026-08-20.

This is **not** a deployment request — it asks Catalyst to price the work before
we schedule it. The sendable deploy request for when the quote is agreed is
`.drafts/catalyst-prod-deploy-request-v7.0.0.md`, which needs its build number
moved from 2026082002 to 2026082003 before it goes out.

Everything from `---` down is the sendable message.

---

Subject: Request for a support-hours estimate: SOLA plugin upgrade v6.8.2 → v7.0.1 on Learn and Degrees

Hi team,

Before we schedule anything, could you give us an estimate of the support hours
you would bill for upgrading the SOLA plugin (`local_ai_course_assistant`) on
both production sites, Learn (learn.saylor.org) and Degrees, from the current
v6.8.2 (build 2026061702) to v7.0.1 (build 2026082003)?

We are asking for the number, not for a slot, so that we can get the spend
approved on our side first.

Release: https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.0.1

Please take the build number from this message rather than from the release page
title: the release is named 7.0.1 and the build we want deployed is 2026082003.

## What the upgrade involves, so you can scope it

It is a single-plugin upgrade with a database upgrade step, no core changes and
no dependency on other plugins. Same shape as the v5.4.5 → v6.8.2 upgrade you
ran for us in June.

- **One plugin, one zip.** Install over the existing `local/ai_course_assistant`
  directory, run the upgrade, purge caches. No files outside the plugin
  directory are touched.
- **Schema changes.** The upgrade step adds columns and indexes only; there are
  no destructive migrations and no table drops. We have run the one-jump
  v6.8.2 → v7.0.1 upgrade on a copy and `admin/cli/check_database_schema.php`
  comes back clean.
- **No new server dependencies.** Nothing new to install at the OS level. The
  optional features that need a binary (`pdftotext` for PDF indexing) are the
  same ones as in v6.8.2 and are off unless enabled.
- **No configuration migration needed.** Existing settings carry across. Two
  settings were removed in v7.0.0 because nothing read them
  (`failover_timeout_voice`, and two dead talking-avatar credential fields);
  their rows can be left in place harmlessly or cleaned up, your preference.
- **Rollback** is reinstalling the v6.8.2 zip. The added columns are additive,
  so a downgrade leaves them unused rather than broken.

## One behavior change we want to flag before you quote

In v7.0.0 a scheduled task that had been ignoring its own off switch started
honoring it. `inactivity_reminder_enabled` was registered in the admin UI but
read nowhere, so unchecking the box did nothing and the weekly learner email
kept going out.

We checked the current state of both sites: **Learn has that box unticked and
Degrees has it ticked.** So on upgrade, Learn will stop sending inactivity
reminder emails and Degrees will carry on unchanged. That is the setting doing
what the UI always said it did, but it is a visible change in learner-facing
email volume on Learn, so we wanted it in writing before the work is priced
rather than discovered afterwards. No action needed from you — we will decide
whether to tick Learn's box before or after the upgrade.

## What is in v7.0.1 specifically

v7.0.1 is a patch on top of v7.0.0 and does not change the deployment shape. It
is mostly two things:

1. **Internationalization completed.** 86 admin settings had their titles and
   descriptions written as hardcoded English, which meant they could not be
   translated at all. Those are now proper language strings, translated into all
   45 non-English locales the plugin ships — about 7,650 strings.
2. **Moodle Marketplace review remediation.** We are listing the plugin in the
   Marketplace and the reviewer returned a findings report. The substantive
   fixes are a CSS rule that was leaking site-wide, tightened request-parameter
   validation across the admin endpoints, moving CLI run artifacts out of the
   web-accessible plugin directory into `dataroot`, and routing one remaining
   raw cURL call through Moodle's `\curl` wrapper so it honors the site proxy
   and SSRF allowlist.

Nothing in either item changes how the plugin is installed or upgraded.

## Questions for the quote

1. Estimated support hours for the upgrade on both sites, including your
   pre-deployment check and post-deployment verification.
2. Whether you would price Learn and Degrees separately or as one piece of work.
3. Whether a staging run on a clone is included in that figure or billed on top.
4. Your current lead time, so we know what window we are approving spend for.

Happy to provide the zip, a staging copy, or anything else that helps you scope
it. If it is easier to price against the release notes directly, they are on the
release page linked above.

Thanks,
Tom
