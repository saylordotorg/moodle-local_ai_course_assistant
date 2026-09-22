# Correction to post on CONTRIB-10574

**Status: POSTED. Tom sent it on 21 September 2026.**

**Paste plain text, not markup.** The sendable version is
`.drafts/contrib-10574-correction-PLAINTEXT-to-paste.txt`, which is what actually
worked. Jira wiki markup and Markdown were both tried first and neither rendered:
the editor CONTRIB-10574 opens in converts neither, so tables came out as literal
pipes and code spans as literal backticks. Space-aligned columns in plain text are
the format that survives. Use that for any future comment on this thread.

This connection is scoped to saylor.atlassian.net and cannot post to
moodle.atlassian.net, so a human has to paste it either way.

**Why this is needed.** The AUTH001 rebuttal posted on 16 September 2026 contains
one paragraph that a reviewer can disprove with a single grep, in a comment whose
explicit premise is "the evidence is below so you can verify rather than take our
word for it". The substantive argument is unaffected and still correct. The
inaccurate part is the sub-argument about how the sessionless intent is declared.

**What the comment claimed:**

> `email_unsubscribe.php`, `digest_unsubscribe.php` and `unsubscribe.php` carry
> `// phpcs:disable moodle.Files.RequireLogin.Missing` with a comment giving the
> reason. `talking_avatar_webhook.php` and `redash_export.php` declare
> `define('NO_MOODLE_COOKIES', true)`, which is what suppresses the same sniff
> for a sessionless endpoint.
>
> We did not add the `phpcs:disable` line to the two `NO_MOODLE_COOKIES`
> endpoints, because the sniff does not fire on them.

**What is actually true at v7.4.7**, the version the comment cites:

| File | `NO_MOODLE_COOKIES` | `phpcs:disable` |
|---|---|---|
| `email_unsubscribe.php` | yes | yes |
| `digest_unsubscribe.php` | yes | yes |
| `unsubscribe.php` | no | yes |
| `talking_avatar_webhook.php` | yes | no |
| `redash_export.php` | yes | no |

So four of the five declare `NO_MOODLE_COOKIES`, not two, and two of those four
carry the `phpcs:disable` line as well. The sentence describes a clean split
between two mechanisms that does not exist, and the reason it gives for the split
is contradicted by the plugin's own code.

---

Everything below the line is the sendable comment.

---

A correction to my own comment above, before you spend time on it.

In the AUTH001 section I described the sessionless declarations as a clean split:
three files carrying {{// phpcs:disable moodle.Files.RequireLogin.Missing}}, two
declaring {{define('NO_MOODLE_COOKIES', true)}}, and no overlap. I then said we had
not added the {{phpcs:disable}} line to "the two {{NO_MOODLE_COOKIES}} endpoints"
because the sniff does not fire on them.

That is wrong, and it is wrong in a checkable direction, so I would rather flag
it than have you find it. Here is the actual state at v7.4.7:

|| File || {{NO_MOODLE_COOKIES}} || {{phpcs:disable}} ||
| {{email_unsubscribe.php}} | yes | yes |
| {{digest_unsubscribe.php}} | yes | yes |
| {{unsubscribe.php}} | no | yes |
| {{talking_avatar_webhook.php}} | yes | no |
| {{redash_export.php}} | yes | no |

Four of the five declare {{NO_MOODLE_COOKIES}}, not two. Two of those four carry
the {{phpcs:disable}} line as well. So the two markers are not alternatives used in
different files, and my stated reason for the difference does not hold: if the
sniff genuinely did not fire on a {{NO_MOODLE_COOKIES}} file, the suppression in
{{email_unsubscribe.php}} and {{digest_unsubscribe.php}} would be redundant, and it
is there.

What I should have said is simpler and is what the code actually shows. All five
endpoints declare their sessionless intent in a machine-readable form. Three do
it with the phpcs suppression, four do it with {{NO_MOODLE_COOKIES}}, and two do
both. We had not audited which marker sat where before writing that paragraph,
and I presented an assumption as a finding.

None of this changes the substance of the AUTH001 response. Each of the five
endpoints authenticates its caller before doing anything: HMAC-SHA256 tokens
compared with {{hash_equals()}} in the three unsubscribe endpoints and the webhook,
and a bearer key compared with {{hash_equals()}} in {{redash_export.php}}. The
argument that {{require_login()}} would be a regression on an unsubscribe link sent
by email is unaffected, and so is the rest of the response.

On the offer at the end of that paragraph, which still stands: if Marketplace
would prefer one uniform marker across all five, tell us which and we will
standardise on it rather than leaving two conventions in the tree.

---

**NOTE TO TOM, not part of the comment:**

1. The table above uses Jira wiki-markup table syntax ({{||}} header, {{|}} cells),
   which is what the rest of the thread uses. If the editor is in the new rich
   text mode it may not convert it; paste into the markup mode if so.
2. I verified every cell against the {{v7.4.7}} tag, which is the version your
   comment cites, and re-checked against current {{main}} (v7.5.2). The map is the
   same in both, so the correction does not go stale when the reviewer rescans.
3. {{spend_export.php}} also declares {{NO_MOODLE_COOKIES}} and carries no phpcs
   line, but it is not among the five files the scanner cited, so I left it out
   rather than widening the scope of your own comment.
4. Do NOT send the comment-access support request in
   {{.drafts/moodle-support-request-comment-access-v7.5.1.txt}}. You posted three
   comments on 16 September, so whatever was blocking has cleared, and that
   request is now moot.
