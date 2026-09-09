Subject: SOLA (local_ai_course_assistant) v7.4.0 — please deploy to staging today

Hi,

Please deploy SOLA v7.4.0 to staging when you get a chance. I plan to test it
today, and if it passes I'll ask for production later today.

WHAT TO DEPLOY

  Plugin:  local_ai_course_assistant
  Tag:     v7.4.0
  Build:   2026091000
  Repo:    https://github.com/saylordotorg/moodle-local_ai_course_assistant
  Zip:     attached to the GitHub release for v7.4.0

  Staging is currently on v7.2.7. Learn and Degrees production are on v6.8.2.

ONE THING THAT MATTERS MORE THAN USUAL

Please deploy by REPLACING the plugin directory, not by copying files over the
existing one. A merge-style deploy leaves deleted files behind, and two of them
were deleted for a security reason: update_admin.php and
classes/plugin_updater.php were an in-plugin self-updater (it downloaded a zip
and renamed the installed plugin directory), removed in v7.2.0 for a Moodle
plugin-directory security finding. Both files exist in v6.8.2, so they are on
production right now, and a merge-style upgrade would leave them there.

If it's easier, `rsync -a --delete` achieves the same thing. To confirm
afterwards, anything this prints was deleted from the release but is still on
the server:

  diff <(cd /path/to/release && find . -type f | sort) \
       <(cd /var/www/moodle/local/ai_course_assistant && find . -type f | sort) \
    | grep '^>'

UPGRADE STEPS

  1. Replace the plugin directory with v7.4.0 (see above).
  2. Run the Moodle upgrade (web UI or `php admin/cli/upgrade.php`).
  3. Purge caches.

The upgrade traverses 19 savepoints from 6.8.2 and creates three new tables
(_models, _pricesrc, _bench). I tested the full 6.8.2 -> 7.4.0 path locally on
MySQL with seeded data: all 19 steps succeeded, the data-migration step
backfilled correctly, and the resulting schema is byte-identical to a fresh
install of 7.4.0 (37 tables, zero column differences). CI additionally runs the
suite on PostgreSQL and MariaDB across Moodle 4.5, 5.0 and 5.1.

DEPLOY-WINDOW NOTE

Three of the steps do ADD COLUMN on the two largest tables — twice on _chunks
(the RAG index, six figures of rows on production) and once on _msgs. On MySQL 8
and MariaDB 10.6+ these are normally instant metadata-only operations, but if
you would rather not find out during a window, they are the ones to watch.

TWO BEHAVIOUR CHANGES WORTH KNOWING BEFORE PRODUCTION

1. Chat spend starts counting for real. The primary chat model
   (gemini-2.5-flash) had no rate-card entry, and the code treats "no price" as
   "no cost", so chat spend has been computing as $0.00. v7.4.0 prices it
   correctly. If any spend cap is configured with a real value, it will now
   begin to bind, and the 80%/95% notification emails could fire on the first
   full month. Nothing changes if the caps are 0, which is the shipped default
   and means unlimited.

   Could you tell me what these are set to on staging and production? I can
   read them myself if you'd rather, but if it's quick:

     SELECT name, value FROM mdl_config_plugins
      WHERE plugin = 'local_ai_course_assistant'
        AND name LIKE 'spend_cap%';

2. Everything else new in this release is off by default: the price-drift check,
   the spend-reporting endpoint (no key set = returns 404), and the embedding
   migration (no target configured). No new outbound traffic starts on its own.

WHAT'S IN IT

Mainly infrastructure for keeping model pricing correct without a code deploy —
a model registry with provenance, configurable vendor pricing feeds, and
persisted benchmark results — plus two fixes reported by an outside user on
Moodle 5.1: a page that served part of its own source, and a fatal in the
default provider path on every Moodle 5.x. Full notes are on the GitHub release.

Quality gate: 1,470 tests / 0 failures, 36/36 validators, 46/46 locales, and an
HTTP smoke over 35 pages.

Thanks,
Tom
