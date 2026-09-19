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
 * Guard: a docblock's param tags must match the function signature.
 *
 * The Moodle PHPDoc Checker gates CI on this and reports it as
 * "incomplete parameters list", with a file and a line and no further detail.
 * It cost four CI round trips across v7.3.5 and v7.4.0, in three different
 * shapes, none of which php -l or any other local check can see:
 *
 *  - A parameter simply not documented.
 *  - A parameter documented with a generic type -- 'array<string,string> $x'.
 *    The sniff cannot parse the generic, so it does not recognise the
 *    parameter as documented at all and reports the list as incomplete. Note
 *    this applies to param tags only; a generic on a return tag is fine and
 *    is used widely in this codebase.
 *  - The same parameter documented twice, which is what a careless bulk edit
 *    of the generic form above produces: the type collapses, the tag is
 *    duplicated, and prose ends up stranded between the tags.
 *
 * Deliberately narrow: it only compares tags against the signature, and only
 * where a docblock exists. It does not enforce descriptions or ordering,
 * because that would duplicate the sniff rather than front-run its most
 * expensive failure.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class phpdoc_param_consistency_test extends \basic_testcase {

    /**
     * Every documented function's param tags match its signature exactly.
     */
    public function test_param_tags_match_signatures(): void {
        $root = realpath(__DIR__ . '/..');
        $offenders = [];
        $checked = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace($root . '/', '', $file->getPathname());
            // Lang files hold no functions; vendored trees are not ours.
            if (preg_match('#^(lang|cdn/node_modules|node_modules|\.git)/#', $path)) {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            $re = '#(/\*\*(?:[^*]|\*(?!/))*\*/)\s*'
                . '(?:public\s+|private\s+|protected\s+|static\s+|final\s+|abstract\s+)*'
                . 'function\s+(\w+)\s*\(([^)]*)\)#';
            if (!preg_match_all($re, $src, $ms, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($ms as $m) {
                [$whole, $doc, $name, $sig] = $m;
                $line = substr_count(substr($src, 0, strpos($src, $whole)), "\n") + 1;

                // The Moodle PHPDoc Checker (moodlehq/moodle-local_moodlecheck)
                // is the authority here, not this test: it gates CI, and it
                // cannot parse a generic or an array shape on @param. Do not
                // relax this check to allow the modern annotation style --
                // that puts main straight back in the red.
                //
                // A generic type or array shape on a param tag reads as
                // undocumented to the Moodle sniff, so it must be flagged.
                // This check MUST run before the "no param tags" bail below:
                // the extraction regex there is '\S+\s+\$name', so a type
                // containing a space -- array<string, array{...}> -- yields no
                // match at all, $documented comes back empty, and the function
                // is skipped before ever reaching this check. That blind spot
                // is how the submit_batch() generic reached CI in v7.4.4.
                if (preg_match_all('/@param\s+\S*[<{][^$]*\$(\w+)/', $doc, $gm)) {
                    $offenders[] = sprintf('%s:%d %s() uses a generic type on @param $%s',
                        $path, $line, $name, implode(', $', $gm[1]));
                    continue;
                }

                preg_match_all('/@param\s+\S+\s+\$(\w+)/', $doc, $dm);
                $documented = $dm[1];
                if (!$documented) {
                    // No param tags at all: the sniff allows that shape, and
                    // enforcing it here would be a different, larger argument.
                    continue;
                }
                $checked++;
                preg_match_all('/\$(\w+)/', $sig, $sm);
                $actual = $sm[1];

                $dupes = array_keys(array_filter(array_count_values($documented), fn($n) => $n > 1));
                if ($dupes) {
                    $offenders[] = sprintf('%s:%d %s() documents $%s twice',
                        $path, $line, $name, implode(', $', $dupes));
                    continue;
                }
                if (array_diff($actual, $documented) || array_diff($documented, $actual)) {
                    $offenders[] = sprintf('%s:%d %s() doc(%s) vs signature(%s)',
                        $path, $line, $name,
                        implode(',', $documented) ?: '-', implode(',', $actual) ?: '-');
                }
            }
        }

        $this->assertGreaterThan(500, $checked, 'scanner found suspiciously few documented functions');
        $this->assertSame([], $offenders,
            "Docblock param tags do not match the signature. The Moodle PHPDoc Checker fails CI on these\n"
            . "as \"incomplete parameters list\". Remember that a generic type on a @param (array<int,string>)\n"
            . "reads as undocumented to the sniff -- use plain array and put the shape in the description:\n"
            . implode("\n", $offenders));
    }
    /**
     * No docblock is left stranded with another docblock immediately after it.
     *
     * This is a repeat defect, not a hypothetical. Inserting a new member
     * directly above an existing one puts the new member's docblock between the
     * existing docblock and the thing it documents. The first docblock now
     * describes nothing, and the member below it has none. It happened once with
     * a constant above detect_best_source() and reached main, and again in
     * v7.5.1 with normalise_name() above empty_result().
     *
     * test_param_tags_match_signatures() cannot see it: it matches each docblock
     * to whatever function follows it, and the ORPHAN is followed by a perfectly
     * well documented function, so the pairing looks correct. This checks the
     * shape instead, which is two docblocks with only whitespace between them.
     *
     * @return void
     */
    public function test_no_orphaned_docblocks(): void {
        $root = realpath(__DIR__ . '/..');
        $offenders = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace($root . '/', '', $file->getPathname());
            if (preg_match('#^(lang|cdn/node_modules|node_modules|\.git)/#', $path)) {
                continue;
            }
            $src = file_get_contents($file->getPathname());

            // A docblock, then only whitespace, then another docblock.
            $re = '#/\*\*(?:[^*]|\*(?!/))*\*/\s*/\*\*#';
            if (!preg_match_all($re, $src, $ms, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($ms[0] as $m) {
                $line = substr_count(substr($src, 0, $m[1]), "\n") + 1;
                $offenders[] = $path . ':' . $line;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A docblock is immediately followed by another docblock, so the first one documents nothing "
                . "and the member it belonged to now has no docblock. This usually means a new member was "
                . "inserted between an existing docblock and its function."
        );
    }

}
