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
 * SOLA post-deploy health check CLI.
 *
 * Runs every check in {@see \local_ai_course_assistant\health_check::run_all()}
 * and reports per-check pass/fail. Designed to be wired into deploy
 * procedures as the last step — exit code 0 means the upgrade is healthy,
 * exit code 1 means at least one check failed and the deploy should be
 * investigated (or rolled back) before traffic resumes.
 *
 * Usage:
 *   php admin/cli/health_check.php
 *   php admin/cli/health_check.php --strict     # treat warnings as failures too
 *   php admin/cli/health_check.php --verbose    # one-line message per check, even passes
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');

use local_ai_course_assistant\health_check;

$strict = false;
$verbose = false;
foreach ($argv as $arg) {
    if ($arg === '--strict') {
        $strict = true;
    }
    if ($arg === '--verbose' || $arg === '-v') {
        $verbose = true;
    }
}

mtrace('SOLA Post-Deploy Health Check');
mtrace('=============================');
mtrace('');

$result = health_check::run_all();

foreach ($result['checks'] as $c) {
    $tag = strtoupper($c['status']);
    if ($c['status'] === health_check::STATUS_FAIL || $c['status'] === health_check::STATUS_WARN || $verbose) {
        mtrace(sprintf('  [%s] %s — %s', $tag, $c['name'], $c['message']));
    } else {
        mtrace(sprintf('  [%s] %s', $tag, $c['name']));
    }
}

mtrace('');
mtrace('=============================');
mtrace(sprintf('Result: %d passed, %d failed, %d warned (%d total)',
    $result['passed'], $result['failed'], $result['warned'], count($result['checks'])));
mtrace('=============================');

$exitcode = 0;
if ($result['failed'] > 0) {
    $exitcode = 1;
} else if ($strict && $result['warned'] > 0) {
    $exitcode = 1;
    mtrace('');
    mtrace('--strict was set: warnings count as failures.');
}
exit($exitcode);
