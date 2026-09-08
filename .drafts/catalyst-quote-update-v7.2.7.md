Subject: SOLA plugin upgrade — please quote against v7.2.7, not v7.2.5

Hi team,

One more update to the estimate request for the SOLA plugin
(local_ai_course_assistant) upgrade on Learn and Degrees.

Please quote against v7.2.7 (build 2026083101) rather than v7.2.5
(build 2026082901).

https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.2.7

As before, please take the build number from this email rather than from the
release page title: the release is named 7.2.7 and the build we want deployed
is 2026083101.

Unlike the last time I did this, v7.2.5 is not a version we are withdrawing.
It is sound. We ran a pre-go-live pass on the Catalyst staging site after that
email went out, which turned up ten items, and v7.2.6 and v7.2.7 close all of
them. One is specific to Learn and is the reason I would rather not deploy
v7.2.5: a settings collision that would silently disable the plugin's new
derived prompt-budget calculation on that site. We can work around it by hand,
but v7.2.7 makes it impossible rather than merely avoided.

What changed for your purposes

Less than last time. The scope is the same single-plugin upgrade:

- 147 commits and 655 files, up from 142 and 643.
- Still 15 upgrade steps, and none of them are new since v7.2.5. The database
  work is identical to what you have already priced.
- Still 3 new tables and no drops, renames or data migrations. All schema
  changes remain additive.
- 18 JavaScript bundles rather than 17, so the cache purge is still required.
- Still no new server dependencies.

Given there are no new upgrade steps and no schema change, I would not expect
this to move the hours estimate at all. I am sending it so you are not pricing
or deploying a superseded build.

No change to timing on our side — this is not urgent, and we are not asking to
book a slot yet.

Thanks,
Tom
