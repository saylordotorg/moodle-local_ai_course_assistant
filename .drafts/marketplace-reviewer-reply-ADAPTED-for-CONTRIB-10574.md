Hello Volodymyr,

Answering your 26 June request first: **the latest version is now uploaded.**

v7.0.2 (build `2026082100`) was submitted to Moodle Marketplace on 21 August 2026
against this review, replacing the 7.0.0 file that was there before. It declares
Moodle 4.5, 5.0, 5.1 and 5.2, repository tag `v7.0.2`.

## Your 16 June findings

All 29 were addressed in v6.8.1, with per-issue notes recorded on GitHub issues
#68 to #96 (all closed). Briefly, by your headings:

- **External-service validation and capability checks** — every external function
  now performs its own context and capability check.
- **License file** — `LICENSE` added at the package root (GNU GPL v3 or later).
- **PARAM_RAW** — tightened to specific types everywhere it was not genuinely
  free-form.
- **External API calls in the privacy provider** — `add_external_location_link()`
  declared for the AI, voice, Zendesk and learning-radar endpoints.
- **Frankenstyle prefixes** — all global functions prefixed.
- **Insecure directory permissions** — replaced with the Moodle File API; no
  `mkdir(0777)` remains.
- **`print_error()`** — replaced with `moodle_exception`.
- **Direct `config_plugins` access** — replaced with the config API.
- **`curl_init`** — replaced with Moodle's `\curl` throughout, including the
  streaming SSE path, with DNS-rebinding protection via a resolved-address pin.
- **`$_SESSION`** — replaced with a `MODE_SESSION` cache.
- **AJAX to external services** — `consent.php` and `radar_cite.php` removed and
  replaced with external functions. `transcribe.php` remains a plain endpoint
  because it takes a binary audio upload.
- **Templates and Output API** — `vendor_dpa.php` and `privacy.php` migrated;
  `innerHTML` replaced with `core/templates` in the audio player, learning radar
  and analytics dashboard.
- **Inline stylesheets, boilerplate headers, autoloading, naming conventions,
  `$USER` modification, admin course picker** — all addressed.
- **Language support and missing string definitions** — this one went further
  than the original finding. All 46 supported languages are now complete with no
  missing keys, including strings that had been embedded in inline `<script>`
  blocks and are now passed to the browser as a JSON bundle.

## Separately: the automated test blockers

The Marketplace automated test on our recent uploads reports 25 blocking
occurrences under two rule identifiers, `AUTH001` (16) and `SEC006` (9). We
believe both are false positives, so no code change would clear them, and we
would rather set out the evidence than ship a version that does not fix anything.

**`SEC006` — hardcoded parameters in SQL.** Four of the nine cited locations
contain no SQL at all: `classes/analytics.php:246` is a docblock comment, `:260`
is a code comment, `:334` is a blank line, and `classes/meta_ai_data_builder.php`
is cited at line 1380 in a file that is 458 lines long. The remaining five are
genuine SQL construction, but in each the interpolated variable is a string
literal defined a few lines above with no request data in it, and every
user-supplied value is bound through a named placeholder. The clearest case is
`token_analytics.php` (line 93 in v7.0.2, line 90 in v7.0.1), where the variable
is a fixed `CASE` expression used as a `GROUP BY` key:

    $categorysql = "CASE
        WHEN m.interaction_type IN ('voice')                                 THEN 'voice_realtime'
        WHEN m.interaction_type IN ('openai_tts','xai_tts')                  THEN 'voice_tts'
        WHEN m.interaction_type IN ('embedding','embed','rerank')            THEN 'rag'
        ...
    END";

Every `WHEN` and `THEN` is a literal and no variable is interpolated into the
string at any point. Where request values do reach these queries they are bound:

    $where = "m.model_name IS NOT NULL AND m.model_name != '' AND " . self::spend_rows_predicate('m');
    $params = [];
    if ($courseid > 0) { $where .= ' AND m.courseid = :courseid'; $params['courseid'] = $courseid; }
    if ($since > 0)    { $where .= ' AND m.timecreated >= :since'; $params['since']   = $since; }

If you can see an injection path in any of the five, we will fix it immediately.

**`AUTH001` — missing authentication check.** The five cited files are
token-authenticated endpoints, and four of them cannot use a Moodle session by
design:

| File | How it authenticates |
|---|---|
| `email_unsubscribe.php` | HMAC-SHA256 token, `hash_equals()` |
| `digest_unsubscribe.php` | HMAC-SHA256 token, `hash_equals()` |
| `unsubscribe.php` | opaque random token, `PARAM_ALPHANUM`, looked up server-side |
| `talking_avatar_webhook.php` | provider webhook signature, HMAC-SHA256, `hash_equals()` |
| `redash_export.php` | bearer API key, `hash_equals()` against an admin-configured value |

The three unsubscribe endpoints implement RFC 8058 one-click unsubscribe, which
requires the URL in an email to work without an authenticated session: the
recipient clicks a link in their mail client, or the mail provider issues an
unattended POST, and neither carries a Moodle session cookie. Adding
`require_login()` would redirect the learner to a login page and break the
unsubscribe mechanism that Gmail and others require us to honour, and it would
be worse for the learner, who could no longer opt out without holding an account.
`talking_avatar_webhook.php` receives callbacks from a third-party video provider
that has no Moodle account. `redash_export.php` is a reporting API consumed by a
BI tool with a bearer key.

Each of those files carries a header comment stating that it is deliberately
token-authenticated and not session-gated, with the reason. We are happy to
strengthen that documentation, add rate limiting, or restrict them further — but
session authentication is not something we can add to these five without
breaking the features.

**One related note that may be useful for other submissions.** Between two of our
uploads, one rule's count rose from 1 occurrence to 6 with no code change,
because we had added a comment explaining why the single real line was safe — and
the comment necessarily contained the symbol the rule matches in order to say
"this is not a call to that function." The scanner appears to match inside PHP
comments as well as in code. We have since reworded those comments, and verified
the change was comment-only by comparing each file's PHP token stream with
comments and whitespace stripped.

## On licensing

If it is also relevant to the review: the plugin requires no license key, no
activation code and no subscription. The shipped provider setting is `auto`,
which uses Moodle's own `core_ai` subsystem when one is configured, so the plugin
is fully functional with no credential from us. Every outbound host is either a
third-party AI vendor the administrator brings their own key for, or the GitHub
API for the optional update check. There is no call to any Saylor service and no
phone-home. Licence: GNU GPL v3 or later, `LICENSE` in the package root.

## What would help us

1. If you see an injection path in any of the five real SQL locations, we will
   fix it immediately — that is a genuine offer.
2. If Marketplace policy requires a particular documented pattern for
   token-authenticated endpoints, please point us at it and we will adopt it.
3. If those two findings can be marked reviewed-and-dismissed rather than
   requiring a code change, that would let us proceed.

Thank you for the review, and for the detail in the June list — most of it was
directly actionable and the plugin is materially better for it.
