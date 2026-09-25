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
 * Every endpoint that skips require_login() says so the same way, and the README says
 * which version it is.
 *
 * Both of these exist because of the Moodle plugin-directory review, and both are
 * checks a reviewer can run in seconds and we could not.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\sessionless_endpoints_test
 */
final class sessionless_endpoints_test extends \basic_testcase {
    /** @var string The suppression every sessionless endpoint must carry. */
    private const MARKER = 'phpcs:disable moodle.Files.RequireLogin.Missing';

    /**
     * Root PHP files that are not request entry points at all.
     *
     * @var string[]
     */
    private const NOT_AN_ENDPOINT = ['version.php', 'lib.php', 'settings.php'];

    /**
     * Files that intentionally do not authenticate a Moodle session.
     *
     * Every one of these is authenticated by something else before it does anything:
     * an HMAC-SHA256 token or a bearer key, compared with hash_equals(). Requiring a
     * login would break what each exists for, which is being reachable from an email
     * client or from a third-party service.
     *
     * @return string[] Absolute paths.
     */
    private function sessionless_endpoints(): array {
        $root = realpath(__DIR__ . '/..');
        $found = [];
        foreach (glob($root . '/*.php') as $path) {
            if (in_array(basename($path), self::NOT_AN_ENDPOINT, true)) {
                continue;
            }
            $source = file_get_contents($path);
            // A call, not a mention: several of these files discuss require_login()
            // in their header comment precisely because they do not call it.
            $calls = preg_match(
                '/^[^*\/]*\b(require_login|require_admin|admin_externalpage_setup)\s*\(/m',
                $source
            );
            if (!$calls) {
                $found[] = $path;
            }
        }
        return $found;
    }

    /**
     * One marker, used everywhere, so the convention is checkable rather than described.
     *
     * We told the plugin-directory reviewer that all five sessionless endpoints declared
     * their intent in a machine-readable form, and offered to standardise on one marker
     * if they preferred. Twice we described the split between the two markers from
     * memory and twice we got it wrong, because the answer was a grep away and we
     * reasoned about the code instead of reading it. Two conventions is the underlying
     * problem; this test is the fix, and the fix is the kind that cannot go stale.
     *
     * @return void
     */
    public function test_every_sessionless_endpoint_carries_the_same_marker(): void {
        $endpoints = $this->sessionless_endpoints();
        $this->assertNotEmpty($endpoints, 'The scan found nothing, so it is not scanning.');

        $missing = [];
        foreach ($endpoints as $path) {
            if (strpos(file_get_contents($path), self::MARKER) === false) {
                $missing[] = basename($path);
            }
        }

        sort($missing);
        $this->assertSame(
            [],
            $missing,
            'These skip require_login() without declaring it. Add "// ' . self::MARKER . '" '
                . 'above the first define(), with a comment saying what authenticates the '
                . 'caller instead. NO_MOODLE_COOKIES is a different statement about a '
                . 'different thing and does not substitute for it.'
        );
    }

    /**
     * NO_MOODLE_COOKIES is about cookies, and its VALUE is the whole statement.
     *
     * The specific mistake this pins: reading the presence of the define as "this
     * endpoint is sessionless". Two of these files declare it FALSE, which says the
     * opposite, and counting the token rather than its value is how a description of
     * this code reached a reviewer twice in a form they could disprove with one grep.
     *
     * @return void
     */
    public function test_the_cookie_declaration_is_read_by_value_not_by_presence(): void {
        $expected = [
            'digest_unsubscribe.php' => 'false',
            'email_unsubscribe.php' => 'false',
            'redash_export.php' => 'true',
            'spend_export.php' => 'true',
            'talking_avatar_webhook.php' => 'true',
        ];

        $actual = [];
        foreach (glob(realpath(__DIR__ . '/..') . '/*.php') as $path) {
            $source = file_get_contents($path);
            if (preg_match("/define\('NO_MOODLE_COOKIES',\s*(true|false)\)/", $source, $m)) {
                $actual[basename($path)] = $m[1];
            }
        }
        ksort($actual);

        $this->assertSame(
            $expected,
            $actual,
            'The map of which endpoints suppress cookies, and which explicitly keep them, '
                . 'has changed. That is allowed, but it is a statement we have made to the '
                . 'plugin-directory reviewer in writing, so update this list in the same '
                . 'commit and say so if the review is still open.'
        );
    }

    /**
     * The packaged README names the version actually being packaged.
     *
     * A direct recommendation from the plugin-directory review of 2026-08-26: "Update
     * the packaged README before resubmission so it accurately documents the submitted
     * release". It was fixed once and drifted again by four releases, which is what
     * happens to a fact kept in two places and checked in neither.
     *
     * @return void
     */
    public function test_the_readme_names_the_version_being_shipped(): void {
        $root = realpath(__DIR__ . '/..');
        $version = file_get_contents($root . '/version.php');
        $readme = file_get_contents($root . '/README.md');

        $this->assertSame(
            1,
            preg_match("/\\\$plugin->release\s*=\s*'([^']+)'/", $version, $release),
            'version.php must declare a release.'
        );
        $this->assertSame(
            1,
            preg_match('/\$plugin->version\s*=\s*(\d+)/', $version, $build),
            'version.php must declare a version.'
        );

        $this->assertSame(
            1,
            preg_match('/^## Version (.+)$/m', $readme, $readmerelease),
            'README.md must carry a "## Version X" heading.'
        );
        $this->assertSame(
            1,
            preg_match('/^\*\*Plugin build:\*\* (\d+)$/m', $readme, $readmebuild),
            'README.md must carry a "**Plugin build:** N" line.'
        );

        $this->assertSame(
            $release[1],
            trim($readmerelease[1]),
            'README.md names a different release from version.php. Update it in the release '
                . 'commit: the packaged README is what a reviewer reads first.'
        );
        $this->assertSame(
            $build[1],
            $readmebuild[1],
            'README.md names a different build from version.php.'
        );
    }
}
