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
 * Site-level support mode: the assistant on pages that are not a course.
 *
 * The problem this solves: a learner who cannot find the syllabus, cannot submit
 * an assignment, or cannot get logged in has no way to reach the assistant,
 * because it only renders inside a course. That is the population with the most
 * urgent questions and the least ability to self-serve.
 *
 * WHY THIS BINDS TO A REAL COURSE RATHER THAN SITEID
 *
 * The obvious implementation is to render on non-course pages and pass SITEID as
 * the course id. That was measured against the code and rejected. SITEID is not
 * a free slot; it already carries at least five distinct meanings:
 *
 *   1. The FAQ chunk namespace (faq_manager writes modtype='faq' rows at SITEID,
 *      and content_indexer carves them out of three separate predicates so a
 *      site-course reindex cannot delete them).
 *   2. The "no course supplied" null object in conversation_manager, tts,
 *      transcribe, soapbox_transcribe and voyage_reranker's spend log.
 *   3. The booking code for background embedding, rerank and benchmark spend.
 *   4. Moodle's own front-page course.
 *   5. The exclusion floor in embedding_migration, which enumerates courses with
 *      `courseid > SITEID` -- so any chunk written at SITEID is structurally
 *      invisible to an embedding-model migration, silently and forever.
 *
 * Two of those are not merely untidy, they are wrong answers. conversation_manager
 * has a UNIQUE index on (userid, courseid) and ALREADY creates a SITEID
 * conversation: record_meta_query() (Learning Radar) writes user/assistant rows
 * against it. get_messages() filters on role, not on interaction type, so a
 * support turn at SITEID would load the learner's Learning Radar prose as its
 * chat history -- into the prompt and into the drawer. And spend_guard::get_cap()
 * is keyed on courseid, so every user's support traffic across the whole site
 * would meter against the site course's single per-course cap.
 *
 * Binding to an administrator-designated REAL course avoids all of it by
 * construction rather than by special-casing. The conversation gets its own row,
 * spend meters against a course an administrator can configure, the prompt cache
 * key stops collapsing every non-course surface onto one entry per user, the
 * embedding migration can see the chunks, and -- the decisive practical point --
 * every existing `require_capability(':use', context_course::instance($courseid))`
 * site keeps working against a context that genuinely exists. There are about
 * forty-five of those, including get_config and get_history, which the drawer
 * calls before it can render anything at all.
 *
 * WHAT AN ADMINISTRATOR HAS TO DO
 *
 * Point `support_courseid` at a real, visible course holding the getting-started
 * and onboarding material, and tick `support_enabled`. Retrieval then reaches
 * that course's already-indexed content plus the site FAQ, with no new indexing
 * pipeline, exactly as supplemental_sources does for course-to-course reach.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class support_mode {

    /**
     * Capability that lets an authenticated user open the assistant off-course.
     *
     * Deliberately a NEW capability at CONTEXT_SYSTEM rather than granting the
     * existing :use capability to the 'user' archetype. Archetypes apply at every
     * context, so adding 'user' => CAP_ALLOW to :use would switch the widget on
     * in every course on the site by inheritance and defeat the per-course
     * opt-out that default_course_mode exists to provide.
     */
    public const CAPABILITY = 'local/ai_course_assistant:usesupport';

    /** @var int|null Per-request memo for the resolved course id. */
    private static ?int $resolved = null;

    /**
     * The designated support course id, or 0 when support mode is unusable.
     *
     * Validated the same way supplemental_sources validates its list: the course
     * must exist and be visible. A hidden course is hidden from learners, and
     * surfacing its content through the assistant would route around that.
     *
     * Returns 0 rather than throwing when the setting names a course that has
     * since been deleted or hidden, so a stale setting degrades to "support mode
     * is off" instead of breaking every page render on the site.
     *
     * @return int
     */
    public static function course_id(): int {
        global $DB;

        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $raw = (int) get_config('local_ai_course_assistant', 'support_courseid');

        // SITEID is refused explicitly. Accepting it would reintroduce every
        // collision documented in this class's header through the back door of
        // an administrator typing 1 into the box.
        if ($raw <= 0 || $raw == SITEID) {
            self::$resolved = 0;
            return 0;
        }

        $exists = $DB->record_exists_select('course', 'id = :id AND visible = 1', ['id' => $raw]);
        self::$resolved = $exists ? $raw : 0;
        return self::$resolved;
    }

    /**
     * Whether support mode is switched on and correctly configured.
     *
     * Both halves are required. The checkbox alone is not enough, because a
     * course id that no longer resolves would otherwise put the widget on every
     * page of the site pointed at nothing.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('local_ai_course_assistant', 'support_enabled')
            && self::course_id() > 0;
    }

    /**
     * Whether the current user may use the assistant outside a course.
     *
     * Guests are excluded here rather than left to the capability. Phase 1 is the
     * logged-in case only: an unauthenticated streaming LLM endpoint needs
     * per-IP rate limiting and an anonymous identity story, and neither exists
     * yet. The capability is also deliberately not granted to the 'guest'
     * archetype, so this is belt and braces on purpose.
     *
     * @return bool
     */
    public static function can_use(): bool {
        if (!isloggedin() || isguestuser()) {
            return false;
        }
        return has_capability(self::CAPABILITY, \context_system::instance());
    }

    /**
     * Whether a request carrying this course id is a support turn.
     *
     * Note this is true when the learner is genuinely inside the designated
     * support course as a course, too. That is correct: the same content, the
     * same corpus and the same conservative integrity scoping should apply
     * whether they arrived from the dashboard or from the course page.
     *
     * @param int $courseid
     * @return bool
     */
    public static function is_support_turn(int $courseid): bool {
        return $courseid > 0 && self::is_enabled() && $courseid === self::course_id();
    }

    /**
     * Course id to scope an academic-integrity check to, for this request.
     *
     * quiz_lock::active_attempt() adds `AND cm.course = :courseid` under its
     * default course scope. The designated support course holds no quizzes, so
     * passing its id would scope the exam lock to a course with nothing to find
     * and the lock would never fire -- a learner mid-exam could open the
     * dashboard in a second tab and get an unlocked assistant. quiz_lock's own
     * docblock says to pass 0 for a surface with no course context, which falls
     * back to the site-wide test: the conservative direction for an integrity
     * control.
     *
     * Callers pass their real course id; this returns 0 only on a support turn.
     *
     * @param int $courseid
     * @return int
     */
    public static function integrity_scope(int $courseid): int {
        return self::is_support_turn($courseid) ? 0 : $courseid;
    }

    /**
     * Whether the off-course widget should render on the page being built.
     *
     * Single source of truth, called by both the footer injector that renders the
     * widget and the before_http_headers hook that sends its Content-Security-
     * Policy. Those two have drifted before -- the CSP mirror's docblock claims
     * checks it does not make -- and a page that renders the widget without the
     * CSP loses the defence added after the injected-widget incident.
     *
     * Read-only and side-effect-free, because the CSP hook runs early and must be
     * able to ask the same question before the page has finished setting up.
     *
     * @param \moodle_page $page
     * @return bool
     */
    public static function renders_here(\moodle_page $page): bool {
        if (!get_config('local_ai_course_assistant', 'enabled')) {
            return false;
        }
        if (!self::is_enabled() || !self::can_use()) {
            return false;
        }

        $context = $page->context ?? null;
        if (!$context) {
            return false;
        }

        // Course and module contexts belong to the ordinary per-course path, which
        // has its own gate. The one exception is the site course: it is a course
        // context, but it is the front page, and the per-course path returns early
        // on it. Anything above a course -- user (dashboard, profile) and system --
        // is ours.
        $iscoursecontext = $context->contextlevel === CONTEXT_COURSE
            || $context->contextlevel === CONTEXT_MODULE;
        if ($iscoursecontext) {
            $coursecontext = $context->contextlevel === CONTEXT_MODULE
                ? $context->get_course_context(false)
                : $context;
            // get_course_context(false) returns false rather than throwing when
            // there is no course ancestor; treat that as "not ours" either way.
            if (!$coursecontext || (int) $coursecontext->instanceid !== (int) SITEID) {
                return false;
            }
        } else if ($context->contextlevel !== CONTEXT_USER
                && $context->contextlevel !== CONTEXT_SYSTEM) {
            return false;
        }

        // Never on an administration page, and never on the plugin's own pages --
        // the same two exclusions the per-course gate makes, for the same reasons.
        if (($page->pagelayout ?? '') === 'admin') {
            return false;
        }

        // Path comparisons are made against the wwwroot-relative path, not against
        // moodle_url::get_path(). get_path() includes the subdirectory of a
        // subdirectory install ("/moodle/local/..."), so an anchored prefix test
        // on it silently matches nothing there -- which is how both of these
        // exclusions first shipped broken, and is why the test harness (whose
        // wwwroot has a subdirectory) is the environment that catches it.
        $relative = '';
        if ($page->url instanceof \moodle_url) {
            try {
                $relative = $page->url->out_as_local_url(false);
            } catch (\moodle_exception $e) {
                // Not a local URL. Nothing we render belongs on one, so refuse
                // rather than guess; renders_here() must never throw, because it
                // is called from a hook on every page of the site.
                return false;
            }
        }

        if ($relative !== '' && strpos($relative, '/local/ai_course_assistant/') === 0) {
            return false;
        }

        // Not on the login or signup flow. before_footer_html_generation fires
        // there too, and a chat drawer over a login form is both useless (the
        // visitor is not authenticated yet, so can_use() has already refused) and
        // an invitation to type a password into it.
        if ($relative !== '' && strpos($relative, '/login/') === 0) {
            return false;
        }

        return true;
    }

    /**
     * Enforce access for a request that may be a support turn.
     *
     * The per-course :use capability is the wrong question on a support turn: a
     * learner who opened the assistant from their dashboard is by definition not
     * enrolled in the support course and holds no role in it, so :use there is
     * false for exactly the people the feature is for.
     *
     * Deliberately NOT applied to all ~45 sites that enforce :use. The ones wired
     * to this are the ones a support conversation needs -- boot, history, the
     * turn itself, and the feedback controls attached to it. Everything else
     * (quiz generation, study plans, flashcards, voice, soapbox, analytics) stays
     * course-only and refuses on a support turn, which is correct: those features
     * are about course material, and there is none here.
     *
     * @param int $courseid Course id the request carries.
     * @param \context $context Course context to fall back to.
     * @return void
     */
    public static function require_use(int $courseid, \context $context): void {
        if (self::is_support_turn($courseid) && self::can_use()) {
            return;
        }
        require_capability('local/ai_course_assistant:use', $context);
    }

    /**
     * Non-throwing form of {@see require_use()}, for callers that branch.
     *
     * @param int $courseid
     * @param \context $context
     * @return bool
     */
    public static function can_use_in(int $courseid, \context $context): bool {
        if (self::is_support_turn($courseid) && self::can_use()) {
            return true;
        }
        return has_capability('local/ai_course_assistant:use', $context);
    }

    /**
     * Clear the per-request memo. Tests only.
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::$resolved = null;
    }
}
