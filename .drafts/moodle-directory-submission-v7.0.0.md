# Moodle plugin directory submission — v7.0.0

Supersedes the v6.9.6, v6.9.7 and v6.9.8 packs. None of them was ever
submitted, so this replaces all three: submit **7.0.0** and skip the rest.

The last version actually in the directory is **v6.8.3** (the 29/29
CONTRIB-10574 resubmission), so this submission carries the 6.9.x line and
7.0.0 together.

The one step that cannot be automated is signing in to
marketplace.moodle.com, which uses an authentik SSO flow (email + password, or
Google / Microsoft).

---

## Correction carried into this pack

The v6.9.7 and v6.9.8 packs both stated that the only remaining
internationalisation gap was "two hardcoded strings in
`provider_benchmark.php`, an admin-only page". That is understated. A review of
the settings page for v7.0.0 counted **86 settings and headings in
`settings.php` whose titles are hardcoded English literals** rather than
`get_string()` calls.

This does not contradict the 46/46 completeness figure, which is also accurate:
that check measures whether every key in `lang/en` has a translation in the
other 45 locales, and it does. It cannot see a string that was never made a
lang key in the first place. Both facts are stated below rather than only the
flattering one.

v7.0.0 fixed the brand leaks among those literals (eight settings showed
"SOLA"/"Saylor" regardless of an institution's own branding). It did not make
them translatable.

---

## Step 1 — add the version

**URL:** https://marketplace.moodle.com/plugins/local_ai_course_assistant → "Add version"

| Field | Value |
|---|---|
| Version | `2026082000` |
| Release name | `7.0.0` |
| Maturity | Stable |
| Supported Moodle versions | 4.5, 4.6, 5.0, 5.1, 5.2, 5.3 |
| Source control URL | `https://github.com/saylordotorg/moodle-local_ai_course_assistant` |
| Source control tag | `v7.0.0` |
| ZIP | attached to the GitHub release |

### Release notes for the version form

> An administrator-facing release. A review of the plugin's settings pages found
> three controls that were registered but never read, and a settings page that
> rendered every one of its 246 controls unconditionally.
>
> **A scheduled task now honours its own off switch.** The setting controlling
> weekly inactivity reminder emails was registered in the settings page and read
> nowhere, so switching it off had no effect and the emails continued. The same
> task also lacked the site-wide enable check its sibling task has always had,
> so it sent learner email even on a site where the plugin was switched off
> entirely. Both checks are now in place. An unset value still reads as enabled,
> matching the setting's documented default, so upgrading cannot silently
> disable reminders on a site whose configuration row was never written.
>
> **Three unread settings removed.** A per-call failover timeout for the voice
> path, which was never wired through; and two placeholder credential fields
> retained as an upgrade fallback that could not fire, because the fallback
> composed a configuration name that was never registered. One of the two was a
> password field, so it invited an administrator to store a credential that
> nothing would ever read. An upgrade step clears all three stored values.
>
> **The settings page collapses.** The page had no setting dependencies at all.
> 71 settings now hide behind the 22 feature toggles that own them, using
> Moodle's own `hide_if` mechanism, so a default installation shows the feature
> switches rather than their configuration. This affects visibility only: hidden
> settings keep their stored values and reappear intact when a feature is
> switched back on.
>
> **The settings page now rebrands completely.** The plugin supports
> white-labelling through four institution-name settings. Eight settings whose
> text was a hardcoded literal were outside that mechanism and continued to
> display the original institution's product name, including one that described
> what "Saylor sites" must configure and one that referred administrators to a
> specific institution's legal review. These now resolve through the branding
> layer, and the two institution-specific sentences are generic.
>
> Tested: PHP lint clean; the full PHPUnit suite with 0 failures; security
> validator suite 36/36; internationalisation complete across all 46 language
> packs with no missing or stale keys; Moodle Plugin CI across PHP 8.1–8.3 with
> MariaDB and PostgreSQL; deployed and smoke-tested on five development sites
> spanning Moodle 4.5 to 5.3; and a single-jump upgrade from the previous
> production version verified against a seeded install with a clean
> `check_database_schema.php`.
>
> Known gap: 86 settings and headings on the administrator settings page still
> carry hardcoded English titles rather than translatable strings. This is
> separate from language-pack completeness, which is complete, and is the next
> internationalisation task.

---

## Step 2 — reply on CONTRIB-10574 (only if still open)

`tracker.moodle.org` now redirects to `moodle.atlassian.net`, which needs a
login to read. Check the issue first; if it closed when v6.8.3 was approved,
adding the version is enough.

> Hi Volodymyr,
>
> A new release, v7.0.0, is now published:
> https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.0.0
>
> Nothing here changes the substance of the 29 issues from the original review;
> those remain fixed as of v6.8.3. Since then the plugin has had reliability
> work prompted by a production incident, the Moodle coding standard applied
> across the tree, and — in this release — an audit of the administrator
> settings pages.
>
> I should correct something I said in earlier correspondence. I described the
> remaining internationalisation gap as two hardcoded strings on one admin-only
> page. Reviewing the settings page for this release, the real figure is 86
> settings and headings whose titles are hardcoded English literals rather than
> `get_string()` calls. The language packs themselves are complete — all 46 with
> no missing or stale keys — but that check cannot see a string that was never
> made a lang key, which is why the smaller number was reported. This release
> fixed the white-labelling leaks among those literals; making them translatable
> is the next task, and I would rather state the position accurately than have
> it found in review.
>
> Also in this release: a scheduled task that sent learner email while ignoring
> both its own on/off setting and the site-wide plugin switch now honours both;
> three settings that were registered but never read anywhere in the codebase
> have been removed, with an upgrade step clearing their stored values; and the
> settings page now uses `hide_if` so that a default install shows feature
> toggles rather than all 246 controls at once.
>
> Best regards,
> Tom Caswell
> Saylor Academy

---

## Notes

- The directory version and the Saylor production pin do not need to match.
  Production runs `2026061702` (v6.8.2); the directory would move to v7.0.0.
- The login page states a Moodle Marketplace account is required, which may not
  be the same as the older moodle.org account used for the v6.8.3 submission.
  If the account does not carry over, that is the first thing to resolve.
