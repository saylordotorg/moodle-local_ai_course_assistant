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
 * Byte-identical English must not ship as a "translation" (v7.3.3 guard).
 *
 * The parity test (lang_completeness_test) checks that KEYS exist, so a locale
 * file holding the untranslated English VALUE passes it forever. That is how
 * the 16 flashcards strings shipped as English in all 45 locales for months
 * (finding F32), invisible to CI.
 *
 * tests/fixtures/i18n_identical_debt.txt freezes the debt as of v7.3.3
 * (393 keys, mostly admin surfaces). This test fails in BOTH directions:
 *  - a key NOT on the list that is byte-identical in >= 40 locales is NEW
 *    drift and must be translated (or deliberately added to the list);
 *  - a key ON the list that is no longer identical has been translated and
 *    must be REMOVED from the list, so the list only ever shrinks.
 *
 * Keys of 15 characters or fewer are exempt: short strings ("OK", "RAG",
 * button words, proper nouns) are legitimately identical in many languages.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class i18n_translation_drift_test extends \basic_testcase {
    /** Locales where a key must be identical before it counts as untranslated. */
    private const THRESHOLD = 40;

    /** Values at or under this length are exempt (legitimately identical). */
    private const MIN_LEN = 16;

    /**
     * Parse a lang file without executing it (they die() outside Moodle).
     *
     * @param string $path
     * @return array<string, string>
     */
    private function parse(string $path): array {
        $out = [];
        $body = file_get_contents($path);
        if (preg_match_all(
            '/\$string\[\'((?:[^\'\\\\]|\\\\.)*)\'\]\s*=\s*(\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)");/',
            $body, $m, PREG_SET_ORDER
        )) {
            foreach ($m as $hit) {
                $out[$hit[1]] = ($hit[3] !== '' || !isset($hit[4])) ? $hit[3] : $hit[4];
            }
        }
        return $out;
    }

    public function test_untranslated_english_only_shrinks(): void {
        global $CFG;
        $root = $CFG->dirroot . '/local/ai_course_assistant';

        $debt = array_flip(array_filter(array_map('trim',
            file($root . '/tests/fixtures/i18n_identical_debt.txt'))));
        $this->assertNotEmpty($debt, 'debt fixture missing or empty; the scan is broken');

        $en = $this->parse($root . '/lang/en/local_ai_course_assistant.php');
        $this->assertGreaterThan(1000, count($en), 'en parse failed');

        $identicalcount = [];
        $locales = 0;
        foreach (scandir($root . '/lang') as $loc) {
            if ($loc === 'en' || $loc[0] === '.') {
                continue;
            }
            $p = $root . '/lang/' . $loc . '/local_ai_course_assistant.php';
            if (!is_file($p)) {
                continue;
            }
            $locales++;
            foreach ($this->parse($p) as $k => $v) {
                if (isset($en[$k]) && strlen($en[$k]) >= self::MIN_LEN && $v === $en[$k]) {
                    $identicalcount[$k] = ($identicalcount[$k] ?? 0) + 1;
                }
            }
        }
        $this->assertSame(45, $locales, 'expected 45 non-English locales');

        $newdrift = [];
        foreach ($identicalcount as $k => $c) {
            if ($c >= self::THRESHOLD && !isset($debt[$k])) {
                $newdrift[] = "$k (identical in $c locales)";
            }
        }
        $this->assertSame([], $newdrift,
            "NEW untranslated strings shipping as byte-identical English. Translate them, "
            . "or add them to tests/fixtures/i18n_identical_debt.txt as a deliberate act:\n  - "
            . implode("\n  - ", $newdrift));

        $paid = [];
        foreach (array_keys($debt) as $k) {
            if (($identicalcount[$k] ?? 0) < self::THRESHOLD) {
                $paid[] = $k;
            }
        }
        $this->assertSame([], $paid,
            "These debt-list keys are now translated -- remove them from "
            . "tests/fixtures/i18n_identical_debt.txt so the list only shrinks:\n  - "
            . implode("\n  - ", $paid));
    }
}
