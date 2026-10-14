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
 * Stops a course from holding an API key that belongs to a different provider
 * (v7.8.8).
 *
 * On 2026-10-11 BUS101 was switched from Claude to Gemini. Its saved Claude key
 * stayed (a blank key box means "keep the stored key"), was sent to Google, and
 * Google answered HTTP 400 "Please pass a valid API key" on every call. That
 * opened a site-wide circuit and failed 232 of 705 messages across 15 courses
 * before anyone found the cause.
 *
 * A key's own prefix says who issued it, so a mismatch can be caught when the
 * course settings are saved, and an existing mismatch can be listed on Reports >
 * Status. Only prefixes that are unambiguous count: a bare "sk-" is shared by
 * OpenAI and many OpenAI-compatible vendors, so it identifies nobody. A course
 * with its own base URL is never checked, because a proxy can take any key.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_key_guard {
    /** @var string[] Providers that take whatever key they are given. */
    private const UNCHECKED = ['', 'auto', 'custom', 'ollama', 'coreai'];

    /**
     * Who issued a key, judged by its prefix alone.
     *
     * @param string $key Plain-text key.
     * @return string 'claude', 'openai', 'gemini', 'xai', 'openrouter', or '' when the key says nothing.
     */
    public static function owner_of_key(string $key): string {
        $key = trim($key);
        if (str_starts_with($key, 'sk-ant-')) {
            return 'claude';
        }
        if (str_starts_with($key, 'sk-or-')) {
            return 'openrouter';
        }
        if (str_starts_with($key, 'sk-proj-')) {
            return 'openai';
        }
        if (str_starts_with($key, 'AIza') || str_starts_with($key, 'AQ.')) {
            return 'gemini';
        }
        if (str_starts_with($key, 'xai-')) {
            return 'xai';
        }
        return '';
    }

    /**
     * The provider a course will actually call.
     *
     * @param string $provider The course's own provider ('' = use the site's).
     * @param string $model The course's own model ('' = the site's).
     * @param string $siteprovider The site's provider setting.
     * @return string Provider id, or '' when it cannot be told.
     */
    public static function effective_provider(string $provider, string $model, string $siteprovider): string {
        $effective = $provider !== '' ? $provider : $siteprovider;
        if ($effective === 'auto' || $effective === '') {
            $effective = $model !== '' ? \local_ai_course_assistant\autoupgrade\roles::infer_provider($model) : '';
        }
        return strtolower($effective);
    }

    /**
     * Whether a key clearly belongs to a different provider than the one in use.
     *
     * @param string $provider Effective provider (see effective_provider()).
     * @param string $key Plain-text key.
     * @param string $baseurl The course's own base URL, if any.
     * @return string The provider the key belongs to when it conflicts, else ''.
     */
    public static function conflict(string $provider, string $key, string $baseurl = ''): string {
        if ($baseurl !== '' || in_array($provider, self::UNCHECKED, true)) {
            return '';
        }
        $owner = self::owner_of_key($key);
        return ($owner !== '' && $owner !== $provider) ? $owner : '';
    }

    /**
     * Decide what a course settings save does with the key.
     *
     * A key typed in the form that clearly belongs to another provider is not
     * saved. A key already stored that no longer fits the provider (the form
     * just changed it) is removed, so the course falls back to the site key
     * instead of sending the wrong credential to the new provider.
     *
     * @param string $provider Provider submitted for the course ('' = site's).
     * @param string $model Model submitted for the course.
     * @param string $siteprovider The site's provider setting.
     * @param string $baseurl Base URL submitted for the course.
     * @param string $typed Key typed in the form ('' = left blank).
     * @param string $stored Plain-text key already stored for the course.
     * @return array{apikey: string, notices: array[]} The key to store, and notices as [type, lang key, $a].
     */
    public static function resolve(
        string $provider,
        string $model,
        string $siteprovider,
        string $baseurl,
        string $typed,
        string $stored
    ): array {
        $effective = self::effective_provider($provider, $model, $siteprovider);
        $notices = [];
        $key = $typed !== '' ? $typed : $stored;

        $owner = self::conflict($effective, $key, $baseurl);
        if ($owner !== '' && $typed !== '') {
            $notices[] = ['error', 'coursesettings:key_rejected', (object) ['owner' => $owner, 'provider' => $effective]];
            $key = $stored;
            $owner = self::conflict($effective, $key, $baseurl);
        }
        if ($owner !== '') {
            $notices[] = ['warning', 'coursesettings:key_removed', (object) ['owner' => $owner, 'provider' => $effective]];
            $key = '';
        }
        return ['apikey' => $key, 'notices' => $notices];
    }

    /**
     * Courses whose saved key clearly belongs to a different provider.
     *
     * @return array<int, array{shortname: string, provider: string, owner: string}>
     */
    public static function mismatched_courses(): array {
        global $DB;
        $siteprovider = (string) (get_config('local_ai_course_assistant', 'provider') ?: '');
        $sql = "SELECT cfg.id, cfg.provider, cfg.model, cfg.apikey, cfg.apibaseurl, c.shortname
                  FROM {local_ai_course_assistant_course_cfg} cfg
             LEFT JOIN {course} c ON c.id = cfg.courseid
                 WHERE cfg.enabled = 1 AND cfg.apikey <> ''
              ORDER BY c.shortname";
        $found = [];
        foreach ($DB->get_records_sql($sql) as $row) {
            $effective = self::effective_provider((string) $row->provider, (string) $row->model, $siteprovider);
            $owner = self::conflict($effective, secrets::reveal((string) $row->apikey), (string) $row->apibaseurl);
            if ($owner !== '') {
                $found[] = ['shortname' => (string) $row->shortname, 'provider' => $effective, 'owner' => $owner];
            }
        }
        return $found;
    }
}
