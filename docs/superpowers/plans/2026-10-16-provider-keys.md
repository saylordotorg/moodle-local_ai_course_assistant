# SOLA 7.9.0 Providers and Keys Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One encrypted "Providers and keys" table that holds every provider key, with no key or ciphertext ever rendered into a SOLA settings page, and no change in behavior for a site that configures nothing new.

**Architecture:** Keep the `comparison_providers` setting and its five-column format, but store each key encrypted and put one accessor class (`provider_keys`) in front of every reader. The legacy `apikey` setting stays as an automatic mirror of the default provider's row key, so the eleven places that read it do not change. A shared row-editor setting class renders tables without ever outputting a key (a "Saved, ends in ab12" label, a Change button, and a hash row id that lets a blank key keep the stored one). The Voice providers table moves onto the same editor.

**Tech Stack:** PHP 8.1 to 8.3, Moodle 4.5 to 5.2 admin settings API, `\core\encryption` through `secrets`, Mustache templates, an AMD module built with terser, PHPUnit (advanced_testcase), `scripts/apply_i18n.py` for 45 locales.

**Spec:** `docs/superpowers/specs/2026-10-16-provider-keys-design.md` (approved by Tom on 2026-10-16, all four decisions as recommended). Read it first. Where this plan departs from it, the departure is listed in "Plan-time decisions" below and flagged for Tom.

## Plan-time decisions (differences from the spec)

1. **Duplicate provider rows are allowed, with a warning, not rejected.** The spec said a new duplicate is rejected. `admin/cli/run_tutor_golden.php` deliberately reads two rows for one provider (the A.10 bake-off uses two Claude rows), and the UI must not remove that. The accessor keeps the existing rule: the first row that has a key serves the provider.
2. **The legacy `apikey` is a mirror, not a fallback** (already amended into spec section 5.3).
3. **`apikey` is declared in `secrets::names()` but no longer on the settings page**, so the settings-tree test gets one explicit exemption list (`secrets::NOT_ON_SETTINGS_PAGE`).

## Global Constraints

- Prod Learn and Degrees stay on 7.5.7 until 2027. Do NOT edit `sola-config.json`. This ships to the dev fleet and the Marketplace only.
- Keys stay encrypted at rest (7.7.3 behavior). Never print or log a key value in tests, the audit log, HTML or diagnostics. Tests use sentinel strings.
- No key, token, secret, credential URL, or ciphertext of one may appear in the HTML or JS arguments of any SOLA settings page on any render: first load, reload, after a save, or after a failed save.
- A site that configures nothing new behaves identically after the upgrade (same key for the same call, same failover, same LLM picker).
- Version: `$plugin->version = 2026101601;` `$plugin->release = '7.9.0';`.
- Every new admin string needs all 45 locales (`lang_completeness_test`). Commit `lang/en` in the SAME commit as the code that references it.
- Strings, comments that users read, and release notes: no em dashes, use contractions, US English.
- PHPUnit traps: put PHP 8.3 first on PATH; a `version.php` bump makes the next run execute zero tests until `php admin/tool/phpunit/cli/init.php` is re-run, so READ THE TEST COUNT every run; passing several test files runs only the first; rsync the repo into the Moodle tree after any test edit; use `--testsuite local_ai_course_assistant_testsuite` for the full suite, never a bare directory.
- Do not add a `CONTRIB-10574` prefix to commits (that is for Marketplace-ticket work only).

## Test runner recipe (used by every task)

```bash
export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH"
W=~/ai-projects/wt-provider-keys
rsync -a --delete --exclude=.git --exclude=.wiki --exclude=docs --exclude=.drafts "$W"/ ~/Sites/moodle/local/ai_course_assistant/
cd ~/Sites/moodle && php vendor/bin/phpunit local/ai_course_assistant/tests/<one_file>.php
```
Run ONE file per command. Expected success line: `OK (N tests, M assertions)`; note N.

## Review Focus

Input classes the spec implies but a straightforward build would not test. Each has a named test in the owning task.

1. **Saving the table with no key typed** must leave the stored string byte-identical (no re-encryption, no spurious "settings changed" notice). Task 5.
2. **A row whose ciphertext cannot be decrypted** (database restored onto another site): shows "Saved" with no last four, is treated as no key, and a save does not silently wipe it. Task 1 and Task 5.
3. **A stale or unknown `keepid`** (the row was removed by another admin): must not attach some other row's key; it becomes "needs a key". Task 5.
4. **`auto` with no legacy key** (a core_ai-only site): the mirror and the migration must not make the site start using a direct provider. Tasks 3 and 4.
5. **Keyless providers** (ollama, coreai) save without a key and resolve. Tasks 1 and 5.
6. **A failed save**: Moodle hands the submitted array to `output_html()`; a key typed into the form must never come back in the HTML. Task 9.

---

## File structure

Create:
- `classes/provider_keys.php`: the accessor, mirror sync, upgrade routine.
- `classes/admin_setting_keyed_rows.php`: shared row-editor base (no key ever rendered).
- `classes/admin_setting_provider_keys.php`: the Providers and keys table.
- `classes/admin_setting_secret_field.php`: single-key field with a last-four hint.
- `templates/admin_keyed_rows.mustache`, `templates/admin_keyed_row.mustache`.
- `amd/src/keyed_rows.js` and built `amd/build/keyed_rows.min.js` (+ map).
- Tests: `tests/provider_keys_test.php`, `tests/provider_keys_no_raw_read_test.php`, `tests/provider_keys_sync_test.php`, `tests/provider_keys_upgrade_test.php`, `tests/admin_setting_provider_keys_test.php`, `tests/admin_setting_voice_providers_test.php`, `tests/settings_no_secret_in_page_test.php`, `tests/provider_key_sources_test.php`.

Modify: `classes/spend_guard.php`, `classes/failover_verifier.php`, `classes/provider/base_provider.php`, `classes/hook_callbacks.php`, `analytics.php`, `classes/provider_benchmark.php`, `classes/autoupgrade/discovery.php`, `classes/task/run_model_benchmark.php`, `admin/cli/run_tutor_golden.php`, `classes/admin_setting_voice_providers.php` (rewritten), `classes/secrets.php`, `classes/task/run_anomaly_digest.php`, `classes/embedding_provider/base_embedding_provider.php`, `classes/embedding_provider/voyage_reranker.php`, `classes/voice_registry.php`, `settings.php`, `db/upgrade.php`, `version.php`, `lang/en/local_ai_course_assistant.php`, the 45 other lang files, `tests/secrets_test.php`, `tests/settings_secret_masking_test.php`.

Delete: `classes/admin_setting_comparison_providers.php` (only `settings.php` references it).

Branches (stacked PRs, merged in order, one release): `feat/7.9.0-keys-foundation` (Tasks 0 to 4, base `main`), `feat/7.9.0-keys-ui` (Tasks 5 to 9, base = foundation), `feat/7.9.0-keys-sources` (Tasks 10 to 12, base = ui). Create each from the previous with `git checkout -b <new> <previous>`; retarget with `gh pr edit <n> --base main` after the predecessor merges.

---

### Task 0: Baseline and branch

**Files:** none changed.

- [ ] **Step 1: Make the foundation branch.** The approved spec, its amendment and this plan are already committed on `feat/7.9.0-provider-keys`. Rename that branch so it becomes PR 1, and make sure it sits on the current `main`:

```bash
cd ~/ai-projects/wt-provider-keys
git fetch -q origin
git branch -m feat/7.9.0-provider-keys feat/7.9.0-keys-foundation
git rebase origin/main
git push -u origin feat/7.9.0-keys-foundation
git push origin --delete feat/7.9.0-provider-keys 2>/dev/null || true
```

- [ ] **Step 2: Record the green baseline**

Run the full suite once and write down the test count (call it BASE_COUNT):

```bash
export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH"
rsync -a --delete --exclude=.git --exclude=.wiki --exclude=docs --exclude=.drafts ~/ai-projects/wt-provider-keys/ ~/Sites/moodle/local/ai_course_assistant/
cd ~/Sites/moodle && php admin/tool/phpunit/cli/init.php >/dev/null 2>&1; php vendor/bin/phpunit --testsuite local_ai_course_assistant_testsuite 2>&1 | tail -4
```
Expected: `OK (2416 tests, ...)` give or take the tests added since the 7.8.9 note (7 skipped). Every later full run must show BASE_COUNT plus the tests this plan adds, never fewer.

---

### Task 1: The `provider_keys` accessor

**Files:**
- Create: `classes/provider_keys.php`
- Test: `tests/provider_keys_test.php`

**Interfaces:**
- Produces (all `public static`, class `local_ai_course_assistant\provider_keys`):
  - consts `PLUGIN`, `SETTING`, `CHAT_PROVIDERS`, `KEY_ONLY_PROVIDERS`, `KEYLESS`
  - `stored_rows(): array`: server-side only; each row `provider, label, stored_key, models, temperature, baseurl, columns, id`
  - `rows(bool $reveal = false): array`: each row `id, provider, label, haskey, last4, key, models, model, temperature, baseurl, columns` (`key` is `''` unless `$reveal`)
  - `by_label(): array`: lowercase provider => serving row (first keyed row, else first row)
  - `row_for(string $provider, bool $reveal = false): ?array`: first row with `columns >= 2`
  - `for_provider(string $provider): ?array`: first row WITH a key, key revealed
  - `key_for(string $provider): string`
  - `serialize(array $rows): string`, `rowid(string $provider, string $stored): string`, `last4(string $plain): string`

- [ ] **Step 1: Write the failing test**

```php
<?php
// This file is part of Moodle - http://moodle.org/
// (standard GPL header, copy from tests/secrets_test.php)

namespace local_ai_course_assistant;

/**
 * The provider table is read through one accessor, with keys revealed only on request.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider_keys
 */
final class provider_keys_test extends \advanced_testcase {

    private function seed(string $rows): void {
        set_config('comparison_providers', $rows, 'local_ai_course_assistant');
    }

    public function test_plain_and_encrypted_rows_read_the_same(): void {
        $this->resetAfterTest();
        $enc = secrets::conceal('sk-ant-CLAUDE-KEY-0001-ABCD');
        $this->seed("openai|sk-proj-OPENAI-KEY-0002-WXYZ|gpt-4o-mini\nclaude|{$enc}|claude-haiku-4-5|0.5|https://x.example");
        $rows = provider_keys::rows(true);
        $this->assertSame('sk-proj-OPENAI-KEY-0002-WXYZ', $rows[0]['key']);
        $this->assertSame('sk-ant-CLAUDE-KEY-0001-ABCD', $rows[1]['key']);
        $this->assertSame('claude-haiku-4-5', $rows[1]['model']);
        $this->assertSame('0.5', $rows[1]['temperature']);
        $this->assertSame('https://x.example', $rows[1]['baseurl']);
        $this->assertSame(5, $rows[1]['columns']);
    }

    public function test_rows_without_reveal_never_carry_a_key_or_the_stored_value(): void {
        $this->resetAfterTest();
        $enc = secrets::conceal('sk-proj-SENTINEL-KEY-9999-QRST');
        $this->seed("openai|{$enc}|gpt-4o-mini");
        $json = json_encode(provider_keys::rows());
        $this->assertStringNotContainsString('SENTINEL', $json);
        $this->assertStringNotContainsString(substr($enc, strpos($enc, ':') + 1, 24), $json);
        $this->assertTrue(provider_keys::rows()[0]['haskey']);
        $this->assertSame('QRST', provider_keys::rows()[0]['last4']);
    }

    public function test_last4_is_shown_only_for_a_long_key(): void {
        $this->assertSame('', provider_keys::last4('short-key'));
        $this->assertSame('6789', provider_keys::last4('sk-0123456789'));
        $this->assertSame('', provider_keys::last4(''));
    }

    public function test_for_provider_takes_the_first_row_that_has_a_key(): void {
        $this->resetAfterTest();
        $this->seed("openai||gpt-4o-mini\nopenai|sk-proj-SECOND-ROW-KEY-1234|gpt-4o\nopenai|sk-proj-THIRD-ROW-KEY-5678|x");
        $row = provider_keys::for_provider('OpenAI');
        $this->assertSame('sk-proj-SECOND-ROW-KEY-1234', $row['key']);
        $this->assertSame('gpt-4o', $row['model']);
        $this->assertSame('sk-proj-SECOND-ROW-KEY-1234', provider_keys::key_for('openai'));
        $this->assertNull(provider_keys::for_provider('gemini'));
        $this->assertSame('', provider_keys::key_for('gemini'));
    }

    public function test_by_label_prefers_a_keyed_row_over_an_earlier_keyless_one(): void {
        $this->resetAfterTest();
        $this->seed("openai||a\nopenai|sk-proj-KEYED-ROW-0000-ZZZZ|b");
        $this->assertTrue(provider_keys::by_label()['openai']['haskey']);
        $this->assertSame('b', provider_keys::by_label()['openai']['model']);
    }

    public function test_row_for_needs_two_columns_like_the_old_lookup(): void {
        $this->resetAfterTest();
        $this->seed("ollama\nopenai|sk-proj-AAAA-BBBB-CCCC-DDDD|m");
        $this->assertNull(provider_keys::row_for('ollama'));
        $this->assertSame('m', provider_keys::row_for('openai')['models']);
        $this->assertSame('sk-proj-AAAA-BBBB-CCCC-DDDD', provider_keys::row_for('openai', true)['key']);
    }

    public function test_the_row_id_is_stable_and_does_not_contain_the_key(): void {
        $this->resetAfterTest();
        $enc = secrets::conceal('sk-proj-ID-TEST-KEY-1234-5678');
        $id = provider_keys::rowid('openai', $enc);
        $this->assertSame($id, provider_keys::rowid('openai', $enc));
        $this->assertNotSame($id, provider_keys::rowid('claude', $enc));
        $this->assertSame(12, strlen($id));
        $this->assertStringNotContainsString('ID-TEST', $id);
    }

    public function test_serialize_round_trips_and_writes_the_fewest_columns(): void {
        $rows = [
            ['provider' => 'openai', 'stored_key' => 'K1', 'models' => 'm1', 'temperature' => '', 'baseurl' => ''],
            ['provider' => 'claude', 'stored_key' => 'K2', 'models' => '', 'temperature' => '0.3', 'baseurl' => ''],
            ['provider' => 'custom', 'stored_key' => 'K3', 'models' => 'x', 'temperature' => '', 'baseurl' => 'https://g.example'],
            ['provider' => 'ollama', 'stored_key' => '', 'models' => 'llama3', 'temperature' => '', 'baseurl' => ''],
        ];
        $this->assertSame("openai|K1|m1\nclaude|K2||0.3\ncustom|K3|x||https://g.example\nollama||llama3", provider_keys::serialize($rows));
    }

    /** Review focus 2: a key that cannot be decrypted reads as "has a key" but yields nothing. */
    public function test_an_undecryptable_key_is_present_but_empty(): void {
        $this->resetAfterTest();
        $this->seed('openai|sodium:AAAAnotarealciphertextAAAAAAAAAAAAAAAAAAAAAAAA|gpt-4o-mini');
        $row = provider_keys::rows(true)[0];
        $this->assertTrue($row['haskey']);
        $this->assertSame('', $row['key']);
        $this->assertSame('', $row['last4']);
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }

    /** Review focus 5: keyless providers resolve with no key. */
    public function test_a_keyless_row_is_listed_and_has_no_key(): void {
        $this->resetAfterTest();
        $this->seed('ollama||llama3||http://localhost:11434');
        $row = provider_keys::rows(true)[0];
        $this->assertSame('ollama', $row['provider']);
        $this->assertFalse($row['haskey']);
        $this->assertNull(provider_keys::for_provider('ollama'));
        $this->assertSame('http://localhost:11434', $row['baseurl']);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run the recipe with `provider_keys_test.php`. Expected: FAIL / error `Class "local_ai_course_assistant\provider_keys" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php
// (standard GPL header)

namespace local_ai_course_assistant;

defined('MOODLE_INTERNAL') || die();

