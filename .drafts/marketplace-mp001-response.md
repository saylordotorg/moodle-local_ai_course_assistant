# Response to MP001 — no license key, activation code, or subscription

Status: DRAFT for Tom to review and paste into the Moodle Marketplace review
thread. This finding needs an answer, not a code change.

Everything from `---` down is the sendable reply.

---

**MP001 — "Marketplace licensing or credential activation flow needs clarification"**

There is no licensing or activation flow to clarify. The plugin requires no
license key, no activation code, no subscription state, and no credential issued
by us. Nothing needs to be delivered to a customer after purchase, because
nothing is gated.

The finding points at `settings.php:226`, which is the chat **provider** selector,
and the `apikey` field immediately below it. That field is not our credential and
does not unlock our functionality — it is where an administrator optionally pastes
**their own** third-party AI API key, obtained directly from OpenAI, Anthropic,
Google or whichever vendor they choose. We neither issue, validate, proxy, nor
meter it.

## The plugin installs and runs with no credential from us, and none at all

The shipped default for that provider setting is `auto`, which resolves per call
in `base_provider::resolve_auto_provider()`:

1. If the administrator has entered their own API key, use that vendor.
2. Otherwise, if Moodle's own `core_ai` subsystem has a configured provider
   (Site administration → AI), route through `core_ai`.
3. Otherwise fall back to the OpenAI adapter, which will report a missing-key
   error.

So on a site that has already configured Moodle's built-in AI provider, this
plugin is fully functional out of the box with **no key entered anywhere in our
settings** — path 2. That path exists precisely so the plugin is not a
bring-your-own-credential product.

## Verifiable claims

These are checkable in the source rather than taken on trust:

- **No entitlement logic exists.** Grepping the plugin for `license_key`,
  `activation_code`, `entitlement`, `subscription`, `is_licensed`,
  `trial_expiry` and similar returns nothing outside the GPL headers. The only
  matches for "subscription" are learner study-reminder email preferences and
  the corresponding privacy-metadata strings.
- **No Saylor-operated endpoint gates anything.** Every outbound host the plugin
  can contact is either a third-party AI vendor the administrator brings their
  own key for (`api.openai.com`, `api.anthropic.com`, `api.mistral.ai`,
  `api.together.xyz`, `api.deepseek.com`, `api.x.ai`, `openrouter.ai`,
  `api.voyageai.com`, and the optional avatar/voice vendors), or `api.github.com`
  for the optional update checker, or a documentation link. There is no call to
  any Saylor service, and no phone-home of any kind.
- **The Saylor URLs in the source are white-label defaults, not checks.** They
  are a course-catalog link, a privacy-policy URL and a help-page link, all
  overridable by the four branding settings added in v6.8.0
  (`institution_name`, `institution_short_name`, `display_name`, `short_name`).
  A non-Saylor institution renames the product and repoints those links entirely
  through the admin UI.
- **License:** GNU GPL v3 or later, `LICENSE` in the package root. The plugin is
  free software and there is no paid tier, upsell, or off-platform purchase flow
  anywhere in it.

## What an administrator has to do after installing

Nothing that involves us. Either configure a Moodle `core_ai` provider, or paste
their own vendor API key into the plugin's settings. Both are documented on the
settings page. Every optional feature (voice, RAG retrieval, talking avatars,
re-ranking) is off by default and, where it needs a vendor, uses the same
bring-your-own-key model.

If it would help, I am happy to point to the exact lines for any of the above.

---

## Notes for Tom (not part of the reply)

Evidence gathered 2026-08-21 against v7.0.1:

- `classes/provider/base_provider.php:689` — `resolve_auto_provider()`, the
  three-step fallback quoted above.
- Entitlement grep returned only `reminder_manager.php` (study reminders) and
  privacy-metadata lang strings.
- Saylor host references: `hook_callbacks.php:698`, `branding.php:106`,
  `cost_anomaly_detector.php:363`, `templates/chat_widget.mustache:59`,
  `settings.php:1814` — all defaults for brandable links, none an API call.

This finding has now been raised twice (v7.0.0 and v7.0.1 scans) with the same
excerpt, which suggests the scanner flags any `admin_setting_configpasswordunmask`
near a provider selector. Answering it in the review thread is the only fix
available; there is no code change that would satisfy it without removing the
administrator's ability to use their own vendor key.
