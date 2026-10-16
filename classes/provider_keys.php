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
}
