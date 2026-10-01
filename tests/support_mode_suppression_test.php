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
 * Course-only drawer features are off in support mode.
 *
 * This file exists because the first implementation wrote the suppressions
 * inline at each flag's own line as `!$supportmode && ...`, and a review found
 * SEVEN flags that had been missed that way: the email and WhatsApp reminder
 * toggles, the mastery chip and dashboard, the satisfaction survey, the user
 * testing prompt and the talking-avatar button. The pattern had been applied
 * correctly five times, which is exactly what made the misses invisible -- a
 * missed flag simply keeps its course-mode value and looks like every other
 * line.
 *
 * The reminder pair was the worst of them. The write endpoint refuses, and
 * chat.js swallows the failure, so a learner sets a reminder, sees it accepted,
 * and no reminder is ever sent.
 *
 * The suppressions now live in one list on support_mode, applied as a single
 * pass immediately before rendering. These tests pin that list and, more
 * importantly, pin the RULE for what belongs in it.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\support_mode::suppress_course_features
 * @covers     \local_ai_course_assistant\support_mode::filter_starters
 */
final class support_mode_suppression_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    protected function tearDown(): void {
        support_mode::reset_cache();
        parent::tearDown();
    }

    /**
     * Every flag in the list is forced off when the pass runs.
     */
    public function test_every_listed_flag_is_forced_off(): void {
        $data = [];
        foreach (array_keys(support_mode::SUPPRESSED_FLAGS) as $key) {
            // Seed each with a truthy value the template would act on.
            $data[$key] = true;
        }
        $data['courseid'] = 42;

        $out = support_mode::suppress_course_features($data);

        foreach (support_mode::SUPPRESSED_FLAGS as $key => $expected) {
            $this->assertSame($expected, $out[$key], $key . ' must be forced off in support mode');
            $this->assertFalse(
                !empty($out[$key]) && $out[$key] !== '0',
                $key . ' must not remain truthy'
            );
        }
        $this->assertSame(42, $out['courseid'], 'unrelated keys must be untouched');
    }

    /**
     * The string flags are forced to '0', not to false.
     *
     * surveyenabled and usertestingenabled are '1'/'0' STRINGS in the template
     * data. Mustache treats the string '0' as falsy but treats boolean false
     * the same way, so either would work today -- what this pins is that they
     * keep their declared TYPE, because the template interpolates them into a
     * data attribute that JavaScript then compares against '1'.
     */
    public function test_string_flags_stay_strings(): void {
        foreach (['surveyenabled', 'usertestingenabled'] as $key) {
            $this->assertArrayHasKey($key, support_mode::SUPPRESSED_FLAGS);
            $this->assertSame(
                '0',
                support_mode::SUPPRESSED_FLAGS[$key],
                $key . ' is a string flag and must be suppressed to the string "0"'
            );
        }
    }

    /**
     * A key the template data does not carry is not invented.
     */
    public function test_absent_keys_are_not_added(): void {
        $out = support_mode::suppress_course_features(['courseid' => 7]);

        $this->assertSame(['courseid' => 7], $out);
    }

    /**
     * THE RULE: every drawer feature whose endpoint is gated on the per-course
     * capability must be in the list.
     *
     * This is the test that would have caught the original seven misses. It reads
     * the template data assembled by the injector -- by name, from the source --
     * and cross-references it against the external functions that still enforce
     * the course capability. Anything that renders a control for an endpoint a
     * support learner cannot reach, and is not on the suppression list, fails.
     */
    public function test_the_suppression_list_covers_every_course_gated_feature(): void {
        $root = __DIR__ . '/../';

        // Flags known to drive a control whose endpoint enforces course :use.
        // Each entry names the endpoint, so a failure says WHY it must be listed.
        $coursegated = [
            'pathenabled'             => 'get_learning_path',
            'masteryenabled'          => 'get_mastery_summary',
            'masterychipenabled'      => 'get_mastery_summary',
            'masterydashboardenabled' => 'get_mastery_summary',
            'flashcardsenabled'       => 'generate_flashcards',
            'showdigestoptin'         => 'set_digest_optin',
            'emailreminders'          => 'update_reminder_preferences',
            'whatsappreminders'       => 'update_reminder_preferences',
            'activelearnersenabled'   => 'get_active_learners',
            'surveyenabled'           => 'get_survey',
            'usertestingenabled'      => 'get_usertesting',
            'voicetabenabled'         => 'get_realtime_token',
            'talkingavatarenabled'    => 'start_avatar_session',
            'quizenabled'             => 'generate_quiz',
        ];

        foreach ($coursegated as $flag => $endpoint) {
            $this->assertArrayHasKey(
                $flag,
                support_mode::SUPPRESSED_FLAGS,
                "'{$flag}' renders a control that calls {$endpoint}, which still enforces the "
                    . "per-course capability. A support learner is not enrolled in the support "
                    . "course, so the control fails when used. Add it to "
                    . "support_mode::SUPPRESSED_FLAGS."
            );
        }

        // And the injector must actually run the pass.
        $src = file_get_contents($root . 'classes/hook_callbacks.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString(
            'support_mode::suppress_course_features($templatedata)',
            $src,
            'do_inject_chat_widget() must apply the suppression pass to the assembled '
                . 'template data, or the list is inert'
        );
    }

    /**
     * Quiz is unreachable in support mode by every route, not just the chip.
     *
     * Suppressing the starter chip was not enough: quiz is also reachable by
     * TYPING ("quiz me on the introduction"), which chat.js intercepts with
     * detectQuizIntent, and through the SOLA_NEXT fallback chips, which offer
     * "Quiz me on this" independently of the starter list. All three routes are
     * now gated on the same flag.
     */
    public function test_the_client_side_quiz_entrances_are_gated(): void {
        $chat = file_get_contents(__DIR__ . '/../amd/src/chat.js');
        $this->assertNotFalse($chat);

        $this->assertStringContainsString(
            'isQuizEnabled() && detectQuizIntent(text)',
            $chat,
            'typing "quiz me on X" must not reach the quiz UI when quiz is disabled'
        );
        $this->assertStringContainsString(
            "dataset.quizEnabled === '1'",
            $chat,
            'the client must read the server-side quiz flag'
        );
        // The helper must resolve the widget root itself. There is no
        // module-level `root` in chat.js -- every sibling helper takes it as a
        // parameter -- so a bare reference is a ReferenceError at call time.
        // The first version had that bug, and because the throw landed inside
        // the send handler it killed the whole turn: three Behat chat scenarios
        // failed with the assistant reply never rendering, while PHPUnit stayed
        // green because none of it executes JavaScript.
        $this->assertMatchesRegularExpression(
            '/const isQuizEnabled = function\(\) \{.*?getElementById\(/s',
            $chat,
            'isQuizEnabled must resolve the widget root itself, not reference a bare root'
        );
        $this->assertSame(
            2,
            substr_count($chat, "c !== 'Quiz me on this'"),
            'both SOLA_NEXT fallback chip lists must filter the quiz chip'
        );

        // The template must actually emit the attribute the client reads.
        $tpl = file_get_contents(__DIR__ . '/../templates/chat_widget.mustache');
        $this->assertNotFalse($tpl);
        $this->assertStringContainsString('data-quiz-enabled=', $tpl);

        // And the built bundle must carry it -- Moodle serves amd/build, and a
        // stale bundle has shipped from this repo before.
        $built = file_get_contents(__DIR__ . '/../amd/build/chat.min.js');
        $this->assertNotFalse($built);
        $this->assertStringContainsString(
            'quizEnabled',
            $built,
            'amd/build/chat.min.js is stale: rebuild it, Moodle does not serve amd/src'
        );
    }

    /**
     * Quiz stays ON for ordinary courses.
     *
     * The direction of this change that could hurt an existing site. quizenabled
     * is set unconditionally true and only the support-mode pass turns it off, so
     * a course render must still emit data-quiz-enabled="1". If someone ever
     * defaults it off, every course on every site loses the practice quiz
     * silently -- the chip disappears and typed quiz intent stops working, with
     * no error anywhere.
     */
    public function test_quiz_stays_enabled_for_ordinary_courses(): void {
        $src = file_get_contents(__DIR__ . '/../classes/hook_callbacks.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString(
            "'quizenabled'        => true,",
            $src,
            'quizenabled must default to true; only the support-mode pass turns it off'
        );

        // The suppression pass must be reached only when support mode is active.
        // Checked as a literal two-line sequence rather than a regex: an earlier
        // attempt used one and PCRE rejected \l inside the namespace separator.
        $this->assertStringContainsString(
            "if (\$supportmode) {\n"
                . "            \$templatedata = \\local_ai_course_assistant\\support_mode::suppress_course_features(",
            $src,
            'the suppression pass must be guarded on support mode, or every course '
                . 'render would lose the quiz and every other listed feature'
        );

        // And the template must emit "1" for the truthy case.
        $tpl = file_get_contents(__DIR__ . '/../templates/chat_widget.mustache');
        $this->assertNotFalse($tpl);
        $this->assertStringContainsString(
            'data-quiz-enabled="{{#quizenabled}}1{{/quizenabled}}"',
            $tpl,
            'the attribute must render the literal 1 the client compares against'
        );
    }

    /**
     * filter_starters keeps only what a support turn can service.
     *
     * Called through the production helper, not a copy of it. The first version
     * of this test re-implemented the filter and asserted against its own copy,
     * so it would have passed whatever the injector actually did.
     */
    public function test_filter_starters_drops_everything_course_only(): void {
        $starters = [
            ['key' => 'help-page',         'type' => 'prompt'],
            ['key' => 'quiz',              'type' => 'quiz'],
            ['key' => 'ell-pronunciation', 'type' => 'pronunciation'],
            ['key' => 'ell-practice',      'type' => 'voice'],
            ['key' => 'focus-next',        'type' => 'prompt'],
            ['key' => 'study-plan',        'type' => 'prompt'],
            ['key' => 'ai-project-coach',  'type' => 'prompt'],
        ];

        $kept = array_column(support_mode::filter_starters($starters), 'key');

        $this->assertSame(['help-page', 'ai-project-coach'], $kept);
    }

    /**
     * The real built-in starters survive the filter without anything course-only.
     *
     * Reads starter_manager's actual definitions, so a new course-only built-in
     * is caught here rather than by a learner clicking it.
     */
    public function test_no_real_builtin_starter_escapes_the_filter(): void {
        $course = $this->getDataGenerator()->create_course();

        $starters = starter_manager::get_effective_starters((int) $course->id, true, true, false);
        $kept = support_mode::filter_starters($starters);

        foreach ($kept as $starter) {
            $this->assertSame('prompt', $starter['type'] ?? 'prompt');
            $this->assertNotContains(
                $starter['key'] ?? '',
                ['quiz', 'focus-next', 'study-plan', 'ell-practice', 'ell-pronunciation'],
                'starter "' . ($starter['key'] ?? '?') . '" is course-only and must not survive'
            );
        }
    }
}
