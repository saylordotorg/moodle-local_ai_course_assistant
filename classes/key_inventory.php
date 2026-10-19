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
 * Where each API key is stored, without ever handing out a key.
 *
 * A key can sit in five kinds of place: a row of the Providers and keys table (comparison_providers), one of
 * the five pasted-key settings, a row of the voice providers table, the legacy apikey mirror, and a course's
 * own key (course_cfg.apikey). places() lists every one of them; groups() puts the places that hold the same
 * key together. A key is named only by its last four characters (provider_keys::last4(), empty for a key under
 * 12 characters) and by fingerprint(), a keyed hash that is safe to show. Nothing this class returns, prints or
 * throws carries a key, its ciphertext or any other part of it.
 *
 * A stored value this site can't decrypt (a database restored from another site) is not a place: it is skipped,
 * and the developer notice secrets::reveal() would raise for it is held back for the whole walk, so the CLI's
 * --json output stays clean.
 *
 * The line editor replace_in_table() is shared by provider_keys::replace_key() and
 * voice_registry::replace_row_key(), which read and write their own tables.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_inventory {
    /** @var string Plugin component. */
    private const PLUGIN = 'local_ai_course_assistant';

    /** @var string Per-course configuration table. */
    private const COURSE_TABLE = 'local_ai_course_assistant_course_cfg';

    /** @var string[] The pasted-key settings, each a single secret field. */
    public const PASTED = ['embed_apikey', 'rerank_apikey', 'embed_migration_target_apikey', 'realtime_apikey',
        'stt_selfhosted_apikey'];

    /** @var string The legacy setting that mirrors the default provider's row key. */
    public const MIRROR = 'apikey';

    /**
     * A key's fingerprint: a keyed hash (the site identifier is the secret), so it is safe to show and can't be
     * turned back into the key. The same key gives the same fingerprint wherever it is stored.
     *
     * @param string $plain the plain key, exactly as revealed
     * @return string 12 hex characters
     */
    public static function fingerprint(string $plain): string {
        return substr(hash_hmac('sha256', 'keyfp|' . $plain, get_site_identifier()), 0, 12);
    }

    /**
     * Every place a key is stored, in a fixed order: table rows, pasted settings, voice rows, the mirror, courses.
     *
     * Each place is ['kind', 'ref', 'provider', 'courseid', 'course', 'last4', 'fingerprint', 'baseurl']:
     * kind is row, pasted, voice, mirror or course; ref is the provider id for a row or a voice row, the setting
     * name for pasted and mirror, and the course short name for a course; provider is the provider the key is
     * used with ('' when it has none, as for the self-hosted speech-to-text key); courseid is 0 unless kind is
     * course, and course is then the course's full name ('' otherwise); baseurl is where the key is sent, with
     * any credential in it masked (admin_setting_provider_keys::mask_url()), '' for the vendor's own endpoint.
     * Never contains a key.
     *
     * @return array list of place arrays as above
     */
    public static function places(): array {
        return self::walk()['places'];
    }

    /**
     * The places grouped by key, most places first.
     *
     * Keyed by fingerprint. Each group is ['fingerprint', 'last4', 'vendor', 'places']: vendor is the vendor the
     * key's own prefix names (course_key_guard::owner_of_key()), else the one provider id every place shares,
     * else ''. Groups with the same number of places keep the order of their first place.
     *
     * @return array fingerprint => group array as above
     */
    public static function groups(): array {
        $walk = self::walk();
        $groups = [];
        foreach ($walk['places'] as $place) {
            $fp = $place['fingerprint'];
            if (!isset($groups[$fp])) {
                $groups[$fp] = ['fingerprint' => $fp, 'last4' => $place['last4'], 'vendor' => '', 'places' => []];
            }
            $groups[$fp]['places'][] = $place;
        }
        foreach ($groups as $fp => $group) {
            $vendor = $walk['owners'][$fp] ?? '';
            if ($vendor === '') {
                $providers = array_unique(array_column($group['places'], 'provider'));
                $vendor = (count($providers) === 1) ? (string) reset($providers) : '';
            }
            $groups[$fp]['vendor'] = $vendor;
        }
        // PHP 8 sorts are stable, so equal counts keep their first-seen order.
        uasort($groups, fn($a, $b) => count($b['places']) <=> count($a['places']));
        return $groups;
    }

    /**
     * Walk every store once, with debugging held off.
     *
     * A plain key lives only inside the iteration that fingerprints it.
     *
     * @return array ['places' => list of place arrays, 'owners' => fingerprint => vendor named by the key's prefix]
     */
    private static function walk(): array {
        global $CFG;
        // admin_setting_provider_keys (for mask_url()) extends a core admin class.
        require_once($CFG->libdir . '/adminlib.php');
        return self::quietly(function (): array {
            $places = [];
            $owners = [];
            $add = function (string $kind, string $ref, string $provider, string $plain, string $baseurl,
                    int $courseid = 0, string $course = '') use (&$places, &$owners): void {
                if ($plain === '') {
                    return;
                }
                $fp = self::fingerprint($plain);
                if (($owners[$fp] ?? '') === '') {
                    $owners[$fp] = course_key_guard::owner_of_key($plain);
                }
                $places[] = [
                    'kind'        => $kind,
                    'ref'         => $ref,
                    'provider'    => $provider,
                    'courseid'    => $courseid,
                    'course'      => $course,
                    'last4'       => provider_keys::last4($plain),
                    'fingerprint' => $fp,
                    'baseurl'     => $baseurl === '' ? '' : admin_setting_provider_keys::mask_url($baseurl),
                ];
            };

            foreach (provider_keys::rows(true) as $row) {
                $add('row', $row['provider'], $row['provider'], $row['key'], trim($row['baseurl']));
            }
            foreach (self::PASTED as $name) {
                [$provider, $baseurl] = self::pasted_target($name);
                $add('pasted', $name, $provider, secrets::get($name), $baseurl);
            }
            foreach (voice_registry::parse_rows(true) as $row) {
                $add('voice', $row['provider'], $row['provider'], (string) $row['apikey'], '');
            }
            $add('mirror', self::MIRROR, provider_keys::site_provider(), secrets::get(self::MIRROR),
                trim((string) get_config(self::PLUGIN, 'apibaseurl')));
            foreach (self::course_rows() as $row) {
                $add('course', $row['shortname'], $row['provider'], secrets::reveal($row['stored']), $row['baseurl'],
                    $row['courseid'], $row['fullname']);
            }
            return ['places' => $places, 'owners' => $owners];
        });
    }

    /**
     * The provider and base URL a pasted-key setting is used with.
     *
     * Embedding uses embed_provider (default openai) and embed_apibaseurl; Rerank is voyage at rerank_apibaseurl;
     * the migration target uses embed_migration_target_provider, else the live embedding provider; Realtime is
     * openai at its own endpoint; the self-hosted speech-to-text key has no vendor and goes to stt_selfhosted_url.
     *
     * @param string $name one of PASTED
     * @return array [provider id, base URL]
     */
    private static function pasted_target(string $name): array {
        $cfg = fn(string $setting): string => trim((string) (get_config(self::PLUGIN, $setting) ?: ''));
        $embed = strtolower($cfg('embed_provider')) ?: 'openai';
        switch ($name) {
            case 'embed_apikey':
                return [$embed, $cfg('embed_apibaseurl')];
            case 'rerank_apikey':
                return ['voyage', $cfg('rerank_apibaseurl')];
            case 'embed_migration_target_apikey':
                return [strtolower($cfg('embed_migration_target_provider')) ?: $embed, ''];
            case 'realtime_apikey':
                return ['openai', ''];
            default:
                return ['', $cfg('stt_selfhosted_url')];
        }
    }

    /**
     * Course rows that store a key of their own, with the provider each key is used with.
     *
     * The provider is the course's own, else the site's; 'auto' follows the model name
     * (course_key_guard::effective_provider()).
     *
     * @return array list of ['courseid', 'shortname', 'fullname', 'provider', 'stored', 'baseurl'], stored being
     *         the key column as stored (ciphertext or a legacy plain key)
     */
    private static function course_rows(): array {
        global $DB;
        $records = $DB->get_records_select(self::COURSE_TABLE, "apikey IS NOT NULL AND apikey <> ''", null, '',
            'id, courseid, provider, model, apikey, apibaseurl');
        if (!$records) {
            return [];
        }
        $courses = $DB->get_records_list('course', 'id', array_unique(array_column($records, 'courseid')), '',
            'id, shortname, fullname');
        $siteprovider = strtolower(trim((string) (get_config(self::PLUGIN, 'provider') ?: 'auto')));
        $sitemodel = trim((string) get_config(self::PLUGIN, 'model'));
        $out = [];
        foreach ($records as $r) {
            $course = $courses[$r->courseid] ?? null;
            $out[] = [
                'courseid'  => (int) $r->courseid,
                'shortname' => $course ? (string) $course->shortname : (string) $r->courseid,
                'fullname'  => $course ? format_string($course->fullname) : '',
                'provider'  => course_key_guard::effective_provider(strtolower(trim((string) $r->provider)),
                    trim((string) $r->model), $siteprovider, $sitemodel),
                'stored'    => trim((string) $r->apikey),
                'baseurl'   => trim((string) $r->apibaseurl),
            ];
        }
        return $out;
    }

    /**
     * In a "provider|key|..." table, put a new stored value in the key column of every row whose key has the
     * given fingerprint. Pure: reads and writes no setting.
     *
     * A row is what provider_keys::stored_rows() and voice_registry::parse_rows() call one: not blank, not a
     * "#" comment, with a provider in its first column. A comment that quotes the same key is left alone. Only
     * the key text inside a matching row's second column changes: the spaces around it, every other column, a
     * CR at the end of the line and every other line (blank, comment, providerless, or holding another key)
     * come back byte for byte. A key that can't be decrypted matches nothing, quietly.
     *
     * @param string $text the stored table
     * @param string $oldfingerprint fingerprint() of the key to replace
     * @param string $newstored the new key column, already concealed (secrets::conceal())
     * @return array ['text' => the edited table, 'changed' => number of lines changed]
     * @throws \coding_exception when $newstored is empty, not ciphertext, or holds a separator; the message names
     *         neither key
     */
    public static function replace_in_table(string $text, string $oldfingerprint, string $newstored): array {
        if ($newstored === '' || !secrets::is_encrypted($newstored) || strpbrk($newstored, "|\r\n") !== false) {
            throw new \coding_exception('The new key must be passed concealed (secrets::conceal()), as one '
                . 'non-empty field.');
        }
        $lines = explode("\n", $text);
        $changed = 0;
        self::quietly(function () use (&$lines, &$changed, $oldfingerprint, $newstored): void {
            foreach ($lines as $n => $line) {
                $trim = trim($line);
                if ($trim === '' || $trim[0] === '#') {
                    continue;
                }
                $parts = explode('|', $line);
                if (trim($parts[0]) === '' || !isset($parts[1])) {
                    continue;
                }
                $stored = trim($parts[1]);
                if ($stored === '') {
                    continue;
                }
                $plain = secrets::reveal($stored);
                if ($plain === '' || !hash_equals(self::fingerprint($plain), $oldfingerprint)) {
                    continue;
                }
                $at = strpos($parts[1], $stored);
                $parts[1] = substr($parts[1], 0, $at) . $newstored . substr($parts[1], $at + strlen($stored));
                $lines[$n] = implode('|', $parts);
                $changed++;
            }
        });
        return ['text' => implode("\n", $lines), 'changed' => $changed];
    }

    /**
     * Run a function with developer debugging held off, then put debugging back exactly as it was, an exception
     * included. secrets::reveal() raises a notice for a value it can't decrypt, and a walk over every store must
     * stay quiet (settings_usage::secrets_set() does the same).
     *
     * @param callable $fn
     * @return mixed what $fn returns
     */
    private static function quietly(callable $fn) {
        global $CFG;
        $before = [];
        foreach (['debug', 'debugusers'] as $key) {
            $before[$key] = property_exists($CFG, $key) ? [$CFG->$key] : null;
        }
        $CFG->debug = 0;
        $CFG->debugusers = '';
        try {
            return $fn();
        } finally {
            foreach ($before as $key => $was) {
                if ($was === null) {
                    unset($CFG->$key);
                } else {
                    $CFG->$key = $was[0];
                }
            }
        }
    }
}
