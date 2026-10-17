# SOLA 7.9.0: one "Providers and keys" table, and no secret ever shown in settings

Status: APPROVED by Tom on 2026-10-16 (the written spec, and all four decisions in section 11 as recommended). Nothing is built yet.
Amended the same day, during planning, to correct two things found while reading the code (the Voice providers table in sections 2, 3 and 5.7, and how the legacy key is kept in step in section 5.3). Both keep the approved behavior. Written 2026-10-16. Requested by the AI Spend/MFA session on Tom's behalf, plus Tom's own addition (section 3).

## 1. What we are building, and how we will know it worked

**Purpose.** An admin should be able to add or fix the API key for any provider without touching the default provider, tell which key is which, and never see a secret on screen. The trigger was the 2026-10-15/16 key rotation, where entering an OpenAI key seemed to require switching the default chat provider first, which would have rerouted every student's chat on save.

**Success criteria**

1. Adding or editing any provider's key, model or base URL never requires changing "Default chat AI provider".
2. Every stored key shows the provider it belongs to and its last four characters, so it can be matched to a provider console.
3. A site that configures nothing new behaves identically after the upgrade (same key used for the same call, same failover, same LLM picker).
4. **No secret value appears in the HTML of any SOLA settings page, on any render: first load, reload, after a successful save, or after a failed save.** (Section 3.)
5. Provider keys are encrypted at rest, all of them. Nothing moves from encrypted to plain text.
6. Embedding, Rerank and Voice/TTS can point at a provider row instead of needing the key pasted again.
7. Nothing new gets silently switched on by adding a key (for example adding an OpenAI key as a failover must not turn on the TTS speak button).

**Out of scope.** Prod Learn and Degrees stay on 7.5.7 until 2027 and `sola-config.json` is not touched, so this ships to the dev fleet and the Marketplace only. Per-course key overrides and `course_key_guard` keep working unchanged. Failover semantics, spend caps and the LLM picker's behavior do not change.

## 2. Current state (verified, not assumed)

Checked on the code at main (7.8.9) and on the live dev settings page on 2026-10-16.

- Where things live: on the settings page "AI provider and models" (`local_ai_course_assistant_ai`): `provider` (13 options including `auto`), `apikey` (`admin_setting_encryptedpassword`), `model`, `apibaseurl`, and the `comparison_providers` table. The failover chain and `failover_per_call_enabled` are on the operations page.
- `apikey` belongs to the site provider only. A different provider needs a `comparison_providers` row, stored as `provider|key|models|temperature|baseurl`. Other keys sit in `embed_apikey`, `rerank_apikey`, `realtime_apikey` ("OpenAI API Key (Voice & TTS)"), `stt_selfhosted_apikey` and the Voice providers table. The same OpenAI key can be pasted in four places.
- **`comparison_providers` is stored in plain text** (not in `secrets::names()`, no `conceal_row_keys`). The main `apikey` is encrypted. The Voice providers table already encrypts per row (`secrets::conceal_row_keys`, `voice_registry::parse_rows`), which is the pattern to copy for storage. Its renderer is not compliant, though: `voice_registry::parse_rows(false)` returns the stored ciphertext, and the form writes that into each row's password input and into a hidden field, so the page carries ciphertext whenever the table has rows. Dev has no voice rows, which is why the first audit missed it.
- **Secrets are visible in the page today.** On dev, the comparison table renders 8 visible password inputs, each carrying a real key in its `value` attribute (lengths 50 to 164), and a hidden serialized field of 1,005 characters holding all eight rows with their keys. Every single-key field (`apikey`, embed, rerank, realtime, STT, avatar providers, Zendesk, Redash, spend export) renders empty, because Moodle's `admin_setting_encryptedpassword` outputs only a set/unset flag, never the value.
- Two plain visible text inputs hold bearer secrets when set: `anomaly_digest_slack_webhook` and `anomaly_digest_teams_webhook` (empty on dev, but a webhook URL is a credential).
- The table cannot save a keyless row (ollama, coreai) because the JS drops rows without a key.
- 7.8.8 already warns, after a save and on Reports > Status, about a failover line with no matching row, a row with no key, and a model written with capitals or spaces (`failover_verifier::static_problems()`). That check covers rows only, not the primary `model` setting.
- What an OpenAI key silently enables today (`voice_registry::is_configured()`): true if any Voice row exists, or `realtime_apikey` is set, or `provider` is openai and `apikey` is set. That turns on the TTS speak button, a Speaking Practice or Conversation Practice chip where a course enables it, and the STT fallback, even with Voice Mode off.

