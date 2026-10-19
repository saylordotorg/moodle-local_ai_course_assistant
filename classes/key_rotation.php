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
 * Replace one API key everywhere it is stored, or nowhere.
 *
 * plan() finds the key (by its last four characters, or by its fingerprint) in key_inventory::groups(), checks the
 * new key, and says what apply() would change. apply() runs plan() again and then writes every store inside ONE
 * database transaction: the Providers and keys rows (edited as text lines, provider_keys::replace_key()), the voice
 * rows (voice_registry::replace_row_key()), each pasted-key setting and the apikey mirror that held the key, each
 * course that held it, and one key_rotated audit row. A failure anywhere rolls all of it back. After the commit the
 * apikey mirror is re-synced to the default provider's row.
 *
 * Refused, and nothing written, when: the selector matches no key or more than one; the new key is empty, shorter
 * than 8 characters, holds a "|", CR, LF or NUL, or is the old key again; the new key's prefix names another vendor
 * than a place's provider (course_key_guard::conflict()); or a place holding the key is forced in config.php, which
 * this code can't write (the place is counted in notrotated and named in an error, so the admin changes config.php
 * first and runs again, rather than being told the key was replaced when a copy is left behind).
 *
 * Nothing either method returns, prints, logs or audits carries a key, its ciphertext or any part of it beyond
 * provider_keys::last4(). Errors name a place by kind and ref (provider id, setting name or course short name).
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_rotation {
    /** @var string Plugin component. */
    private const PLUGIN = 'local_ai_course_assistant';

    /** @var string Per-course configuration table. */
    private const COURSE_TABLE = 'local_ai_course_assistant_course_cfg';

    /** @var string[] Every kind of place, in the order counts are reported. */
    public const KINDS = ['row', 'pasted', 'voice', 'mirror', 'course'];

    /** @var int The shortest new key accepted. */
    public const MIN_LENGTH = 8;

    /** @var int The longest new key accepted. */
    public const MAX_LENGTH = 512;

    /** @var string[] Command-line options that would carry a key, recognised by the command only to refuse them. */
    public const KEY_OPTIONS = ['new-key', 'newkey', 'key', 'apikey', 'old-key'];

    /** @var string[] The command's on/off options. */
    private const FLAGS = ['help', 'list', 'stdin', 'apply'];

    /** @var string[] Key prefixes an environment variable name must not start with. */
    private const KEY_PREFIXES = ['sk-', 'sk-ant-', 'AIza', 'xai-'];

    /** @var string The audit action of a rotation. */
    public const AUDIT_ACTION = 'key_rotated';

    /**
     * What a rotation would change. Writes nothing.
     *
     * @param array $selector exactly one of ['last4' => 'ab12'] or ['fingerprint' => key_inventory::fingerprint()]
     * @param string $newkey the new plain key (trimmed before it is checked)
     * @return array ['ok' => bool, 'errors' => string[], 'reason' => '' | 'usage' | 'nomatch' | 'ambiguous' |
     *         'refused', 'group' => null or ['fingerprint', 'last4', 'vendor'], 'counts' => kind => int (every
     *         kind in KINDS), 'notrotated' => int (places forced in config.php), 'places' => list of
     *         key_inventory places, each with 'forced' => bool added]; group, counts and places are filled
     *         whenever exactly one key was selected, even when the new key is refused
     */
    public static function plan(array $selector, string $newkey): array {
        $result = ['ok' => false, 'errors' => [], 'reason' => '', 'group' => null,
            'counts' => array_fill_keys(self::KINDS, 0), 'notrotated' => 0, 'places' => []];

        $group = self::select(key_inventory::groups(), $selector, $result);
        if ($group === null) {
            return $result;
        }
        $result['group'] = ['fingerprint' => $group['fingerprint'], 'last4' => $group['last4'],
            'vendor' => $group['vendor']];

        foreach ($group['places'] as $place) {
            $place['forced'] = self::is_forced($place);
            $result['places'][] = $place;
            if ($place['forced']) {
                $result['notrotated']++;
                $result['errors'][] = self::name($place) . ' is set in config.php, which this command does not '
                    . 'change. Put the new key there first, then run the command again.';
            } else {
                $result['counts'][$place['kind']]++;
            }
        }

        $new = trim($newkey);
        $problem = self::key_problem($new);
        if ($problem !== '') {
            $result['errors'][] = $problem;
        } else if (hash_equals($group['fingerprint'], key_inventory::fingerprint($new))) {
            $result['errors'][] = 'The new key is the same as the old key.';
        } else {
            foreach ($result['places'] as $place) {
                $owner = course_key_guard::conflict($place['provider'], $new, $place['baseurl']);
                if ($owner !== '') {
                    $result['errors'][] = self::name($place) . ' is used with ' . $place['provider']
                        . ', and the new key looks like a ' . $owner . ' key.';
                }
            }
        }

        if ($result['errors']) {
            $result['reason'] = 'refused';
            return $result;
        }
        $result['ok'] = true;
        return $result;
    }

    /**
     * Replace the key everywhere plan() found it, in one transaction, or change nothing.
     *
     * Refused (nothing written) when plan() refuses, or when a database transaction is already open: a rollback
     * inside someone else's transaction can't be promised, so a rotation always runs in a transaction of its own.
     *
     * @param array $selector exactly one of ['last4' => 'ab12'] or ['fingerprint' => key_inventory::fingerprint()]
     * @param string $newkey the new plain key (trimmed)
     * @return array ['ok' => bool, 'errors' => string[], 'reason' => as plan(), or 'failed' when a write failed
     *         and everything was rolled back, or 'unconfirmed' when the commit worked but reading the stores back
     *         did not find the new key, 'counts' => kind => places changed (every kind in KINDS),
     *         'notrotated' => int, 'newlast4' => provider_keys::last4() of the new key ('' unless it worked)]
     */
    public static function apply(array $selector, string $newkey): array {
        global $DB, $USER;
        $plan = static::plan($selector, $newkey);
        $result = ['ok' => false, 'errors' => $plan['errors'], 'reason' => $plan['reason'],
            'counts' => array_fill_keys(self::KINDS, 0), 'notrotated' => $plan['notrotated'], 'newlast4' => ''];
        if (!$plan['ok']) {
            return $result;
        }
        if ($DB->is_transaction_started()) {
            $result['reason'] = 'refused';
            $result['errors'][] = 'A database transaction is already open, so the change could not be made in one '
                . 'step. Nothing was changed.';
            return $result;
        }

        $fingerprint = $plan['group']['fingerprint'];
        $newstored = secrets::conceal(trim($newkey));
        $newlast4 = provider_keys::last4(trim($newkey));
        $refs = ['pasted' => [], 'mirror' => [], 'course' => []];
        foreach ($plan['places'] as $place) {
            if (isset($refs[$place['kind']])) {
                $refs[$place['kind']][] = $place['kind'] === 'course' ? $place['courseid'] : $place['ref'];
            }
        }

        $userid = (int) ($USER->id ?? 0);
        // The mirror follows the default provider's row, so it needs a re-sync only when a row or the mirror held
        // the key.
        $resync = $plan['counts']['row'] > 0 || $plan['counts']['mirror'] > 0;
        $changed = false;
        try {
            $done = static::in_one_transaction(function () use ($fingerprint, $newstored, $refs, $plan, $newlast4,
                    $userid, $resync, &$changed): array {
                $done = array_fill_keys(self::KINDS, 0);
                $done['row'] = static::write_rows($fingerprint, $newstored);
                $done['voice'] = static::write_voice($fingerprint, $newstored);
                $done['pasted'] = static::write_settings($refs['pasted'], $fingerprint, $newstored);
                $done['mirror'] = static::write_settings($refs['mirror'], $fingerprint, $newstored);
                $done['course'] = static::write_courses($refs['course'], $fingerprint, $newstored);
                if ($done !== $plan['counts']) {
                    // A store changed between the plan and the write: roll back rather than half rotate.
                    $changed = true;
                    throw new \RuntimeException('A store changed since the plan.');
                }
                // Written here, inside the transaction, and allowed to throw: a rotation with no audit row rolls back.
                static::write_audit($userid, [
                    'provider'      => $plan['group']['vendor'],
                    'fingerprint'   => $fingerprint,
                    'old_last4'     => $plan['group']['last4'],
                    'new_last4'     => $newlast4,
                    'counts'        => $done,
                    'mirror_synced' => $resync,
                ]);
                return $done;
            });
        } catch (\Throwable $e) {
            // The writes were rolled back in the database; the config cache may still hold a value written inside
            // the transaction, so drop it. The exception's message is never passed on: it could quote a value.
            \cache_helper::invalidate_by_definition('core', 'config', [], self::PLUGIN);
            $result['reason'] = 'failed';
            $result['errors'][] = $changed
                ? 'A store changed while the key was being replaced. Nothing was changed; run the command again.'
                : 'The key could not be replaced (' . get_class($e) . '). Nothing was changed.';
            return $result;
        }

        // Moodle's config cache is not transaction-aware: a request that read the settings between the last write
        // and the commit may have cached the old rows. Drop the cache before anything reads it again.
        \cache_helper::invalidate_by_definition('core', 'config', [], self::PLUGIN);

        if ($resync) {
            // Chat reads the default provider's key from the apikey mirror. sync_after_writes() skips a table write
            // on purpose (MIRROR_SETTINGS), so sync directly, and never let a failed mirror undo a committed
            // rotation: the class name only, like sync_after_writes().
            try {
                provider_keys::sync_default_key();
            } catch (\Throwable $e) {
                debugging('key_rotation could not re-mirror the default key (' . get_class($e) . ')', DEBUG_DEVELOPER);
            }
        }

        $result['counts'] = $done;
        if (!static::confirm(key_inventory::fingerprint(trim($newkey)), $done)) {
            $result['reason'] = 'unconfirmed';
            $result['errors'][] = 'The change was saved, but reading it back did not find the new key. Run --list '
                . 'to see where each key is now before you revoke the old one.';
            return $result;
        }
        $result['ok'] = true;
        $result['newlast4'] = $newlast4;
        return $result;
    }

    /**
     * After the commit, read the stores back: is the new key in at least one place of every kind that was written?
     *
     * @param string $newfingerprint the new key's fingerprint
     * @param array $counts kind => places written
     * @return bool
     */
    protected static function confirm(string $newfingerprint, array $counts): bool {
        $found = [];
        foreach (key_inventory::places() as $place) {
            if ($place['fingerprint'] === $newfingerprint) {
                $found[$place['kind']] = true;
            }
        }
        foreach ($counts as $kind => $n) {
            if ($n > 0 && empty($found[$kind])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Write the key_rotated audit row, the same table and columns as audit_logger::log(), but letting a failed
     * insert throw so that the transaction rolls back (audit_logger::log() swallows it).
     *
     * @param int $userid the user who ran the rotation
     * @param array $details provider, fingerprint, last-four values and counts; never a key
     * @return void
     * @throws \dml_exception when the row can't be written
     */
    protected static function write_audit(int $userid, array $details): void {
        global $DB;
        $DB->insert_record('local_ai_course_assistant_audit', (object) [
            'action'      => self::AUDIT_ACTION,
            'userid'      => $userid,
            'courseid'    => 0,
            'ipaddress'   => getremoteaddr(),
            'useragent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'details'     => json_encode($details),
            'timecreated' => time(),
        ]);
    }

    /**
     * Run the writes in one delegated transaction: commit when they all worked, roll every one back when any threw.
     *
     * @param callable $writes does every write and returns the counts
     * @return array what $writes returns
     * @throws \Throwable whatever $writes threw, after the rollback
     */
    protected static function in_one_transaction(callable $writes): array {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $done = $writes();
            $transaction->allow_commit();
            return $done;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Replace the key in the Providers and keys rows.
     *
     * @param string $fingerprint the old key's fingerprint
     * @param string $newstored the new key, concealed
     * @return int rows changed
     */
    protected static function write_rows(string $fingerprint, string $newstored): int {
        return provider_keys::replace_key($fingerprint, $newstored);
    }

    /**
     * Replace the key in the voice provider rows.
     *
     * @param string $fingerprint the old key's fingerprint
     * @param string $newstored the new key, concealed
     * @return int rows changed
     */
    protected static function write_voice(string $fingerprint, string $newstored): int {
        return voice_registry::replace_row_key($fingerprint, $newstored);
    }

    /**
     * Replace the key in single-key settings (the pasted keys, or the apikey mirror), each only if it still holds
     * the old key and is not forced in config.php.
     *
     * @param string[] $names setting names
     * @param string $fingerprint the old key's fingerprint
     * @param string $newstored the new key, concealed
     * @return int settings changed
     */
    protected static function write_settings(array $names, string $fingerprint, string $newstored): int {
        $changed = 0;
        foreach ($names as $name) {
            if (self::forced_setting($name) || !hash_equals($fingerprint, key_inventory::setting_fingerprint($name))) {
                continue;
            }
            set_config($name, $newstored, self::PLUGIN);
            $changed++;
        }
        return $changed;
    }

    /**
     * Replace the key of courses, setting only the key column and the time modified (never a full-row rewrite),
     * each only if its row still holds the old key (a row changed since the plan is skipped, and the count check in
     * apply() then rolls everything back).
     *
     * @param array $courseids course ids whose course_cfg row held the old key when the plan was made
     * @param string $fingerprint the old key's fingerprint
     * @param string $newstored the new key, concealed
     * @return int course rows changed
     */
    protected static function write_courses(array $courseids, string $fingerprint, string $newstored): int {
        global $DB;
        $changed = 0;
        $now = time();
        foreach ($courseids as $courseid) {
            $row = $DB->get_record(self::COURSE_TABLE, ['courseid' => (int) $courseid], 'id, apikey');
            if (!$row || !hash_equals($fingerprint, key_inventory::stored_fingerprint((string) $row->apikey))) {
                continue;
            }
            $DB->update_record(self::COURSE_TABLE, (object) ['id' => $row->id, 'apikey' => $newstored,
                'timemodified' => $now]);
            $changed++;
        }
        return $changed;
    }

    /**
     * Check the command's options before anything is read: a key must never arrive on the command line.
     *
     * Refused: any of KEY_OPTIONS; any unknown option or argument; an on/off option given a value other than
     * true or '1'; an --old-last4 that is not exactly four non-space characters; an --old-fingerprint that is not
     * exactly 12 hex characters; a --new-key-env that is not an environment variable NAME (an upper-case letter,
     * then upper-case letters, digits or underscores, 64 at most) or that starts with a key prefix. The message is
     * fixed text and never repeats a value.
     *
     * @param array $options from cli_get_params()
     * @param array $unrecognised from cli_get_params()
     * @return string why the command line is refused, or '' when it is fine
     */
    public static function command_line_problem(array $options, array $unrecognised): string {
        foreach (self::KEY_OPTIONS as $name) {
            if (($options[$name] ?? null) !== null) {
                return 'A key is never taken on the command line: it would be saved in your shell history and shown '
                    . 'in the process list. Nothing was read or changed. If that was a real key, treat it as '
                    . 'exposed. Put the new key in an environment variable (--new-key-env=NAME, default '
                    . 'SOLA_NEW_KEY) or pipe it in with --stdin.';
            }
        }
        if ($unrecognised) {
            return 'Unknown option or argument (not shown here, in case it was a key). Nothing was read or changed. '
                . 'Try --help.';
        }
        foreach (self::FLAGS as $name) {
            $value = $options[$name] ?? false;
            if ($value !== false && $value !== true && $value !== '1') {
                return '--' . $name . ' takes no value (not shown here, in case it was a key). Nothing was read or '
                    . 'changed.';
            }
        }
        $last4 = (string) ($options['old-last4'] ?? '');
        if ($last4 !== '' && !preg_match('~^\S{4}$~', $last4)) {
            return '--old-last4 takes exactly the last four characters of the old key (the value is not shown here, '
                . 'in case it was a key). Nothing was read or changed.';
        }
        $fingerprint = (string) ($options['old-fingerprint'] ?? '');
        if ($fingerprint !== '' && !preg_match('~^[0-9a-fA-F]{12}$~', $fingerprint)) {
            return '--old-fingerprint takes the 12 hex characters --list shows (the value is not shown here, in case '
                . 'it was a key). Nothing was read or changed.';
        }
        $envname = (string) ($options['new-key-env'] ?? '');
        $prefixed = false;
        foreach (self::KEY_PREFIXES as $prefix) {
            $prefixed = $prefixed || str_starts_with($envname, $prefix);
        }
        if ($prefixed || !preg_match('~^[A-Z][A-Z0-9_]{0,63}$~', $envname)) {
            return '--new-key-env takes the NAME of an environment variable (upper-case letters, digits and '
                . 'underscores), not a key. The value is not shown here. Nothing was read or changed.';
        }
        return '';
    }

    /**
     * Pick the one group the selector names, or say why not.
     *
     * @param array $groups key_inventory::groups()
     * @param array $selector ['last4' => ...] or ['fingerprint' => ...]
     * @param array $result the result being built; errors and reason are set here on a refusal
     * @return array|null the group, or null
     */
    private static function select(array $groups, array $selector, array &$result): ?array {
        $last4 = trim((string) ($selector['last4'] ?? ''));
        $fingerprint = strtolower(trim((string) ($selector['fingerprint'] ?? '')));
        if (($last4 === '') === ($fingerprint === '')) {
            $result['reason'] = 'usage';
            $result['errors'][] = 'Name the old key by exactly one of its last four characters or its fingerprint.';
            return null;
        }
        if ($fingerprint !== '') {
            if (isset($groups[$fingerprint])) {
                return $groups[$fingerprint];
            }
            $result['reason'] = 'nomatch';
            $result['errors'][] = 'No stored key has that fingerprint.';
            return null;
        }
        $matches = array_values(array_filter($groups, fn($g) => $g['last4'] !== '' && $g['last4'] === $last4));
        if (count($matches) === 1) {
            return $matches[0];
        }
        if (!$matches) {
            $result['reason'] = 'nomatch';
            $result['errors'][] = 'No stored key ends in those four characters. Matching is case-sensitive and '
                . 'compares the last four characters only. Keys under 12 characters show no last four; use --list '
                . 'and select one by its fingerprint.';
            return null;
        }
        $result['reason'] = 'ambiguous';
        $lines = array_map(fn($g) => '  ' . $g['fingerprint'] . ' ends in ' . $g['last4']
            . ($g['vendor'] !== '' ? ' (' . $g['vendor'] . ')' : '') . ', ' . count($g['places']) . ' places',
            $matches);
        $result['errors'][] = count($matches) . ' different keys end in those four characters. Select one by its '
            . "fingerprint:\n" . implode("\n", $lines);
        return null;
    }

    /**
     * What is wrong with a new key, or '' when nothing is.
     *
     * @param string $new the trimmed new key
     * @return string a sentence that never quotes the key
     */
    private static function key_problem(string $new): string {
        if ($new === '') {
            return 'No new key was given.';
        }
        if (strlen($new) < self::MIN_LENGTH) {
            return 'The new key is shorter than ' . self::MIN_LENGTH . ' characters.';
        }
        if (strlen($new) > self::MAX_LENGTH) {
            return 'The new key is longer than ' . self::MAX_LENGTH . ' characters.';
        }
        if (strpos($new, '|') !== false || preg_match('~[\x00-\x1F\x7F\s]~', $new)) {
            return 'The new key holds a "|", a space, a tab, a line break or another control character, which a key '
                . 'never does.';
        }
        return '';
    }

    /**
     * Is this place's store forced in config.php (and so not ours to write)?
     *
     * @param array $place a key_inventory place
     * @return bool
     */
    private static function is_forced(array $place): bool {
        switch ($place['kind']) {
            case 'row':
                return self::forced_setting(provider_keys::SETTING);
            case 'voice':
                return self::forced_setting('voice_providers');
            case 'pasted':
            case 'mirror':
                return self::forced_setting($place['ref']);
            default:
                return false;
        }
    }

    /**
     * Is a setting of this plugin forced in config.php?
     *
     * @param string $name
     * @return bool
     */
    private static function forced_setting(string $name): bool {
        global $CFG;
        return array_key_exists($name, $CFG->forced_plugin_settings[self::PLUGIN] ?? []);
    }

    /**
     * A place as an error names it: kind and ref, never a key.
     *
     * @param array $place a key_inventory place
     * @return string
     */
    private static function name(array $place): string {
        return $place['kind'] . ' ' . $place['ref'];
    }
}
