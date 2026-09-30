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
 * No CJK, Kana or Hangul in a locale that does not use them.
 *
 * v7.5.7 shipped a Bulgarian string reading `кажи го直ясно` -- a Han character
 * spliced into Cyrillic where a space belonged. It reached review because the
 * checks applied to that translation batch covered brand tokens, byte-identical
 * English and PHP syntax, and none of those notice a character from the wrong
 * script.
 *
 * It matters more than a typo. `support:promptrole` is not only shown on screen:
 * context_builder puts it into the SYSTEM PROMPT of every support turn in that
 * language, so a stray glyph goes to the model on every request.
 *
 * Scope is deliberately narrow. This does not try to validate that each locale
 * uses its own script -- Latin runs through most of them legitimately, in brand
 * names, URLs and loanwords. It asserts one thing: a locale that is not Chinese,
 * Japanese or Korean has no business containing Han, Kana or Hangul, and when it
 * does, a translation step hallucinated it.
 *
 * Indic punctuation is explicitly allowed everywhere. U+0964 DANDA lives in the
 * Devanagari block but is shared punctuation used by Bengali and Gurmukhi among
 * others, so a naive block test reports it as foreign in exactly the locales
 * where it is correct.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class lang_script_census_test extends \basic_testcase {

    /** Locales that legitimately contain Han, Kana or Hangul. */
    private const CJK_LOCALES = ['zh_cn', 'ja', 'ko'];

    /**
     * Parse a lang file without executing it (they die() outside Moodle).
     *
     * @param string $path
     * @return array<string, string>
     */
    private function parse(string $path): array {
        $out = [];
        $body = file_get_contents($path);
        if ($body === false) {
            return $out;
        }
        $pattern = '/\$string\[\'((?:[^\'\\\\]|\\\\.)*)\'\]\s*=\s*'
            . '(\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)");/';
        if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $hit) {
                $out[$hit[1]] = ($hit[3] !== '' || !isset($hit[4])) ? $hit[3] : $hit[4];
            }
        }
        return $out;
    }

    /**
     * Han, Kana and Hangul appear only in the three locales that use them.
     */
    public function test_no_cjk_characters_in_non_cjk_locales(): void {
        $langdir = __DIR__ . '/../lang';
        $locales = array_values(array_filter(
            scandir($langdir),
            static function ($d) use ($langdir): bool {
                return $d !== '.' && $d !== '..' && is_dir($langdir . '/' . $d);
            }
        ));
        $this->assertGreaterThan(40, count($locales), 'sanity: the lang directory should hold 46 locales');

        // Han, Hiragana, Katakana, Hangul syllables and Hangul jamo.
        $ranges = [
            [0x4E00, 0x9FFF],   // CJK unified ideographs.
            [0x3400, 0x4DBF],   // CJK extension A.
            [0x3040, 0x309F],   // Hiragana.
            [0x30A0, 0x30FF],   // Katakana.
            [0xAC00, 0xD7AF],   // Hangul syllables.
            [0x1100, 0x11FF],   // Hangul jamo.
            [0xF900, 0xFAFF],   // CJK compatibility ideographs.
        ];

        $offenders = [];
        foreach ($locales as $locale) {
            if (in_array($locale, self::CJK_LOCALES, true)) {
                continue;
            }
            $path = $langdir . '/' . $locale . '/local_ai_course_assistant.php';
            if (!is_readable($path)) {
                continue;
            }
            foreach ($this->parse($path) as $key => $value) {
                $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
                if ($chars === false) {
                    continue;
                }
                foreach ($chars as $char) {
                    $cp = \core_text::utf8ord($char);
                    foreach ($ranges as [$lo, $hi]) {
                        if ($cp >= $lo && $cp <= $hi) {
                            $offenders[] = sprintf(
                                '%s / %s: U+%04X (%s)',
                                $locale,
                                $key,
                                $cp,
                                $char
                            );
                            break 2;
                        }
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Han, Kana or Hangul found in a locale that does not use them. This is the\n"
                . "signature of a translation step hallucinating a character, and these\n"
                . "strings reach the model as well as the screen:\n  "
                . implode("\n  ", $offenders)
        );
    }
}
