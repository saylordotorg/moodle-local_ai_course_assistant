# Deployment request to Catalyst: SOLA v6.8.2 → v6.9.8 on Learn and Degrees

Status: DRAFT for Tom to review and send (Catalyst ticket + cc Artem).
Prepared: 2026-08-19. Supersedes
`.drafts/catalyst-prod-deploy-request-2026-06-11.md`, which covered the
v5.4.5 → v6.8.2 jump that landed on 2026-06-24.

Everything from `---` down is the sendable message.

---

Subject: SOLA plugin upgrade request: v6.8.2 to v6.9.8 on Learn and Degrees

Hi team,

We would like to schedule an upgrade of the SOLA plugin
(local_ai_course_assistant) on both production sites, Learn (learn.saylor.org)
and Degrees, from the current v6.8.2 (2026061702) to v6.9.8 (2026081900).

Release: https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v6.9.8

## Why now

1. Provider error visibility. This is the main driver. In August we had a
   nine-day incident where an upstream AI provider silently rejected every
   request for a subset of courses. The plugin was discarding the provider's
   own error payload in three places, two of them on the streaming path the
   chat drawer uses, so there was nothing in the logs to diagnose from. v6.9.7
   surfaces the vendor's error type and message and records it on the audit
   row, so the same failure is now diagnosable from the audit log without
   turning on debugging.
2. Credential scoping. When a requested provider had no configured credential
   row, the plugin passed through whichever API key the course had inherited,
   sending one vendor's key to another. It now uses the site credential where
   that is correct and otherwise refuses with a message naming the missing
   configuration.
3. New monitoring for the failure mode we did not catch. A scheduled task
   compares per-course question and reply counts and alerts when learners are
   asking and not receiving answers. Our existing monitors are all spend-based,
   and a provider rejecting every call costs nothing, so nothing fired. Off by
   default; we will enable it ourselves after the upgrade.
4. Moodle coding standard and internationalisation work across the tree
   (v6.9.6), which is also what the plugin directory listing needs.
5. Two smaller learner-facing fixes in v6.9.8: the auto-opening chat drawer no
   longer covers a Moodle user tour, and a widget line that could render its
   own string identifier instead of translated text now resolves correctly.

## What we have verified

- One-jump upgrade v6.8.2 (2026061702) to v6.9.8 (2026081900), tested today on
  a seeded Moodle 4.5.10+ install. This is the exact jump being requested, not
  an incremental path. `upgrade.php --non-interactive` exited 0,
  `check_database_schema.php` reports "Database structure is ok", and
  pre-upgrade conversations, messages, token accounting, rubrics, practice
  scores and per-course configuration all survived intact. The three new tables
  were created and all scheduled tasks registered.
- All five of our dev sites (Moodle 4.5, 4.5, 5.0, 5.1 and 5.3dev) are running
  v6.9.8 and pass our smoke test.
- Release gate on the v6.9.8 tag: PHP lint clean; 801 PHPUnit tests with 3,472
  assertions and 0 failures; security validator corpus 36/36; i18n completeness
  46/46 locales; and the GitHub Actions matrix (PHP 8.1/8.2/8.3 on MariaDB and
  PostgreSQL) green, 13/13. The prompt-injection suite was last run on the
  v6.9.7 tag with 0 FAIL and 0 ERROR; v6.9.8 touches no prompt, provider or
  routing code, so it was not re-run.

## Schema changes

Five upgrade steps between the two versions: 2026071001, 2026071010,
2026071100, 2026071102, 2026080300.

They create three new tables (`..._sbx_assign`, `..._sbx_topic`, `..._sbx_rec`,
for the Soapbox speaking-assignment feature) and add columns and indexes to
existing tables. Everything is additive and guarded with
`table_exists` / `field_exists` / `index_exists`, so a partial rerun is safe.
Nothing is dropped, renamed or backfilled.

## Behaviour changes to be aware of

- Two new scheduled tasks are registered enabled, but both return immediately
  unless the corresponding feature is configured: the unanswered-learner check
  exits unless `unanswered_check_enabled` is set, and the Soapbox cleanup task
  exits unless object storage is configured. Neither will be configured at
  deploy time.
- The default chat provider for a *fresh* install is now "auto". Both
  production sites have an explicit provider configured, and an explicit choice
  overrides auto, so nothing changes for Learn or Degrees.
- The Soapbox slide renderer uses Moodle core's existing `$CFG->pathtogs`
  (the same Ghostscript path core uses for PDF annotation). It is not a new
  system dependency, and it is only reached if we enable Soapbox slides, which
  we are not doing as part of this deployment.
- Everything else new ships OFF by default. Please leave all of it at defaults
  for this deployment; we will enable anything further ourselves through the
  admin UI afterwards.

## Requested procedure

1. Stagger: Degrees first, then Learn after a 24 to 48 hour soak, per our
   usual approach.
2. Take the standard pre-upgrade DB backup for each site.
3. Replace the plugin directory contents with the v6.9.8 release artifact (zip
   attached to the GitHub release), preserving directory ownership and
   permissions, then run:
   php admin/cli/upgrade.php --non-interactive
   php admin/cli/purge_caches.php
4. Post-checks per site:
   - php admin/cli/check_database_schema.php reports ok
   - Plugin version shows 2026081900 / release 6.9.8 in Site administration
   - A test learner can open the assistant on a course page and get a response
     (we will verify the chat path ourselves immediately after)
5. Rollback: restore the pre-upgrade DB backup and the previous plugin
   directory. We are not aware of any irreversible step; all schema changes are
   additive and guarded.

## One thing we will do ourselves afterwards

Both sites currently carry a small workaround rule in the theme's Custom SCSS
(`.aica-active-learners { display: none !important; }`) that we added during
the incident. v6.9.8 fixes the underlying problem, so we will remove that rule
from both sites once the upgrade has soaked. No action needed from you.

## Timing

Any maintenance window in the next two weeks works for us; low-traffic hours
preferred as usual. Please confirm a slot for Degrees and we will lock in the
Learn follow-up after the soak.

Happy to jump on a call for the upgrade window. Thanks!

Tom
