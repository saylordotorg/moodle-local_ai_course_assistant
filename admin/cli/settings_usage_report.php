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
 * Report which of this site's SOLA settings are at their default and which were changed.
 *
 * Prints every plugin setting as one of default, changed, secret set, secret
 * unset or not stored, comparing the stored value to the registered default by
 * hash. It never prints a stored value or a secret, only names and counts. The
 * logic is classes/settings_usage.php; this is the thin wrapper.
 *
 * Usage:
 *   php local/ai_course_assistant/admin/cli/settings_usage_report.php
 *   php local/ai_course_assistant/admin/cli/settings_usage_report.php --per-course --tables
 *   php local/ai_course_assistant/admin/cli/settings_usage_report.php --json
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
global $CFG;
require_once($CFG->dirroot . '/lib/clilib.php');
require_once($CFG->libdir . '/adminlib.php');

use local_ai_course_assistant\settings_usage;

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'json' => false, 'per-course' => false, 'tables' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error('Unknown option(s): ' . implode(', ', $unrecognised) . '. Try --help.');
}
if ($options['help']) {
    cli_writeln(
        "Reports which SOLA settings are at their default and which were changed. "
        . "Never prints a stored value or a secret.\n\n"
        . "  (no options)   a summary, then one line per changed setting and per secret that is set\n"
        . "  --per-course   also count per-course override settings and per-course config rows\n"
        . "  --tables       also count the rows of every table the plugin owns\n"
        . "  --json         print the whole report as JSON instead of text\n"
        . "  -h, --help     this help\n\n"
        . "States: default, changed, secret set, secret unset, not stored."
    );
    exit(0);
}

// The settings pages register only for an administrator.
\core\session\manager::set_user(get_admin());

try {
    $report = settings_usage::report((bool) $options['per-course'], (bool) $options['tables']);
} catch (\Throwable $e) {
    cli_error('The report could not be built: ' . $e->getMessage());
}

if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    echo settings_usage::render_text($report);
}
exit(0);
