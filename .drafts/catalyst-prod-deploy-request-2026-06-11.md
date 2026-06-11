# Catalyst Production Deploy Request (ready to send)

To: Artem / Catalyst
Subject: SOLA plugin upgrade request: v5.4.5 to v6.4.1 on Degrees, then Learn

Hi Artem,

We would like to schedule an upgrade of the SOLA plugin (local_ai_course_assistant) on production. Details below; the goal is to make this as close to a standard, scriptable deploy as possible for you.

**What:** upgrade the plugin submodule pin from v5.4.5 to tag v6.4.1
(https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v6.4.1).

**Where and in what order:** Degrees first, then Learn a few days later once we have confirmed Degrees is healthy. Two small windows rather than one.

**Deploy steps per site (the standard three):**
1. Update the submodule pin to tag v6.4.1.
2. php admin/cli/upgrade.php --non-interactive
3. Purge caches.

**Migration profile:** small and tested. We tested this exact one jump (v5.4.5 to v6.4.1) on a clone yesterday: the schema check passes, existing conversation data and settings survive, and all 19 scheduled tasks register. The schema delta is one nullable column and one index on the plugin's messages table; on a messages table in the low millions of rows the index build is seconds to low minutes. No maintenance mode needed; a low traffic window is enough.

**Pre deploy:** your standard DB backup plus a copy of the current plugin directory is all we need for rollback. Clean rollback is restore both; the schema additions are additive, so even a code only rollback to v5.4.5 runs without errors if ever needed in a hurry.

**Release quality:** every tag ships only after our automated gate: validator and jailbreak suites, about 500 PHPUnit tests, Behat, an accessibility audit, CI green on the tagged commit, and deployment plus smoke verification on our five dev sites. v6.4.1 has been through all of it.

**Post deploy:** we will run our own functional verification within the hour (we have a 10 minute checklist) and will not need anything further from you in the window.

**One process question while we are at it:** for future SOLA upgrades, could updating our submodule pin to the latest Saylor approved tag ride along as a standard step in the maintenance windows you already run (Moodle core security releases and similar)? Our cadence need is only a few prod updates a year, each with the same three deploy steps and a pretested migration path. If ride along is not a fit, we would be interested in a quote for a one time setup of a client controlled pin with automated deployment (we maintain a prod branch or prod-current tag; your automation deploys on change and posts a confirmation).

What windows do you have available in the next two weeks for the Degrees deploy?

Thanks,
Tom

---

## Internal notes (not part of the message)

- Runbook with full detail: `.drafts/sola-prod-upgrade-runbook-v5.4.5-to-v6.4.1.md` (settings deltas, behavior changes, post deploy verification checklist, first week config steps).
- Post deploy verification (Tom or Claude runs it): plugin version shows 2026061010/6.4.1; BUS101 chat round trip streams with SOLA_NEXT chips; history shows no telemetry rows; analytics + token analytics render; emergency panel shows all flags inactive.
- First week after both sites are live: set `spend_notify_emails`, run the spend alert self test CLI, then enable `cost_anomaly_enabled` and set `spend_cap_per_course_default` to 30.
- Policy bundle stays OFF on prod until the dev soak finishes; enabling later is three settings and needs no Catalyst involvement.
- Why v6.4.1 and not v6.3.0: same tested migration path (the only db/ change after v6.3.0 is a task registration), and it carries the v6.0.1 security fix, the v6.2.x RAG and CSP fixes, and the policy bundle mechanism that reduces future Catalyst engagements.
