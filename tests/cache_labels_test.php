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
 * Every cache definition has a label, and every label has a definition.
 *
 * Moodle builds the key at runtime as 'cachedef_' . $name, so the string never
 * appears literally in plugin code and test_every_referenced_key_is_defined
 * cannot see it. A definition without one renders as
 * [[cachedef_whatever]] on Site administration > Plugins > Caching >
 * Configuration, and logs a "string does not exist" notice with debugging on.
 *
 * Two of the eight were missing when this test was written. One was added in
 * this release and caught by a reviewer; the other, cachedef_vectors, had been
 * missing since v5.4.0 and nobody had looked at that page. This is the check
 * that would have found both, which is why it is a test and not a note.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class cache_labels_test extends \basic_testcase {
    /**
     * The definition names declared in db/caches.php.
     *
     * Read from the source rather than by including the file, because it expects
     * MOODLE_INTERNAL and a cache_store class. Top-level keys only: a definition
     * body also contains keys such as 'invalidationevents', at a deeper indent,
     * and counting those would invent definitions that do not exist.
     *
     * @return string[]
     */
    private function definitions(): array {
        $source = file_get_contents(dirname(__DIR__) . '/db/caches.php');
        $this->assertIsString($source);
        preg_match_all("/^    '([a-z0-9_]+)' => \\[/m", $source, $m);

        return $m[1];
    }

    /**
     * Each cache definition has its cachedef_ string in lang/en.
     *
     * @return void
     */
    public function test_every_cache_definition_has_a_label(): void {
        $definitions = $this->definitions();
        $this->assertGreaterThan(4, count($definitions), 'The scan found almost nothing, so it is broken.');

        $strings = file_get_contents(dirname(__DIR__) . '/lang/en/local_ai_course_assistant.php');
        $missing = [];
        foreach ($definitions as $name) {
            if (strpos($strings, "\$string['cachedef_{$name}']") === false) {
                $missing[] = $name;
            }
        }

        sort($missing);
        $this->assertSame(
            [],
            $missing,
            'Cache definitions with no cachedef_ string. The caching admin page will show the '
                . 'raw key in double brackets and log a missing-string notice. Add '
                . '$string[\'cachedef_<name>\'] to lang/en for each.'
        );
    }

    /**
     * And no label survives the definition it names.
     *
     * The other direction, because a stale label is a different kind of wrong: it
     * suggests a cache exists that does not, to anyone reading the lang file for
     * an inventory of what the plugin stores.
     *
     * @return void
     */
    public function test_every_label_has_a_definition(): void {
        $definitions = array_flip($this->definitions());
        $strings = file_get_contents(dirname(__DIR__) . '/lang/en/local_ai_course_assistant.php');
        preg_match_all("/\\\$string\\['cachedef_([a-z0-9_]+)'\\]/", $strings, $m);

        $orphans = [];
        foreach ($m[1] as $name) {
            if (!isset($definitions[$name])) {
                $orphans[] = $name;
            }
        }

        sort($orphans);
        $this->assertSame([], $orphans, 'cachedef_ strings naming a cache that db/caches.php does not define.');
    }
}
