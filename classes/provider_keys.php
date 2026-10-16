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
        // The site's apikey is sent to the site's apibaseurl, and a row's key to the row's base URL. Copy
        // only between the same destination (both empty is the normal case), or a gateway site would be
        // handed the vendor's key and send it to the gateway host.
        if (trim($row['baseurl']) !== trim((string) get_config(self::PLUGIN, 'apibaseurl'))) {
            return false;
        }
        set_config('apikey', secrets::conceal($row['key']), self::PLUGIN);
        return true;
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
     * Warnings about the default provider's key, as finished sentences. Pure: reads settings, raises nothing.
     *
     * Empty when the default provider needs no key or has a keyed row. Otherwise one sentence when the
     * saved key belongs to another vendor, or when no key is saved anywhere (a custom gateway with a base
     * URL is allowed to have none).
     *
     * @return string[]
     */
    public static function default_provider_warnings(): array {
        $provider = self::site_provider();
        if ($provider === '' || in_array($provider, self::KEYLESS, true) || self::key_for($provider) !== '') {
            return [];
        }
        $baseurl = trim((string) get_config(self::PLUGIN, 'apibaseurl'));
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
}
