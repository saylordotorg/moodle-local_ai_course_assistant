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
 * CONTRIB-10574 #280: the listing copy must not claim more than version.php.
 *
 * The plugin-directory review found the Marketplace description promising
 * "Moodle 4.5 through Moodle 5.3 LTS" while version.php declared 4.5 to 5.2,
 * and asked that 5.3 be claimed only once it is released and tested. The
 * README was already pinned to version.php by
 * sessionless_endpoints_test::test_the_readme_names_the_version_being_shipped,
 * but the description copy, which is what gets pasted onto moodle.org, had no
 * guard at all. That is how it drifted in the first place.
 *
 * The listing itself lives on moodle.org and is edited by hand, so this pins
 * the copy in .drafts/ that the next edit is pasted from.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class listing_support_claims_test extends \basic_testcase {

    /**
     * The newest Marketplace description names no Moodle newer than version.php declares.
     */
    public function test_the_listing_copy_claims_no_more_than_version_php(): void {
        $root = realpath(__DIR__ . '/..');

        $this->assertSame(1, preg_match('/\$plugin->supported\s*=\s*\[\s*\d+\s*,\s*(\d)(\d)(\d)\s*\]/',
            file_get_contents($root . '/version.php'), $m), 'version.php must declare a supported range');
        $maxmajor = (int) $m[1];
        $maxminor = (int) ($m[2] . $m[3]);

        $drafts = glob($root . '/.drafts/marketplace-description-v*.txt') ?: [];
        if (!$drafts) {
            $this->markTestSkipped('No .drafts/ Marketplace copy in this checkout.');
        }
        usort($drafts, fn($a, $b) => version_compare(self::ver($a), self::ver($b)));
        $latest = end($drafts);
        $copy = file_get_contents($latest);

        preg_match_all('/Moodle\s+(\d+)\.(\d+)/', $copy, $all, PREG_SET_ORDER);
        $this->assertNotEmpty($all, 'sanity: the description should name a Moodle version');

        $over = [];
        foreach ($all as [$whole, $major, $minor]) {
            if ((int) $major > $maxmajor || ((int) $major === $maxmajor && (int) $minor > $maxminor)) {
                $over[] = $whole;
            }
        }
        $this->assertSame([], array_values(array_unique($over)),
            basename($latest) . " claims Moodle versions beyond version.php's declared "
            . "{$maxmajor}.{$maxminor}. Claim a newer Moodle only once version.php declares it.");

        $this->assertStringContainsString("Moodle {$maxmajor}.{$maxminor}", $copy,
            basename($latest) . " should state the declared ceiling, Moodle {$maxmajor}.{$maxminor}.");
    }

    /**
     * Every place that states the Moodle range or the CI range says the same thing (8.4.1, Marketplace review).
     *
     * The reviewer of 8.4.0 found the README's Moodle Workplace section still saying CI covers 4.5, 5.0 and 5.1
     * after 5.3 joined the matrix. The CI list is read from .github/workflows/ci.yml and the declared range from
     * version.php; the README's Requires line, its Workplace section, the version.php comment and the newest
     * Marketplace description must each state both, in the one sentence shape
     * "declares support for Moodle 4.5 to 5.3; CI covers 4.5, 5.0, 5.1 and 5.3 (5.2 is declared but not tested)".
     */
    public function test_every_range_statement_matches_version_php_and_ci(): void {
        $root = realpath(__DIR__ . '/..');
        $ci = $root . '/.github/workflows/ci.yml';
        if (!is_readable($ci)) {
            $this->markTestSkipped('No .github/workflows/ci.yml in this checkout.');
        }
        preg_match_all('/MOODLE_(\d)(\d\d)_STABLE/', file_get_contents($ci), $m, PREG_SET_ORDER);
        $tested = [];
        foreach ($m as [, $major, $minor]) {
            $tested[(int) $major * 100 + (int) $minor] = $major . '.' . (int) $minor;
        }
        ksort($tested);
        $this->assertNotEmpty($tested, 'sanity: ci.yml should name Moodle branches');

        $version = file_get_contents($root . '/version.php');
        $this->assertSame(1, preg_match('/\$plugin->supported\s*=\s*\[\s*(\d)(\d\d)\s*,\s*(\d)(\d\d)\s*\]/',
            $version, $s));
        $min = $s[1] . '.' . (int) $s[2];
        $max = $s[3] . '.' . (int) $s[4];
        $lo = (int) $s[1] * 100 + (int) $s[2];
        $hi = (int) $s[3] * 100 + (int) $s[4];
        // Moodle 4 ended at 4.5; Moodle 5 numbers from 5.0 up.
        $declared = [];
        for ($v = $lo; $v <= $hi; $v++) {
            $minor = $v % 100;
            if (($v >= 400 && $v < 500 && $minor <= 5) || ($v >= 500 && $minor <= 9)) {
                $declared[$v] = intdiv($v, 100) . '.' . $minor;
            }
        }
        $this->assertSame([], array_values(array_diff_key($tested, $declared)), 'CI tests a Moodle that version.php '
            . 'does not declare');
        $untested = array_values(array_diff_key($declared, $tested));

        $list = static function(array $items): string {
            $items = array_values($items);
            return count($items) < 2 ? implode('', $items)
                : implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items);
        };
        $ciphrase = 'CI covers ' . $list($tested);
        $statement = "declares support for Moodle {$min} to {$max}; {$ciphrase}"
            . ($untested ? ' (' . $list($untested) . (count($untested) > 1 ? ' are' : ' is')
                . ' declared but not tested)' : '');

        $readme = file_get_contents($root . '/README.md');
        $this->assertSame(1, preg_match('/^\*\*Requires:\*\*.*$/m', $readme, $requires));
        $this->assertStringContainsString($statement, $requires[0], 'README Requires line');

        $this->assertSame(1, preg_match('/^## Moodle Workplace$(.*?)^## /ms', $readme, $workplace),
            'README must keep its Moodle Workplace section');
        $this->assertStringContainsString('CI covers Moodle LMS only: ' . $list($tested) . ',', $workplace[1],
            'README Moodle Workplace section');

        $this->assertSame(1, preg_match('/\$plugin->supported\s*=.*$/m', $version, $line));
        $this->assertStringContainsString("Moodle {$min} to {$max}. {$ciphrase}", $line[0], 'version.php comment');

        $drafts = glob($root . '/.drafts/marketplace-description-v*.txt') ?: [];
        if ($drafts) {
            usort($drafts, fn($a, $b) => version_compare(self::ver($a), self::ver($b)));
            $copy = file_get_contents(end($drafts));
            $this->assertStringContainsString($statement, $copy, basename(end($drafts)));
            // No older wording of the range may survive beside it.
            $this->assertDoesNotMatchRegularExpression('/(declared|support) through Moodle|CI covers (?!'
                . preg_quote($list($tested), '/') . ')/', $copy, basename(end($drafts)));
        }
    }

    /**
     * Pull the release number out of a draft's filename.
     *
     * @param string $path
     * @return string
     */
    private static function ver(string $path): string {
        return preg_match('/-v([\d.]+)\.txt$/', $path, $m) ? $m[1] : '0';
    }
}
