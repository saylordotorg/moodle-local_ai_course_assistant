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
     * The name filter runs in SQL and the rows stream: config_log on a site
     * upgraded over several years is large, and this runs from a CLI that may
     * have a modest memory limit. Pulling the whole table in one
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
            // allowlist -- so the authoritative decision still happens here.
            if (!self::is_secret_name((string) $row->name)) {
                continue;
            }
            foreach (['value', 'oldvalue'] as $column) {
                $candidate = (string) ($row->{$column} ?? '');
                if (!self::looks_exposed($candidate)) {
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
        if (in_array($name, self::NOT_SECRETS, true)) {
            return false;
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
     * Overwrite exposed values in place, preserving the audit trail.
     *
     * The row survives -- who changed which setting and when is exactly the
     * question the config log exists to answer, and deleting it to hide a
     * credential would destroy that. Only the value is replaced.
     *
     * Each exposed column is overwritten independently, so a row whose `value`
     * is a live key and whose `oldvalue` is the key it replaced loses both.
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
            $DB->set_field('config_log', $column, self::REDACTED, ['id' => $row['id']]);
            $count++;
        }

        return $count;
    }
}
