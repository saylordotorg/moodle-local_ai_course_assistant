REPLY 1 of 2 — post on CONTRIB-10574
(delete this line and everything from the CUT HERE marker down, then post separately)

**Re: AUTH001 (16 occurrences, BLOCKER) and SEC006 (9 occurrences, BLOCKER)**

Thank you for the report — the majority of it was actionable and we shipped
fixes in v7.0.1 for the CSS global-selector leak, the direct superglobal read,
the unprefixed CLI functions, the runtime writes into the plugin directory, the
remaining raw cURL call, the camelCase variables, the manual version include,
and the missing boilerplate headers.

These two findings we believe are false positives. We have set out the evidence
below so you can verify rather than take our word for it.

## SEC006 — "Hardcoded parameters in SQL queries" (9 occurrences)

### Four of the nine cited locations contain no SQL

Checked against the v7.0.1 tag:

| Cited location | What is actually on that line |
|---|---|
| `classes/analytics.php:246` | a docblock comment — `* @return array Array of ['pattern' => text, 'frequency' => int].` |
| `classes/analytics.php:260` | a code comment — `// load every message body in a large course into memory. The most` |
| `classes/analytics.php:334` | a blank line |
| `classes/meta_ai_data_builder.php:1380` | **this line does not exist.** The file is 458 lines long. |

We mention this not to score a point but because it suggests the rule is
matching on something other than SQL construction, which may be worth knowing
for other submissions too. We noticed the same effect elsewhere in the report:
`SEC002` rose from 1 occurrence in our v7.0.0 scan to 6 in v7.0.1 without any
code change, because we had *added a comment explaining why the one real line
was safe* — and the comment contained the words the rule matches. We have since
reworded those comments.

### The five real locations interpolate SQL literals, not user input

The remaining five are genuine SQL construction. In each, the interpolated
variable is a string literal defined a few lines above, containing no request
data, and every user-supplied value is bound through a `:named` placeholder.

The clearest example is `token_analytics.php`. The variable the rule objects to
is built like this (quoted from v7.0.2, where it is at line 93; in the v7.0.1
tag your scan ran against it is at line 90 and the `THEN` values are English
display strings rather than slugs):

```php
$categorysql = "CASE
    WHEN m.interaction_type IN ('voice')                                 THEN 'voice_realtime'
    WHEN m.interaction_type IN ('openai_tts','xai_tts')                  THEN 'voice_tts'
    WHEN m.interaction_type IN ('openai_whisper','openai_stt','xai_stt') THEN 'voice_stt'
    WHEN m.interaction_type IN ('embedding','embed','rerank')            THEN 'rag'
    WHEN m.interaction_type IN ('meta')                                  THEN 'analytics'
    ...
END";
```

That is a fixed `CASE` expression used as a `GROUP BY` key. Every `WHEN` and
every `THEN` is a literal; no variable is interpolated into the string at any
point. It is not derived from any parameter, and it cannot be influenced by a
request. The same pattern
covers `classes/analytics.php:508`, `:636`, `:722` and
`classes/meta_ai_data_builder.php:133` — a `CASE` expression or a `WHERE`
predicate assembled from constants, with the actual values bound:

```php
$where = "m.model_name IS NOT NULL AND m.model_name != '' AND " . self::spend_rows_predicate('m');
$params = [];
if ($courseid > 0) { $where .= ' AND m.courseid = :courseid'; $params['courseid'] = $courseid; }
if ($since > 0)    { $where .= ' AND m.timecreated >= :since'; $params['since']   = $since; }
```

Note that the two request-derived values, `$courseid` and `$since`, are the only
things that reach the query and both are placeholders. We are not aware of an
injection path here, and we would genuinely like to know if you see one.

## AUTH001 — "Missing Authentication Check" (16 occurrences)

The five cited files are token-authenticated endpoints. Four of them cannot use
a Moodle session by design, and the fifth is an API endpoint.

| File | How it authenticates |
|---|---|
| `email_unsubscribe.php` | HMAC-SHA256 token, verified in `email_optout::verify_token()`, compared with `hash_equals()` |
| `digest_unsubscribe.php` | HMAC-SHA256 token, verified in `digest_unsubscribe_token::verify()`, compared with `hash_equals()` |
| `unsubscribe.php` | opaque random token, `PARAM_ALPHANUM`, looked up via `reminder_manager::unsubscribe_by_token()` |
| `talking_avatar_webhook.php` | provider webhook signature, HMAC-SHA256, compared with `hash_equals()` |
| `redash_export.php` | bearer API key, compared with `hash_equals()` against an admin-configured value |

### Why `require_login()` would be a regression, not a fix

The three unsubscribe endpoints implement **RFC 8058 one-click unsubscribe**.
That specification requires the unsubscribe URL in an email to work without an
authenticated session — the recipient clicks a link in their mail client, or the
mail provider issues an unattended `POST`, and neither carries a Moodle session
cookie. Adding `require_login()` would redirect the learner to a login page and
break the very unsubscribe mechanism that Gmail and other providers require us
to honor. It would make the plugin less compliant with email standards and
arguably worse for the learner's privacy, since they could no longer opt out
without holding an account session.

`talking_avatar_webhook.php` receives callbacks from an external video provider.
That caller is a third-party server; it has no Moodle account and cannot have a
session. A signature is the only authentication available to it.

`redash_export.php` is a reporting API consumed by a BI tool with a bearer key.

Each of these files carries a header comment stating that the endpoint is
deliberately token-authenticated and not session-gated, with the reason. We are
happy to strengthen that documentation, add explicit rate limiting, or restrict
the endpoints further if you would like — but session authentication is not
something we can add to these five without breaking the features.

## What we would find helpful

1. If you can see an injection path in any of the five real SEC006 locations, we
   will fix it immediately — that is a genuine offer, not a rhetorical one.
2. If Marketplace policy requires a specific documented pattern for
   token-authenticated endpoints (a naming convention, a shared helper, a
   declaration somewhere), please point us at it and we will adopt it.
3. If these findings can be marked as reviewed-and-dismissed rather than
   requiring a code change, that would let us proceed. If instead there is a
   change you do want, we would rather make it than argue.

We are also separately replying on MP001, where the short answer is that the
plugin requires no license key, activation code or subscription of any kind, and
runs entirely through Moodle's own `core_ai` subsystem with no credential from
us.


========================= CUT HERE =========================
REPLY 2 of 2 — MP001, post as a separate comment
(delete this header line too)

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