Corrections to the request that prompted this work: Learn's failover chain is already just `chat:openai` (the dead `chat:xai` line is gone), and the `GPT-4o-mini` display-name row was lowercased on 2026-10-12 and verified, so it no longer explains the chronic failures. Validation of missing rows and display-name models already shipped in 7.8.8.

## 3. The secret display rule (Tom's requirement)

> Hide all visible password and key fields every time anything is saved or reloaded in SOLA settings.

**Rule.** No key, token, secret or credential URL, and no ciphertext of one, may appear anywhere in the HTML or JavaScript arguments of a SOLA settings page. That means no `value` attribute (including password inputs, where the value is hidden on screen but still in the page source), no hidden input, no `data-` attribute, no textarea content, no `js_call_amd` argument, no success or error notice, and no echo of submitted values after a failed save.

**How each kind of field complies**

| Field | Today | 7.9.0 |
|---|---|---|
| Single-key fields (`admin_setting_encryptedpassword`) | Compliant (empty input, set flag) | Unchanged, plus a masked last-four hint |
| Comparison / provider rows | **Violates**: real key in password input and in a hidden field | New table never renders a key or ciphertext; see section 5.4 |
| Slack and Teams webhook URLs | **Violates when set**: plain text input | Become encrypted password fields; upgrade encrypts existing values; readers use `secrets::get()` |
| Voice providers table | **Violates when it has rows**: stored ciphertext in each password input and in a hidden field | Moves onto the same row editor as the provider table; nothing is rendered (section 5.7) |
| After a failed save | Moodle re-renders submitted values | Key inputs always come back empty with "re-enter the key" help |

**How we prove it.** A new test renders every SOLA settings page with a known sentinel string seeded into every secret setting and every table row, runs a simulated save round trip and a failed save, and asserts that neither the sentinel nor any stored ciphertext appears in any output. A second test fails if a settings class that holds a credential is anything other than an encrypted or masked type. We also scan the real dev and alpha settings pages after the deploy.

## 4. Approaches considered

**A. Reuse `comparison_providers` storage, encrypt the key column, and put one accessor class in front of every reader. (Recommended.)** Keeps the stored format, the failover chain, spend guard, the LLM picker and the policy bundle working. All the risk concentrates in one new class and one upgrade step, both easy to test.

**B. A brand-new store (a new setting or table) with `comparison_providers` derived from it.** Cleaner in theory. It forces a rewrite of every reader at once, leaves two sources of truth to keep in sync, and gains nothing visible to the admin. Rejected.

**C. Relabel and reorder the existing page only.** Does not fix keys being in plain text, does not stop the pasted-in-four-places problem, and does not meet the display rule. Rejected.

## 5. Design (approach A)

### 5.1 Storage and encryption
- The setting keeps the name `comparison_providers` and the five-column format. Column 1 (the key) is stored encrypted with `secrets::conceal()`. Because `reveal()` passes un-encrypted values through, a mix of old plain rows and new encrypted rows is safe during and after the upgrade.
- It is deliberately not added to `secrets::names()`: the existing settings-tree test pins that list to `admin_setting_encryptedpassword` instances only. A separate test pins the table setting's encryption instead.

### 5.2 One accessor for every reader
New class `local_ai_course_assistant\provider_keys` is the only code that reads or writes the raw table:
- `rows(bool $reveal = false)`: each row as `id` (an opaque hash of the ciphertext, safe to put in a page), `provider`, `haskey`, `last4`, `models`, first `model`, `temperature`, `baseurl`. The key is returned only when `$reveal` is true, and only server-side.
- `key_for(string $provider)`: the first row that has a key wins (the existing rule in `lookup_chat_label`), then the legacy `apikey` if the provider is the site's resolved provider, else empty.
- `serialize()` and `conceal_all()`.

