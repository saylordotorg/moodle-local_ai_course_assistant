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
     * @var string The only result state that carries a percentage.
     *
     * Everything else means the figure does not exist yet, and each means
     * something different to a learner: no evidence collected, a calculation
     * queued, a figure out of date, a result withheld pending release, or an
     * outcome not assessed in this program at all.
     *
     * "No number" is the normal case rather than an edge. When last measured, on
     * the production degrees site on 2026-09-22, 525 of 546 result rows were
     * insufficient_evidence against 21 calculated. That is a reading with a date
     * on it, not a property of the system: it will drift as courses are assessed,
     * and the design holds whatever the ratio is.
     */
    private const STATE_CALCULATED = 'calculated';

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
     * Is attainment readable by ANYBODY on this site?
     *
     * A question about what is installed, not about who may ask. It returns true
     * if either attainment function is registered, and says nothing about whether
     * the current user can call it: the export function needs a system capability
     * and the own-attainment function needs none beyond being the subject. Callers
     * that care about a learner want own_attainment_available() below.
     *
     * Separate from is_available(), which answers the same question about outcome
     * DEFINITIONS. A site can have one without the other, and the capabilities
     * behind them differ too: definitions are readable by a course teacher,
     * anybody's attainment is not.
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
     * COULD render for administrators and for nobody else.
     *
     * course_panel() gates on this rather than on attainment_available() precisely
     * so that it does not: on a site without the learner-safe function the panel is
     * hidden from everyone, staff included, which is what the settings page
     * promises. This method is what makes that promise checkable rather than a
     * claim in a comment.
     *
     * @return bool
     */
    public static function own_attainment_available(): bool {
        // === true, not !== null. The helper is deliberately THREE-state: null for
        // absent, false for present but unable to narrow by course, true for
        // usable. Treating "not null" as available reads the false case as a yes,
        // which is the precise failure this guard exists to prevent, and it is
        // what the first version of this line did.
        return self::own_attainment_takes_a_course() === true;
    }

    /**
     * Does the installed own-attainment function accept a courseid, and is it there at all?
     *
     * Returns null when the function is absent, true or false for whether it
     * declares courseid.
     *
     * WHY THIS IS NOT JUST ws_registered(). local_outcomemap merged the first
     * version of this function without the course filter, so a site can now have
     * a registered function that cannot narrow by course. Calling it anyway is
     * worse than it sounds: PHP discards surplus arguments to a userland function
     * silently, so the courseid would vanish with no error and the learner would
     * see every programme they have results in, on every course they open. The
     * requirement was that the panel appears only where it means something, and
     * that failure mode breaks it quietly, which is the worst way to break it.
     *
     * This asks the external-services registry what the installed function
     * actually declares rather than assuming a version number implies a shape.
     *
     * @return bool|null True if courseid is accepted, false if not, null if absent.
     */
    private static function own_attainment_takes_a_course(): ?bool {
        $info = self::function_info(self::OWN_ATTAINMENT_WS);
        if ($info === false) {
            return null;
        }

        $desc = $info->parameters_desc ?? null;
        if (!$desc || !isset($desc->keys) || !is_array($desc->keys)) {
            return false;
        }

        return array_key_exists('courseid', $desc->keys);
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
     * A caller that treats null as 0 tells a learner they failed an outcome nobody
     * has measured yet. It is the same mistake as scoring a Soapbox criterion zero
     * because no camera was on, and it is not a rare case: 525 of 546 rows were
     * insufficient_evidence when last measured (production degrees, 2026-09-22).
     * Treat that figure as a dated reading rather than a constant.
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
     * The own-attainment function does not exist before local_outcomemap 0.9.4,
     * and the first 0.9.4 is not enough either: it was merged without the course
     * filter, and its pooling still required the export capability, so it raised
     * for exactly the learners it was written for. own_attainment_takes_a_course()
     * is what decides, by asking the registry what the installed function declares
     * rather than trusting a version number.
     *
     * On a site where it is absent or too old, a learner asking about themselves
     * gets an empty array, not a fallback to the export function. Falling back
     * would mean either calling it without the capability, which fails, or calling
     * it privileged on a learner's behalf, which would make the capability
     * meaningless.
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
            // Only pass what the installed function declares. See
            // own_attainment_takes_a_course(): a surplus argument is dropped in
            // silence, so a version without the filter would answer about every
            // programme rather than raising.
            $args = self::own_attainment_takes_a_course() === true
                ? [$programcode, max(0, $courseid)]
                : [$programcode];
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
            // What is given up, precisely, because "nothing is skipped" was the
            // first version of this comment and it was not true. The wrapper also
            // runs clean_returnvalue() against execute_returns(), and going direct
            // does not. That is a deliberate trade rather than an oversight: the
            // parsing below reads every field defensively with a default, whereas
            // clean_returnvalue() THROWS on a shape it does not expect, and a
            // throw here means the panel silently disappears the day a third-party
            // plugin adds a field. Degrading to a missing value beats degrading to
            // a missing panel.
            //
            // The checks that matter are not skipped. The capability and context
            // checks live inside the function's own execute(), which is what
            // actually enforces them, and validate_parameters() runs there too.
            // What the wrapper adds beyond that is transport-layer guards an
            // in-process caller does not need and cannot satisfy.
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
        // is_array() on both levels: the upstream plugin is optional and beta, and
        // foreach over a scalar is a PHP warning plus an empty result rather than a
        // clean refusal. A shape we do not recognise is "no data", not a notice in
        // the log of every learner who opens the widget.
        $rawprograms = $data['programs'] ?? [];
        if (!is_array($rawprograms)) {
            return [];
        }
        foreach ($rawprograms as $program) {
            if (!is_array($program)) {
                continue;
            }
            $outcomes = [];
            $rawoutcomes = $program['outcomes'] ?? [];
            foreach (is_array($rawoutcomes) ? $rawoutcomes : [] as $o) {
                if (!is_array($o)) {
                    continue;
                }
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
        // Allowlist, not a deny-list. The deny-list version failed OPEN: a state
        // this plugin has not heard of, added by a later local_outcomemap, would
        // have had its figure printed next to an explanation reading "not
        // assessed", because state_explanation() defaults the other way. Printing
        // a number we cannot describe is the one outcome this class exists to
        // prevent, so an unrecognised state carries no figure.
        if ($state !== self::STATE_CALCULATED) {
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
     * of them have no number: 525 of 546 rows were insufficient_evidence when last
     * measured (production degrees, 2026-09-22). A panel that showed those as
     * blanks, or worse as zeroes, would read as a wall of failure on outcomes
     * nobody has measured yet.
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
        // a local_outcomemap whose own-attainment function accepts a courseid,
        // and turning it on should be a deliberate
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
        $programs = self::cached_attainment($userid, $courseid);
        if ($programs === []) {
            return null;
        }

        $out = [];
        $anyoutcome = false;
        foreach ($programs as $program) {
            $outcomes = [];
            foreach ($program['outcomes'] as $o) {
                $anyoutcome = true;
                // A row can arrive claiming 'calculated' with no number, which is
                // upstream contradicting itself. state_explanation() returns an
                // empty string for calculated, on the reasonable assumption that a
                // figure needs no excuse, so without this the learner would get
                // "No result yet" and no reason at all: the one blank this panel
                // exists to prevent. We do not know WHY it is missing, so the
                // explanation says only that, rather than picking a cause.
                $state = $o['percent'] === null && $o['state'] === self::STATE_CALCULATED
                    ? 'unavailable'
                    : $o['state'];
                $outcomes[] = $o + [
                    'explanation' => self::state_explanation($state),
                    'statelabel' => self::state_label($state),
                ];
            }
            if ($outcomes !== []) {
                $out[] = ['code' => $program['code'], 'name' => $program['name'], 'outcomes' => $outcomes];
            }
        }

        return $anyoutcome ? $out : null;
    }

    /**
     * The short text that stands where a percentage would.
     *
     * One generic label for every state was a small lie told five times. "No
     * result yet" sat next to "Your result has been worked out but is not
     * published yet", and next to "your result is out of date", both of which say
     * a result exists. A learner reading the column and then the sentence under it
     * got two different answers.
     *
     * @param string $state The upstream result state.
     * @return string A short translated label.
     */
    public static function state_label(string $state): string {
        $keys = [
            'insufficient_evidence' => 'outcomes:label_insufficient_evidence',
            'calculation_pending' => 'outcomes:label_calculation_pending',
            'stale' => 'outcomes:label_stale',
            'not_released' => 'outcomes:label_not_released',
            'not_assessed' => 'outcomes:label_not_assessed',
            'unavailable' => 'outcomes:no_percentage_yet',
        ];

        // An unrecognised state falls back to the most cautious wording rather than
        // to a claim about assessment, because we do not know which it is.
        return branding::str($keys[$state] ?? 'outcomes:no_percentage_yet');
    }

    /**
     * attainment(), through a five-minute cache, for the panel path only.
     *
     * The panel is drawn every time a learner opens the Progress tab, and the read
     * behind it walks every course where they hold a current result and builds a
     * release-gated report for each. On a seeded fixture that measured 22 queries
     * and about 8ms for a single contributing course, and it is linear in a degree
     * learner's history rather than in the course they are looking at, so the
     * number nobody has measured is the one that matters: a learner three years
     * into a programme.
     *
     * Five minutes, by TTL rather than by invalidation. The figures move when a
     * batch calculation runs inside local_outcomemap, which this plugin is not
     * told about and should not subscribe to. The panel is a view of another
     * system's record, not the record, and a learner seeing a figure five minutes
     * late cannot act differently for it.
     *
     * Only this path is cached. attainment() itself stays uncached, because its
     * other caller is an administrator reading one named learner deliberately, and
     * a stale answer there is a support ticket rather than a saved query.
     *
     * @param int $userid The learner.
     * @param int $courseid The course being viewed.
     * @return array Programs, as attainment() returns them.
     */
    private static function cached_attainment(int $userid, int $courseid): array {
        // Underscore, not a colon: simplekeys allows only alphanumerics and
        // underscores, and a colon raises a coding_exception on every set().
        $key = $userid . '_' . $courseid;

        // The cache is an optimisation, so it is not allowed to be the thing that
        // breaks the panel. cache::make() throws if the definition is missing,
        // which happens on a site mid-upgrade or with a cache store that has gone
        // away, and this runs inside the ajax call that draws the whole Progress
        // tab. Falling back to the uncached read costs about 8ms per contributing
        // course; letting it throw costs the tab.
        $cache = null;
        try {
            $cache = \cache::make('local_ai_course_assistant', 'outcomesattainment');
            $hit = $cache->get($key);
            if (is_array($hit)) {
                return $hit;
            }
        } catch (\Throwable $e) {
            debugging(
                'local_ai_course_assistant: outcomes attainment cache unavailable, reading '
                    . 'through: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            $cache = null;
        }

        $programs = self::attainment($userid, '', $courseid);

        if ($cache !== null) {
            try {
                $cache->set($key, $programs);
            } catch (\Throwable $e) {
                debugging(
                    'local_ai_course_assistant: could not store outcomes attainment: '
                        . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        return $programs;
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
            'unavailable' => 'outcomes:state_unavailable',
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
