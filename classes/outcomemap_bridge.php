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

    /** @var string External function that reports a learner's program-level attainment. */
    private const ATTAINMENT_WS = 'local_outcomemap_get_user_program_attainment';

    /** @var string Capability that external function requires, at SYSTEM context. */
    private const ATTAINMENT_CAPABILITY = 'local/outcomemap:exportattainment';

    /**
     * @var string[] Result states that carry no usable percentage.
     *
     * Only 'calculated' has a number. Every other state means the figure does not
     * exist yet, and each means something different to a learner: no evidence has
     * been collected, a calculation is queued, the figure is out of date, the
     * result is withheld pending release, or the outcome is not assessed in this
     * program at all. On the production site 525 of 546 result rows are
     * insufficient_evidence and 21 are calculated, so this is the normal case
     * rather than an edge, and rendering any of them as 0% would tell almost
     * every learner they had failed an outcome nobody has measured.
     */
    private const STATES_WITHOUT_A_NUMBER = [
        'insufficient_evidence',
        'calculation_pending',
        'stale',
        'not_released',
        'not_assessed',
    ];

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

    /**
     * Whether this site can report program attainment at all.
     *
     * Separate from is_available() because the two capabilities are different and a
     * site can have one without the other: outcome definitions are readable by any
     * course teacher, attainment is not.
     *
     * @return bool
     */
    public static function attainment_available(): bool {
        return class_exists('\\core_external\\external_api')
            && \core_component::get_component_directory('local_outcomemap') !== null
            && \core_external\external_api::external_function_info(self::ATTAINMENT_WS, IGNORE_MISSING) !== false;
    }

    /**
     * One learner's program-level outcome attainment, as local_outcomemap sees it.
     *
     * WHAT THIS IS, AND WHAT SOLA'S OWN MASTERY IS. They are different questions and
     * this does not merge them. SOLA's objective_manager answers "has this learner
     * mastered the objectives of THIS COURSE", from attempts SOLA itself recorded.
     * local_outcomemap answers "where does this learner stand on the PROGRAM's
     * outcomes", pooled across every course that contributes released evidence, from
     * real graded quiz questions mapped to outcomes by a human and approved. The
     * second is evidence a course-scoped tool cannot produce, which is the whole
     * reason to read it.
     *
     * STATE IS NOT A DETAIL. Every outcome carries a state, and only 'calculated'
     * has a percentage. The rest are returned with percent = null and their state
     * intact, and a caller MUST render the state rather than substituting zero.
     * On the production site today 525 of 546 rows are insufficient_evidence
     * against 21 calculated, so a caller that treats null as 0 would tell almost
     * every learner they had failed an outcome nobody has measured yet. This is the
     * same mistake as scoring a Soapbox criterion zero because no camera was on.
     *
     * PRIVACY. The underlying external function requires
     * local/outcomemap:exportattainment at SYSTEM context, which is an
     * administrator or SIS capability that no learner holds. So this deliberately
     * does NOT call the external function as the current user. It refuses outright
     * unless the caller is asking about themselves, or holds the capability. There
     * is no path here that lets one learner read another's attainment.
     *
     * @param int $userid The learner whose attainment is wanted.
     * @param string $programcode Restrict to one program, or empty for all of them.
     * @return array<int, array{code: string, name: string, outcomes: array}> Programs,
     *         each carrying its outcomes with percent (float|null), state and thresholds.
     */
    public static function attainment(int $userid, string $programcode = ''): array {
        global $USER;

        if ($userid <= 0 || !self::attainment_available()) {
            return [];
        }

        // Self, or a holder of the export capability. Nothing else.
        $isself = ((int) $USER->id === $userid);
        if (!$isself && !has_capability(self::ATTAINMENT_CAPABILITY, \context_system::instance())) {
            return [];
        }

        try {
            $raw = \core_external\external_api::call_external_function(
                self::ATTAINMENT_WS,
                ['userid' => $userid, 'programcode' => $programcode],
                false
            );
            if (!empty($raw['error'])) {
                debugging(
                    'local_outcomemap attainment call failed for user ' . $userid . ': '
                        . (string) ($raw['exception']->message ?? 'unknown'),
                    DEBUG_DEVELOPER
                );
                return [];
            }
            $data = $raw['data'] ?? [];
        } catch (\Throwable $e) {
            debugging(
                'local_outcomemap attainment lookup failed for user ' . $userid . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return [];
        }

        $programs = [];
        foreach (($data['programs'] ?? []) as $program) {
            $outcomes = [];
            foreach (($program['outcomes'] ?? []) as $o) {
                $state = (string) ($o['state'] ?? 'not_assessed');
                $outcomes[] = [
                    'itemid' => (int) ($o['itemid'] ?? 0),
                    'code' => (string) ($o['code'] ?? ''),
                    'statement' => (string) ($o['statement'] ?? ''),
                    'shortstatement' => (string) ($o['shortstatement'] ?? ''),
                    'state' => $state,
                    // Null unless the state actually carries a figure. The upstream
                    // values are canonical decimal STRINGS, so they are cast here
                    // once rather than in every caller, and a null stays null: (float)
                    // null is 0.0, which is the value this whole class refuses to
                    // invent.
                    'percent' => self::percent_or_null($state, $o['percentage'] ?? null),
                    'expectedpercent' => self::decimal_or_null($o['expectedpercent'] ?? null),
                    'strongpercent' => self::decimal_or_null($o['strongpercent'] ?? null),
                    'coursesassessed' => (int) ($o['coursesassessed'] ?? 0),
                    'coursestotal' => (int) ($o['coursestotal'] ?? 0),
                    'gradeditems' => (int) ($o['gradeditems'] ?? 0),
                    'timecalculated' => (int) ($o['timecalculated'] ?? 0),
                ];
            }
            $programs[] = [
                'code' => (string) ($program['code'] ?? ''),
                'name' => (string) ($program['name'] ?? ''),
                'outcomes' => $outcomes,
            ];
        }

        return $programs;
    }

    /**
     * A percentage, but only when the state says one exists.
     *
     * @param string $state The outcome result state.
     * @param mixed $value The upstream canonical decimal string, or null.
     * @return float|null
     */
    private static function percent_or_null(string $state, $value): ?float {
        if (in_array($state, self::STATES_WITHOUT_A_NUMBER, true)) {
            return null;
        }
        return self::decimal_or_null($value);
    }

    /**
     * Cast a canonical decimal string, preserving null.
     *
     * @param mixed $value Upstream decimal string, or null.
     * @return float|null
     */
    private static function decimal_or_null($value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    /**
     * The "Your program outcomes" panel for one learner in one course, or null.
     *
     * Returns null, meaning render nothing at all, unless BOTH are true: this course
     * actually sits under a framework that defines outcomes, and the learner has
     * attainment rows to show. A panel on a course with no outcome mapping is an
     * empty box asking a learner to care about something their course does not
     * participate in.
     *
     * The course gate uses outcome_search through fetch(), which the class docblock
     * records as returning the outcomes of every framework visible to the course,
     * including the programs the course belongs to. That is the plugin's own
     * definition of "outcomes for this course" and it is the right gate here: a
     * course inside a degree program receives that program's outcomes, which is
     * exactly the population this panel is about.
     *
     * Every outcome keeps its state and an explanation of that state, because most
     * of them have no number. Of the 546 result rows on the production degrees site
     * today, 525 are insufficient_evidence. A panel that showed those as blanks, or
     * worse as zeroes, would read as a wall of failure on outcomes nobody has
     * measured yet.
     *
     * @param int $userid The learner.
     * @param int $courseid The course whose page the panel would appear on.
     * @return array|null Panel data, or null when nothing should be rendered.
     */
    public static function course_panel(int $userid, int $courseid): ?array {
        if ($userid <= 0 || $courseid <= 0 || $courseid == SITEID) {
            return null;
        }
        if (!self::attainment_available()) {
            return null;
        }
        // Gate one: does this course participate in outcomes at all?
        if (self::fetch($courseid) === []) {
            return null;
        }
        // Gate two: does this learner have anything to show?
        $programs = self::attainment($userid);
        if ($programs === []) {
            return null;
        }

        $out = [];
        $anyoutcome = false;
        foreach ($programs as $program) {
            $outcomes = [];
            foreach ($program['outcomes'] as $o) {
                $anyoutcome = true;
                $outcomes[] = $o + ['explanation' => self::state_explanation($o['state'])];
            }
            if ($outcomes !== []) {
                $out[] = ['code' => $program['code'], 'name' => $program['name'], 'outcomes' => $outcomes];
            }
        }

        return $anyoutcome ? $out : null;
    }

    /**
     * Plain-language reason a given outcome has no percentage.
     *
     * Each state means something different and a learner deserves the difference:
     * "nobody has assessed this yet" is not the same as "your result is calculated
     * but not published". Returning one vague sentence for all of them would be
     * the same as returning none.
     *
     * @param string $state The upstream result state.
     * @return string A translated sentence, empty for a calculated result.
     */
    public static function state_explanation(string $state): string {
        $keys = [
            'calculated' => '',
            'insufficient_evidence' => 'outcomes:state_insufficient_evidence',
            'calculation_pending' => 'outcomes:state_calculation_pending',
            'stale' => 'outcomes:state_stale',
            'not_released' => 'outcomes:state_not_released',
            'not_assessed' => 'outcomes:state_not_assessed',
        ];
        $key = $keys[$state] ?? 'outcomes:state_not_assessed';

        return $key === '' ? '' : branding::str($key);
    }
}
