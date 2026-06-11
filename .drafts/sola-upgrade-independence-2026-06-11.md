# SOLA Upgrade Independence: Reducing Catalyst Engagements

Date: 2026-06-11
Audience: Tom (internal). Includes a ready to send message to Artem at the end.

## The problem in one paragraph

Saylor prod (Learn and Degrees) is managed by Catalyst. The plugin codebase there is read only and deployed from git, which is correct managed hosting practice, but it means every SOLA upgrade today is an ad hoc human engagement with a per engagement cost. Meanwhile SOLA iterates fast: eleven releases shipped to dev in the last three days. The goal is not to bypass Catalyst (PHP code changes will always need their deploy pipeline, and any mechanism that hot loads code from outside that pipeline is a security hole, not a feature). The goal is to make engagements rare, predictable, and mostly automated, and to make the most common kinds of change need no deploy at all.

---

## Lever 1: A standing deploy pipeline instead of per engagement work

**What it is.** Replace "email Catalyst, wait, pay" with a one time arrangement: Saylor controls which SOLA version prod runs (a git tag pin or release branch), and Catalyst automation deploys it on a schedule or on demand, running the standard steps (code sync, `php admin/cli/upgrade.php`, cache purge) without a human project each time.

**Why Catalyst can do this easily.** Catalyst is a Moodle CI shop; client managed plugin pins with automated deployment are a standard offering pattern for them. Prod already deploys SOLA as a git submodule, so the mechanics are: point the submodule at a branch or tag pattern that Saylor controls, and add the deploy job to their existing automation. The marginal work for them is small and one time.

**Two concrete asks, in order of preference:**

1. **Ride along (often free or near free):** Catalyst already runs maintenance windows for Moodle core security releases, roughly every two months. Ask whether updating the SOLA submodule pin to the latest Saylor approved tag can simply ride along in every maintenance window they already perform. If yes, the steady state cost of SOLA upgrades drops to zero and the cadence (about six prod updates a year) is plenty.
2. **Client controlled pin with automated deploy:** Saylor maintains a `prod` branch (or a `prod-current` tag) in the SOLA repo. Catalyst automation checks it on a schedule (weekly, or in maintenance windows), deploys when it changed, runs upgrade.php, purges caches, and posts a confirmation. One time setup fee, then no per upgrade engagement.

**What protects prod in this model.** The same things that protect it today, plus more: every SOLA tag ships only after the full local gate (validators, jailbreak suite, full PHPUnit, Behat, a11y, CI green on the tagged commit, deployed and smoked on five dev sites), the upgrade path from whatever prod runs is tested in advance on a local clone (we test the exact one jump), and Saylor decides what the pin points at — Catalyst automation never picks a version. Rollback is the previous pin plus the standard pre deploy DB backup they already take.

**What it saves.** Every future upgrade conversation. The one time setup conversation likely pays for itself within two upgrades.

---

## Lever 2: Make behavior changes data, not code (the signed policy bundle)

**The observation.** A large share of SOLA "releases" do not change logic at all — they change behavior data: system prompt wording, premium escalation triggers, model and provider routing, spend thresholds, RAG tuning values (relevance floor, rerank candidates), feature flags. Today those are admin settings, which means prod behavior changes either need an admin clicking through screens on prod or a code release that changes defaults.

**What we build.** A **signed policy bundle**: a single JSON file, hosted anywhere Saylor controls (S3, GitHub raw, any https URL), that SOLA fetches once a day via a scheduled task and applies — but only if all of the following hold:

1. **The signature verifies.** Bundles are signed with an Ed25519 private key that lives only with Saylor (Tom's machine). The matching public key is configured in the plugin. A compromised bucket or DNS cannot change prod behavior, because an attacker cannot produce a valid signature.
2. **Every key is on the allowlist.** The plugin ships a hard coded allowlist of setting names a bundle may touch: prompts, triggers, routing, thresholds, flags. API keys, URLs, security settings (SSRF allowlist, consent, privacy) are not on it and can never be set by a bundle, signed or not.
3. **The version is newer.** Bundles carry a monotonically increasing version; an old bundle replayed later is rejected. This blocks rollback attacks and accidental re application.

Every application writes an audit row (who signed implicitly, version, which keys changed, old and new values), and the sync respects the master kill switch. Turning the feature off is one checkbox; SOLA with the feature off behaves exactly as today.

**What this changes operationally.** A v5.12 style change (new escalation triggers), a prompt improvement, a model routing swap, a spend threshold change — all become: edit a JSON file, sign it with one CLI command, upload it. Prod picks it up within a day, with an audit trail. No Catalyst, no deploy, no release.

**What it deliberately does not do.** It moves data, never code. No PHP, no JS, no templates ever come through a bundle. Code changes still go through git, the full test gate, and the Catalyst pipeline from Lever 1. That boundary is what makes this safe to put in front of a security review.

**Status:** built as part of v6.4.0 — see the release notes for the mechanism details and the authoring workflow.

---

## Lever 3: Release trains

**What it is.** Dev keeps its fast cadence (it is free and it is where the testing happens). Prod moves on a train: a batch of releases ships to prod as one upgrade, on a predictable schedule, ideally aligned with Catalyst's existing maintenance windows.

**Why this works for SOLA specifically.** The upgrade path is one jump regardless of how many versions are batched (Moodle runs all intermediate upgrade steps in sequence), and we now test that exact jump on a local clone before every prod request — the current v5.4.5 to v6.3.0 path is tested and the runbook is written. Eleven releases or one release, the Catalyst work is identical.

**Suggested cadence.** Quarterly, or simply "every Catalyst maintenance window where we have something worth shipping." With Lever 2 in place, the pressure to ship code to prod drops substantially because behavior changes flow through bundles, and the train mostly carries genuine new code (features, schema, security fixes).

**The one exception.** Security fixes that matter for prod (like the v6.0.1 conversation history fix) should not wait for a train. Those are worth a paid engagement, which stays rare.

---

## How the three levers fit together

- Lever 2 removes most of the *reasons* to deploy.
- Lever 3 batches the remaining deploys into few, predictable events.
- Lever 1 makes those events automated and cheap instead of bespoke and billed.

Steady state: behavior changes flow daily through signed bundles at zero cost; code reaches prod a few times a year through an automated pin update that rides maintenance windows; Catalyst gets paid for genuinely bespoke work, not routine deploys.

---

## Message to Artem (ready to send)

Subject: SOLA plugin updates — can we set up a standing deploy arrangement?

Hi Artem,

We have been iterating quickly on the SOLA plugin (local_ai_course_assistant) and I want to set up a smoother path for getting tested releases onto Learn and Degrees than a bespoke request each time. Two questions:

1. When you run your regular maintenance windows (Moodle core security releases and similar), could updating our SOLA submodule pin to the latest Saylor approved release tag ride along as a standard step? Our releases are tagged on GitHub, each tag ships only after a full automated test gate plus a tested upgrade path from whatever prod currently runs, and the deploy steps are the standard ones: update the pin, php admin/cli/upgrade.php, purge caches.

2. If ride along is not a fit, what would it look like (and cost, one time) to set up a client controlled pin with automated deployment? For example we maintain a prod branch or a prod-current tag in our repo, your automation checks it weekly or per maintenance window, deploys when it changes, and posts a confirmation. We would keep full responsibility for what the pin points at; rollback is the previous pin plus the pre deploy DB backup you already take.

For context on testing rigor: every tagged release passes our validator and jailbreak suites, about 500 PHPUnit tests, Behat, and an accessibility audit, is verified on five dev sites first, and we test the exact version jump prod would take on a local clone before we would ever move the pin. Happy to walk you through the pipeline.

Near term: we do have one regular upgrade to schedule with you soon (v5.4.5 to v6.3.0, runbook ready). If question 1 works, maybe that one can be the first ride along.

Thanks,
Tom