Every current direct reader moves to it: `spend_guard` (`lookup_chat_label`, `resolve_failover_chain`), `failover_verifier::rows`, `base_provider` (`label_for_active_provider`, `lookup_comparison_row`, `create_for_comparison`, the constructor's site-key rule), the analytics and `hook_callbacks` LLM picker lists, `provider_benchmark`, `autoupgrade\discovery::credentials`, `run_model_benchmark`, and the `run_tutor_golden` CLI. `label_for_active_provider` currently compares the row key to the plain primary key, which would silently fail on encrypted rows, so it compares revealed values through the accessor. A guard test fails on any `get_config(..., 'comparison_providers')` outside the accessor, in the same spirit as `secrets_test::test_no_secret_is_read_around_the_helper`.

### 5.3 Key resolution and parity
Order for provider P: keyed row for P, then the legacy `apikey` if P is the site's provider (`auto` resolves exactly as `base_provider::resolve_auto_provider()` does today), then none (keyless providers such as ollama are allowed).

**How the legacy key is kept in step.** About eleven places read the legacy `apikey` through `secrets::get('apikey')` (the `base_provider` constructor, `create_for_comparison`, `resolve_auto_provider`, course config, the voice registry, benchmark tools). Rewriting all of them is the riskiest part of this work, so they stay untouched. Instead `apikey` becomes an automatic mirror of the default provider's row key: whenever the table, the default provider, the model or the base URL changes, the key of the row for the site's provider is re-copied into `apikey`, encrypted. Readers of `apikey` therefore see the key the table says, with no change to their code. (Amended after the final review: Moodle fires an updated-callback only when a value changes, not on every save. The row that counts is the first keyed row whose base URL equals the API Base URL setting, both trimmed, and an empty row URL matches an empty API Base URL. The signed policy bundle, the automatic model switcher and its rollback write settings with `set_config()`, which fires no callback, so each calls `provider_keys::sync_after_writes()` after a write of `provider`, `model` or `apibaseurl`.)

Consequences, all wanted:
- Editing the default provider's key in the table changes what chat uses. Nothing else needs touching.
- Switching the default provider from Gemini to OpenAI picks OpenAI's row key, not the leftover Gemini key. If the new default has no keyed row (and is not keyless), the next admin page after the save shows a warning, and a key that clearly belongs to another vendor (`course_key_guard::owner_of_key`) is called out by name. (Amended after the final review: a second warning covers a default whose keyed row has a base URL of its own that differs from API Base URL while no keyed row matches. The mirror copies none of them, so chat still uses the previous default key. It stays quiet on a site where `apikey` or the table is forced in `config.php`.)
- The `apikey` setting is no longer shown on the page (decision 4) but stays declared and encrypted. `config.php` forced values and CLI tools keep working.
- On 7.8.x the `apikey` mirror still works, so the default provider's key does, but 7.8.x does not understand the encrypted row keys. (Amended after the final review: this is not a re-entry job. Moodle refuses a code-only downgrade, so rolling back means restoring the database from a backup taken before the upgrade.)

### 5.4 The admin UI
- A new setting class `admin_setting_provider_keys` replaces `admin_setting_comparison_providers` on the AI page, under a heading **"Providers and keys"** placed above "Default chat AI provider", so the table is the place keys live and the dropdown only chooses which row is the default.
- Columns: Provider (select of valid ids), API key, Models (first is the default), Temperature, Base URL, Remove. An "Add provider" button adds a row.
- The key cell for a saved row shows **"Saved, ends in ab12"** and a **Change** button that reveals an empty input. A new row has an empty input. Keyless providers can be saved without a key.
- The form posts plain arrays (`provider[]`, `newkey[]`, `keepid[]`, `models[]`, `temp[]`, `baseurl[]`). The old hidden field that carried every key is removed. On save, a blank key with a `keepid` keeps the stored ciphertext for that row; a filled key replaces it. The server builds and encrypts the stored string. Keys are never sent to the browser.
- The row `id` is a hash of the ciphertext. The encryption uses a random nonce, so the hash reveals nothing about the key.
- Inline validation reuses `failover_verifier::static_problems()` strings: a failover line with no row, a row with no key (for a keyed provider), a model that looks like a display name. The display-name check is extended to the primary `model` setting. A duplicate provider is allowed, with a warning on save (amended at plan time: `admin/cli/run_tutor_golden.php` deliberately reads two rows for one provider, so the UI must not remove that). The accessor keeps the existing rule, the first row that has a key serves the provider, which is why one provider and base URL can't hold two keys as a primary and a failover backup.
- Config-log redaction (`redact_keys`) is updated so neither plain keys nor ciphertext land in the log.

### 5.5 Upgrade (`db/upgrade.php`, one idempotent step, version 2026101601, release 7.9.0)
Modeled on the 7.7.3 step (2026100114):
1. Encrypt every plain key in `comparison_providers` (`conceal_row_keys`).
2. If the site's provider is a concrete id (or `auto` resolvable from the model) and no keyed row exists for it under the same base URL, copy `apikey` into a new row for that provider. `apikey` itself is not deleted. (Amended after the final review: nothing is added, and nothing is said, when the key's own prefix clearly belongs to another vendor, for example `auto` with a Gemini key and no model resolves to openai, so no openai row is filed. A gateway base URL turns that check off. `auto` with no key adds nothing.)
3. If a keyed row for that provider already exists with a **different** key than `apikey`, the primary path used `apikey` and the row served only the picker and same-provider failover, so the migration sets the row to the `apikey` value (the key production chat actually uses) and records an audit row and an admin notice naming the provider and both last-four values. See open decision 1.
4. Encrypt the Slack and Teams webhook URLs.
5. Leave `provider`, `model`, `apibaseurl`, the failover chain and course-level settings untouched.

