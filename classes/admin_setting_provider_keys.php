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

    /** @var string Matches a base URL with a user name or password: anything before an @ in the authority. */
    private const CREDENTIALS = '~^(https?://)[^/?#\\\\]*@~i';

    /** @var string[] Words that make a query-string parameter name a credential (ruling R22), matched per word. */
    private const SECRET_PARAMS = ['key', 'token', 'secret', 'sig', 'signature', 'password', 'auth', 'apikey'];

    /**
     * The columns after the key.
     *
     * @return array[]
     */
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

    /**
     * Rows as stored (server side only).
     *
     * @return array[]
     */
    protected function load_rows(): array {
        return provider_keys::stored_rows();
    }

    /**
     * Rows to the stored string.
     *
     * @param array[] $rows
     * @return string
     */
    protected function store(array $rows): string {
        return provider_keys::serialize($rows);
    }

    /**
     * Lang key of the add button.
     *
     * @return string
     */
    protected function add_label_key(): string {
        return 'providerkeys:add';
    }

    /**
     * A base URL saved before 7.9.0 may carry a user name and password. They never go into the page: the
     * input shows ***, and a save of that is refused until the admin moves the secret to the key column.
     *
     * @param string $column
     * @param string $value
     * @return string
     */
    protected function display_value(string $column, string $value): string {
        return $column === 'baseurl' ? self::mask_url($value) : $value;
    }

    /**
     * Whether a query-string parameter name is a credential: one of its words (split on anything that isn't
     * a letter or digit, and on camelCase) is key, token, secret, sig, signature, password, auth or apikey.
     * "api_key", "X-Amz-Signature" and "accessToken" match; "monkey", "keyboard" and "api-version" don't.
     *
     * @param string $name
     * @return bool
     */
    private static function secret_param(string $name): bool {
        $words = preg_split('~[^A-Za-z0-9]+|(?<=[a-z0-9])(?=[A-Z])~', rawurldecode($name), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $word) {
            if (in_array(strtolower($word), self::SECRET_PARAMS, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a URL's query string has a parameter named like a credential.
     *
     * @param string $url
     * @return bool
     */
    private static function has_secret_query(string $url): bool {
        if (!preg_match('~^[^?#]*\?([^#]*)~', $url, $m)) {
            return false;
        }
        foreach (explode('&', $m[1]) as $part) {
            $eq = strpos($part, '=');
            if (self::secret_param($eq === false ? $part : substr($part, 0, $eq))) {
                return true;
            }
        }
        return false;
    }

    /**
     * A URL as it may appear on a page, in a notice or in the config log: the user name and password are
     * replaced by *** and so is the value of any credential-named query parameter.
     *
     * @param string $url
     * @return string
     */
    public static function mask_url(string $url): string {
        $url = preg_replace(self::CREDENTIALS, '$1***@', $url);
        return preg_replace_callback('~^([^?#]*\?)([^#]*)~', static function (array $m): string {
            $parts = explode('&', $m[2]);
            foreach ($parts as $i => $part) {
                $eq = strpos($part, '=');
                if ($eq !== false && self::secret_param(substr($part, 0, $eq))) {
                    $parts[$i] = substr($part, 0, $eq) . '=***';
                }
            }
            return $m[1] . implode('&', $parts);
        }, $url);
    }

    /**
     * The key column is blanked by the parent. A base URL can carry a credential too (a legacy saved one), so
     * it is masked here, and the config log and the settings-changed notice never see it.
     *
     * @param mixed $raw
     * @return mixed
     */
    public static function redact_keys($raw) {
        $raw = parent::redact_keys($raw);
        if (!is_string($raw) || $raw === '') {
            return $raw;
        }
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $parts = explode('|', $line);
            if (isset($parts[4]) && trim($parts[4]) !== '') {
                $parts[4] = self::mask_url($parts[4]);
            }
            $out[] = implode('|', $parts);
        }
        return implode("\n", $out);
    }

    /**
     * Problems that stop a save. Messages never repeat a typed value except a short provider id or a plain
     * number, because a key pasted into the wrong field must not come back in the page.
     *
     * @param array[] $rows
     * @return string[]
     */
    protected function validate(array $rows): array {
        $errors = [];
        $known = array_merge(provider_keys::CHAT_PROVIDERS, provider_keys::KEY_ONLY_PROVIDERS);
        $plugin = 'local_ai_course_assistant';
        foreach ($rows as $r) {
            $p = $r['provider'];
            if (!in_array($p, $known, true)) {
                $errors[] = branding::str('providerkeys:err_provider', s(self::safe_name($p)));
                continue;
            }
            if ($r['stored_key'] === '' && !in_array($p, provider_keys::KEYLESS, true)) {
                $errors[] = get_string('providerkeys:err_needs_key', $plugin, $p);
            }
            // These three go between pipes on one line, so a pipe or a line break would corrupt the table.
            $badchar = false;
            foreach (['models' => 'providerkeys:col_models', 'temperature' => 'providerkeys:col_temp',
                    'baseurl' => 'providerkeys:col_baseurl'] as $field => $label) {
                if (preg_match('~[|\r\n]~', $r[$field])) {
                    $badchar = true;
                    $errors[] = get_string('providerkeys:err_badchar', $plugin,
                        (object) ['field' => get_string($label, $plugin), 'provider' => $p]);
                }
            }
            if ($badchar) {
                continue;
            }
            if ($r['temperature'] !== '' && (!is_numeric($r['temperature']) || $r['temperature'] < 0 || $r['temperature'] > 2)) {
                $shown = (is_numeric($r['temperature']) && strlen($r['temperature']) <= 12) ? ' (' . s($r['temperature']) . ')' : '';
                $errors[] = get_string('providerkeys:err_temperature', $plugin, $p . $shown);
            }
            if ($r['baseurl'] !== '') {
                if (!preg_match('~^https?://~i', $r['baseurl'])) {
                    $errors[] = get_string('providerkeys:err_baseurl', $plugin, $p);
                } else if (preg_match(self::CREDENTIALS, $r['baseurl'])) {
                    $errors[] = get_string('providerkeys:err_baseurl_credentials', $plugin, $p);
                } else if (self::has_secret_query($r['baseurl'])) {
                    $errors[] = get_string('providerkeys:err_baseurl_query', $plugin, $p);
                }
            }
        }
        return $errors;
    }

    /**
     * After a save, point out a provider listed twice. Allowed (the golden bake-off CLI uses two rows for one
     * provider), but failover and the LLM picker use the first row that has a key.
     *
     * @param mixed $original
     * @return bool
     */
    public function post_write_settings($original) {
        $seen = [];
        foreach (provider_keys::stored_rows() as $row) {
            $seen[$row['provider']] = ($seen[$row['provider']] ?? 0) + 1;
        }
        foreach ($seen as $provider => $count) {
            if ($count > 1) {
                \core\notification::warning(get_string('providerkeys:warn_duplicate', 'local_ai_course_assistant',
                    s(self::safe_name($provider))));
            }
        }
        return parent::post_write_settings($original);
    }
}
