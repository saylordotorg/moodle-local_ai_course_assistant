# Moodle plugin directory submission — v6.9.8

Supersedes `moodle-directory-submission-v6.9.7.md`, which in turn superseded
the v6.9.6 pack. Neither was ever submitted, so this replaces both: submit
**6.9.8** and skip 6.9.6 and 6.9.7 entirely.

The last version actually in the directory is **v6.8.3** (the 29/29
CONTRIB-10574 resubmission), so this submission carries the 6.9.6, 6.9.7 and
6.9.8 work together.

Everything below is ready to paste. The one step that cannot be automated is
signing in to marketplace.moodle.com, which uses an authentik SSO flow
(email + password, or Google / Microsoft).

---

## Step 1 — add the version

**URL:** https://marketplace.moodle.com/plugins/local_ai_course_assistant → "Add version"

| Field | Value |
|---|---|
| Version | `2026081900` |
| Release name | `6.9.8` |
| Maturity | Stable |
| Supported Moodle versions | 4.5, 4.6, 5.0, 5.1, 5.2, 5.3 |
| Source control URL | `https://github.com/saylordotorg/moodle-local_ai_course_assistant` |
| Source control tag | `v6.9.8` |
| ZIP | attached to the GitHub release, or `~/ai-projects/ai_course_assistant.zip` |

Zip verified 2026-08-19: **656 files**, 16,202,281 bytes uncompressed, and none
of `__pycache__`, `.pyc`, `scripts/`, `services/`, `cdn/node_modules`, `.git/`,
`.wiki/`, `CLAUDE.md` or `deploy_dev.py`. `version.php` inside the zip reads
`2026081900` / `6.9.8`, `requires` 2024100700 (Moodle 4.5+), `supported`
[405, 503].

### Release notes for the version form

> A reliability release, plus the coding-standard and internationalisation work
> from the two tagged versions in between. The directory currently lists 6.8.3;
> this submission carries 6.9.6, 6.9.7 and 6.9.8 together.
>
> **Error visibility.** The Anthropic provider discarded the API's own error
> payload in three places, including two on the streaming path used by the chat
> drawer: there was no handler for an SSE `error` event, and a rejected request
> — which returns a plain JSON body rather than a stream — was never parsed at
> all. All three now surface the vendor's error type and message, and the SSE
> endpoint records it on its audit row, so an administrator can diagnose a
> provider failure from the audit log without enabling debugging on a live site.
>
> **Credential safety.** When a requested provider had no configured credential
> row, the plugin passed through whichever API key the course had inherited —
> sending one vendor's key to another, which can only fail and does so opaquely.
> It now uses the site credential where that is correct, and otherwise refuses
> with a message naming the missing configuration. Providers that legitimately
> need no key are exempt.
>
> **New monitoring.** A scheduled task alerts when learners are asking questions
> and not receiving answers, comparing per-course question and reply counts over
> a window. Existing cost monitors only fire when spend rises, so a provider
> rejecting every call costs nothing and raises nothing. Off by default.
>
> **Coding standard and internationalisation.** The Moodle coding standard is
> now applied across the tree, and three pages added after the original review
> no longer carry hardcoded English. The plugin is 46/46 language packs complete
> with no missing or stale keys.
>
> **User tour interoperability.** The assistant's optional auto-open behaviour
> raced Moodle's own user tours: on a learner's first visit to a course both
> claimed the screen and the chat drawer won, leaving the tour half covered. The
> widget now checks whether a tour will actually run for this user on this page,
> using the same predicate core uses for its own bootstrap, and waits for
> `tool_usertours/tourEnded` instead of racing it. A tour that is disabled,
> scoped to another page, carries no steps, or has already been completed
> suppresses nothing.
>
> **Accessibility/i18n fix.** A widget line could render its own string
> identifier rather than the translated text when the plugin runs from its
> standalone bundle, where the string map is built ahead of time rather than
> resolved on demand. The missing keys are now declared, and the build fails if
> a bundled module requests a key that was never declared.
>
> Tested: PHP lint clean; 801 PHPUnit tests with 3,472 assertions and 0
> failures; security validator suite 36/36; prompt-injection suite 0 FAIL /
> 0 ERROR (run on the 6.9.7 tag; 6.9.8 touches no prompt or provider code);
> internationalisation complete across all 46 language packs; Moodle Plugin CI
> green across PHP 8.1–8.3 with MariaDB and PostgreSQL; deployed and
> smoke-tested on five development sites spanning Moodle 4.5 to 5.3; and a
> single-jump upgrade from the previous production version verified against a
> seeded install with `check_database_schema.php` clean.

---

## Step 2 — reply on CONTRIB-10574 (only if still open)

Check https://tracker.moodle.org/browse/CONTRIB-10574 first — it now redirects
to https://moodle.atlassian.net/browse/CONTRIB-10574, which needs a login to
read. If the issue closed when v6.8.3 was approved, skip this; adding the
version is enough.

> Hi Volodymyr,
>
> A new release, v6.9.8, is now published:
> https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v6.9.8
>
> Nothing here changes the substance of the 29 issues from the original review;
> those remain fixed as of v6.8.3. This release is reliability work prompted by
> a production incident, plus the coding-standard and internationalisation work
> from v6.9.6 and v6.9.7, both of which were tagged but never submitted:
>
> - The Moodle coding standard is now applied across the tree. phpcbf fixed
>   5,051 violations across 399 files, taking phpcs errors from 7,940 to 2,831.
>   What remains is line length, file docblocks, copyright and licence tags,
>   unnecessary MOODLE_INTERNAL guards, and language-string ordering — none of
>   which were raised in the original review.
> - Three pages added after the original review carried hardcoded English
>   strings. These are now translatable, with generic labels reusing Moodle core
>   strings and the remainder translated into all 45 non-English locales. The
>   plugin is 46/46 complete with no missing or stale keys.
> - Provider error handling and credential scoping have been hardened, and a
>   scheduled task now detects a silently failing AI provider.
> - The plugin's optional auto-open no longer covers a pending Moodle user tour.
>
> One item remains a known gap rather than an oversight: `provider_benchmark.php`
> still has two hardcoded strings on an admin-only page.
>
> Release quality gate for this tag: PHP lint clean; 801 PHPUnit tests with
> 3,472 assertions and 0 failures; security validator suite 36/36;
> prompt-injection suite with 0 FAIL and 0 ERROR; internationalisation complete
> across 46 language packs; Moodle Plugin CI across PHP 8.1–8.3 with MariaDB and
> PostgreSQL; deployment plus smoke verification on five development sites
> spanning Moodle 4.5 to 5.3; and a verified single-jump upgrade from our
> production version with a clean database schema check.
>
> Best regards,
> Tom Caswell
> Saylor Academy

---

## Notes

- **Submit 6.9.8.** 6.9.6 and 6.9.7 were tagged and released on GitHub but
  never submitted to the directory; 6.9.8 contains both.
- The directory version and the Saylor production pin do not need to match.
  Production runs `2026061702` (v6.8.2); the directory would move to v6.9.8.
- Previous directory release was v6.8.3, the 29/29 CONTRIB-10574 resubmission.
- `moodle.org/plugins/...` now 303-redirects to `marketplace.moodle.com`, which
  in turn bounces to an authentik flow on `login.moodle.org`. The login page
  states a Moodle Marketplace account is required, which may not be the same as
  the older moodle.org account used for the v6.8.3 submission. If the Saylor
  account does not carry over, that is the first thing to resolve.