/**
 * The one place that reads and writes the provider rows (the comparison_providers setting).
 *
 * Stored format, one row per line: provider|key|models|temperature|baseurl. The key column holds
 * \core\encryption ciphertext (secrets::conceal); a plain value passes through secrets::reveal,
 * so old and new rows can sit side by side.
 *
 * Nothing outside this class and the settings classes reads the raw setting (a guard test enforces
 * it). rows() returns a key only when asked and only to server-side callers; a row id is a keyed
 * hash that is safe to put in a page.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_keys {
    /** @var string Plugin component. */
    public const PLUGIN = 'local_ai_course_assistant';
    /** @var string Setting that stores the rows. */
    public const SETTING = 'comparison_providers';
    /** @var string[] Providers chat, failover and the LLM picker can use. */
    public const CHAT_PROVIDERS = ['openai', 'claude', 'deepseek', 'gemini', 'ollama', 'minimax', 'mistral',
        'openrouter', 'together', 'xai', 'coreai', 'custom'];
    /** @var string[] Providers used only by Embedding and Rerank; chat code ignores their rows. */
    public const KEY_ONLY_PROVIDERS = ['voyage'];
    /** @var string[] Providers that work without a key, so a row for them may have none. */
    public const KEYLESS = ['ollama', 'coreai'];

    /**
     * Rows as stored. SERVER-SIDE ONLY: stored_key is ciphertext (or a legacy plain key). Never pass
     * this array to a template, a notice or a log.
     *
     * @return array[] provider, label, stored_key, models, temperature, baseurl, columns, id
     */
    public static function stored_rows(): array {
        $raw = (string) (get_config(self::PLUGIN, self::SETTING) ?: '');
        $rows = [];
        foreach (preg_split("/\r?\n/", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $provider = strtolower($parts[0] ?? '');
            if ($provider === '') {
                continue;
            }
            $stored = $parts[1] ?? '';
            $rows[] = [
                'provider'    => $provider,
                'label'       => $parts[0],
                'stored_key'  => $stored,
                'models'      => $parts[2] ?? '',
                'temperature' => $parts[3] ?? '',
                'baseurl'     => $parts[4] ?? '',
                'columns'     => count($parts),
                'id'          => self::rowid($provider, $stored),
            ];
        }
        return $rows;
    }

    /**
     * Rows for callers that need to know about keys.
     *
     * @param bool $reveal Include each row's plain key. Only server-side code that is about to send the
     *                     key to its provider may ask for it.
     * @return array[] id, provider, label, haskey, last4, key, models, model, temperature, baseurl, columns
     */
    public static function rows(bool $reveal = false): array {
        $out = [];
        foreach (self::stored_rows() as $r) {
            $plain = $r['stored_key'] === '' ? '' : secrets::reveal($r['stored_key']);
            $models = array_values(array_filter(array_map('trim', explode(',', $r['models']))));
            $out[] = [
                'id'          => $r['id'],
                'provider'    => $r['provider'],
                'label'       => $r['label'],
                'haskey'      => $r['stored_key'] !== '',
                'last4'       => self::last4($plain),
                'key'         => $reveal ? $plain : '',
                'models'      => $r['models'],
                'model'       => $models[0] ?? '',
                'temperature' => $r['temperature'],
                'baseurl'     => $r['baseurl'],
                'columns'     => $r['columns'],
            ];
        }
        return $out;
    }

    /**
     * The row that serves each provider: the first row WITH a key, else the first row.
     *
     * @return array<string, array> lowercase provider => row (no key)
     */
    public static function by_label(): array {
        $out = [];
        foreach (self::rows() as $r) {
            $key = $r['provider'];
            if (isset($out[$key]) && ($out[$key]['haskey'] || !$r['haskey'])) {
                continue;
            }
            $out[$key] = $r;
        }
        return $out;
    }

    /**
     * The first row for a provider that has at least two columns (the old lookup_comparison_row rule).
     *
     * @param string $provider
     * @param bool $reveal
     * @return array|null
     */
    public static function row_for(string $provider, bool $reveal = false): ?array {
        $provider = strtolower($provider);
        foreach (self::rows($reveal) as $r) {
            if ($r['provider'] === $provider && $r['columns'] >= 2) {
                return $r;
            }
        }
        return null;
    }

    /**
     * The first row for a provider that HAS a key (the old lookup_chat_label rule), key revealed.
     *
     * @param string $provider
     * @return array|null
     */
    public static function for_provider(string $provider): ?array {
        $provider = strtolower($provider);
        foreach (self::rows(true) as $r) {
            if ($r['provider'] === $provider && $r['haskey']) {
                return $r;
            }
        }
        return null;
    }

    /**
     * The plain key for a provider, or '' when no row has one.
     *
     * @param string $provider
     * @return string
     */
    public static function key_for(string $provider): string {
        $row = self::for_provider($provider);
        return $row === null ? '' : $row['key'];
    }

    /**
     * Turn rows back into the stored string, writing the fewest columns the parsers need.
     *
     * @param array[] $rows each with provider, stored_key, models, temperature, baseurl
     * @return string
     */
    public static function serialize(array $rows): string {
        $lines = [];
        foreach ($rows as $r) {
            $line = $r['provider'] . '|' . $r['stored_key'] . '|' . $r['models'];
            if ($r['temperature'] !== '' || $r['baseurl'] !== '') {
                $line .= '|' . $r['temperature'];
            }
            if ($r['baseurl'] !== '') {
                $line .= '|' . $r['baseurl'];
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * An opaque, stable id for a row. A keyed hash (the site identifier is the secret), so a legacy plain
     * key can never be guessed from it. Safe to put in a page.
     *
     * @param string $provider
     * @param string $stored the stored key column
     * @return string 12 hex characters
     */
    public static function rowid(string $provider, string $stored): string {
        return substr(hash_hmac('sha256', $provider . '|' . $stored, get_site_identifier()), 0, 12);
    }

    /**
     * The last four characters of a key, for the "ends in" hint. A short key shows nothing.
     *
     * @param string $plain
     * @return string
     */
    public static function last4(string $plain): string {
        return strlen($plain) >= 12 ? substr($plain, -4) : '';
    }
}
```

- [ ] **Step 4: Run the test and confirm it passes**

Run the recipe with `provider_keys_test.php`. Expected: `OK (10 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
cd ~/ai-projects/wt-provider-keys
git add classes/provider_keys.php tests/provider_keys_test.php
git commit -m "feat: provider_keys accessor for the provider table, keys revealed only on request

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016qGUU4qerkRHrXTh1qyBp6"
```

---

### Task 2: Move every raw reader onto the accessor, and guard against new ones

**Files:**
- Modify: `classes/spend_guard.php:461-525`, `classes/failover_verifier.php:78-108`, `classes/provider/base_provider.php:944-965 and 1224-1250`, `classes/hook_callbacks.php:739-770`, `analytics.php:786-808 and 971-988`, `classes/provider_benchmark.php:354-380`, `classes/autoupgrade/discovery.php:292-312`, `classes/task/run_model_benchmark.php:676-692`, `admin/cli/run_tutor_golden.php:836-862`
- Test: `tests/provider_keys_no_raw_read_test.php` (new); the existing `comparison_provider_credentials_test`, `comparison_providers_baseurl_test`, `failover_model_remap_test`, `failover_verifier_test`, `provider_benchmark_test`, `autoupgrade_discovery_test`, `autoupgrade_switch_test` must stay green unchanged.

**Interfaces:**
- Consumes: Task 1's `provider_keys` methods exactly as listed there.
- Produces: no raw `get_config(..., 'comparison_providers')` outside `provider_keys` and the settings classes.

- [ ] **Step 1: Write the failing guard test**

```php
<?php
// (standard GPL header)

namespace local_ai_course_assistant;

/**
 * Nothing reads the provider table around the accessor, because rows hold encrypted keys.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class provider_keys_no_raw_read_test extends \advanced_testcase {

    public function test_no_code_reads_the_provider_table_around_provider_keys(): void {
        $root = dirname(__DIR__);
        $allowed = ['classes/provider_keys.php'];
        $offenders = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $path = $file->getPathname();
            $rel = ltrim(substr($path, strlen($root)), '/');
            if (substr($path, -4) !== '.php' || preg_match('~^(tests|lang|vendor|scripts|node_modules|cdn)/~', $rel)
                    || in_array($rel, $allowed, true)) {
                continue;
            }
            if (preg_match('~get_config\([^)]*[\'"]comparison_providers[\'"]~', (string) file_get_contents($path))) {
                $offenders[] = $rel;
            }
        }
        $this->assertSame([], $offenders,
            'These read the raw comparison_providers setting. Use provider_keys::rows(), row_for(), for_provider() '
            . 'or key_for(): rows hold encrypted keys and the accessor decrypts them.');
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Expected: FAIL listing `analytics.php`, `classes/hook_callbacks.php`, `classes/provider_benchmark.php`, `classes/spend_guard.php`, `classes/failover_verifier.php`, `classes/autoupgrade/discovery.php`, `classes/provider/base_provider.php`, `classes/task/run_model_benchmark.php`, `admin/cli/run_tutor_golden.php`.

- [ ] **Step 3: Replace each reader**

**3a. `classes/spend_guard.php`.** In `resolve_failover_chain()` delete the line
`$cmpraw  = !$isvoice ? (string) (get_config('local_ai_course_assistant', 'comparison_providers') ?: '') : '';`
and change the call to `self::lookup_chat_label($label)`. Replace the whole `lookup_chat_label` method (docblock included) with:

```php
    /**
     * Look up a chat/analytics failover label in the provider rows: the first row WITH a key serves it.
     *
     * @param string $label
     * @return array|null ['provider', 'apikey', 'label', 'model', 'apibaseurl'] or null
     */
    private static function lookup_chat_label(string $label): ?array {
        $row = provider_keys::for_provider($label);
        if ($row === null) {
            return null;
        }
        return [
            'provider'   => $row['provider'],
            'apikey'     => $row['key'],
            'label'      => $label,
            'model'      => $row['model'],
            'apibaseurl' => $row['baseurl'],
        ];
    }
```

**3b. `classes/failover_verifier.php`.** Replace the body of `rows()` (keep its docblock and signature):

```php
    public static function rows(): array {
        $rows = [];
        foreach (provider_keys::by_label() as $key => $row) {
            $rows[$key] = [
                'label'   => $row['label'],
                'haskey'  => $row['haskey'],
                'model'   => $row['model'],
                'baseurl' => $row['baseurl'],
            ];
        }
        return $rows;
    }
```

**3c. `classes/provider/base_provider.php`.** Replace `label_for_active_provider` (the whole method body after the docblock) and `lookup_comparison_row`:

```php
    private static function label_for_active_provider(string $providerid, array $overrides): string {
        $apikey = (string) ($overrides['apikey'] ?? '');
        foreach (provider_keys::rows(true) as $row) {
            if ($row['provider'] === strtolower($providerid) && ($apikey === '' || $row['key'] === $apikey)) {
                return $row['provider'];
            }
        }
        return strtolower($providerid) . '-primary';
    }
```
```php
    private static function lookup_comparison_row(string $providerid): ?array {
        $row = provider_keys::row_for($providerid, true);
        if ($row === null) {
            return null;
        }
        return [
            'apikey'      => $row['key'],
            'models'      => $row['models'],
            'temperature' => $row['temperature'],
            'apibaseurl'  => $row['baseurl'],
        ];
    }
```
Add `use local_ai_course_assistant\provider_keys;` is NOT needed if the file is in namespace `local_ai_course_assistant\provider`: reference `\local_ai_course_assistant\provider_keys` fully qualified in both methods.

**3d. Pickers (`classes/hook_callbacks.php`, `analytics.php` twice).** Each loop parses `$compraw`. Replace the parsing preamble only (the lines from `$compraw = ...` through the `$seen[$pid] = true;` line) and keep each loop's tail. For `hook_callbacks.php`:

```php
            foreach (\local_ai_course_assistant\provider_keys::rows() as $row) {
                if ($row['columns'] < 2 || in_array($row['provider'], \local_ai_course_assistant\provider_keys::KEY_ONLY_PROVIDERS, true)) {
                    continue;
                }
                $pid = $row['provider'];
                if (!empty($seen[$pid])) {
                    continue;
                }
                $seen[$pid] = true;
                $models = [];
                foreach (array_filter(array_map('trim', explode(',', $row['models']))) as $m) {
                    $models[] = ['id' => $m, 'label' => $m, 'status' => 'active'];
                }
                $providers[] = [
                    'id' => $pid,
                    'label' => ucfirst($pid),
                    'models' => $models,
                ];
            }
```
For the two `analytics.php` loops use the same preamble and keep their own `$providers[] = [...]` shapes (`models_json => json_encode(array_values($models))` at 786; `'models' => $models` at 971, where `$models = array_values(array_filter(array_map('trim', explode(',', $row['models']))))`).

**3e. `classes/provider_benchmark.php` (the "Comparison providers" block).**

```php
        foreach (\local_ai_course_assistant\provider_keys::rows(true) as $row) {
            if (in_array($row['provider'], \local_ai_course_assistant\provider_keys::KEY_ONLY_PROVIDERS, true)) {
                continue;
            }
            $rows[] = [
                'id' => $row['provider'],
                'label' => $row['provider'],
                'model' => $row['model'],
                'apikey' => $row['key'],
                'temperature' => $row['temperature'] !== '' ? (float) $row['temperature'] : null,
                'is_primary' => false,
            ];
        }
```

**3f. `classes/autoupgrade/discovery.php::credentials()`.** Replace the row-parsing loop (keep the site-key block after it):

```php
        $out = [];
        foreach (\local_ai_course_assistant\provider_keys::rows(true) as $row) {
            if (!$row['haskey'] || $row['key'] === '' || isset($out[$row['provider']])) {
                continue;
            }
            $out[$row['provider']] = ['apikey' => $row['key'], 'baseurl' => $row['baseurl']];
        }
```

**3g. `classes/task/run_model_benchmark.php::has_comparison_row()`.**

```php
    private function has_comparison_row(string $providerid): bool {
        foreach (\local_ai_course_assistant\provider_keys::rows() as $row) {
            if ($row['columns'] >= 3 && $row['haskey'] && $row['provider'] === $providerid) {
                return true;
            }
        }
        return false;
    }
```

**3h. `admin/cli/run_tutor_golden.php::local_ai_course_assistant_golden_parse_comparison_providers()`.** Replace the parsing loop (keep the label-disambiguation code after it):

```php
    $out = [];
    foreach (\local_ai_course_assistant\provider_keys::rows(true) as $row) {
        if ($row['columns'] < 3 || !$row['haskey']) {
            continue;
        }
        $out[] = [
            'label'       => $row['provider'],
            'provider'    => $row['provider'],
            'apikey'      => $row['key'],
            'models'      => $row['models'],
            'temperature' => $row['temperature'],
            'apibaseurl'  => $row['baseurl'],
        ];
    }
```
(Duplicate provider rows stay: the label disambiguation below depends on them.)

- [ ] **Step 4: Run the guard test, then every test that touches these readers, one file per command**

Files: `provider_keys_no_raw_read_test`, `comparison_provider_credentials_test`, `comparison_providers_baseurl_test`, `failover_model_remap_test`, `failover_verifier_test`, `provider_benchmark_test`, `autoupgrade_discovery_test`, `autoupgrade_switch_test`, `failover_chain_test`, `spend_guard_test` (if present). Expected: each `OK`, and none lower than its previous test count.

- [ ] **Step 5: Prove the guard can fail**

```bash
cd ~/ai-projects/wt-provider-keys
python3 scripts/prove_test.py --file classes/failover_verifier.php \
  --find 'foreach (provider_keys::by_label() as $key => $row) {' \
  --replace '$unused = get_config("local_ai_course_assistant", "comparison_providers"); foreach (provider_keys::by_label() as $key => $row) {' \
  --filter provider_keys_no_raw_read_test
```
Expected exit 0 (caught). Exit 2 is inconclusive; fix and rerun.

- [ ] **Step 6: Commit**

```bash
git add -A classes analytics.php admin tests/provider_keys_no_raw_read_test.php
git commit -m "refactor: every reader of the provider table goes through provider_keys

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016qGUU4qerkRHrXTh1qyBp6"
```

---

### Task 3: The mirror: `apikey` follows the default provider's row key

**Files:**
- Modify: `classes/provider_keys.php` (add methods), `settings.php` (callbacks, in Task 5; here only the class and tests)
- Test: `tests/provider_keys_sync_test.php`

**Interfaces:**
- Consumes: Task 1; `provider\base_provider::resolve_auto_provider(array $overrides): string` (public static since 7.8.9); `course_key_guard::conflict(string $provider, string $key, string $baseurl = ''): string`.
- Produces: `provider_keys::site_provider(): string` (concrete id or `''`), `provider_keys::sync_default_key(): bool`, `provider_keys::after_settings_saved($fullname = ''): void` (admin updated-callback), `provider_keys::key_from_setting(string $setting): string`, `provider_keys::from_choices(): array`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// (standard GPL header)

namespace local_ai_course_assistant;

/**
 * apikey mirrors the default provider's row key, so the places that read apikey need no change.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider_keys
 */
final class provider_keys_sync_test extends \advanced_testcase {

    private function cfg(string $name, string $value): void {
        set_config($name, $value, 'local_ai_course_assistant');
    }

    public function test_sync_copies_the_default_providers_row_key_into_apikey(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-OLD-KEY-OLD-KEY-1111'));
        $this->cfg('comparison_providers', "openai|sk-proj-NEW-KEY-NEW-KEY-2222|gpt-4o-mini");
        $this->assertTrue(provider_keys::sync_default_key());
        $this->assertSame('sk-proj-NEW-KEY-NEW-KEY-2222', secrets::get('apikey'));
        $this->assertTrue(secrets::is_encrypted(get_config('local_ai_course_assistant', 'apikey')));
        $this->assertFalse(provider_keys::sync_default_key(), 'second run changes nothing');
    }

    public function test_switching_the_default_picks_the_new_providers_key_not_the_old_one(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('comparison_providers', "gemini|AIzaGEMINI-KEY-0000-1234|g\nopenai|sk-proj-OPENAI-KEY-5555-6789|o");
        provider_keys::sync_default_key();
        $this->assertSame('AIzaGEMINI-KEY-0000-1234', secrets::get('apikey'));
        $this->cfg('provider', 'openai');
        provider_keys::sync_default_key();
        $this->assertSame('sk-proj-OPENAI-KEY-5555-6789', secrets::get('apikey'));
    }

    public function test_no_keyed_row_for_the_default_leaves_apikey_alone(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-KEEP-THIS-KEY-3333'));
        $this->cfg('comparison_providers', "gemini|AIzaGEMINI-KEY-0000-1234|g");
        $this->assertFalse(provider_keys::sync_default_key());
        $this->assertSame('sk-proj-KEEP-THIS-KEY-3333', secrets::get('apikey'));
    }

    /** Review focus 4. */
    public function test_auto_with_no_legacy_key_never_starts_using_a_direct_provider(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'auto');
        $this->cfg('model', 'gemini-3.1-flash-lite');
        $this->cfg('comparison_providers', "gemini|AIzaGEMINI-KEY-0000-1234|g");
        $this->assertSame('', provider_keys::site_provider());
        $this->assertFalse(provider_keys::sync_default_key());
        $this->assertSame('', secrets::get('apikey'));
    }

    public function test_auto_with_a_key_follows_the_model_name(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'auto');
        $this->cfg('model', 'gemini-3.1-flash-lite');
        $this->cfg('apikey', secrets::conceal('AIzaOLD-GEMINI-0000-9999'));
        $this->cfg('comparison_providers', "gemini|AIzaNEW-GEMINI-0000-8888|g");
        $this->assertSame('gemini', provider_keys::site_provider());
        $this->assertTrue(provider_keys::sync_default_key());
        $this->assertSame('AIzaNEW-GEMINI-0000-8888', secrets::get('apikey'));
    }

    public function test_a_keyless_default_provider_is_never_synced(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'ollama');
        $this->cfg('comparison_providers', "ollama||llama3");
        $this->assertFalse(provider_keys::sync_default_key());
    }

    public function test_a_key_forced_in_config_php_is_never_written(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->forced_plugin_settings['local_ai_course_assistant']['apikey'] = 'sk-proj-FORCED-KEY-4444-0000';
        $this->cfg('provider', 'openai');
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-KEY-ROW-KEY-7777|o");
        $this->assertFalse(provider_keys::sync_default_key());
        unset($CFG->forced_plugin_settings['local_ai_course_assistant']['apikey']);
    }

    public function test_the_save_callback_warns_when_the_new_default_has_no_row_and_the_key_is_another_vendors(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('AIzaGEMINI-LEFTOVER-0000-1234'));
        $this->cfg('comparison_providers', '');
        provider_keys::after_settings_saved();
        $notices = \core\notification::fetch();
        $this->assertCount(1, $notices);
        $text = $notices[0]->get_message();
        $this->assertStringContainsString('gemini', $text);
        $this->assertStringContainsString('openai', $text);
        $this->assertStringNotContainsString('AIzaGEMINI', $text);
    }

    public function test_key_from_setting_returns_the_chosen_rows_key_or_nothing(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "openai|sk-proj-EMBED-ROW-KEY-1212-3434|o");
        $this->assertSame('', provider_keys::key_from_setting('embed_key_from'));
        $this->cfg('embed_key_from', 'own');
        $this->assertSame('', provider_keys::key_from_setting('embed_key_from'));
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame('sk-proj-EMBED-ROW-KEY-1212-3434', provider_keys::key_from_setting('embed_key_from'));
        $this->cfg('embed_key_from', 'gemini');
        $this->assertSame('', provider_keys::key_from_setting('embed_key_from'));
    }
}
```

- [ ] **Step 2: Run it and confirm it fails** (`Call to undefined method ...::sync_default_key`).

- [ ] **Step 3: Add the methods to `classes/provider_keys.php`** (inside the class, after `last4`)

```php
    /**
     * The concrete provider the site chats with, or '' when there is none to mirror.
     *
     * 'auto' only counts when a legacy key is saved: with no key, auto means core_ai, and mirroring a row
     * key into apikey would flip the site onto a direct provider.
     *
     * @return string
     */
    public static function site_provider(): string {
        $provider = strtolower((string) (get_config(self::PLUGIN, 'provider') ?: 'auto'));
        if ($provider !== 'auto') {
            return $provider;
        }
        $legacy = secrets::get('apikey');
        if ($legacy === '') {
            return '';
        }
        return strtolower(provider\base_provider::resolve_auto_provider([
            'apikey'     => $legacy,
            'model'      => (string) get_config(self::PLUGIN, 'model'),
            'apibaseurl' => (string) get_config(self::PLUGIN, 'apibaseurl'),
        ]));
    }

    /**
     * Make apikey equal the key of the default provider's row. Idempotent.
     *
     * @return bool true when apikey was rewritten
     */
    public static function sync_default_key(): bool {
        global $CFG;
        if (array_key_exists('apikey', $CFG->forced_plugin_settings[self::PLUGIN] ?? [])) {
            return false;
        }
        $provider = self::site_provider();
        if ($provider === '' || in_array($provider, self::KEYLESS, true)) {
            return false;
        }
        $row = self::for_provider($provider);
        if ($row === null || $row['key'] === '' || secrets::get('apikey') === $row['key']) {
            return false;
        }
        set_config('apikey', secrets::conceal($row['key']), self::PLUGIN);
        return true;
    }

    /**
     * Admin updated-callback for provider, model, apibaseurl and the table: keep apikey in step and
     * warn when the default provider has no key of its own. Idempotent, so it may run once per changed setting.
     *
     * @param string $fullname unused; Moodle passes the changed setting's name
     * @return void
     */
    public static function after_settings_saved($fullname = ''): void {
        self::sync_default_key();
        $provider = self::site_provider();
        if ($provider === '' || in_array($provider, self::KEYLESS, true) || self::key_for($provider) !== '') {
            return;
        }
        $legacy = secrets::get('apikey');
        $owner = $legacy === '' ? '' : course_key_guard::conflict($provider, $legacy,
            (string) get_config(self::PLUGIN, 'apibaseurl'));
        if ($owner !== '') {
            \core\notification::warning(get_string('providerkeys:default_vendor_mismatch', self::PLUGIN,
                (object) ['provider' => $provider, 'owner' => $owner]));
        } else if ($legacy === '') {
            \core\notification::warning(get_string('providerkeys:default_no_key', self::PLUGIN,
                (object) ['provider' => $provider]));
        }
    }

    /**
     * The key of the provider row an Embedding, Rerank or Voice field points at, or '' for "use my own".
     *
     * @param string $setting one of embed_key_from, rerank_key_from, realtime_key_from
     * @return string
     */
    public static function key_from_setting(string $setting): string {
        $from = strtolower(trim((string) get_config(self::PLUGIN, $setting)));
        return ($from === '' || $from === 'own') ? '' : self::key_for($from);
    }

    /**
     * Choices for an "use the key from a provider row" select: own key, then every provider that has a keyed row.
     *
     * @return array<string, string>
     */
    public static function from_choices(): array {
        $choices = ['own' => get_string('providerkeys:use_own_key', self::PLUGIN)];
        foreach (self::by_label() as $provider => $row) {
            if ($row['haskey']) {
                $choices[$provider] = get_string('providerkeys:use_row_key', self::PLUGIN, $provider);
            }
        }
        return $choices;
    }
```
Add to `lang/en/local_ai_course_assistant.php` (same commit):

```php
$string['providerkeys:default_no_key'] = '{$a->provider} is the default provider, but the table has no key for it, so chat keeps using the key that was saved before. Add a key for {$a->provider} in "Providers and keys".';
$string['providerkeys:default_vendor_mismatch'] = 'The saved key looks like a {$a->owner} key, but the default provider is {$a->provider}. Add a key for {$a->provider} in "Providers and keys" before students use chat.';
$string['providerkeys:use_own_key'] = 'Use the key pasted below';
$string['providerkeys:use_row_key'] = 'Use the key from the {$a} row';
```

- [ ] **Step 4: Run `provider_keys_sync_test.php`.** Expected: `OK (9 tests, ...)`.

- [ ] **Step 5: Commit** (`git add classes/provider_keys.php lang/en tests/provider_keys_sync_test.php`; message `feat: apikey mirrors the default provider's row key`).

---

### Task 4: Upgrade step, parity matrix, version bump

**Files:**
- Modify: `classes/provider_keys.php` (add `upgrade()`, `flush_notice()`), `db/upgrade.php` (new step before `return true;`), `version.php`
- Test: `tests/provider_keys_upgrade_test.php`

**Interfaces:**
- Consumes: Tasks 1 and 3; `secrets::conceal_row_keys`, `secrets::encrypt_stored_settings()`.
- Produces: `provider_keys::upgrade(): array{encrypted:int, settings:int, row_added:string, conflict:string}`; `provider_keys::flush_notice(): void`.

- [ ] **Step 1: Write the failing parity test**

```php
<?php
// (standard GPL header)

namespace local_ai_course_assistant;

/**
 * The 7.9.0 upgrade changes where keys live, never which key a call uses.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\provider_keys
 */
final class provider_keys_upgrade_test extends \advanced_testcase {

    private function cfg(string $name, string $value): void {
        set_config($name, $value, 'local_ai_course_assistant');
    }

    private function stored(): string {
        return (string) get_config('local_ai_course_assistant', 'comparison_providers');
    }

    public function test_plain_rows_become_ciphertext_and_keep_their_keys(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-DEFAULT-KEY-1111-AAAA'));
        $this->cfg('comparison_providers', "openai|sk-proj-DEFAULT-KEY-1111-AAAA|gpt-4o-mini\nclaude|sk-ant-CLAUDE-ROW-2222-BBBB|claude-haiku-4-5");
        $summary = provider_keys::upgrade();
        $this->assertSame(1, $summary['encrypted']);
        $this->assertStringNotContainsString('sk-ant-CLAUDE', $this->stored());
        $this->assertSame('sk-ant-CLAUDE-ROW-2222-BBBB', provider_keys::key_for('claude'));
        $this->assertSame('sk-proj-DEFAULT-KEY-1111-AAAA', provider_keys::key_for('openai'));
        $this->assertSame('sk-proj-DEFAULT-KEY-1111-AAAA', secrets::get('apikey'));
        $this->assertSame('', $summary['row_added']);
        $this->assertSame('', $summary['conflict']);
    }

    public function test_a_default_provider_with_no_row_gets_one_from_apikey(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('apikey', secrets::conceal('AIzaDEFAULT-GEMINI-3333-CCCC'));
        $this->cfg('comparison_providers', '');
        $summary = provider_keys::upgrade();
        $this->assertSame('gemini', $summary['row_added']);
        $this->assertSame('AIzaDEFAULT-GEMINI-3333-CCCC', provider_keys::key_for('gemini'));
        $this->assertSame('AIzaDEFAULT-GEMINI-3333-CCCC', secrets::get('apikey'));
        $this->assertStringNotContainsString('AIzaDEFAULT', $this->stored());
    }

    public function test_a_conflicting_row_takes_the_key_chat_was_using(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-PRIMARY-KEY-4444-DDDD'));
        $this->cfg('comparison_providers', "openai|sk-proj-STALE-ROW-KEY-5555-EEEE|gpt-4o-mini");
        $summary = provider_keys::upgrade();
        $this->assertSame('sk-proj-PRIMARY-KEY-4444-DDDD', provider_keys::key_for('openai'));
        $this->assertSame('sk-proj-PRIMARY-KEY-4444-DDDD', secrets::get('apikey'));
        $this->assertStringContainsString('EEEE', $summary['conflict']);
        $this->assertStringContainsString('DDDD', $summary['conflict']);
        $this->assertStringNotContainsString('STALE', $summary['conflict']);
        $this->assertStringNotContainsString('PRIMARY', $summary['conflict']);
    }

    /** Review focus 4. */
    public function test_auto_with_no_key_is_left_exactly_as_it_was(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'auto');
        $this->cfg('model', 'gemini-3.1-flash-lite');
        $this->cfg('comparison_providers', "gemini|AIzaROW-ONLY-GEMINI-6666-FFFF|g");
        $summary = provider_keys::upgrade();
        $this->assertSame('', $summary['row_added']);
        $this->assertSame('', secrets::get('apikey'));
        $this->assertSame('AIzaROW-ONLY-GEMINI-6666-FFFF', provider_keys::key_for('gemini'));
    }

    public function test_auto_with_a_key_and_a_model_adds_the_inferred_providers_row(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'auto');
        $this->cfg('model', 'gemini-3.1-flash-lite');
        $this->cfg('apikey', secrets::conceal('AIzaAUTO-GEMINI-KEY-7777-GGGG'));
        $summary = provider_keys::upgrade();
        $this->assertSame('gemini', $summary['row_added']);
        $this->assertSame('AIzaAUTO-GEMINI-KEY-7777-GGGG', provider_keys::key_for('gemini'));
    }

    public function test_a_keyless_default_provider_adds_no_row(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'ollama');
        $this->cfg('apikey', secrets::conceal('not-a-real-key-0000'));
        $summary = provider_keys::upgrade();
        $this->assertSame('', $summary['row_added']);
    }

    public function test_a_table_forced_in_config_php_is_untouched(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->forced_plugin_settings['local_ai_course_assistant']['comparison_providers'] = 'openai|sk-proj-FORCED-ROW-8888-HHHH|m';
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-ANOTHER-KEY-9999-IIII'));
        $summary = provider_keys::upgrade();
        $this->assertSame(0, $summary['encrypted']);
        $this->assertSame('', $summary['row_added']);
        unset($CFG->forced_plugin_settings['local_ai_course_assistant']['comparison_providers']);
    }

    public function test_running_it_twice_changes_nothing(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-TWICE-KEY-1010-JJJJ'));
        $this->cfg('comparison_providers', "claude|sk-ant-TWICE-ROW-2020-KKKK|c");
        provider_keys::upgrade();
        $first = $this->stored();
        $again = provider_keys::upgrade();
        $this->assertSame($first, $this->stored(), 'stored table is byte-identical on a second run');
        $this->assertSame(0, $again['encrypted']);
        $this->assertSame('', $again['row_added']);
        $this->assertSame('', $again['conflict']);
    }

    public function test_a_conflict_leaves_a_one_time_notice_that_names_no_key(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('apikey', secrets::conceal('sk-proj-PRIMARY-KEY-3030-LLLL'));
        $this->cfg('comparison_providers', "openai|sk-proj-OLDROW-KEY-4040-MMMM|m");
        provider_keys::upgrade();
        provider_keys::flush_notice();
        $notices = \core\notification::fetch();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('MMMM', $notices[0]->get_message());
        $this->assertStringNotContainsString('OLDROW', $notices[0]->get_message());
        provider_keys::flush_notice();
        $this->assertCount(0, \core\notification::fetch(), 'shown once');
    }
}
```

- [ ] **Step 2: Run it and confirm it fails** (`undefined method upgrade`).

- [ ] **Step 3: Add `upgrade()` and `flush_notice()` to `provider_keys`**

```php
    /**
     * The 7.9.0 upgrade. Idempotent. Moves where keys live and never which key a call uses.
     *
     * 1. Encrypt every stored secret still in plain text (secrets::encrypt_stored_settings, which also
     *    covers the voice table and, once added to secrets::names(), the webhook URLs).
     * 2. Encrypt every key in the provider table.
     * 3. Make sure the default provider has a keyed row. If it has none, add one from apikey. If it has one
     *    with a DIFFERENT key, chat was using apikey, so the row takes apikey's value and a one-time notice
     *    names the two last-four values.
     *
     * @return array{encrypted:int, settings:int, row_added:string, conflict:string}
     */
    public static function upgrade(): array {
        global $CFG;
        $forced = $CFG->forced_plugin_settings[self::PLUGIN] ?? [];
        $summary = ['encrypted' => 0, 'settings' => secrets::encrypt_stored_settings(), 'row_added' => '', 'conflict' => ''];
        if (array_key_exists(self::SETTING, $forced)) {
            return $summary;
        }
        $raw = (string) (get_config(self::PLUGIN, self::SETTING) ?: '');
        $concealed = secrets::conceal_row_keys($raw);
        if ($raw !== '' && $concealed !== $raw) {
            set_config(self::SETTING, $concealed, self::PLUGIN);
            $summary['encrypted'] = 1;
        }
        $legacy = secrets::get('apikey');
        $provider = self::site_provider();
        if ($legacy === '' || $provider === '' || in_array($provider, self::KEYLESS, true)) {
            return $summary;
        }
        $rows = self::stored_rows();
        $found = null;
        foreach ($rows as $i => $row) {
            if ($row['provider'] === $provider && $row['stored_key'] !== '') {
                $found = $i;
                break;
            }
        }
        if ($found === null) {
            $rows[] = ['provider' => $provider, 'stored_key' => secrets::conceal($legacy), 'models' => '',
                'temperature' => '', 'baseurl' => ''];
            set_config(self::SETTING, self::serialize($rows), self::PLUGIN);
            $summary['row_added'] = $provider;
        } else if (secrets::reveal($rows[$found]['stored_key']) !== $legacy) {
            $summary['conflict'] = $provider . ': the row ended in ' . self::last4(secrets::reveal($rows[$found]['stored_key']))
                . ' and the default key ends in ' . self::last4($legacy);
            $rows[$found]['stored_key'] = secrets::conceal($legacy);
            set_config(self::SETTING, self::serialize($rows), self::PLUGIN);
            set_config('provider_keys_notice', $summary['conflict'], self::PLUGIN);
        }
        return $summary;
    }

    /**
     * Show the one-time upgrade notice, if there is one, then forget it.
     *
     * @return void
     */
    public static function flush_notice(): void {
        $notice = (string) get_config(self::PLUGIN, 'provider_keys_notice');
        if ($notice === '') {
            return;
        }
        unset_config('provider_keys_notice', self::PLUGIN);
        \core\notification::warning(get_string('providerkeys:upgrade_conflict', self::PLUGIN, s($notice)));
    }
```
Lang (`lang/en`, same commit): `$string['providerkeys:upgrade_conflict'] = 'On upgrade, SOLA found two different keys for one provider: {$a}. The row now uses the default provider key, the one chat was already using. Paste the other key again if it was the one you meant.';`

- [ ] **Step 4: Add the upgrade step and bump the version**

In `db/upgrade.php`, before the final `return true;`:

```php
    if ($oldversion < 2026101601) {
        // v7.9.0: provider keys move into one encrypted "Providers and keys" table. Encrypt the stored
        // table and secrets, give the default provider a keyed row, and leave every other setting alone.
        // Idempotent: a second run changes nothing.
        $summary = \local_ai_course_assistant\provider_keys::upgrade();
        mtrace('local_ai_course_assistant: provider keys: encrypted ' . $summary['settings'] . ' stored secret(s)'
            . ($summary['encrypted'] ? ' and the provider table' : '')
            . ($summary['row_added'] !== '' ? ', added a row for ' . $summary['row_added'] : '')
            . ($summary['conflict'] !== '' ? ', resolved a key conflict (see the settings page notice)' : '') . '.');
        upgrade_plugin_savepoint(true, 2026101601, 'local', 'ai_course_assistant');
    }
```
`version.php`: `$plugin->version   = 2026101601;` and `$plugin->release = '7.9.0';`. Then re-init phpunit (see recipe note): `cd ~/Sites/moodle && php admin/tool/phpunit/cli/init.php`.

- [ ] **Step 5: Run `provider_keys_upgrade_test.php`.** Expected `OK (9 tests, ...)`. Then re-run `provider_keys_test`, `provider_keys_sync_test`, `provider_keys_no_raw_read_test` and `secrets_test` (one per command).

- [ ] **Step 6: Commit** (`git add classes/provider_keys.php db/upgrade.php version.php lang/en tests/provider_keys_upgrade_test.php`; message `feat: 7.9.0 upgrade encrypts the provider table and gives the default provider a row`).

- [ ] **Step 7: Open PR 1** (`git push -u origin feat/7.9.0-keys-foundation`; `gh pr create --base main --title "SOLA 7.9.0 part 1: provider keys accessor, mirror and upgrade" ...`). Wait for CI. Request the `sola-reviewer` agent on the diff (Task 14's checklist applies). Do not merge yet if PR 2 is not ready: merging a state where the old table UI still posts plain keys is harmless but untidy; merge in order once PR 2 is green.

---

### Task 5: The row editor and the Providers and keys table

**Files:**
- Create: `classes/admin_setting_keyed_rows.php`, `classes/admin_setting_provider_keys.php`, `templates/admin_keyed_rows.mustache`, `templates/admin_keyed_row.mustache`, `amd/src/keyed_rows.js`, `amd/build/keyed_rows.min.js` (+ `.map`)
- Modify: `settings.php`, `lang/en/local_ai_course_assistant.php`, `classes/secrets.php`, `tests/secrets_test.php`
- Delete: `classes/admin_setting_comparison_providers.php`
- Test: `tests/admin_setting_provider_keys_test.php`

**Interfaces:**
- Consumes: Tasks 1 to 4.
- Produces: `admin_setting_keyed_rows` (abstract) with protected abstract `columns(): array`, `load_rows(): array` (each row has `id`, `stored_key`, plus a value per column name), `store(array $rows): string`, `validate(array $rows): array`; public `write_setting($data)`, `output_html($data, $query = '')`, static `redact_keys($raw)`. `admin_setting_provider_keys` concretes them. `secrets::NOT_ON_SETTINGS_PAGE = ['apikey']`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// (standard GPL header)

namespace local_ai_course_assistant;

/**
 * The Providers and keys table: saves keys encrypted, never renders one, and never echoes one back.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\admin_setting_provider_keys
 * @covers     \local_ai_course_assistant\admin_setting_keyed_rows
 */
final class admin_setting_provider_keys_test extends \advanced_testcase {

    private function setting(): admin_setting_provider_keys {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        return new admin_setting_provider_keys('local_ai_course_assistant/comparison_providers', 'Provider keys', 'desc');
    }

    private function stored(): string {
        return (string) get_config('local_ai_course_assistant', 'comparison_providers');
    }

    private function row(string $provider, string $newkey = '', string $keepid = '', string $models = '',
            string $temp = '', string $baseurl = ''): array {
        return ['provider' => $provider, 'newkey' => $newkey, 'keepid' => $keepid, 'models' => $models,
            'temperature' => $temp, 'baseurl' => $baseurl];
    }

    public function test_a_typed_key_is_saved_encrypted_and_reads_back_plain(): void {
        $this->resetAfterTest();
        $err = $this->setting()->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-TYPED-KEY-1234-ABCD', '', 'gpt-4o-mini'),
        ]]);
        $this->assertSame('', $err);
        $this->assertStringNotContainsString('TYPED-KEY', $this->stored());
        $this->assertTrue(secrets::is_encrypted(provider_keys::stored_rows()[0]['stored_key']));
        $this->assertSame('sk-proj-TYPED-KEY-1234-ABCD', provider_keys::key_for('openai'));
    }

    /** Review focus 1. */
    public function test_saving_with_no_key_typed_leaves_the_stored_table_byte_identical(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-ONE-KEY-0000-AAAA', '', 'gpt-4o-mini'),
            $this->row('claude', 'sk-ant-TWO-KEY-1111-BBBB', '', 'claude-haiku-4-5', '0.4', 'https://c.example'),
        ]]);
        $before = $this->stored();
        $posted = [];
        foreach (provider_keys::rows() as $r) {
            $posted[] = $this->row($r['provider'], '', $r['id'], $r['models'], $r['temperature'], $r['baseurl']);
        }
        $this->assertSame('', $setting->write_setting(['rows' => $posted]));
        $this->assertSame($before, $this->stored());
    }

    public function test_a_typed_key_replaces_only_that_row(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-OLD-OLD-OLD-0000-AAAA'),
            $this->row('claude', 'sk-ant-KEEP-KEEP-1111-BBBB'),
        ]]);
        $rows = provider_keys::rows();
        $setting->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-NEW-NEW-NEW-2222-CCCC', $rows[0]['id']),
            $this->row('claude', '', $rows[1]['id']),
        ]]);
        $this->assertSame('sk-proj-NEW-NEW-NEW-2222-CCCC', provider_keys::key_for('openai'));
        $this->assertSame('sk-ant-KEEP-KEEP-1111-BBBB', provider_keys::key_for('claude'));
    }

    /** Review focus 3. */
    public function test_an_unknown_keepid_never_attaches_another_rows_key(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('claude', 'sk-ant-ONLY-ROW-3333-DDDD')]]);
        $before = $this->stored();
        $err = $setting->write_setting(['rows' => [$this->row('openai', '', 'deadbeef0000')]]);
        $this->assertStringContainsString('openai', $err);
        $this->assertSame($before, $this->stored(), 'nothing saved');
    }

    /** Review focus 5. */
    public function test_a_keyless_provider_saves_without_a_key(): void {
        $this->resetAfterTest();
        $this->assertSame('', $this->setting()->write_setting(['rows' => [
            $this->row('ollama', '', '', 'llama3', '', 'http://localhost:11434'),
        ]]));
        $this->assertSame("ollama||llama3||http://localhost:11434", $this->stored());
    }

    public function test_a_keyed_provider_with_no_key_is_refused(): void {
        $this->resetAfterTest();
        $err = $this->setting()->write_setting(['rows' => [$this->row('openai')]]);
        $this->assertStringContainsString('openai', $err);
        $this->assertSame('', $this->stored());
    }

    public function test_validation_names_the_problem(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $this->assertStringContainsString('notaprovider',
            $setting->write_setting(['rows' => [$this->row('notaprovider', 'sk-x-1234567890-abcd')]]));
        $this->assertStringContainsString('9',
            $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-T-1234-5678-ABCD', '', '', '9')]]));
        $this->assertStringContainsString('http',
            $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-T-1234-5678-ABCD', '', '', '', 'ftp://x')]]));
    }

    public function test_two_rows_for_one_provider_are_allowed(): void {
        $this->resetAfterTest();
        $this->assertSame('', $this->setting()->write_setting(['rows' => [
            $this->row('claude', 'sk-ant-FIRST-0000-1111-AAAA', '', 'claude-haiku-4-5'),
            $this->row('claude', 'sk-ant-SECOND-2222-3333-BBBB', '', 'claude-sonnet-4-6'),
        ]]));
        $this->assertCount(2, provider_keys::rows());
        $this->assertSame('sk-ant-FIRST-0000-1111-AAAA', provider_keys::key_for('claude'));
    }

    public function test_a_blank_provider_row_is_a_removed_row(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-GONE-0000-1111-AAAA')]]);
        $setting->write_setting(['rows' => [$this->row('')]]);
        $this->assertSame('', $this->stored());
    }

    public function test_the_page_never_contains_a_key_or_its_ciphertext(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-HIDDEN-KEY-4444-EEEE', '', 'gpt-4o-mini')]]);
        $stored = provider_keys::stored_rows()[0]['stored_key'];
        $html = $setting->output_html($this->stored());
        $this->assertStringNotContainsString('HIDDEN-KEY', $html);
        $this->assertStringNotContainsString(substr($stored, strpos($stored, ':') + 1, 24), $html);
        $this->assertStringContainsString('EEEE', $html, 'the last four are shown');
        $this->assertStringContainsString('gpt-4o-mini', $html);
        $this->assertDoesNotMatchRegularExpression('~<input[^>]*type="password"[^>]*value="[^"]+"~', $html);
    }

    /** Review focus 6. */
    public function test_a_failed_save_echoes_nothing_back(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $submitted = ['rows' => [$this->row('openai', 'sk-proj-TYPED-AND-REFUSED-5555-FFFF', '', '', '9')]];
        $this->assertNotSame('', $setting->write_setting($submitted));
        $html = $setting->output_html($submitted);
        $this->assertStringNotContainsString('TYPED-AND-REFUSED', $html);
        $this->assertSame('', $this->stored());
    }

    public function test_the_config_log_never_sees_a_key_or_ciphertext(): void {
        $this->assertSame("openai|********|m\nollama||x", admin_setting_provider_keys::redact_keys("openai|sodium:abcdef|m\nollama||x"));
        $this->assertSame('', admin_setting_provider_keys::redact_keys(''));
    }

    public function test_an_undecryptable_row_shows_saved_without_digits_and_survives_a_save(): void {
        $this->resetAfterTest();
        set_config('comparison_providers', 'openai|sodium:AAAAnotarealciphertextAAAAAAAAAAAAAAAAAAAAAAAA|m', 'local_ai_course_assistant');
        $setting = $this->setting();
        $html = $setting->output_html('');
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertStringContainsString('Saved', $html);
        $before = $this->stored();
        $id = provider_keys::rows()[0]['id'];
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertSame('', $setting->write_setting(['rows' => [$this->row('openai', '', $id, 'm')]]));
        $this->assertSame($before, $this->stored(), 'a save does not wipe a key it cannot read');
    }
}
```

- [ ] **Step 2: Run it and confirm it fails** (class not found).

- [ ] **Step 3: Write `classes/admin_setting_keyed_rows.php`**

```php
<?php
// (standard GPL header)

namespace local_ai_course_assistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Base for settings that are a table of rows, one of whose columns is a secret key.
 *
 * The rule this class exists to keep: no key and no ciphertext is ever written into the page. A saved
 * key shows as "Saved, ends in ab12" with a Change button. The form posts plain arrays (never one
 * string carrying every key), a blank key keeps the stored one through an opaque row id, and a typed
 * key is encrypted on the server.
 *
 * Posted shape: {fullname}[rows][<n>][provider|newkey|keepid|<other columns>].
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class admin_setting_keyed_rows extends \admin_setting {

    /**
     * Columns other than the key. The FIRST column identifies the row (the provider).
     *
     * @return array[] each: name, label (lang key), type text|number|select, options (value => label), placeholder
     */
    abstract protected function columns(): array;

    /**
     * Rows as stored, for server-side use only.
     *
     * @return array[] each: id, stored_key, and one value per column name
     */
    abstract protected function load_rows(): array;

    /**
     * Turn rows (each with stored_key and one value per column) into the stored string.
     *
     * @param array[] $rows
     * @return string
     */
    abstract protected function store(array $rows): string;

    /**
     * Problems that stop a save.
     *
     * @param array[] $rows
     * @return string[] messages; empty means fine
     */
    abstract protected function validate(array $rows): array;

    /** @return string lang key for the add button */
    abstract protected function add_label_key(): string;

    public function __construct(string $name, string $visiblename, string $description) {
        parent::__construct($name, $visiblename, $description, '');
    }

    public function get_setting() {
        return $this->config_read($this->name);
    }

    public function write_setting($data) {
        $posted = (is_array($data) && isset($data['rows']) && is_array($data['rows'])) ? $data['rows'] : [];
        $existing = [];
        foreach ($this->load_rows() as $row) {
            $existing[$row['id']] = $row;
        }
        $columns = $this->columns();
        $first = $columns[0]['name'];
        $rows = [];
        foreach ($posted as $p) {
            if (!is_array($p)) {
                continue;
            }
            $row = [];
            foreach ($columns as $column) {
                $row[$column['name']] = trim((string) ($p[$column['name']] ?? ''));
            }
            $row[$first] = strtolower($row[$first]);
            if ($row[$first] === '') {
                continue;
            }
            $newkey = trim((string) ($p['newkey'] ?? ''));
            $keepid = (string) ($p['keepid'] ?? '');
            if ($newkey !== '') {
                $row['stored_key'] = secrets::conceal($newkey);
            } else if ($keepid !== '' && isset($existing[$keepid])) {
                $row['stored_key'] = $existing[$keepid]['stored_key'];
            } else {
                $row['stored_key'] = '';
            }
            $rows[] = $row;
        }
        $errors = $this->validate($rows);
        if ($errors) {
            return implode(' ', $errors) . ' ' . get_string('providerkeys:err_resubmit', 'local_ai_course_assistant');
        }
        $this->config_write($this->name, $this->store($rows));
        return '';
    }

    /**
     * @param mixed $data IGNORED ON PURPOSE. After a failed save Moodle passes the submitted values here;
     *                    a key typed into the form must never be echoed back, so this renders from storage only.
     * @param string $query
     * @return string
     */
    public function output_html($data, $query = ''): string {
        global $OUTPUT, $PAGE;
        $id = $this->get_id();
        $columns = $this->columns();
        $rows = [];
        foreach ($this->load_rows() as $i => $row) {
            $rows[] = $this->row_context((string) $i, $row, $columns);
        }
        $blank = ['id' => '', 'stored_key' => ''];
        foreach ($columns as $column) {
            $blank[$column['name']] = '';
        }
        $heads = [];
        foreach ($columns as $i => $column) {
            $heads[] = ['label' => get_string($column['label'], 'local_ai_course_assistant'), 'iskey' => false];
            if ($i === 0) {
                $heads[] = ['label' => get_string('providerkeys:col_key', 'local_ai_course_assistant'), 'iskey' => true];
            }
        }
        $html = $OUTPUT->render_from_template('local_ai_course_assistant/admin_keyed_rows', [
            'id' => $id,
            'heads' => $heads,
            'rows' => $rows,
            'blankrow' => $this->row_context('__IDX__', $blank, $columns),
            'addlabel' => get_string($this->add_label_key(), 'local_ai_course_assistant'),
        ]);
        $PAGE->requires->js_call_amd('local_ai_course_assistant/keyed_rows', 'init', [$id]);
        return format_admin_setting($this, $this->visiblename, $html, $this->description, true, '', null, $query);
    }

    /**
     * Template context for one row. Contains no key and no ciphertext, only the saved flag and last four.
     *
     * @param string $idx row index in the posted array
     * @param array $row
     * @param array[] $columns
     * @return array
     */
    private function row_context(string $idx, array $row, array $columns): array {
        $fullname = $this->get_full_name();
        $haskey = $row['stored_key'] !== '';
        $last4 = $haskey ? provider_keys::last4(secrets::reveal($row['stored_key'])) : '';
        $cells = [];
        foreach ($columns as $i => $column) {
            $cell = [
                'name' => $column['name'],
                'inputname' => $fullname . '[rows][' . $idx . '][' . $column['name'] . ']',
                'value' => (string) $row[$column['name']],
                'isselect' => $column['type'] === 'select',
                'isnumber' => $column['type'] === 'number',
                'istext' => $column['type'] === 'text',
                'placeholder' => $column['placeholder'] ?? '',
                'options' => [],
                'iskeynext' => $i === 0,
            ];
            if ($cell['isselect']) {
                foreach ($column['options'] as $value => $label) {
                    $cell['options'][] = ['value' => $value, 'label' => $label,
                        'selected' => (string) $value === (string) $row[$column['name']]];
                }
            }
            $cells[] = $cell;
        }
        return [
            'idx' => $idx,
            'rowid' => $row['id'],
            'haskey' => $haskey,
            'savedlabel' => $last4 !== ''
                ? get_string('providerkeys:saved_ends_in', 'local_ai_course_assistant', $last4)
                : get_string('providerkeys:saved', 'local_ai_course_assistant'),
            'keyinput' => $fullname . '[rows][' . $idx . '][newkey]',
            'keepinput' => $fullname . '[rows][' . $idx . '][keepid]',
            'cells' => $cells,
            'changelabel' => get_string('providerkeys:change', 'local_ai_course_assistant'),
            'newkeyplaceholder' => get_string('providerkeys:newkey_placeholder', 'local_ai_course_assistant'),
            'removelabel' => get_string('providerkeys:remove', 'local_ai_course_assistant'),
        ];
    }

    /**
     * Keep keys out of the configuration change log: the key column becomes a fixed placeholder.
     *
     * @param string $name
     * @param mixed $oldvalue
     * @param mixed $value
     * @return void
     */
    protected function add_to_config_log($name, $oldvalue, $value) {
        parent::add_to_config_log($name, self::redact_keys($oldvalue), self::redact_keys($value));
    }

    /**
     * Replace the key column of every row with a placeholder. Which provider or model changed stays visible.
     *
     * @param mixed $raw
     * @return mixed
     */
    public static function redact_keys($raw) {
        if (!is_string($raw) || $raw === '') {
            return $raw;
        }
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            if (trim($line) === '') {
                $out[] = $line;
                continue;
            }
            $parts = explode('|', $line);
            if (isset($parts[1]) && trim($parts[1]) !== '') {
                $parts[1] = '********';
            }
            $out[] = implode('|', $parts);
        }
        return implode("\n", $out);
    }
}
```

- [ ] **Step 4: Write `classes/admin_setting_provider_keys.php`**

```php
<?php
// (standard GPL header)

