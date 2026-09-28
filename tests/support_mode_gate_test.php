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
 * The support-mode render gate, and the two call sites that must not drift.
 *
 * hook_callbacks has two gates: do_inject_chat_widget() decides whether the
 * widget renders, and widget_would_render_here() decides whether its
 * Content-Security-Policy header is sent. They are a copy-paste pair, they have
 * drifted before -- the CSP copy's docblock still claims checks it does not make
 * -- and a page that renders the widget without the CSP silently loses the
 * defence added after the injected-widget incident.
 *
 * Support mode does not re-implement the predicate in either place. Both ask
 * support_mode::renders_here(). These tests pin that, structurally, so the next
 * person to widen one gate cannot widen only one.
 *
 * @package    local_ai_course_assistant
 * @copyright  2026 Tom Caswell / Saylor University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_course_assistant\support_mode::renders_here
 */
final class support_mode_gate_test extends \advanced_testcase {

    /** @var \stdClass The designated support course. */
    private $supportcourse;

    /** @var \stdClass The learner. */
    private $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $gen = $this->getDataGenerator();
        $this->supportcourse = $gen->create_course();
        $this->user = $gen->create_user();
        set_config('enabled', 1, 'local_ai_course_assistant');
        set_config('support_enabled', 1, 'local_ai_course_assistant');
        set_config('support_courseid', $this->supportcourse->id, 'local_ai_course_assistant');
        support_mode::reset_cache();
        $this->setUser($this->user);
    }

    /**
     * Build a page at a given context and URL.
     *
     * @param \context $context
     * @param string $url
     * @param string $layout
     * @return \moodle_page
     */
    private function page(\context $context, string $url = '/my/index.php', string $layout = 'mydashboard'): \moodle_page {
        $page = new \moodle_page();
        $page->set_context($context);
        $page->set_url(new \moodle_url($url));
        $page->set_pagelayout($layout);
        return $page;
    }

    /**
     * The dashboard is a user context, and is exactly what this feature is for.
     */
    public function test_the_dashboard_renders_support_mode(): void {
        $page = $this->page(\context_user::instance($this->user->id));

        $this->assertTrue(support_mode::renders_here($page));
    }

    /**
     * The site home is a course context at SITEID, which the per-course gate
     * returns early on. Support mode picks it up.
     */
    public function test_the_site_home_renders_support_mode(): void {
        $page = $this->page(\context_course::instance(SITEID), '/index.php', 'frontpage');

        $this->assertTrue(support_mode::renders_here($page));
    }

    /**
     * An ordinary course page is NOT support mode; the per-course path owns it.
     *
     * If this ever returned true the widget would render twice on a course page,
     * and the support gate would bypass the per-course enable check.
     */
    public function test_an_ordinary_course_page_is_not_support_mode(): void {
        $other = $this->getDataGenerator()->create_course();
        $page = $this->page(\context_course::instance($other->id), '/course/view.php', 'course');

        $this->assertFalse(support_mode::renders_here($page));
    }

    /**
     * The designated support course, viewed as a course, is also not the support
     * gate's business -- the per-course path renders it.
     */
    public function test_the_support_course_page_itself_is_not_the_support_gate(): void {
        $page = $this->page(\context_course::instance($this->supportcourse->id), '/course/view.php', 'course');

        $this->assertFalse(support_mode::renders_here($page));
    }

    /**
     * Administration pages are excluded, as they are on the per-course path.
     */
    public function test_admin_pages_are_excluded(): void {
        $page = $this->page(\context_system::instance(), '/admin/search.php', 'admin');

        $this->assertFalse(support_mode::renders_here($page));
    }

    /**
     * The plugin's own pages are excluded, whatever layout they choose.
     */
    public function test_the_plugins_own_pages_are_excluded(): void {
        $page = $this->page(
            \context_system::instance(),
            '/local/ai_course_assistant/analytics.php',
            'incourse'
        );

        $this->assertFalse(support_mode::renders_here($page));
    }

    /**
     * The login flow is excluded.
     *
     * A chat drawer over a login form is useless -- the visitor is not
     * authenticated, so can_use() has already refused -- and is an invitation to
     * type a password into it.
     */
    public function test_the_login_flow_is_excluded(): void {
        $page = $this->page(\context_system::instance(), '/login/index.php', 'login');

        $this->assertFalse(support_mode::renders_here($page));
    }

    /**
     * With the plugin globally disabled, support mode renders nowhere.
     */
    public function test_the_global_kill_switch_wins(): void {
        set_config('enabled', 0, 'local_ai_course_assistant');
        $page = $this->page(\context_user::instance($this->user->id));

        $this->assertFalse(support_mode::renders_here($page));
    }

    /**
     * With support mode off -- the state every existing site is in -- the
     * dashboard is untouched.
     */
    public function test_an_existing_site_is_unaffected(): void {
        set_config('support_enabled', 0, 'local_ai_course_assistant');
        support_mode::reset_cache();
        $page = $this->page(\context_user::instance($this->user->id));

        $this->assertFalse(support_mode::renders_here($page));
    }

    /**
     * A guest gets nothing, even on a page support mode would otherwise claim.
     */
    public function test_a_guest_gets_nothing(): void {
        $this->setGuestUser();
        $page = $this->page(\context_system::instance(), '/index.php', 'frontpage');

        $this->assertFalse(support_mode::renders_here($page));
    }

    // ------------------------------------------------------- mirror protection

    /**
     * Both gates delegate to support_mode::renders_here().
     *
     * Structural rather than behavioural because the two methods are private and
     * driven by globals that are awkward to stage. What this pins is the thing
     * that actually goes wrong: someone teaching one gate about a new surface and
     * not the other. As long as both defer to one predicate, they cannot disagree.
     */
    public function test_both_gates_delegate_to_one_predicate(): void {
        $src = file_get_contents(__DIR__ . '/../classes/hook_callbacks.php');
        $this->assertNotFalse($src);

        foreach (['do_inject_chat_widget', 'widget_would_render_here'] as $method) {
            $start = strpos($src, 'function ' . $method);
            $this->assertNotFalse($start, $method . ' must exist');

            // Read to the end of the method by brace balance.
            $open = strpos($src, '{', $start);
            $depth = 0;
            $end = $open;
            for ($i = $open, $len = strlen($src); $i < $len; $i++) {
                if ($src[$i] === '{') {
                    $depth++;
                } else if ($src[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
            $body = substr($src, $open, $end - $open);

            $this->assertStringContainsString(
                'support_mode::renders_here',
                $body,
                $method . '() must ask support_mode::renders_here() rather than '
                    . 'reimplementing the gate -- the CSP header and the widget have to '
                    . 'agree about which pages are in scope'
            );
        }
    }

    /**
     * Every quiz_lock call site on a chat path routes through integrity_scope().
     *
     * The bypass this prevents: scoping the exam lock to a course that holds no
     * quizzes means it never fires. A new call site added without the helper
     * reopens it silently, which is why this is asserted over the files rather
     * than over one code path.
     */
    public function test_every_chat_path_scopes_the_quiz_lock(): void {
        $files = [
            'sse.php',
            'classes/provider/base_provider.php',
            'classes/external/send_message.php',
            'classes/external/generate_quiz.php',
            'classes/hook_callbacks.php',
        ];
        $root = __DIR__ . '/../';

        foreach ($files as $rel) {
            $src = file_get_contents($root . $rel);
            $this->assertNotFalse($src, $rel . ' must be readable');

            // Every active_attempt/is_locked_for call must have integrity_scope()
            // within the same call expression. Both are multi-line, so match the
            // call and the following two lines.
            if (!preg_match_all('/(?:active_attempt|is_locked_for)\s*\((.{0,240}?)\)\s*;/s', $src, $m)) {
                continue;
            }
            foreach ($m[1] as $args) {
                $this->assertStringContainsString(
                    'integrity_scope',
                    $args,
                    $rel . ' passes a raw course id to the quiz lock. On the support '
                        . 'surface that scopes the exam lock to a course with no quizzes, '
                        . 'so it never fires.'
                );
            }
        }
    }
}
