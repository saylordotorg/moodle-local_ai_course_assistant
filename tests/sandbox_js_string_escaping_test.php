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
 * A translated string must not be able to break the sandbox page's JavaScript.
 *
 * sandbox.php built three JavaScript string literals by echoing get_string()
 * straight between single quotes:
 *
 *     status.textContent = '<?php echo get_string('sandbox:load_error', ...); ?>';
 *
 * The French translation of that key is "Impossible de charger
 * l'environnement d'exécution Python", which carries two apostrophes. Rendered
 * into the page, the literal closes at the first one and the rest is garbage,
 * so the whole inline script fails to parse. Not one message: every script on
 * the page, which is the Pyodide loader, the Run button and the output pane.
 * The sandbox was dead on any site running in French.
 *
 * It was never noticed because the plugin's own locale is English, the English
 * string has no apostrophe, and nothing tests a page's rendered JavaScript.
 *
 * The fix is json_encode, which emits the quotes itself and escapes what is
 * inside them. This test reads the source rather than rendering the page,
 * because the defect is in how the literal is constructed and that is visible
 * statically. It also asserts the real translations survive the encoder, which
 * is the part that would have caught the original.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class sandbox_js_string_escaping_test extends \basic_testcase {

    /**
     * No quoted get_string() interpolation remains in the page.
     */
    public function test_no_lang_string_is_interpolated_into_a_quoted_js_literal(): void {
        $src = file_get_contents(__DIR__ . '/../sandbox.php');
        $this->assertNotFalse($src, 'sandbox.php must be readable');

        $found = [];
        if (preg_match_all(
            '/[\'"]\s*<\?php\s+echo\s+get_string\(\s*[\'"]([a-z_:]+)[\'"]/i',
            $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $found[] = $hit[1];
            }
        }

        $this->assertSame([], $found,
            "These lang keys are echoed inside a quoted JavaScript literal in sandbox.php:\n  "
                . implode("\n  ", $found)
                . "\nAny translation containing an apostrophe closes the literal early and "
                . "breaks every script on the page. Use json_encode(get_string(...), "
                . "JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), which "
                . "emits its own quotes.");
    }

    /**
     * Inside a script block, every lang string goes through the encoder.
     *
     * Scoped to script blocks on purpose. The same page echoes a dozen lang
     * strings into HTML, where s() and Moodle's own escaping are correct and
     * json_encode would be wrong. The bug is specific to JavaScript context.
     */
    public function test_lang_strings_inside_script_blocks_use_json_encode(): void {
        $src = file_get_contents(__DIR__ . '/../sandbox.php');
        $this->assertNotFalse($src);

        // Collect the inline script bodies. src="..." tags carry no JS literals.
        $scripts = [];
        if (preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>(.*?)<\/script>/s', $src, $m)) {
            $scripts = $m[1];
        }
        $this->assertNotEmpty($scripts, 'sanity: sandbox.php should have inline script blocks');

        $bare = [];
        foreach ($scripts as $js) {
            if (preg_match_all('/<\?php\s+echo\s+([^;]+);\s*\?>/', $js, $mm)) {
                foreach ($mm[1] as $expr) {
                    if (strpos($expr, 'get_string(') === false) {
                        continue;
                    }
                    if (strpos($expr, 'json_encode(') === false) {
                        $bare[] = trim($expr);
                    }
                }
            }
        }

        $this->assertSame([], $bare,
            "These lang strings reach JavaScript in sandbox.php without json_encode:\n  "
                . implode("\n  ", $bare)
                . "\nAn apostrophe in any translation then breaks the whole script block.");
    }

    /**
     * No translation makes the encoder return false.
     *
     * This replaces a test that could not fail. The earlier version asserted
     * that the encoded form of each translation carried no raw quote or angle
     * bracket, which the HEX flags guarantee unconditionally: it never read
     * sandbox.php, so it passed just as happily against the buggy page. Review
     * caught that, and it was overstated in three places as "the part that
     * would have caught the original". It would not have. What catches a new
     * locale is the two tests above, which read the page.
     *
     * There is still something real to pin here, and it is the one remaining
     * way a lang string can blank the script. json_encode returns false on
     * invalid UTF-8, and `status.textContent = ;` is a syntax error, so a
     * mangled customlang edit would kill the page exactly as the apostrophe
     * did. JSON_INVALID_UTF8_SUBSTITUTE prevents that, and this asserts both
     * halves: the flag is present at every site, and every shipped translation
     * encodes to something non-empty.
     */
    public function test_no_translation_can_make_the_encoder_return_false(): void {
        $root = __DIR__ . '/..';

        $src = file_get_contents($root . '/sandbox.php');
        $this->assertNotFalse($src);
        // Check each site, not a count. Counting let a site lose the flag as
        // long as some other json_encode on the page still carried it, which is
        // exactly what the proof run showed: removing one flag SURVIVED.
        preg_match_all('/json_encode\(\s*get_string\([^)]*\)\s*,([^;]*?)\)\s*;/s', $src, $m);
        $this->assertNotEmpty($m[1], 'sanity: the page should encode lang strings');

        $unguarded = [];
        foreach ($m[1] as $i => $flags) {
            if (strpos($flags, 'JSON_INVALID_UTF8_SUBSTITUTE') === false) {
                $unguarded[] = 'site ' . ($i + 1) . ': ' . trim(preg_replace('/\s+/', ' ', $flags));
            }
        }

        $this->assertSame([], $unguarded,
            "These json_encode calls on a lang string in sandbox.php lack "
                . "JSON_INVALID_UTF8_SUBSTITUTE:\n  " . implode("\n  ", $unguarded)
                . "\nWithout it, invalid UTF-8 from a customlang edit makes json_encode "
                . "return false, the literal renders as nothing, and the whole script "
                . "block dies the same way the apostrophe killed it.");

        $keys = ['sandbox:ready', 'sandbox:load_error'];
        $checked = 0;
        $problems = [];

        foreach (scandir($root . '/lang') as $locale) {
            if ($locale[0] === '.') {
                continue;
            }
            $file = $root . '/lang/' . $locale . '/local_ai_course_assistant.php';
            if (!is_file($file)) {
                continue;
            }
            $contents = file_get_contents($file);

            foreach ($keys as $key) {
                if (!preg_match("/\\\$string\['" . preg_quote($key, '/') . "'\]\s*=\s*'(.*)';/", $contents, $m)) {
                    continue;
                }
                $value = str_replace(["\\'", '\\\\'], ["'", '\\'], $m[1]);
                $checked++;

                $encoded = json_encode($value,
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                        | JSON_INVALID_UTF8_SUBSTITUTE);

                if ($encoded === false || $encoded === '""') {
                    $problems[] = "{$locale}/{$key}: encodes to "
                        . var_export($encoded, true)
                        . ', which would render as an empty or missing JS literal';
                }
            }
        }

        $this->assertGreaterThan(40, $checked,
            'sanity: these keys should exist across the locale set, got ' . $checked);
        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
