SUPERSEDES `.drafts/marketplace-reviewer-replies-SENDABLE.md` (drafted 2026-08-21
against the v7.0.1 tag). Every citation below was re-verified against v7.4.7 on
2026-09-14. Two claims in the older draft were imprecise and are corrected here;
see the NOTES FOR TOM block at the bottom, which is not part of the reply.

=========================================================================
REPLY 1 of 2 — post on CONTRIB-10574
(delete everything above this line, and everything from CUT HERE down)
=========================================================================

**Re: AUTH001 (16 occurrences, BLOCKER) and SEC006 (9 occurrences, BLOCKER)**

Thank you for the report — the majority of it was actionable and we shipped
fixes in v7.0.1 for the CSS global-selector leak, the direct superglobal read,
the unprefixed CLI functions, the runtime writes into the plugin directory, the
remaining raw cURL call, the camelCase variables, the manual version include,
and the missing boilerplate headers.

These two findings we believe are false positives. The evidence is below so you
can verify rather than take our word for it.

A note on line numbers first: your scan ran against v7.0.1. The current release
is v7.4.7, so we give both — the v7.0.1 location you cited, and where the same
code sits today — since a re-scan will report the newer ones.

## SEC006 — "Hardcoded parameters in SQL queries" (9 occurrences)

### Four of the nine cited locations contain no SQL

Verified against the v7.0.1 tag you scanned:

| Cited location | What is actually on that line in v7.0.1 |
|---|---|
| `classes/analytics.php:246` | a docblock comment — `* @return array Array of ['pattern' => text, 'frequency' => int].` |
| `classes/analytics.php:260` | a code comment — `// load every message body in a large course into memory. The most` |
| `classes/analytics.php:334` | a blank line |
| `classes/meta_ai_data_builder.php:1380` | **this line does not exist.** The file was 458 lines at v7.0.1, and is 492 lines at v7.4.7. |

We mention this not to score a point but because it suggests the rule is
matching on something other than SQL construction, which may be worth knowing
for other submissions. We saw the same effect elsewhere in the report: `SEC002`
rose from 1 occurrence in our v7.0.0 scan to 6 in v7.0.1 with no code change,
because we had *added a comment explaining why the one real line was safe* and
the comment contained the words the rule matches. We have since reworded them.

### The five real locations interpolate SQL literals, not user input

The remaining five are genuine SQL construction. Rather than argue case by case,
we enumerated **every** variable interpolated into a SQL string in the three
files involved (`classes/analytics.php`, `classes/meta_ai_data_builder.php`,
`token_analytics.php`) — 27 distinct variables — identified which of them reach
a SQL statement, and checked each one's origin. Every variable that reaches SQL
resolves to exactly one of four things:

1. **A string literal defined a few lines above** — e.g. `$categorysql`, a fixed
   `CASE` expression used as a `GROUP BY` key. No variable is interpolated into
   it at any point.
2. **A constant predicate returned by a helper** — `conversation_rows_predicate()`,
   `spend_rows_predicate()`, `meta_rows_excluded()`, `benchmark_rows_excluded()`.
   These return fixed strings.
3. **The output of `$DB->get_in_or_equal(..., SQL_PARAMS_NAMED, ...)`** — Moodle's
   own placeholder builder, with its params merged into the bound array.
4. **A table alias** (`'m'`, `'e'`, `''`) — a defaulted function parameter. We
   checked every call site of the five alias-taking helpers: each passes a
   hardcoded literal or omits the argument. No request value reaches it.

Every request-derived value is bound. The representative shape:

```php
$where = "m.model_name IS NOT NULL AND m.model_name != '' AND " . self::spend_rows_predicate('m');
$params = [];
if ($courseid > 0) { $where .= ' AND m.courseid = :courseid'; $params['courseid'] = $courseid; }
if ($since > 0)    { $where .= ' AND m.timecreated >= :since'; $params['since']   = $since; }
```

`$courseid` and `$since` are the only request-derived values reaching the query,
and both are `:named` placeholders. The same holds for the provider filter in
`meta_ai_data_builder::build_where()`, which binds to `:filterprov`.

For reference, `$categorysql` was at `token_analytics.php:90` in v7.0.1 and is at
line 96 in v7.4.7.

