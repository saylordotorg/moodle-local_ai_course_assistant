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
     * Pull the release number out of a draft's filename.
     *
     * @param string $path
     * @return string
     */
    private static function ver(string $path): string {
        return preg_match('/-v([\d.]+)\.txt$/', $path, $m) ? $m[1] : '0';
    }
}
