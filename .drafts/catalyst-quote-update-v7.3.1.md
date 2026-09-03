Subject: Quote request — SOLA (local_ai_course_assistant) update to v7.3.1 on staging

Hi team,

Could we get an updated estimate of support hours to deploy SOLA
(local_ai_course_assistant) v7.3.1 to the Saylor Academy staging site?

Current state
- Staging is on v7.2.7.
- Learn and Degrees production are on v6.8.2. This request covers staging only;
  production is a separate decision once staging has been through a test pass.

Requested
- Deploy v7.3.1 to staging (s-saylor-academy-moodle.catalyst-ca.net).
- Source: https://github.com/saylordotorg/moodle-local_ai_course_assistant
  Tag: v7.3.1   Build: 2026090500
  Release: https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.3.1

What changed since v7.2.7

New
- Anonymized chat transcript and summary report. Staff can view and download
  transcripts filtered by course, unit, date range and outcome. Learner identities
  are replaced with per-report pseudonyms, exports are audit-logged and require the
  analytics capability, and CSV cells are neutralized against spreadsheet formula
  injection.

Fixed — controls that were not doing what they said
- Per-course spend caps had never worked. The value was read from a config array
  that never contained it, and there was no database column to store it and no
  field to set it, so every course silently used the site-wide figure. The column,
  the upgrade step and the admin field are now in place.
- Spend cap precedence is now defined: the most specific cap wins (per-course, then
  per-capability, then site-wide per-course default, then site cap). Previously a
  site-wide capability cap overrode an explicit per-course cap.
- Rate limits with a window longer than two minutes were not being enforced. The
  limiter stores a whole window as one cache entry and restarts it when that entry
  expires, while the cache expired after 120 seconds. The most significant case was
  the support-escalation limit of two tickets per learner per hour, which sends a
  transcript to an external help desk each time.
- Per-course provider API keys were rendered into the page source as the value of
  the password field, readable by anyone able to edit the course.
- Two analytics tabs (By Course, AI Feedback) read result keys the server does not
  send and so displayed zeros for everything.
- Export CSV emitted JSON rather than a downloadable CSV.
- Course and section summaries were invisible to the assistant (this was the S14
  fix in v7.2.9 — see the note on reindexing below).

Removed
- Chat attachments. The paperclip in the composer shipped enabled by default and
  could never work: the upload endpoint it posted to does not exist in the plugin.
  The whole surface has been removed rather than left as a broken control.

Deployment notes
- The upgrade adds one database column and runs two short upgrade steps (the column,
  and a sweep that clears the three attachment settings plus any orphaned attachment
  files). No manual intervention expected.
- ORDER MATTERS: if the RAG index has not been rebuilt since v7.2.9, please reindex
  AFTER the upgrade, not before. Course and section summaries were not indexed prior
  to v7.2.9, so a pre-upgrade index will still be missing them.
- Please flag if per-course spend caps are set anywhere on staging. They were inert
  before this release and will begin enforcing after it, so a course already over its
  intended cap will start refusing AI requests.

Verification already completed on our side
- Full test suite: 1136 tests, 0 failures. Static validator suite: 36 of 36.
- CI green on PHP 8.1, 8.2 and 8.3 against both MariaDB and PostgreSQL.
- Interface parity across all 46 supported languages.
- Upgrade path exercised locally from v7.3.0.

Could you confirm the estimated hours and the earliest window you could schedule it?

Thanks,
Tom
