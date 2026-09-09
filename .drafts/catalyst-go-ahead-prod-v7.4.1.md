Hi Dean,

We're good to go for tonight's 9:00 PM EDT window. Staging review is complete
and v7.4.1 passed. Thank you for fitting the staging update in today.

WHAT TO DEPLOY TO PRODUCTION (Learn + Degrees)

  Plugin:  local_ai_course_assistant
  Tag:     v7.4.1
  Build:   2026091001
  Release: https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.4.1

  Production is currently on v6.8.2, so this is a 19-step upgrade. Staging was
  on 7.2.7 and upgraded cleanly; I also rehearsed the full 6.8.2 to 7.4.1 path
  separately and the resulting schema is byte-identical to a fresh install.

ONE REQUEST THAT MATTERS MORE FOR PRODUCTION THAN IT DID FOR STAGING

Please deploy by REPLACING the plugin directory, not by copying files over the
existing one. `rsync -a --delete` does the same thing.

This mattered less on staging because staging was already past the relevant
release. Production is not. These four files exist in v6.8.2 and are on
production right now, and are deliberately absent from v7.4.1:

  update_admin.php
  classes/plugin_updater.php
  classes/attachment_manager.php
  classes/external/email_study_notes.php

The first two were an in-plugin self-updater, removed for a Moodle
plugin-directory security finding: it downloaded a zip over the web and renamed
the installed plugin directory. A merge-style deploy would leave that on
production. To confirm afterwards, anything this prints was removed from the
release but is still on the server:

  diff <(cd /path/to/release && find . -type f | sort) \
       <(cd /var/www/moodle/local/ai_course_assistant && find . -type f | sort) \
    | grep '^>'

UPGRADE STEPS

  1. Replace the plugin directory with v7.4.1.
  2. Run the Moodle upgrade (web UI or `php admin/cli/upgrade.php`).
  3. Purge caches.

DEPLOY-WINDOW NOTE

Three of the 19 steps do ADD COLUMN on the two largest tables the plugin owns:
twice on its RAG index (six figures of rows on production) and once on its
messages table. On MySQL 8 and MariaDB 10.6+ these are normally instant
metadata-only operations, but they are the ones to watch if the window is tight.

ONE BEHAVIOUR CHANGE, AND IT LOOKS SAFE

Chat spend starts being costed correctly. The primary chat model had no entry
in the plugin's rate card, and the code treats "no price" as "no cost", so chat
spend has been computing as zero. v7.4.1 prices it properly, which means any
configured spend cap starts to bind for the first time.

On staging that turned out to be a non-event: caps are configured with real
values ($2,500 site, $2,100 chat) and actual spend sits at 0% of them. I expect
production to look similar, but if you want to eyeball it before the window:

  SELECT name, value FROM mdl_config_plugins
   WHERE plugin = 'local_ai_course_assistant' AND name LIKE 'spend_cap%';

Everything else new in this release is off by default: the price-drift check,
the spend-reporting endpoint (no key set means it returns 404), and the
embedding migration (no target configured). Nothing starts outbound traffic or
sends mail on its own.

WHAT WE VERIFIED ON STAGING

Plugin reports 7.4.1 / 2026091001 with no pending upgrade. All 32 plugin pages
render clean. Chat works end to end, including on Moodle 5.0. The backend
self-test passes its chat and embedding round-trips. Model pricing resolves for
every model billed in the last 30 days. All deployed static assets are
byte-identical to the released tag. Unauthenticated probes of 21 endpoints all
refuse correctly with no information leakage.

Two things we found are logged and neither is a regression from what production
runs today: one course on staging (a restored test course) gets an HTTP 400 back
from the AI vendor, which fails gracefully with a generic message to the learner
and behaves identically on v6.8.2, since that code path is unchanged between the
two versions; and a separate logging improvement that is not in this tag.

ROLLBACK

Restoring the previous plugin directory and running the Moodle upgrade again is
sufficient; the new tables are additive and unused by v6.8.2. Happy to be on
hand during the window if that helps.

Thanks,
Tom
