Subject: Quote request — SOLA (local_ai_course_assistant) update to v7.3.3 on staging

Hi team,

Could we get an updated estimate of support hours to deploy SOLA
(local_ai_course_assistant) v7.3.3 to the Saylor Academy staging site? This
supersedes the earlier v7.3.1 request — please quote against v7.3.3.

Current state
- Staging is on v7.2.7. Learn and Degrees production are on v6.8.2. This
  request covers staging only.

Requested
- Deploy v7.3.3 to staging (s-saylor-academy-moodle.catalyst-ca.net).
- Source: https://github.com/saylordotorg/moodle-local_ai_course_assistant
  Tag: v7.3.3   Build: 2026090700
  Release: https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.3.3

What changed since v7.2.7 (summary; full notes on the release page)

The v7.3.x line closed 91 of the 93 findings from a full adversarial review.
Highlights by risk:

- GDPR/erasure: the user-deletion cascade had never run (registered against a
  hook class that does not exist); both "download my data" bundles exported 11
  of 23 declared tables. Both corrected.
- Safety controls: emergency Restore no longer switches on controls that were
  off before the incident; the panel's Voice card reports the flag that actually
  enforces; viewing token analytics no longer consumes the spend-alert
  suppression; rate-limit windows over 120s (including the Zendesk escalation
  limit) are actually enforced.
- Spend: per-course spend caps now exist and enforce (they were silently dead
  since v5.13 -- if staging has per-course caps configured, they take effect on
  upgrade); the premium tier respects the cap; voice/STT spend is priced and
  visible for the first time; quiz spend is recorded on failure paths.
- Soapbox: transient transcription failures no longer permanently consume
  learner attempts; recorder capability/permission errors distinguished; ESL
  rubric levels no longer overridden by an auto-seeded global rubric; scoring
  works on all providers (schema shape fix).
- Outreach: dry-run previews no longer burn milestone emails or arm the real
  cooldown; the learner consent toggle now exists ("My communications" panel).
- Streaming: non-English answers no longer silently drop text (UTF-8 boundary
  fix in the SSE holdback).
- Removed: chat attachments (the upload endpoint never existed; every use
  failed) -- the upgrade sweeps orphaned files from the filearea.
- ~2,100 new translations across the 46 supported languages in this line.

Deployment notes
- Three upgrade steps since 7.2.7 (course_cfg.spend_cap_monthly column;
  attachment settings/file sweep; outreach_log.dryrun column with backfill).
  No manual intervention expected.
- ORDER MATTERS for RAG: if the index has not been rebuilt since v7.2.9, please
  reindex AFTER the upgrade (course/section summaries were not indexed before).
- Flag to us if per-course spend caps or outreach dry-run were ever configured
  on staging -- both change behavior on upgrade, deliberately (details in the
  release notes' upgrade section).

Verification already completed on our side
- Full suite: 1,163 tests, 0 failures. Static validators 36/36.
- CI green on PHP 8.1/8.2/8.3 against MariaDB and PostgreSQL for the 7.3.x line.
- Upgrade path exercised locally through every step from 7.2.x.
- Language parity 46/46.

Separate, smaller item while you are in there: production's config_log holds 34
clear-text credential rows readable at /report/configlog (5 are our
redash_api_key rows; 29 belong to other plugins' owners -- list available on
request). Our audit/redaction CLI ships in the plugin from v7.2.5, so it will be
available on staging after this deploy; production remediation can be a
follow-up conversation.

Could you confirm the estimated hours and the earliest window?

Thanks,
Tom
