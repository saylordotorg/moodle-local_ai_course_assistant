# Deployment request to Catalyst: SOLA v6.4.2 to production

Status: DRAFT for Tom to review and send (Catalyst ticket + cc Artem).
Prepared: 2026-06-11. Companion document:
`.drafts/sola-prod-upgrade-runbook-v5.4.5-to-v6.3.0.md` (full runbook; the
notes below extend it from v6.3.0 to v6.4.2).

---

Subject: SOLA plugin upgrade request: v5.4.5 to v6.4.2 on Learn and Degrees

Hi team,

We would like to schedule an upgrade of the SOLA plugin
(local_ai_course_assistant) on both production sites, Learn (learn.saylor.org)
and Degrees, from the current v5.4.5 (2026050945) to v6.4.2 (2026061011).

Release: https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v6.4.2

## Why now

1. Security fix (v6.0.1). On v5.4.5, internal telemetry rows (premium router,
   rerank, embedding logs) can appear in learner conversation history and in
   the LLM context. v6.0.1 filters history to user and assistant roles. This
   is the main driver for the upgrade.
2. Working kill switch (v5.13.0). The emergency_control --chat CLI is a silent
   no-op on v5.4.5, so the chat spend kill switch does not actually work in an
   incident today.
3. Spend protections: per-course default cap, cost anomaly detector, and the
   web emergency panel.
4. Cost reduction: the mastery classifier routes to gpt-4o-mini (roughly $220
   per month saved at 100k MAU, quality unchanged per our evaluation).
5. Full 46-language coverage for all admin surfaces added since v5.10, plus
   the RAG retrieval-quality and debugging improvements from v6.2 and v6.4.

## What we have verified

- One-jump upgrade path v5.4.5 to v6.3.0 was tested end to end on a seeded
  local install: upgrade.php completed with no errors, check_database_schema
  reports ok, conversation and config data survived, the new column and index
  were created, and all scheduled tasks registered. All upgrade steps between
  the two versions are guarded (index_exists / field_exists), so a partial
  rerun is safe.
- The v6.3.0 to v6.4.2 delta contains no schema change: v6.4.0 adds three
  settings (off/empty by default) and one scheduled task (inert when
  disabled), v6.4.1 is an admin-only debug view, and v6.4.2 is deploy tooling
  only.
- All five of our dev sites (Moodle 4.5.6, 4.5.9, 5.0.2, 5.1, and 5.3dev)
  have upgraded through this full lineage and pass our smoke test.
- Release gate on the v6.4.2 tag: full PHPUnit plugin suite green, security
  validator corpus 36/36, jailbreak suite 32/32 (live LLM round-trips), i18n
  completeness 46/46 locales, and the GitHub Actions matrix (PHP 8.1/8.2/8.3
  on mariadb and pgsql) green.

## Behavior changes to be aware of

- Mastery classifier (v5.11.0) now routes per-turn classification through
  openai / gpt-4o-mini by default. Prod already has a valid OpenAI key (it is
  the existing failover chat tier), so no action needed.
- Everything else new ships OFF by default: premium escalation, cost anomaly
  alerts, rerank, per-course default cap, policy bundles, selfhosted STT.
  Leave all of them at defaults for this deployment; we will enable anything
  further ourselves through the admin UI afterwards.

## Requested procedure

1. Stagger: Degrees first, then Learn after a 24 to 48 hour soak, per our
   usual approach.
2. Take the standard pre-upgrade DB backup for each site.
3. Replace the plugin directory contents with the v6.4.2 release artifact
   (zip attached to the GitHub release), preserving directory ownership and
   permissions, then run:
   php admin/cli/upgrade.php --non-interactive
   php admin/cli/purge_caches.php
4. Post-checks per site (we can run these together on a call if useful):
   - php admin/cli/check_database_schema.php reports ok
   - Plugin version shows 2026061011 / release 6.4.2 in Site administration
   - A test learner can open the assistant on a course page and get a
     response (we will verify the chat path ourselves immediately after)
5. Rollback: restore the pre-upgrade DB backup and the previous plugin
   directory. We are not aware of any irreversible step; all schema additions
   are additive and guarded.

## Timing

Any maintenance window in the next two weeks works for us; low-traffic hours
preferred as usual. Please confirm a slot for Degrees and we will lock in the
Learn follow-up after the soak.

Happy to jump on a call for the upgrade window. Thanks!

Tom
