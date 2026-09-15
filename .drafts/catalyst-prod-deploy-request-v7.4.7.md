Subject: Production deploy estimate — SOLA (local_ai_course_assistant) v7.4.7 on Learn + Degrees

Hi Dean,

Could we get an estimate of support hours to deploy SOLA
(local_ai_course_assistant) v7.4.7 to both production sites?

**This supersedes the v7.4.6 request I sent earlier today — please quote
against v7.4.7 and ignore the v7.4.6 one.** v7.4.7 was tagged after that
message went out and contains a learner-facing fix I would rather not hold
back. The schema story is unchanged: v7.4.7 adds no upgrade step of its own,
so the four blocks described below are exactly the four in the v7.4.6 request.

WHAT TO DEPLOY (Learn + Degrees)

  Plugin:  local_ai_course_assistant
  Tag:     v7.4.7  (commit e8b8f16d)
  Build:   2026091400
  Release: https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.4.7
  Asset:   https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/download/v7.4.7/ai_course_assistant-v7.4.7.zip

  sha256  5a3221ffb0d7189b34acebb962dca384045c0ef7dac7e326401b000c97d23681

Both sites are on build 2026091001 (v7.4.1), the release you deployed on
2026-09-09.

Please deploy from the asset link above and not from the "Source code (zip)"
link GitHub shows more prominently on the same page, nor from a git clone or
git archive of the tag. The tag tree carries a .drafts/ directory of internal
correspondence that the zip build excludes; a clone would put those files under
the web root. The zip is byte-identical to the tag for every file it contains.

As with v7.4.1: please deploy by REPLACING the plugin directory rather than
copying over the existing one. `rsync -a --delete` does the same thing.

WHAT IT FIXES

1. The assistant stops showing learners its own internal markers. The system
   prompt emits control tokens that the browser strips before rendering the
   answer. Some were surviving to the transcript, so a learner could see
   internal protocol syntax in the middle of a reply. Stripping now also
   happens server-side, so a marker cannot get through on a path where the
   client-side strip was missed. This was found by running ten fixed prompts
   against live production courses on Learn across three models, so it is a
   thing that happened to real learners rather than a theoretical gap. The
   same fix covers two places the browser never protected: the Moodle mobile
   app, and the teacher-facing transcript CSV export, which reads the stored
   message verbatim. Markers are now removed before the message is stored.

2. The assistant no longer tells the user what their role is. It was opening
   answers with "As an administrator...". The role is given to the model
   deliberately, to decide how much depth to give, but it was never meant to
   come back out in the reply; it is now explicitly instructed not to say it.
   Teachers would have seen the same thing.

3. Under a tight prompt budget the course outline could be dropped entirely
   rather than shortened, because it was being counted against the budget
   twice. On a course with more units than fit, the assistant would describe
   the first three and present that as the complete list.

4. 82 strings that displayed in English to every learner, in every language,
   are now translated. They were present in all 45 translation files while
   still holding the English text, so every completeness check we had reported
   the translations as complete and the interface still showed English. This
   affects the course settings page, the instructor dashboard, essay feedback
   and the Python sandbox. A new automated check now fails the build when a
   string is present in every language but still holding the English value,
   which is the specific gap that let this sit unnoticed.

5. An invalid learning-objectives import is now rejected instead of erroring
   at the database. One field in that insert had no length limit applied where
   every neighboring field did, so an over-long value produced a database
   error rather than being truncated. Administrator-only and form-token
   protected, so this was low severity; it is fixed because the column already
   had a defined set of valid values and nothing enforced it.

No configuration changes are needed for any of the above.

TIME ESTIMATE

Going from 2026091001 to 2026091400, four upgrade blocks run. v7.4.7 itself
adds none — it is a code-only release.

  2026091002  ADD COLUMN msgs.reasoning_tokens, nullable INT
  2026091004  CREATE TABLE ..._radar_batch, 17 fields, created empty
  2026091005  ADD COLUMN models.eol_date, models.eol_surface, both nullable
  2026091007  sets a table COMMENT on radar_batch, metadata only

All four are additive: 20 ADD COLUMN, 2 keys, 2 indexes, 1 CREATE TABLE, and
one metadata-only statement. Nothing is dropped, renamed or rewritten, and no
existing row is modified.

Only the first block touches a table that holds data. The models table holds 0
rows on both sites. As recorded in the v7.4.6 request: msgs holds roughly
138,000 rows on Degrees and 186,000 on Learn, both ROW_FORMAT=Dynamic on Aurora
MySQL 3.08.1, where adding a nullable column is an instant metadata change that
does not scale with row count. We expect the plugin's own upgrade step to be
sub-second on both sites.

VERIFICATION COMPLETED ON OUR SIDE

- Full suite: 1,644 tests, 7,238 assertions, 0 failures.
- Static validators: 36 of 36, 0 failures.
- PHP lint: 546 tracked files, 0 failures.
- CI: 10 of 10 matrix jobs green — PHP 8.1/8.2/8.3 against MariaDB and
  PostgreSQL on Moodle 4.5, 5.0 and 5.1.
- Jailbreak / prompt-injection suite: 28 pass, 0 fail, 0 error (4 results
  flagged for manual review were each read individually and are correct
  refusals that matched no pass-indicator pattern).
- Translation parity: 3,690 translated strings machine-checked — every
  placeholder and brand token byte-identical to source, no key missing, and no
  key left as English across all 45 locales.
- Deployed and smoke-tested on all five of our dev sites, with the installed
  build confirmed in each site's database rather than only on disk.

ONE QUESTION, NOT URGENT AND NOT BLOCKING THIS DEPLOY

Our spend dashboard pulls a per-month cost figure from an endpoint the plugin
exposes at /local/ai_course_assistant/spend_export.php, authenticated with a
bearer token. That endpoint shipped in v7.4.0, so it has been present on both
production sites since the v7.4.1 deploy, but the dashboard is not receiving
data from either site.

The endpoint returns a bare 404 when its API key setting is empty, which is
deliberate so that an unconfigured site does not advertise the URL — but it
means "not configured" and "wrong key" look identical from our side. Could you
confirm whether local_ai_course_assistant | spend_export_key is set on Learn
and Degrees? If it is unset, that alone explains it and we can supply a value.

SEPARATELY, STILL OPEN FROM THE v7.3.3 REQUEST

Production's config_log still holds 34 clear-text credential rows readable at
/report/configlog — 5 are ours, 29 belong to other plugins' owners (list
available on request). The audit and redaction CLI ships in the plugin and has
been present on production since v7.4.1, so it can be run there whenever suits.
Happy to make that a separate piece of work.

Could you confirm the estimated hours and the earliest window?

Thanks,
Tom
