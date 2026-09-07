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
 * Guard: {$a} placeholders and [[brand tokens]] must survive translation.
 *
 * The completeness test next door proves every locale DEFINES every key. It
 * says nothing about what is inside the value, and two kinds of damage hide
 * there -- both of which shipped to learners before this guard existed:
 *
 *  - A dropped or mistyped placeholder. v7.3.5 found three: Bambara had
 *    '{$a>' and Somali '{$a>' where '{$a}' belonged, and Tagalog closed
 *    '{$a->title)' with a parenthesis. Moodle substitutes nothing, so the
 *    learner reads the raw fragment -- "Mɔgɔ {$a> bɛ kalan na sisan." Nobody
 *    smoke-tests in Bambara, so nobody saw it.
 *  - A dropped [[brand token]]. The plugin is white-labelable through four
 *    settings, and branding::apply() substitutes the tokens at the output
 *    boundary. A translation that drops the token cannot be rebranded: it
 *    either hard-codes one institution's product name in another language or
 *    loses the name entirely. The existing branding leak-guard checks the
 *    opposite direction -- that no UNRESOLVED token survives apply() -- so an
 *    ABSENT token was invisible to it.
 *
 * Placeholders are enforced absolutely: any mismatch fails. Brand tokens
 * carry 127 entries of pre-existing debt (26 keys, mostly stale translations
 * of English strings that predated the v6.8.0 tokenization), frozen in
 * tests/fixtures/i18n_brandtoken_debt.txt. That list may only shrink -- the
 * test fails on new drift AND on debt that was paid but not removed from the
 * fixture, so fixing a string is not complete until its line is deleted.
 *
 * Lang files are PARSED, never included: they die() when included outside
 * Moodle's string manager.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lang_placeholder_integrity_test extends \basic_testcase {

    /** @var string Absolute path of the plugin's lang directory. */
    private const LANGDIR = __DIR__ . '/../lang';

    /** @var string Fixture listing accepted brand-token debt. */
    private const DEBTFILE = __DIR__ . '/fixtures/i18n_brandtoken_debt.txt';

    /**
     * Parse a lang file into key => raw value.
     *
     * Matches the single-line $string[...] = '...'; form used throughout the
     * plugin, in both quote styles. Anything else is ignored rather than
     * guessed at.
     *
     * @param string $path
     * @return array<string, string>
     */
    private function parse_lang_file(string $path): array {
        $out = [];
        $src = file_get_contents($path);
        $re = '/^\$string\[\s*([\'"])(.+?)\1\s*\]\s*=\s*([\'"])(.*?)\3\s*;\s*$/ms';
        if (preg_match_all($re, $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $out[$hit[2]] = $hit[4];
            }
        }
        return $out;
    }

    /**
     * Every {$a} / {$a->name} placeholder in an English string.
     *
     * @param string $value
     * @return string[] sorted, unique
     */
    private function placeholders(string $value): array {
        preg_match_all('/\{\$a(?:->\w+)?\}/', $value, $m);
        $set = array_values(array_unique($m[0]));
        sort($set);
        return $set;
    }

    /**
     * Every [[token]] in a string.
     *
     * @param string $value
     * @return string[] sorted, unique
     */
    private function brand_tokens(string $value): array {
        preg_match_all('/\[\[\w+\]\]/', $value, $m);
        $set = array_values(array_unique($m[0]));
        sort($set);
        return $set;
    }

    /**
     * Locale codes present in the plugin, excluding English.
     *
     * @return string[]
     */
    private function locales(): array {
        $out = [];
        foreach (glob(self::LANGDIR . '/*/local_ai_course_assistant.php') as $path) {
            $loc = basename(dirname($path));
            if ($loc !== 'en') {
                $out[] = $loc;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * A translated string must carry exactly the placeholders English carries.
     *
     * No debt list: a broken placeholder is visible breakage, and all three
     * instances found at v7.3.5 were fixed in the same release.
     */
    public function test_placeholders_survive_translation(): void {
        $en = $this->parse_lang_file(self::LANGDIR . '/en/local_ai_course_assistant.php');
        $this->assertGreaterThan(2000, count($en), 'English lang file failed to parse');

        $broken = [];
        foreach ($this->locales() as $loc) {
            $tr = $this->parse_lang_file(self::LANGDIR . "/{$loc}/local_ai_course_assistant.php");
            foreach ($en as $key => $envalue) {
                $want = $this->placeholders($envalue);
                if (!$want || !array_key_exists($key, $tr)) {
                    continue;
                }
                $got = $this->placeholders($tr[$key]);
                if ($want !== $got) {
                    $broken[] = sprintf(
                        "%s %s: expected %s, found %s -- value: %s",
                        $loc,
                        $key,
                        implode(',', $want) ?: '(none)',
                        implode(',', $got) ?: '(none)',
                        \core_text::substr($tr[$key], 0, 90)
                    );
                }
            }
        }
        $this->assertSame([], $broken, "Placeholder damage in translated strings:\n" . implode("\n", $broken));
    }

    /**
     * Brand-token drift may not grow, and paid debt must leave the fixture.
     */
    public function test_brand_tokens_survive_translation(): void {
        $debt = [];
        foreach (file(self::DEBTFILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $debt[$line] = true;
        }
        $this->assertNotEmpty($debt, 'Debt fixture failed to load');

        $en = $this->parse_lang_file(self::LANGDIR . '/en/local_ai_course_assistant.php');
        $current = [];
        foreach ($this->locales() as $loc) {
            $tr = $this->parse_lang_file(self::LANGDIR . "/{$loc}/local_ai_course_assistant.php");
            foreach ($en as $key => $envalue) {
                $want = $this->brand_tokens($envalue);
                if (!$want || !array_key_exists($key, $tr)) {
                    continue;
                }
                if ($want !== $this->brand_tokens($tr[$key])) {
                    $current[$loc . "\t" . $key] = true;
                }
            }
        }

        $new = array_diff_key($current, $debt);
        $this->assertSame([], array_keys($new),
            "New brand-token drift. A translation dropped a [[token]] the English string carries, so these\n"
            . "strings cannot be rebranded. Fix the translation -- do not add to the debt fixture:\n"
            . implode("\n", array_keys($new)));

        $paid = array_diff_key($debt, $current);
        $this->assertSame([], array_keys($paid),
            "These entries are fixed but still listed in " . basename(self::DEBTFILE) . ".\n"
            . "Delete them so the list keeps shrinking:\n" . implode("\n", array_keys($paid)));
    }

    /**
     * A key may be defined only once per lang file.
     *
     * PHP silently keeps the LAST assignment, so a duplicate is not a syntax
     * error -- it is a trap. v7.3.5 removed 265 shadowed definitions across 36
     * locales, and 221 of them held text that DIFFERED from the winning copy:
     * mostly pre-tokenization literals like 'KI-Tutor' left behind when
     * v6.8.0 appended rebranded overrides instead of editing in place. Anyone
     * who found one of those by grep and corrected it would have seen no
     * change on screen, because the definition 200 lines below still won.
     */
    public function test_no_duplicate_key_definitions(): void {
        $offenders = [];
        foreach (glob(self::LANGDIR . '/*/local_ai_course_assistant.php') as $path) {
            $loc = basename(dirname($path));
            $src = file_get_contents($path);
            $re = '/^\$string\[\s*([\'"])(.+?)\1\s*\]\s*=/ms';
            preg_match_all($re, $src, $m);
            $counts = array_count_values($m[2]);
            foreach ($counts as $key => $n) {
                if ($n > 1) {
                    $offenders[] = "{$loc} {$key} (defined {$n} times)";
                }
            }
        }
        $this->assertSame([], $offenders,
            "Duplicate lang key definitions. PHP keeps the last one, so the earlier copies are\n"
            . "dead text that will mislead the next person who greps for the string. Delete the\n"
            . "shadowed definitions and edit the surviving one:\n" . implode("\n", $offenders));
    }
}