We are not aware of an injection path here, and we would genuinely like to know
if you see one.

## AUTH001 — "Missing Authentication Check" (16 occurrences)

The five cited files are token-authenticated endpoints. Four of them cannot use
a Moodle session by design, and the fifth is a machine API.

| File | How it authenticates |
|---|---|
| `email_unsubscribe.php` | HMAC-SHA256 token, verified in `email_optout::verify_token()`, compared with `hash_equals()` |
| `digest_unsubscribe.php` | HMAC-SHA256 token, verified in `digest_unsubscribe_token::verify()`, compared with `hash_equals()` |
| `unsubscribe.php` | opaque random token, `PARAM_ALPHANUM`, looked up via `reminder_manager::unsubscribe_by_token()` |
| `talking_avatar_webhook.php` | provider webhook signature, HMAC-SHA256, compared with `hash_equals()` |
| `redash_export.php` | bearer API key, compared with `hash_equals()` against an admin-configured value |

### The sessionless intent is already declared in code, not only in prose

Each of the five states in its header docblock that it is deliberately not
login-gated, and each also declares it in a form your tooling can see:

- `email_unsubscribe.php`, `digest_unsubscribe.php` and `unsubscribe.php` carry
  `// phpcs:disable moodle.Files.RequireLogin.Missing`, with a comment giving
  the reason.
- `talking_avatar_webhook.php` and `redash_export.php` declare
  `define('NO_MOODLE_COOKIES', true)`, which is what suppresses the same sniff
  for a sessionless endpoint.

`unsubscribe.php` was the one file where `moodle.Files.RequireLogin.Missing`
still raised an unsuppressed warning; we have added the exemption and its
rationale there, so the declaration is now uniform across all five. We did *not*
add the `phpcs:disable` line to the two `NO_MOODLE_COOKIES` endpoints, because
the sniff does not fire on them — a suppression there would assert a warning
that does not exist.

If Marketplace would rather see one single marker on all five regardless, tell
us which and we will standardize on it.

### Why `require_login()` would be a regression, not a fix

The three unsubscribe endpoints implement **RFC 8058 one-click unsubscribe**.
That specification requires the unsubscribe URL in an email to work without an
authenticated session — the recipient clicks a link in their mail client, or the
mail provider issues an unattended `POST`, and neither carries a Moodle session
cookie. Adding `require_login()` would redirect the learner to a login page and
break the unsubscribe mechanism that Gmail and other providers require us to
honor. It would make the plugin less compliant with email standards, and
arguably worse for the learner's privacy, since they could no longer opt out
without holding an account session.

`talking_avatar_webhook.php` receives callbacks from an external video provider.
That caller is a third-party server with no Moodle account and no session. A
signature is the only authentication available to it.

`redash_export.php` is a reporting API consumed by a BI tool with a bearer key.

We are happy to strengthen documentation, add explicit rate limiting, or
restrict these endpoints further — but session authentication is not something
we can add to these five without breaking the features.

## What we would find helpful

1. If you can see an injection path in any of the five real SEC006 locations, we
   will fix it immediately — that is a genuine offer, not a rhetorical one.
2. If Marketplace policy requires a specific documented pattern for
   token-authenticated endpoints (a naming convention, a shared helper, an
   annotation), please point us at it and we will adopt it across all five.
3. If these findings can be marked reviewed-and-dismissed rather than requiring
   a code change, that would let us proceed. If instead there is a change you do
   want, we would rather make it than argue — as with the `unsubscribe.php`
   annotation above, which we have already made rather than argued about.

We are also separately replying on MP001, where the short answer is that the
plugin requires no license key, activation code or subscription of any kind.

========================= CUT HERE =========================
REPLY 2 of 2 — MP001, post as a separate comment
(delete this header line too)

**MP001 — "Marketplace licensing or credential activation flow needs clarification"**

There is no licensing or activation flow to clarify. The plugin requires no
license key, no activation code, no subscription state, and no credential issued
by us. Nothing needs to be delivered to a customer after purchase, because
nothing is gated.

The finding points at `settings.php:226` in v7.0.1, which is the chat
**provider** selector, and the `apikey` field immediately below it. In v7.4.7
those are at lines 285 and 295. That field is not our credential and does not
unlock our functionality — it is where an administrator optionally pastes
**their own** third-party AI API key, obtained directly from OpenAI, Anthropic,
Google or whichever vendor they choose. We neither issue, validate, proxy, nor
meter it.

