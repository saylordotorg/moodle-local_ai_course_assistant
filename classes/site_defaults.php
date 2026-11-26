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
 * Neutral defaults for every site, and Saylor's own values kept for Saylor's own sites (8.4.1).
 *
 * Until 8.4.0 several settings shipped with Saylor's values as their defaults (institution and assistant names,
 * contact and privacy links, the transcript URL pattern, the footer courses link, one line of the external
 * resources allowlist), and the code fell back to them when a setting was blank. Every site that installed the
 * plugin therefore showed Saylor's name and links unless an administrator noticed and changed them. 8.4.1 ships
 * neutral defaults and neutral runtime fallbacks (see branding), and migrate() moves each site once:
 *
 * - On Saylor's own sites (is_saylor_host(): the host is saylor.org, sylr.org or a subdomain of either), a setting
 *   that was never set, or that is blank where blank used to mean the Saylor value, is written with the old Saylor
 *   value, so nothing visibly changes there.
 * - On any other site, a setting that still holds exactly the old Saylor default was carried in by the default and
 *   never chosen, so it is replaced with the neutral default. Any other value was chosen and stays.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell & David Ta / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class site_defaults {

    /** @var string Plugin component. */
    private const COMPONENT = 'local_ai_course_assistant';

    /** @var string The external resources allowlist every site gets by default. */
    public const NEUTRAL_RESOURCES_ALLOWLIST = "Wikipedia (en.wikipedia.org)\n"
        . "Khan Academy (khanacademy.org)\n"
        . "OER Commons (oercommons.org)\n"
        . "OpenStax (openstax.org)\n"
        . "MIT OpenCourseWare (ocw.mit.edu)";

    /** @var string[] The values these settings shipped with (or fell back to) until 8.4.0. */
    public const SAYLOR_VALUES = [
        'institution_name' => 'Saylor University',
        'institution_short_name' => 'Saylor',
        'display_name' => 'Saylor Online Learning Assistant',
        'contact_email' => 'contact@saylor.org',
        'dpo_email' => 'dpo@saylor.org',
        'privacy_external_url' => 'https://www.saylor.org/privacypolicy/',
        'rag_transcript_url_pattern' => 'resources\\.saylor\\.org/transcripts/[^"\'\\s>]+',
        'footer_courses_text' => 'Explore open online courses at saylor.org',
        'footer_courses_url' => 'https://www.saylor.org',
        'external_resources_allowlist' => self::NEUTRAL_RESOURCES_ALLOWLIST . "\nSaylor Academy (learn.saylor.org)",
    ];

    /** @var string[] The defaults 8.4.1 ships for the same settings. */
    public const NEUTRAL_VALUES = [
        'institution_name' => '',
        'institution_short_name' => '',
        'display_name' => '',
        'contact_email' => '',
        'dpo_email' => '',
        'privacy_external_url' => '',
        'rag_transcript_url_pattern' => '',
        'footer_courses_text' => '',
        'footer_courses_url' => '',
        'external_resources_allowlist' => self::NEUTRAL_RESOURCES_ALLOWLIST,
    ];

    /** @var string[] Settings whose blank value used to mean the Saylor value (the code fell back to it). */
    public const BLANK_MEANT_SAYLOR = ['institution_name', 'institution_short_name', 'display_name', 'contact_email',
        'dpo_email', 'privacy_external_url', 'rag_transcript_url_pattern'];

    /** @var string[] Hosts (and their subdomains) that are Saylor's own sites. */
    public const SAYLOR_DOMAINS = ['saylor.org', 'sylr.org'];

    /**
     * Whether a site address is one of Saylor's own (learn.saylor.org, degrees.saylor.org, dev.sylr.org, ...).
     *
     * @param string|null $wwwroot the site address; null means this site's
     * @return bool
     */
    public static function is_saylor_host(?string $wwwroot = null): bool {
        global $CFG;
        $host = strtolower((string) parse_url($wwwroot ?? (string) $CFG->wwwroot, PHP_URL_HOST));
        foreach (self::SAYLOR_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Move this site's settings once (upgrade step 2026112610, and install): see the class comment.
     *
     * @param string|null $wwwroot the site address; null means this site's (tests pass one)
     * @return array<string, string> setting => 'saylor' (old value written) or 'neutral' (old default replaced)
     */
    public static function migrate(?string $wwwroot = null): array {
        $saylor = self::is_saylor_host($wwwroot);
        $changed = [];
        foreach (self::SAYLOR_VALUES as $name => $old) {
            $current = get_config(self::COMPONENT, $name);
            if ($saylor) {
                $blank = $current === false
                    || (in_array($name, self::BLANK_MEANT_SAYLOR, true) && trim((string) $current) === '');
                if ($blank) {
                    set_config($name, $old, self::COMPONENT);
                    $changed[$name] = 'saylor';
                }
            } else if ($current !== false && self::same($current, $old)) {
                set_config($name, self::NEUTRAL_VALUES[$name], self::COMPONENT);
                $changed[$name] = 'neutral';
            }
        }
        return $changed;
    }

    /**
     * Whether a stored value is the old default (line endings and surrounding space ignored).
     *
     * @param string $current
     * @param string $old
     * @return bool
     */
    private static function same(string $current, string $old): bool {
        $norm = static fn(string $v): string => trim(str_replace("\r\n", "\n", $v));
        return $norm($current) === $norm($old);
    }
}
