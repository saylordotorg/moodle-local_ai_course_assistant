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
 * The node guards in tests/js are actually wired into CI.
 *
 * They sat in the repo running nowhere: not PHPUnit, not Behat, not
 * run_validators.php, not package.json. The most valuable assertion among them
 * is a built-bundle staleness check against amd/build/chat.min.js, and a
 * staleness check nothing runs is worse than none, because it reads as covered.
 *
 * This test pins the wiring, not the scripts. The scripts already guard
 * themselves; what nothing guarded was that anything invokes them.
 *
 * @package    local_ai_course_assistant
 * @coversNothing
 */
final class js_guards_ci_wiring_test extends \basic_testcase {

    /**
     * Every script in tests/js is reachable from the CI workflow.
     *
     * The job globs the directory rather than naming files, so this asserts the
     * glob still points at a non-empty directory and the job still exists.
     *
     * @return void
     */
    public function test_ci_runs_the_js_guards(): void {
        $root = dirname(__DIR__);
        $ci = $root . '/.github/workflows/ci.yml';
        $this->assertFileExists($ci, 'the CI workflow is missing');

        $yaml = file_get_contents($ci);
        $this->assertStringContainsString(
            'js-guards:',
            $yaml,
            'The js-guards job has been removed from ci.yml, so nothing runs tests/js/*.js again. '
                . 'The built-bundle staleness assertion in starter-page-ref-check.js is the one '
                . 'that matters: it only helps if something invokes it.'
        );
        $this->assertStringContainsString(
            'tests/js/*.js',
            $yaml,
            'the js-guards job no longer globs tests/js/*.js'
        );

        $scripts = glob($root . '/tests/js/*.js');
        $this->assertNotEmpty(
            $scripts,
            'tests/js is empty or gone, so the CI job globs nothing. The job fails loudly in that '
                . 'case by design, but this says so here rather than in a red pipeline.'
        );
    }

    /**
     * The guards are plain node, with nothing to install.
     *
     * The CI job deliberately has no dependency step. A script that grew an
     * import would pass locally and fail in CI with a module-not-found error
     * that looks like an infrastructure problem rather than a code change.
     *
     * @return void
     */
    public function test_the_guards_stay_dependency_free(): void {
        $offenders = [];
        foreach (glob(dirname(__DIR__) . '/tests/js/*.js') as $path) {
            $src = file_get_contents($path);
            // Bare require/import of anything that is not a node builtin.
            if (preg_match('/\b(?:require\(|from\s+)[\'"](?!node:|fs|path|assert|url|util)([^\'"]+)[\'"]/', $src, $m)) {
                $offenders[] = basename($path) . ' -> ' . $m[1];
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'A tests/js guard imports something outside node builtins. The CI job runs plain '
                . '`node <file>` with no install step, so this would fail in CI while passing '
                . 'locally. Either keep the guard dependency-free, or add an install step to the '
                . 'js-guards job in ci.yml.'
        );
    }
}
