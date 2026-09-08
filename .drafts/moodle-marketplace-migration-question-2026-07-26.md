# Question to Moodle — in-flight approval during the Marketplace migration

**Send this before uploading v6.9.4 anywhere.** It resolves which route applies, so we do not create a duplicate listing or upload into a queue nobody is reading.

**Where to send:** the Marketplace support request (Jira Service Desk, linked as "Create a support request" from https://marketplace.moodle.com/). If that form does not fit an existing-plugin question, post the same text as a comment on https://moodle.atlassian.net/browse/CONTRIB-10574 — the reviewer note already carries a shortened version of the same question as a fallback.

**Why we are asking:** `moodle.org/plugins` now redirects to `marketplace.moodle.com`, moodledev.io marks the old contribution process as legacy, and the launch communications we found describe onboarding for *paid* plugins. None of the public documentation covers a plugin that was already mid-approval in the legacy queue, which is our situation.

---

Subject: Plugin in initial approval (CONTRIB-10574) — correct route after the Marketplace migration?

Hello,

We maintain **local_ai_course_assistant** (SOLA — Saylor Online Learning Assistant), a free GPLv3 local plugin from Saylor Academy. It is currently in **initial approval** under CONTRIB-10574: the reviewer filed 29 issues, all of which we resolved, and we are ready to submit our current release (v6.9.4) for re-review.

With the Plugins Directory now moved to Moodle Marketplace, we want to make sure we follow the right process rather than guess. Could you confirm:

1. **Does CONTRIB-10574 still govern our approval**, or should we re-submit the plugin through the Marketplace provider flow?
2. **Where should we upload the new version (v6.9.4)** — the existing pending plugin page, or a new Marketplace submission?
3. **Was our pending (not yet approved) listing migrated automatically**, or do we need to create it again in Marketplace?
4. **Is submission open for free plugins?** The launch announcements we saw referred to onboarding for paid plugins. Ours is free and GPLv3.
5. **Have the submission requirements changed** with Marketplace (for example around the privacy statement, per-file copyright headers, or required listing metadata), so we can check our package against the current rules before submitting?

For context, v6.9.4 is packaged and passing our full gate: the Moodle Plugin CI matrix (PHP 8.1–8.3 with MariaDB and PostgreSQL, including codechecker, phpdoc, Mustache lint and ESLint), 631 PHPUnit tests, our security validator suite, and deploy-plus-smoke across five sites spanning Moodle 4.5 to 5.3. Repository: https://github.com/saylordotorg/moodle-local_ai_course_assistant

Happy to follow whichever route you recommend.

Best regards,
Tom Caswell
Chief Data Officer, Saylor Academy

---

## Internal notes (not part of the message)

- Deliberately asks only what the public docs genuinely do not answer; everything else (fields, package, release notes) is already prepared in `v6.9.4-directory-add-version.md`.
- Question 4 matters more than it looks: if free-plugin onboarding is not open yet, the correct action may be to **wait** rather than submit, and CONTRIB-10574 may stay the active channel in the meantime.
- Question 5 is prompted by community feedback reporting that Marketplace review flags a missing privacy statement as blocking even when a plugin stores no personal data, and is strict about per-file copyright headers. Our privacy provider is comprehensive and headers were part of the CONTRIB-10574 remediation, so we expect to pass — this just confirms the current bar.
- Do not upload before there is an answer. Uploading into the wrong route risks a duplicate listing or a submission that sits unreviewed.
- Verified 2026-07-26: `moodle.org/plugins` returns **303 → marketplace.moodle.com**; moodledev.io plugin-contribution states its process is "legacy guidance and should not be used for new plugin submissions."
