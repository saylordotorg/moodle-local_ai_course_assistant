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
 * Guard: the generated help-panel translation bundle matches lang/.
 *
 * amd/src/i18n_help.js is generated from the plugin's own lang files by
 * scripts/build_help_i18n.php. It exists because the widget's language switch
 * is client-side and the server cannot supply a string in another language:
 * Moodle's PARAM_LANG rejects any language whose pack is not installed, and
 * these sites install only en and en_us (verified on staging, where
 * core_get_strings with lang 'es' returns "Invalid parameter value detected").
 *
 * A generated file's real hazard is going stale, so this asserts it is in step
 * with lang/ rather than merely well-formed. Editing a help: string without
 * regenerating would leave the panel showing the old text to every non-English
 * learner while the English page showed the new one -- the silent half-update
 * this whole class of bug keeps producing.
 *
 * Also pins the two-character keying. The lang directories are zh_cn and pt_br
 * but the runtime lookup truncates to two characters, so a table keyed by
 * directory name silently strands Chinese and Portuguese on English. The first
 * generated version did exactly that.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class help_i18n_bundle_test extends \basic_testcase {

    /** @var string Generated module. */
    private const BUNDLE = __DIR__ . '/../amd/src/i18n_help.js';

    /** @var string Lang directory. */
    private const LANGDIR = __DIR__ . '/../lang';

    /**
     * Extract the help: strings from a lang file without executing it.
     *
     * Lang files die() when included outside Moodle's string manager, so they
     * are always parsed, never required.
     *
     * @param string $path Absolute path to a lang file.
     * @return array Key => value.
     */
    private function parse_help_strings(string $path): array {
        if (!is_file($path)) {
            return [];
        }
        $src = file_get_contents($path);
        $out = [];
        $re = '/^\$string\[\s*([\'"])(help:[a-z0-9_]+)\1\s*\]\s*=\s*([\'"])(.*?)\3\s*;\s*$/ms';
        if (preg_match_all($re, $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $out[$hit[2]] = str_replace(
                    ['\\' . $hit[3], '\\\\'],
                    [$hit[3], '\\'],
                    $hit[4]
                );
            }
        }
        return $out;
    }

    /**
     * Decode the EN and LANGS tables out of the generated module.
     *
     * @return array{en: array, langs: array}
     */
    private function bundle_tables(): array {
        $js = file_get_contents(self::BUNDLE);
        $this->assertNotEmpty($js, 'generated bundle is missing; run php scripts/build_help_i18n.php');
        $grab = function (string $name) use ($js): array {
            if (!preg_match('/const ' . $name . ' = (\{.*?\});\n/s', $js, $m)) {
                $this->fail("could not find {$name} in " . basename(self::BUNDLE));
            }
            $decoded = json_decode($m[1], true);
            $this->assertIsArray($decoded, "{$name} is not valid JSON");
            return $decoded;
        };
        return ['en' => $grab('EN'), 'langs' => $grab('LANGS')];
    }

    /**
     * The bundle's English table matches lang/en exactly.
     */
    public function test_bundle_english_is_in_step_with_lang_en(): void {
        $tables = $this->bundle_tables();
        $expected = $this->parse_help_strings(self::LANGDIR . '/en/local_ai_course_assistant.php');
        ksort($expected);
        $actual = $tables['en'];
        ksort($actual);
        $this->assertSame($expected, $actual,
            "amd/src/i18n_help.js is out of step with lang/en.\n"
            . 'Regenerate it: php scripts/build_help_i18n.php');
    }

    /**
     * Every translated locale is present, keyed by its two-character code.
     */
    public function test_every_locale_is_present_and_two_char_keyed(): void {
        $tables = $this->bundle_tables();
        $langs = $tables['langs'];

        foreach (array_keys($langs) as $code) {
            $this->assertSame(2, strlen($code),
                "bundle language key '{$code}' is not two characters; the runtime lookup "
                . 'truncates to two, so a longer key can never be matched');
        }

        // Every locale that actually translates a help string must appear.
        $missing = [];
        foreach (glob(self::LANGDIR . '/*/local_ai_course_assistant.php') as $path) {
            $dir = basename(dirname($path));
            if ($dir === 'en') {
                continue;
            }
            $rows = $this->parse_help_strings($path);
            $translates = false;
            foreach ($tables['en'] as $k => $v) {
                if (isset($rows[$k]) && $rows[$k] !== $v) {
                    $translates = true;
                    break;
                }
            }
            if ($translates && !isset($langs[substr($dir, 0, 2)])) {
                $missing[] = $dir . ' (expected key ' . substr($dir, 0, 2) . ')';
            }
        }
        $this->assertSame([], $missing,
            "These locales translate help strings but are absent from the bundle:\n"
            . implode("\n", $missing) . "\nRegenerate: php scripts/build_help_i18n.php");
    }

    /**
     * Each tagged element in the widget template has a key in the bundle.
     */
    public function test_template_help_keys_all_exist_in_the_bundle(): void {
        $tpl = file_get_contents(__DIR__ . '/../templates/chat_widget.mustache');
        preg_match_all('/data-help-key(?:-extra)?="([^"]+)"/', $tpl, $m);
        $keys = array_values(array_unique($m[1]));
        $this->assertNotEmpty($keys, 'the help panel has no data-help-key attributes to retranslate');

        $tables = $this->bundle_tables();
        $unknown = array_values(array_diff($keys, array_keys($tables['en'])));
        $this->assertSame([], $unknown,
            "The template tags these keys for retranslation but the bundle has no such string:\n"
            . implode("\n", $unknown));
    }
}
