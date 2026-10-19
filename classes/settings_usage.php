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
 * Which settings a site has changed, never what it changed them to (7.10.0).
 *
 * The settings menu was simplified from a settings audit that hashed every
 * stored value and compared it to the default settings.php ships. This turns
 * that into a tool any admin can run on any site: every plugin setting in the
 * live admin tree is reported as one of
 *
 *  - default: stored, and equal to the registered default;
 *  - changed: stored, and different;
 *  - secret set / secret unset: a key or token (secrets::names() and every
 *    encrypted-password field), reported as present or absent and nothing else;
 *  - not stored: no config_plugins row (the default applies).
 *
 * `default` means the site's own default: a value local/defaults.php gives
 * the site counts, because the registered default honours it. A value forced
 * in config.php with no stored row reads `not stored` (the report reads the
 * database, not the forced value), while a forced secret reads `secret set`
 * (secrets::get() sees the forced value).
 *
 * A value is never returned, printed or logged. A stored value is compared to
 * the default by the md5 of each, in this process, and only the verdict leaves
 * the comparison. A secret's stored text is not read at all except through
 * secrets::get(), which only decides whether it is empty. The per-course and
 * table counts are COUNT queries and setting NAMES; no value column is read.
 *
 * The walk reads the live admin tree (admin_get_root()), not the layout map,
 * so it reports whatever settings.php registers today, wherever it puts them.
 * It cannot reuse tests/fixtures/settings_tree.php: tests are not part of what
 * ships, and this runs on production sites.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_usage {
    /** @var string The plugin component, and the config_plugins plugin column. */
    private const PLUGIN = 'local_ai_course_assistant';

    /** @var string The per-course AI configuration table. */
    private const COURSE_TABLE = 'local_ai_course_assistant_course_cfg';

    /** @var string State: stored and equal to the registered default. */
    public const STATE_DEFAULT = 'default';

    /** @var string State: stored and different from the registered default. */
    public const STATE_CHANGED = 'changed';

    /** @var string State: a secret that holds a value. */
    public const STATE_SECRET_SET = 'secret set';

    /** @var string State: a secret that is absent or empty. */
    public const STATE_SECRET_UNSET = 'secret unset';

    /** @var string State: no config_plugins row, so the default applies. */
    public const STATE_NOT_STORED = 'not stored';

    /**
     * Report how this site uses the plugin's settings.
     *
     * Returns ['settings' => name => state, 'summary' => counts per state,
     * 'percourse' => per-course counts or null, 'tables' => table => rows or
     * null]. The current user must be able to see the plugin's settings pages
     * (an administrator); the CLI signs in as one. Nothing is printed and no
     * developer notice escapes, so a CLI run's --json output stays valid even
     * when a stored secret can't be decrypted (it reads as secret unset).
     *
     * @param bool $percourse Also count per-course override settings and per-course config rows.
     * @param bool $tables Also count the rows of every table the plugin owns.
     * @return array The report.
     */
    public static function report(bool $percourse = false, bool $tables = false): array {
        global $DB;
        $registered = self::registered_settings();
        $secrets = secrets::names();

        // Never load a secret's stored text, even though only its presence is used.
        [$notsecret, $params] = $DB->get_in_or_equal($secrets, SQL_PARAMS_NAMED, 'sec', false);
        $params['plugin'] = self::PLUGIN;
        $stored = $DB->get_records_select_menu('config_plugins', "plugin = :plugin AND name $notsecret",
            $params, '', 'name, value');

        $secretset = self::secrets_set(array_keys(array_filter($registered, fn($info) => $info['secret'])));
        $settings = [];
        foreach ($registered as $name => $info) {
            if ($info['secret']) {
                $settings[$name] = $secretset[$name] ? self::STATE_SECRET_SET : self::STATE_SECRET_UNSET;
            } else {
                $settings[$name] = self::status_of(isset($stored[$name]) ? (string) $stored[$name] : null, $info['default']);
            }
        }
        ksort($settings);

        $counts = array_count_values($settings);
        return [
            'settings' => $settings,
            'summary' => [
                'default' => $counts[self::STATE_DEFAULT] ?? 0,
                'changed' => $counts[self::STATE_CHANGED] ?? 0,
                'secret_set' => $counts[self::STATE_SECRET_SET] ?? 0,
                'secret_unset' => $counts[self::STATE_SECRET_UNSET] ?? 0,
                'not_stored' => $counts[self::STATE_NOT_STORED] ?? 0,
            ],
            'percourse' => $percourse ? self::percourse() : null,
            'tables' => $tables ? self::table_counts() : null,
        ];
    }

    /**
     * Whether each named secret holds a value, read with debugging held off.
     *
     * secrets::get() raises a developer debugging() notice for ciphertext this
     * site's key can't open (a database restored onto another site) and treats
     * it as unset. A CLI run echoes that notice, which would land in the middle
     * of --json output, so debugging is switched off for the loop and put back
     * exactly as it was, an exception included.
     *
     * @param string[] $names Secret setting names.
     * @return array Name => true when the secret holds a value, false when it is absent, empty or unreadable.
     */
    private static function secrets_set(array $names): array {
        global $CFG;
        $before = [];
        foreach (['debug', 'debugusers'] as $key) {
            $before[$key] = property_exists($CFG, $key) ? [$CFG->$key] : null;
        }
        $CFG->debug = 0;
        $CFG->debugusers = '';
        try {
            $set = [];
            foreach ($names as $name) {
                $set[$name] = secrets::get($name) !== '';
            }
        } finally {
            foreach ($before as $key => $was) {
                if ($was === null) {
                    unset($CFG->$key);
                } else {
                    $CFG->$key = $was[0];
                }
            }
        }
        return $set;
    }

    /**
     * Decide a non-secret setting's state from its stored value and its registered default.
     *
     * The two are compared by md5, and only the verdict is returned. Line
     * endings are normalised first: a browser posts a textarea with CRLF, so a
     * setting saved untouched would otherwise read as changed against the
     * LF default in settings.php. A list default (a multi-select or checkbox
     * group, stored comma-separated) compares as a set, because the stored
     * order follows the choices and the default's follows settings.php. A
     * keyed list default (Moodle's multicheckbox shape, ['a' => 1, 'b' => 0])
     * compares by the keys that are switched on.
     *
     * @param string|null $stored The stored value, or null when there is no config_plugins row.
     * @param mixed $default The default the setting registers: a scalar, null or a list.
     * @return string STATE_NOT_STORED, STATE_DEFAULT or STATE_CHANGED.
     */
    public static function status_of(?string $stored, $default): string {
        if ($stored === null) {
            return self::STATE_NOT_STORED;
        }
        $aslist = is_array($default);
        return hash_equals(md5(self::canonical($default, $aslist)), md5(self::canonical($stored, $aslist)))
            ? self::STATE_DEFAULT : self::STATE_CHANGED;
    }

    /**
     * Count the per-course overrides: dynamic settings and per-course config rows.
     *
     * Per-course settings are config_plugins rows named <base>_course_<id>
     * (for example rag_enabled_course_12). Only their names are read, grouped
     * by base name. The per-course config table is counted with COUNT queries;
     * its provider, model, key, URL and prompt columns are tested for being
     * empty and never selected.
     *
     * @return array overrides (base name => count, largest first), override_rows, courses (distinct course ids),
     *               config_rows (rows, enabled, provider, model, own_key, base_url, system_prompt, temperature, spend_cap).
     */
    public static function percourse(): array {
        global $DB;
        $like = $DB->sql_like('name', ':pattern');
        $names = $DB->get_fieldset_select('config_plugins', 'name', "plugin = :plugin AND $like",
            ['plugin' => self::PLUGIN, 'pattern' => '%' . $DB->sql_like_escape('_course_') . '%']);

        $overrides = [];
        $courses = [];
        foreach ($names as $name) {
            if (preg_match('~^(.+)_course_(\d+)$~', (string) $name, $m)) {
                $overrides[$m[1]] = ($overrides[$m[1]] ?? 0) + 1;
                $courses[$m[2]] = true;
            }
        }
        uksort($overrides, fn($a, $b) => [$overrides[$b], $a] <=> [$overrides[$a], $b]);

        return [
            'overrides' => $overrides,
            'override_rows' => array_sum($overrides),
            'courses' => count($courses),
            'config_rows' => self::course_config_counts(),
        ];
    }

    /**
     * Count the rows of every table the plugin owns, exactly.
     *
     * @return array Table name (without the site prefix) => COUNT(*), sorted by name.
     */
    public static function table_counts(): array {
        global $DB;
        $counts = [];
        foreach ($DB->get_tables() as $table) {
            if ($table === self::PLUGIN || strpos($table, self::PLUGIN . '_') === 0) {
                $counts[$table] = $DB->count_records($table);
            }
        }
        ksort($counts);
        return $counts;
    }

    /**
     * The report as plain text, for the CLI: a summary, then one line per setting worth a look.
     *
     * A line is printed for every changed setting and every secret that is set,
     * by name only. The per-course and table sections appear when the report
     * carries them. Nothing here can print a value, because the report holds none.
     *
     * @param array $report A report() result.
     * @return string The text, ending in a newline.
     */
    public static function render_text(array $report): string {
        $s = $report['summary'];
        $lines = [sprintf(
            '%d settings: %d default, %d changed, %d secret set, %d secret unset, %d not stored',
            count($report['settings']),
            $s['default'],
            $s['changed'],
            $s['secret_set'],
            $s['secret_unset'],
            $s['not_stored']
        )];
        $notable = [];
        foreach ($report['settings'] as $name => $state) {
            if ($state === self::STATE_CHANGED || $state === self::STATE_SECRET_SET) {
                $notable[] = sprintf('%-13s %s', $state, $name);
            }
        }
        if ($notable) {
            $lines[] = '';
            array_push($lines, ...$notable);
        }
        if ($report['percourse'] !== null) {
            array_push($lines, ...self::render_percourse($report['percourse']));
        }
        if ($report['tables'] !== null) {
            $lines[] = '';
            $lines[] = 'Table row counts:';
            array_push($lines, ...self::render_counts($report['tables']));
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Every plugin setting that holds a value: those in the live admin tree, plus the secrets the tree doesn't show.
     *
     * @return array Name => ['secret' => bool, 'default' => mixed], in no particular order.
     */
    private static function registered_settings(): array {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $category = admin_get_root(true, true)->locate(self::PLUGIN);
        if (!$category instanceof \admin_category) {
            throw new \coding_exception('The ' . self::PLUGIN . ' settings are not in the admin tree: '
                . 'the report has to run as an administrator.');
        }
        $found = [];
        self::walk($category, $found);
        // A declared secret with no field on any page (the apikey mirror) is still stored, and still worth a line.
        foreach (secrets::names() as $name) {
            $found[$name] = $found[$name] ?? ['secret' => true, 'default' => ''];
        }
        return $found;
    }

    /**
     * Collect the value settings below a category, nested categories included.
     *
     * @param \admin_category $category The category to walk.
     * @param array $found Name => ['secret' => bool, 'default' => mixed], filled in place.
     * @return void
     */
    private static function walk(\admin_category $category, array &$found): void {
        foreach ($category->children as $child) {
            if ($child instanceof \admin_category) {
                self::walk($child, $found);
            } else if ($child instanceof \admin_settingpage) {
                foreach ((array) $child->settings as $setting) {
                    self::note($setting, $found);
                }
            }
        }
    }

    /**
     * Record one setting if it stores a value of this plugin.
     *
     * Headings and descriptions store nothing and are skipped. A default is
     * not read from a secret field.
     *
     * @param \admin_setting $setting A setting from a settings page.
     * @param array $found Name => ['secret' => bool, 'default' => mixed], filled in place.
     * @return void
     */
    private static function note(\admin_setting $setting, array &$found): void {
        if ($setting instanceof \admin_setting_heading || $setting instanceof \admin_setting_description
                || $setting->plugin !== self::PLUGIN) {
            return;
        }
        $secret = secrets::is_secret($setting->name) || $setting instanceof \admin_setting_encryptedpassword;
        $found[$setting->name] = [
            'secret' => $secret,
            'default' => $secret ? '' : $setting->get_defaultsetting(),
        ];
    }

    /**
     * A value in the form the comparison hashes.
     *
     * @param mixed $value A scalar, null or a list.
     * @param bool $aslist Compare as a set of comma-separated members (order ignored, empty members dropped).
     * @return string The canonical text.
     */
    private static function canonical($value, bool $aslist): string {
        if ($aslist) {
            if (!is_array($value)) {
                $members = explode(',', (string) $value);
            } else if (array_is_list($value)) {
                $members = $value;
            } else {
                // Moodle's multicheckbox default is keyed, ['a' => 1, 'b' => 0]: the choices switched on are the
                // keys with a truthy value, and those are what the stored comma-separated list names.
                $members = array_keys(array_filter($value));
            }
            $members = array_values(array_filter(array_map('strval', $members), fn($m) => $m !== ''));
            sort($members);
            return implode(',', $members);
        }
        return str_replace(["\r\n", "\r"], "\n", (string) $value);
    }

    /**
     * Count the per-course config rows, and how many of them set each override.
     *
     * @return array rows, enabled, provider, model, own_key, base_url, system_prompt, temperature, spend_cap.
     */
    private static function course_config_counts(): array {
        global $DB;
        $table = self::COURSE_TABLE;
        $set = fn(string $field, bool $text = false) =>
            $DB->count_records_select($table, $DB->sql_isnotempty($table, $field, true, $text));
        return [
            'rows' => $DB->count_records($table),
            'enabled' => $DB->count_records_select($table, 'enabled <> 0'),
            'provider' => $set('provider'),
            'model' => $set('model'),
            'own_key' => $set('apikey', true),
            'base_url' => $set('apibaseurl'),
            'system_prompt' => $set('systemprompt', true),
            'temperature' => $DB->count_records_select($table, 'temperature IS NOT NULL'),
            'spend_cap' => $DB->count_records_select($table, 'spend_cap_monthly IS NOT NULL AND spend_cap_monthly > 0'),
        ];
    }

    /**
     * The per-course section of the text report.
     *
     * @param array $percourse A percourse() result.
     * @return string[] Lines, starting with a blank one.
     */
    private static function render_percourse(array $percourse): array {
        $lines = ['', sprintf('Per-course overrides: %d settings in %d courses',
            $percourse['override_rows'], $percourse['courses'])];
        array_push($lines, ...self::render_counts($percourse['overrides']));
        $c = $percourse['config_rows'];
        $lines[] = sprintf(
            'Per-course config table: %d %s (%d switched on; provider %d, model %d, own key %d, base URL %d, '
                . 'system prompt %d, temperature %d, spend cap %d)',
            $c['rows'],
            $c['rows'] === 1 ? 'row' : 'rows',
            $c['enabled'],
            $c['provider'],
            $c['model'],
            $c['own_key'],
            $c['base_url'],
            $c['system_prompt'],
            $c['temperature'],
            $c['spend_cap']
        );
        return $lines;
    }

    /**
     * Indented "name  count" lines with the names padded to one column.
     *
     * @param array $counts Name => integer count.
     * @return string[] Lines.
     */
    private static function render_counts(array $counts): array {
        $width = $counts ? max(array_map('strlen', array_map('strval', array_keys($counts)))) : 0;
        $lines = [];
        foreach ($counts as $name => $count) {
            $lines[] = sprintf('  %-' . $width . 's  %d', $name, $count);
        }
        return $lines;
    }
}
