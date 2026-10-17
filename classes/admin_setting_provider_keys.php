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

    /** @var string[] Words that make a query-string parameter name a credential (rulings R22, R30), matched per word. */
    private const SECRET_WORDS = ['key', 'token', 'secret', 'sig', 'signature', 'password', 'passwd', 'pwd', 'pass', 'auth',
        'authorization', 'credential', 'credentials', 'code', 'apikey'];

    /** @var string[] Endings that make a whole lower-case name a credential: apitoken, accesstoken, clientsecret, userpwd. */
    private const SECRET_ENDINGS = ['token', 'secret', 'password', 'passwd', 'pwd', 'signature', 'credential', 'credentials'];

    /** @var string[] Run-together names that are a credential. There is no bare "key" ending: monkey and turkey are fine. */
    private const SECRET_NAMES = ['apikey', 'secretkey', 'accesskey', 'authkey', 'privatekey', 'subscriptionkey'];

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
     * Whether a query-string parameter name is a credential. The name is decoded, then it is one when any of
     * its words (split on anything that isn't a letter or digit, and on camelCase) is a credential word, when
     * the whole lower-case name ends in token, secret, password, passwd, pwd, signature or credential(s), or
     * when it is one of the run-together names (apikey, secretkey, accesskey and so on). "api_key",
     * "X-Amz-Signature", "accessToken", "apitoken", "code" and "pwd" match; "monkey", "turkey",
     * "keyboard", "region" and "api-version" don't.
     *
     * Fails closed: if the pattern engine can't split the name, it counts as a credential.
     *
     * @param string $name
     * @return bool
     */
    private static function secret_param(string $name): bool {
        $decoded = rawurldecode($name);
        $words = preg_split('~[^A-Za-z0-9]+|(?<=[a-z0-9])(?=[A-Z])~', $decoded, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return true;
        }
        foreach ($words as $word) {
            if (in_array(strtolower($word), self::SECRET_WORDS, true)) {
                return true;
            }
        }
        $whole = strtolower($decoded);
        if (in_array($whole, self::SECRET_NAMES, true)) {
            return true;
        }
        foreach (self::SECRET_ENDINGS as $ending) {
            if (str_ends_with($whole, $ending)) {
                return true;
            }
        }
        return false;
    }

    /**
     * What is wrong with a non-empty base URL, if anything. Judges only and calls nothing else in Moodle.
     * Fails closed: when the pattern engine gives up on a test (preg_match() is false) the URL fails that test.
     *
     * @param string $url
     * @return string '' when it is fine, else 'scheme' (not http or https), 'credentials' (a user name or
     *                password in the address) or 'query' (a secret, a # part, or text that couldn't be judged)
     */
    private static function base_url_problem(string $url): string {
        if (!preg_match('~^https?://~i', $url)) {
            return 'scheme';
        }
        if (preg_match(self::CREDENTIALS, $url) !== 0) {
            return 'credentials';
        }
        return self::carries_secret($url) ? 'query' : '';
    }

    /**
     * Whether a base URL carries something that is a secret or can't be judged: a # part (a fragment is never
     * sent to a server, so no API base URL has a use for one), a parameter named like a credential (the query
     * is split on & and ;), or text the pattern engine gave up on (it fails closed). Walks the query one
     * parameter at a time, so a query of several megabytes costs time but no extra memory.
     *
     * @param string $url
     * @return bool
     */
    private static function carries_secret(string $url): bool {
        if (str_contains($url, '#')) {
            return true;
        }
        $q = strpos($url, '?');
        if ($q === false) {
            return false;
        }
        $query = substr($url, $q + 1);
        $length = strlen($query);
        for ($start = 0; $start <= $length; $start = $end + 1) {
            $end = $start + strcspn($query, '&;', $start);
            $part = substr($query, $start, $end - $start);
            $eq = strpos($part, '=');
            if (self::secret_param($eq === false ? $part : substr($part, 0, $eq))) {
                return true;
            }
        }
        return false;
    }

    /**
     * A URL as it may appear on a page, in a notice or in the config log: the user name and password are
     * replaced by ***, so is the value of any credential-named query parameter (the query is split on & and
     * ;), and so is the whole fragment. Fails closed: a URL the pattern engine can't read comes back as ***.
     *
     * @param string $url
     * @return string
     */
    public static function mask_url(string $url): string {
        $url = preg_replace(self::CREDENTIALS, '$1***@', $url);
        if ($url === null) {
            return '***';
        }
        $fragment = '';
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = $hash === strlen($url) - 1 ? '#' : '#***';
            $url = substr($url, 0, $hash);
        }
        $q = strpos($url, '?');
        if ($q === false) {
            return $url . $fragment;
        }
        $query = substr($url, $q + 1);
        $length = strlen($query);
        $masked = '';
        for ($start = 0; $start <= $length; $start = $end + 1) {
            $end = $start + strcspn($query, '&;', $start);
            $part = substr($query, $start, $end - $start);
            $eq = strpos($part, '=');
            $masked .= ($eq !== false && self::secret_param(substr($part, 0, $eq))) ? substr($part, 0, $eq) . '=***' : $part;
            if ($end < $length) {
                $masked .= $query[$end];
            }
        }
        return substr($url, 0, $q + 1) . $masked . $fragment;
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
                $problem = self::base_url_problem($r['baseurl']);
                if ($problem === 'scheme') {
                    $errors[] = get_string('providerkeys:err_baseurl', $plugin, $p);
                } else if ($problem === 'credentials') {
                    $errors[] = get_string('providerkeys:err_baseurl_credentials', $plugin, $p);
                } else if ($problem === 'query') {
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
