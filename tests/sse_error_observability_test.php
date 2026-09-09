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

/**
 * Guard: every sse.php error handler must record the underlying cause.
 *
 * A chat failure shows the learner a deliberately generic string. The audit
 * row is the only place the real cause survives, and it exists precisely so a
 * production site -- debug off, as it should be -- can still be diagnosed.
 *
 * sse.php has two outer handlers. The `catch (\Throwable)` one recorded
 * `$e->debuginfo` into the audit entry; the `catch (\moodle_exception)` one did
 * not. That is the handler every provider failure actually takes, because
 * base_provider throws moodle_exception with the cause in debuginfo. So the
 * remediation for the 2026-08 incident -- ten courses failing for nine days
 * behind an identical "Sorry, something went wrong", the cause finally found by
 * hand with curl -- was live only for the exception class provider errors do
 * not use.
 *
 * Found on staging at v7.4.1: chat failed on one course, the audit row read
 * `msg=Sorry, something went wrong. Please try again.` with no detail field,
 * and the cause was indistinguishable between a dead key, a spend cap, an
 * unknown model and a network timeout -- the four candidates the code's own
 * comment lists.
 *
 * This is a structural check, deliberately: the behaviour lives in a page
 * script rather than a class, and the defect was an asymmetry between two
 * handlers, which is exactly what a structural check catches.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sse_error_observability_test extends \basic_testcase {

    /**
     * Every audit_logger::log call in an sse.php catch block records debuginfo.
     */
    public function test_every_sse_error_handler_records_the_cause(): void {
        $src = file_get_contents(__DIR__ . '/../sse.php');
        $this->assertNotEmpty($src, 'sse.php unreadable');

        // Split on the OUTER catch blocks -- those at column 0, which are the
        // page-level handlers rather than the many inner per-step try/catches.
        $parts = preg_split('/^\} catch \(/m', $src);
        $handlers = [];
        foreach (array_slice($parts, 1) as $part) {
            if (strpos($part, 'audit_logger::log') !== false) {
                $handlers[] = $part;
            }
        }
        $this->assertGreaterThanOrEqual(2, count($handlers),
            'expected at least two outer sse.php handlers that write an audit row; '
            . 'if sse.php was restructured, update this guard rather than deleting it');

        $silent = [];
        foreach ($handlers as $i => $h) {
            // Only inspect up to the end of this handler's audit call.
            $upto = substr($h, 0, strpos($h, 'audit_logger::log') + 400);
            $caught = trim(strtok($h, ')'));
            // The invariant is that a 'detail' key REACHES THE AUDIT ENTRY --
            // not merely that debuginfo is mentioned somewhere in the handler.
            // The first draft of this guard checked the latter and was
            // therefore vacuous: the broken handler referenced debuginfo when
            // appending to the on-screen message for developer-debug admins,
            // and never put it in the audit row. It passed on the defect.
            if (strpos($upto, "'detail'") === false) {
                $silent[] = sprintf(
                    'handler #%d (catch %s) writes an audit row with no detail key',
                    $i + 1, $caught);
            }
        }
        $this->assertSame([], $silent,
            "An sse.php error handler records an audit row without the underlying cause.\n"
            . "The learner-facing message is generic by design, so the audit detail is the only\n"
            . "place the real reason survives on a site running with debug off:\n"
            . implode("\n", $silent));
    }
}
