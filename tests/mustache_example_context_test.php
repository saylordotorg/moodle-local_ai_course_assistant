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
 * Guard: every template's Example context must parse AND render valid HTML.
 *
 * moodle-plugin-ci's mustache lint renders each template against the
 * `Example context (json):` blob in its own docblock and fails the build on
 * WARNING-level HTML validation errors. Both halves of that have bitten this
 * plugin, both times from the v7.3.5 i18n extraction, and both times the cost
 * was a CI round trip because nothing local reproduced it:
 *
 *  - Eight templates had @package/@copyright/@license placed AFTER the example
 *    JSON. The linter treats everything from the example line to the end of
 *    the docblock as the blob, so those tags landed inside it and it failed to
 *    parse. Invisible to php -l and to every other test.
 *  - analytics_dashboard.mustache rendered nine `<option value="x"></option>`
 *    elements, which the HTML validator rejects. Cause: extraction moved the
 *    option labels out of the markup into {{str_*}} variables, and the example
 *    context did not supply them -- so they rendered empty. An empty option is
 *    a build failure, while an empty heading is only INFO, which is why this
 *    surfaced as one specific template rather than everywhere.
 *
 * This test checks exactly the two failing classes and deliberately not more:
 * empty headings and empty anchors come back as INFO and do not fail the
 * build, so asserting on them would train the reader to ignore this test.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mustache_example_context_test extends \basic_testcase {

    /** @var string Template directory. */
    private const TEMPLATEDIR = __DIR__ . '/../templates';

    /**
     * Extract the last `Example context (json):` blob from a template.
     *
     * The last, not the first: a few templates carry two docblocks (a
     * validator-safe placeholder plus the real one) and the linter uses what it
     * finds last.
     *
     * @param string $src Template source.
     * @return string|null Raw JSON, or null when the template declares none.
     */
    private function example_json(string $src): ?string {
        if (!preg_match_all('/Example context \(json\):\s*(\{.*?\})\s*\}\}/s', $src, $m)) {
            return null;
        }
        return end($m[1]);
    }

    /**
     * Every declared example context must be parseable JSON.
     */
    public function test_example_contexts_parse(): void {
        $broken = [];
        foreach (glob(self::TEMPLATEDIR . '/*.mustache') as $path) {
            $json = $this->example_json(file_get_contents($path));
            if ($json === null) {
                continue;
            }
            json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $broken[] = basename($path) . ': ' . json_last_error_msg();
            }
        }
        $this->assertSame([], $broken,
            "Unparseable Example context JSON. The most common cause is a Moodle tag\n"
            . "(@package/@copyright/@license) placed AFTER the example blob -- the linter reads to the end of\n"
            . "the docblock, so those lines land inside the JSON. Put the tags above the example:\n"
            . implode("\n", $broken));
    }

    /**
     * Rendering a template against its own example context must not emit an
     * empty <option>, which the HTML validator treats as build-failing.
     */
    public function test_templates_render_without_empty_options(): void {
        if (!class_exists('\Mustache_Engine')) {
            $this->markTestSkipped('Mustache engine unavailable');
        }
        $offenders = [];
        foreach (glob(self::TEMPLATEDIR . '/*.mustache') as $path) {
            $src = file_get_contents($path);
            $json = $this->example_json($src);
            if ($json === null) {
                continue;
            }
            $ctx = json_decode($json, true);
            if (!is_array($ctx)) {
                continue; // Covered by the parse test above.
            }
            $body = preg_replace('/\{\{!.*?\}\}/s', '', $src);
            try {
                $engine = new \Mustache_Engine(['escape' => fn($v) => s($v)]);
                $html = $engine->render($body, $ctx);
            } catch (\Throwable $e) {
                $offenders[] = basename($path) . ': render failed: ' . $e->getMessage();
                continue;
            }
            if (preg_match_all('/<option(?![^>]*\blabel=)[^>]*>\s*<\/option>/i', $html, $m)) {
                $offenders[] = sprintf('%s: %d empty <option>', basename($path), count($m[0]));
            }
        }
        $this->assertSame([], $offenders,
            "These templates render an empty <option> against their own example context, which fails\n"
            . "Mustache Lint. Either add the missing label to the example context, or give the option a\n"
            . "label attribute:\n" . implode("\n", $offenders));
    }
}
