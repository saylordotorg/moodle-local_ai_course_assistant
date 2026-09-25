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
 * No Mustache comment is cut short by a tag written inside it.
 *
 * A Mustache comment runs to the FIRST closing brace pair it meets, not to a
 * matching one. So a comment that quotes a tag inline, by way of explaining the
 * template, ends at that tag, and every remaining line of the comment is emitted
 * to the page as body text.
 *
 * This shipped. The help panel's comment named the str helper in tag form while
 * explaining why the str helper could not be used, and the result was that every
 * learner who opened the help panel read nine lines of internal engineering
 * notes, ending in a stray brace pair, above the actual help. It was in v7.4.1
 * through v7.5.3 and was found by a human looking at the panel, not by any test.
 *
 * Nothing else would have caught it. The mustache linter checks syntax and this
 * is syntactically valid; the comment simply ends earlier than its author meant.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class mustache_comments_test extends \basic_testcase {
    /**
     * Every comment in every template ends where its author intended.
     *
     * @return void
     */
    public function test_no_comment_is_truncated_by_a_tag_inside_it(): void {
        $root = dirname(__DIR__) . '/templates';
        $templates = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        $checked = 0;
        $broken = [];
        foreach ($templates as $file) {
            if ($file->getExtension() !== 'mustache') {
                continue;
            }
            $checked++;
            $source = file_get_contents($file->getPathname());
            $offset = 0;
            while (($start = strpos($source, '{{!', $offset)) !== false) {
                $end = strpos($source, '}}', $start);
                if ($end === false) {
                    $broken[] = basename($file->getPathname()) . ': unterminated comment';
                    break;
                }
                $body = substr($source, $start + 3, $end - $start - 3);
                if (strpos($body, '{{') !== false) {
                    $line = substr_count(substr($source, 0, $start), "\n") + 1;
                    $leak = trim(substr($source, $end + 2, 60));
                    $broken[] = basename($file->getPathname()) . ':' . $line
                        . ' leaks to the page starting "' . $leak . '"';
                }
                $offset = $end + 2;
            }
        }

        $this->assertGreaterThan(5, $checked, 'Found almost no templates, so the scan is broken.');
        $this->assertSame(
            [],
            $broken,
            'A Mustache comment ends at the FIRST closing brace pair, so a tag quoted inside one '
                . 'truncates it and the rest of the comment renders to the learner. Describe the '
                . 'tag in words instead of writing it.'
        );
    }
}
