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
 * Request shaping for the Redash analytics export endpoint (v6.8.6).
 *
 * `redash_export.php` used to emit every section on every call, over all of
 * time, and let any holder of the API key de-anonymize the payload. All three
 * are decided here so they are unit-testable without an HTTP round trip:
 *
 *  - `parse_sections()` turns the `sections` parameter into an allow-list, so a
 *    dashboard that only needs cost data never pulls raw transcript text.
 *  - `resolve_since()` applies a default lookback window, so an absent `since`
 *    no longer means "every row ever recorded".
 *  - `deanonymize_allowed()` gates `anonymize=0` behind its own admin setting
 *    rather than leaving it to the caller.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class redash_export_request {

    /**
     * Every section the export can emit, in response order.
     *
     * `student_usage` and `hotspots` are per-course blocks nested inside
     * `courses`; they are listed separately because they are the expensive
     * parts of a course row and are usually not wanted.
     */
    public const SECTIONS = [
        'courses',
        'student_usage',
        'hotspots',
        'feedback',
        'token_costs',
        'survey_responses',
        'meta_ai',
        'learning_radar_queries',
    ];

    /** Fallback lookback window (days) when the admin setting is unset. */
    public const DEFAULT_WINDOW_DAYS = 90;

    /**
     * Resolve the `sections` parameter into an ordered allow-list.
     *
     * An absent or empty parameter keeps the historical behaviour and returns
     * every section, so existing Redash data sources do not silently lose data
     * on upgrade. `sections=all` is accepted as an explicit synonym.
     *
     * A parameter that names only unknown sections returns an empty array; the
     * caller is expected to reject the request rather than fall back to
     * everything, so that a typo (`tokencosts`) fails loudly instead of
     * quietly exporting the full payload.
     *
     * @param string $raw Raw comma-separated parameter value.
     * @return array List of valid section names in canonical order, deduplicated.
     */
    public static function parse_sections(string $raw): array {
        $raw = trim($raw);
        if ($raw === '' || strtolower($raw) === 'all') {
            return self::SECTIONS;
        }

        $requested = [];
        foreach (explode(',', $raw) as $piece) {
            $name = strtolower(trim($piece));
            if ($name !== '') {
                $requested[$name] = true;
            }
        }

        // Iterate SECTIONS rather than the caller's list so the response order
        // is stable regardless of the order the parameter was written in.
        $out = [];
        foreach (self::SECTIONS as $section) {
            if (isset($requested[$section])) {
                $out[] = $section;
            }
        }
        return $out;
    }

    /**
     * Names in a `sections` parameter that match no known section.
     *
     * Reported back in the error response so a misconfigured data source says
     * which word was wrong instead of just "invalid".
     *
     * @param string $raw Raw comma-separated parameter value.
     * @return array List of unrecognised names, in the order supplied.
     */
    public static function unknown_sections(string $raw): array {
        $raw = trim($raw);
        if ($raw === '' || strtolower($raw) === 'all') {
            return [];
        }

        $out = [];
        foreach (explode(',', $raw) as $piece) {
            $name = strtolower(trim($piece));
            if ($name !== '' && !in_array($name, self::SECTIONS, true) && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Resolve the effective `since` timestamp.
     *
     * The endpoint passes -1 as the "parameter absent" sentinel, which maps to
     * the configured lookback window. An explicit `since=0` still means all of
     * time, so a deliberate backfill remains possible.
     *
     * @param int $raw Caller-supplied value, or a negative sentinel when absent.
     * @param int $now Current Unix timestamp.
     * @param int|null $windowdays Window override; null reads the admin setting.
     * @return int Unix timestamp to filter from; 0 means no lower bound.
     */
    public static function resolve_since(int $raw, int $now, ?int $windowdays = null): int {
        if ($raw > 0) {
            return $raw;
        }
        if ($raw === 0) {
            // Explicit opt-in to an all-time export.
            return 0;
        }

        $days = $windowdays ?? self::window_days();
        if ($days <= 0) {
            // An admin can restore the old all-time default by setting 0 days.
            return 0;
        }

        $since = $now - ($days * DAYSECS);
        return $since > 0 ? $since : 0;
    }

    /**
     * Configured default lookback window in days.
     *
     * @return int Days; 0 or less means "no default window" (all time).
     */
    public static function window_days(): int {
        $configured = get_config('local_ai_course_assistant', 'redash_export_window_days');
        if ($configured === false || trim((string) $configured) === '') {
            return self::DEFAULT_WINDOW_DAYS;
        }
        return (int) $configured;
    }

    /**
     * How a person is represented in the export payload.
     *
     * One definition for every section, because the four sections each rolled
     * their own and two of them leaked: `student_usage` emitted the real
     * `userid` alongside the pseudonym even under anonymize=1, and
     * `learning_radar_queries` emitted a raw `userid` unconditionally. A
     * pseudonym next to the id it is derived from is not a pseudonym.
     *
     * `survey_responses` was a naming bug rather than a leak: it put the raw
     * userid under the key `user_ref` when not anonymizing, so a consumer
     * reading `user_ref` could not tell whether it held a reference or an id.
     * Under this helper `user_ref` always means pseudonym and `userid` always
     * means a real id, so the key alone tells you which you have.
     *
     * @param int $userid Moodle user id.
     * @param bool $anonymize Whether the export is anonymized.
     * @param string|null $firstname Real first name, when the caller has it.
     * @param string|null $lastname Real last name, when the caller has it.
     * @return array Identity fields to merge into the row.
     */
    public static function learner_identity(int $userid, bool $anonymize,
            ?string $firstname = null, ?string $lastname = null): array {
        if ($anonymize) {
            return ['user_ref' => anonymizer::name($userid)];
        }
        $out = ['userid' => $userid];
        if ($firstname !== null || $lastname !== null) {
            $out['firstname'] = $firstname;
            $out['lastname'] = $lastname;
        }
        return $out;
    }

    /**
     * Whether `anonymize=0` (real learner names) is permitted at all.
     *
     * The export authenticates by shared API key, not by a logged-in admin, so
     * without this gate the key alone is enough to pull de-anonymized learner
     * data. Off unless an admin deliberately turns it on.
     *
     * @return bool
     */
    public static function deanonymize_allowed(): bool {
        return !empty(get_config('local_ai_course_assistant', 'redash_allow_deanonymized'));
    }
}
