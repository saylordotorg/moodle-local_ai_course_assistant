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
     * @var string External function that reports the CALLER'S OWN attainment.
     *
     * The learner-safe sibling of ATTAINMENT_WS. It takes no user id, so there is
     * no request it can be made to answer about another person, which is what lets
     * a site expose it to students. Requested upstream and proposed as
     * dta121/moodle-local_outcomemap#9; absent from local_outcomemap 0.9.3 and
     * earlier, which is why every use of it is guarded rather than assumed.
     */
    private const OWN_ATTAINMENT_WS = 'local_outcomemap_get_own_program_attainment';

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
        return self::ws_registered(self::ATTAINMENT_WS) || self::ws_registered(self::OWN_ATTAINMENT_WS);
    }

    /**
     * Can a LEARNER read their own attainment on this site?
     *
     * The distinction that decides whether the program outcomes panel is a real
     * feature or a staff-only curiosity. Without the own-attainment function the
     * only pooled API needs a system capability students do not have, so the panel
     * renders for administrators and for nobody else; the settings page says so,
     * and this is the method that makes that statement checkable rather than a
     * claim in a comment.
     *
     * @return bool
     */
    public static function own_attainment_available(): bool {
        return self::ws_registered(self::OWN_ATTAINMENT_WS);
    }

    /**
     * Is one named external function installed and callable on this site?
     *
     * IGNORE_MISSING rather than a try/catch: a function that is not registered is
     * the expected case here, not an error, since local_outcomemap is optional and
     * the own-attainment function is newer than the releases most sites run.
     *
     * @param string $function Frankenstyle external function name.
     * @return bool
     */
    private static function ws_registered(string $function): bool {
        return self::function_info($function) !== false;
    }

    /**
     * The external-services registry entry for one function, or false.
     *
     * The registry, rather than a hard-coded class name, is what makes calling the
     * implementation directly safe: it is the same record the web service layer
     * resolves, so it tracks an upstream class rename without SOLA knowing one
     * happened.
     *
     * @param string $function Frankenstyle external function name.
     * @return \stdClass|false
     */
    private static function function_info(string $function) {
        if (!class_exists('\\core_external\\external_api')) {
            return false;
        }
        if (\core_component::get_component_directory('local_outcomemap') === null) {
            return false;
        }
        try {
            return \core_external\external_api::external_function_info($function, IGNORE_MISSING);
        } catch (\Throwable $e) {
            // A function declared in db/services.php whose class is missing or
            // malformed. The plugin is optional and beta; an unusable entry is the
            // same to us as an absent one.
            return false;
        }
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
     * PRIVACY, AND WHY THERE ARE TWO WAYS IN. There is no path here that lets one
     * learner read another's attainment, and the two routes protect that property
     * differently rather than one being a relaxed version of the other.
     *
     * A learner asking about themselves goes through
     * local_outcomemap_get_own_program_attainment, which takes no user id at all:
     * it can only ever answer about its caller, so no argument this method could
     * construct would reach somebody else's data.
     *
     * Anyone asking about another user goes through the SIS export function, which
     * takes an arbitrary user id and therefore requires
     * local/outcomemap:exportattainment at SYSTEM context. That is an administrator
     * or integration capability and it must never be granted to a student, since
     * holding it means being able to read anybody's attainment.
     *
     * The own-attainment function does not exist before local_outcomemap 0.9.4. On
     * a site without it a learner asking about themselves gets an empty array, not
     * a fallback to the export function, because falling back would mean either
     * calling it without the capability, which fails, or calling it privileged on a
     * learner's behalf, which would make the capability meaningless.
     *
     * @param int $userid The learner whose attainment is wanted.
     * @param string $programcode Restrict to one program, or empty for all of them.
     * @param int $courseid Restrict to the programs this Moodle course contributes to, or 0
     *        for all of them. Honoured only on the own-attainment path, which is the only
     *        one that accepts it; the SIS export function has no such parameter, so a
     *        privileged caller asking about somebody else gets the unnarrowed report.
     * @return array<int, array{code: string, name: string, outcomes: array}> Programs,
     *         each carrying its outcomes with percent (float|null), state and thresholds.
     */
    public static function attainment(int $userid, string $programcode = '', int $courseid = 0): array {
        global $USER;

        if ($userid <= 0 || !self::attainment_available()) {
            return [];
        }

        // Which function answers this question, if any. Asking about yourself is a
        // different question from asking about someone else, and they are answered
        // by different functions with different guarantees; see the docblock.
        $isself = ((int) $USER->id === $userid);
        $own = $isself ? self::function_info(self::OWN_ATTAINMENT_WS) : false;
        if ($own !== false) {
            $function = self::OWN_ATTAINMENT_WS;
            $info = $own;
            $args = [$programcode, max(0, $courseid)];
        } else {
            $info = has_capability(self::ATTAINMENT_CAPABILITY, \context_system::instance())
                ? self::function_info(self::ATTAINMENT_WS)
                : false;
            if ($info === false) {
                // A learner on a site whose local_outcomemap predates the
                // own-attainment function lands here. Empty, deliberately: the
                // alternative would be reading their data through a privileged path
                // the site has not granted them.
                return [];
            }
            $function = self::ATTAINMENT_WS;
            $args = [$userid, $programcode];
        }

        try {
            // The implementation directly, resolved through the registry, rather
            // than external_api::call_external_function().
            //
            // That wrapper is the HTTP/AJAX entry path and it calls require_sesskey()
            // for any login-required function outside a web service server. This is
            // an in-process read during a page or AJAX render, so whether it works
            // would depend on whether the surrounding request happens to carry a
            // sesskey parameter: it does from the mastery-summary AJAX call and it
            // does not from cron, CLI or a plain page render. A data source that
            // silently returns nothing depending on how the page was reached is a
            // bug waiting to be diagnosed as "the outcomes plugin is broken".
            //
            // Nothing is skipped by going direct. The capability and context checks
            // live inside the function's own execute(), which is what actually
            // enforces them; the wrapper only adds the transport-layer guards that
            // an in-process caller does not need and cannot satisfy.
            $callable = [$info->classname, $info->methodname];
            $data = call_user_func_array($callable, $args);
        } catch (\Throwable $e) {
            debugging(
                'local_outcomemap ' . $function . ' failed for user ' . $userid . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return [];
        }

        if (!is_array($data)) {
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
     * Returns null, meaning render nothing at all, unless the learner has attainment
     * rows in a program THIS COURSE contributes to. A panel on a course with no
     * outcome mapping is an empty box asking a learner to care about something their
     * course does not take part in, and a panel with no rows is worse: it implies
     * the reader has been measured and found empty.
     *
     * Both conditions are one question, answered upstream. The course-to-program
     * mapping lives in local_outcomemap, so the narrowing happens there, against
     * tables SOLA does not read and effective dates SOLA does not track. What comes
     * back is still only this learner's own attainment.
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
        // The kill switch, checked before anything else so that turning it off
        // costs one setting change and no deploy. OFF by default: see the long
        // note in settings.php. The short version is that the panel needs
        // local_outcomemap 0.9.4 or later, and turning it on should be a deliberate
        // act by someone watching the result rather than something that happens by
        // itself when an unrelated plugin is upgraded.
        if (!get_config('local_ai_course_assistant', 'outcomes_panel_enabled')) {
            return null;
        }
        // The learner-safe API, specifically, and not attainment_available().
        //
        // attainment() will happily answer an administrator through the privileged
        // SIS export path, so without this line a site running local_outcomemap
        // 0.9.3 would show the panel to staff and to nobody else: every learner it
        // describes would see nothing, and the people in a position to notice would
        // see something that looked like it worked. That asymmetry is worse than
        // the feature being absent, so the panel is all-or-nothing per site.
        if (!self::own_attainment_available()) {
            return null;
        }
        // One gate, asked of the plugin that owns the answer: what are this learner's
        // results in the programs THIS COURSE contributes to?
        //
        // It used to be two, and the first of them was a defect of exactly the kind
        // this release exists to fix. It called fetch(), which requires
        // local/outcomemap:viewdefinitions, and that capability is granted to
        // editing teachers and managers and NOT to students. So the course gate
        // returned nothing for every learner, and a panel written for learners
        // could only ever have rendered for staff. The capability blocker had a
        // second copy one layer up, in code added to work around the first.
        //
        // The fix is not to elevate the check but to stop asking that question
        // here. A learner may see their own results, including the outcome
        // statements attached to them; browsing the outcome catalogue is a
        // different thing and the two capabilities say so. Narrowing by course
        // happens upstream, where the program-to-course mapping lives, and returns
        // an empty list when the course takes part in no program. That is the same
        // "render nothing" answer the course gate was there to produce, reached
        // without asking a learner for an author's capability.
        $programs = self::attainment($userid, '', $courseid);
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
