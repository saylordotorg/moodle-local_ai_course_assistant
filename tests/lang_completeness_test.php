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
        // v7.0.0 parked 170 keys here as debt: 86 settings had their title
        // and description written as hardcoded English literals, so this
        // check could not even see them. v7.0.1 translated all 170 into all
        // 45 non-English locales, so the debt is paid and the entries are
        // gone — the parity test below now covers them like any other key.
        // Do not re-add them.
        //
        // What is left is the admin-only diagnostic pages, extracted in
        // v7.0.1 and still awaiting a translation batch.
        ...self::ADMIN_DIAGNOSTIC_UNTRANSLATED,
    ];

    /**
     * v7.0.1 (I18N001/I18N003): keys extracted from the admin-only diagnostic
     * pages — prompt_playground.php, provider_benchmark.php and
     * course_settings.php — where the text was hardcoded English and so was
     * never translatable at all. Staged for a later translation batch; they are
     * part of KNOWN_UNTRANSLATED (spread in above) so the parity check stays
     * green, and listed separately so the v7.0.0 locale guard can skip them
     * (the ten v7.0.0 locales do not carry this newer batch).
     *
     * Every locale falls back to lang/en for these, which is byte-for-byte what
     * the pages displayed before the extraction, so nothing regressed.
     */
    private const ADMIN_DIAGNOSTIC_UNTRANSLATED = [
        'benchmark:export_csv',
        'benchmark:export_json',
        'benchmark:export_markdown',
        'benchmark:lastrun',
        'benchmark:norun',
        'benchmark:rerun',
        'benchmark:runnow',
        'coursesettings:auto_open',
        'coursesettings:auto_open_desc',
        'coursesettings:auto_open_heading',
        'coursesettings:auto_open_help',
        'coursesettings:back_to_course',
        'coursesettings:course_analytics',
        'coursesettings:english_lock',
        'coursesettings:english_lock_desc',
        'coursesettings:english_lock_heading',
        'coursesettings:english_lock_help',
        'coursesettings:english_lock_toggle',
        'coursesettings:force_off',
        'coursesettings:force_on',
        'coursesettings:how_it_works',
        'coursesettings:how_it_works_desc',
        'coursesettings:inherit_global',
        'coursesettings:reset_prompt',
        'coursesettings:reset_prompt_confirm',
        'coursesettings:reset_prompt_title',
        'coursesettings:state_disabled',
        'coursesettings:state_enabled',
        'coursesettings:systemprompt_hint',
        'coursesettings:voice_tab',
        'coursesettings:voice_tab_desc',
        'coursesettings:voice_tab_help',
        'prompt_playground:assemble',
        'prompt_playground:assemble_failed',
        'prompt_playground:assembled',
        'prompt_playground:breakdown',
        'prompt_playground:col_content',
        'prompt_playground:col_score',
        'prompt_playground:col_type',
        'prompt_playground:intro',
        'prompt_playground:label_chunks',
        'prompt_playground:label_courseid',
        'prompt_playground:label_pageid',
        'prompt_playground:label_query',
        'prompt_playground:live_retrieval',
        'prompt_playground:mode_live',
        'prompt_playground:mode_simulated',
        'prompt_playground:no_chunks',
        'prompt_playground:pagetitle',
        'prompt_playground:question',
        'prompt_playground:result',
        'prompt_playground:result_summary',
        'prompt_playground:retrieval_failed',
        'prompt_playground:score_na',
        'prompt_playground:selected_chunks',
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
