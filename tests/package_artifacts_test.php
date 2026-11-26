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
 * The package carries no editor or tool artifacts (8.4.1, Marketplace review).
 *
 * 8.4.0 shipped README.md-E, the backup a BSD `sed -i -E` writes. The zip script excludes and refuses such names;
 * this test fails the build when one is in the plugin tree at all (in CI that is exactly what git tracks).
 *
 * @package    local_ai_course_assistant
 * @category   test
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class package_artifacts_test extends \basic_testcase {

    /** @var string Names that are never part of the plugin. Kept in step with create_fixed_zip.sh. */
    public const ARTIFACT = '/(-E|\.orig|\.rej|\.bak|~|\.swp|\.DS_Store)$/';

    /**
     * Files in the plugin tree that look like editor or tool artifacts.
     *
     * @param string $root
     * @return string[] relative paths
     */
    public static function artifacts(string $root): array {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $f): bool => !in_array($f->getFilename(), ['.git', 'node_modules', '.claude', '.wiki'],
                true)
        ));
        foreach ($it as $file) {
            $rel = substr($file->getPathname(), strlen($root) + 1);
            if (preg_match(self::ARTIFACT, $rel)) {
                $out[] = $rel;
            }
        }
        sort($out);
        return $out;
    }

    public function test_the_plugin_tree_has_no_artifacts(): void {
        $this->assertSame([], self::artifacts(dirname(__DIR__)));
    }

    public function test_the_check_sees_each_kind_of_artifact(): void {
        $dir = make_request_directory();
        mkdir($dir . '/sub');
        foreach (['README.md-E', 'sub/a.php.orig', 'b.rej', 'c.bak', 'd.php~', '.e.swp', 'sub/.DS_Store', 'ok.php',
                'README.md'] as $name) {
            file_put_contents($dir . '/' . $name, 'x');
        }
        $this->assertSame(['.e.swp', 'README.md-E', 'b.rej', 'c.bak', 'd.php~', 'sub/.DS_Store', 'sub/a.php.orig'],
            self::artifacts($dir));
    }

    public function test_the_zip_script_excludes_and_refuses_the_same_names(): void {
        $script = file_get_contents(dirname(__DIR__) . '/create_fixed_zip.sh');
        foreach (['"*-E"', '"*.orig"', '"*.rej"', '"*.bak"', '"*~"', '"*.swp"', '"*/.DS_Store"'] as $exclude) {
            $this->assertStringContainsString('-x ' . $exclude, $script);
        }
        $this->assertStringContainsString('ABORTED: the zip contains editor or tool artifacts', $script);
    }
}