A parity test resolves the key for every provider before and after the step on seeded configurations (no rows, rows only, `auto`, conflicting keys, forced settings) and asserts identical results except the one documented conflict case.

### 5.6 Embedding, Rerank and Voice/TTS
- New settings `embed_key_from`, `rerank_key_from` and `realtime_key_from`: a select of "Use the key pasted below" plus the provider ids that have a row. If a row is chosen and exists, its key is used; otherwise the field's own key. Existing pasted keys keep working. (Amended after the final review: Embedding and Rerank pass over a chosen row whose key is clearly another vendor's, unless a base URL is set for that feature. They also pass over a row that has its own base URL unless it is exactly the URL the feature sends to, so an Azure or gateway row is never used against the vendor's default endpoint; a trailing slash or a spelled-out default URL counts as a mismatch. In every such case the pasted key is used. Voice uses the first OpenAI row that has a key and no base URL of its own. A `chat:voyage` failover line is reported as having no usable row, because `voyage` rows are key-only.)
- `voyage` becomes a key-only provider id in the table for embedding and rerank. Chat code, failover and the LLM picker ignore rows whose id is not a chat provider.
- **No new silent enablement.** `voice_registry::is_configured()` and `resolve()` are not changed to look at the table. An OpenAI failover row does not turn on TTS. Voice features follow an explicit "use the key from row X" choice or the existing behavior only.
- Voice section help text states the existing behavior accurately: an OpenAI key (a Voice row, `realtime_apikey`, or the default provider being OpenAI with a key) also enables the TTS speak button, the per-course Speaking Practice chip, and the speech-to-text fallback, even with Voice Mode off. The exact chip names are checked against the lang strings and `starter_manager.php` at plan time.

### 5.7 Voice providers table
Same treatment as the provider table, because it breaks the display rule today. It moves onto the shared row editor (section 5.4): columns provider (openai or xai), label, realtime voice and TTS voice; the key cell is "Saved, ends in ab12" with Change; keys are never rendered; blank keeps the stored ciphertext through the row id; the stored format `provider|key|label|realtime_voice|tts_voice` and `voice_registry` do not change.

### 5.8 Single-key fields
They stay `admin_setting_encryptedpassword` (already compliant). A small subclass adds the masked last-four hint to the description. The Slack and Teams webhook fields change to the same class.

