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
 * The Python sandbox fetches its runtime only from somewhere an admin chose.
 *
 * CONTRIB-10574 #271. sandbox.php carried the jsDelivr URL for Pyodide v0.27.0
 * in three places, so every learner who opened the page downloaded about 10 MB
 * from a third party and told that third party their IP address, with no admin
 * control at all. The location is now a setting with no default, and empty
 * means the sandbox is off, with no fallback.
 *
 * The last test here greps the page source. That is on purpose: the whole
 * finding is about a literal in a file, and a unit test of the helper would
 * stay green if someone pasted the CDN URL back into the markup.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\code_sandbox
 */
final class code_sandbox_runtime_url_test extends \advanced_testcase {

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * A fresh install requests nothing, because no location is configured.
     */
    public function test_the_shipped_default_is_empty(): void {
        unset_config(code_sandbox::BASE_URL_SETTING, 'local_ai_course_assistant');

        $this->assertSame('', code_sandbox::pyodide_base_url());
        $this->assertSame('', code_sandbox::pyodide_asset_url('pyodide.js'),
            'With no base there is no asset URL to put in a script tag.');
    }

    /**
     * Empty means off. The feature flag alone must not start a download.
     */
    public function test_an_enabled_course_is_still_unavailable_without_a_location(): void {
        set_config('code_sandbox_enabled', 1, 'local_ai_course_assistant');
        set_config(code_sandbox::BASE_URL_SETTING, '   ', 'local_ai_course_assistant');

        $this->assertFalse(code_sandbox::is_available(42),
            'A blank location is an administrator saying no to the outbound '
                . 'request. Honouring the flag anyway would be #271 again.');
    }

    /**
     * Both halves are required, and a configured location alone is not enough.
     */
    public function test_availability_needs_the_flag_and_the_location(): void {
        set_config(code_sandbox::BASE_URL_SETTING,
            'https://cdn.example.org/pyodide/v0.27.0/full/', 'local_ai_course_assistant');

        set_config('code_sandbox_enabled', 0, 'local_ai_course_assistant');
        $this->assertFalse(code_sandbox::is_available(42));

        set_config('code_sandbox_enabled', 1, 'local_ai_course_assistant');
        $this->assertTrue(code_sandbox::is_available(42));

        // Per-course off still wins over the site default.
        set_config('code_sandbox_enabled_course_42', '0', 'local_ai_course_assistant');
        $this->assertFalse(code_sandbox::is_available(42));
    }

    /**
     * The base always ends in exactly one slash, so file names can be appended.
     */
    public function test_the_base_is_normalised(): void {
        set_config(code_sandbox::BASE_URL_SETTING,
            '  https://pyodide.example.org/full  ', 'local_ai_course_assistant');

        $this->assertSame('https://pyodide.example.org/full/', code_sandbox::pyodide_base_url());
        $this->assertSame('https://pyodide.example.org/full/pyodide.js',
            code_sandbox::pyodide_asset_url('pyodide.js'));
        $this->assertSame('https://pyodide.example.org/full/pyodide.js',
            code_sandbox::pyodide_asset_url('/pyodide.js'),
            'A leading slash on the file must not produce a double slash.');
    }

    /**
     * Anything that is not a plain https base is refused, and refusal is off.
     *
     * @dataProvider bad_url_provider
     * @param string $url
     * @param string $why
     */
    public function test_unusable_locations_disable_the_sandbox(string $url, string $why): void {
        set_config('code_sandbox_enabled', 1, 'local_ai_course_assistant');
        set_config(code_sandbox::BASE_URL_SETTING, $url, 'local_ai_course_assistant');

        $this->assertFalse(code_sandbox::validate($url), $why);
        $this->assertSame('', code_sandbox::pyodide_base_url(), $why);
        $this->assertFalse(code_sandbox::is_available(42), $why);
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function bad_url_provider(): array {
        return [
            'plain http' => ['http://pyodide.example.org/full/',
                'http would be blocked as mixed content on an https site anyway.'],
            'javascript' => ['javascript:alert(1)//',
                'This value ends up in a script src attribute.'],
            'data uri' => ['data:text/javascript,alert(1)',
                'A data URI has no host and must never reach a script tag.'],
            'protocol relative' => ['//cdn.example.org/pyodide/',
                'No scheme means the browser picks one. Be explicit.'],
            'no host' => ['https:///full/', 'A base with no host resolves nowhere.'],
            'credentials' => ['https://user:pw@pyodide.example.org/full/',
                'Credentials in a URL would leak into page source.'],
            'query string' => ['https://pyodide.example.org/full/?v=1',
                'File names are appended to the base, so a query would land in the wrong place.'],
            'fragment' => ['https://pyodide.example.org/full/#x',
                'Same reason as the query string.'],
            'quote' => ['https://pyodide.example.org/full/"onload="x',
                'Refuse to store anything that could break out of the attribute.'],
            'whitespace' => ['https://pyodide.example.org/a b/',
                'A space in a URL is a sign of a mangled value, not a working mirror.'],
        ];
    }

    /**
     * No CDN literal survives in the page the finding was filed against.
     */
    public function test_the_page_carries_no_hardcoded_runtime_url(): void {
        $source = file_get_contents(__DIR__ . '/../sandbox.php');
        $this->assertNotFalse($source, 'sandbox.php should be readable.');

        $this->assertDoesNotMatchRegularExpression(
            '~["\']https?://[^"\']*(jsdelivr|unpkg|pyodide\.org)[^"\']*["\']~i',
            $source,
            'sandbox.php must not fetch the runtime from a URL baked into the code. '
                . 'The location belongs in the ' . code_sandbox::BASE_URL_SETTING . ' setting.'
        );
    }
}
