<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

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
    /** @var string[] Settings that decide which row's key apikey mirrors (the table is the fourth; its writers sync themselves). */
    public const MIRROR_SETTINGS = ['provider', 'model', 'apibaseurl'];

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
     * The last four characters of a key, for the "ends in" hint. A short key (under 12 characters) shows nothing.
     * Counted and cut by character, so a multibyte tail is never split into invalid UTF-8.
     *
     * @param string $plain
     * @return string
     */
    public static function last4(string $plain): string {
        return \core_text::strlen($plain) >= 12 ? \core_text::substr($plain, -4) : '';
    }

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
        if (self::mirror_is_forced_off()) {
            return false;
        }
        $provider = self::site_provider();
        if ($provider === '' || in_array($provider, self::KEYLESS, true)) {
            return false;
        }
        $row = self::mirror_lookup($provider, (string) get_config(self::PLUGIN, 'apibaseurl'))['row'];
        if ($row === null || secrets::get('apikey') === $row['key']) {
            return false;
        }
        set_config('apikey', secrets::conceal($row['key']), self::PLUGIN);
        return true;
    }

    /**
     * Is the apikey mirror switched off by config.php?
     *
     * A forced apikey is not ours to write. A forced table keeps its keys out of the database on purpose, and the
     * mirror would copy one in, where it goes stale when config.php is rotated. On such a site the mirror is off by
     * design, so sync_default_key() does nothing and default_key_warnings() has no mirror to warn about.
     *
     * @return bool
     */
    private static function mirror_is_forced_off(): bool {
        global $CFG;
        $forced = $CFG->forced_plugin_settings[self::PLUGIN] ?? [];
        return array_key_exists('apikey', $forced) || array_key_exists(self::SETTING, $forced);
    }

    /**
     * Which row does the apikey mirror copy for a provider and the site's API Base URL?
     *
     * The site's apikey is sent to the site's apibaseurl, and a row's key to the row's base URL. Only the first
     * readable keyed row for the same destination (both empty is the normal case) can stand in for apikey, or a
     * gateway site would be handed the vendor's key and send it to the gateway host. A keyed row for another
     * destination is skipped, not a stop: it can be listed before the right one. A row whose key cannot be
     * decrypted is no keyed row at all.
     *
     * One pass over the table, so sync_default_key() and default_key_warnings() always agree on the row.
     *
     * @param string $provider lowercase provider id
     * @param string $baseurl the site's API Base URL (compared trimmed)
     * @return array{row: ?array, keyed: bool, elsewhere: bool} row: the row the mirror copies, key revealed, or
     *         null; keyed: whether the provider has any readable keyed row, under any base URL; elsewhere: whether
     *         one of them has a base URL of its own that is not the site's, which is a row the admin pointed
     *         somewhere else on purpose
     */
    private static function mirror_lookup(string $provider, string $baseurl): array {
        $baseurl = trim($baseurl);
        $found = ['row' => null, 'keyed' => false, 'elsewhere' => false];
        foreach (self::rows(true) as $candidate) {
            if ($candidate['provider'] !== $provider || !$candidate['haskey'] || $candidate['key'] === '') {
                continue;
            }
            $found['keyed'] = true;
            $rowurl = trim($candidate['baseurl']);
            if ($rowurl === $baseurl) {
                $found['row'] = $found['row'] ?? $candidate;
            } else if ($rowurl !== '') {
                $found['elsewhere'] = true;
            }
        }
        return $found;
    }

    /**
     * Admin updated-callback for provider, model, apibaseurl and the table: keep apikey in step.
     *
     * Sync only, and idempotent, because Moodle runs it once per changed setting, interleaved with the
     * writes, so the table or the provider may still be the old value. It raises no notices; the warnings
     * come from default_provider_warnings(), read once the whole save is done.
     *
     * @param string $fullname unused; Moodle passes the changed setting's name
     * @return void
     */
    public static function after_settings_saved($fullname = ''): void {
        self::sync_default_key();
    }

    /**
     * Keep apikey in step after something OTHER than the admin settings page wrote settings (the signed policy
     * bundle, the automatic model switcher and its rollback). The admin page has its updated-callback
     * (after_settings_saved()); these writers go through set_config(), which fires no callback.
     *
     * Syncs once when any written setting is one the mirror reads (the default provider, its model or the
     * gateway URL), and does nothing when none is: no write, no call. Idempotent, raises no notices.
     *
     * @param string[] $names the settings the caller actually wrote
     * @return bool true when apikey was rewritten
     */
    public static function sync_after_writes(array $names): bool {
        if (!array_intersect(self::MIRROR_SETTINGS, $names)) {
            return false;
        }
        return self::sync_default_key();
    }

    /**
     * Warnings about the default provider, as finished sentences. Pure: reads settings, raises nothing.
     *
     * First the key, if it is a problem: one sentence when the default provider needs a key and has no keyed
     * row, and either the saved key belongs to another vendor or no key is saved anywhere (a custom gateway
     * with a base URL is allowed to have none). Then the model, if the primary model is a display name
     * ("GPT-4o-mini") a hosted provider will refuse: the same check, failover_verifier::display_name_problem(),
     * that judges a failover row's model. The model sentence carries what the admin typed, so it is escaped.
     *
     * Empty when there is no concrete default provider to judge ('auto' with no saved key is core_ai).
     *
     * @return string[]
     */
    public static function default_provider_warnings(): array {
        $provider = self::site_provider();
        if ($provider === '') {
            return [];
        }
        $warnings = self::default_key_warnings($provider);
        $problem = failover_verifier::display_name_problem(
            $provider,
            trim((string) get_config(self::PLUGIN, 'model')),
            trim((string) get_config(self::PLUGIN, 'apibaseurl'))
        );
        if ($problem !== '') {
            $warnings[] = s($problem);
        }
        return $warnings;
    }

    /**
     * The key half of default_provider_warnings(): none when the provider needs no key or has a keyed row (the
     * mirror's row, under the site's API Base URL, or a plain one that is kept for failover).
     *
     * One sentence when none of the provider's keyed rows is under the site's base URL and one of them has a base
     * URL of its own: the mirror copies none of them, so chat still uses the previous default key, and an admin
     * who typed a new key into such a row (moving to an Azure or gateway endpoint) would otherwise see "Saved"
     * and nothing else. A plain row on a gateway site is not that case: it has no base URL of its own, and the
     * sentence would be untrue. Nor is a site whose apikey or table is forced in config.php: the mirror is off
     * there by design, and clearing the row's base URL could break a same-vendor failover row.
     *
     * @param string $provider the concrete default provider
     * @return string[]
     */
    private static function default_key_warnings(string $provider): array {
        if (in_array($provider, self::KEYLESS, true)) {
            return [];
        }
        $baseurl = trim((string) get_config(self::PLUGIN, 'apibaseurl'));
        $found = self::mirror_lookup($provider, $baseurl);
        if ($found['row'] !== null) {
            return [];
        }
        if ($found['elsewhere'] && !self::mirror_is_forced_off()) {
            return [get_string('providerkeys:default_baseurl_mismatch', self::PLUGIN, (object) ['provider' => $provider])];
        }
        if ($found['keyed']) {
            return [];
        }
        $legacy = secrets::get('apikey');
        $owner = $legacy === '' ? '' : course_key_guard::conflict($provider, $legacy, $baseurl);
        if ($owner !== '') {
            return [get_string('providerkeys:default_vendor_mismatch', self::PLUGIN,
                (object) ['provider' => $provider, 'owner' => $owner])];
        }
        if ($legacy === '' && !($provider === 'custom' && $baseurl !== '')) {
            return [get_string('providerkeys:default_no_key', self::PLUGIN, (object) ['provider' => $provider])];
        }
        return [];
    }

    /**
     * The 7.9.0 upgrade. Idempotent. Moves where keys live and never which key a call uses.
     *
     * 1. Encrypt every stored secret still in plain text (secrets::encrypt_stored_settings, which also
     *    covers the voice table).
     * 2. Encrypt every key in the provider table.
     * 3. Make sure the default provider has a keyed row for the destination chat uses. If it has none, add
     *    one from apikey, carrying the site's base URL so a failover never sends a gateway key to the real
     *    vendor. If it has one for the same destination with a DIFFERENT key, chat was using apikey, so the
     *    row takes apikey's value and a one-time notice names the two last-four values. A keyed row for the
     *    same provider but another base URL is a different destination and is left alone. Skipped when
     *    apikey is forced in config.php: that key is kept out of the database on purpose. Skipped too when
     *    the key's own prefix says it belongs to another vendor than the provider (course_key_guard), unless
     *    the site has a gateway base URL, which takes any key.
     *
     * Existing lines are edited as text, never rebuilt from the parsed rows. A one-column line, a comment
     * line, a label's case and any column past the fifth are kept. What does change: a key field is encrypted
     * or swapped, the spaces around each field of a row line are trimmed (conceal_row_keys() rejoins the
     * trimmed fields), and line endings become \n (a CR is dropped). A new row is one line appended at the end.
     *
     * A key it overwrites in a row is first kept, concealed, in config provider_keys_displaced (one release,
     * no screen) and recorded in the audit log as a provider_key_displaced row.
     *
     * Nothing it writes or reports in the clear contains a key: the notice, the audit row and the summary carry
     * a provider id and last-four values only.
     *
     * @return array{encrypted:int, settings:int, row_added:string, conflict:string} conflict is the provider whose
     *         row took the default key ('' when none)
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
        if (array_key_exists('apikey', $forced)) {
            // get_config() returns a forced key, so going on would copy it into the table and leave a stale
            // copy behind when config.php is rotated. The forced key stays where the admin put it.
            return $summary;
        }
        $legacy = secrets::get('apikey');
        $provider = self::site_provider();
        if ($legacy === '' || $provider === '' || in_array($provider, self::KEYLESS, true)) {
            return $summary;
        }
        $baseurl = trim((string) get_config(self::PLUGIN, 'apibaseurl'));
        if (course_key_guard::conflict($provider, $legacy, $baseurl) !== '') {
            // The saved key's own prefix says it belongs to another vendor (auto with an Anthropic key and no
            // model name resolves openai, say). Filing it under this provider, as a new row or over an old
            // one, would hand it to the wrong vendor on a failover. Leave everything as it is; the settings
            // page warns about the mismatch (default_provider_warnings()).
            return $summary;
        }
        $rows = self::stored_rows();
        $found = null;
        foreach ($rows as $i => $row) {
            if ($row['provider'] === $provider && $row['stored_key'] !== '' && trim($row['baseurl']) === $baseurl) {
                $found = $i;
                break;
            }
        }
        $rowkey = $found === null ? '' : secrets::reveal($rows[$found]['stored_key']);
        if ($found === null) {
            $line = self::serialize([['provider' => $provider, 'stored_key' => secrets::conceal($legacy), 'models' => '',
                'temperature' => '', 'baseurl' => $baseurl]]);
            $separator = ($concealed === '' || substr($concealed, -1) === "\n") ? '' : "\n";
            set_config(self::SETTING, $concealed . $separator . $line, self::PLUGIN);
            $summary['row_added'] = $provider;
        } else if ($rowkey !== $legacy) {
            $summary['conflict'] = $provider;
            // The notice first, so a crash between the two writes can't lose it (a re-run finds the same
            // conflict). Structured data, not a sentence: flush_notice() formats it with one lang string.
            $rowlast4 = self::last4($rowkey);
            $defaultlast4 = self::last4($legacy);
            set_config('provider_keys_notice', json_encode([
                'provider'     => $provider,
                'rowlast4'     => $rowlast4,
                'defaultlast4' => $defaultlast4,
            ]), self::PLUGIN);
            // The row is about to lose its key. Keep the old one, still encrypted, for one release, and say so in
            // the audit log (provider and last-four values only), both before the row is rewritten.
            self::keep_displaced($provider, $rows[$found]['stored_key']);
            audit_logger::log('provider_key_displaced', 0, 0, [
                'provider'      => $provider,
                'row_last4'     => $rowlast4,
                'default_last4' => $defaultlast4,
            ]);
            set_config(self::SETTING, self::with_row_key($concealed, $found, secrets::conceal($legacy)), self::PLUGIN);
        }
        return $summary;
    }

    /**
     * Keep the key a conflict is about to overwrite, for one release (config provider_keys_displaced).
     *
     * The setting is a JSON map of provider => concealed key (\core\encryption ciphertext, never plain text).
     * There is no screen for it; it exists so an admin who needed the older key can still be helped. An entry
     * already there is the older, original credential, so a re-run never replaces it. A damaged value is
     * treated as an empty map.
     *
     * @param string $provider lowercase provider id
     * @param string $stored the row's stored key column
     * @return void
     */
    private static function keep_displaced(string $provider, string $stored): void {
        $map = json_decode((string) get_config(self::PLUGIN, 'provider_keys_displaced'), true);
        if (!is_array($map)) {
            $map = [];
        }
        if (isset($map[$provider])) {
            return;
        }
        $map[$provider] = secrets::conceal($stored);
        set_config('provider_keys_displaced', json_encode($map), self::PLUGIN);
    }

    /**
     * Replace the key field of one row in a stored table, touching no other row.
     *
     * Only that row's line is rebuilt: its fields are trimmed and rejoined with "|", every column kept, the
     * key column replaced. Every other line comes back as it was, apart from line endings, which become \n (a
     * CR is dropped, as conceal_row_keys() already did to the text passed in).
     *
     * The index counts rows the way stored_rows() does (blank lines, comment lines and lines with no
     * provider are not rows), so it is the index stored_rows() reports. Keep the two in step.
     *
     * @param string $text the stored table
     * @param int $index row index as reported by stored_rows()
     * @param string $stored the new value for the key column (already concealed)
     * @return string the table with that one key field replaced
     */
    private static function with_row_key(string $text, int $index, string $stored): string {
        $lines = preg_split("/\r?\n/", $text);
        $seen = 0;
        foreach ($lines as $n => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if ($parts[0] === '') {
                continue;
            }
            if ($seen++ === $index) {
                $parts[1] = $stored;
                $lines[$n] = implode('|', $parts);
                break;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Show the one-time upgrade notice, if there is one, then forget it.
     *
     * The stored value is JSON: {provider, rowlast4, defaultlast4}. Anything else (the English sentence an
     * earlier build stored, a damaged value) shows nothing; it is still forgotten.
     *
     * Only a web request outside an upgrade shows it, and the value is left untouched otherwise.
     * admin/cli/upgrade.php runs admin_apply_default_settings(), which includes settings.php (and so this)
     * straight after the upgrade step wrote the notice: showing it there would queue it into the CLI's
     * throwaway session and forget it before any administrator could see it.
     *
     * @return void
     */
    public static function flush_notice(): void {
        global $CFG;
        if (!settings_save_report::is_web_request() || !empty($CFG->upgraderunning)) {
            return;
        }
        $raw = (string) get_config(self::PLUGIN, 'provider_keys_notice');
        if ($raw === '') {
            return;
        }
        unset_config('provider_keys_notice', self::PLUGIN);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }
        $a = new \stdClass();
        foreach (['provider', 'rowlast4', 'defaultlast4'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                return;
            }
            $a->$field = s($data[$field]);
        }
        if ($a->provider === '') {
            return;
        }
        \core\notification::warning(branding::str('providerkeys:upgrade_conflict', $a));
    }

    /**
     * The provider row an Embedding or Rerank field points at, or null for "use my own" or a choice that can't be used.
     *
     * The row's key is sent to the target, so a row is passed over (null, and the caller falls back to the pasted
     * key, silently) when:
     * - it is gone, has no key, or its key cannot be decrypted;
     * - it has a base URL of its own that is not the target's base URL: an Azure OpenAI or gateway key is for that
     *   destination, not for the vendor's own endpoint (see row_reaches());
     * - with a target vendor, the key's own prefix identifies it as ANOTHER vendor's (course_key_guard::conflict():
     *   unambiguous prefixes only, a bare sk- identifies nobody). That covers a choice that went stale when the
     *   admin switched the embedding provider after picking a row. A base URL for the target turns this check
     *   off: a gateway takes any key.
     *
     * @param string $setting one of embed_key_from, rerank_key_from
     * @param string $vendor the provider the key will be sent to ('' = do not check the vendor)
     * @param string $baseurl the base URL the key will be sent to ('' = the vendor's own endpoint)
     * @return array|null the row, key revealed
     */
    public static function chosen_row(string $setting, string $vendor = '', string $baseurl = ''): ?array {
        $from = strtolower(trim((string) get_config(self::PLUGIN, $setting)));
        if ($from === '' || $from === 'own') {
            return null;
        }
        $row = self::for_provider($from);
        if ($row === null || $row['key'] === '' || !self::row_reaches($row['baseurl'], $baseurl)) {
            return null;
        }
        if ($vendor !== '' && course_key_guard::conflict(strtolower($vendor), $row['key'], trim($baseurl)) !== '') {
            return null;
        }
        return $row;
    }

    /**
     * The key of the provider row an Embedding or Rerank field points at, or '' for "use my own".
     *
     * @param string $setting one of embed_key_from, rerank_key_from
     * @param string $vendor the provider the key will be sent to ('' = do not check the vendor)
     * @param string $baseurl the base URL the key will be sent to ('' = the vendor's own endpoint)
     * @return string the plain key, or '' when the row is not to be used (see chosen_row())
     */
    public static function key_from_setting(string $setting, string $vendor = '', string $baseurl = ''): string {
        $row = self::chosen_row($setting, $vendor, $baseurl);
        return $row === null ? '' : $row['key'];
    }

    /**
     * May a provider row's key be sent to this target? A row with no base URL is for the vendor's own endpoint and
     * goes anywhere the caller already vetted (the vendor check); a row with a base URL of its own holds a
     * credential for that destination, so it goes only to the same one. Compared trimmed.
     *
     * @param string $rowbaseurl the row's base URL column
     * @param string $target the base URL the key would be sent to ('' = the vendor's own endpoint)
     * @return bool
     */
    public static function row_reaches(string $rowbaseurl, string $target): bool {
        $rowbaseurl = trim($rowbaseurl);
        return $rowbaseurl === '' || $rowbaseurl === trim($target);
    }

    /**
     * Which of the given key-source settings point at a provider row instead of "own".
     *
     * @param string[] $settings setting names (embed_key_from, rerank_key_from, realtime_key_from)
     * @return string[] the names whose value is a provider id
     */
    public static function chosen_row_sources(array $settings): array {
        $chosen = [];
        foreach ($settings as $setting) {
            $from = strtolower(trim((string) get_config(self::PLUGIN, $setting)));
            if ($from !== '' && $from !== 'own') {
                $chosen[] = $setting;
            }
        }
        return $chosen;
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
}
