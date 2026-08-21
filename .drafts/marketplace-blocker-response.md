# Response to the AUTH001 and SEC006 BLOCKER findings

Status: DRAFT for Tom to review and paste into the Moodle Marketplace review
thread, alongside `.drafts/marketplace-mp001-response.md`.

**Why this matters more than another release.** The v7.0.1 scan reports 25
BLOCKER occurrences. All 25 are AUTH001 (16) and SEC006 (9). Both are false
positives. No code change can reduce that number, because there is no defect
behind it — so shipping another version will not clear the gate. This reply is
the only thing that can.

Tone note before sending: everything below is checkable, and it is offered that
way rather than as an assertion. The scanner has been useful to us — it found
a genuine site-wide CSS leak, a `$_POST` read, fourteen unprefixed CLI
functions, writes into `dirroot`, and a raw cURL handle, all of which we fixed
in v7.0.1. These two findings are the ones where it is wrong.

Everything from `---` down is the sendable reply.

---

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

---

## Notes for Tom (not part of the reply)

Evidence gathered 2026-08-21 against the `v7.0.1` tag:

- Line-existence check on all 9 SEC006 citations: 5 real SQL, 2 comments, 1
  blank line, 1 non-existent line. `meta_ai_data_builder.php` is 458 lines; the
  report cites 1380.
- `$categorysql` verified as a literal `CASE` at `token_analytics.php:90` in
  v7.0.1, and re-verified at `token_analytics.php:93` in v7.0.2 — the file grew
  from 426 to 452 lines in the i18n extraction, so the line moved by 3. No
  variable is interpolated into the string in either version.
- **Re-checked all the other cited lines against v7.0.2 before sending:** every
  `classes/analytics.php` citation (246, 260, 334, 508, 636, 722) and
  `classes/meta_ai_data_builder.php:133` are byte-identical between the two tags,
  and both files are unchanged in length (1,442 and 458 lines). So the
  line-by-line table above holds whether the reviewer opens v7.0.1 or v7.0.2 —
  worth knowing, because uploading v7.0.2 means they may well open the newer one.
  `meta_ai_data_builder.php` is still 458 lines, so the cited line 1380 still
  does not exist.
- Token verification confirmed: `hash_equals` at `classes/email_optout.php:167`
  and `classes/digest_unsubscribe_token.php:76`, both with
  `hash_hmac('sha256', ...)` at `:203` and `:122` respectively.
- The SEC002 inflation claim is measurable and reproducible: 42 scanner-trigger
  tokens sat inside comments repo-wide before v7.0.2, versus 159 in real code.
  `$_POST` was the starkest — 2 comment mentions and **zero** real occurrences,
  for a finding we had already driven to zero.

Judgment call for you: paragraph 3 of "What we would find helpful" concedes
willingness to make changes. Keep it if you want the review to move; cut it if
you would rather not invite make-work.
