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
 * v7.8.8 on-demand check of the failover backups.
 *
 * Sends one tiny real request to each chat backup, built the way a real
 * failover builds it, and prints the settings problems found without a network
 * call. Exit status is 1 when anything is wrong, so it can sit in a deploy
 * script.
 *
 * Usage:
 *   php local/ai_course_assistant/admin/cli/verify_failover.php
 *   php local/ai_course_assistant/admin/cli/verify_failover.php --settings-only
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
global $CFG;
require_once($CFG->dirroot . '/lib/clilib.php');

use local_ai_course_assistant\failover_verifier;

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'settings-only' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error('Unknown option(s): ' . implode(', ', $unrecognised) . '. Try --help.');
}
if ($options['help']) {
    cli_writeln("Checks the failover backups.\n\n  --settings-only   check the settings only, send no request\n  -h, --help        this help");
    exit(0);
}

$problems = failover_verifier::static_problems();
foreach ($problems as $line) {
    echo "SETTINGS: $line\n";
}
$failed = count($problems);

if (!$options['settings-only']) {
    foreach (failover_verifier::probe_all() as $r) {
        printf(
            "%s (%s): %s in %d ms%s\n",
            $r['label'],
            $r['model'],
            $r['ok'] ? 'ok' : 'FAILED',
            $r['ms'],
            $r['ok'] ? '' : ' - ' . $r['detail']
        );
        $failed += $r['ok'] ? 0 : 1;
    }
}
echo $failed === 0 ? "All good.\n" : "$failed problem(s).\n";
exit($failed === 0 ? 0 : 1);
