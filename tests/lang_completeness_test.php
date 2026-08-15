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
 * Meta-test: every plugin lang key referenced from a mustache template
 * or a PHP file must be defined in lang/en/local_ai_course_assistant.php
 * (v5.3.19).
 *
 * Catches the class of bug where new code references a string that was
 * never added (the v5.3.17 missing `messageprovider:study_reminder` is
 * a concrete example — caught only because Moodle's own test happened
 * to run on it). Generalises that check to every key the plugin uses.
 *
 * Scans:
 *   - All `*.mustache` templates for `{{#str KEY, local_ai_course_assistant}}`.
 *   - All `*.php` files for `get_string('KEY', 'local_ai_course_assistant')`.
 *
 * Skips dynamically-built keys (e.g. `'foo:' . $bar`) since those need
 * runtime context to resolve.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lang_completeness_test extends \basic_testcase {
    /**
     * Plugin root.
     *
     * @return string
     */
    private function plugin_root(): string {
        global $CFG;
        return $CFG->dirroot . '/local/ai_course_assistant';
    }

    /**
     * Load the EN string set.
     *
     * @return array<string, string>
     */
    private function load_en_strings(): array {
        $string = [];
        include($this->plugin_root() . '/lang/en/local_ai_course_assistant.php');
        return $string;
    }

    /**
     * Walk a directory and return every file matching the extension list.
     *
     * @param string $dir
     * @param array $exts list of file extensions to include (e.g. ['.php', '.mustache'])
     * @return array list of absolute file paths matching one of $exts
     */
    private function walk(string $dir, array $exts): array {
        $out = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iter as $f) {
            $path = $f->getPathname();
            // Skip the test dir itself, vendor, build artefacts, drafts.
            if (preg_match('#/(\.git|node_modules|vendor|amd/build|tests/|\.drafts/|\.wiki/)#', $path)) {
                continue;
            }
            foreach ($exts as $ext) {
                if (str_ends_with($path, $ext)) {
                    $out[] = $path;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Extract every static lang key referenced from mustache + PHP files.
     *
     * @return array<int, array{key: string, file: string}>
     */
    private function referenced_keys(): array {
        $refs = [];
        $component = 'local_ai_course_assistant';

        // Mustache: `{{#str KEY, local_ai_course_assistant}}`
        foreach ($this->walk($this->plugin_root() . '/templates', ['.mustache']) as $f) {
            $body = file_get_contents($f);
            if (
                preg_match_all(
                    '/\{\{#str\}\}\s*([a-zA-Z0-9_:]+)\s*,\s*' . preg_quote($component, '/') . '\s*\{\{\/str\}\}/',
                    $body,
                    $m
                )
            ) {
                foreach ($m[1] as $k) {
                    $refs[] = ['key' => $k, 'file' => basename($f)];
                }
            }
            // Older mustache form: `{{#str}}KEY, comp{{/str}}`
            if (
                preg_match_all(
                    '/\{\{#str\}\}\s*([a-zA-Z0-9_:]+)\s*,\s*' . preg_quote($component, '/') . '\s*\{\{\/str\}\}/s',
                    $body,
                    $m2
                )
            ) {
                foreach ($m2[1] as $k) {
                    $refs[] = ['key' => $k, 'file' => basename($f)];
                }
            }
        }

        // PHP: `get_string('KEY', 'local_ai_course_assistant')`. We only
        // capture single-quoted literal keys; dynamic keys (concatenation
        // or interpolation) are skipped because they need runtime context.
        foreach ($this->walk($this->plugin_root(), ['.php']) as $f) {
            $body = file_get_contents($f);
            if (
                preg_match_all(
                    "/get_string\(\s*'([a-zA-Z0-9_:]+)'\s*,\s*'" . preg_quote($component, '/') . "'/",
                    $body,
                    $m
                )
            ) {
                foreach ($m[1] as $k) {
                    $refs[] = ['key' => $k, 'file' => str_replace($this->plugin_root() . '/', '', $f)];
                }
            }
        }

        return $refs;
    }

    public function test_every_referenced_key_is_defined(): void {
        $defined = $this->load_en_strings();
        $refs = $this->referenced_keys();
        $this->assertNotEmpty($refs, 'Reference scan must find at least some keys');

        $missing = [];
        foreach ($refs as $r) {
            if (!array_key_exists($r['key'], $defined)) {
                $missing[$r['key']] = ($missing[$r['key']] ?? []);
                $missing[$r['key']][] = $r['file'];
            }
        }

        if (!empty($missing)) {
            $report = [];
            foreach ($missing as $k => $files) {
                $report[] = $k . ' (referenced in: ' . implode(', ', array_unique($files)) . ')';
            }
            $this->fail("Lang keys referenced in templates/PHP but missing from "
                . "lang/en/local_ai_course_assistant.php:\n  - "
                . implode("\n  - ", $report));
        }
    }

    /**
     * English keys that are knowingly not translated yet.
     *
     * The pre-7.0.0 audit found the plugin was 8 keys short of the 46/46 parity
     * the docs claim, and nothing detected it: the test above only proves a
     * referenced key exists in English, so a new English-only string was invisible.
     *
     * This list is the debt, not an exemption. Removing an entry is what a
     * translation batch does. Adding one should be a deliberate, reviewed act,
     * because the parity test below fails the moment an unlisted key appears in
     * English without translations, which is the drift that went unnoticed.
     */
    private const KNOWN_UNTRANSLATED = [
        // Empty as of 6.9.5: the eight keys that were listed here were translated
        // into all 45 locales. Keep it empty. An entry appearing here should be a
        // conscious, temporary decision, not the default way new strings land.
    ];

    /**
     * Keys defined in a locale file.
     *
     * @param string $lang locale directory name.
     * @return array<string, true>
     */
    private function locale_keys(string $lang): array {
        $path = $this->plugin_root() . '/lang/' . $lang . '/local_ai_course_assistant.php';
        if (!file_exists($path)) {
            return [];
        }
        $string = [];
        include($path);
        return $string;
    }

    public function test_translation_parity_has_not_regressed(): void {
        $en = array_keys($this->load_en_strings());
        $locales = array_filter(
            scandir($this->plugin_root() . '/lang'),
            fn($d) => $d !== '.' && $d !== '..' && $d !== 'en'
        );
        $this->assertGreaterThan(40, count($locales), 'expected the full locale set');

        $unexpected = [];
        foreach ($locales as $lang) {
            $keys = $this->locale_keys($lang);
            foreach ($en as $key) {
                if (
                    !array_key_exists($key, $keys)
                        && !in_array($key, self::KNOWN_UNTRANSLATED, true)
                ) {
                    $unexpected[$key][] = $lang;
                }
            }
        }

        if (!empty($unexpected)) {
            $report = [];
            foreach ($unexpected as $key => $langs) {
                $report[] = $key . ' (missing from ' . count($langs) . ' locales)';
            }
            $this->fail("English strings with no translations, and not listed in "
                . "KNOWN_UNTRANSLATED. Either translate them or add them to that "
                . "list deliberately:\n  - " . implode("\n  - ", $report));
        }
    }
}
