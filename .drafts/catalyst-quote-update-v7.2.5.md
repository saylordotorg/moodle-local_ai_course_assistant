Subject: SOLA plugin upgrade — please quote against v7.2.5, not v7.2.0

Hi team,

A small update to the estimate request I sent for the SOLA plugin
(local_ai_course_assistant) upgrade on Learn and Degrees.

Please quote against v7.2.5 (build 2026082901) rather than v7.2.0
(build 2026082700). We found and fixed a significant defect during testing
after that email went out, so v7.2.0 is not a version we want deployed.

https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.2.5

As before, please take the build number from this email rather than from the
release page title: the release is named 7.2.5 and the build we want deployed
is 2026082901.

What changed for your purposes

Very little. The scope is the same single-plugin upgrade, and the difference
against the figures I gave you is marginal:

- 142 commits and 643 files, up from 125 and 526.
- 15 upgrade steps, of which 2 are new since v7.2.0.
- Still 3 new tables and no drops, renames or data migrations. All schema
  changes remain additive.
- Still 17 JavaScript bundles, so the cache purge is still required.
- Still no new server dependencies.

I would not expect this to move the hours estimate much, but I did not want you
pricing a version we have withdrawn.

No change to timing on our side — this is not urgent, and we are not asking to
book a slot yet.

Thanks,
Tom
