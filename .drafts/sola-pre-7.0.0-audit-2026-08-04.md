# SOLA pre-7.0.0 release audit (2026-08-04)

Audited at `fix/sola-sweep` (`1d6b8651`), which carries this session's six commits
on top of `848c9e51`. It does **not** include the concurrent
`fix/secret-masking-and-rerank-default` (`2a7c8581`: `redash_api_key` masking, the
`rerank_candidates` 50 to 20 default, and a retriever cache-invalidation fix).
Those three are reviewed separately and referenced where they matter below.

Scope: what a release audit can establish by reading and running the code. Full
suite 749 pass, validators 36/36, no PHP lint errors. This is not a penetration
test and not a load test.

---

## Verdict

> **Shipped as 6.9.5, not 7.0.0.** Tom's call, 5 August 2026. The filename is left
> as written. The breaking export changes that motivated the major-bump
> recommendation are instead called out at the top of the v6.9.5 release notes and
> changelog entry, since a patch number does not signal them.


**Not ready to tag.** Two blockers, both mechanical rather than architectural.
Nothing found here argues against shipping the code; the issues are that one
shipped artifact does not match its source, and that the release checklist has
not been run.

## Status as of the follow-up pass (same day)

| Item | Status |
|---|---|
| B1 stale `sse_client` bundle | **fixed**, rebuilt and caps verified present |
| B2 release checklist | **open**, needs the version decision and the manual smoke |
| M1 anomaly detector blind to RAG | **fixed** |
| M2 `soapbox_storage_key` cleartext | **fixed**, plus the guard gap that hid it |
| M3 i18n 8 keys short | **guarded, not translated** (360 strings, see below) |
| M4 auto-refresh outranks curated rates | **no change**, informational by design |
| L1 token analytics panels disagree | **fixed** |
| L2 untested risk classes | **partly fixed**, `anonymizer` + `digest_unsubscribe_token` now tested |
| L3 projection window bound | **fixed** |

Correction to M1 as originally written below: it said changing the population
needed a decision because the first comparison after upgrade might fire a spurious
alert. That was wrong. `spend_for_day()` is called for today and for each of the
prior 7 days, and there is no persisted baseline, so the median moves with the
numerator. The fix is safe to apply directly, and was.

M3 is guarded rather than fixed. The 8 keys are 1,436 characters of English, so
translating them into the 45 non-English locales is 360 strings and roughly 65,000
characters. Rather than do that at low quality inside an unrelated pass,
`lang_completeness_test` gained a `KNOWN_UNTRANSLATED` list and a parity test: the
existing 8 are enumerated as debt, and any *new* English-only string now fails the
suite. Verified by adding a probe key, which failed with "missing from 45 locales".
The translation batch remains outstanding and matches the repo's existing pattern
of doing i18n catch-up as its own release item (v6.3.0 did 100 keys that way).

L2 is partly addressed: `anonymizer` (39 lines, load-bearing for every PII gate)
and `digest_unsubscribe_token` (the only control on unauthenticated unsubscribe
links) now have tests, 15 between them. `integrity_checker` (538 lines),
`rate_limiter` and `rate_card_refresher` remain untested.

---

## Blockers

### B1. A committed security fix has never shipped

`amd/build/sse_client.min.js` was last built 2026-03-10. `amd/src/sse_client.js`
was last changed 2026-05-15 by commit `62629153`, "v5.5.4: security hardening from
red-team audit". Moodle serves the build, not the source.

The unshipped change is the SSE accumulator cap: a 1 MB buffer ceiling and a
256 KB per-line JSON ceiling that stop a malicious or malfunctioning provider from
exhausting browser memory with an unbounded chunk. Verified by grepping the built
bundle: `safety cap` 0 occurrences, `1048576` 0, `262144` 0, while the source has
them. The `onMeta` callback from the same file is present in the build, so the
build corresponds to an intermediate state of the source, not to any tagged
version of it.

So the v5.5.4 red-team item is recorded as fixed in the changelog and is fixed in
source, but no site has ever run it. Fix is one terser invocation. It is a blocker
only because tagging 7.0.0 would ship a third release claiming a fix it does not
contain.

