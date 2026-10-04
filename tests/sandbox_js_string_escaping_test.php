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
 * History. sandbox.php used to build JavaScript string literals by echoing
 * get_string() between single quotes. The French translation of
 * sandbox:load_error carries two apostrophes, so the literal closed at the
 * first one and every script on the page failed to parse: the Pyodide loader,
 * the Run button and the output pane. The sandbox was dead on any French site,
 * unnoticed because the plugin's own locale is English. v7.6.1 fixed it with
 * json_encode, and this test used to pin that each such site was encoded and
 * carried JSON_INVALID_UTF8_SUBSTITUTE.
 *
 * CONTRIB-10574 #273 then moved the page to a Mustache template and an AMD
 * module. Strings now reach JavaScript through core/str and values through
 * data attributes, so no PHP value is interpolated into JavaScript anywhere on
 * the page. The old assertions had nothing left to check, and one of them, a
 * sanity check that the page HAS inline script blocks, would now fail for the
 * right reason. So this test pins the stronger invariant the migration
 * created: there is no inline JavaScript to break. It also renders the real
 * template, because the move introduced two risks of its own.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\code_sandbox
 */
final class sandbox_js_string_escaping_test extends \advanced_testcase {

    /**
     * Neither the page nor its template emits any inline JavaScript.
     *
     * If a script block comes back, so does the possibility of a lang string
     * being interpolated into it, which is the original bug.
     */
    public function test_the_page_emits_no_inline_javascript(): void {
        $root = __DIR__ . '/..';
        foreach (['sandbox.php', 'templates/sandbox.mustache'] as $file) {
            $src = file_get_contents($root . '/' . $file);
            $this->assertNotFalse($src, "{$file} must be readable");
            $this->assertStringNotContainsStringIgnoringCase('<script', $src,
                "{$file} emits an inline script again; lang strings and PHP values "
                . 'must reach the page through core/str and data attributes, not a script block.');
            $this->assertDoesNotMatchRegularExpression('/json_encode\s*\(\s*get_string/', $src,
                "{$file} encodes a lang string for JavaScript again.");
        }
    }

    /**
     * The module fetches the two runtime strings through core/str.
     */
    public function test_the_module_takes_its_strings_from_core_str(): void {
        $src = file_get_contents(__DIR__ . '/../amd/src/sandbox.js');
        $this->assertNotFalse($src);
        $this->assertStringContainsString('Str.get_strings(', $src);
        foreach (['sandbox:ready', 'sandbox:load_error'] as $key) {
            $this->assertStringContainsString("key: '{$key}'", $src,
                "{$key} must come from core/str, not be baked into the page");
        }
    }

    /**
     * A Python error shows the learner their traceback, not the word "PythonError".
     *
     * The module redirects sys.stderr into _aica_err so it can capture output,
     * and that redirect is also where Pyodide writes the formatted traceback,
     * leaving the thrown error's message EMPTY. The handler fell back to
     * String(err), so for as long as the sandbox has existed a failing program
     * showed the bare word "PythonError" and never the actual error. Found by
     * running the module in a browser during #273; there is no browser in CI, so
     * this pins the handler reading the buffer first.
     */
    public function test_a_python_error_shows_the_captured_traceback(): void {
        $src = file_get_contents(__DIR__ . '/../amd/src/sandbox.js');
        $this->assertNotFalse($src);

        $this->assertSame(1, preg_match('/\} catch \(err\) \{(.*?)\} finally \{/s', $src, $m),
            'the run handler should have a catch block');
        // Compare code only. The block's own comment explains the bug and names
        // err.message before the code does, which would satisfy or defeat the
        // ordering check for the wrong reason.
        $catch = preg_replace('#//[^\n]*#', '', $m[1]);
        // Reading the buffer is not enough: the first version of this test
        // passed against a handler that read it and then ignored it. Pin what
        // is actually SHOWN: the captured traceback comes first in the argument
        // to showStderr, ahead of any fallback to the error object.
        $this->assertSame(1, preg_match('/var\s+(\w+)\s*=\s*readBuffer\(\'_aica_err\'\)/', $catch, $v),
            'the catch must capture the stderr buffer; err.message is empty under the redirect');
        $this->assertSame(1, preg_match('/showStderr\(\s*' . preg_quote($v[1], '/') . '\s*\|\|/', $catch),
            'showStderr must be given the captured traceback first, falling back only when it is empty');
    }

    /**
     * The starter code renders exactly, because the textarea is whitespace-sensitive.
     *
     * Everything between the textarea tags is what the learner sees and runs.
     * Indenting the template to match the surrounding markup would shift every
     * line of Python right and turn the starter into an IndentationError.
     */
    public function test_the_starter_code_renders_exactly(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('local_ai_course_assistant/sandbox', [
            'pyodidebase' => 'https://pyodide.example.org/full/',
            'loaderurl' => 'https://pyodide.example.org/full/pyodide.js',
        ]);

        $this->assertSame(1, preg_match('#<textarea[^>]*>(.*?)</textarea>#s', $html, $m),
            'the code textarea must render');
        $comment = s(get_string('sandbox:default_code_comment', 'local_ai_course_assistant'));
        $this->assertSame($comment . "\nfor n in range(1, 11):\n    print(n, n*n)\n", $m[1]);
    }

    /**
     * A runtime URL carrying quotes or markup cannot break out of its attribute.
     *
     * The URL is an admin setting and the validator rejects most bad values, but
     * the template is the last line and must not depend on that.
     */
    public function test_a_hostile_runtime_url_stays_inside_its_attribute(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $hostile = 'https://x.example/"><script>alert(1)</script>';
        $html = $OUTPUT->render_from_template('local_ai_course_assistant/sandbox', [
            'pyodidebase' => $hostile,
            'loaderurl' => $hostile,
        ]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('data-pyodide-base="https://x.example/&quot;&gt;&lt;script&gt;', $html);
    }
}