## The plugin installs and runs with no credential from us, and none at all

The shipped default for that provider setting is `auto`, resolved per call in
`base_provider::resolve_auto_provider()`:

1. If the administrator has entered their own API key, use that vendor.
2. Otherwise, if Moodle's own `core_ai` subsystem has a configured provider
   (Site administration → AI), route through `core_ai`.
3. Otherwise fall back to the OpenAI adapter, which reports a missing-key error.

So on a site that has already configured Moodle's built-in AI provider, this
plugin is fully functional out of the box with **no key entered anywhere in our
settings** — path 2. That path exists precisely so the plugin is not a
bring-your-own-credential product.

## Verifiable claims

- **No entitlement logic exists.** Grepping the plugin for `license_key`,
  `activation_code`, `entitlement`, `is_licensed`, `trial_expiry` and similar
  returns no functional code. The single textual match at v7.4.7 is a comment in
  `admin/cli/jailbreak_test.php` using the word "entitlements" to mean an
  administrator's Moodle capabilities in a test prompt. The only matches for
  "subscription" are learner study-reminder email preferences and the
  corresponding privacy-metadata strings.
- **No Saylor-operated endpoint gates anything.** Every outbound host the plugin
  can contact is either a third-party AI vendor the administrator brings their
  own key for (`api.openai.com`, `api.anthropic.com`, `api.mistral.ai`,
  `api.together.xyz`, `api.deepseek.com`, `api.x.ai`, `openrouter.ai`,
  `api.voyageai.com`, and the optional avatar/voice vendors), or `api.github.com`
  for the optional update checker, or a documentation link. There is no call to
  any Saylor service, and no phone-home of any kind.
- **The Saylor URLs in the source are white-label defaults, not checks.** A
  course-catalog link, a privacy-policy URL and a help-page link, all overridable
  by the four branding settings added in v6.8.0 (`institution_name`,
  `institution_short_name`, `display_name`, `short_name`). A non-Saylor
  institution renames the product and repoints those links through the admin UI.
- **License:** GNU GPL v3 or later, `LICENSE` in the package root (present in the
  published zip). The plugin is free software; there is no paid tier, upsell, or
  off-platform purchase flow anywhere in it.

## What an administrator has to do after installing

Nothing that involves us. Either configure a Moodle `core_ai` provider, or paste
their own vendor API key into the plugin's settings. Both are documented on the
settings page. Every optional feature (voice, RAG retrieval, talking avatars,
re-ranking) is off by default and, where it needs a vendor, uses the same
bring-your-own-key model.

If it would help, I am happy to point to the exact lines for any of the above.

========================= NOTES FOR TOM, NOT PART OF THE REPLY =========================

What changed from the 2026-08-21 draft, and why:

1. SEC006 evidence is now systematic rather than example-based. The old draft
   walked one example and asserted the other four were "the same pattern". This
   version enumerates all 27 SQL interpolations across the three files and
   classifies every one. That is a claim the reviewer can falsify in a few
   minutes, which is the point.

2. AUTH001's "each of these files carries a header comment" was imprecise. All
   five do document it in the docblock, but the machine-readable declaration is
   NOT uniform: two use `phpcs:disable moodle.Files.RequireLogin.Missing`, two
   use `NO_MOODLE_COOKIES`, and `unsubscribe.php` has neither. A reviewer
   checking that sentence would have found the gap. It is now stated exactly,
   with an offer to make it uniform.

3. MP001's grep claim said the search "returns nothing outside the GPL headers".
   At v7.4.7 there is one match — a comment in admin/cli/jailbreak_test.php using
   "entitlements" in an unrelated sense. Stated precisely rather than left to be
   discovered.

4. All cited line numbers now give both the v7.0.1 value the reviewer scanned and
   the v7.4.7 value a re-scan will produce.

Open question before sending: the Moodle tracker renders Jira markup, not
Markdown, so the `##` headings, `|` tables and fenced code blocks above will not
render there. If these are going on CONTRIB-10574 rather than a Marketplace
comment thread, say so and I will convert to Jira markup (h2. / || || / {code}).
