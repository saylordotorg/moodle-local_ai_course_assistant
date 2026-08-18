# Moodle plugin directory submission — v6.9.7

Supersedes `moodle-directory-submission-v6.9.6.md`. That version was never
submitted, so this replaces it rather than following it — submit **6.9.7** and
skip 6.9.6 entirely.

Everything below is ready to paste. The one step that cannot be automated is
signing in to moodle.org / marketplace.moodle.com, which uses an authentik SSO
flow (email + password, or Google / Microsoft).

---

## Step 1 — add the version

**URL:** https://moodle.org/plugins/local_ai_course_assistant → "Add version"

| Field | Value |
|---|---|
| Version | `2026081800` |
| Release name | `6.9.7` |
| Maturity | Stable |
| Supported Moodle versions | 4.5, 4.6, 5.0, 5.1, 5.2, 5.3 |
| Source control URL | `https://github.com/saylordotorg/moodle-local_ai_course_assistant` |
| Source control tag | `v6.9.7` |
| ZIP | attached to the GitHub release, or `~/ai-projects/ai_course_assistant.zip` |

Zip verified: **654 files**, and none of `__pycache__`, `.pyc`, `scripts/`,
`services/`, `cdn/node_modules`, `.git`, `.wiki/`, `CLAUDE.md` or
`deploy_dev.py`. `version.php` inside the zip reads `2026081800` / `6.9.7`.

### Release notes for the version form

> A reliability release, written after a nine-day production incident in which an
> upstream provider silently rejected every request for a subset of courses.
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
> **Accessibility/i18n fix.** A widget line could render its own string
> identifier rather than the translated text, caused by a parameterised
> `core/str` lookup being served from Moodle's client-side string cache. The
> feature it belongs to also gains an explicit on/off setting, defaulting to off.
>
> Tested: PHP lint clean; 797 PHPUnit tests with 3,468 assertions and 0 failures;
> security validator suite 36/36; prompt-injection suite 0 FAIL / 0 ERROR;
> internationalisation complete across all 46 language packs; Moodle Plugin CI
> green across PHP 8.1–8.3 with MariaDB and PostgreSQL; deployed and smoke-tested
> on five development sites spanning Moodle 4.5 to 5.3.

---

## Step 2 — reply on CONTRIB-10574 (only if still open)

Check https://tracker.moodle.org/browse/CONTRIB-10574 first. If it closed when
v6.8.3 was approved, skip this — adding the version is enough.

> Hi Volodymyr,
>
> A new release, v6.9.7, is now published:
> https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v6.9.7
>
> Nothing here changes the substance of the 29 issues from the original review;
> those remain fixed as of v6.8.3. This release is reliability work prompted by a
> production incident, plus the coding-standard and internationalisation work
> from v6.9.6, which was tagged but not submitted:
>
> - The Moodle coding standard is now applied across the tree. phpcbf fixed 5,051
>   violations across 399 files, taking phpcs errors from 7,940 to 2,831. What
>   remains is line length, file docblocks, copyright and licence tags,
>   unnecessary MOODLE_INTERNAL guards, and language-string ordering — none of
>   which were raised in the original review.
> - Three pages added after the original review carried hardcoded English
>   strings. These are now translatable, with generic labels reusing Moodle core
>   strings and the remainder translated into all 45 non-English locales. The
>   plugin is 46/46 complete with no missing or stale keys.
> - Provider error handling and credential scoping have been hardened, and a
>   scheduled task now detects a silently failing AI provider.
>
> One item remains a known gap rather than an oversight: `provider_benchmark.php`
> still has two hardcoded strings on an admin-only page.
>
> Release quality gate for this tag: PHP lint clean; 797 PHPUnit tests with 3,468
> assertions and 0 failures; security validator suite 36/36; prompt-injection
> suite with 0 FAIL and 0 ERROR; internationalisation complete across 46 language
> packs; Moodle Plugin CI across PHP 8.1–8.3 with MariaDB and PostgreSQL; and
> deployment plus smoke verification on five development sites spanning Moodle
> 4.5 to 5.3.
>
> Best regards,
> Tom Caswell
> Saylor Academy

---

## Notes

- **Submit 6.9.7, not 6.9.6.** 6.9.6 was tagged and released on GitHub but never
  submitted to the directory; 6.9.7 contains it plus the incident fixes.
- The directory version and the Saylor production pin do not need to match.
  Production runs `2026061702` (v6.8.2-era); the directory would move to v6.9.7.
- Previous directory release was v6.8.3, the 29/29 CONTRIB-10574 resubmission.
- `moodle.org/plugins/...` redirects through `login.moodle.org` into a
  `marketplace-authentication-flow` on `marketplace.moodle.com`. The login page
  states a Moodle Marketplace account is required, which may not be the same as
  the older moodle.org account used for the v6.8.3 submission.