Every other src/build pair is consistent (checked all nine by comparing last-commit
dates, not mtimes; mtimes in a fresh worktree are meaningless).

### B2. The release checklist has not been run

Per `CLAUDE.md`, in order: release-notes draft via `scripts/new_release_notes.py`,
`version.php` bump, `.wiki/Changelog.md`, i18n sync, lint, jailbreak 32/32,
validators, manual smoke, then tag.

Current state: `version.php` is still `2026080300` / `6.9.4`. No `.drafts/v7.0.0-*`
notes file. i18n is out of sync (see M3). Validators do pass (36/36) and lint is
clean, so the remaining work is notes, version, changelog, i18n, and the manual
smoke pass.

---

## Medium findings

### M1. Runaway RAG spend cannot trigger the cost-anomaly alert

`cost_anomaly_detector.php:92` and `:132` filter `m.role = 'assistant'`, and
`task/run_anomaly_digest.php:61` does the same. Embedding and rerank rows are
`role = 'system'`, so the daily "today versus rolling 7-day median" comparison is
blind to RAG spend.

This is the same defect class fixed in five other places this session, and it is
the one place where it has an operational consequence rather than a reporting one:
a runaway reindex is exactly the scenario the anomaly detector exists to catch, and
it is the scenario it cannot see. `analytics::spend_rows_predicate()` now exists to
fix it in one line each.

Deliberately not fixed here: changing the anomaly baseline population means today's
figure jumps against a median computed without RAG, which would fire a spurious
alert on the first run after upgrade. That needs a decision about whether to seed
or suppress the first comparison.

### M2. `soapbox_storage_key` is a credential stored in cleartext in `mdl_config_log`

Declared `admin_setting_configtext` (`settings.php:1648`) and consumed as
`'accesskey'` for S3-style object storage (`soapbox_storage.php:143`, `:164`,
`:189`, `:221`). Its own sibling `soapbox_storage_secret` is correctly
`admin_setting_configpasswordunmask`.

Moodle writes `********` into `config_log` only for password-typed settings, so
every historical value of this one is recoverable from a table that is never
purged. Identical to the `redash_api_key` issue found on production 2026-08-03.

It also slips through the new guard: `settings_secret_masking_test.php` matches on
`SECRET_HINTS = ['apikey', 'api_key', 'secret', 'token', 'password']`, and
`soapbox_storage_key` contains none of those substrings. Adding a bare `key` hint
would catch it, at the cost of needing `policy_bundle_pubkey` in the existing
`NOT_SECRETS` allowlist, which is already there.

A full re-scan of all 17 credential-named settings found no others: everything else
is already password-typed.

### M3. i18n is not at the 46/46 the docs claim

`CLAUDE.md` states "46/46 locales pass the completeness check with zero missing
keys". Eight keys are missing from every non-English locale:

- `chat:refused`
- `settings:rerank_margin_threshold`, `settings:rerank_margin_threshold_desc`
- `rag_cap_blocked` (added this session)
- `settings:redash_export_window_days`, `..._desc` (this session)
- `settings:redash_allow_deanonymized`, `..._desc` (this session)

Three predate this session, five are mine. `lang_completeness_test.php` does not
catch these because it only asserts that keys referenced from code exist in
English, not that translations exist.

Separately, the translated files carry roughly 27 keys that English no longer has,
so they hold stale entries as well as gaps. Harmless at runtime, but it means
per-locale string counts cannot be used as a completeness signal.

### M4. The curated rate card is not the effective source of truth

`rate_card_refresher` pulls the LiteLLM community pricing manifest and writes it
into `rate_card_overrides`, which `token_cost_manager::get_effective_rate_cards()`
applies **on top of** the hardcoded card. The `refresh_rate_card` task runs Mondays
at 02:30 and `rate_card_auto_refresh` defaults to `1`.

So on any site with cron running, the Voyage rates added this session are a
fallback that a weekly third-party fetch can replace. That is the designed
behaviour and the refresher is SSRF-checked and failure-tolerant, but it is worth
stating plainly before a release whose notes will advertise accurate RAG costing:
pricing on a default install comes from a community-maintained GitHub file, not
from the plugin.

