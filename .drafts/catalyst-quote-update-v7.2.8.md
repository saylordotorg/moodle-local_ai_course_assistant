Subject: SOLA plugin upgrade — please deploy v7.2.8, not v7.2.7

Hi team,

One further update to the SOLA plugin (local_ai_course_assistant) upgrade for
Learn and Degrees. Please use v7.2.8 (build 2026090100) rather than v7.2.7
(build 2026083101).

https://github.com/saylordotorg/moodle-local_ai_course_assistant/releases/tag/v7.2.8

As before, please take the build number from this email rather than from the
release page title: the release is named 7.2.8 and the build we want deployed
is 2026090100.

Why this one matters more than the last bump

We found a defect that our current production configuration triggers. Both
Learn and Degrees run Gemini as the chat provider with an OpenAI failover chain
configured. The failover was building its fallback with the primary's model
name, so the fallback asked OpenAI for a Gemini model and was refused. In other
words the failover chain we rely on to keep the assistant answering through a
Gemini outage could never actually work — and that is true of the version
running in production today, not just of the version we were about to deploy.

It is not causing a live problem, because the primary provider is healthy. It
means we have no working safety net if that changes. That is why I would rather
not deploy v7.2.7 now and pick this up later.

What changed for your purposes

Nothing that should affect the estimate:

- 156 commits and 659 files, up from 147 and 655.
- Still 15 upgrade steps, and none are new since v7.2.5 — the database work is
  identical to what you have already priced.
- Still 3 new tables, no drops, renames or data migrations. All schema changes
  remain additive.
- Still 18 JavaScript bundles, so the cache purge is still required.
- Still no new server dependencies.

Separately, and only if it is cheap to check: our staging site egresses through
proxy.catca-us01-prod.catalyst-ca.net:3128. Everything we need is reachable, so
there is nothing to fix — I mention it only because we spent time today ruling
the proxy out while diagnosing the above, and it would be useful to know for
future reference whether staging and production share the same egress policy.

No change to timing on our side.

Thanks,
Tom
