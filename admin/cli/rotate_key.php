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
 * Find an API key everywhere SOLA stores it, and replace it everywhere in one step.
 *
 * --list shows every stored key as a fingerprint and its last four characters, with the places that hold it.
 * Name the old key with --old-last4 or --old-fingerprint; the new key comes from an environment variable
 * (--new-key-env, default SOLA_NEW_KEY) or the first line of standard input (--stdin), never from the command
 * line. Without --apply it is a dry run. Prints only counts, places and last-four hints, never a key. The logic
 * is classes/key_rotation.php; this is the thin wrapper.
 *
 * Usage:
 *   php local/ai_course_assistant/admin/cli/rotate_key.php --list
 *   SOLA_NEW_KEY=... php local/ai_course_assistant/admin/cli/rotate_key.php --old-last4=ab12
 *   php local/ai_course_assistant/admin/cli/rotate_key.php --old-fingerprint=0123456789ab --stdin --apply
 *
 * Exit codes: 0 ok, 1 error or refused, 2 no stored key matches or more than one does.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
global $CFG;
require_once($CFG->dirroot . '/lib/clilib.php');

use local_ai_course_assistant\key_inventory;
use local_ai_course_assistant\key_rotation;

[$options, $unrecognised] = cli_get_params(
    [
        'help' => false,
        'list' => false,
        'old-last4' => '',
        'old-fingerprint' => '',
        'new-key-env' => 'SOLA_NEW_KEY',
        'stdin' => false,
        'apply' => false,
        // Recognised only so they can be refused: a key typed on the command line is kept in the shell history and
        // shown to every user of the machine in the process list.
        'new-key' => null,
        'newkey' => null,
        'key' => null,
        'apikey' => null,
        'old-key' => null,
    ],
    ['h' => 'help']
);

// Before anything is read: a key on the command line, or an option's value that could be one, is refused and
// never repeated back.
$problem = key_rotation::command_line_problem($options, $unrecognised);
if ($problem !== '') {
    cli_error($problem);
}
if ($options['help']) {
    cli_writeln(
        "Finds an API key everywhere SOLA stores it and replaces it everywhere in one step.\n"
        . "Prints counts, places and last-four hints only, never a key.\n\n"
        . "  --list                   every stored key: fingerprint, last four, vendor and the places holding it\n"
        . "  --old-last4=XXXX         the key to replace, by its last four characters\n"
        . "  --old-fingerprint=HEX    the key to replace, by its fingerprint (from --list)\n"
        . "  --new-key-env=NAME       read the new key from this environment variable (default SOLA_NEW_KEY)\n"
        . "  --stdin                  read the new key from the first line of standard input instead\n"
        . "  --apply                  make the change (without it, a dry run that changes nothing)\n"
        . "  -h, --help               this help\n\n"
        . "The new key is never accepted on the command line.\n"
        . "Exit codes: 0 ok, 1 error or refused, 2 no stored key matches or more than one does."
    );
    exit(0);
}

// Audit rows name the administrator who ran the rotation.
\core\session\manager::set_user(get_admin());

$plural = fn(int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
$counts = function (array $places): string {
    $bykind = [];
    foreach ($places as $place) {
        $bykind[$place['kind']] = ($bykind[$place['kind']] ?? 0) + 1;
    }
    $parts = [];
    foreach ($bykind as $kind => $n) {
        $parts[] = "$kind $n";
    }
    return implode(', ', $parts);
};
$placeline = function (array $place): string {
    return '    ' . $place['kind'] . ' ' . $place['ref']
        . ($place['course'] !== '' ? ' (' . $place['course'] . ')' : '')
        . ($place['provider'] !== '' ? ' [' . $place['provider'] . ']' : '')
        . (!empty($place['forced']) ? ' FORCED IN config.php' : '');
};
$groupline = function (array $group, array $places) use ($counts, $plural): string {
    return $group['fingerprint'] . '  ' . ($group['last4'] !== '' ? 'ends in ' . $group['last4'] : '(short key, no last four)')
        . ($group['vendor'] !== '' ? '  ' . $group['vendor'] : '') . '  '
        . $plural(count($places), 'place', 'places') . ': ' . $counts($places);
};

if ($options['list']) {
    $groups = key_inventory::groups();
    if (!$groups) {
        cli_writeln('No API key is stored in any place SOLA keeps one.');
        exit(0);
    }
    cli_writeln($plural(count($groups), 'key is', 'different keys are') . ' stored.');
    foreach ($groups as $group) {
        cli_writeln('');
        cli_writeln($groupline($group, $group['places']));
        foreach ($group['places'] as $place) {
            cli_writeln($placeline($place));
        }
    }
    exit(0);
}

$selector = array_filter([
    'last4' => trim((string) $options['old-last4']),
    'fingerprint' => trim((string) $options['old-fingerprint']),
], fn($value) => $value !== '');
if (!$selector) {
    cli_error('Name the key to replace with --old-last4 or --old-fingerprint, or use --list. Try --help.');
}

if ($options['stdin']) {
    $line = fgets(STDIN);
    $newkey = $line === false ? '' : $line;
} else {
    // command_line_problem() has checked that this is an environment variable name.
    $newkey = (string) getenv((string) $options['new-key-env']);
}
if (trim($newkey) === '') {
    cli_error('No new key: the environment variable (--new-key-env, default SOLA_NEW_KEY) is empty or unset, '
        . 'or --stdin read nothing.');
}

$plan = key_rotation::plan($selector, $newkey);
if ($plan['group'] !== null) {
    cli_writeln('Key ' . $groupline($plan['group'], $plan['places']));
    foreach ($plan['places'] as $place) {
        cli_writeln($placeline($place));
    }
}
if (!$plan['ok']) {
    unset($newkey);
    cli_error(implode("\n", $plan['errors']), in_array($plan['reason'], ['nomatch', 'ambiguous'], true) ? 2 : 1);
}

if (!$options['apply']) {
    unset($newkey);
    cli_writeln('');
    cli_writeln('Dry run: nothing was changed. Run again with --apply to replace the key in '
        . $plural(count($plan['places']), 'place', 'places') . '.');
    exit(0);
}

$result = key_rotation::apply($selector, $newkey);
unset($newkey);
if (!$result['ok']) {
    cli_error(implode("\n", $result['errors']), in_array($result['reason'], ['nomatch', 'ambiguous'], true) ? 2 : 1);
}
$done = [];
foreach ($result['counts'] as $kind => $n) {
    if ($n > 0) {
        $done[] = "$kind $n";
    }
}
cli_writeln('');
cli_writeln('Replaced the key in ' . $plural(array_sum($result['counts']), 'place', 'places') . ': '
    . implode(', ', $done) . '.');
cli_writeln('The new key ' . ($result['newlast4'] !== '' ? 'ends in ' . $result['newlast4'] : 'is stored') . '. '
    . 'Check that SOLA still answers, then revoke the old key at the vendor.');
exit(0);
