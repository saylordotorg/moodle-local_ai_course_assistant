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
 * Finds credentials sitting in the clear in Moodle's config change log.
 *
 * Registering a setting as a password type masks it when an administrator
 * saves it through the settings form. It does nothing for a value written by
 * `set_config()` from a CLI script, an install step, or an upgrade step --
 * core logs those verbatim, and `/report/configlog` is readable by anyone who
 * can reach site administration.
 *
 * On the dev fleet in August 2026 a reviewer found one such row by grepping the
 * page for a key they already suspected. Auditing the table properly turned up
 * five, across four plugins, including one of this plugin's own settings. That
 * ratio is the argument for this class: the exposure is not hard to fix, it is
 * hard to notice.
 *
 * config_log stores two values per row and /report/configlog renders both:
 * `value` as New value and `oldvalue` as Original value. Scanning only `value`
 * misses every credential that has since been changed -- and makes rotation
 * actively harmful, because set_config() writes the key being retired into the
 * next row's `oldvalue`. An admin who follows this tool's own advice would mint
 * a fresh exposure of the key they had just rotated away from. Both columns are
 * scanned and both are overwritten.
 *
 * Two kinds of setting are covered besides the ones whose name sounds like a
 * credential. Every setting listed in secrets::names() is a secret whatever it is
 * called (an incoming-webhook URL, an access key id: neither has a "key", "token"
 * or "secret" word the name fragments could match). And the provider and voice
 * tables (comparison_providers, voice_providers) hold a key in their second column
 * on every line: before 7.9.0 that column held the key in the clear or as
 * ciphertext, and the admin form logged the whole table. A line whose key column
 * is empty, a run of asterisks (what the 7.9.0 editor logs), or a comment is fine;
 * anything else is exposed. Redacting such a row overwrites only the key column
 * of each line, so the history still shows which provider and model changed.
 *
 * This class never returns, prints, or logs a secret value. It reports where
 * the exposure is and, on request, overwrites it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config_log_audit {

    /** @var string Replacement written over an exposed value. */
    public const REDACTED = '********';

    /**
     * Setting-name fragments that indicate the value is a credential.
     *
     * Deliberately matched against the setting NAME only. Matching on the value
     * would mean reading and pattern-testing every secret in the table, which
     * is the thing this class exists to avoid doing more of than necessary.
     */
    private const SECRET_NAME_FRAGMENTS = [
        'apikey', 'api_key', 'secret', 'token', 'password', 'passwd',
        'privatekey', 'private_key', 'credential', 'clientsecret',
    ];

    /**
     * Setting names that match the fragments above but are not secrets.
     *
     * A public key is published on purpose; masking it in the log would hide a
     * change an administrator genuinely needs to be able to review.
     */
    private const NOT_SECRETS = [
        'policy_bundle_pubkey',
        'token_analytics_link',
        'backend_context_tokens',
        'max_tokens',
        'maxtokens',
        'tokenexpiry',
    ];

    /** @var string The plugin whose two tables are read as tables. */
    private const TABLE_PLUGIN = 'local_ai_course_assistant';

    /**
     * Settings of TABLE_PLUGIN that are tables of pipe-separated lines with a key in the second column:
     * provider|key|models|temperature|baseurl and provider|key|label|realtime_voice|tts_voice.
     */
    private const KEYED_TABLES = ['comparison_providers', 'voice_providers'];

    /**
     * Shortest value worth treating as an exposed credential.
     *
     * Below this a value is far more likely to be a flag, a count, or an
     * already-blanked field than a live key.
     */
    private const MIN_SECRET_LENGTH = 12;

    /**
     * Config-log rows holding a credential in the clear, in either column.
     *
     * The name filter (secret-looking fragments, listed secrets, the two
     * tables) runs in SQL and the rows stream: config_log on a site upgraded
     * over several years is large, and this runs from a CLI that may have a
     * modest memory limit. Pulling the whole table in one
     * get_records_sql -- values included -- was not a size anyone should have to
     * discover in production.
     *
     * @param int|null $since Only consider rows modified at or after this time.
     * @return array List of ['id', 'plugin', 'name', 'column', 'length',
     *               'timemodified']. No value is ever included.
     */
    public static function find_exposed(?int $since = null): array {
        global $DB;

        $params = [];
        $clauses = [];
        foreach (self::SECRET_NAME_FRAGMENTS as $i => $fragment) {
            // sql_like's third argument is $casesensitive; false is portable
            // across the DB drivers Moodle supports, unlike wrapping the column
            // in a LOWER() the DML layer does not expose.
            $clauses[] = $DB->sql_like('name', ':frag' . $i, false);
            $params['frag' . $i] = '%' . $DB->sql_like_escape($fragment) . '%';
        }
        // Settings listed as secrets whatever their name looks like, and the two tables. Like the fragments, this
        // is a superset: which plugin a table row belongs to is decided below, in is_keyed_table().
        [$insql, $inparams] = $DB->get_in_or_equal(secrets::names(), SQL_PARAMS_NAMED, 'secret');
        $clauses[] = 'name ' . $insql;
        $params += $inparams;
        [$tablesql, $tableparams] = $DB->get_in_or_equal(self::KEYED_TABLES, SQL_PARAMS_NAMED, 'tbl');
        $clauses[] = 'name ' . $tablesql;
        $params += $tableparams;
        $where = '(' . implode(' OR ', $clauses) . ')';
        if ($since !== null) {
            $where .= ' AND timemodified >= :since';
            $params['since'] = $since;
        }

        $exposed = [];
        $rs = $DB->get_recordset_select('config_log', $where, $params, 'id DESC',
            'id, plugin, name, value, oldvalue, timemodified');
        foreach ($rs as $row) {
            // The SQL filter is a superset -- it cannot express the NOT_SECRETS
            // allowlist or the plugin a table belongs to -- so the authoritative
            // decision still happens here.
            $istable = self::is_keyed_table((string) $row->plugin, (string) $row->name);
            if (!$istable && !self::is_secret_name((string) $row->name)) {
                continue;
            }
            foreach (['value', 'oldvalue'] as $column) {
                $candidate = (string) ($row->{$column} ?? '');
                if ($istable ? !self::table_holds_a_key($candidate) : !self::looks_exposed($candidate)) {
                    continue;
                }
                $exposed[] = [
                    'id' => (int) $row->id,
                    'plugin' => (string) $row->plugin,
                    'name' => (string) $row->name,
                    'column' => $column,
                    'length' => strlen($candidate),
                    'timemodified' => (int) $row->timemodified,
                ];
            }
        }
        $rs->close();

        return $exposed;
    }

    /**
     * Whether a setting name denotes a credential.
     *
     * @param string $name
     * @return bool
     */
    public static function is_secret_name(string $name): bool {
        $name = strtolower($name);
        // First, so a public key or a token count stays visible whatever else says it is a secret.
        if (in_array($name, self::NOT_SECRETS, true)) {
            return false;
        }
        // Listed as a secret by the plugin itself, whatever its name looks like.
        if (secrets::is_secret($name)) {
            return true;
        }
        foreach (self::SECRET_NAME_FRAGMENTS as $fragment) {
            if (str_contains($name, $fragment)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a stored value is a credential in the clear rather than a mask.
     *
     * Core writes a run of asterisks when it masks, and blanks the field when a
     * setting is cleared. Anything else of credential length is exposed.
     *
     * @param string $value
     * @return bool
     */
    public static function looks_exposed(string $value): bool {
        if (strlen($value) < self::MIN_SECRET_LENGTH) {
            return false;
        }
        // A value made only of mask characters has already been redacted.
        return trim($value, '*') !== '';
    }

    /**
     * Whether a config_log row is one of the two key tables of this plugin.
     *
     * @param string $plugin
     * @param string $name
     * @return bool
     */
    public static function is_keyed_table(string $plugin, string $name): bool {
        return $plugin === self::TABLE_PLUGIN && in_array($name, self::KEYED_TABLES, true);
    }

    /**
     * Whether a value of one of the two key tables has a key on any line that isn't masked.
     *
     * One line per row, columns split by "|", the key in the second column. A comment line (first character
     * "#") and a blank line are skipped. An empty key (a keyless provider) is fine, and so is a run of
     * asterisks, which is what the 7.9.0 editor writes in its place. A key in the clear and a key as
     * ciphertext are both exposed, the same as for any other secret.
     *
     * @param string $value
     * @return bool
     */
    public static function table_holds_a_key(string $value): bool {
        foreach (preg_split('/\r?\n/', $value) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $key = trim(explode('|', $line)[1] ?? '');
            if ($key !== '' && trim($key, '*') !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * The value of one of the two key tables with the key column of every line replaced by the mask. Every
     * other column, comment lines, blank lines, a line already masked or keyless, and the line endings
     * are left exactly as they were.
     *
     * @param string $value
     * @return string
     */
    public static function mask_table_keys(string $value): string {
        // The delimiters are kept, so a value with Windows line endings keeps them.
        $pieces = preg_split('/(\r?\n)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($pieces as $i => $piece) {
            if ($i % 2 === 1 || trim($piece) === '' || ltrim($piece)[0] === '#') {
                continue;
            }
            $columns = explode('|', $piece);
            if (isset($columns[1]) && trim($columns[1]) !== '') {
                $columns[1] = self::REDACTED;
            }
            $pieces[$i] = implode('|', $columns);
        }
        return implode('', $pieces);
    }

    /**
     * What `--help` prints. Kept here, not in the script, so a test can read it.
     *
     * @return string
     */
    public static function help(): string {
        return "Report credentials left in the clear in Moodle's config change log.\n\n"
            . "Options:\n"
            . "  -h, --help     Show this help.\n"
            . "      --redact   Overwrite exposed values with asterisks, keeping the rows.\n"
            . "      --ids=A,B  Redact only these row ids (implies --redact).\n\n"
            . "Besides settings whose name sounds like a credential, every setting this plugin lists as a\n"
            . "secret is covered (webhook URLs, the Soapbox access key id, the spend export key), and so are\n"
            . "the two provider tables, comparison_providers and voice_providers, whose second column holds a\n"
            . "key. A row of those tables is reported when any of its lines has a key that is neither empty\n"
            . "nor asterisks, whether it is in the clear or encrypted. --redact overwrites only the key column\n"
            . "of each line with asterisks, so the provider, model and other columns stay in the history.\n"
            . "Every other setting is overwritten whole.\n\n"
            . "No key value is printed by this script under any option.\n";
    }

    /**
     * Overwrite exposed values in place, preserving the audit trail.
     *
     * The row survives -- who changed which setting and when is exactly the
     * question the config log exists to answer, and deleting it to hide a
     * credential would destroy that. Only the value is replaced.
     *
     * Each exposed column is overwritten independently, so a row whose `value`
     * is a live key and whose `oldvalue` is the key it replaced loses both. A row of
     * the provider or voice table loses only the key column of each line.
     *
     * @param array $ids Row ids to redact; empty means every exposed row.
     * @param array|null $targets Pre-computed find_exposed() result, to avoid
     *                            scanning the table twice.
     * @return int Number of column values overwritten.
     */
    public static function redact(array $ids = [], ?array $targets = null): int {
        global $DB;

        $targets = $targets ?? self::find_exposed();
        if (!empty($ids)) {
            $wanted = array_map('intval', $ids);
            $targets = array_filter($targets, function ($row) use ($wanted) {
                return in_array($row['id'], $wanted, true);
            });
        }

        $count = 0;
        foreach ($targets as $row) {
            // Column comes from this class's own fixed pair, never from input.
            $column = $row['column'] === 'oldvalue' ? 'oldvalue' : 'value';
            if (self::is_keyed_table((string) $row['plugin'], (string) $row['name'])) {
                // A table keeps its provider, model and other columns: only the key column is overwritten.
                $current = (string) $DB->get_field('config_log', $column, ['id' => $row['id']]);
                $DB->set_field('config_log', $column, self::mask_table_keys($current), ['id' => $row['id']]);
            } else {
                $DB->set_field('config_log', $column, self::REDACTED, ['id' => $row['id']]);
            }
            $count++;
        }

        return $count;
    }
}
