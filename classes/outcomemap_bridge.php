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

/**
 * Read approved learning outcomes from local_outcomemap, when that plugin is present.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_course_assistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Adapter over the local_outcomemap public API.
 *
 * Why this exists. SOLA's own objective discovery scrapes course content: it walks
 * the course summary, then each section summary, then every visible Page and Book,
 * running each through format_text() looking for something bullet-shaped. That is
 * expensive and it guesses. In production it has produced six rows across both
 * Saylor sites, on one course, and all six are course NAMES rather than objectives,
 * because the scraper falls back to reading bullets from the top of a document when
 * no heading pattern matches.
 *
 * local_outcomemap holds the same information as curated data: human-authored,
 * versioned, approved outcome statements bound to a Moodle course. Where a course
 * has them, they are strictly better than anything the scraper can infer, and
 * reading them costs one query instead of a walk over every module in the course.
 *
 * Coupling. This class is the ONLY place SOLA touches local_outcomemap. It calls
 * the plugin's declared public API under \local_outcomemap\api and never its
 * \local_outcomemap\local\... internals or its database tables directly, so a
 * change to the plugin's storage cannot break SOLA silently. Every entry point is
 * guarded by class_exists()/method_exists(), because the plugin is optional, is
 * absent from most installs, and is still MATURITY_BETA on the sites that do
 * have it.
 *
 * Scope caveat, deliberately not filtered. outcome_search scopes by Moodle context
 * and returns the approved outcomes of every framework visible to that course: the
 * course's own catalog framework, any institution-level framework, and the
 * frameworks of programs the course belongs to. So a course inside a degree program
 * will also receive that program's outcomes. That is the plugin's definition of
 * "outcomes for this course" and this adapter does not second-guess it; the public
 * API exposes no owner-type filter, and reaching past it to apply one would mean
 * querying the plugin's tables, which is exactly the coupling this class avoids.
 */
final class outcomemap_bridge {

    /** @var string Fully qualified name of the outcome search API. */
    private const SEARCH_API = '\\local_outcomemap\\api\\outcome_search';

    /** @var string Capability the outcome search API requires of the caller. */
    private const VIEW_CAPABILITY = 'local/outcomemap:viewdefinitions';

    /** @var string Prefix written into objs.external_ref, ahead of the version UUID. */
    public const REF_PREFIX = 'outcomemap:';

    /** @var int Upper bound on imported outcomes. The upstream API itself caps at 200. */
    private const MAX_OUTCOMES = 200;

    /**
     * Is the local_outcomemap outcome-search API installed and callable?
     *
     * Checked per call rather than cached: a site can install or remove the plugin
     * between requests, and this is two reflection lookups, not a query.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return class_exists(self::SEARCH_API)
            && method_exists(self::SEARCH_API, 'search');
    }

    /**
     * Fetch approved, currently-effective outcomes for a Moodle course.
     *
     * Returns rows in the shape objective_manager::import_batch() consumes, so the
     * caller needs no knowledge of local_outcomemap at all.
     *
     * Returns an empty array, never an exception, whenever the outcomes cannot be
     * read: plugin absent, course gone, caller lacks the capability, or the API
     * throws. An empty result simply means detect_best_source() moves on to the
     * next candidate, which is the pre-existing behaviour.
     *
     * @param int $courseid Moodle course id.
     * @return array<int, array{title: string, description: string, code: string, external_ref: string}>
     */
    public static function fetch(int $courseid): array {
        if ($courseid <= 0 || $courseid == SITEID || !self::is_available()) {
            return [];
        }

        try {
            $context = \context_course::instance($courseid, IGNORE_MISSING);
            if (!$context) {
                return [];
            }

            // outcome_search::search() calls require_capability() internally and
            // throws when it fails. Objective discovery runs on an admin page and
            // from background callers, and neither should turn a missing read
            // capability into a fatal, so the check is made here first.
            if (!has_capability(self::VIEW_CAPABILITY, $context)) {
                return [];
            }

            $outcomes = call_user_func(
                [self::SEARCH_API, 'search'],
                $context,
                '',
                null,
                self::MAX_OUTCOMES
            );
        } catch (\Throwable $e) {
            // The plugin is optional and beta. A failure to read it is never a
            // reason to fail objective discovery.
            debugging(
                'local_outcomemap outcome lookup failed for course ' . $courseid . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return [];
        }

        if (!is_array($outcomes)) {
            return [];
        }

        $items = [];
        foreach ($outcomes as $outcome) {
            $row = self::to_objective_row($outcome);
            if ($row !== null) {
                $items[] = $row;
            }
        }
        return $items;
    }

    /**
     * Convert one outcome value object into an import_batch() row.
     *
     * Properties are read defensively rather than by type hint. The DTO is the
     * plugin's own class, it is beta, and SOLA must not fatal if a field it expects
     * is renamed in a later release.
     *
     * @param mixed $outcome An outcome DTO from the search API.
     * @return array{title: string, description: string, code: string, external_ref: string}|null
     */
    private static function to_objective_row($outcome): ?array {
        if (!is_object($outcome)) {
            return null;
        }

        $statement = trim((string) ($outcome->statement ?? ''));
        $short = trim((string) ($outcome->shortstatement ?? ''));

        // objs.title is NOT NULL and 255 chars; objs.description is unbounded text.
        // Prefer the curated short statement for the title and keep the full
        // statement as the description, so nothing is lost when the title is cut.
        $title = $short !== '' ? $short : $statement;
        if ($title === '') {
            return null;
        }

        $versionuuid = trim((string) ($outcome->versionuuid ?? ''));

        return [
            // The VERSION uuid, not the outcome uuid: it identifies the exact
            // revision of the wording that was imported, so a later edit upstream
            // is visibly a different reference rather than silently the same one.
            'external_ref' => $versionuuid !== '' ? self::REF_PREFIX . $versionuuid : '',
            'title' => $title,
            'description' => $statement,
            'code' => trim((string) ($outcome->code ?? '')),
        ];
    }
}
