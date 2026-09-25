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
     * the docs claim, and nothing detected it: the reference test only proves a
     * key exists in English, so a new English-only string was invisible.
     *
     * This list is the debt, not an exemption. Removing an entry is what a
     * translation batch does, and v7.5.3 removed 401 of them: every
     * administrator and teacher surface extracted since v7.0.1, translated into
     * all 45 locales with placeholder, branding-token, HTML and <code> parity
     * checked mechanically on every string.
     *
     * The teacher half of that was overdue rather than optional. course_settings.php
     * is gated on local/ai_course_assistant:manage, which editing teachers hold, so
     * its twenty-five strings had been showing English to every non-English teacher
     * on every site while the docs claimed 46/46.
     *
     * What is left is eleven keys with a reason each, below. Adding one should be
     * a deliberate, reviewed act, because the parity test fails the moment an
     * unlisted key appears in English without translations, which is the drift
     * that went unnoticed.
     */
    private const KNOWN_UNTRANSLATED = [
        // The video rubric tab. NOT deferred translation debt: the tab was specced
        // and cut, so nothing in the product can reach these. rubric_admin.php
        // whitelists conversation, pronunciation and speech only, and clamps an
        // unknown ?type back to conversation. They are held rather than deleted
        // because the video rubric TYPE is live (rubric_manager::TYPE_VIDEO,
        // reached from score_speech), so these are the labels that ship with the
        // tab when it exists. Do not send them to a translator until it does.
        'rubric_admin:tab_video',
        'rubric_admin:rubric_title_video',
        'rubric_admin:needs_video',
        'rubric_admin:needs_video_help',
        'rubric_admin:needs_video_aria',
        'rubric_admin:preview_conditional',
        'rubric_admin:preview_total_novideo',
        // Two pairs of settings copy whose whole content is a consequence an
        // administrator would act on, where a subtly wrong translation is worse
        // than an honest English fallback.
        //
        // outcomes_panel_enabled explains a capability boundary in another plugin
        // and what happens if you enable the panel before that plugin is new
        // enough. Getting it slightly wrong means an administrator switches it on
        // believing something false about who can see the result.
        'settings:outcomes_panel_enabled',
        'settings:outcomes_panel_enabled_desc',
        // soapbox_gesture_vision turns on scoring a learner's body language from
        // sampled video stills. What it does and what it retains is the entire
        // point of the description.
        'settings:soapbox_gesture_vision',
        'settings:soapbox_gesture_vision_desc',
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

    /**
     * Strings that are identical to English in every locale on purpose, and
     * always will be. Acronyms (CSV, DPA, LLM, RAG), product names (Redash,
     * Soapbox, OpenAI, OpenRouter) and strings whose entire content is a
     * placeholder token ([[tutorshort]]). Translating these would be wrong,
     * not merely unnecessary.
     */
    private const TRANSLATION_NOT_REQUIRED = [
        'admin:vendor_dpa:col_dpa',
        'analytics:format_csv',
        'analytics:format_json',
        'analytics:format_markdown',
        'analytics:redash',
        'chat:assistant',
        'chat:llm_label',
        'chat:title',
        'emergency:flag_rag',
        'settings:provider_coreai',
        'settings:provider_deepseek',
        'settings:provider_minimax',
        'settings:provider_openai',
        'settings:provider_openrouter',
        'settings:soapbox_heading',
        'soapbox:title',
        // v7.5.3: confirmed untranslatable by the 339-key batch rather than
        // assumed. Every locale returned these unchanged, and each is a bare
        // product name or a format string with no words in it.
        // The whole value is "{$a}". There is nothing to translate.
        'error',
        // The whole value is "{$a->raw} / {$a->max}".
        'bench:quality_of',
        'settings:embed_provider_openai',
        'settings:provider_gemini',
        'settings:provider_mistral',
        'settings:provider_together',
        'settings:provider_xai',
        'settings:talking_avatar_provider_did',
        'settings:talking_avatar_provider_heygen',
        'settings:talking_avatar_provider_tavus',
    ];

    /**
     * Keys that ARE defined in all 45 locales but still hold the English text.
     *
     * A different failure from a missing key. A missing key falls back to
     * lang/en, which is honest and is what KNOWN_UNTRANSLATED relies on for its
     * eleven. These instead look translated: present in the locale file, so the
     * parity check passes and "46/46 with zero missing keys" is true and tells
     * you nothing. 68 of them were privacy: strings, which learners read.
     *
     * v7.5.3 worked the list down to nothing. All 339 were translated into all 45
     * locales, and the twelve that came back identical everywhere turned out not
     * to be backlog at all: nine are bare product names, one is "{$a}", one is
     * "{$a->raw} / {$a->max}", and they moved to TRANSLATION_NOT_REQUIRED where
     * they belonged.
     *
     * The list is a backlog to be worked down, not a policy, so it is empty
     * rather than deleted: the next release that extracts hardcoded English into
     * lang/en without translating it has somewhere honest to put it, and the test
     * below fails until someone does.
     */
    private const IDENTICAL_TO_ENGLISH_BACKLOG = [];

    /**
     * Fail when a key is byte-identical to English in EVERY locale that
     * defines it, unless it is declared above.
     *
     * "Every locale" is the point. A single locale matching English is usually
     * a cognate -- Spanish "Radar", German "Chat" -- and flagging those would
     * bury the real gaps in noise (869 keys match in at least one locale, only
     * 434 in all of them). Agreement across all 45 at once, Japanese, Arabic,
     * Thai and Chinese included, does not happen by coincidence; it means the
     * key was copied from English and never translated.
     *
     * What this deliberately does NOT catch: a key translated in 44 locales
     * and left English in one. That needs a per-locale rule and a much larger
     * allowlist; this gate is the high-signal half.
     *
     * @return void
     */
    public function test_present_keys_are_not_just_english(): void {
        $en = $this->load_en_strings();
        $locales = array_filter(
            scandir($this->plugin_root() . '/lang'),
            fn($d) => $d !== '.' && $d !== '..' && $d !== 'en'
        );
        $this->assertGreaterThan(40, count($locales), 'expected the full locale set');

        $tables = [];
        foreach ($locales as $lang) {
            $tables[$lang] = $this->locale_keys($lang);
        }

        $allowed = array_flip(array_merge(
            self::TRANSLATION_NOT_REQUIRED,
            self::IDENTICAL_TO_ENGLISH_BACKLOG
        ));

        $untranslated = [];
        foreach ($en as $key => $value) {
            if (trim($value) === '' || isset($allowed[$key])) {
                continue;
            }
            $defining = 0;
            $matching = 0;
            foreach ($tables as $table) {
                if (!array_key_exists($key, $table)) {
                    continue;
                }
                $defining++;
                if ($table[$key] === $value) {
                    $matching++;
                }
            }
            if ($defining > 0 && $matching === $defining) {
                $untranslated[] = $key . ' (English in all ' . $defining . ' locales)';
            }
        }

        if (!empty($untranslated)) {
            $this->fail("Keys present in every locale but still holding the English "
                . "text. Translate them, or add them to IDENTICAL_TO_ENGLISH_BACKLOG "
                . "(a backlog) or TRANSLATION_NOT_REQUIRED (an acronym or product "
                . "name) deliberately:\n  - " . implode("\n  - ", $untranslated));
        }
    }
}