namespace local_ai_course_assistant;

defined('MOODLE_INTERNAL') || die();

/**
 * The "Providers and keys" table: one row per provider with its key, models, temperature and base URL.
 *
 * Same storage as the old comparison providers table (provider|key|models|temperature|baseurl), with the
 * key encrypted. Reading goes through provider_keys.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_provider_keys extends admin_setting_keyed_rows {

    protected function columns(): array {
        $options = [];
        foreach (array_merge(provider_keys::CHAT_PROVIDERS, provider_keys::KEY_ONLY_PROVIDERS) as $id) {
            $options[$id] = get_string('providerkeys:name_' . $id, 'local_ai_course_assistant');
        }
        return [
            ['name' => 'provider', 'label' => 'providerkeys:col_provider', 'type' => 'select', 'options' => $options],
            ['name' => 'models', 'label' => 'providerkeys:col_models', 'type' => 'text',
                'placeholder' => 'model-name-1, model-name-2'],
            ['name' => 'temperature', 'label' => 'providerkeys:col_temp', 'type' => 'number', 'placeholder' => '0.7'],
            ['name' => 'baseurl', 'label' => 'providerkeys:col_baseurl', 'type' => 'text',
                'placeholder' => 'https://... (optional)'],
        ];
    }

    protected function load_rows(): array {
        return provider_keys::stored_rows();
    }

    protected function store(array $rows): string {
        return provider_keys::serialize($rows);
    }

    protected function add_label_key(): string {
        return 'providerkeys:add';
    }

    protected function validate(array $rows): array {
        $errors = [];
        $known = array_merge(provider_keys::CHAT_PROVIDERS, provider_keys::KEY_ONLY_PROVIDERS);
        foreach ($rows as $r) {
            $p = $r['provider'];
            if (!in_array($p, $known, true)) {
                $errors[] = get_string('providerkeys:err_provider', 'local_ai_course_assistant', s($p));
                continue;
            }
            if ($r['stored_key'] === '' && !in_array($p, provider_keys::KEYLESS, true)) {
                $errors[] = get_string('providerkeys:err_needs_key', 'local_ai_course_assistant', $p);
            }
            if ($r['temperature'] !== '' && (!is_numeric($r['temperature']) || $r['temperature'] < 0 || $r['temperature'] > 2)) {
                $errors[] = get_string('providerkeys:err_temperature', 'local_ai_course_assistant', $p . ' (' . s($r['temperature']) . ')');
            }
            if ($r['baseurl'] !== '' && !preg_match('~^https?://~i', $r['baseurl'])) {
                $errors[] = get_string('providerkeys:err_baseurl', 'local_ai_course_assistant', $p);
            }
        }
        return $errors;
    }

    /**
     * After a save, point out a provider listed twice. Allowed (the golden bake-off CLI uses two rows for one
     * provider), but failover and the LLM picker use the first row that has a key.
     */
    public function post_write_settings($original) {
        $seen = [];
        foreach (provider_keys::stored_rows() as $row) {
            $seen[$row['provider']] = ($seen[$row['provider']] ?? 0) + 1;
        }
        foreach ($seen as $provider => $count) {
            if ($count > 1) {
                \core\notification::warning(get_string('providerkeys:warn_duplicate', 'local_ai_course_assistant', $provider));
            }
        }
        return parent::post_write_settings($original);
    }
}
```
Lang (`lang/en`, same commit as this class and its template): all the `providerkeys:*` strings below.

```php
$string['providerkeys:heading'] = 'Providers and keys';
$string['providerkeys:heading_desc'] = 'One row per AI provider. Add or change a key here without touching the default provider. The default provider uses the key of its own row. A saved key is never shown again, only its last four characters. To replace one, press Change and type the new key.';
$string['providerkeys:table'] = 'Provider keys';
$string['providerkeys:table_desc'] = 'Models: a comma-separated list, and the first is the default. Temperature and base URL are optional. These rows also feed failover, the LLM picker, and Embedding or Rerank when they say "use the key from a provider row". voyage is a key for Embedding and Rerank only.';
$string['providerkeys:default_heading'] = 'Default provider';
$string['providerkeys:default_heading_desc'] = 'Which row above chat uses. Changing the default never changes a key.';
$string['providerkeys:col_provider'] = 'Provider';
$string['providerkeys:col_key'] = 'API key';
$string['providerkeys:col_models'] = 'Models (comma-separated)';
$string['providerkeys:col_temp'] = 'Temp.';
$string['providerkeys:col_baseurl'] = 'Base URL';
$string['providerkeys:saved_ends_in'] = 'Saved, ends in {$a}';
$string['providerkeys:saved'] = 'Saved';
$string['providerkeys:change'] = 'Change';
$string['providerkeys:newkey_placeholder'] = 'Paste the API key';
$string['providerkeys:add'] = '+ Add provider';
$string['providerkeys:remove'] = 'Remove';
$string['providerkeys:err_provider'] = '"{$a}" is not a provider SOLA knows.';
$string['providerkeys:err_needs_key'] = '{$a} needs an API key.';
$string['providerkeys:err_temperature'] = 'Temperature for {$a} must be a number from 0 to 2.';
$string['providerkeys:err_baseurl'] = 'The base URL for {$a} must start with http:// or https://.';
$string['providerkeys:err_resubmit'] = 'Nothing was saved. Keys are never shown again, so type any key you changed once more.';
$string['providerkeys:warn_duplicate'] = '{$a} is listed more than once. Failover and the LLM picker use the first row that has a key.';
$string['providerkeys:name_openai'] = 'OpenAI';
$string['providerkeys:name_claude'] = 'Claude (Anthropic)';
$string['providerkeys:name_deepseek'] = 'DeepSeek';
$string['providerkeys:name_gemini'] = 'Google Gemini';
$string['providerkeys:name_ollama'] = 'Ollama (local)';
$string['providerkeys:name_minimax'] = 'MiniMax';
$string['providerkeys:name_mistral'] = 'Mistral AI';
$string['providerkeys:name_openrouter'] = 'OpenRouter';
$string['providerkeys:name_together'] = 'Together AI';
$string['providerkeys:name_xai'] = 'xAI (Grok)';
$string['providerkeys:name_coreai'] = 'Moodle AI (core_ai)';
$string['providerkeys:name_custom'] = 'Custom (OpenAI-compatible)';
$string['providerkeys:name_voyage'] = 'Voyage AI (Embedding and Rerank only)';
```

- [ ] **Step 5: Templates**

`templates/admin_keyed_row.mustache`:
```mustache
{{!
    This file is part of Moodle - http://moodle.org/
    (standard GPL header as in the other templates)
}}
{{!
    @template local_ai_course_assistant/admin_keyed_row

    One row of a keyed-rows admin table. Never contains a key or ciphertext.

    Example context (json):
    { "idx": "0", "rowid": "a1b2c3d4e5f6", "haskey": true, "savedlabel": "Saved, ends in ab12",
      "keyinput": "x[rows][0][newkey]", "keepinput": "x[rows][0][keepid]", "changelabel": "Change",
      "newkeyplaceholder": "Paste the API key", "removelabel": "Remove",
      "cells": [ { "name": "provider", "inputname": "x[rows][0][provider]", "value": "openai", "isselect": true,
        "options": [ { "value": "openai", "label": "OpenAI", "selected": true } ], "iskeynext": true } ] }
}}
<tr data-row="{{idx}}">
    {{#cells}}
    <td>
        {{#isselect}}<select class="form-control form-control-sm" name="{{inputname}}">{{#options}}<option value="{{value}}"{{#selected}} selected{{/selected}}>{{label}}</option>{{/options}}</select>{{/isselect}}
        {{#istext}}<input type="text" class="form-control form-control-sm" name="{{inputname}}" value="{{value}}" placeholder="{{placeholder}}">{{/istext}}
        {{#isnumber}}<input type="number" step="0.1" min="0" max="2" class="form-control form-control-sm" name="{{inputname}}" value="{{value}}" placeholder="{{placeholder}}">{{/isnumber}}
    </td>
    {{#iskeynext}}
    <td class="sola-kr-keycell">
        {{#haskey}}
        <span class="sola-kr-saved">{{savedlabel}}</span>
        <button type="button" class="btn btn-sm btn-link sola-kr-change">{{changelabel}}</button>
        <input type="password" class="form-control form-control-sm sola-kr-newkey d-none" name="{{keyinput}}" value="" autocomplete="new-password" placeholder="{{newkeyplaceholder}}" disabled>
        {{/haskey}}
        {{^haskey}}
        <input type="password" class="form-control form-control-sm sola-kr-newkey" name="{{keyinput}}" value="" autocomplete="new-password" placeholder="{{newkeyplaceholder}}">
        {{/haskey}}
        <input type="hidden" name="{{keepinput}}" value="{{rowid}}">
    </td>
    {{/iskeynext}}
    {{/cells}}
    <td><button type="button" class="btn btn-sm btn-outline-danger sola-kr-remove" title="{{removelabel}}">&times;</button></td>
</tr>
```

`templates/admin_keyed_rows.mustache`:
```mustache
{{!
    This file is part of Moodle - http://moodle.org/
    (standard GPL header)
}}
{{!
    @template local_ai_course_assistant/admin_keyed_rows

    A table of rows with a secret key column. No key or ciphertext is ever in the context.

    Example context (json):
    { "id": "id_s_x", "heads": [ { "label": "Provider" }, { "label": "API key" } ], "rows": [],
      "blankrow": { "idx": "__IDX__", "cells": [] }, "addlabel": "+ Add provider" }
}}
<div id="{{id}}-wrap" class="local-ai-course-assistant-keyed-rows">
    <table class="table table-sm table-bordered" style="max-width:960px">
        <thead><tr>{{#heads}}<th>{{label}}</th>{{/heads}}<th style="width:50px"></th></tr></thead>
        <tbody>
            {{#rows}}{{> local_ai_course_assistant/admin_keyed_row}}{{/rows}}
            <template class="sola-kr-blank">{{#blankrow}}{{> local_ai_course_assistant/admin_keyed_row}}{{/blankrow}}</template>
        </tbody>
    </table>
    <button type="button" class="btn btn-sm btn-outline-primary sola-kr-add">{{addlabel}}</button>
</div>
```

- [ ] **Step 6: AMD module `amd/src/keyed_rows.js`** and build

```js
// This file is part of Moodle - http://moodle.org/
// (standard GPL header)

/**
 * Add, remove and "Change key" for keyed-rows admin tables. Never reads or writes a key: a new key is only
 * ever typed by the admin into an empty field.
 *
 * @module     local_ai_course_assistant/keyed_rows
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    var counter = 0;

    /**
     * @param {String} id The wrapper id prefix.
     */
    var init = function(id) {
        var wrap = document.getElementById(id + '-wrap');
        if (!wrap) {
            return;
        }
        var tbody = wrap.querySelector('tbody');
        var tpl = wrap.querySelector('template.sola-kr-blank');
        wrap.addEventListener('click', function(e) {
            var t = e.target;
            if (t.classList.contains('sola-kr-remove')) {
                // A removed row posts nothing, so it is dropped on save.
                t.closest('tr').remove();
            } else if (t.classList.contains('sola-kr-change')) {
                var cell = t.closest('td');
                var input = cell.querySelector('.sola-kr-newkey');
                input.disabled = false;
                input.classList.remove('d-none');
                cell.querySelector('.sola-kr-saved').classList.add('d-none');
                t.classList.add('d-none');
                input.focus();
            } else if (t.classList.contains('sola-kr-add')) {
                counter++;
                var html = tpl.innerHTML.split('__IDX__').join('n' + counter + String(Date.now()));
                tbody.insertAdjacentHTML('beforeend', html);
            }
        });
    };

    return {init: init};
});
```
Build (CLAUDE.md pattern, run from the worktree):
```bash
cd ~/ai-projects/wt-provider-keys
terser amd/src/keyed_rows.js --compress --mangle --source-map "url=keyed_rows.min.js.map" -o amd/build/keyed_rows.min.js
grep -c "sola-kr-change" amd/build/keyed_rows.min.js   # expect 1
```

- [ ] **Step 7: Wire `settings.php`** (page `local_ai_course_assistant_ai`)

(a) Directly after the `provider_heading` `admin_setting_heading` block and BEFORE the `$providers = [...]` array, add:

```php
    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/providerkeys_heading',
        get_string('providerkeys:heading', 'local_ai_course_assistant'),
        get_string('providerkeys:heading_desc', 'local_ai_course_assistant')
    ));
    $providerkeys = new \local_ai_course_assistant\admin_setting_provider_keys(
        'local_ai_course_assistant/comparison_providers',
        get_string('providerkeys:table', 'local_ai_course_assistant'),
        get_string('providerkeys:table_desc', 'local_ai_course_assistant')
    );
    $providerkeys->set_updatedcallback('local_ai_course_assistant\provider_keys::after_settings_saved');
    $settings->add($providerkeys);

    $settings->add(new admin_setting_heading(
        'local_ai_course_assistant/providerkeys_default_heading',
        get_string('providerkeys:default_heading', 'local_ai_course_assistant'),
        get_string('providerkeys:default_heading_desc', 'local_ai_course_assistant')
    ));
```

(b) Replace the `provider` select registration with a variable that gets the callback, and do the same for `model` and `apibaseurl`:

```php
    $providersetting = new admin_setting_configselect(
        'local_ai_course_assistant/provider',
        get_string('settings:provider', 'local_ai_course_assistant'),
        \local_ai_course_assistant\branding::str('settings:provider_desc'),
        'auto',
        $providers
    );
    $providersetting->set_updatedcallback('local_ai_course_assistant\provider_keys::after_settings_saved');
    $settings->add($providersetting);
```
`model` and `apibaseurl`: same pattern (`$modelsetting`, `$baseurlsetting`), keeping their existing arguments, adding `->set_updatedcallback('local_ai_course_assistant\provider_keys::after_settings_saved')`.

(c) DELETE the `admin_setting_encryptedpassword` block for `local_ai_course_assistant/apikey` (the "API Key" field).

(d) DELETE the old block at the former line 682:
```php
    $settings->add(new \local_ai_course_assistant\admin_setting_comparison_providers(
        'local_ai_course_assistant/comparison_providers',
        get_string('settings:comparison_providers', 'local_ai_course_assistant'),
        get_string('settings:comparison_providers_desc', 'local_ai_course_assistant')
    ));
```

(e) At the top of `if ($hassiteconfig) {`, right after `\local_ai_course_assistant\settings_save_report::flush();` add: `\local_ai_course_assistant\provider_keys::flush_notice();`

`git rm classes/admin_setting_comparison_providers.php`. The strings `settings:comparison_providers` and `settings:comparison_providers_desc` become unused: delete them from `lang/en` and run `lang_completeness_test` (it fails on a string defined but unused only if a test enforces it; if not, leave them, they do no harm).

- [ ] **Step 8: Update the secrets tree test**

In `classes/secrets.php` add above `names()`:
```php
    /** Declared in names() and encrypted, but not a field on the settings page: apikey is an automatic mirror of the default provider's row key (provider_keys::sync_default_key). */
    public const NOT_ON_SETTINGS_PAGE = ['apikey'];
```
In `tests/secrets_test.php::test_the_secret_list_matches_the_settings_tree` change `$names = secrets::names();` to `$names = array_values(array_diff(secrets::names(), secrets::NOT_ON_SETTINGS_PAGE));`.

- [ ] **Step 9: Run** `admin_setting_provider_keys_test.php` (expect `OK (13 tests, ...)`), then `secrets_test`, `settings_page_structure_test`, `settings_dependencies_test`, `settings_secret_masking_test`, `lang_completeness_test` one file per command. Fix any string the lang test flags by adding it to `lang/en`.

- [ ] **Step 10: Browser check on the local Moodle** (no assertions, a human-eyes check): open `http://localhost:8080/admin/category.php?category=local_ai_course_assistant`, find "Providers and keys", add a row with a sentinel key, save, reload, and run in the console `document.documentElement.outerHTML.includes('<the sentinel>')`: must be `false`. Add a row, press Change on another, save, confirm no change to the others.

- [ ] **Step 11: Commit** (`git add -A classes templates amd settings.php lang/en tests/admin_setting_provider_keys_test.php tests/secrets_test.php; git rm` the old class; message `feat: Providers and keys table that never renders a key`).

---

### Task 6: The Voice providers table on the same editor

**Files:**
- Modify: `classes/admin_setting_voice_providers.php` (rewrite)
- Test: `tests/admin_setting_voice_providers_test.php`

**Interfaces:**
- Consumes: `admin_setting_keyed_rows`, `voice_registry::parse_rows(bool $reveal = true): array` (rows: provider, apikey, label, realtime_voice, tts_voice).
- Produces: same stored format `provider|key|label|realtime_voice|tts_voice`, key encrypted; `voice_registry` unchanged.

- [ ] **Step 1: Write the failing test**

```php
<?php
// (standard GPL header)

namespace local_ai_course_assistant;

/**
 * The Voice providers table keeps its stored format but never renders a key or ciphertext.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\admin_setting_voice_providers
 */
final class admin_setting_voice_providers_test extends \advanced_testcase {

    private function setting(): admin_setting_voice_providers {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        return new admin_setting_voice_providers('local_ai_course_assistant/voice_providers', 'Voice', 'desc');
    }

    private function row(string $p, string $new = '', string $keep = '', string $label = '', string $rt = '', string $tts = ''): array {
        return ['provider' => $p, 'newkey' => $new, 'keepid' => $keep, 'label' => $label,
            'realtime_voice' => $rt, 'tts_voice' => $tts];
    }

    public function test_a_row_is_saved_in_the_old_format_with_an_encrypted_key(): void {
        $this->resetAfterTest();
        $this->assertSame('', $this->setting()->write_setting(['rows' => [
            $this->row('openai', 'sk-proj-VOICE-KEY-1111-AAAA', '', 'main', 'shimmer', 'nova'),
        ]]));
        $rows = voice_registry::parse_rows(true);
        $this->assertSame('sk-proj-VOICE-KEY-1111-AAAA', $rows[0]['apikey']);
        $this->assertSame(['openai', 'main', 'shimmer', 'nova'],
            [$rows[0]['provider'], $rows[0]['label'], $rows[0]['realtime_voice'], $rows[0]['tts_voice']]);
        $this->assertStringNotContainsString('VOICE-KEY', (string) get_config('local_ai_course_assistant', 'voice_providers'));
    }

    public function test_the_page_never_contains_the_key_or_its_ciphertext(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('openai', 'sk-proj-VOICE-HIDDEN-2222-BBBB', '', 'main')]]);
        $stored = voice_registry::parse_rows(false)[0]['apikey'];
        $html = $setting->output_html('');
        $this->assertStringNotContainsString('VOICE-HIDDEN', $html);
        $this->assertStringNotContainsString(substr($stored, strpos($stored, ':') + 1, 24), $html);
        $this->assertStringContainsString('BBBB', $html);
        $this->assertDoesNotMatchRegularExpression('~<input[^>]*type="password"[^>]*value="[^"]+"~', $html);
    }

    public function test_saving_with_no_key_typed_keeps_the_stored_value_byte_identical(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $setting->write_setting(['rows' => [$this->row('xai', 'xai-VOICE-KEY-3333-CCCC', '', 'grok', 'eve', 'eve')]]);
        $before = (string) get_config('local_ai_course_assistant', 'voice_providers');
        $id = $this->id_of_first_row();
        $this->assertSame('', $setting->write_setting(['rows' => [$this->row('xai', '', $id, 'grok', 'eve', 'eve')]]));
        $this->assertSame($before, (string) get_config('local_ai_course_assistant', 'voice_providers'));
    }

    public function test_only_openai_and_xai_are_accepted_and_a_key_is_required(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $this->assertStringContainsString('gemini', $setting->write_setting(['rows' => [$this->row('gemini', 'AIza-1234567890-abcd')]]));
        $this->assertStringContainsString('openai', $setting->write_setting(['rows' => [$this->row('openai')]]));
    }

    public function test_a_failed_save_echoes_nothing(): void {
        $this->resetAfterTest();
        $setting = $this->setting();
        $submitted = ['rows' => [$this->row('gemini', 'sk-proj-VOICE-TYPED-4444-DDDD')]];
        $this->assertNotSame('', $setting->write_setting($submitted));
        $this->assertStringNotContainsString('VOICE-TYPED', $setting->output_html($submitted));
    }

    private function id_of_first_row(): string {
        $stored = voice_registry::parse_rows(false)[0];
        return provider_keys::rowid($stored['provider'] . '|' . $stored['label'], $stored['apikey']);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails** (the old class has no array write path).

- [ ] **Step 3: Rewrite `classes/admin_setting_voice_providers.php`**

```php
<?php
// (standard GPL header)

namespace local_ai_course_assistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Admin setting for the voice_providers table: openai or xai rows with a key, a label and default voices.
 *
 * Stored format (unchanged, read by voice_registry): provider|key|label|realtime_voice|tts_voice, key encrypted.
 * Shares the row editor, so the page never carries a key or ciphertext.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_voice_providers extends admin_setting_keyed_rows {

    private const PROVIDERS = ['openai' => 'OpenAI', 'xai' => 'xAI (Grok)'];

    protected function columns(): array {
        return [
            ['name' => 'provider', 'label' => 'providerkeys:col_provider', 'type' => 'select', 'options' => self::PROVIDERS],
            ['name' => 'label', 'label' => 'providerkeys:col_label', 'type' => 'text', 'placeholder' => 'e.g. openai-prod'],
            ['name' => 'realtime_voice', 'label' => 'providerkeys:col_rtvoice', 'type' => 'text', 'placeholder' => 'e.g. shimmer, eve'],
            ['name' => 'tts_voice', 'label' => 'providerkeys:col_ttsvoice', 'type' => 'text', 'placeholder' => 'e.g. shimmer, eve'],
        ];
    }

    protected function load_rows(): array {
        $rows = [];
        foreach (voice_registry::parse_rows(false) as $r) {
            $rows[] = [
                'id'             => provider_keys::rowid($r['provider'] . '|' . $r['label'], $r['apikey']),
                'stored_key'     => $r['apikey'],
                'provider'       => $r['provider'],
                'label'          => $r['label'],
                'realtime_voice' => $r['realtime_voice'],
                'tts_voice'      => $r['tts_voice'],
            ];
        }
        return $rows;
    }

    protected function store(array $rows): string {
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = $r['provider'] . '|' . $r['stored_key'] . '|' . $r['label'] . '|' . $r['realtime_voice'] . '|' . $r['tts_voice'];
        }
        return implode("\n", $lines);
    }

    protected function add_label_key(): string {
        return 'providerkeys:add_voice';
    }

    protected function validate(array $rows): array {
        $errors = [];
        foreach ($rows as $r) {
            if (!isset(self::PROVIDERS[$r['provider']])) {
                $errors[] = get_string('providerkeys:err_provider', 'local_ai_course_assistant', s($r['provider']));
            } else if ($r['stored_key'] === '') {
                $errors[] = get_string('providerkeys:err_needs_key', 'local_ai_course_assistant', $r['provider']);
            }
        }
        return $errors;
    }
}
```
Lang (`lang/en`, same commit): 
```php
$string['providerkeys:col_label'] = 'Label';
$string['providerkeys:col_rtvoice'] = 'Realtime voice';
$string['providerkeys:col_ttsvoice'] = 'TTS voice';
$string['providerkeys:add_voice'] = '+ Add voice provider';
```
Note `secrets::encrypt_stored_settings()` keeps encrypting this table's keys on upgrade through `conceal_row_keys`; the class no longer needs to, because `write_setting` conceals each typed key.

- [ ] **Step 4: Run** `admin_setting_voice_providers_test.php` (expect `OK (5 tests, ...)`), then `voice_registry_selfhosted_test`, `voice_audio_test`, `secrets_test`.

- [ ] **Step 5: Commit** (`feat: Voice providers table on the shared row editor, no key or ciphertext in the page`).

---

### Task 7: Webhook URLs become encrypted fields

**Files:**
- Modify: `classes/secrets.php` (add two names), `settings.php:3194-3209`, `classes/task/run_anomaly_digest.php:131,136`, `tests/settings_secret_masking_test.php`
- Test: extend `tests/secrets_test.php`, `tests/settings_secret_masking_test.php`

**Interfaces:** Consumes `secrets::get`, `admin_setting_secret_field` (Task 8; until then use `admin_setting_encryptedpassword` and swap in Task 8).

- [ ] **Step 1: Write the failing tests.** In `settings_secret_masking_test.php` add `'webhook'` to `SECRET_HINTS` and run it: it must now FAIL naming `anomaly_digest_slack_webhook` and `anomaly_digest_teams_webhook`. In `secrets_test.php` add:

```php
    public function test_the_anomaly_webhook_urls_are_secrets_and_the_digest_reads_them_decrypted(): void {
        $this->resetAfterTest();
        $this->assertTrue(secrets::is_secret('anomaly_digest_slack_webhook'));
        $this->assertTrue(secrets::is_secret('anomaly_digest_teams_webhook'));
        set_config('anomaly_digest_slack_webhook', secrets::conceal('https://hooks.example/T000/B000/SENTINELWEBHOOK'), 'local_ai_course_assistant');
        $this->assertSame('https://hooks.example/T000/B000/SENTINELWEBHOOK', secrets::get('anomaly_digest_slack_webhook'));
    }
```

- [ ] **Step 2: Implement.** Add `'anomaly_digest_slack_webhook'` and `'anomaly_digest_teams_webhook'` to `secrets::names()`. In `settings.php` replace both `admin_setting_configtext` blocks with:

```php
    $settings->add(new admin_setting_encryptedpassword(
        'local_ai_course_assistant/anomaly_digest_slack_webhook',
        get_string('settings:anomaly_digest_slack_webhook', 'local_ai_course_assistant'),
        get_string('settings:anomaly_digest_slack_webhook_desc', 'local_ai_course_assistant')
    ));

    $settings->add(new admin_setting_encryptedpassword(
        'local_ai_course_assistant/anomaly_digest_teams_webhook',
        get_string('settings:anomaly_digest_teams_webhook', 'local_ai_course_assistant'),
        get_string('settings:anomaly_digest_teams_webhook_desc', 'local_ai_course_assistant')
    ));
```
In `classes/task/run_anomaly_digest.php` replace lines 131 and 136 with:
```php
        $slack = secrets::get('anomaly_digest_slack_webhook');
        ...
        $teams = secrets::get('anomaly_digest_teams_webhook');
```
(`use` / FQCN as the file already does for other helpers.) The upgrade step already encrypts them, because `secrets::encrypt_stored_settings()` walks `names()`.

- [ ] **Step 3: Run** `secrets_test`, `settings_secret_masking_test`, `anomaly_digest_test` (if present). All `OK`.

- [ ] **Step 4: Commit** (`fix: Slack and Teams webhook URLs are encrypted and masked like other credentials`).

---

### Task 8: Masked last-four hint on every single-key field

**Files:**
- Create: `classes/admin_setting_secret_field.php`
- Modify: `settings.php` (swap the constructor everywhere), `tests/settings_secret_masking_test.php` (accept the subclass)
- Test: `tests/admin_setting_secret_field_test.php`

**Interfaces:** Produces `admin_setting_secret_field extends \admin_setting_encryptedpassword`; output never contains the value, appends "Saved key ends in ab12." to the description.

- [ ] **Step 1: Write the failing test**

```php
<?php
// (standard GPL header)

namespace local_ai_course_assistant;

/**
 * A single-key field shows only a masked "ends in" hint.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\admin_setting_secret_field
 */
final class admin_setting_secret_field_test extends \advanced_testcase {

    public function test_it_shows_the_last_four_and_never_the_key(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        set_config('embed_apikey', secrets::conceal('sk-proj-EMBED-FIELD-0000-WXYZ'), 'local_ai_course_assistant');
        $field = new admin_setting_secret_field('local_ai_course_assistant/embed_apikey', 'Embedding key', 'desc');
        $html = $field->output_html($field->get_setting());
        $this->assertStringContainsString('WXYZ', $html);
        $this->assertStringNotContainsString('EMBED-FIELD', $html);
        $this->assertSame('desc', $field->description, 'the description is restored after rendering');
    }

    public function test_an_unset_field_shows_no_hint(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $field = new admin_setting_secret_field('local_ai_course_assistant/rerank_apikey', 'Rerank key', 'desc');
        $this->assertStringNotContainsString('ends in', $field->output_html(''));
    }

    public function test_it_is_still_an_encrypted_password_setting(): void {
        $this->assertTrue(is_subclass_of(admin_setting_secret_field::class, \admin_setting_encryptedpassword::class));
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**, then implement:

```php
<?php
// (standard GPL header)

namespace local_ai_course_assistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle's encrypted password field plus a masked "Saved key ends in ab12." hint.
 *
 * The parent already renders only a set/unset flag, never the value; this adds the last four characters
 * so a key can be matched to a provider console.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_secret_field extends \admin_setting_encryptedpassword {

    public function output_html($data, $query = '') {
        $original = $this->description;
        $last4 = provider_keys::last4(secrets::get($this->name));
        if ($last4 !== '') {
            $this->description .= ' ' . get_string('providerkeys:last4_hint', 'local_ai_course_assistant', $last4);
        }
        try {
            return parent::output_html($data, $query);
        } finally {
            $this->description = $original;
        }
    }
}
```
Lang: `$string['providerkeys:last4_hint'] = 'Saved key ends in {$a}.';`

- [ ] **Step 3: Swap the constructor.** In `settings.php` replace every `new admin_setting_encryptedpassword(` with `new \local_ai_course_assistant\admin_setting_secret_field(` (about 24 sites including the avatar-provider loop; confirm with `grep -c "admin_setting_secret_field" settings.php` against `grep -c "admin_setting_encryptedpassword" <the original>`). **Patch `tests/settings_secret_masking_test.php::test_every_credential_setting_is_a_password_type`.** It only scans declarations written as `new admin_setting_x('local_ai_course_assistant/name'`; a namespace-qualified `new \local_ai_course_assistant\admin_setting_secret_field(` would silently fall out of the scan, which is a guard that passes by seeing nothing. Make three edits:

1. The pattern accepts an optional leading namespace:
```php
        $pattern = '/new\s+\\?(?:local_ai_course_assistant\\)?(admin_setting_\w+)\s*\(\s*\'local_ai_course_assistant\/([a-z0-9_]+)\'/i';
```
2. Both password types are accepted:
```php
            if (!in_array(strtolower($class), ['admin_setting_encryptedpassword', 'admin_setting_secret_field'], true)) {
                $offenders[] = "{$name} (declared {$class})";
            }
```
3. A floor, so the scan can never quietly shrink again (the real count is about 24 after Task 7; use 20):
```php
        $this->assertGreaterThanOrEqual(20, $checked, 'the scan saw too few credential settings: the declaration pattern has drifted from settings.php');
```
Run the test BEFORE the constructor swap to confirm it still passes with the new pattern, then again after the swap. After the swap it must still report the same number of checked settings (the floor guards this).

- [ ] **Step 4: Run** `admin_setting_secret_field_test` (expect `OK (3 tests, ...)`), `secrets_test`, `settings_secret_masking_test`, `settings_page_structure_test`.

- [ ] **Step 5: Commit** (`feat: masked last-four hint on every single-key settings field`).

---

### Task 9: The whole-tree no-leak gate (acceptance test for the display rule)

**Files:**
- Create: `tests/settings_no_secret_in_page_test.php`

**Interfaces:** Consumes everything from Tasks 5 to 8. This is the test that proves Tom's rule across every SOLA settings page.

- [ ] **Step 1: Write the test**

```php
<?php
// (standard GPL header)

namespace local_ai_course_assistant;

/**
 * No SOLA settings page ever contains a secret or the ciphertext of one, however it was rendered.
 *
 * Seeds a sentinel into every secret setting and every table row, renders every settings page, then
 * saves and re-renders, then fails a save and re-renders with the submitted values (which is what Moodle
 * hands to output_html after an error).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class settings_no_secret_in_page_test extends \advanced_testcase {
    private const SENTINEL = 'SENTINEL-NOLEAK-7f3a9c2e1b4d';

    /** @var string[] plain sentinels that must never appear */
    private array $plains = [];
    /** @var string[] ciphertext payloads that must never appear */
    private array $payloads = [];

    private function remember(string $plain, string $stored): void {
        $this->plains[] = $plain;
        if (secrets::is_encrypted($stored)) {
            $this->payloads[] = substr($stored, strpos($stored, ':') + 1, 24);
        }
    }

    private function seed_every_secret(): void {
        foreach (secrets::names() as $name) {
            $plain = self::SENTINEL . '-' . $name;
            $stored = secrets::conceal($plain);
            set_config($name, $stored, 'local_ai_course_assistant');
            $this->remember($plain, $stored);
        }
        $rows = '';
        foreach (['openai', 'claude', 'gemini'] as $p) {
            $plain = self::SENTINEL . '-row-' . $p;
            $stored = secrets::conceal($plain);
            $this->remember($plain, $stored);
            $rows .= "{$p}|{$stored}|m-{$p}\n";
        }
        set_config('comparison_providers', trim($rows), 'local_ai_course_assistant');
        $voiceplain = self::SENTINEL . '-voice';
        $voicestored = secrets::conceal($voiceplain);
        $this->remember($voiceplain, $voicestored);
        set_config('voice_providers', "openai|{$voicestored}|main|shimmer|nova", 'local_ai_course_assistant');
    }

    private function all_pages_html(): string {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->setAdminUser();
        $category = \admin_get_root(true, true)->locate('local_ai_course_assistant');
        $html = '';
        foreach ($category->children as $page) {
            if ($page instanceof \admin_settingpage) {
                $html .= $page->output_html();
            }
        }
        return $html;
    }

    private function assert_clean(string $html, string $when): void {
        $this->assertNotSame('', $html, 'the settings pages rendered nothing, so this test would pass vacuously');
        foreach ($this->plains as $plain) {
            $this->assertStringNotContainsString($plain, $html, "a plain secret is in the page ($when)");
        }
        foreach ($this->payloads as $payload) {
            $this->assertStringNotContainsString($payload, $html, "ciphertext is in the page ($when)");
        }
        $this->assertDoesNotMatchRegularExpression('~<input[^>]*type="password"[^>]*value="[^"]+"~i', $html,
            "a password input carries a value ($when)");
        $this->assertDoesNotMatchRegularExpression('~(sodium|openssl-aes-256-ctr):[A-Za-z0-9+/=]{16,}~', $html,
            "ciphertext-shaped text is in the page ($when)");
    }

    public function test_every_secret_is_absent_on_first_load_and_reload(): void {
        $this->resetAfterTest();
        $this->seed_every_secret();
        $this->assert_clean($this->all_pages_html(), 'first load');
        $this->assert_clean($this->all_pages_html(), 'reload');
    }

    public function test_every_secret_is_absent_after_a_successful_save(): void {
        $this->resetAfterTest();
        $this->seed_every_secret();
        $setting = new admin_setting_provider_keys('local_ai_course_assistant/comparison_providers', 'k', 'd');
        $typed = self::SENTINEL . '-typed-on-save';
        $this->remember($typed, '');
        $rows = [];
        foreach (provider_keys::rows() as $r) {
            $rows[] = ['provider' => $r['provider'], 'newkey' => '', 'keepid' => $r['id'], 'models' => $r['models'],
                'temperature' => '', 'baseurl' => ''];
        }
        $rows[] = ['provider' => 'mistral', 'newkey' => $typed, 'keepid' => '', 'models' => '', 'temperature' => '', 'baseurl' => ''];
        $this->assertSame('', $setting->write_setting(['rows' => $rows]));
        $this->assert_clean($this->all_pages_html(), 'after a save');
    }

    public function test_a_typed_key_is_never_echoed_after_a_failed_save(): void {
        $this->resetAfterTest();
        $this->seed_every_secret();
        $typed = self::SENTINEL . '-typed-and-refused';
        $this->remember($typed, '');
        $submitted = ['rows' => [['provider' => 'openai', 'newkey' => $typed, 'keepid' => '', 'models' => '',
            'temperature' => '99', 'baseurl' => '']]];
        $table = new admin_setting_provider_keys('local_ai_course_assistant/comparison_providers', 'k', 'd');
        $this->assertNotSame('', $table->write_setting($submitted));
        $this->assert_clean($table->output_html($submitted), 'provider table after a failed save');

        $voice = new admin_setting_voice_providers('local_ai_course_assistant/voice_providers', 'v', 'd');
        $voicesubmitted = ['rows' => [['provider' => 'gemini', 'newkey' => $typed, 'keepid' => '', 'label' => '',
            'realtime_voice' => '', 'tts_voice' => '']]];
        $this->assertNotSame('', $voice->write_setting($voicesubmitted));
        $this->assert_clean($voice->output_html($voicesubmitted), 'voice table after a failed save');
    }

    public function test_the_save_report_notice_never_carries_a_key(): void {
        $this->resetAfterTest();
        $this->seed_every_secret();
        $report = settings_save_report::describe(['comparison_providers' => ["openai|sodium:AAA|m", "openai|sodium:BBB|m"]]);
        $this->assertStringNotContainsString('sodium', $report['message']);
    }
}
```

- [ ] **Step 2: Run it.** Expected: `OK (4 tests, ...)`. If a test fails, the failure message names the setting or phase; fix the class that rendered the secret, never the test. If `admin_settingpage::output_html()` throws for some page in PHPUnit (a page that needs a request context), render that page's settings one by one with `$setting->output_html($setting->get_setting(), '')`, skip only the setting that throws, and keep an assertion that the total HTML contains the provider table and the voice table (search for `sola-kr-add`) so the test cannot pass by rendering nothing.

- [ ] **Step 3: Prove it can fail** (mutate a COPY via `prove_test.py`):

```bash
python3 scripts/prove_test.py --file classes/admin_setting_keyed_rows.php \
  --find "'value' => (string) \$row[\$column['name']]," \
  --replace "'value' => (string) \$row[\$column['name']] . \$row['stored_key']," \
  --filter settings_no_secret_in_page_test::test_every_secret_is_absent_on_first_load_and_reload
```
Expected exit 0 (caught). Then a second proof: temporarily make `admin_setting_secret_field::output_html` append `secrets::get($this->name)` to the description; expect the same test to go red.

- [ ] **Step 4: Run the full suite** and compare with Task 0's BASE_COUNT plus the tests this plan added (provider_keys 10, no_raw_read 1, sync 9, upgrade 9, provider_keys table 13, voice 5, secret_field 3, no-leak 4, Task 7 1, Task 10 4, Task 11 2). Expected: green, count equal to BASE_COUNT + about 61, never lower.

- [ ] **Step 5: Commit and open PR 2** (`feat: whole-tree no-secret-in-page test`; push `feat/7.9.0-keys-ui`; `gh pr create --base feat/7.9.0-keys-foundation`). Run the `sola-reviewer` agent on the PR diff with this prompt: "Security pass on the provider keys UI: any path where a key, ciphertext or the row id hash could reach HTML, a notice, the config log or the audit log; IDOR on keepid; XSS through provider or model strings in the template; CSRF (settings use sesskey); the failed-save echo."

---

### Task 10: "Use the key from provider row" for Embedding, Rerank and Voice

**Files:**
- Modify: `settings.php` (three new selects, `voyage`), `classes/embedding_provider/base_embedding_provider.php:88`, `classes/embedding_provider/voyage_reranker.php:53-56`, `classes/voice_registry.php:172,336`, `lang/en/local_ai_course_assistant.php`
- Test: `tests/provider_key_sources_test.php`

**Interfaces:** Consumes `provider_keys::key_from_setting(string $setting): string` and `provider_keys::from_choices(): array` (Task 3). Produces settings `embed_key_from`, `rerank_key_from`, `realtime_key_from` (values `own` or a provider id).

- [ ] **Step 1: Write the failing test**

```php
<?php
// (standard GPL header)

namespace local_ai_course_assistant;

/**
 * Embedding, Rerank and Voice can use a provider row's key, and nothing else turns voice on.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class provider_key_sources_test extends \advanced_testcase {

    private function cfg(string $name, string $value): void {
        set_config($name, $value, 'local_ai_course_assistant');
    }

    public function test_embedding_uses_the_chosen_rows_key_and_falls_back_to_its_own(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "openai|sk-proj-ROW-FOR-EMBED-1111-AAAA|m");
        $this->cfg('embed_apikey', secrets::conceal('sk-proj-OWN-EMBED-KEY-2222-BBBB'));
        $this->assertSame('sk-proj-OWN-EMBED-KEY-2222-BBBB', (new \local_ai_course_assistant\embedding_provider\openai_embedding_provider())->resolved_key());
        $this->cfg('embed_key_from', 'openai');
        $this->assertSame('sk-proj-ROW-FOR-EMBED-1111-AAAA', (new \local_ai_course_assistant\embedding_provider\openai_embedding_provider())->resolved_key());
    }

    public function test_rerank_uses_its_row_then_its_own_then_the_embedding_key(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "voyage|pa-VOYAGE-ROW-KEY-3333-CCCC|rerank-2.5");
        $this->cfg('embed_apikey', secrets::conceal('pa-EMBED-KEY-4444-DDDD'));
        $this->assertSame('pa-EMBED-KEY-4444-DDDD', (new \local_ai_course_assistant\embedding_provider\voyage_reranker())->resolved_key());
        $this->cfg('rerank_key_from', 'voyage');
        $this->assertSame('pa-VOYAGE-ROW-KEY-3333-CCCC', (new \local_ai_course_assistant\embedding_provider\voyage_reranker())->resolved_key());
    }

    public function test_voice_uses_the_chosen_row_for_the_realtime_key_only_when_asked(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'gemini');
        $this->cfg('comparison_providers', "openai|sk-proj-FAILOVER-ONLY-5555-EEEE|m");
        $this->assertFalse(voice_registry::is_configured(), 'an OpenAI failover row must not turn voice on');
        $this->assertNull(voice_registry::resolve(voice_registry::CAPABILITY_TTS));
        $this->cfg('realtime_key_from', 'openai');
        $this->assertTrue(voice_registry::is_configured());
        $this->assertSame('sk-proj-FAILOVER-ONLY-5555-EEEE', voice_registry::resolve(voice_registry::CAPABILITY_TTS)['apikey']);
    }

    public function test_the_choices_list_own_plus_every_keyed_provider(): void {
        $this->resetAfterTest();
        $this->cfg('comparison_providers', "openai|sk-proj-CHOICE-ONE-6666-FFFF|m\nollama||x");
        $choices = provider_keys::from_choices();
        $this->assertSame(['own', 'openai'], array_keys($choices));
    }
}
```
(The test reads the resolved key through a new public read-only accessor, so no network call is needed. Add `public function resolved_key(): string { return $this->apikey; }` to BOTH `base_embedding_provider` and `voyage_reranker` in Step 2's implementation. If either constructor takes arguments, pass the same ones the production caller passes.)

- [ ] **Step 2: Run it and confirm it fails**, then implement:

`base_embedding_provider::__construct` (line 88) becomes:
```php
        $own = (string) (self::cfg('embed_apikey') ?: '');
        $fromrow = \local_ai_course_assistant\provider_keys::key_from_setting('embed_key_from');
        // A migration override of embed_apikey (embedding_migration) always wins; otherwise an explicit
        // "use the key from row X" wins over the pasted key.
        $this->apikey = array_key_exists('embed_apikey', self::$configoverrides) ? $own : ($fromrow !== '' ? $fromrow : $own);
```
`voyage_reranker` (lines 53 to 56) becomes:
```php
        $this->apikey = \local_ai_course_assistant\provider_keys::key_from_setting('rerank_key_from');
        if ($this->apikey === '') {
            $this->apikey = (string) (\local_ai_course_assistant\secrets::get('rerank_apikey') ?: '');
        }
        if ($this->apikey === '') {
            $this->apikey = (string) (\local_ai_course_assistant\secrets::get('embed_apikey') ?: '');
        }
```
`voice_registry` line 172: `$legacykey = \local_ai_course_assistant\provider_keys::key_from_setting('realtime_key_from'); if (empty($legacykey)) { $legacykey = \local_ai_course_assistant\secrets::get('realtime_apikey'); }` (then the existing main-provider fallback). `is_configured()` (line 336): add `|| \local_ai_course_assistant\provider_keys::key_from_setting('realtime_key_from') !== ''` to the existing realtime key check. Nothing else in `is_configured()` or `resolve()` changes.

Settings: next to each of `embed_apikey`, `rerank_apikey`, `realtime_apikey` add:
```php
    $settings->add(new admin_setting_configselect(
        'local_ai_course_assistant/embed_key_from',
        get_string('settings:embed_key_from', 'local_ai_course_assistant'),
        get_string('settings:embed_key_from_desc', 'local_ai_course_assistant'),
        'own',
        \local_ai_course_assistant\provider_keys::from_choices()
    ));
```
(and `rerank_key_from`, `realtime_key_from`). Lang:
```php
$string['settings:embed_key_from'] = 'Embedding key source';
$string['settings:embed_key_from_desc'] = 'Use a key you already entered in "Providers and keys" instead of pasting it again. "Use the key pasted below" keeps the field below in charge.';
$string['settings:rerank_key_from'] = 'Rerank key source';
$string['settings:rerank_key_from_desc'] = 'Use a key from "Providers and keys" (for example a voyage row) instead of pasting it again. When neither is set, the Embedding key is used, as before.';
$string['settings:realtime_key_from'] = 'Voice and TTS key source';
$string['settings:realtime_key_from_desc'] = 'Use a key from "Providers and keys" instead of pasting it again. Choosing a row here is what turns voice on from that key. Adding a row for failover alone never does.';
```
If a `hide_if` dependency list in `settings.php` (around line 3664 to 3725) names these fields' siblings, add the new names to the same lists so `settings_dependencies_test` stays green.

- [ ] **Step 3: Run** `provider_key_sources_test` (expect `OK (4 tests, ...)`), `voice_registry_selfhosted_test`, `settings_dependencies_test`, `settings_page_structure_test`, `lang_completeness_test`.

- [ ] **Step 4: Commit** (`feat: Embedding, Rerank and Voice can use a provider row's key; voice never turns on from a failover row`).

---

### Task 11: Inline validation for the primary model, and the voice help text

**Files:**
- Modify: `classes/failover_verifier.php` (extract the display-name check), `classes/provider_keys.php` (`after_settings_saved`), `lang/en/local_ai_course_assistant.php` (voice help text), `tests/failover_verifier_test.php`, `tests/provider_keys_sync_test.php`

**Interfaces:** Produces `failover_verifier::display_name_problem(string $provider, string $model, string $baseurl = ''): string` ('' or the `verify:model_case` message), used by `static_problems()` for rows and by `provider_keys::after_settings_saved()` for the primary `model`.

- [ ] **Step 1: Write the failing tests.** In `failover_verifier_test.php`:

```php
    public function test_display_name_problem_flags_a_capitalised_hosted_model_only(): void {
        $this->assertStringContainsString('gpt-4o-mini', \local_ai_course_assistant\failover_verifier::display_name_problem('openai', 'GPT-4o-mini'));
        $this->assertSame('', \local_ai_course_assistant\failover_verifier::display_name_problem('openai', 'gpt-4o-mini'));
        $this->assertSame('', \local_ai_course_assistant\failover_verifier::display_name_problem('openrouter', 'Meta Llama'));
        $this->assertSame('', \local_ai_course_assistant\failover_verifier::display_name_problem('openai', 'GPT-4o', 'https://gateway.example'));
    }
```
In `provider_keys_sync_test.php`:
```php
    public function test_the_save_callback_warns_about_a_display_name_primary_model(): void {
        $this->resetAfterTest();
        $this->cfg('provider', 'openai');
        $this->cfg('model', 'GPT-4o-mini');
        $this->cfg('comparison_providers', "openai|sk-proj-MODEL-WARN-0000-ABCD|m");
        provider_keys::after_settings_saved();
        $texts = array_map(fn($n) => $n->get_message(), \core\notification::fetch());
        $this->assertNotEmpty(array_filter($texts, fn($t) => str_contains($t, 'gpt-4o-mini')));
    }
```

- [ ] **Step 2: Run both and confirm they fail**, then implement. In `failover_verifier.php` extract the body of the existing model-case check into:

```php
    /**
     * A hosted provider's model written as a display name ("GPT-4o-mini") instead of its id.
     *
     * @param string $provider lowercase provider id
     * @param string $model
     * @param string $baseurl a gateway base URL disables the check
     * @return string the verify:model_case message, or ''
     */
    public static function display_name_problem(string $provider, string $model, string $baseurl = ''): string {
        if ($model === '' || $baseurl !== '' || !in_array($provider, self::HOSTED_LOWERCASE, true)
                || !preg_match('/[A-Z\s]/', $model)) {
            return '';
        }
        return get_string('verify:model_case', 'local_ai_course_assistant', (object) [
            'label' => $provider,
            'model' => $model,
            'suggest' => strtolower((string) preg_replace('/\s+/', '-', $model)),
        ]);
    }
```
and make `static_problems()` call it for each row (`$msg = self::display_name_problem($key, $row['model'], $row['baseurl']); if ($msg !== '') { $problems[] = $msg; }`), preserving existing behavior (the old condition on `$row['label']` in the message becomes the lowercase provider; update any assertion in `failover_verifier_test` that depended on label case). In `provider_keys::after_settings_saved()` add before the early returns:

```php
        $model = trim((string) get_config(self::PLUGIN, 'model'));
        $site = self::site_provider();
        if ($model !== '' && $site !== '') {
            $problem = failover_verifier::display_name_problem($site, $model, (string) get_config(self::PLUGIN, 'apibaseurl'));
            if ($problem !== '') {
                \core\notification::warning($problem);
            }
        }
```
Voice help text (`lang/en`): replace the descriptions of `settings:realtime_apikey_desc` and `settings:voice_providers_heading_desc` with text that ends with: `Adding an OpenAI key here also turns on the speak button, the Speaking Practice chip (for courses that enable it) and the speech-to-text fallback, even when Voice Mode is off.` Before writing it, open `classes/starter_manager.php` around line 225 and `hook_callbacks.php` around line 632 and confirm the chip names and the speak-button behavior; use the exact chip names the code gates on.

- [ ] **Step 3: Run** `failover_verifier_test`, `provider_keys_sync_test`, `lang_completeness_test`. Then **commit** (`feat: warn about a display-name primary model, and say what an OpenAI key turns on`).

---

### Task 12: Translations (45 locales) and back-translation check

**Files:** `lang/<locale>/local_ai_course_assistant.php` for every locale; possibly `tests/lang_completeness_test.php` (only if a locale falls back to English).

- [ ] **Step 1: List the new keys.**

```bash
cd ~/ai-projects/wt-provider-keys
git diff origin/main -- lang/en/local_ai_course_assistant.php | grep '^+\$string' | sed -E "s/^\+\\\$string\['([^']+)'\].*/\1/" | sort > /tmp/new-keys.txt   # use the session scratchpad instead of /tmp
wc -l < /tmp/new-keys.txt
```
Expected about 50 keys.

- [ ] **Step 2: Translate in parallel.** Build `batch.json` as `{locale: {key: translation}}` for the 45 locales. Dispatch 5 `general-purpose` agents with about 9 locales each. Each agent receives: the English strings, the rule that placeholders like `{$a}`, `{$a->provider}`, `{$a->owner}` and the words "Providers and keys", "Change", "SOLA", "voyage", provider names and `sodium` stay untouched, no em dashes, and the instruction to write `{locale: {key: value}}` JSON to its own file in the session scratchpad. Merge the five files into one `batch.json`. Check it parses: `python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(len(d), 'locales', sum(len(v) for v in d.values()), 'strings')" batch.json` (expect 45 locales).

- [ ] **Step 3: Apply and scan.**

```bash
python3 scripts/apply_i18n.py . batch.json
grep -c "providerkeys:" lang/es/local_ai_course_assistant.php    # spot check
```
Grep each locale for English leftovers and for words from the wrong language (the script's own lesson). Run `lang_completeness_test.php` (the 45-locale parity and "not just English" checks).

- [ ] **Step 4: Back-translation QA** for the lesser-resourced locales (am, bm, ha, ig, om, so, wo, yo, zu, sw, ne, pa, bn, ta): nine fresh agents translate each locale's new strings back to English without seeing the original. Any locale whose strings lose the meaning falls back to English (set those values to the English text in that locale file) and is listed in the release notes as awaiting native-speaker review, exactly as bm and wo were for 7.8.9. Re-run `lang_completeness_test.php`.

- [ ] **Step 5: Commit** (`i18n: provider keys strings in 45 locales`).

- [ ] **Step 6: Open PR 3** (`feat/7.9.0-keys-sources`, base `feat/7.9.0-keys-ui`).

---

### Task 13: Docs: release draft, wiki, spec status

**Files:** `.drafts/v7.9.0-release-notes-and-walkthrough.md`, wiki `Admin-Settings.md`, wiki `Changelog.md`, `README.md`, `CLAUDE.md`, the spec.

- [ ] **Step 1: The three-part release draft** (7.9.0 is a minor release, so use the full format: Part 1 release notes with the changelog entry; Part 2 "Key SOLA Features" snapshot carried forward from `.drafts/v7.8.0-release-notes-and-walkthrough.md` with the provider and keys section updated; Part 3 a 20-minute conversational admin walkthrough that includes: open "Providers and keys", add a key without touching the default, see "Saved, ends in", press Change, switch the default and watch the warning when no key exists, point Embedding at a row, confirm the page source holds no key). Begin with the CHANGELOG paragraph from Step 2 so the two agree. State in Part 1 the rollback caveat: 7.8.x does not understand encrypted rows.

- [ ] **Step 2: Wiki.** Add the `## v7.9.0 (<date>): one table for provider keys, and no secret ever shown in settings` entry at the top of `.wiki/Changelog.md` (what changed, the display rule, what the upgrade does to each site, the duplicate-provider rule, the rollback caveat, translations awaiting review). Rewrite the provider and Comparison providers sections of `.wiki/Admin-Settings.md` for the new table and the key-source selects, and delete text that says the comparison table stores keys in plain text. Publish with `python3 scripts/push_wiki.py -m "Changelog: v7.9.0" --dry-run` first; deletions are intended here, so the real run needs `--allow-deletions` after you read the dry-run diff stat.

- [ ] **Step 3: Repo docs.** `README.md`: update any text about the API key field and the comparison table. `CLAUDE.md`: update the "Current version" line, replace "Still plain text: keys inside the free-text `comparison_providers`" with the new truth, add a `provider_keys` entry to the Key Files table, and add the Providers and keys release bullet under Key Features. Spec: change its Status line to "IMPLEMENTED in 7.9.0" at release time.

- [ ] **Step 4: Commit** (`docs: 7.9.0 release draft, README and CLAUDE.md`).

---

### Task 14: Verification and review gate (before any merge)

- [ ] **Step 1: Full suite on the stacked tip.** Run the full suite from the recipe; the count must be at least BASE_COUNT plus every test this plan added, with the same 7 skips. Note it in the PR.

- [ ] **Step 2: Validators.** `cd ~/Sites/moodle/local/ai_course_assistant && php admin/cli/run_validators.php` must report 0 failures.

- [ ] **Step 3: Mutation proofs** (`scripts/prove_test.py`, one per run, never in parallel) for each invariant, expecting exit 0 every time: (a) `write_setting` ignores `keepid` and always blanks the key (`test_saving_with_no_key_typed_leaves_the_stored_table_byte_identical` must fail); (b) `write_setting` stores `newkey` without `secrets::conceal` (`test_a_typed_key_is_saved_encrypted...` must fail); (c) `for_provider` returns the LAST keyed row instead of the first (`test_for_provider_takes_the_first_row...` must fail); (d) `sync_default_key` drops the forced-setting guard (`test_a_key_forced_in_config_php_is_never_written` must fail); (e) `upgrade()` stops handling a conflict (`test_a_conflicting_row_takes_the_key_chat_was_using` must fail); (f) `site_provider()` returns the inferred provider for `auto` even with no legacy key (`test_auto_with_no_legacy_key_never_starts_using_a_direct_provider` must fail).

- [ ] **Step 4: sola-reviewer on all three PRs** with the adversarial security pass: no key, ciphertext or row id hash reaches a notice, the config log or the audit log; `keepid` cannot cross rows (IDOR); provider and model strings escape correctly in the template; the settings POST is behind `sesskey`; `redact_keys` covers the config log. Fix every blocker. Report findings that question a design premise separately from fixes.

- [ ] **Step 5: End-to-end on the local Moodle** (`~/Sites/moodle`): upgrade a copy of a 7.8.9 database (seed plain rows, a default `apikey`, a voice row, a webhook) by bumping the plugin; confirm the three outcomes (rows encrypted, default row added, webhook encrypted), that chat still answers through the default provider, that a failover still reaches its backup, and that the settings page source contains no key (console: `document.documentElement.outerHTML.match(/sodium:|sk-|AIza/)` returns `null`).

- [ ] **Step 6: Merge in order.** Wait for CI on each PR (about 30 minutes), merge PR 1, retarget PR 2 to `main` (`gh pr edit <n> --base main`), merge, repeat for PR 3. Tom's standing rule applies: when a PR is exactly what he asked for and tests pass, merge without waiting for a go.

---

### Task 15: Release and rollout

- [ ] **Step 1: Build and verify the zip** from the primary checkout (`create_fixed_zip.sh` resolves its path from its own location, so run it from `~/ai-projects/ai_course_assistant` after `git pull`, never from the worktree). Verify with `unzip -p ... version.php` (7.9.0, 2026101601) and by CONTENT: the zip must contain `classes/provider_keys.php`, `templates/admin_keyed_rows.mustache` and `amd/build/keyed_rows.min.js`, must not contain `.drafts`, `CLAUDE.md` or `docs/`, and `diff -rq` against `git archive v7.9.0` must show no differing files.

- [ ] **Step 2: Tag and publish.** `git tag v7.9.0 <merge commit>`, push the tag, `gh release create v7.9.0 ai_course_assistant.zip --verify-tag --title "v7.9.0: one table for provider keys, and no secret ever shown in settings" --notes-file <Part 1 of the draft>`; wiki through `push_wiki.py` as in Task 13.

- [ ] **Step 3: Deploy to the dev fleet** with the release zip: `gh release download v7.9.0 -p ai_course_assistant.zip -D <scratch>`, then `~/ai-projects/ecs-dev-deploy/deploy_sola.sh dev <zip> --dry-run`, then for real, verify, then the same for `dev503`. After each deploy: run `ecs_run.sh <site> php admin/cli/cfg.php --component=local_ai_course_assistant --name=version` (expect 2026101601), and open the settings page in the browser: the key and password inputs must be empty or absent of values (scan names and lengths only, never print values), the table rows show "Saved, ends in ....", add a key without changing the default, save and reload twice. Run `verify_failover.php` (full probe) on each site: expect "All good".

- [ ] **Step 4: Tell Tom** the 7.9.0 zip is ready for the Marketplace upload (the form needs his browser), with the zip path, the release URL and the dev results. Send the PR links and release status to the AI Spend/MFA session. A Jira note on CONTRIB-10574 only if Tom asks.

- [ ] **Step 5: Memory.** Update `moodle-marketplace-blocked-on-jira-access`, `sola-prod-circuit-breaker-incident` (or a new `sola-provider-keys-7-9-0` note) and `dev-sites-on-ecs` with the release, the deploy and anything surprising.

---

## Self-review

**Spec coverage.** Section 1 criteria 1 to 7: (1) Task 5 table plus Task 3 mirror; (2) Task 5 "Saved, ends in" and Task 8 hints; (3) Task 4 parity tests; (4) Task 9 whole-tree gate plus Tasks 5, 6, 7; (5) Tasks 1, 4, 5; (6) Task 10; (7) Task 10's `test_voice_uses_the_chosen_row...`. Section 3 (display rule) Tasks 5 to 9. Section 5.1 to 5.8 map to Tasks 1, 2, 3, 5, 4, 10, 6, 8. Section 7 testing plan is Tasks 9 and 14. Section 8 translations is Task 12. Section 9 rollout is Tasks 13 to 15. Decisions 1 to 4 are implemented in Tasks 4 (conflict), 5 and 8 (last four), 5 and 10 (voyage), 5 (remove the field).

**Placeholder scan.** The only deliberate "look at the file first" instructions are: Task 8 step 3 (the exact accepted-types list in the masking test, which has to be read to be edited), Task 10 step 1 (the exact embedding class name and a test accessor), and Task 11 (confirm chip names in `starter_manager.php` before writing help text). Each says what to look for and what to do with it. Task 12 produces strings by translation, not by hand-written code.

**Type consistency.** `provider_keys::rows()` row keys (`id, provider, label, haskey, last4, key, models, model, temperature, baseurl, columns`) and `stored_rows()` keys (`provider, label, stored_key, models, temperature, baseurl, columns, id`) are used consistently in Tasks 2, 3, 4, 5, 6. `admin_setting_keyed_rows` posted keys (`provider`, `newkey`, `keepid`, column names) match every test row helper. The voice rows use `rowid($provider.'|'.$label, $stored)` in both `load_rows()` and the test's `id_of_first_row()`.

**Review focus.** Items 1 to 6 each have a named test: 1 `test_saving_with_no_key_typed_leaves_the_stored_table_byte_identical` (Tasks 5 and 6); 2 `test_an_undecryptable_key_is_present_but_empty` and `test_an_undecryptable_row_shows_saved...` (Tasks 1, 5); 3 `test_an_unknown_keepid_never_attaches_another_rows_key` (Task 5); 4 the two `auto` tests (Tasks 3, 4); 5 `test_a_keyless_provider_saves_without_a_key` and `test_a_keyless_row_is_listed...` (Tasks 1, 5); 6 `test_a_typed_key_is_never_echoed_after_a_failed_save` (Task 9) and its per-table twins.