---

## Low findings and nits

### L1. Token analytics panels do not reconcile with each other

The category breakdown in `token_analytics.php` correctly includes `role='system'`
cost rows, fixed in v6.1.0. Other panels on the same page still filter
`role='assistant'` (`:65` per-model table, `:216` missing-model diagnostic, `:228`,
`:317` cached tokens). Some of those are correct on purpose (cached tokens is an
OpenAI chat concept), but the per-model table excluding RAG while the category card
includes it means two numbers on one screen will not add up.

### L2. Untested classes that carry real risk

92 test files against 83 top-level classes, which is good coverage overall, but
these have none and are not incidentally exercised:

| Class | Lines | Why it matters |
|---|---|---|
| `digest_unsubscribe_token` | 150 | HMAC verification for unauthenticated unsubscribe links |
| `rate_limiter` | 144 | abuse control |
| `integrity_checker` | 538 | largest untested class |
| `rate_card_refresher` | 147 | can rewrite every price on the site |
| `anonymizer` | 39 | the pseudonym function behind every PII gate |

`anonymizer` is 39 lines and one function, and it is now load-bearing for four
export sections. Its `crc32 % 9999` construction also means pseudonyms collide on
any site with more than ~10k learners, which is worth knowing before dashboards
are built on `user_ref` as a grouping key.

### L3. `project_monthly_spend()` window bound

`llm_optimizer.php:237` finds the earliest timestamp over `role='assistant'` rows
to decide how many days of data a projection covers. The spend it projects now
includes RAG, so a site whose RAG spend predates its first chat message would get a
slightly short window. Harmless in practice, noted for completeness.

### Checked and clean

- Zero `TODO`, `FIXME`, `XXX`, `HACK` markers in shipped PHP.
- Zero `var_dump` / `print_r` / `error_log` / `console.log` in shipped PHP or `amd/src`.
- All four `NO_MOODLE_COOKIES` endpoints authenticate: `redash_export.php` and
  `talking_avatar_webhook.php` by `hash_equals`, `digest_unsubscribe.php` and
  `email_unsubscribe.php` by HMAC token verification. My first grep suggested the
  unsubscribe pair was unauthenticated; that was a false positive from matching on
  function names rather than reading them.
- `.wiki` being untracked is not a defect: it is the GitHub wiki's own clone.
- The two remaining `role='assistant'` filters in `llm_optimizer` are correct as
  written (a ratings join, and L3 above).

---

## On the version number

7.0.0 is defensible, but for integration reasons rather than feature reasons.

This cycle changes contracts that consumers can be relying on:

- `redash_export.php` payload shape: `student_usage` loses `userid` and `name` in
  favour of `user_ref`; `survey_responses` non-anonymized rows move from `user_ref`
  to `userid`; `token_costs` gains `category`; the response gains `sections` and
  `since`.
- `sections` absent still returns everything, but `since` absent now means 90 days
  rather than all time, so an existing data source silently changes what it pulls.
- `anonymize=0` now returns 403 unless explicitly enabled, which will break any
  consumer that used it.
- Reported metric values move: `total_messages` drops by the telemetry it used to
  count, and Tokens (30d) rises by the RAG spend it used to omit.
- A configured RAG cap now enforces where it previously did nothing.

Those are breaking changes to an integration surface plus intentional changes to
published numbers, which is what a major bump is for. Two practical notes: the
Moodle `version` integer must still increase monotonically regardless of the
release string, and the plugin directory listing tracks its own version (v6.8.3 for
the CONTRIB-10574 resubmission), so a major bump is a good moment to decide whether
the directory entry follows.

## Suggested order

1. Rebuild `sse_client.min.js` and confirm the caps appear in the bundle (B1).
2. Fix M2, one-line, plus the `key` hint in the masking guard so it cannot recur.
3. Decide M1, including what to do about the first anomaly comparison after the
   population changes.
4. i18n sync for the eight keys (M3).
5. Run the release checklist end to end (B2), with the export payload changes and
   the moved metric values called out prominently in the notes.
