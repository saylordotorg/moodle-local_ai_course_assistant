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

/**
 * Provider keys and other secrets, encrypted at rest (#302).
 *
 * Every secret setting is an admin_setting_encryptedpassword, which stores
 * \core\encryption output ("sodium:..." or "openssl-aes-256-ctr:...") in
 * config_plugins, keyed by a site key in dataroot. Everything that needs a
 * secret reads it through get(), never through get_config(): get_config()
 * returns the ciphertext, which a provider would reject as a bad key.
 * secrets_guard_test fails if a secret is read any other way.
 *
 * A value WITHOUT the encryption prefix is returned as it is. That covers a key
 * forced in config.php ($CFG->forced_plugin_settings), which never passes
 * through the setting, and a site between deploy and upgrade.
 *
 * A value that carries the prefix but will not decrypt returns '' and a
 * developer notice, never the ciphertext. The usual cause is a database
 * restored on a site whose dataroot holds a different key, a staging copy of
 * production for example: the keys have to be entered again there.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class secrets {

    /** Avatar providers whose key and webhook secret are secrets. */
    const AVATAR_PROVIDERS = ['did', 'heygen', 'tavus', 'synthesia'];

    /**
     * Every setting that holds a secret.
     *
     * @return string[]
     */
    public static function names(): array {
        $names = [
            'apikey',
            'realtime_apikey',
            'embed_apikey',
            'rerank_apikey',
            'embed_migration_target_apikey',
            'stt_selfhosted_apikey',
            'xai_proxy_jwt_secret',
            'whatsapp_api_token',
            'soapbox_storage_key',
            'soapbox_storage_secret',
            'zendesk_token',
            'redash_api_key',
            'redash_user_api_key',
            'spend_export_key',
        ];
        foreach (self::AVATAR_PROVIDERS as $p) {
            $names[] = $p . '_api_key';
            $names[] = $p . '_webhook_secret';
        }
        return $names;
    }

    /**
     * Whether a setting holds a secret.
     *
     * @param string $name
     * @return bool
     */
    public static function is_secret(string $name): bool {
        return in_array($name, self::names(), true);
    }

    /**
     * Read a secret setting as plain text.
     *
     * @param string $name setting name in local_ai_course_assistant
     * @return string '' when unset or undecryptable
     */
    public static function get(string $name): string {
        return self::reveal((string) (get_config('local_ai_course_assistant', $name) ?: ''));
    }

    /**
     * Turn a stored value into plain text.
     *
     * @param string $stored ciphertext from \core\encryption, or a plain value
     * @return string
     */
    public static function reveal(string $stored): string {
        if (!self::is_encrypted($stored)) {
            return $stored;
        }
        try {
            return \core\encryption::decrypt($stored);
        } catch (\Throwable $e) {
            debugging('local_ai_course_assistant: a stored key could not be decrypted with this site\'s '
                . 'encryption key, so it is treated as unset. If this database came from another site, '
                . 'enter the keys again. (' . $e->getMessage() . ')', DEBUG_DEVELOPER);
            return '';
        }
    }

    /**
     * Turn plain text into a stored value. Empty stays empty.
     *
     * @param string $plain
     * @return string
     */
    public static function conceal(string $plain): string {
        $plain = trim($plain);
        if ($plain === '' || self::is_encrypted($plain)) {
            return $plain;
        }
        return \core\encryption::encrypt($plain);
    }

    /**
     * Whether a stored value is \core\encryption output.
     *
     * @param string $value
     * @return bool
     */
    public static function is_encrypted(string $value): bool {
        // Literal prefixes, not the class constants: Moodle 5.0 removed
        // \core\encryption::METHOD_OPENSSL, and a value written by 4.x with it
        // must still be recognised as ciphertext (it then fails to decrypt and
        // reads as unset) rather than passed through as if it were a key.
        return (bool) preg_match('~^(sodium|openssl-aes-256-ctr):~', $value);
    }

    /**
     * Encrypt every secret setting still stored as plain text. Idempotent.
     *
     * Forced settings are skipped: config.php is their source, and writing a
     * ciphertext into the database under a forced value changes nothing.
     *
     * @return int how many settings were encrypted
     */
    public static function encrypt_stored_settings(): int {
        global $CFG, $DB;
        $forced = $CFG->forced_plugin_settings['local_ai_course_assistant'] ?? [];
        $done = 0;
        foreach (self::names() as $name) {
            if (array_key_exists($name, $forced)) {
                continue;
            }
            $raw = $DB->get_field('config_plugins', 'value',
                ['plugin' => 'local_ai_course_assistant', 'name' => $name]);
            if ($raw === false || trim((string) $raw) === '' || self::is_encrypted((string) $raw)) {
                continue;
            }
            set_config($name, self::conceal((string) $raw), 'local_ai_course_assistant');
            $done++;
        }
        // The voice providers table keeps a key in each row.
        if (!array_key_exists('voice_providers', $forced)) {
            $raw = (string) ($DB->get_field('config_plugins', 'value',
                ['plugin' => 'local_ai_course_assistant', 'name' => 'voice_providers']) ?: '');
            $new = self::conceal_row_keys($raw);
            if ($raw !== '' && $new !== $raw) {
                set_config('voice_providers', $new, 'local_ai_course_assistant');
                $done++;
            }
        }
        return $done;
    }

    /**
     * Encrypt the key field of every row in a "provider|apikey|..." table.
     *
     * Used for voice_providers, whose rows each carry a key. Comment and blank
     * lines are kept as they are; a key that is already ciphertext is left
     * alone, so a form that posts the stored value back stays idempotent.
     * Ciphertext is base64 after a "method:" prefix, so it never contains the
     * "|" separator or a newline.
     *
     * @param string $raw
     * @return string
     */
    public static function conceal_row_keys(string $raw): string {
        $out = [];
        foreach (explode("\n", str_replace("\r", '', $raw)) as $line) {
            $trim = trim($line);
            if ($trim === '' || $trim[0] === '#') {
                $out[] = $line;
                continue;
            }
            $parts = array_map('trim', explode('|', $trim));
            if (isset($parts[1])) {
                $parts[1] = self::conceal($parts[1]);
            }
            $out[] = implode('|', $parts);
        }
        return implode("\n", $out);
    }

    /**
     * Encrypt every per-course key still stored as plain text. Idempotent.
     *
     * @return int how many course keys were encrypted
     */
    public static function encrypt_course_keys(): int {
        global $DB;
        $done = 0;
        $rows = $DB->get_recordset_select('local_ai_course_assistant_course_cfg',
            $DB->sql_isnotempty('local_ai_course_assistant_course_cfg', 'apikey', true, true), null, '', 'id, apikey');
        foreach ($rows as $row) {
            $raw = trim((string) $row->apikey);
            if ($raw === '' || self::is_encrypted($raw)) {
                continue;
            }
            $DB->set_field('local_ai_course_assistant_course_cfg', 'apikey', self::conceal($raw), ['id' => $row->id]);
            $done++;
        }
        $rows->close();
        return $done;
    }
}