## 6. Security notes
- Keys encrypted at rest everywhere; no new plain-text store.
- Never print or log a key in tests, the audit log, HTML or diagnostics. Tests use sentinel strings, not real keys.
- Last four characters: shown only as a masked hint, never an input value, and only because matching keys to provider consoles needs it (open decision 2).
- Rollback caveat: 7.8.x does not understand encrypted rows and would send ciphertext as the key. Moodle refuses a code-only downgrade, so rolling back from 7.9.0 means restoring the database from a backup taken before the upgrade (amended after the final review; this section first said to re-enter the row keys). The release notes say so.
- The sola-reviewer security pass (IDOR, XSS, token leakage) is a required step before merge.

## 7. Testing and review plan
- Unit and integration tests for: the accessor (reveal and no-reveal, ordering, duplicates), encryption of rows, the parity matrix, the upgrade step (idempotent, conflict case, forced settings), save semantics (blank keeps, filled replaces, keyless row, a duplicate provider is allowed with a warning, failed save echoes nothing), and the no-leak render test of section 3. A guard test for raw reads of the table.
- Mutation proofs through `scripts/prove_test.py` for the invariants that matter (key not rendered, blank-keep, encryption on write, accessor ordering). Read the test COUNT every run: a version bump makes the next phpunit run execute zero tests until `php admin/tool/phpunit/cli/init.php` is re-run, and several test paths in one command run only the first. Rsync the repo into the Moodle tree after test edits.
- Full suite on PHP 8.1 to 8.3 through CI, plus the validators.
- Browser smoke on dev and alpha after deploy: the table loads without any key in the DOM (scan names and lengths, never print values), add and edit a key without changing the default, save and reload twice.

## 8. Translations
Every new admin string needs all 45 locales (the `lang_completeness_test` parity check). Plan: write the English strings in the same commit as the code that uses them, translate in parallel batches, apply with `scripts/apply_i18n.py`, and run a back-translation check on the lesser-resourced locales as for 7.8.9 (Bambara and Wolof fall back to English if the check shows meaning loss). Strings and release notes follow Tom's writing rules: no em dashes, contractions, US English.

## 9. Release and rollout
1. Branch `feat/7.9.0-provider-keys` in a worktree; PRs into `main`; CI green; sola-reviewer clean.
2. Version 7.9.0 (a minor release, so the full three-part `.drafts` release draft: notes, Key SOLA Features snapshot, 20-minute admin walkthrough).
3. Tag, GitHub release with the zip, wiki changelog through `push_wiki.py` (Admin-Settings page rewritten for the new table).
4. Deploy to dev and alpha with `~/ai-projects/ecs-dev-deploy/deploy_sola.sh` (dry-run first, one site at a time), then the live checks in section 7.
5. Marketplace upload needs Tom's browser. Tom is told when the 7.9.0 zip is ready; a Jira note on CONTRIB-10574 only if he asks.
6. Prod Learn and Degrees are not touched.

## 10. Risks
- The accessor touches about ten readers. Mitigation: one class, a guard test against raw reads, the parity matrix.
- Encrypting rows changes what a 7.8.x rollback sees. Mitigation: documented, and only affects row keys.
- Moodle core could change how `admin_setting_encryptedpassword` renders. Mitigation: the no-leak render test runs on every supported Moodle in CI.
- Size: roughly 15 files plus about 30 new strings in 45 locales. Mitigation: staged PRs (accessor and migration first, then the table UI, then the other key fields and help text).

## 11. Decisions for Tom (all four approved as recommended on 2026-10-16)

1. **Conflicting keys.** If the default provider's key and an existing row for the same provider differ, the upgrade makes the row use the default's key (what production chat uses today) and shows a notice. Alternative: leave both and only warn. Recommended: the first.
2. **Last four characters.** Show them as a masked hint next to every stored key (the other session asked for this). It does reveal four characters of each key. Recommended: yes.
3. **`voyage` as a key-only provider id** so Embedding and Rerank can reuse a row. Recommended: yes.
4. **The old "API Key" field under "Default chat AI provider".** Remove it from the page (the table becomes the only place, the stored `apikey` stays as a fallback for one release) or keep it visible and relabeled. Recommended: remove it, because two places to edit the same key is the confusion we are fixing.
