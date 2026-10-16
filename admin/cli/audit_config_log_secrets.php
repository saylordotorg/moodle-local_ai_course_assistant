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

/**
 * Report, and optionally redact, credentials left in the clear in config_log.
 *
 * A setting registered as a password type is masked when saved through the
 * settings form, and not masked when written by a CLI script, an install step,
 * or an upgrade step. `/report/configlog` shows the result to anyone who can
 * reach site administration.
 *
 * The audit covers every plugin on the site, not just this one: the exposure is
 * a property of how the value was written, so a site that has this plugin
 * configured correctly can still be leaking another plugin's key.
 *
 * Both stored columns are covered. /report/configlog shows `value` as New value
 * and `oldvalue` as Original value, so a credential that has since been changed
 * or cleared is still on screen under the second one. That also means rotating a
 * key writes the retired key into the next row's `oldvalue` -- run this again
 * after any rotation.
 *
 * Settings are found by name: anything that sounds like a credential, and every
 * setting this plugin lists as a secret (webhook URLs, the Soapbox access key id,
 * the spend export key). The two provider tables, comparison_providers and
 * voice_providers, are read line by line: before 7.9.0 their second column held a
 * key in the clear or as ciphertext, and the admin form logged the whole table.
 * Redacting one of those rows overwrites only the key column of each line, so the
 * provider and model stay in the history; every other row is overwritten whole.
 *
 * No key value is ever printed. Rows are identified by plugin, setting name,
 * column, length, and date, which is enough to decide what to rotate.
 *
 * Usage:
 *   php admin/cli/audit_config_log_secrets.php
 *   php admin/cli/audit_config_log_secrets.php --redact
 *   php admin/cli/audit_config_log_secrets.php --redact --ids=4750,4731
 *
 * Redaction overwrites the value (the key column, for the two tables) and keeps
 * the row: who changed what, and when, is the question the config log exists to
 * answer. Nothing is overwritten unless you pass --redact or --ids.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'redact' => false,
        'ids' => '',
    ],
    ['h' => 'help']
);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if ($options['help']) {
    echo \local_ai_course_assistant\config_log_audit::help();
    exit(0);
}

$exposed = \local_ai_course_assistant\config_log_audit::find_exposed();

if (empty($exposed)) {
    cli_writeln('No credentials found in the clear in config_log.');
    exit(0);
}

cli_writeln(count($exposed) . ' credential(s) stored in the clear in config_log:');
cli_writeln('');
cli_writeln(sprintf('%-8s %-30s %-26s %-9s %-5s %s',
    'ID', 'PLUGIN', 'SETTING', 'COLUMN', 'LEN', 'WRITTEN'));

$plugins = [];
foreach ($exposed as $row) {
    cli_writeln(sprintf(
        '%-8d %-30s %-26s %-9s %-5d %s',
        $row['id'],
        $row['plugin'] !== '' ? $row['plugin'] : 'core',
        $row['name'],
        // "previous" is config_log.oldvalue, which /report/configlog renders as
        // Original value -- every bit as readable as the current one.
        $row['column'] === 'oldvalue' ? 'previous' : 'current',
        $row['length'],
        userdate($row['timemodified'], get_string('strftimedate', 'langconfig'))
    ));
    $plugins[$row['plugin'] !== '' ? $row['plugin'] : 'core'] = true;
}

cli_writeln('');

$ids = [];
if (trim((string) $options['ids']) !== '') {
    $ids = array_filter(array_map('intval', explode(',', (string) $options['ids'])));
}

if (!$options['redact'] && empty($ids)) {
    cli_writeln('Every value above is readable at /report/configlog by anyone who can');
    cli_writeln('reach site administration -- a "previous" row shows there under');
    cli_writeln('Original value, and is just as readable as a current one.');
    cli_writeln('Treat each one as disclosed and rotate it.');
    cli_writeln('Re-run with --redact to overwrite the stored values.');
    exit(1);
}

$count = \local_ai_course_assistant\config_log_audit::redact($ids, $exposed);
cli_writeln("Redacted {$count} value(s). The rows remain; only the values were overwritten.");
cli_writeln('');
cli_writeln('Redaction is not rotation. These keys were readable before this ran, so');
cli_writeln('rotate them at the vendor: ' . implode(', ', array_keys($plugins)) . '.');
cli_writeln('');
cli_writeln('Then run this again. Rotating through the settings form writes the key');
cli_writeln('you retired into the next log row, so the act of fixing this re-creates');
cli_writeln('the exposure one row further down.');
exit(0);
