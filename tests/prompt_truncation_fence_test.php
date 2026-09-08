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
 * Truncation must not leave an untrusted fence hanging open.
 *
 * security::fence_untrusted() wraps course text in markers telling the model the
 * enclosed text is reference material and must never be followed as
 * instructions. The assembler truncates with a byte-wise substr, which lands
 * mid-fence and discards the closing marker -- so every section after it in the
 * prompt, persona and safety block included, reads as though it were inside the
 * untrusted region.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\prompt\builder
 */
final class prompt_truncation_fence_test extends \basic_testcase {

    /**
     * Reach the private truncation helper.
     *
     * @param string $content
     * @param int $len
     * @return string
     */
    private function truncate(string $content, int $len): string {
        $m = new \ReflectionMethod(\local_ai_course_assistant\prompt\builder::class, 'truncate_content');
        $m->setAccessible(true);
        return $m->invoke(null, $content, $len);
    }

    public function test_every_cut_point_leaves_the_fence_balanced(): void {
        $one = security::fence_untrusted(str_repeat('Sentence about photosynthesis. ', 60), 'retrieved passage');
        $two = $one . "\n\n---\n\n"
            . security::fence_untrusted(str_repeat('Second passage text. ', 60), 'retrieved passage');

        foreach (['single' => $one, 'two fences' => $two] as $label => $text) {
            for ($n = 40; $n <= strlen($text) + 200; $n += 37) {
                $cut = $this->truncate($text, $n);
                $opens = preg_match_all('/\[\[UNTRUSTED /', $cut);
                $closes = substr_count($cut, '[[/UNTRUSTED');
                $this->assertSame(
                    $opens,
                    $closes,
                    "{$label}: cutting at {$n} left {$opens} open fence(s) and {$closes} close(s)."
                );
                $this->assertDoesNotMatchRegularExpression(
                    '/\[\[[^\]]*$/',
                    $cut,
                    "{$label}: cutting at {$n} left a dangling partial marker."
                );
            }
        }
    }

    public function test_unfenced_content_is_untouched_apart_from_the_notice(): void {
        $plain = str_repeat('Plain instruction text. ', 40);
        $cut = $this->truncate($plain, 200);

        $this->assertStringContainsString('truncated by prompt budget', $cut);
        $this->assertStringNotContainsString('UNTRUSTED', $cut);
        $this->assertStringStartsWith(substr($plain, 0, 100), $cut);
    }
}
