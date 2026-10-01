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
 * Where the browser-side Python runtime (Pyodide) is loaded from.
 *
 * CONTRIB-10574 #271. The sandbox page used to carry the jsDelivr URL for
 * Pyodide v0.27.0 in three hard-coded places, so every learner who opened it
 * fetched roughly 10 MB of code from a third party and disclosed their IP
 * address and user agent to that third party, with no way for an administrator
 * to point the page somewhere else or to say no.
 *
 * The runtime location is now an administrator setting with no default, and an
 * EMPTY value means the sandbox is off. This is deliberately the same shape as
 * the remoteconfigurl fix in 7.6.0: empty means disabled, and there is no
 * fallback, because an administrator who clears the field has switched the
 * outbound request off and must be able to rely on that.
 *
 * Why this does NOT call security::is_safe_provider_url():
 *
 * That check exists to stop a compromised admin account aiming a SERVER-side
 * request at 127.0.0.1 or a cloud metadata address. Nothing on the server ever
 * fetches this URL; the learner's browser does. So the SSRF threat model does
 * not apply, and borrowing the check would do real harm: it resolves the host
 * through gethostbyname() (a blocking DNS lookup on every sandbox page view)
 * and it rejects private and reserved addresses, which is exactly where a
 * self-hosted Pyodide mirror sits on an intranet Moodle. That is the
 * deployment this issue is asking us to make possible, so refusing it would be
 * backwards. What matters for a browser-loaded script is the shape of the URL,
 * which is what validate() checks, plus escaping at the point of output.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class code_sandbox {

    /** @var string Name of the admin setting holding the runtime base URL. */
    const BASE_URL_SETTING = 'code_sandbox_pyodide_baseurl';

    /**
     * The administrator-configured Pyodide base URL, with a trailing slash.
     *
     * @return string '' when unset, blank, or not a usable base URL.
     */
    public static function pyodide_base_url(): string {
        $url = trim((string) get_config('local_ai_course_assistant', self::BASE_URL_SETTING));
        if ($url === '' || !self::validate($url)) {
            return '';
        }
        return rtrim($url, '/') . '/';
    }

    /**
     * A file under the configured runtime base, or '' when there is no base.
     *
     * @param string $file Relative file name, for example 'pyodide.js'.
     * @return string
     */
    public static function pyodide_asset_url(string $file): string {
        $base = self::pyodide_base_url();
        return $base === '' ? '' : $base . ltrim($file, '/');
    }

    /**
     * Is the sandbox usable in this course?
     *
     * Both halves are required: the pedagogy flag says the course wants it,
     * and the base URL says the site has somewhere to load the runtime from.
     *
     * @param int $courseid
     * @return bool
     */
    public static function is_available(int $courseid): bool {
        return feature_flags::resolve('code_sandbox', $courseid)
            && self::pyodide_base_url() !== '';
    }

    /**
     * Is this a URL we are willing to put in a <script src> on a Moodle page?
     *
     * https only, which also rules out javascript: and data: and keeps the
     * page free of mixed content. No credentials, no query string and no
     * fragment, because the page appends file names to this value and either
     * would produce a nonsense URL rather than a working one.
     *
     * @param string $url
     * @return bool
     */
    public static function validate(string $url): bool {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return false;
        }
        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        // Anything that could break out of an attribute or a JS string. The
        // output path escapes as well; this just refuses to store the value.
        return !preg_match('/["\'<>\s\\\\]/', $url);
    }
}
