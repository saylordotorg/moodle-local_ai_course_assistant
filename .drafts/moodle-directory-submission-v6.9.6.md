# Moodle plugin directory submission — v6.9.6

Everything below is ready to paste. The one step that cannot be automated is signing in
to moodle.org / marketplace.moodle.com, which now uses an authentik SSO flow
(email + password, or Google / Microsoft).

---

## Step 1 — add the version

**URL:** https://moodle.org/plugins/local_ai_course_assistant → "Add version"

| Field | Value |
|---|---|
| Version | `2026081400` |
| Release name | `6.9.6` |
| Maturity | Stable |
| Supported Moodle versions | 4.5, 4.6, 5.0, 5.1, 5.2, 5.3 |
| Source control URL | `https://github.com/saylordotorg/moodle-local_ai_course_assistant` |
| Source control tag | `v6.9.6` |
| ZIP (if uploading directly) | attached to the GitHub release, or `~/ai-projects/ai_course_assistant.zip` |

The zip has been checked: 648 files, no `__pycache__`, no `.pyc`, no `scripts/`,
no `services/`, no `cdn/node_modules`, no `.git`, no `CLAUDE.md`, no `deploy_dev.py`.
`version.php` inside the zip reads `2026081400` / `6.9.6`.

### Release notes for the version form

> A maintenance release. No new features, no schema change, no migration, no new settings.
>
> **Coding standard.** 5,051 phpcbf violations fixed across 399 files against the Moodle
> standard; phpcs errors fell from 7,940 to 2,831. Formatting only — argument-per-line
> wrapping, brace and operator spacing, indentation.
>
> **Retrieval default.** The `rag_topk` default moves from 5 to 3, on the basis of a blind
> A/B over 40 questions in which 3 and 5 were statistically tied (11 wins vs 12, 17 ties)
> while 3 used 31% fewer prompt tokens. Note that a new default only applies where the
> setting has never been written to config, so existing sites keep their stored value.
>
> **Internationalisation.** The three Soapbox assignment pages carried around 28 hardcoded
> English labels; these are now translatable. Seven generic labels reuse Moodle core strings
> rather than duplicating them. The remaining 27 keys are translated into all 45
> non-English locales, and the plugin ships 46/46 complete with no missing keys.
>
> Tested: PHP lint clean; 775 PHPUnit tests, 3,427 assertions, 0 failures; security
> validator suite 36/36; prompt-injection suite 0 FAIL / 0 ERROR; Moodle Plugin CI green
> across PHP 8.1–8.3 with MariaDB and PostgreSQL; deployed and smoke-verified on five dev
> sites spanning Moodle 4.5 to 5.3.

---

## Step 2 — reply on CONTRIB-10574 (only if the tracker issue is still open)

Check https://tracker.moodle.org/browse/CONTRIB-10574 first. If it was closed when v6.8.3
was approved, skip this — adding the version is enough to re-enter the queue.

> Hi Volodymyr,
>
> A new release, v6.9.6, is now published:
> https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v6.9.6
>
> Nothing in it changes the substance of the 29 issues from the original review; those
> remain fixed as of v6.8.3. This release is maintenance work in the same spirit:
>
> - The Moodle coding standard is now applied across the tree. phpcbf fixed 5,051
>   violations across 399 files, taking phpcs errors from 7,940 to 2,831. What remains is
>   line length, file docblocks, copyright and license tags, unnecessary MOODLE_INTERNAL
>   guards, and language-string ordering — all of which need judgement rather than a tool,
>   and none of which were raised in the original review.
> - Three Soapbox pages added after the original review carried hardcoded English strings.
>   These are now translatable, with seven generic labels reusing Moodle core strings and
>   the remaining 27 keys translated into all 45 non-English locales. The plugin is 46/46
>   complete with no missing or stale keys.
> - The retrieval default was corrected to match our measurements.
>
> One item is a known gap rather than an oversight: `provider_benchmark.php` still has two
> hardcoded strings on an admin-only page. It is on the list.
>
> Release quality gate for this tag: PHP lint clean; 775 PHPUnit tests with 3,427
> assertions and 0 failures; security validator suite 36/36; prompt-injection suite with
> 0 FAIL and 0 ERROR; Moodle Plugin CI across PHP 8.1–8.3 with MariaDB and PostgreSQL;
> and deployment plus smoke verification on five dev sites spanning Moodle 4.5 to 5.3.
>
> Best regards,
> Tom Caswell
> Saylor Academy

---

## Notes

- The directory version and the Saylor production pin do not need to match. Production
  runs `2026061702` (v6.8.2-era); the directory would move to v6.9.6.
- Previous directory release was v6.8.3, the 29/29 CONTRIB-10574 resubmission.
- `moodle.org/plugins/...` now redirects through `login.moodle.org` into a
  `marketplace-authentication-flow` on `marketplace.moodle.com`. The older
  moodle.org account may not carry over — the login page says a Moodle Marketplace
  account is required.
