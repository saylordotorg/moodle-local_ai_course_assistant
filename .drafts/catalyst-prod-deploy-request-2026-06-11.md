# Catalyst Production Deploy Request (ready to send)

To: Artem / Catalyst
Subject: SOLA plugin upgrade request: v5.4.5 to v6.8.2 on Degrees, then Learn

Hi Artem,

We would like to schedule an upgrade of the SOLA plugin (local_ai_course_assistant) on production. Details below; the goal is to make this as close to a standard, scriptable deploy as possible for you.

**What:** upgrade the plugin submodule pin from v5.4.5 to tag v6.8.2
(https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v6.8.2).

**Where and in what order:** Degrees first, then Learn a few days later once we have confirmed Degrees is healthy. Two small windows rather than one.

**Deploy steps per site (the standard three):**
1. Update the submodule pin to tag v6.8.2.
2. php admin/cli/upgrade.php --non-interactive
3. Purge caches.

**Migration profile:** small and tested. We retested the schema-bearing one jump (v5.4.5 to v6.7.1) on a clone on 2026-06-16: the schema check passes, existing conversation data and settings survive, and the scheduled tasks register. v6.7.1 to v6.8.2 adds no new database migrations (verified 2026-06-17: the highest upgrade savepoint is unchanged and install.xml is unchanged); the v6.8.x bumps only register two new web-service functions and one session-cache definition, which apply automatically on upgrade. The schema delta from v5.4.5 is therefore additive only: two nullable columns (cached_tokens, session_meta) and two non-unique indexes on the plugin's messages table; on a messages table in the low millions of rows each index build is seconds to low minutes and the column adds are instant. No maintenance mode needed; a low traffic window is enough.

**Pre deploy:** your standard DB backup plus a copy of the current plugin directory is all we need for rollback. Clean rollback is restore both; the schema additions are additive, so even a code only rollback to v5.4.5 runs without errors if ever needed in a hurry.

**Release quality:** every tag ships only after our automated gate: validator and jailbreak suites, about 550 PHPUnit tests, Behat, an accessibility audit, CI green on the tagged commit, and deployment plus smoke verification on our five dev sites (Moodle 4.5 through 5.3). v6.8.2 has been through all of it: PHPUnit 553/0, validators 36/0, jailbreak 32/32, the CDN-bundle and live-API workflows green, and BUS101 smoke confirmed on all five dev sites on 2026-06-17. The v6.8.1 line is also the build we submitted to the Moodle plugin directory: it resolves all 29 reviewer findings (external-service capability checks, privacy external-location declarations, routing all outbound HTTP through Moodle's curl wrapper, File-API temp handling, and related conventions hardening), with no learner-facing change. The v6.6.0 baseline it builds on includes a full security review (the report is attached to that GitHub release as a PDF); the two critical findings, both on an analytics export endpoint, are fixed and verified, and v6.8.2 carries those fixes.

**Post deploy:** we will run our own functional verification within the hour (we have a 10 minute checklist) and will not need anything further from you in the window.

**One process question while we are at it:** for future SOLA upgrades, could updating our submodule pin to the latest Saylor approved tag ride along as a standard step in the maintenance windows you already run (Moodle core security releases and similar)? Our cadence need is only a few prod updates a year, each with the same three deploy steps and a pretested migration path. If ride along is not a fit, we would be interested in a quote for a one time setup of a client controlled pin with automated deployment (we maintain a prod branch or prod-current tag; your automation deploys on change and posts a confirmation).

What windows do you have available in the next two weeks for the Degrees deploy?

Thanks,
Tom

---

## Internal notes (not part of the message)

- Runbook with full detail: `.drafts/sola-prod-upgrade-runbook-v5.4.5-to-v6.8.2.md` (settings deltas, behavior changes, post deploy verification checklist, first week config steps).
- Prerequisite: cut the `v6.8.2` git tag on merged `main` (commit 4326003) and publish the GitHub release before sending this (the message links the release).
- Post deploy verification (Tom or Claude runs it): plugin version shows 2026061702/6.8.2; BUS101 chat round trip streams with SOLA_NEXT chips; history shows no telemetry rows; analytics + token analytics render; the "AI Tutor Dashboard" link appears in course nav for teachers/admins and the instructor dashboard renders; emergency panel shows all flags inactive; Soapbox stays off (no Soapbox link in course nav); the four branding settings still read Saylor / SOLA.
- First week after both sites are live: set `spend_notify_emails`, run the spend alert self test CLI, then enable `cost_anomaly_enabled` and set `spend_cap_per_course_default` to 30.
- Policy bundle stays OFF on prod until the dev soak finishes; enabling later is three settings and needs no Catalyst involvement.
- Why v6.8.2: it carries the v6.0.1 security fix, the v6.2.x RAG and CSP fixes, the policy bundle mechanism that reduces future Catalyst engagements, the avatar engagement probe, the v6.6.0 security-review fixes (two criticals on the analytics export, plus the IDOR/XSS/hardening set), the v6.7.0/v6.7.1 Soapbox speech-practice tool (off by default; for speech and ESL courses) with full 46-language coverage, the v6.8.0 white-label branding capability (defaults unchanged so prod renders identically), and the v6.8.1 Moodle plugin-directory review remediation. Schema changes since v6.3.0 are additive only: the v6.6.0 non-unique index (courseid, role, timecreated on the messages table) and the v6.7.0 nullable `session_meta` column; v6.8.x adds no schema. The one-jump path is tested.
